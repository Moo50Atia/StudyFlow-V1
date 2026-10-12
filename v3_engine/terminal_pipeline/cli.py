"""CLI implementation for the Temporal Terminal Pipeline (Spec 018 / T015)."""

from __future__ import annotations

import argparse
import asyncio
import io
import json
import logging
import os
import sys
import hashlib
import time
import uuid
from pathlib import Path
from typing import Any, Sequence
from temporalio.client import Client, WithStartWorkflowOperation, WorkflowUpdateStage
from temporalio.common import WorkflowIDConflictPolicy

from v3_engine.terminal_pipeline.contracts import (
    SafeCode,
    validate_canonical_uuid,
)
from v3_engine.terminal_pipeline.manifest import (
    ManifestCaptureError,
    SubmissionManifest,
    capture_manifest,
)
from v3_engine.terminal_pipeline.journal import OperationalJournal
from v3_engine.terminal_pipeline.status import get_subject_status
from v3_engine.terminal_pipeline.store import TerminalIndexStore
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow
from v3_engine.terminal_pipeline.worker import run_workers

logger = logging.getLogger("v3_engine.terminal_pipeline.cli")


def get_temporal_address() -> str:
    return os.environ.get("V3_TERMINAL_TEMPORAL_ADDRESS") or os.environ.get("V3_TEMPORAL_ADDRESS") or "127.0.0.1:7233"


def get_temporal_namespace() -> str:
    return os.environ.get("V3_TERMINAL_TEMPORAL_NAMESPACE") or "v3-terminal"


async def connect_client() -> Client:
    return await Client.connect(
        get_temporal_address(),
        namespace=get_temporal_namespace(),
    )


def run_cli(args: Sequence[str], client: Client | None = None) -> tuple[int, str]:
    """Execute CLI command returning (exit_code, output_text)."""
    parser = argparse.ArgumentParser(description="Temporal Terminal Pipeline CLI")
    subparsers = parser.add_subparsers(dest="command", required=True)

    # submit
    submit_p = subparsers.add_parser("submit")
    submit_p.add_argument("--manifest", required=True, type=Path)
    submit_p.add_argument(
        "--export-prompts", action="store_true",
        help="Export NotebookLM prompts without calling a scene-generation provider.",
    )
    submit_p.add_argument("--json", action="store_true", dest="json_mode")
    submit_p.add_argument("--pdf-processing", choices=["joint", "per-pdf"], default=None,
                          help="Process all PDFs jointly, or run a fresh conversation for each PDF in one notebook.")

    # worker
    worker_p = subparsers.add_parser("worker")
    worker_p.add_argument("--json", action="store_true", dest="json_mode")

    # status
    status_p = subparsers.add_parser("status")
    status_p.add_argument("--submission-id", type=str)
    status_p.add_argument("--run-id", type=str)
    status_p.add_argument("--json", action="store_true", dest="json_mode")

    # logs
    logs_p = subparsers.add_parser("logs")
    logs_p.add_argument("--run-id", required=True, type=str)
    logs_p.add_argument("--after-seq", type=int, default=0)
    logs_p.add_argument("--follow", action="store_true")
    logs_p.add_argument("--json", action="store_true", dest="json_mode")

    # controls: pause, resume, cancel
    for ctrl_cmd in ("pause", "resume", "cancel"):
        cp = subparsers.add_parser(ctrl_cmd)
        cp.add_argument("--run-id", required=True, type=str)
        cp.add_argument("--command-id", required=True, type=str)
        cp.add_argument("--expected-version", required=True, type=int)
        cp.add_argument("--json", action="store_true", dest="json_mode")

    # retry
    retry_p = subparsers.add_parser("retry")
    retry_p.add_argument("--run-id", required=True, type=str)
    retry_p.add_argument("--command-id", required=True, type=str)
    retry_p.add_argument("--expected-version", required=True, type=int)
    retry_p.add_argument("--phase", required=True, choices=["notebook", "generate", "build"])
    retry_p.add_argument("--scene-key", action="append", default=[])
    retry_p.add_argument("--accept-duplicate-risk", action="store_true")
    retry_p.add_argument("--json", action="store_true", dest="json_mode")

    # prepare-pdfs
    prep_p = subparsers.add_parser("prepare-pdfs")
    input_group = prep_p.add_mutually_exclusive_group(required=True)
    input_group.add_argument("--pdf-dir", type=Path, help="Directory containing source PDFs.")
    input_group.add_argument("--pdf-file", dest="pdf_files", action="append", type=Path,
                             help="Source PDF file (repeatable for multiple files in explicit order).")
    prep_p.add_argument("--output", required=True, type=Path, help="Target preparation package root.")
    prep_p.add_argument("--json", action="store_true", dest="json_mode")

    try:
        parsed = parser.parse_args(args)
    except SystemExit as exc:
        if exc.code == 0:
            return 0, ""
        return 2, "Invalid arguments"

    if parsed.command == "prepare-pdfs":
        return _handle_prepare_pdfs(parsed)
    elif parsed.command == "submit":
        return _handle_submit(parsed, client)
    elif parsed.command == "worker":
        return _handle_worker(parsed, client)
    elif parsed.command in ("pause", "resume", "cancel"):
        return _handle_control(parsed, client)
    elif parsed.command == "retry":
        return _handle_retry(parsed, client)
    elif parsed.command == "status":
        return _handle_status(parsed, client)
    elif parsed.command == "logs":
        return _handle_logs(parsed, client)

    return 0, "{}"


