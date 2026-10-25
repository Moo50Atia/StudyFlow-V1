"""Outbound worker adapter for the existing local Temporal terminal pipeline.

This module submits export-only work to the installed terminal CLI. It never
implements NotebookLM turns, imports a hosted database, or starts Laravel.
"""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import sqlite3
from typing import Any
import uuid

from v3_engine.notebooklm.terminal_service import atomic_json
from v3_engine.terminal_pipeline.pdf_scope import verify_pdf_archive
from v3_engine.notebooklm.ports import AuthenticationRequiredError, ProviderRateLimitError


class TerminalPipelineError(RuntimeError):
    pass


class TerminalObservationError(TerminalPipelineError):
    """Only the read-only status observation failed; no provider outcome is implied."""


class TerminalPipelineAdapter:
    def __init__(self, manifest_template: Path, *, project_root: Path | None = None,
                 terminal_root: Path | None = None, command_runner=None,
                 poll_interval: float = 5.0):
        self.project_root = (project_root or Path(__file__).resolve().parents[2]).resolve()
        self.manifest_template = Path(manifest_template).expanduser().resolve()
        root = terminal_root or os.environ.get("V3_TERMINAL_ROOT") or self.project_root / "storage/app/private/v3/terminal"
        self.terminal_root = Path(root).expanduser().resolve()
        self.command_runner = command_runner or subprocess.run
        self.poll_interval = poll_interval

    def validate_local_template(self) -> dict[str, Any]:
        template = json.loads(self.manifest_template.read_text(encoding='utf-8'))
        if not isinstance(template, dict):
            raise TerminalPipelineError('Terminal template must be a JSON object.')
        subjects = template.get('subjects', [])
        if (not isinstance(subjects, list) or len(subjects) != 1
                or not isinstance(subjects[0], dict) or subjects[0].get('mode', 'new') != 'new'):
            raise TerminalPipelineError('Terminal template must contain one new-mode subject.')
        subject = subjects[0]
        if not all(isinstance(subject.get(key), str) and subject[key].strip()
                   for key in ('account_check_notebook_id', 'provider', 'model')):
            raise TerminalPipelineError('Existing terminal template needs its account-check notebook, provider and model.')
        return subject

    def prepare(self, ctx) -> dict[str, Any]:
        """Capture local CLI settings once, binding actual downloaded inputs."""
        target = ctx.task_dir / "terminal_submission.json"
        protocol_text = ctx.instruction_texts.get("notebooklm_protocol")
        if not protocol_text:
            raise TerminalPipelineError("Sealed NotebookLM protocol bytes are missing from the task context.")
        protocol_sha = hashlib.sha256(protocol_text.encode("utf-8")).hexdigest()
        captured_settings = ctx.runtime_settings.get("captured_settings")
        templates = captured_settings.get("templates") if isinstance(captured_settings, dict) else None
        scene_template = templates.get("scene") if isinstance(templates, dict) else None
        expected_template_sha = scene_template.get("checksum") if isinstance(scene_template, dict) else None
        if not isinstance(expected_template_sha, str) or len(expected_template_sha) != 64:
            raise TerminalPipelineError("Sealed runtime settings do not identify the approved scene system template.")
        captured_template = ctx.instruction_texts.get("scene_system_template")
        captured_template_sha = hashlib.sha256(captured_template.encode("utf-8")).hexdigest() if isinstance(captured_template, str) else None
        if (not isinstance(captured_template, str) or not captured_template.strip()
                or captured_template_sha != expected_template_sha):
            raise TerminalPipelineError("Sealed approved scene system template is missing or has the wrong checksum.")
        if target.exists():
            document = json.loads(target.read_text(encoding="utf-8"))
            subject = document["subjects"][0]
        else:
            template_subject = self.validate_local_template()
            # These are local operator settings, never executable server input.
            fields = ("provider", "model", "timeout_seconds", "account_alias",
                      "account_check_notebook_id", "endpoint", "toolchain")
            subject = {key: template_subject[key] for key in fields if key in template_subject}
            if not subject.get("account_check_notebook_id"):
                raise TerminalPipelineError("Existing terminal template has no account-check notebook.")
            subject.update({
                "subject_key": "hosted-" + ctx.run_id,
                "subject_run_id": str(uuid.uuid5(uuid.UUID(ctx.job_id), "terminal-subject-v1")),
                "mode": "new", "display_title": "Hosted run " + ctx.run_id,
                "pdf_processing": "per_pdf",
                "pdf_files": self._pdf_paths(ctx),
                "steps": self._framework_path(ctx),
            })
            subject.update({
                "captured_notebooklm_protocol_json": protocol_text,
                "captured_notebooklm_protocol_sha256": protocol_sha,
                "captured_scene_system_prompt": captured_template,
                "captured_scene_system_prompt_sha256": captured_template_sha,
            })
            document = {"schema_version": 1,
                        "submission_id": str(uuid.uuid5(uuid.UUID(ctx.job_id), "terminal-submission-v1")),
                        "subjects": [subject]}
            atomic_json(target, document)
        if (subject.get("pdf_files") != self._pdf_paths(ctx)
                or subject.get("steps") != self._framework_path(ctx)
                or subject.get("subject_run_id") != str(uuid.uuid5(uuid.UUID(ctx.job_id), "terminal-subject-v1"))):
            raise TerminalPipelineError("Captured terminal submission does not match this task's inputs.")
        if (subject.get("captured_notebooklm_protocol_json") != protocol_text
                or subject.get("captured_notebooklm_protocol_sha256") != protocol_sha
                or subject.get("captured_scene_system_prompt_sha256") != expected_template_sha
                or not isinstance(subject.get("captured_scene_system_prompt"), str)
                or hashlib.sha256(subject["captured_scene_system_prompt"].encode("utf-8")).hexdigest() != expected_template_sha):
            raise TerminalPipelineError("Captured NotebookLM instructions or scene template differ from sealed settings.")
        terminal_id = subject["subject_run_id"]
        from v3_engine.local_worker.config import load_config
        worker_config = load_config()
        marker_path = self.terminal_root / "runs" / terminal_id / "private" / "hosted-assignment-fence.json"
        atomic_json(marker_path, {
            "schema_version": 1,
            "task_id": ctx.task_id,
            "assignment_id": ctx.assignment_id,
            "assignment_epoch": int(ctx.assignment_epoch),
            "device_id": worker_config.device_id,
        })
        link = {"run_id": ctx.run_id, "job_id": ctx.job_id,
                "terminal_run_id": terminal_id, "terminal_root": str(self.terminal_root),
                "run_dir": str(self.terminal_root / "runs" / terminal_id),
                "manifest_path": str(target)}
        atomic_json(ctx.task_dir / "terminal_link.json", link)
        return {"submission_id": document["submission_id"], "terminal_run_id": terminal_id,
                "input_manifest_sha256": ctx.manifest_sha256,
                "terminal_manifest_sha256": hashlib.sha256(target.read_bytes()).hexdigest()}

    @staticmethod
    def _pdf_paths(ctx) -> list[str]:
        artifacts = {row["artifact_id"]: row for row in ctx.manifest["artifacts"]}
        return [str(ctx.inputs_dir / artifacts[item["prepared_artifact_id"]]["logical_name"])
                for item in ctx.manifest["items"]]

    @staticmethod
    def _framework_path(ctx) -> str:
        instructions = ctx.manifest.get("instruction_artifacts", [])
        binding = next((row for row in instructions if row.get("name") == "framework"), None)
        if not binding:
            raise TerminalPipelineError("Sealed manifest has no framework instruction binding.")
        row = next((artifact for artifact in ctx.manifest.get("artifacts", [])
                    if artifact.get("artifact_id") == binding.get("artifact_id")), None)
        if not row or row.get("purpose") != "framework_body":
            raise TerminalPipelineError("Framework instruction binding does not resolve to a framework artifact.")
        return str(ctx.inputs_dir / row["logical_name"])

    def command(self, *args: str) -> dict[str, Any]:
        env = dict(os.environ, V3_TERMINAL_ROOT=str(self.terminal_root))
        # Status is read-only. A transient native CLI/transport failure does
        # not establish an ambiguous provider mutation and may safely retry.
        attempts = 3 if args[0] == 'status' else 1
        for attempt in range(attempts):
            try:
                result = self.command_runner(
                    [sys.executable, str(self.project_root / "scripts/v3/run_temporal_pipeline.py"), *args],
                    cwd=self.project_root, env=env, capture_output=True, text=True, timeout=45, check=False,
                )
            except subprocess.TimeoutExpired:
                if args[0] != 'status':
                    raise
                if attempt + 1 == attempts:
                    raise TerminalObservationError('Terminal status timed out; retain the existing run.') from None
                time.sleep(1)
                continue
            if result.returncode == 0 or attempt + 1 == attempts:
                break
            time.sleep(1)
        if result.returncode:
            if args[0] == 'status':
                raise TerminalObservationError('Read-only terminal status is temporarily unavailable.')
            try:
                code = json.loads(result.stdout).get('safe_code')
            except (ValueError, AttributeError):
                code = None
            if code == 'authentication_required':
                raise AuthenticationRequiredError('Existing terminal authentication requires attention.')
            if code == 'provider_rate_limit':
                raise ProviderRateLimitError('Existing terminal provider quota requires attention.')
            # Child output can contain private local paths/provider diagnostics.
            raise TerminalPipelineError(f"Terminal {args[0]} failed (exit {result.returncode}); inspect the local terminal logs.")
        try:
            return json.loads(result.stdout)
        except (ValueError, TypeError) as exc:
            error = TerminalObservationError if args[0] == 'status' else TerminalPipelineError
            raise error("Terminal CLI returned an invalid JSON response.") from exc

    def execute_operation(self, operation_kind, request_payload, inputs) -> str:
        if operation_kind != "terminal_pipeline_export":
            raise TerminalPipelineError("Unsupported terminal adapter operation.")
        ctx, heartbeat = inputs["context"], inputs["heartbeat"]
        link = json.loads((ctx.task_dir / "terminal_link.json").read_text(encoding="utf-8"))
        recovery = inputs.get('terminal_recovery')
        if recovery:
            if recovery['terminal_run_id'] != link['terminal_run_id']:
                raise TerminalPipelineError('Recovery refers to another terminal run.')
            while True:
                try:
                    observed = self.recovery_evidence(ctx, recovery['request_digest'])
                    break
                except TerminalObservationError:
                    self._wait_for_observation_retry(heartbeat, inputs.get('drain_check'))
            if recovery['terminal_status'] == 'prompts_exported' and observed['export_sha256'] != recovery['export_sha256']:
                raise TerminalPipelineError('Recovered export bytes changed since owner authorization.')
            if observed['terminal_status'] == 'paused':
                if not heartbeat.is_lease_healthy() or (inputs.get('drain_check') and inputs['drain_check']()):
                    raise TerminalPipelineError('Lease/control prevents terminal resume.')
                receipt = self.command('resume', '--run-id', link['terminal_run_id'],
                    '--command-id', str(uuid.uuid5(uuid.UUID(ctx.task_id), 'resume-' + str(ctx.assignment_epoch))),
                    '--expected-version', str(observed['terminal_state_version']), '--json')
                if receipt.get('outcome') not in ('accepted', 'applied'):
                    raise TerminalPipelineError('Existing terminal resume is not acknowledged.')
        else:
            admitted = self.command("submit", "--manifest", link["manifest_path"],
                                    "--pdf-processing", "per-pdf", "--prepared-input-manifest",
                                    str(ctx.task_dir / 'input_manifest.json'), "--export-prompts", "--json")
            if (admitted.get("outcome") != "accepted" or
                    [row.get("subject_run_id") for row in admitted.get("subjects", [])] != [link["terminal_run_id"]]):
                raise TerminalPipelineError("Terminal admission did not acknowledge the captured run.")
        run_dir = Path(link["run_dir"])
        while True:
            if not heartbeat.is_lease_healthy() or (inputs.get("drain_check") and inputs["drain_check"]()):
                # Ask the existing workflow to stop at its own safe boundary.
                # Killing the CLI would leave the durable Temporal run active.
                status = self.command("status", "--run-id", link["terminal_run_id"], "--json")
                if status.get("freshness") != "realtime" or not status.get("state_version"):
                    raise TerminalPipelineError("Cannot confirm terminal control state; retain this run for reconciliation.")
                action = "cancel" if heartbeat.is_cancelled else "pause"
                receipt = self.command(action, "--run-id", link["terminal_run_id"],
                                      "--command-id", str(uuid.uuid5(uuid.UUID(ctx.task_id), action + str(heartbeat.control_version))),
                                      "--expected-version", str(status["state_version"]), "--json")
                if receipt.get("outcome") in ("rejected", "pending"):
                    raise TerminalPipelineError("Terminal control is not acknowledged; retain the existing run.")
                raise TerminalPipelineError("Terminal run held at an operator/lease control boundary; resume the same run.")
            report_path = run_dir / "final-report.json"
            if report_path.exists():
                report = json.loads(report_path.read_text(encoding="utf-8"))
                if (report.get("subject_run_id") != link["terminal_run_id"]
                        or report.get("status") != "prompts_exported"
                        or report.get("scene_generation_mode") != "export_prompts"):
                    self._raise_held(link)
                export = self.load_export(ctx, link)
                return json.dumps(export, ensure_ascii=False)
            try:
                status = self.command('status', '--run-id', link['terminal_run_id'], '--json')
            except TerminalObservationError:
                self._wait_for_observation_retry(heartbeat, inputs.get('drain_check'))
                continue
            if status.get('freshness') == 'realtime' and status.get('status') in {'failed', 'cancelled', 'partial', 'paused'}:
                self._raise_held(link)
            time.sleep(self.poll_interval)

    def _wait_for_observation_retry(self, heartbeat, drain_check=None):
        # Heartbeats remain independent. Reconnect only while this worker still
        # owns the lease and no operator control/drain asks execution to stop.
        if not heartbeat.is_lease_healthy() or (drain_check and drain_check()):
            raise TerminalPipelineError('Lease/control prevents automatic status reconnection.')
        time.sleep(self.poll_interval)

    def _raise_held(self, link):
        # Read actual terminal diagnostics, without exposing raw provider output.
        database = self.terminal_root / 'index.sqlite'
        code = None
        if database.is_file():
            try:
                with sqlite3.connect(database.as_uri() + '?mode=ro', uri=True) as conn:
                    row = conn.execute("SELECT safe_code FROM events WHERE subject_run_id = ? AND safe_code != 'ok' ORDER BY seq DESC LIMIT 1",
                                       (link['terminal_run_id'],)).fetchone()
                    code = row[0] if row else None
            except sqlite3.Error:
                pass  # Missing diagnostics do not prove that no execution occurred.
        if code == 'authentication_required':
            raise AuthenticationRequiredError('Refresh the existing local terminal session; retain the terminal run.')
        if code == 'provider_rate_limit':
            raise ProviderRateLimitError('The existing terminal run reached provider quota.')
        raise TerminalPipelineError('Terminal run is held or failed; inspect the existing terminal run.')

    def recovery_evidence(self, ctx, request_digest):
        link = json.loads((ctx.task_dir / 'terminal_link.json').read_text(encoding='utf-8'))
        if link['terminal_run_id'] != str(uuid.uuid5(uuid.UUID(ctx.job_id), 'terminal-subject-v1')):
            raise TerminalPipelineError('Terminal recovery identity mismatch.')
        captured = json.loads((ctx.task_dir / 'terminal_submission.json').read_text(encoding='utf-8'))
        expected_request = {'submission_id': captured['submission_id'], 'terminal_run_id': link['terminal_run_id'],
            'input_manifest_sha256': ctx.manifest_sha256,
            'terminal_manifest_sha256': hashlib.sha256((ctx.task_dir / 'terminal_submission.json').read_bytes()).hexdigest()}
        if hashlib.sha256(json.dumps(expected_request, sort_keys=True).encode()).hexdigest() != request_digest:
            raise TerminalPipelineError('Terminal recovery request digest mismatch.')
        report = Path(link['run_dir']) / 'final-report.json'
        export_sha = None
        state_version = None
        if report.is_file() and json.loads(report.read_text()).get('status') == 'prompts_exported':
            self.load_export(ctx, link)
            export_sha = hashlib.sha256((Path(link['run_dir']) / 'private/scene-prompt-exports/scene-prompts.json').read_bytes()).hexdigest()
            status = 'prompts_exported'
        else:
            observed = self.command('status', '--run-id', link['terminal_run_id'], '--json')
            status, state_version = observed.get('status'), observed.get('state_version')
            if observed.get('subject_run_id') != link['terminal_run_id']:
                raise TerminalPipelineError('Terminal recovery identity mismatch.')
            if observed.get('freshness') != 'realtime':
                raise TerminalObservationError('Fresh terminal evidence is temporarily unavailable.')
            if (observed.get('subject_run_id') != link['terminal_run_id'] or observed.get('freshness') != 'realtime'
                    or status not in ('running', 'paused') or not isinstance(state_version, int) or state_version < 1):
                raise TerminalPipelineError('No conclusive existing-terminal evidence; task remains held.')
        return {'schema_version': 1, 'kind': 'local_terminal_run_evidence',
            'task_id': ctx.task_id, 'job_id': ctx.job_id, 'run_id': ctx.run_id,
            'terminal_run_id': link['terminal_run_id'], 'input_manifest_sha256': ctx.manifest_sha256,
            'request_digest': request_digest, 'terminal_status': status,
            'terminal_state_version': state_version, 'export_sha256': export_sha}

    @staticmethod
    def load_export(ctx, link: dict[str, Any]) -> dict[str, Any]:
        run_dir = Path(link["run_dir"])
        export = json.loads((run_dir / "private/scene-prompt-exports/scene-prompts.json").read_text(encoding="utf-8"))
        if (export.get("kind") != "v3_notebooklm_scene_prompt_export"
                or export.get("subject_run_id") != link["terminal_run_id"]
                or export.get("pdf_processing") != "per_pdf"
                or export.get("pdf_count") != len(ctx.manifest["items"])):
            raise TerminalPipelineError("Terminal export is not bound to the captured prepared PDF set.")
        for ordinal, item in enumerate(ctx.manifest["items"], 1):
            archive = verify_pdf_archive(run_dir, ordinal)
            if archive["pdf_sha256"] != item["prepared_sha256"]:
                raise TerminalPipelineError("Terminal archive PDF bytes differ from the hosted manifest.")
            lessons = [row for row in export["lessons"] if row.get("pdf_ordinal") == ordinal]
            if [row["lesson_ordinal"] for row in lessons] != list(range(1, archive["work_count"] + 1)):
                raise TerminalPipelineError("Terminal export does not contain the complete ordered PDF lesson map.")
        if (len(export["lessons"]) != export.get("lesson_count")
                or sum(len(row["scenes"]) for row in export["lessons"]) != export.get("scene_count")):
            raise TerminalPipelineError("Terminal export counts differ from its actual records.")
        return export
