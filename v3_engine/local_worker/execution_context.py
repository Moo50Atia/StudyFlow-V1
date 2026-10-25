"""Local execution contexts, lease monitoring and worker boundary errors."""
from __future__ import annotations

import hashlib
import logging
import os
from pathlib import Path
import shutil
import threading
import time
from typing import Any, Dict, Optional

from v3_engine.local_worker.client import LocalWorkerClientError, LocalWorkerHttpClient
from v3_engine.local_worker.protocol import (
    LocalWorkerProtocolError,
    strict_json_decode,
    validate_input_manifest,
    validate_runtime_settings,
    validate_captured_protocol_bundle,
)

logger = logging.getLogger("v3_engine.local_worker.runner")

class LeaseExpiredError(RuntimeError):
    """Raised when server lease has insufficient headroom or is expired."""
    pass


class HeartbeatStaleError(RuntimeError):
    """Raised when heartbeat is older than 30 seconds."""
    pass


class PermitExpiredError(RuntimeError):
    """Raised when pre-send dispatch permit expires before send."""
    pass


class ReconciliationRequiredError(RuntimeError):
    """Raised when an unconfirmed effect requires owner reconciliation."""
    pass


class WorkspaceCapacityError(RuntimeError):
    """The worker workspace cannot safely retain this task's inputs and outputs."""
    pass


def _workspace_usage_bytes(workspace_dir: Path) -> int:
    total = 0
    for root, dirs, files in os.walk(workspace_dir, followlinks=False):
        dirs[:] = [name for name in dirs if not (Path(root) / name).is_symlink()]
        for name in files:
            path = Path(root) / name
            try:
                if not path.is_symlink():
                    total += path.stat().st_size
            except OSError:
                continue
    return total


def ensure_workspace_capacity(
    workspace_dir: Path,
    *,
    budget_bytes: int | None = None,
    safety_margin_bytes: int | None = None,
    additional_bytes: int = 0,
    output_reserve_bytes: int = 0,
) -> None:
    """Reserve space before claim/download and fail closed before writing inputs."""
    workspace_dir = Path(workspace_dir).expanduser().resolve()
    workspace_dir.mkdir(parents=True, exist_ok=True)
    budget = budget_bytes if budget_bytes is not None else int(os.environ.get("LOCAL_WORKER_WORKSPACE_BUDGET_BYTES", 8 * 1024**3))
    margin = safety_margin_bytes if safety_margin_bytes is not None else int(os.environ.get("LOCAL_WORKER_WORKSPACE_SAFETY_MARGIN_BYTES", 512 * 1024**2))
    if budget <= 0 or margin < 0 or margin >= budget or additional_bytes < 0 or output_reserve_bytes < 0:
        raise WorkspaceCapacityError("Local worker workspace capacity settings are invalid.")
    usage = _workspace_usage_bytes(workspace_dir)
    free = shutil.disk_usage(workspace_dir).free
    required = additional_bytes + output_reserve_bytes + margin
    if usage + additional_bytes + output_reserve_bytes + margin > budget or free < required:
        raise WorkspaceCapacityError("Local worker is holding work because its configured workspace budget is exhausted.")


