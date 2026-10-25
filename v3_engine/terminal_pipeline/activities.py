"""Temporal activity definitions for the terminal pipeline (Spec 018 / T013)."""

from __future__ import annotations

import asyncio
import hashlib
import json
import logging
import os
import shutil
import stat
import uuid
from pathlib import Path
from typing import Any
from temporalio import activity

from v3_engine.notebooklm.terminal_service import NotebookTerminalService
from v3_engine.notebooklm.terminal_service import bootstrap_subject_credentials
from v3_engine.notebooklm.ports import AuthenticationRequiredError
from v3_engine.scene_generation.artifact_store import SceneArtifactStore
from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.config import load_provider_config, resolve_template
from v3_engine.scene_generation.handoff import load_handoff_inventory
from v3_engine.scene_generation.providers import create_provider
from v3_engine.scene_generation.service import SceneGenerationService
from v3_engine.terminal_pipeline.contracts import (
    ActivityOutcome,
    SafeCode,
    SafeErrorCode,
)
from v3_engine.terminal_pipeline.journal import OperationalEvent, OperationalJournal, make_stable_event_id
from v3_engine.terminal_pipeline.store import TerminalIndexStore, TerminalRunStore
from v3_engine.terminal_pipeline.pdf_scope import (
    pdf_run_dir, read_json, retain_json, archive_pdf, verify_pdf_archive, scoped_inventories, terminal_scene_key,
)

logger = logging.getLogger("v3_engine.terminal_pipeline.activities")


def _safe_provider_failure(
    subject_run_id: str,
    kind: str,
    phase: str,
    *,
    uncertain: bool = False,
    operation_id: str | None = None,
    safe_code: SafeCode | None = None,
) -> dict[str, Any]:
    """Return only a bounded classification; never serialize an SDK exception/cause into Temporal."""
    outcome = ActivityOutcome.uncertain.value if uncertain else ActivityOutcome.failed.value
    code = SafeCode.delivery_uncertain.value if uncertain else (safe_code or SafeCode.provider_unavailable).value
    try:
        _record_event(subject_run_id, kind, phase, outcome, code, operation_id=operation_id)
    except Exception:
        # Journal outages must not cause a provider exception to escape with its raw cause.
        logger.error("terminal journal write failed for %s", kind)
    return {
        "schema_version": 1,
        "outcome": outcome,
        "safe_code": code,
        "receipt_ref": None,
        "result_ref": None,
        "counts": None,
    }


def _subject_run_dir(subject_run_id: str) -> Path:
    return Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal") / "runs" / subject_run_id


def _resolve_run_template(subject_run_id: str) -> tuple[str, str]:
    """Use the bytes sealed into this run; legacy runs retain their captured local default."""
    config_path = _subject_run_dir(subject_run_id) / "subject-config.json"
    if config_path.is_file():
        config = read_json(config_path)
        template = config.get("captured_scene_system_prompt")
        digest = config.get("captured_scene_system_prompt_sha256")
        if template is not None or digest is not None:
            if (not isinstance(template, str) or not isinstance(digest, str)
                    or hashlib.sha256(template.encode("utf-8")).hexdigest() != digest):
                raise ValueError("captured_scene_template_corrupt")
            return template, digest
    return resolve_template()


def _record_event(subject_run_id: str, kind: str, phase: str, outcome: str, safe_code: str = "ok", operation_id: str | None = None, counts: dict[str, int] | None = None, attempt: int = 1) -> None:
    if operation_id is None and kind == 'scene_prompts_exported':
        try:
            info = activity.info()
            operation_id, attempt = f"export:{info.workflow_run_id}:{info.activity_id}", info.attempt
        except RuntimeError:
            operation_id = f"export:{outcome}:{safe_code}"
    journal = OperationalJournal(Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal"))
    logical_id = operation_id or subject_run_id
    journal.append_event(OperationalEvent(
        # Scene identities are stable across submissions. Scope the journal
        # identity to this subject and generation attempt so independent runs
        # cannot collide while activity retries remain idempotent.
        event_id=make_stable_event_id(f"{subject_run_id}:{logical_id}", max(1, attempt), kind),
        kind=kind,
        occurred_at="",
        subject_run_id=subject_run_id,
        workflow_id=f"v3-terminal-subject-{subject_run_id}",
        operation_id=operation_id,
        attempt=max(1, attempt),
        phase=phase,
        outcome=outcome,
        safe_code=safe_code,
        counts=counts,
    ))


def _subject_credential_path(subject_run_id: str) -> Path | None:
    path = _subject_run_dir(subject_run_id) / "private" / "notebooklm-sessions" / subject_run_id / "storage_state.json"
    return path if path.is_file() else None


def _begin_remote_operation(subject_run_id: str, operation_id: str, request_digest: str) -> tuple[Path, dict[str, Any] | None]:
    """Write a durable dispatch fence before a remote mutation; never replay an uncertain send."""
    root = _subject_run_dir(subject_run_id) / "private" / "notebook-operations"
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    path = root / f"op-{hashlib.sha256(operation_id.encode('utf-8')).hexdigest()[:32]}.json"
    if path.exists():
        saved = json.loads(path.read_text(encoding="utf-8"))
        if saved.get("operation_id") != operation_id or saved.get("request_digest") != request_digest:
            raise ValueError("remote_operation_conflict")
        return path, saved
    from v3_engine.notebooklm.terminal_service import atomic_json
    atomic_json(path, {"schema_version": 1, "operation_id": operation_id,
                       "request_digest": request_digest, "state": "dispatching"})
    return path, None


def _finish_remote_operation(path: Path, saved: dict[str, Any] | None, *, result_ref: str | None) -> None:
    from v3_engine.notebooklm.terminal_service import atomic_json
    payload = dict(saved or json.loads(path.read_text(encoding="utf-8")))
    payload.update({"state": "complete", "result_ref": result_ref})
    atomic_json(path, payload)


def _retain_remote_failure(path: Path, exc: Exception) -> None:
    """Keep bounded upload-stage diagnostics without request bodies, URLs or tokens."""
    allowed_codes = {
        'authentication_required', 'account_mismatch', 'provider_unavailable',
        'provider_rate_limit', 'source_upload_failed', 'source_upload_invalid',
        'provider_unknown_error', 'notebook_create_failed',
    }
    code = getattr(exc, 'code', None)
    details = {'code': code if isinstance(code, str) and code in allowed_codes else 'provider_unavailable'}
    current, seen = exc, set()
    for _ in range(8):
        if current is None or id(current) in seen:
            break
        seen.add(id(current))
        details['exception_type'] = type(current).__name__
        stage = getattr(current, 'stage', None)
        if isinstance(stage, str) and stage in {'start_session', 'upload_finalize'}:
            details['upload_stage'] = stage
        status = getattr(current, 'status_code', None)
        response = getattr(current, 'response', None)
        if status is None and response is not None:
            status = getattr(response, 'status_code', None)
        if isinstance(status, int) and 100 <= status <= 599:
            details['http_status'] = status
        try:
            request = getattr(current, 'request', None)
        except RuntimeError:
            request = None
        if request is not None and request.url.host in {'notebook.google.com', 'notebooklm.google.com'}:
            details['request_host'] = request.url.host
            cookie_names = {part.partition('=')[0].strip() for part in request.headers.get('cookie', '').split(';')}
            details['product_cookie_present'] = bool(cookie_names & {'OSID', '__Secure-OSID'})
            details['account_cookie_present'] = 'SID' in cookie_names
        current = current.__cause__ or current.__context__
    try:
        from v3_engine.notebooklm.terminal_service import atomic_json
        payload = json.loads(path.read_text(encoding='utf-8'))
        payload['failure'] = details
        atomic_json(path, payload)
    except Exception:
        logger.error('Could not retain safe NotebookLM mutation diagnostics')
    logger.warning('NotebookLM mutation failed: %s', json.dumps(details, sort_keys=True))


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def _scene_context(subject_run_id: str, scene_key: str | None = None, scene_root_subject_id: str | None = None,
                  *, provider_required: bool = True, initialize: bool = False, pdf_ordinal: int | None = None):
    """Load frozen educational inputs and captured provider settings for one Spec 017 operation."""
    run_dir = _subject_run_dir(scene_root_subject_id or subject_run_id)
    subject_dir = run_dir
    if pdf_ordinal is not None:
        run_dir = pdf_run_dir(subject_dir, pdf_ordinal)
    elif scene_key and (subject_dir / "private" / "pdf-scene-routing.json").exists():
        routing = read_json(subject_dir / "private" / "pdf-scene-routing.json")
        if scene_key not in routing:
            raise ValueError("scene_not_in_frozen_inventory")
        routed = routing[scene_key]
        # Older per-PDF captures may have an ordinal alone. Preserve them.
        run_dir = pdf_run_dir(subject_dir, routed["pdf_ordinal"] if isinstance(routed, dict) else routed)
        if isinstance(routed, dict):
            scene_key = routed["provider_scene_key"]
    inventory = load_handoff_inventory(run_dir / "lesson_handoffs")
    scene = next((row for row in inventory.scene_inputs if row.scene_key == scene_key), None) if scene_key else None
    if scene_key and scene is None:
        raise ValueError("scene_not_in_frozen_inventory")
    cfg = None
    if provider_required:
        cfg_doc = json.loads((subject_dir / "subject-config.json").read_text(encoding="utf-8"))
        captured_env = dict(os.environ)
        captured_env["V3_GEMINI_THINKING_BUDGET"] = str(cfg_doc.get("gemini_thinking_budget", 24576))
        captured_env["V3_GEMINI_MAX_OUTPUT_TOKENS"] = str(cfg_doc.get("gemini_max_output_tokens", 65536))
        cfg = load_provider_config(cfg_doc["provider"], cfg_doc["model"], cfg_doc["timeout_seconds"], env=captured_env)
    template, template_sha = _resolve_run_template(subject_run_id)
    store = SceneArtifactStore(run_dir / "scene-generation")
    store.acquire_lock(owner_context=f"v3-terminal-{subject_run_id}")
    if initialize:
        raw_inputs = [Path(s.original_path).read_bytes() for s in inventory.input_snapshots]
        store.initialize_run(inventory, cfg, template, template_sha, raw_inputs)
    provider = create_provider(cfg) if provider_required else None
    service = SceneGenerationService(provider, store, SceneBuilder(), template, template_sha)
    return run_dir, inventory, scene, service, provider, store


@activity.defn(name="initialize_terminal_run")
async def initialize_terminal_run(inp: dict[str, Any]) -> dict[str, Any]:
    """Validate immutable snapshots/settings, establish owner marker and initial projection; no external sends."""
    subject_run_id = inp["subject_run_id"]
    run_ref = inp.get("run_ref", f"runs/{subject_run_id}")
    terminal_root = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal")
    run_store = TerminalRunStore(terminal_root=terminal_root, subject_run_id=subject_run_id)
    run_store.initialize_run_dir()
    with run_store.acquire_run_lock(
        owner_workflow_id=f"v3-terminal-subject-{subject_run_id}",
        owner_epoch=inp.get("owner_epoch", 1),
        status="running",
    ):
        pass
    # Only new NotebookLM runs need provider session state. Saved-handoff and
    # build-only recovery must remain independent from credentials entirely.
    if inp.get("mode", "new") == "new" and not inp.get("is_recovery"):
        from v3_engine.notebooklm.credentials import get_storage_state_path
        master = get_storage_state_path()
        isolated = bootstrap_subject_credentials(
            master,
            subject_run_id,
            session_base_dir=run_store.run_dir / "private" / "notebooklm-sessions",
        )
        credential_ref = run_store.run_dir / "private" / "notebooklm-credentials.json"
        credential_ref.write_text(json.dumps({"storage_state_path": str(isolated)}), encoding="utf-8")
        os.chmod(credential_ref, stat.S_IRUSR | stat.S_IWUSR)
    if isinstance(inp.get("subject_config"), dict):
        config_path = run_store.run_dir / "subject-config.json"
        config_path.write_text(json.dumps(inp["subject_config"], sort_keys=True), encoding="utf-8")
        os.chmod(config_path, stat.S_IRUSR | stat.S_IWUSR)
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": None,
        "counts": None,
    }