def _handle_prepare_pdfs(parsed: argparse.Namespace) -> tuple[int, str]:
    from v3_engine.terminal_pipeline.manifest import resolve_original_pdfs
    from v3_engine.terminal_pipeline.pdf_preparation import (
        prepare_pdf_sources,
        PreparationError,
    )

    try:
        output_dir = parsed.output.resolve()
        if parsed.pdf_dir:
            pdf_dir_res = parsed.pdf_dir.resolve()
            if output_dir == pdf_dir_res or output_dir.is_relative_to(pdf_dir_res) or pdf_dir_res.is_relative_to(output_dir):
                raise PreparationError(f"Preparation output directory cannot be inside or overlap source directory: {parsed.pdf_dir}", SafeCode.input_invalid)
        resolved_originals = resolve_original_pdfs(
            pdf_dir=parsed.pdf_dir,
            pdf_files=parsed.pdf_files,
            project_root=Path.cwd(),
            check_hashes=True,
        )
        inventory = prepare_pdf_sources(resolved_originals, output_dir)
    except Exception as exc:
        safe_code = getattr(exc, "safe_code", SafeCode.input_invalid)
        code_val = safe_code.value if hasattr(safe_code, "value") else str(safe_code)
        out = {
            "schema_version": 1,
            "outcome": "rejected",
            "safe_code": code_val,
            "message": str(exc),
        }
        return 2, json.dumps(out) if parsed.json_mode else f"rejected: {code_val} ({str(exc)})"

    if parsed.json_mode:
        out_dict = {
            "schema_version": 1,
            "outcome": "prepared",
            "policy": inventory.policy.to_dict(),
            "original_count": len(inventory.originals),
            "prepared_pdf_count": len(inventory.prepared_pdfs),
            "output_dir": str(output_dir),
            "final_pdf_dir": str(output_dir / "final"),
            "inventory_path": str(output_dir / "inventory.json"),
            "inventory_sha256": inventory.inventory_sha256,
            "originals": [o.to_dict() for o in inventory.originals],
            "prepared_pdfs": [p.to_dict() for p in inventory.prepared_pdfs],
        }
        return 0, json.dumps(out_dict)

    lines = [
        f"prepared: {len(inventory.originals)} original(s) -> {len(inventory.prepared_pdfs)} final PDF(s) in {output_dir / 'final'}",
        f"package root: {output_dir}",
        f"inventory: {output_dir / 'inventory.json'} (sha256={inventory.inventory_sha256})",
        "final files in reading order:",
    ]
    for p in inventory.prepared_pdfs:
        lines.append(f"  [{p.upload_ordinal:04d}] {p.filename} (pages {p.start_page}-{p.end_page}, {p.page_count} pages)")
    return 0, "\n".join(lines)