class StageContext:
    def __init__(
        self,
        task_id: str,
        job_id: str,
        run_id: str,
        task_kind: str,
        assignment_id: str,
        assignment_epoch: int,
        control_version: int,
        manifest: Dict[str, Any],
        manifest_sha256: str,
        workspace_dir: Path,
    ):
        self.task_id = task_id
        self.job_id = job_id
        self.run_id = run_id
        self.task_kind = task_kind
        self.assignment_id = assignment_id
        self.assignment_epoch = assignment_epoch
        self.control_version = control_version
        self.manifest = manifest
        self.manifest_sha256 = manifest_sha256
        self.runtime_settings: Dict[str, Any] = {}
        self.instruction_texts: Dict[str, str] = {}
        self.protocol_bundle: Dict[str, Any] = {}
        self.workspace_dir = Path(workspace_dir).expanduser().resolve()

        self.task_dir = self.workspace_dir / "tasks" / task_id
        self.inputs_dir = self.task_dir / "inputs"
        self.artifacts_dir = self.task_dir / "artifacts"
        self.scenes_dir = self.task_dir / "scenes"

        self.inputs_dir.mkdir(parents=True, exist_ok=True)
        self.artifacts_dir.mkdir(parents=True, exist_ok=True)
        self.scenes_dir.mkdir(parents=True, exist_ok=True)

    @classmethod
    def resolve_and_download(
        cls,
        client: LocalWorkerHttpClient,
        claim_data: Dict[str, Any],
        workspace_dir: Path,
    ) -> StageContext:
        task_id = claim_data["task_id"]
        expected_manifest_sha = claim_data["input_manifest_sha256"]

        assignment_id = claim_data["assignment_id"]
        assignment_epoch = int(claim_data["assignment_epoch"])
        manifest_bytes = client.get_input_manifest(task_id, assignment_id, assignment_epoch)
        computed_sha = hashlib.sha256(manifest_bytes).hexdigest()
        if computed_sha != expected_manifest_sha:
            raise LocalWorkerProtocolError(
                f"Input manifest digest mismatch: declared {expected_manifest_sha}, computed {computed_sha}",
                code="manifest_digest_mismatch",
            )

        manifest_data = strict_json_decode(manifest_bytes, max_size=2097152)
        validate_input_manifest(manifest_data)

        task_dir = Path(workspace_dir).expanduser().resolve() / "tasks" / task_id
        input_bytes_needed = 0
        for artifact in manifest_data.get("artifacts", []):
            if artifact.get("purpose") == "original_pdf":
                continue
            existing = task_dir / "inputs" / artifact.get("logical_name", f"{artifact['artifact_id']}.bin")
            if existing.is_file() and not existing.is_symlink() and existing.stat().st_size == artifact["size_bytes"]:
                try:
                    if hashlib.sha256(existing.read_bytes()).hexdigest() == artifact["digest_sha256"]:
                        continue
                except OSError:
                    pass
            input_bytes_needed += int(artifact["size_bytes"])
        output_reserve = int((manifest_data.get("resource_budget") or {}).get("max_output_bytes", 512 * 1024 * 1024))
        ensure_workspace_capacity(
            Path(workspace_dir), additional_bytes=input_bytes_needed,
            output_reserve_bytes=output_reserve,
        )

        ctx = cls(
            task_id=task_id,
            job_id=claim_data["job_id"],
            run_id=claim_data["run_id"],
            task_kind=claim_data["task_kind"],
            assignment_id=claim_data["assignment_id"],
            assignment_epoch=int(claim_data["assignment_epoch"]),
            control_version=int(claim_data["control_version"]),
            manifest=manifest_data,
            manifest_sha256=expected_manifest_sha,
            workspace_dir=workspace_dir,
        )

        (ctx.task_dir / "input_manifest.json").write_bytes(manifest_bytes)
        (ctx.task_dir / "input_manifest.sha256").write_text(expected_manifest_sha + "\n", encoding="ascii")
        job_dir = ctx.workspace_dir / "jobs" / ctx.job_id
        job_dir.mkdir(parents=True, exist_ok=True)
        if ctx.task_kind == "return_scenes" or "required_scenes" in manifest_data:
            (job_dir / "scene_return_manifest.json").write_bytes(manifest_bytes)
            (job_dir / "scene_return_manifest.sha256").write_text(expected_manifest_sha + "\n", encoding="ascii")
        else:
            (job_dir / "input_manifest.json").write_bytes(manifest_bytes)

        # Download and verify all listed input artifacts without re-splitting
        artifacts = manifest_data.get("artifacts", [])
        for art in artifacts:
            # The full original upload is retained and sealed for provenance;
            # authoring operates on the prepared part PDFs. Avoid duplicating
            # large originals onto the worker disk when one source was split.
            if art.get("purpose") == "original_pdf":
                continue
            art_id = art["artifact_id"]
            expected_size = art["size_bytes"]
            expected_sha = art["digest_sha256"]
            logical_name = art.get("logical_name", f"{art_id}.bin")
            target_path = ctx.inputs_dir / logical_name

            # Check if existing artifact matches
            if target_path.is_file() and target_path.stat().st_size == expected_size:
                content = target_path.read_bytes()
                if hashlib.sha256(content).hexdigest() == expected_sha:
                    continue

            client.download_input_artifact(task_id, art_id, target_path, assignment_id, assignment_epoch)
            actual_bytes = target_path.read_bytes()
            if len(actual_bytes) != expected_size:
                raise LocalWorkerProtocolError(
                    f"Artifact {art_id} size mismatch: expected {expected_size}, got {len(actual_bytes)}",
                    code="artifact_size_mismatch",
                )
            actual_sha = hashlib.sha256(actual_bytes).hexdigest()
            if actual_sha != expected_sha:
                raise LocalWorkerProtocolError(
                    f"Artifact {art_id} hash mismatch: expected {expected_sha}, got {actual_sha}",
                    code="artifact_hash_mismatch",
                )

        artifact_by_id = {entry["artifact_id"]: entry for entry in artifacts}
        runtime_entry = artifact_by_id.get(manifest_data["runtime_settings_artifact_id"])
        if runtime_entry is not None:
            if runtime_entry.get("purpose") != "runtime_settings" or runtime_entry.get("digest_sha256") != manifest_data["runtime_settings_sha256"]:
                raise LocalWorkerProtocolError("Runtime settings artifact binding mismatch", code="settings_snapshot_mismatch")
            runtime_path = ctx.inputs_dir / runtime_entry["logical_name"]
            runtime_value = strict_json_decode(runtime_path.read_bytes(), max_size=2 * 1024 * 1024)
            if not isinstance(runtime_value, dict):
                raise LocalWorkerProtocolError("Runtime settings must be a JSON object", code="invalid_settings")
            validate_runtime_settings(runtime_value, manifest_data)
            ctx.runtime_settings = runtime_value
        elif manifest_data["task_kind"] == "author_prompts":
            raise LocalWorkerProtocolError("Runtime settings artifact is missing", code="settings_snapshot_mismatch")

        for instruction in manifest_data["instruction_artifacts"]:
            artifact = artifact_by_id.get(instruction["artifact_id"])
            expected_purpose = {
                "framework": "framework_body",
                "notebooklm_protocol": "instruction_bundle",
                "scene_system_template": "instruction_bundle",
            }.get(instruction["name"])
            if not artifact or artifact.get("purpose") != expected_purpose:
                raise LocalWorkerProtocolError("Instruction artifact is missing or has the wrong purpose", code="invalid_instructions")
            instruction_path = ctx.inputs_dir / artifact["logical_name"]
            raw = instruction_path.read_bytes()
            if len(raw) != instruction["size_bytes"] or hashlib.sha256(raw).hexdigest() != instruction["sha256"]:
                raise LocalWorkerProtocolError("Instruction artifact integrity check failed", code="artifact_hash_mismatch")
            try:
                ctx.instruction_texts[instruction["name"]] = raw.decode("utf-8")
            except UnicodeDecodeError as exc:
                raise LocalWorkerProtocolError("Instruction artifact is not UTF-8", code="invalid_utf8") from exc
        if manifest_data["task_kind"] == "author_prompts" and not ctx.instruction_texts.get("framework", "").strip():
            raise LocalWorkerProtocolError("Approved framework text is missing", code="invalid_instructions")
        if manifest_data["task_kind"] == "author_prompts":
            protocol_raw = ctx.instruction_texts.get("notebooklm_protocol")
            if not protocol_raw:
                raise LocalWorkerProtocolError("Captured NotebookLM protocol bundle is missing", code="invalid_instructions")
            protocol_bundle = strict_json_decode(protocol_raw.encode("utf-8"), max_size=256 * 1024)
            if not isinstance(protocol_bundle, dict):
                raise LocalWorkerProtocolError("Captured NotebookLM protocol bundle is invalid", code="invalid_instructions")
            validate_captured_protocol_bundle(protocol_bundle, manifest_data)
            ctx.protocol_bundle = protocol_bundle
            captured_template = ctx.instruction_texts.get("scene_system_template")
            captured_settings = ctx.runtime_settings.get("captured_settings", {})
            scene_settings = captured_settings.get("templates", {}).get("scene", {})
            if (not isinstance(captured_template, str) or not captured_template.strip()
                    or hashlib.sha256(captured_template.encode("utf-8")).hexdigest() != scene_settings.get("checksum")):
                raise LocalWorkerProtocolError(
                    "Captured scene system template does not match sealed runtime settings",
                    code="settings_snapshot_mismatch",
                )

        return ctx