@activity.defn(name="register_terminal_child")
async def register_terminal_child(inp: dict[str, Any]) -> dict[str, Any]:
    """Persist launch/start evidence in subject registry."""
    subject_run_id = inp["subject_run_id"]
    store_path = inp.get("store_path")
    term_root = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal")
    store = TerminalIndexStore(Path(store_path) if store_path else term_root / "index.sqlite")
    stage = inp.get("stage", "started")
    try:
        store.transition_subject_lifecycle(
            subject_run_id,
            stage,
            started_run_id=inp.get("started_run_id") or activity.info().workflow_run_id,
        )
    except Exception:
        raise
    _record_event(subject_run_id, "subject_started", "preflight", "complete")
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": None,
        "counts": None,
    }


@activity.defn(name="admit_terminal_submission")
async def admit_terminal_submission(inp: dict[str, Any]) -> dict[str, Any]:
    """Durably accept submission and child identities before the supervisor acknowledges admission."""
    term_root = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal")
    store = TerminalIndexStore(term_root / "index.sqlite")
    receipt = store.register_submission_bundle(inp)
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": receipt.get("manifest_ref"),
        "counts": {"subjects": receipt.get("subject_count", 0), "duplicate": int(bool(receipt.get("duplicate")))},
    }


@activity.defn(name="admit_terminal_recovery")
async def admit_terminal_recovery(inp: dict[str, Any]) -> dict[str, Any]:
    """Persist recovery reservation and child identity before Temporal acceptance."""
    term_root = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal")
    store = TerminalIndexStore(term_root / "index.sqlite")
    try:
        receipt = store.admit_recovery(inp)
    except Exception as exc:
        code = getattr(getattr(exc, "safe_code", None), "value", None) or "storage_unavailable"
        return {"schema_version": 1, "outcome": "rejected", "safe_code": code,
                "recovery_id": inp.get("recovery_id")}
    return {"schema_version": 1, "outcome": "complete", "safe_code": SafeCode.ok.value,
            "recovery_id": receipt["recovery_id"],
            "recovery_subject_id": receipt["recovery_subject_id"],
            "counts": {"duplicate": int(bool(receipt.get("duplicate")))}}


@activity.defn(name="settle_terminal_child")
async def settle_terminal_child(inp: dict[str, Any]) -> dict[str, Any]:
    """Persist terminal chain evidence in subject registry with real close confirmation."""
    subject_run_id = inp["subject_run_id"]
    terminal_status = inp.get("terminal_status", "completed")
    store_path = inp.get("store_path")
    term_root = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal")
    store = TerminalIndexStore(Path(store_path) if store_path else term_root / "index.sqlite")
    close_proof = inp.get("close_proof") or {"workflow_run_id": activity.info().workflow_run_id, "status": terminal_status}
    try:
        store.settle_subject(
            subject_run_id,
            terminal_status=terminal_status,
            final_report_ref=inp.get("final_report_ref"),
            close_proof=close_proof,
            recovery_id=inp.get("recovery_id"),
        )
    except Exception:
        raise
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": None,
        "counts": None,
    }


@activity.defn(name="verify_notebook_session")
async def verify_notebook_session(inp: dict[str, Any]) -> dict[str, Any]:
    """Open isolated SDK copy, CSRF bootstrap, explicit proof ownership; save safe verification record."""
    proof_notebook_id = inp.get("proof_notebook_id", "")
    storage_path = Path(inp["credential_path"]) if "credential_path" in inp else _subject_credential_path(inp["subject_run_id"])
    service = NotebookTerminalService()
    # Resuming a held workflow schedules a new verification activity. Its event
    # must not collide with the earlier failure, while redelivery of this same
    # activity attempt must remain idempotent.
    try:
        info = activity.info()
        verification_id = f"session-check:{info.workflow_run_id}:{info.activity_id}"
        attempt = info.attempt
    except RuntimeError:
        verification_id, attempt = None, 1
    outcome, code = ActivityOutcome.complete.value, SafeCode.ok.value
    try:
        await service.verify_session(proof_notebook_id, storage_path=storage_path)
    except Exception as exc:
        logger.warning("verify_notebook_session provider check failed (%s)", type(exc).__name__)
        outcome = ActivityOutcome.failed.value
        known_code = getattr(exc, 'code', None)
        code = {
            'authentication_required': SafeCode.authentication_required.value,
            'account_mismatch': SafeCode.account_mismatch.value,
            'provider_rate_limit': SafeCode.provider_rate_limit.value,
        }.get(known_code, SafeCode.provider_unavailable.value)
    if verification_id is None:
        # Direct, non-Temporal diagnostic/test calls still retain distinct
        # failed and successful observations without rewriting history.
        verification_id = f"session-check:{outcome}:{code}"
    try:
        _record_event(inp["subject_run_id"], "notebook_session_verified", "preflight", outcome, code,
                      operation_id=verification_id, attempt=attempt)
    except Exception:
        logger.error("NotebookLM verification result could not be recorded in the local journal")
        outcome, code = ActivityOutcome.failed.value, SafeCode.storage_unavailable.value
    return {
        "schema_version": 1, "outcome": outcome, "safe_code": code,
        "receipt_ref": None, "result_ref": None, "counts": None,
    }