def _handle_submit(parsed: argparse.Namespace, client: Client | None) -> tuple[int, str]:
    manifest_path = parsed.manifest
    if not manifest_path.is_file():
        out = {
            "schema_version": 1,
            "outcome": "rejected",
            "safe_code": SafeCode.input_invalid.value,
            "message": f"Manifest file not found: {manifest_path}",
        }
        return 2, json.dumps(out) if parsed.json_mode else f"rejected: input_invalid ({out['message']})"

    try:
        raw_manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        term_root = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal")
        snapshot, saved_manifest_path = capture_manifest(
            raw_manifest, terminal_root=term_root, project_root=Path.cwd(),
            scene_generation_mode="export_prompts" if parsed.export_prompts else "generate",
            pdf_processing=parsed.pdf_processing.replace("-", "_") if parsed.pdf_processing else None,
        )
    except Exception as exc:
        safe_code = getattr(exc, "safe_code", SafeCode.input_invalid)
        code_val = safe_code.value if hasattr(safe_code, "value") else str(safe_code)
        out = {
            "schema_version": 1,
            "outcome": "rejected",
            "safe_code": code_val,
            "message": str(exc),
        }
        return 2, json.dumps(out) if parsed.json_mode else f"rejected: {code_val} ({str(exc)})"

    # Async execute submission update to supervisor
    async def _submit() -> tuple[int, str]:
        for subject in snapshot.subjects:
            if subject.pdf_preparation_ref is not None:
                from v3_engine.terminal_pipeline.pdf_preparation import verify_preparation, PreparationError
                prep_inv_path = subject.pdf_preparation_ref.resolve(term_root)
                try:
                    verify_preparation(prep_inv_path)
                except PreparationError as pe:
                    code_val = pe.safe_code.value if hasattr(pe.safe_code, "value") else str(pe.safe_code)
                    out = {
                        "schema_version": 1,
                        "outcome": "rejected",
                        "safe_code": code_val,
                        "message": f"Pre-admission PDF preparation verification failed: {pe}",
                    }
                    return 2, json.dumps(out) if parsed.json_mode else f"rejected: {code_val} ({out['message']})"
                except Exception as exc:
                    out = {
                        "schema_version": 1,
                        "outcome": "rejected",
                        "safe_code": SafeCode.input_invalid.value,
                        "message": f"Pre-admission PDF preparation verification failed: {exc}",
                    }
                    return 2, json.dumps(out) if parsed.json_mode else f"rejected: input_invalid ({out['message']})"

        cli = client or await connect_client()
        subject_payloads = []
        for subject in snapshot.subjects:
            source_rows = []
            if subject.mode == "new":
                for item in subject.inputs:
                    path = item.snapshot_ref.resolve(term_root)
                    source_rows.append({
                        "path": str(path),
                        "mime_type": "application/pdf" if item.kind == "pdf" else "text/plain",
                        "title": path.name,
                        "role": "educational_pdf" if item.kind == "pdf" else "framework_steps",
                        "ordinal": item.ordinal,
                        "sha256": item.sha256,
                        "original_filename": Path(item.original_path).name,
                    })
            handoff_path = subject.handoff_ref.resolve(term_root) if subject.handoff_ref else None
            subject_payloads.append({
                "subject_key": subject.subject_key,
                "subject_run_id": subject.subject_run_id,
                "run_ref": f"runs/{subject.subject_run_id}",
                "title": subject.display_title,
                "mode": subject.mode,
                "sources": source_rows,
                "proof_notebook_id": subject.account_check_notebook_id or "",
                "provider": subject.provider,
                "model": subject.model,
                "timeout_seconds": subject.timeout_seconds,
                "subject_config": subject.to_dict(),
                "handoff_path": str(handoff_path) if handoff_path else None,
                "handoff_kind": subject.handoff_kind,
            })
        submission_payload = {
            "schema_version": 1,
            "submission_id": snapshot.submission_id,
            "submission_digest": snapshot.manifest_sha256,
            "manifest_ref": str(saved_manifest_path),
            "subjects": subject_payloads,
        }
        operation = WithStartWorkflowOperation(
            TerminalSupervisorWorkflow.run,
            {},
            id="v3-terminal-supervisor",
            task_queue="v3-terminal-control",
            id_conflict_policy=WorkflowIDConflictPolicy.USE_EXISTING,
        )
        update_handle = await cli.start_update_with_start_workflow(
            TerminalSupervisorWorkflow.submit_v1,
            submission_payload,
            start_workflow_operation=operation,
            wait_for_stage=WorkflowUpdateStage.ACCEPTED,
        )
        res = await update_handle.result()

        outcome = res.get("outcome", "unknown")
        safe_code = res.get("safe_code", SafeCode.ok.value)

        if outcome == "accepted":
            out_data = {
                "schema_version": 1,
                "submission_id": snapshot.submission_id,
                "outcome": "accepted",
                "manifest_digest": snapshot.manifest_sha256,
                "subjects": [
                    {
                        "subject_key": s.subject_key,
                        "subject_run_id": s.subject_run_id,
                        "workflow_id": f"v3-terminal-subject-{s.subject_run_id}",
                    }
                    for s in snapshot.subjects
                ],
            }
            return 0, json.dumps(out_data) if parsed.json_mode else f"accepted submission {snapshot.submission_id}"
        elif safe_code in (SafeCode.queue_full.value, SafeCode.scheduler_draining.value):
            out_data = {
                "schema_version": 1,
                "outcome": "rejected",
                "safe_code": safe_code,
                "submission_id": snapshot.submission_id,
            }
            return 3, json.dumps(out_data) if parsed.json_mode else f"rejected: {safe_code}"
        else:
            out_data = {
                "schema_version": 1,
                "outcome": "rejected",
                "safe_code": safe_code,
                "submission_id": snapshot.submission_id,
            }
            return 2, json.dumps(out_data) if parsed.json_mode else f"rejected: {safe_code}"

    try:
        return asyncio.run(_submit())
    except Exception as exc:
        out_data = {
            "schema_version": 1,
            "outcome": "rejected",
            "safe_code": SafeCode.temporal_unavailable.value,
            "message": str(exc),
        }
        return 4, json.dumps(out_data) if parsed.json_mode else f"server unavailable: {exc}"