class HeartbeatManager:
    def __init__(
        self,
        client: LocalWorkerHttpClient,
        task_id: str,
        assignment_id: str,
        assignment_epoch: int,
        initial_control_version: int,
        initial_lease_expires_at: float,
        interval_seconds: float = 15.0,
    ):
        self.client = client
        self.task_id = task_id
        self.assignment_id = assignment_id
        self.assignment_epoch = assignment_epoch
        self.control_version = initial_control_version
        self.lease_expires_at = initial_lease_expires_at
        self.interval_seconds = interval_seconds
        self.last_heartbeat_time = time.time()
        self.is_paused = False
        self.is_cancelled = False
        self._stop_event = threading.Event()
        self._thread: Optional[threading.Thread] = None

    def start(self) -> None:
        self._stop_event.clear()
        self._thread = threading.Thread(target=self._run_loop, daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop_event.set()
        if self._thread is not None:
            self._thread.join(timeout=2.0)
            self._thread = None

    def _run_loop(self) -> None:
        while not self._stop_event.is_set():
            time.sleep(self.interval_seconds)
            if self._stop_event.is_set():
                break
            try:
                res = self.client.heartbeat(
                    self.task_id,
                    self.control_version,
                    self.assignment_id,
                    self.assignment_epoch,
                )
                self.last_heartbeat_time = time.time()
                if "lease_expires_at" in res:
                    try:
                        from datetime import datetime, timezone
                        dt = datetime.fromisoformat(str(res["lease_expires_at"]).replace("Z", "+00:00"))
                        self.lease_expires_at = dt.timestamp()
                    except Exception:
                        self.lease_expires_at = time.time() + 120.0
                if "control_version" in res:
                    self.control_version = int(res["control_version"])
                if res.get("status") == "paused":
                    self.is_paused = True
                elif res.get("status") == "cancelled":
                    self.is_cancelled = True
            except Exception as e:
                if isinstance(e, LocalWorkerClientError) and e.error_code == 'control_changed':
                    detail = (e.response_data or {}).get('error', {})
                    state = detail.get('task_status')
                    self.is_paused = state in {'paused', 'paused_auth', 'paused_quota'}
                    self.is_cancelled = state == 'cancelled'
                    if detail.get('control_version'):
                        self.control_version = int(detail['control_version'])
                logger.warning("Heartbeat failed: %s", e)

    def is_lease_healthy(self) -> bool:
        now = time.time()
        # Must have heartbeat within last 30s
        if now - self.last_heartbeat_time > 30.0:
            return False
        # Must have >= 30s lease headroom
        if self.lease_expires_at - now < 30.0:
            return False
        return not (self.is_paused or self.is_cancelled)