async def _recover_remote_operation(inp, operation_type, operation_id, op_path, prior, storage_path, **criteria):
    """Read-only reconciliation of a retained create/upload/configure intent."""
    service = NotebookTerminalService()
    observed = {'outcome': 'uncertain'}
    for attempt in range(3):
        try:
            observed = await service.reconcile(operation_type=operation_type, storage_path=storage_path, **criteria)
        except AuthenticationRequiredError:
            break
        except Exception:
            observed = {'outcome': 'uncertain'}
        if observed.get('outcome') != 'uncertain' or attempt == 2:
            break
        # Provider inventories can lag a committed upload/create. Repeat only
        # read-only reconciliation; never resend the original mutation here.
        await asyncio.sleep(1)
    if operation_type == 'format' and observed.get('configuration_matches') is False:
        # A successful read proves the current setting differs. Reapplying
        # this convergent setter cannot duplicate a notebook/chat request;
        # require positive readback before settling its receipt.
        try:
            await service.configure_chat(criteria['notebook_id'], criteria['format_text'], storage_path=storage_path)
            observed = await service.reconcile(operation_type=operation_type, storage_path=storage_path, **criteria)
        except Exception:
            observed = {'outcome': 'uncertain'}
    if observed.get('outcome') != 'complete':
        return _safe_provider_failure(inp['subject_run_id'], operation_type + '_reconcile', operation_type,
                                      operation_id=operation_id, uncertain=True)
    result_ref = observed.get('notebook_id') if operation_type == 'create' else observed.get('source_id') if operation_type == 'upload' else None
    if operation_type in ('create', 'upload') and not result_ref:
        return _safe_provider_failure(inp['subject_run_id'], operation_type + '_reconcile', operation_type,
                                      operation_id=operation_id, uncertain=True)
    _finish_remote_operation(op_path, prior, result_ref=result_ref)
    _record_event(inp['subject_run_id'], operation_type + '_recovered', operation_type, 'complete', operation_id=operation_id)
    return {'schema_version': 1, 'outcome': 'complete', 'safe_code': 'ok',
            'receipt_ref': str(op_path), 'result_ref': result_ref, 'counts': None}


@activity.defn(name="create_terminal_notebook")
async def create_terminal_notebook(inp: dict[str, Any]) -> dict[str, Any]:
    """Unique title contains full subject UUID; create once and retain binding receipt."""
    title = inp.get("title", f"V3 Mechanics {inp['subject_run_id']}")
    storage_path = Path(inp["credential_path"]) if "credential_path" in inp else _subject_credential_path(inp["subject_run_id"])
    operation_id = f"create:{inp['subject_run_id']}"
    digest = hashlib.sha256(json.dumps({"title": title}, sort_keys=True).encode()).hexdigest()
    op_path, prior = _begin_remote_operation(inp["subject_run_id"], operation_id, digest)
    if prior:
        if prior.get("state") == "complete":
            return {"schema_version": 1, "outcome": ActivityOutcome.complete.value, "safe_code": SafeCode.ok.value,
                    "receipt_ref": str(op_path), "result_ref": prior.get("result_ref"), "counts": None}
        return await _recover_remote_operation(inp, 'create', operation_id, op_path, prior, storage_path, title=title)
    service = NotebookTerminalService()
    try:
        ref = await service.create_notebook(title, storage_path=storage_path)
    except Exception as exc:
        # The create request may have committed before its acknowledgment was
        # lost. Read the unique run title now; a positive match settles the
        # existing operation without issuing another create request.
        _retain_remote_failure(op_path, exc)
        return await _recover_remote_operation(
            inp, 'create', operation_id, op_path, prior, storage_path, title=title,
        )
    nb_id = ref.id if hasattr(ref, "id") else str(ref)
    _finish_remote_operation(op_path, prior, result_ref=nb_id)
    _record_event(inp["subject_run_id"], "notebook_created", "create", "complete", operation_id=operation_id)
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": str(op_path),
        "result_ref": nb_id,
        "counts": None,
    }


@activity.defn(name="upload_terminal_source")
async def upload_terminal_source(inp: dict[str, Any]) -> dict[str, Any]:
    """Upload exactly one captured PDF/framework snapshot; save source receipt."""
    notebook_id = inp["notebook_id"]
    local_path = Path(inp["source_path"])
    mime_type = inp["mime_type"]
    title = inp["title"]
    storage_path = Path(inp["credential_path"]) if "credential_path" in inp else _subject_credential_path(inp["subject_run_id"])
    operation_id = f"upload:{notebook_id}:{title}"
    source_digest = _sha256_file(local_path)
    if inp.get("expected_sha256") and source_digest != inp["expected_sha256"]:
        return {"schema_version": 1, "outcome": "failed", "safe_code": "input_changed", "counts": None}
    request_digest = hashlib.sha256(json.dumps({"notebook_id": notebook_id, "source_sha256": source_digest,
        "mime_type": mime_type, "title": title}, sort_keys=True).encode()).hexdigest()
    op_path, prior = _begin_remote_operation(inp["subject_run_id"], operation_id, request_digest)
    if prior:
        if prior.get("state") == "complete":
            ref = prior.get("result_ref")
            return {"schema_version": 1, "outcome": ActivityOutcome.complete.value, "safe_code": SafeCode.ok.value,
                    "receipt_ref": ref, "result_ref": ref, "counts": None}
        return await _recover_remote_operation(inp, 'upload', operation_id, op_path, prior, storage_path,
                                               notebook_id=notebook_id, title=title)
    service = NotebookTerminalService()
    try:
        receipt = await service.upload_source(
            notebook_id=notebook_id,
            local_path=local_path,
            mime_type=mime_type,
            title=title,
            storage_path=storage_path,
        )
    except Exception as exc:
        # A lost upload acknowledgment is resolved by listing this run's
        # uniquely titled, fully processed source in the new notebook. A
        # registration row alone does not prove that file bytes arrived.
        _retain_remote_failure(op_path, exc)
        return await _recover_remote_operation(
            inp, 'upload', operation_id, op_path, prior, storage_path,
            notebook_id=notebook_id, title=title,
        )
    _finish_remote_operation(op_path, prior, result_ref=receipt.source_id)
    _record_event(inp["subject_run_id"], "source_uploaded", "upload", "complete", operation_id=operation_id)
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": receipt.source_id,
        "result_ref": receipt.source_id,
        "counts": None,
    }


@activity.defn(name="poll_terminal_sources")
async def poll_terminal_sources(inp: dict[str, Any]) -> dict[str, Any]:
    """Read inventory/status for captured source refs; immutable ID/MIME/inventory validation."""
    notebook_id = inp["notebook_id"]
    # Each readiness poll is a distinct journal event; retries of this same
    # Activity retain the same ordinal and therefore remain idempotent.
    poll_ordinal = int(inp.get("poll_ordinal", 1))
    if poll_ordinal < 1:
        return {
            "schema_version": 1,
            "outcome": ActivityOutcome.failed.value,
            "safe_code": SafeCode.input_invalid.value,
            "receipt_ref": None,
            "result_ref": None,
            "counts": None,
        }
    operation_id = (f"source-readiness-poll:pdf-{inp['pdf_ordinal']:04d}:{poll_ordinal}"
                    if "pdf_ordinal" in inp else f"source-readiness-poll:{poll_ordinal}")
    storage_path = Path(inp["credential_path"]) if "credential_path" in inp else _subject_credential_path(inp["subject_run_id"])
    service = NotebookTerminalService()
    try:
        sources = await service.poll_sources(notebook_id, storage_path=storage_path)
    except Exception:
        return _safe_provider_failure(
            inp["subject_run_id"], "source_readiness_checked", "poll", operation_id=operation_id
        )
    selected = inp.get("source_ids")
    if selected is not None:
        by_id = {s.source_id: s for s in sources}
        if not selected or any(source_id not in by_id for source_id in selected):
            return {"schema_version": 1, "outcome": "failed", "safe_code": "source_error", "counts": None}
        sources = [by_id[source_id] for source_id in selected]
        if any(s.status == "error" for s in sources):
            return {"schema_version": 1, "outcome": "failed", "safe_code": "source_error", "counts": None}
    ready_count = sum(s.status == "ready" for s in sources)
    all_ready = (ready_count == len(sources)) and (len(sources) > 0)
    _record_event(
        inp["subject_run_id"], "source_readiness_checked", "poll",
        "complete" if all_ready else "pending",
        operation_id=operation_id,
        counts={"ready": ready_count, "total": len(sources)},
    )
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value if all_ready else ActivityOutcome.pending.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": None,
        "counts": {"ready": ready_count, "total": len(sources)},
    }