def _handle_worker(parsed: argparse.Namespace, client: Client | None) -> tuple[int, str]:
    async def _worker() -> None:
        cli = client or await connect_client()
        await run_workers(cli)

    try:
        asyncio.run(_worker())
        return 0, "Worker stopped gracefully"
    except Exception as exc:
        return 3, f"Worker error: {exc}"


def _handle_control(parsed: argparse.Namespace, client: Client | None) -> tuple[int, str]:
    async def _ctrl() -> tuple[int, str]:
        cli = client or await connect_client()
        run_id = parsed.run_id
        cmd_id = parsed.command_id
        action = parsed.command  # 'pause' | 'resume' | 'cancel'
        expected_version = parsed.expected_version

        # 1. Check admission state from supervisor
        supervisor_handle = cli.get_workflow_handle("v3-terminal-supervisor")
        try:
            admission = await supervisor_handle.query("get_subject_admission_v1", run_id)
        except Exception:
            admission = "unknown"

        # 2. Unstarted queued subject cannot be paused or resumed
        if admission == "queued" and action in ("pause", "resume"):
            out = {
                "schema_version": 1,
                "outcome": "rejected",
                "safe_code": SafeCode.control_conflict.value,
                "status": "unstarted_queued",
                "message": "Unstarted queued subject cannot be paused or resumed",
                "command_id": cmd_id,
                "subject_run_id": run_id,
            }
            return 3, json.dumps(out) if parsed.json_mode else "rejected: control_conflict (unstarted subject)"

        # 3. Queued cancellation routes to supervisor cancel_queued_v1
        if admission == "queued" and action == "cancel":
            cmd = {
                "command_id": cmd_id,
                "subject_run_id": run_id,
                "expected_version": expected_version,
            }
            try:
                res = await asyncio.wait_for(
                    supervisor_handle.execute_update("cancel_queued_v1", cmd),
                    timeout=10.0,
                )
                outcome = res.get("outcome", "applied")
                safe_code = res.get("safe_code", SafeCode.ok.value)
                res["schema_version"] = 1
                if outcome == "rejected" or safe_code == SafeCode.control_conflict.value:
                    return 3, json.dumps(res) if parsed.json_mode else f"rejected: {safe_code}"
                return 0, json.dumps(res) if parsed.json_mode else f"cancel: {res.get('status')}"
            except asyncio.TimeoutError:
                out = {
                    "schema_version": 1,
                    "outcome": "pending",
                    "safe_code": SafeCode.control_pending.value,
                    "command_id": cmd_id,
                    "subject_run_id": run_id,
                }
                return 4, json.dumps(out) if parsed.json_mode else "pending: control_pending"

        # 4. Active / started subject: route to subject workflow
        wf_id = f"v3-terminal-subject-{run_id}"
        handle = cli.get_workflow_handle(wf_id)
        cmd = {
            "command_id": cmd_id,
            "action": action,
            "subject_run_id": run_id,
            "expected_version": expected_version,
        }
        try:
            res = await asyncio.wait_for(
                handle.execute_update("control_v1", cmd),
                timeout=10.0,
            )
            outcome = res.get("outcome", res.get("status", "applied"))
            safe_code = res.get("safe_code", SafeCode.ok.value)
            res["schema_version"] = 1
            if outcome == "rejected" or safe_code == SafeCode.control_conflict.value:
                return 3, json.dumps(res) if parsed.json_mode else f"rejected: {safe_code}"
            return 0, json.dumps(res) if parsed.json_mode else f"{action}: {res.get('status')}"
        except asyncio.TimeoutError:
            out = {
                "schema_version": 1,
                "outcome": "pending",
                "safe_code": SafeCode.control_pending.value,
                "command_id": cmd_id,
                "subject_run_id": run_id,
            }
            return 4, json.dumps(out) if parsed.json_mode else "pending: control_pending"
        except Exception as exc:
            out = {
                "schema_version": 1,
                "outcome": "rejected",
                "safe_code": SafeCode.control_conflict.value,
                "message": str(exc),
                "command_id": cmd_id,
                "subject_run_id": run_id,
            }
            return 3, json.dumps(out) if parsed.json_mode else f"error: {exc}"

    try:
        return asyncio.run(_ctrl())
    except Exception as exc:
        return 3, f"Control error: {exc}"