@activity.defn(name="configure_terminal_chat")
async def configure_terminal_chat(inp: dict[str, Any]) -> dict[str, Any]:
    """Apply existing combined response-format instruction unchanged; read it back; reconcile uncertainty."""
    notebook_id = inp["notebook_id"]
    format_text = inp.get("format_text", "")
    storage_path = Path(inp["credential_path"]) if "credential_path" in inp else _subject_credential_path(inp["subject_run_id"])
    operation_id = f"configure:{notebook_id}"
    request_digest = hashlib.sha256(json.dumps({"notebook_id": notebook_id,
        "format_sha256": hashlib.sha256(format_text.encode("utf-8")).hexdigest()}, sort_keys=True).encode()).hexdigest()
    op_path, prior = _begin_remote_operation(inp["subject_run_id"], operation_id, request_digest)
    if prior:
        if prior.get("state") == "complete":
            return {"schema_version": 1, "outcome": ActivityOutcome.complete.value, "safe_code": SafeCode.ok.value,
                    "receipt_ref": str(op_path), "result_ref": None, "counts": None}
        return await _recover_remote_operation(inp, 'format', operation_id, op_path, prior, storage_path,
                                               notebook_id=notebook_id, format_text=format_text)
    service = NotebookTerminalService()
    try:
        await service.configure_chat(notebook_id, format_text, storage_path=storage_path)
    except Exception:
        # This is a convergent setter. Reconcile by reading the captured
        # format first; _recover_remote_operation reapplies it only after a
        # positive read proves the previous write did not take effect.
        return await _recover_remote_operation(
            inp, 'format', operation_id, op_path, prior, storage_path,
            notebook_id=notebook_id, format_text=format_text,
        )
    _finish_remote_operation(op_path, prior, result_ref=None)
    _record_event(inp["subject_run_id"], "chat_configured", "configure", "complete", operation_id=operation_id)
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": None,
        "counts": None,
    }


@activity.defn(name="send_terminal_turn")
async def send_terminal_turn(inp: dict[str, Any]) -> dict[str, Any]:
    """One Divide/Execution/Lesson request with captured source refs/history anchor; save exact answer/citations before return."""
    notebook_id = inp["notebook_id"]
    question = inp["question"]
    source_ids = inp["source_ids"]
    conversation_id = inp.get("conversation_id")
    storage_path = Path(inp["credential_path"]) if "credential_path" in inp else _subject_credential_path(inp["subject_run_id"])
    service = NotebookTerminalService()
    run_dir = _subject_run_dir(inp["subject_run_id"])
    receipt_dir = run_dir / "private" / "turn-receipts"
    receipt_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    operation_id = inp.get("operation_id")
    if not isinstance(operation_id, str) or not operation_id:
        raise ValueError("operation_id is required for durable NotebookLM turns")
    receipt_id = f"turn-{hashlib.sha256(operation_id.encode('utf-8')).hexdigest()[:24]}"
    turn_file = receipt_dir / f"{receipt_id}.json"
    request = {
        "notebook_id": notebook_id, "question": question,
        "source_ids": source_ids, "conversation_id": conversation_id,
    }
    fresh_after = inp.get("fresh_after_conversation_id")
    if fresh_after is not None:
        request["fresh_after_conversation_id"] = fresh_after
    request_digest = hashlib.sha256(json.dumps(request, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()
    from v3_engine.notebooklm.terminal_service import atomic_json

    async def reconcile_response(expected_index, prefix, conversation_hint):
        recovered_conversation = conversation_hint
        for attempt in range(3):
            try:
                if not recovered_conversation or recovered_conversation == fresh_after:
                    recovered_conversation = await service.get_current_conversation_id(
                        notebook_id, storage_path=storage_path,
                    )
                if not recovered_conversation or recovered_conversation == fresh_after:
                    recovered = {"outcome": "uncertain"}
                else:
                    recovered = await service.reconcile_chat_turn(
                        notebook_id, recovered_conversation, question, int(expected_index), prefix,
                        storage_path=storage_path,
                    )
            except Exception:
                recovered = {"outcome": "uncertain"}
            if recovered.get("outcome") == "complete":
                answer_text = recovered.get("raw_answer")
                if isinstance(answer_text, str) and answer_text.strip():
                    return {"outcome": "complete", "conversation_id": recovered_conversation,
                            "raw_answer": answer_text, "turn_index": recovered.get("turn_index")}
                recovered = {"outcome": "uncertain"}
            if recovered.get("outcome") == "blocked" or attempt == 2:
                return {"outcome": "uncertain"}
            await asyncio.sleep(1)
        return {"outcome": "uncertain"}

    def persist_recovered_response(recovered, expected_index, prefix):
        recovered_conversation = recovered["conversation_id"]
        atomic_json(turn_file, {
            "schema_version": 1, "operation_id": operation_id, "state": "complete",
            "request_digest": request_digest, "notebook_id": notebook_id,
            "conversation_id": recovered_conversation, "question": question,
            "request_conversation_id": conversation_id, "source_ids": source_ids,
            **({"fresh_after_conversation_id": fresh_after} if fresh_after is not None else {}),
            "raw_answer": recovered["raw_answer"], "turn_number": recovered.get("turn_index"),
            "expected_turn_index": expected_index, "history_prefix": prefix,
        })
        _record_event(inp["subject_run_id"], "notebook_turn_recovered", "chat", "complete", operation_id=operation_id)
        return {"schema_version": 1, "outcome": ActivityOutcome.complete.value, "safe_code": SafeCode.ok.value,
                "receipt_ref": receipt_id, "result_ref": recovered_conversation, "counts": None}

    if turn_file.exists():
        prior = json.loads(turn_file.read_text(encoding="utf-8"))
        if prior.get("request_digest") != request_digest:
            raise ValueError("turn_receipt_conflict")
        if prior.get("state") == "complete":
            _record_event(inp["subject_run_id"], "notebook_turn_reused", "chat", "complete", operation_id=operation_id)
            return {
                "schema_version": 1, "outcome": ActivityOutcome.complete.value, "safe_code": SafeCode.ok.value,
                "receipt_ref": receipt_id, "result_ref": prior.get("conversation_id"), "counts": None,
            }
        # A dispatch intent exists without a committed response. Reconcile by history;
        # never issue a second paid chat mutation automatically.
        if prior.get("expected_turn_index") is not None:
            recovered = await reconcile_response(
                int(prior["expected_turn_index"]), prior.get("history_prefix"),
                prior.get("conversation_id") or conversation_id,
            )
            if recovered.get("outcome") == "complete":
                return persist_recovered_response(recovered, int(prior["expected_turn_index"]), prior.get("history_prefix"))
        return {"schema_version": 1, "outcome": ActivityOutcome.uncertain.value, "safe_code": SafeCode.delivery_uncertain.value,
                "receipt_ref": receipt_id, "result_ref": None, "counts": None}

    history_prefix: list[str] = []
    expected_turn_index = 0
    if conversation_id:
        try:
            history = await service.get_history(notebook_id, conversation_id, storage_path=storage_path)
        except AuthenticationRequiredError:
            return _safe_provider_failure(
                inp["subject_run_id"], "notebook_turn_history", "chat",
                operation_id=operation_id, safe_code=SafeCode.authentication_required,
            )
        except Exception:
            return _safe_provider_failure(inp["subject_run_id"], "notebook_turn_history", "chat")
        history_prefix = [row[0] for row in history]
        expected_turn_index = len(history)
    atomic_json(turn_file, {
        "schema_version": 1, "operation_id": operation_id, "state": "dispatching",
        "request_digest": request_digest, "notebook_id": notebook_id,
        "conversation_id": conversation_id, "question": question,
        "request_conversation_id": conversation_id, "source_ids": source_ids,
        **({"fresh_after_conversation_id": fresh_after} if fresh_after is not None else {}),
        "expected_turn_index": expected_turn_index, "history_prefix": history_prefix,
    })
    _record_event(inp["subject_run_id"], "notebook_turn_dispatching", "chat", "requested", operation_id=operation_id)
    try:
        answer = await service.send_turn(notebook_id, question, source_ids, conversation_id, storage_path,
                                        **({"fresh_after_conversation_id": fresh_after} if fresh_after is not None else {}))
    except Exception:
        # Check the captured conversation history before holding the workflow.
        # A matching completed turn settles this exact intent without a second
        # NotebookLM chat mutation; an unreadable/incomplete history stays held.
        recovered = await reconcile_response(expected_turn_index, history_prefix, conversation_id)
        if recovered.get("outcome") == "complete":
            return persist_recovered_response(recovered, expected_turn_index, history_prefix)
        try:
            _record_event(inp["subject_run_id"], "notebook_turn_uncertain", "chat", "uncertain", SafeCode.delivery_uncertain.value, operation_id=operation_id)
        except Exception:
            logger.error("terminal journal write failed for notebook turn")
        return {
            "schema_version": 1, "outcome": ActivityOutcome.uncertain.value,
            "safe_code": SafeCode.delivery_uncertain.value, "receipt_ref": receipt_id,
            "result_ref": None, "counts": None,
        }
    atomic_json(turn_file, {
        "schema_version": 1, "operation_id": operation_id, "state": "complete",
        "request_digest": request_digest, "notebook_id": notebook_id,
        "conversation_id": answer.conversation_id, "question": question,
        "request_conversation_id": conversation_id, "source_ids": source_ids,
        **({"fresh_after_conversation_id": fresh_after} if fresh_after is not None else {}),
        "raw_answer": answer.text, "turn_number": answer.turn_number,
        "expected_turn_index": expected_turn_index, "history_prefix": history_prefix,
    })
    _record_event(inp["subject_run_id"], "notebook_turn_saved", "chat", "complete", operation_id=operation_id)

    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": receipt_id,
        "result_ref": answer.conversation_id,
        "counts": None,
    }


@activity.defn(name="capture_terminal_turn")
async def capture_terminal_turn(inp: dict[str, Any]) -> dict[str, Any]:
    """Parse map or best-effort export saved receipt, update local checkpoint idempotently; never asks a model."""
    turn_kind = inp["turn_kind"]
    raw_answer = inp.get("raw_answer", "")
    run_dir = inp.get("run_dir") or str(_subject_run_dir(inp["subject_run_id"]))
    receipt_ref = inp.get("receipt_ref")
    if not raw_answer and run_dir and receipt_ref:
        turn_file = Path(run_dir) / "private" / "turn-receipts" / f"{receipt_ref}.json"
        if turn_file.exists():
            saved_turn = json.loads(turn_file.read_text(encoding="utf-8"))
            raw_answer = saved_turn.get("raw_answer", "")

    service = NotebookTerminalService()
    pdf_ordinal = inp.get("pdf_ordinal")
    scope_dir = pdf_run_dir(Path(run_dir), pdf_ordinal) if pdf_ordinal is not None else Path(run_dir)
    mapped_title = inp.get("mapped_title")
    if pdf_ordinal is not None and turn_kind == "lesson" and not mapped_title:
        divide_path = scope_dir / "private" / "turn-captures" / "divide.json"
        if divide_path.exists():
            nodes = read_json(divide_path)["captured"]["nodes"]
            mapped_title = next((node["title"] for node in nodes if node.get("work_ordinal") == inp["lesson_ordinal"]), None)
    if pdf_ordinal is not None and turn_kind == "execution" and raw_answer.strip() != "System Ready":
        return {"schema_version": 1, "outcome": "failed", "safe_code": "receipt_invalid", "counts": None}
    if pdf_ordinal is not None:
        name = f"lesson-{inp['lesson_ordinal']:03d}" if turn_kind == "lesson" else turn_kind
        capture_path = scope_dir / "private" / "turn-captures" / f"{name}.json"
        if capture_path.exists():
            saved = read_json(capture_path)
            if (saved["raw_answer_sha256"] != hashlib.sha256(raw_answer.encode("utf-8")).hexdigest()
                    or saved["conversation_id"] != inp.get("conversation_id")
                    or saved["pdf_source_ids"] != inp.get("pdf_source_ids", [])
                    or saved["notebook_id"] != inp.get("notebook_id")
                    or saved["framework_source_id"] != inp.get("framework_source_id")
                    or saved["receipt_ref"] != receipt_ref):
                raise ValueError("pdf_capture_conflict")
            captured = saved["captured"]
            if turn_kind == "lesson" and read_json(Path(captured["handoff_path"])) != captured["handoff_data"]:
                raise ValueError("pdf_handoff_corrupt")
            return {"schema_version": 1, "outcome": "complete", "safe_code": "ok",
                    "receipt_ref": captured.get("handoff_path"), "result_ref": None,
                    "counts": {key: captured[key] for key in ("work_count", "scene_count") if key in captured}}
    pdf_metadata = None
    config_path = Path(run_dir) / "subject-config.json"
    if pdf_ordinal is not None and config_path.exists():
        source = next(row for row in read_json(config_path)["inputs"] if row["kind"] == "pdf" and row["ordinal"] == pdf_ordinal)
        pdf_metadata = {"ordinal": pdf_ordinal, "sha256": source["sha256"],
                        "original_filename": Path(source["original_path"]).name}
    captured = service.capture_turn(
        turn_kind=turn_kind,
        raw_answer=raw_answer,
        capture_raw=inp.get("capture_raw", True),
        lesson_ordinal=inp.get("lesson_ordinal"),
        mapped_title=mapped_title,
        output_dir=scope_dir / "lesson_handoffs" if pdf_ordinal is not None else (Path(inp["output_dir"]) if "output_dir" in inp else scope_dir / "lesson_handoffs"),
        notebook_id=inp.get("notebook_id"),
        conversation_id=inp.get("conversation_id"),
        pdf_source_ids=inp.get("pdf_source_ids"),
        framework_source_id=inp.get("framework_source_id"),
        **({"pdf_metadata": pdf_metadata} if pdf_ordinal is not None else {}),
    )
    if pdf_ordinal is not None:
        name = f"lesson-{inp['lesson_ordinal']:03d}" if turn_kind == "lesson" else turn_kind
        retain_json(scope_dir / "private" / "turn-captures" / f"{name}.json", {
            "captured": captured, "receipt_ref": receipt_ref, "notebook_id": inp.get("notebook_id"),
            "conversation_id": inp.get("conversation_id"), "pdf_source_ids": inp.get("pdf_source_ids", []),
            "framework_source_id": inp.get("framework_source_id"),
            "raw_answer_sha256": hashlib.sha256(raw_answer.encode("utf-8")).hexdigest(),
        })
    _record_event(inp["subject_run_id"], "notebook_turn_captured", turn_kind, "complete", operation_id=receipt_ref)
    counts = {}
    if "work_count" in captured:
        counts["work_count"] = captured["work_count"]
    if "scene_count" in captured:
        counts["scene_count"] = captured["scene_count"]

    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": captured.get("handoff_path"),
        "result_ref": None,
        "counts": counts,
    }


@activity.defn(name="archive_terminal_pdf")
async def archive_terminal_pdf(inp: dict[str, Any]) -> dict[str, Any]:
    try:
        path, archive = archive_pdf(_subject_run_dir(inp["subject_run_id"]), inp["pdf_ordinal"])
    except (OSError, ValueError, KeyError, StopIteration, TypeError):
        return {"schema_version": 1, "outcome": "failed", "safe_code": "receipt_invalid", "counts": None}
    counts = {"lesson_count": archive["work_count"], "scene_count": archive["scene_count"]}
    _record_event(inp["subject_run_id"], "pdf_archived", "archive", "complete",
                  operation_id=f"pdf-{inp['pdf_ordinal']:04d}:archive", counts=counts)
    return {"schema_version": 1, "outcome": "complete", "safe_code": "ok",
            "result_ref": str(path), "receipt_ref": None, "counts": counts}


@activity.defn(name="reset_terminal_conversation")
async def reset_terminal_conversation(inp: dict[str, Any]) -> dict[str, Any]:
    """Clear only an archived conversation. An uncertain deletion is inspected, never resent."""
    subject_id = inp["subject_run_id"]
    ordinal = inp["pdf_ordinal"]
    run_dir = _subject_run_dir(subject_id)
    try:
        archive = verify_pdf_archive(run_dir, ordinal)
        if archive["notebook_id"] != inp["notebook_id"] or archive["conversation_id"] != inp["conversation_id"]:
            raise ValueError("pdf_reset_lineage_conflict")
        archive_path = pdf_run_dir(run_dir, ordinal) / "private" / "archive.json"
        digest = _sha256_file(archive_path)
        operation_id = f"pdf-{ordinal:04d}:reset:{archive['conversation_id']}"
        op_path, prior = _begin_remote_operation(subject_id, operation_id, digest)
    except (OSError, ValueError, KeyError, TypeError):
        return {"schema_version": 1, "outcome": "failed", "safe_code": "receipt_invalid", "counts": None}
    service = NotebookTerminalService()
    storage_path = _subject_credential_path(subject_id)
    if not prior or prior.get("state") != "complete":
        try:
            current = await service.get_current_conversation_id(inp["notebook_id"], storage_path=storage_path)
            async def reset_is_proven(current_id):
                if current_id is None:
                    return True
                if current_id != archive["conversation_id"]:
                    return False
                history = await service.get_history(inp["notebook_id"], current_id, storage_path=storage_path)
                return isinstance(history, list) and not history
            if prior:
                if not await reset_is_proven(current):
                    return _safe_provider_failure(subject_id, "conversation_reset_uncertain", "reset", uncertain=True,
                                                  operation_id=operation_id)
                # Absence or empty turns on the exact stale ID confirms deletion.
            else:
                if current != archive["conversation_id"]:
                    return {"schema_version": 1, "outcome": "failed", "safe_code": "history_conflict", "counts": None}
                await service.reset_conversation(inp["notebook_id"], archive["conversation_id"], storage_path=storage_path)
                current = await service.get_current_conversation_id(inp["notebook_id"], storage_path=storage_path)
                if not await reset_is_proven(current):
                    return _safe_provider_failure(subject_id, "conversation_reset_uncertain", "reset", uncertain=True,
                                                  operation_id=operation_id)
            _finish_remote_operation(op_path, prior, result_ref=None)
        except Exception:
            return _safe_provider_failure(subject_id, "conversation_reset_uncertain", "reset", uncertain=True,
                                          operation_id=operation_id)
    _record_event(subject_id, "conversation_reset", "reset", "complete", operation_id=operation_id)
    return {"schema_version": 1, "outcome": "complete", "safe_code": "ok",
            "receipt_ref": str(op_path), "result_ref": None, "counts": None}