def _handle_retry(parsed: argparse.Namespace, client: Client | None) -> tuple[int, str]:
    async def _retry() -> tuple[int, str]:
        cli = client or await connect_client()
        run_id = parsed.run_id
        cmd_id = parsed.command_id
        phase = parsed.phase
        expected_version = parsed.expected_version
        scene_keys = parsed.scene_key
        accept_duplicate_risk = parsed.accept_duplicate_risk

        if phase == "generate" and not accept_duplicate_risk:
            out = {
                "schema_version": 1,
                "outcome": "rejected",
                "safe_code": SafeCode.control_conflict.value,
                "message": "Uncertain generation retry requires explicit --accept-duplicate-risk",
                "command_id": cmd_id,
                "subject_run_id": run_id,
            }
            return 3, json.dumps(out) if parsed.json_mode else "rejected: control_conflict (explicit consent required)"

        # Check subject state from supervisor
        supervisor_handle = cli.get_workflow_handle("v3-terminal-supervisor")
        try:
            admission = await supervisor_handle.query("get_subject_admission_v1", run_id)
        except Exception:
            admission = "unknown"

        # If completed or closed partial, admit linked recovery child on supervisor
        if admission in ("completed", "unknown") and phase in ("build", "generate"):
            canonical_request = json.dumps({
                "command_id": cmd_id,
                "subject_run_id": run_id,
                "phase": phase,
                "scene_keys": sorted(scene_keys),
                "accept_duplicate_risk": accept_duplicate_risk,
            }, sort_keys=True, separators=(",", ":"))
            request_digest = hashlib.sha256(canonical_request.encode("utf-8")).hexdigest()
            recovery_id = str(uuid.uuid5(uuid.NAMESPACE_URL, f"v3-terminal-recovery:{cmd_id}"))
            rec_payload = {
                "schema_version": 1,
                "original_generation_ref": f"runs/{run_id}",
                "recovery_id": recovery_id,
                "recovery_subject_id": str(uuid.uuid5(uuid.NAMESPACE_URL, f"v3-terminal-recovery-subject:{cmd_id}")),
                "command_id": cmd_id,
                "command_digest": request_digest,
                "mode": phase,
                "selected_scene_keys": scene_keys,
                "accept_duplicate_risk": accept_duplicate_risk,
            }
            try:
                res = await asyncio.wait_for(
                    supervisor_handle.execute_update(TerminalSupervisorWorkflow.retry_scenes_v1, rec_payload),
                    timeout=10.0,
                )
                outcome = res.get("outcome", "accepted")
                safe_code = res.get("safe_code", SafeCode.ok.value)
                res["schema_version"] = 1
                if outcome == "rejected":
                    return 3, json.dumps(res) if parsed.json_mode else f"rejected: {safe_code}"
                return 0, json.dumps(res) if parsed.json_mode else f"recovery accepted: {res.get('recovery_id')}"
            except asyncio.TimeoutError:
                out = {
                    "schema_version": 1,
                    "outcome": "pending",
                    "safe_code": SafeCode.control_pending.value,
                    "command_id": cmd_id,
                    "subject_run_id": run_id,
                }
                return 4, json.dumps(out) if parsed.json_mode else "pending: control_pending"

        # If active / running subject, send control_v1 to subject workflow
        wf_id = f"v3-terminal-subject-{run_id}"
        handle = cli.get_workflow_handle(wf_id)
        cmd = {
            "command_id": cmd_id,
            "action": "retry",
            "phase": phase,
            "scene_keys": scene_keys,
            "accept_duplicate_risk": accept_duplicate_risk,
            "command_digest": hashlib.sha256(json.dumps({
                "command_id": cmd_id, "run_id": run_id, "phase": phase,
                "scene_keys": sorted(scene_keys), "accept_duplicate_risk": accept_duplicate_risk,
            }, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest(),
            "subject_run_id": run_id,
            "expected_version": expected_version,
        }
        try:
            res = await asyncio.wait_for(handle.execute_update("control_v1", cmd), timeout=10.0)
            outcome = res.get("outcome", res.get("status", "applied"))
            safe_code = res.get("safe_code", SafeCode.ok.value)
            res["schema_version"] = 1
            if outcome == "rejected" or safe_code == SafeCode.control_conflict.value:
                return 3, json.dumps(res) if parsed.json_mode else f"rejected: {safe_code}"
            return 0, json.dumps(res) if parsed.json_mode else f"retry: {res.get('status')}"
        except asyncio.TimeoutError:
            out = {
                "schema_version": 1,
                "outcome": "pending",
                "safe_code": SafeCode.control_pending.value,
                "command_id": cmd_id,
                "subject_run_id": run_id,
            }
            return 4, json.dumps(out) if parsed.json_mode else "pending: control_pending"
        except Exception as exc:
            return 3, f"Retry error: {exc}"

    try:
        return asyncio.run(_retry())
    except Exception as exc:
        return 3, f"Retry error: {exc}"


def _handle_status(parsed: argparse.Namespace, client: Client | None) -> tuple[int, str]:
    term_root = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal")
    index_store = TerminalIndexStore(term_root / "index.sqlite")

    if client is None:
        try:
            client = asyncio.run(connect_client())
        except Exception:
            client = None

    if getattr(parsed, "run_id", None):
        status = get_subject_status(parsed.run_id, client=client, index_store=index_store)
        doc = {"schema_version": 1, **status.to_dict()}
        return 0, json.dumps(doc) if parsed.json_mode else f"status: {status.status} (phase: {status.phase}, freshness: {status.freshness})"

    if getattr(parsed, "submission_id", None):
        sub_id = parsed.submission_id
        with index_store._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM subjects WHERE submission_id = ? ORDER BY acceptance_seq ASC;", (sub_id,))
            rows = [dict(r) for r in cursor.fetchall()]

        results = []
        for r in rows:
            sid = r["subject_run_id"]
            st = get_subject_status(sid, client=client, index_store=index_store)
            results.append(st.to_dict())

        doc = {"schema_version": 1, "submission_id": sub_id, "subjects": results}
        return 0, json.dumps(doc) if parsed.json_mode else f"submission {sub_id}: {len(results)} subjects"

    return 2, "Either --run-id or --submission-id is required"


def _handle_logs(parsed: argparse.Namespace, client: Client | None) -> tuple[int, str]:
    term_root = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal")
    journal = OperationalJournal(term_root)
    after_seq = getattr(parsed, "after_seq", 0)

    output_lines: list[str] = []
    cursor = after_seq
    try:
        while True:
            events = journal.read_events(after_seq=cursor, limit=1000, subject_run_id=parsed.run_id)
            for event in events:
                cursor = event.seq or cursor
                if parsed.json_mode:
                    output_lines.append(json.dumps({"schema_version": 1, **event.to_dict()}))
                else:
                    output_lines.append(f"[{event.seq}] {event.occurred_at} {event.kind} (outcome={event.outcome}, duration={event.duration_ms}ms)")
            if not parsed.follow:
                break
            time.sleep(1.0)
    except KeyboardInterrupt:
        pass
    return 0, "\n".join(output_lines)


def main() -> int:
    exit_code, output = run_cli(sys.argv[1:])
    if output:
        print(output)
    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())