@activity.defn(name="freeze_terminal_handoffs")
async def freeze_terminal_handoffs(inp: dict[str, Any]) -> dict[str, Any]:
    """Validate every mapped ordinal captured, snapshot handoffs and initialize 017 scene inventory/config."""
    mode = inp.get("mode", "new")
    run_dir = _subject_run_dir(inp["subject_run_id"])
    subject_dir = run_dir
    if inp.get("pdf_processing") == "per_pdf":
        routing: dict[str, dict[str, Any]] = {}
        counts = {"lesson_count": 0, "scene_count": 0, "issue_count": 0, "pdf_count": 0}
        for ordinal, scope, inventory, archive in scoped_inventories(subject_dir):
            if ordinal is None:
                raise ValueError("per_pdf_config_missing")
            result = await freeze_terminal_handoffs({
                "subject_run_id": inp["subject_run_id"], "pdf_ordinal": ordinal,
                "handoff_dir": str(scope / "lesson_handoffs"), "expected_lesson_count": archive["work_count"],
            })
            counts["pdf_count"] += 1
            counts["lesson_count"] += archive["work_count"]
            counts["scene_count"] += len(inventory.scene_inputs)
            counts["issue_count"] += result["counts"]["issue_count"]
            for scene in inventory.scene_inputs:
                key = terminal_scene_key(ordinal, scene.scene_key)
                if key in routing:
                    raise ValueError("pdf_scene_identity_conflict")
                routing[key] = {"pdf_ordinal": ordinal, "provider_scene_key": scene.scene_key}
        if counts["pdf_count"] != inp["expected_pdf_count"] or counts["lesson_count"] != inp["expected_lesson_count"]:
            raise ValueError("pdf_subject_archive_incomplete")
        retain_json(subject_dir / "private" / "pdf-scene-routing.json", routing)
        retain_json(subject_dir / "private" / "scene-inventory.json", list(routing))
        _record_event(inp["subject_run_id"], "handoffs_frozen", "freeze", "complete", counts=counts)
        return {"schema_version": 1, "outcome": "complete", "safe_code": "ok",
                "result_ref": str(subject_dir / "private" / "scene-inventory.json"), "counts": counts}
    pdf_ordinal = inp.get("pdf_ordinal")
    if pdf_ordinal is not None:
        run_dir = pdf_run_dir(subject_dir, pdf_ordinal)
    freeze_operation = f"pdf-{pdf_ordinal:04d}:freeze" if pdf_ordinal is not None else None
    frozen_dir = run_dir / "lesson_handoffs"
    if mode == "saved" or "handoff_path" in inp:
        h_path = Path(inp.get("handoff_path") or inp.get("handoff_dir"))
        if h_path.is_dir():
            shutil.copytree(h_path, frozen_dir, dirs_exist_ok=True)
        else:
            frozen_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
            shutil.copy2(h_path, frozen_dir / "lesson-001.json")
    else:
        handoff_dir = Path(inp["handoff_dir"])
        if not handoff_dir.is_absolute():
            handoff_dir = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal") / handoff_dir
        expected_count = inp.get("expected_lesson_count", 1)
        service = NotebookTerminalService()
        service.freeze_handoffs(handoff_dir=handoff_dir, expected_lesson_count=expected_count)
        if handoff_dir.resolve() != frozen_dir.resolve():
            shutil.copytree(handoff_dir, frozen_dir, dirs_exist_ok=True)

    inventory = load_handoff_inventory(frozen_dir)
    scene_ref = run_dir / "private" / "scene-inventory.json"
    scene_ref.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    scene_ref.write_text(json.dumps([s.scene_key for s in inventory.scene_inputs]), encoding="utf-8")
    os.chmod(scene_ref, stat.S_IRUSR | stat.S_IWUSR)
    # Captured Temporal submissions carry provider settings and pre-initialize
    # Spec 017 here. Standalone saved-handoff imports can freeze inventory only;
    # generation remains blocked until an explicit provider config exists.
    config_path = subject_dir / "subject-config.json"
    if config_path.is_file():
        config_doc = json.loads(config_path.read_text(encoding="utf-8"))
        if config_doc.get("scene_generation_mode", "generate") == "export_prompts":
            _record_event(inp["subject_run_id"], "scene_prompt_export_mode", "freeze", "complete",
                          operation_id=freeze_operation, counts={"scenes": len(inventory.scene_inputs)})
            return {
                "schema_version": 1,
                "outcome": ActivityOutcome.complete.value,
                "safe_code": SafeCode.ok.value,
                "receipt_ref": None,
                "result_ref": str(scene_ref),
                "counts": {"lesson_count": len({s.lesson_ordinal for s in inventory.scene_inputs}),
                           "scene_count": len(inventory.scene_inputs), "issue_count": len(inventory.input_issues)},
            }
        _, _, _, _, provider, store = _scene_context(inp["subject_run_id"], provider_required=True, initialize=True, pdf_ordinal=pdf_ordinal)
        await provider.aclose()
        store.close()
    _record_event(inp["subject_run_id"], "handoffs_frozen", "freeze", "complete", operation_id=freeze_operation, counts={"lessons": len(inventory.input_snapshots), "scenes": len(inventory.scene_inputs), "issues": len(inventory.input_issues)})
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": str(scene_ref),
        "counts": {"lesson_count": len(inventory.input_snapshots), "scene_count": len(inventory.scene_inputs), "issue_count": len(inventory.input_issues)},
    }


@activity.defn(name="export_terminal_scene_prompts")
async def export_terminal_scene_prompts(inp: dict[str, Any]) -> dict[str, Any]:
    """Export every frozen NotebookLM scene prompt without creating a provider or sending API requests."""
    subject_run_id = inp["subject_run_id"]
    run_dir = _subject_run_dir(subject_run_id)
    inventories = list(scoped_inventories(run_dir))
    if len(inventories) > 1 or inventories[0][0] is not None:
        return _export_per_pdf_prompts(subject_run_id, run_dir, inventories)
    inventory = inventories[0][2]
    if inventory.input_issues:
        _record_event(subject_run_id, "scene_prompts_exported", "export_prompts", "failed",
                      SafeCode.input_invalid.value, counts={"issues": len(inventory.input_issues)})
        return {"schema_version": 1, "outcome": ActivityOutcome.failed.value,
                "safe_code": SafeCode.input_invalid.value, "receipt_ref": None, "result_ref": None,
                "counts": {"issue_count": len(inventory.input_issues)}}
    if not inventory.scene_inputs or any(not isinstance(scene.prompt, str) or not scene.prompt.strip()
                                         for scene in inventory.scene_inputs):
        _record_event(subject_run_id, "scene_prompts_exported", "export_prompts", "failed",
                      SafeCode.input_invalid.value)
        return {"schema_version": 1, "outcome": ActivityOutcome.failed.value,
                "safe_code": SafeCode.input_invalid.value, "receipt_ref": None, "result_ref": None,
                "counts": {"scene_count": len(inventory.scene_inputs)}}

    scenes = sorted(inventory.scene_inputs, key=lambda scene: (scene.lesson_ordinal, scene.scene_ordinal))
    from v3_engine.scene_generation.service import OUTPUT_CONTRACT_TEXT
    system_prompt, system_prompt_sha256 = _resolve_run_template(subject_run_id)
    actual_lessons = len({scene.lesson_ordinal for scene in scenes})
    if inventory.expected_work_count is not None and actual_lessons != inventory.expected_work_count:
        _record_event(subject_run_id, "scene_prompts_exported", "export_prompts", "failed",
                      SafeCode.input_invalid.value,
                      counts={"expected_lessons": inventory.expected_work_count, "actual_lessons": actual_lessons})
        return {"schema_version": 1, "outcome": ActivityOutcome.failed.value,
                "safe_code": SafeCode.input_invalid.value, "receipt_ref": None, "result_ref": None,
                "counts": {"expected_lesson_count": inventory.expected_work_count,
                           "actual_lesson_count": actual_lessons}}
    document = {
        "schema_version": 1,
        "kind": "v3_notebooklm_scene_prompt_export",
        "subject_run_id": subject_run_id,
        "notebook_id": inventory.notebook_id,
        "conversation_id": inventory.conversation_id,
        "lesson_count": actual_lessons,
        "scene_count": len(scenes),
        "generation_context": {
            "system_prompt_file": "Google-Ai-studio-System-prompts",
            "system_prompt_sha256": system_prompt_sha256,
            "system_prompt": system_prompt,
            "output_contract": OUTPUT_CONTRACT_TEXT,
            "host_context": {"entrypoint": "index.html", "styles": "styles.css",
                             "behavior": "script.js", "runtime": "browser-native HTML CSS JavaScript"},
        },
        "lessons": [],
    }
    by_ordinal: dict[int, dict[str, Any]] = {}
    for scene in scenes:
        lesson = by_ordinal.get(scene.lesson_ordinal)
        if lesson is None:
            lesson = {"lesson_ordinal": scene.lesson_ordinal, "lesson_title": scene.lesson_title, "scenes": []}
            by_ordinal[scene.lesson_ordinal] = lesson
            document["lessons"].append(lesson)
        lesson["scenes"].append({
            "scene_ordinal": scene.scene_ordinal,
            "section_title": scene.section_title,
            "scene_type": scene.scene_type,
            "scene_key": scene.scene_key,
            "prompt": scene.prompt,
            "prompt_sha256": scene.prompt_sha256,
        })

    export_path = run_dir / "private" / "scene-prompt-exports" / "scene-prompts.json"
    from v3_engine.notebooklm.terminal_service import atomic_json
    atomic_json(export_path, document)
    os.chmod(export_path, stat.S_IRUSR | stat.S_IWUSR)
    # Verify the committed private artifact before the workflow reports success.
    saved = json.loads(export_path.read_text(encoding="utf-8"))
    if saved != document or sum(len(lesson["scenes"]) for lesson in saved["lessons"]) != len(scenes):
        raise RuntimeError("scene_prompt_export_integrity_failed")
    counts = {"lesson_count": document["lesson_count"], "scene_count": len(scenes)}
    _record_event(subject_run_id, "scene_prompts_exported", "export_prompts", "complete", counts=counts)
    return {"schema_version": 1, "outcome": ActivityOutcome.complete.value, "safe_code": SafeCode.ok.value,
            "receipt_ref": None, "result_ref": str(export_path), "counts": counts}


def _export_per_pdf_prompts(subject_run_id: str, run_dir: Path, inventories) -> dict[str, Any]:
    """One manual export with explicit PDF/conversation lineage on every lesson."""
    from v3_engine.scene_generation.service import OUTPUT_CONTRACT_TEXT
    from v3_engine.notebooklm.terminal_service import atomic_json

    if any(inv.input_issues or not inv.scene_inputs
           or any(scene.input_error or not isinstance(scene.prompt, str) or not scene.prompt.strip()
                  for scene in inv.scene_inputs) for _, _, inv, _ in inventories):
        return {"schema_version": 1, "outcome": "failed", "safe_code": "input_invalid", "counts": None}
    notebooks = {inv.notebook_id for _, _, inv, _ in inventories}
    if len(notebooks) != 1:
        raise ValueError("pdf_notebook_lineage_conflict")
    system_prompt, system_prompt_sha = _resolve_run_template(subject_run_id)
    document = {
        "schema_version": 1, "kind": "v3_notebooklm_scene_prompt_export", "pdf_processing": "per_pdf",
        "subject_run_id": subject_run_id, "notebook_id": next(iter(notebooks)), "conversation_id": None,
        "pdf_count": len(inventories), "lesson_count": 0, "scene_count": 0, "pdfs": [], "lessons": [],
        "generation_context": {"system_prompt_file": "Google-Ai-studio-System-prompts",
                               "system_prompt_sha256": system_prompt_sha, "system_prompt": system_prompt,
                               "output_contract": OUTPUT_CONTRACT_TEXT,
                               "host_context": {"entrypoint": "index.html", "styles": "styles.css",
                                                "behavior": "script.js", "runtime": "browser-native HTML CSS JavaScript"}},
    }
    for ordinal, scope, inventory, archive in inventories:
        provenance = {"pdf_ordinal": ordinal, "original_filename": archive["original_filename"],
                      "pdf_sha256": archive["pdf_sha256"], "pdf_source_id": archive["pdf_source_id"],
                      "conversation_id": inventory.conversation_id}
        document["pdfs"].append({**provenance, "lesson_count": archive["work_count"],
                                 "scene_count": len(inventory.scene_inputs)})
        lessons: dict[int, dict[str, Any]] = {}
        for scene in sorted(inventory.scene_inputs, key=lambda item: (item.lesson_ordinal, item.scene_ordinal)):
            lesson = lessons.setdefault(scene.lesson_ordinal, {**provenance, "lesson_ordinal": scene.lesson_ordinal,
                                                               "lesson_title": scene.lesson_title, "scenes": []})
            lesson["scenes"].append({"scene_ordinal": scene.scene_ordinal, "section_title": scene.section_title,
                                     "scene_type": scene.scene_type, "scene_key": terminal_scene_key(ordinal, scene.scene_key),
                                     "prompt": scene.prompt, "prompt_sha256": scene.prompt_sha256})
        if sorted(lessons) != list(range(1, archive["work_count"] + 1)):
            return {"schema_version": 1, "outcome": "failed", "safe_code": "input_invalid", "counts": None}
        document["lessons"].extend(lessons.values())
        document["lesson_count"] += len(lessons)
        document["scene_count"] += len(inventory.scene_inputs)
    path = run_dir / "private" / "scene-prompt-exports" / "scene-prompts.json"
    atomic_json(path, document)
    if read_json(path) != document:
        raise ValueError("scene_prompt_export_integrity_failed")
    counts = {key: document[key] for key in ("pdf_count", "lesson_count", "scene_count")}
    _record_event(subject_run_id, "scene_prompts_exported", "export_prompts", "complete", counts=counts)
    return {"schema_version": 1, "outcome": "complete", "safe_code": "ok", "result_ref": str(path), "counts": counts}


@activity.defn(name="page_terminal_scenes")
async def page_terminal_scenes(inp: dict[str, Any]) -> dict[str, Any]:
    path = _subject_run_dir(inp["subject_run_id"]) / "private" / "scene-inventory.json"
    rows = json.loads(path.read_text(encoding="utf-8"))
    offset = int(inp.get("offset", 0))
    limit = min(max(int(inp.get("limit", 50)), 1), 50)
    stop = offset + limit
    return {
        "schema_version": 1, "outcome": ActivityOutcome.complete.value, "safe_code": SafeCode.ok.value,
        "receipt_ref": None, "result_ref": None, "scene_keys": rows[offset:stop],
        "next_offset": stop if stop < len(rows) else None, "counts": {"total": len(rows)},
    }


@activity.defn(name="generate_terminal_scene")
async def generate_terminal_scene(inp: dict[str, Any]) -> dict[str, Any]:
    """One resume-aware 017 provider attempt, raw receipt saved before normalization."""
    _, _, scene, service, provider, store = _scene_context(inp["subject_run_id"], inp["scene_key"], inp.get("scene_root_subject_id"))
    try:
        checkpoint = store.load_checkpoint()
        item = next((row for row in checkpoint.get("items", []) if row.get("scene_key") == scene.scene_key), {})
        prior_attempt = item.get("active_attempt")
        result = await service.generate_one(
            scene,
            explicit_retry_of=prior_attempt if isinstance(prior_attempt, int) and prior_attempt > 0 else None,
        )
    finally:
        if provider is not None:
            await provider.aclose()
        store.close()
    attempt_ordinal = result.get("attempt_ordinal", 0)
    _record_event(inp["subject_run_id"], "scene_generation", "generate", result.get("outcome", "failed"), result.get("safe_code", "provider_request"), operation_id=inp["scene_key"], counts={"attempt": attempt_ordinal}, attempt=attempt_ordinal)
    return {
        "schema_version": 1,
        "outcome": result.get("outcome", ActivityOutcome.failed.value),
        "safe_code": result.get("safe_code", SafeCode.provider_request.value),
        "receipt_ref": result.get("raw_receipt_ref"),
        "result_ref": None,
        "counts": {"attempt_ordinal": result.get("attempt_ordinal", 0)},
    }


@activity.defn(name="materialize_terminal_scene")
async def materialize_terminal_scene(inp: dict[str, Any]) -> dict[str, Any]:
    """Restore/parse retained receipt/source and save package; no generation request."""
    _, _, scene, service, provider, store = _scene_context(inp["subject_run_id"], inp["scene_key"], inp.get("scene_root_subject_id"), provider_required=False)
    try:
        package = service.materialize_source(scene, int(inp.get("attempt_ordinal", 1)))
    finally:
        if provider is not None:
            await provider.aclose()
        store.close()
    _record_event(inp["subject_run_id"], "scene_materialized", "parse", "complete", operation_id=inp["scene_key"], counts={"files": len(package.files)})
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": None,
        "counts": {"file_count": len(package.files)},
    }


@activity.defn(name="build_terminal_scene")
async def build_terminal_scene(inp: dict[str, Any]) -> dict[str, Any]:
    """Build retained source with 017 scaffold, own subprocess group and saved build evidence."""
    _, _, scene, service, provider, store = _scene_context(inp["subject_run_id"], inp["scene_key"], inp.get("scene_root_subject_id"), provider_required=False)
    try:
        if inp.get("mode") == "build":
            result = service.build_only_scene(scene, int(inp.get("attempt_ordinal", 1)))
        else:
            result = service.build_one(scene, int(inp.get("attempt_ordinal", 1)))
    finally:
        if provider is not None:
            await provider.aclose()
        store.close()
    _record_event(inp["subject_run_id"], "scene_build", "build", result.get("outcome", "failed"), result.get("safe_code", "build_failed"), operation_id=inp["scene_key"], counts={"build": result.get("build_ordinal", 0)})
    return {
        "schema_version": 1,
        "outcome": result.get("outcome", ActivityOutcome.failed.value),
        "safe_code": result.get("safe_code", SafeCode.build_failed.value),
        "receipt_ref": None,
        "result_ref": None,
        "counts": {"build_ordinal": result.get("build_ordinal", 0)},
    }


@activity.defn(name="reconcile_terminal_operation")
async def reconcile_terminal_operation(inp: dict[str, Any]) -> dict[str, Any]:
    """Read retained receipt and, for NotebookLM, inventory/anchored history; no mutation or resend."""
    service = NotebookTerminalService()
    res = await service.reconcile(
        operation_type=inp.get("operation_type", ""),
        notebook_id=inp.get("notebook_id"),
        title=inp.get("title"),
        format_text=inp.get("format_text"),
        storage_path=Path(inp["credential_path"]) if "credential_path" in inp else _subject_credential_path(inp["subject_run_id"]),
    )
    return {
        "schema_version": 1,
        "outcome": res.get("outcome", ActivityOutcome.uncertain.value),
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": res.get("notebook_id"),
        "counts": None,
    }


@activity.defn(name="finalize_terminal_run")
async def finalize_terminal_run(inp: dict[str, Any]) -> dict[str, Any]:
    """Idempotent local journal/status/final-report publication; summaries are computed from verified artifacts."""
    run_dir = _subject_run_dir(inp["subject_run_id"])
    scene_root = run_dir / "scene-generation"
    counts: dict[str, int] = {"lessons_saved": len(list((run_dir / "lesson_handoffs").glob("lesson-*.json")))}
    checkpoint_path = scene_root / "checkpoint.json"
    checkpoint_paths = [checkpoint_path] if checkpoint_path.is_file() else []
    config_path = run_dir / "subject-config.json"
    per_pdf = config_path.exists() and read_json(config_path).get("pdf_processing") == "per_pdf"
    if per_pdf:
        counts["lessons_saved"] = len(list((run_dir / "pdfs").glob("pdf-*/lesson_handoffs/lesson-*.json")))
        counts["pdfs_completed"] = len(list((run_dir / "pdfs").glob("pdf-*/private/archive.json")))
        checkpoint_paths = sorted((run_dir / "pdfs").glob("pdf-*/scene-generation/checkpoint.json"))
    for path in checkpoint_paths:
        try:
            checkpoint = json.loads(path.read_text(encoding="utf-8"))
            for name, value in checkpoint.get("counts", {}).items():
                if isinstance(value, int) and not isinstance(value, bool):
                    counts[name] = counts.get(name, 0) + value if per_pdf else value
        except (OSError, ValueError, TypeError):
            raise RuntimeError("final_report_checkpoint_invalid") from None
    if checkpoint_paths:
        from v3_engine.scene_generation.providers import decode_generation_response
        from v3_engine.scene_generation.artifact_store import ArtifactCorruptError
        counts["scenes_built"] = 0
        counts["scenes_generated"] = 0
        for _, scope, inventory, _ in scoped_inventories(run_dir):
            if not (scope / "scene-generation/checkpoint.json").exists():
                continue
            store = SceneArtifactStore(scope / "scene-generation")
            try:
                checkpoint = store.load_checkpoint()
                items = {item["scene_key"]: item for item in checkpoint["items"]}
                provider_doc = read_json(scope / "scene-generation/run.json")["provider"]
                alias = {"gemini_api": "gemini", "glm_api": "glm", "deepseek_api": "deepseek", "qwen_api": "qwen"}.get(provider_doc["id"])
                for scene in inventory.scene_inputs:
                    item = items.get(scene.scene_key, {})
                    counts["scenes_built"] += int(store.verify_current_build(scene, item))
                    attempt = item.get("active_attempt")
                    if not isinstance(attempt, int) or attempt < 1:
                        continue
                    try:
                        verified = store.load_verified_provider_receipt(scene, attempt)
                        if verified is None:
                            continue
                        receipt, body = verified
                        if receipt["complete"] is not True or receipt["truncated"] is not False:
                            continue
                        decoded = decode_generation_response(alias, provider_doc["requested_model"], body, receipt["http_status"], None)
                        counts["scenes_generated"] += int(decoded.outcome == "complete")
                    except (OSError, ValueError, TypeError, ArtifactCorruptError):
                        pass
            finally:
                store.close()
        if per_pdf and inp.get("terminal_status") == "completed" and counts["scenes_built"] != sum(
            len(inventory.scene_inputs) for _, _, inventory, _ in scoped_inventories(run_dir)
        ):
            raise RuntimeError("final_report_builds_unverified")
    report = {
        "schema_version": 1,
        "kind": "v3_terminal_run_report",
        "subject_run_id": inp["subject_run_id"],
        "status": inp.get("terminal_status", "failed"),
        "counts": counts,
        "scene_checkpoint_ref": "scene-generation/checkpoint.json" if checkpoint_path.exists() else None,
        "scene_generation_mode": inp.get("scene_generation_mode", "generate"),
        "prompt_export_ref": inp.get("prompt_export_ref"),
    }
    if inp.get("scene_generation_mode") == "export_prompts":
        counts["scene_prompts_exported"] = int(inp.get("prompt_export_count", 0))
    if per_pdf:
        report["pdf_processing"] = "per_pdf"
        report["scene_checkpoint_refs"] = [str(path.relative_to(run_dir)) for path in checkpoint_paths]
        pdf_archives = [
            verify_pdf_archive(run_dir, source["ordinal"])
            for source in read_json(config_path)["inputs"] if source["kind"] == "pdf"
            and (pdf_run_dir(run_dir, source["ordinal"]) / "private" / "archive.json").exists()
        ]
        report["pdfs"] = pdf_archives
        cfg_doc = read_json(config_path) if config_path.exists() else {}
        if cfg_doc.get("pdf_preparation_ref"):
            report["pdf_preparation_ref"] = cfg_doc["pdf_preparation_ref"]
        part_summaries = []
        for arch in pdf_archives:
            if arch.get("pdf_lineage"):
                part_summaries.append({
                    "upload_ordinal": arch.get("pdf_ordinal"),
                    "source_ordinal": arch.get("source_ordinal"),
                    "part_ordinal": arch.get("part_ordinal"),
                    "original_filename": arch.get("original_source_filename", arch.get("original_filename")),
                    "start_page": arch.get("start_page"),
                    "end_page": arch.get("end_page"),
                    "split_applied": arch.get("split_applied"),
                })
        if part_summaries:
            report["prepared_parts"] = part_summaries
    from v3_engine.notebooklm.terminal_service import atomic_json
    report_path = run_dir / "final-report.json"
    atomic_json(report_path, report)
    _record_event(inp["subject_run_id"], "subject_finalized", "finalize", report["status"], counts=counts)
    return {
        "schema_version": 1,
        "outcome": ActivityOutcome.complete.value,
        "safe_code": SafeCode.ok.value,
        "receipt_ref": None,
        "result_ref": str(report_path),
        "counts": counts,
    }


def uuid_prefix(u: str) -> str:
    return str(u)[:8]


TERMINAL_ACTIVITIES = [
    initialize_terminal_run,
    admit_terminal_submission,
    register_terminal_child,
    settle_terminal_child,
    verify_notebook_session,
    create_terminal_notebook,
    upload_terminal_source,
    poll_terminal_sources,
    configure_terminal_chat,
    send_terminal_turn,
    capture_terminal_turn,
    archive_terminal_pdf,
    reset_terminal_conversation,
    freeze_terminal_handoffs,
    export_terminal_scene_prompts,
    generate_terminal_scene,
    materialize_terminal_scene,
    build_terminal_scene,
    reconcile_terminal_operation,
    finalize_terminal_run,
]
