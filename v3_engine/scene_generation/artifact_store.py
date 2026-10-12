"""Artifact storage, locking, and recovery for terminal scene generation (Spec 017)."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
import errno
import fcntl
import hashlib
import json
import os
from pathlib import Path
import tempfile
from typing import Any, Mapping, Optional

from v3_engine.scene_generation.config import ConfigurationError
from v3_engine.scene_generation.contracts import (
    HandoffInventory,
    InputSnapshot,
    ProviderConfig,
    SceneInput,
)
from v3_engine.scene_generation.source_package import HtmlSceneSourceV1, ReactSceneSourceV1


class LockBusyError(Exception):
    """Raised when output directory .run.lock is held by another process (exit 3)."""


class ArtifactCorruptError(Exception):
    """A retained artifact failed its path, identity, length, or digest check."""


@dataclass(frozen=True)
class AttemptInfo:
    scene_key: str
    ordinal: int
    attempt_dir: Path
    created_at: str


def _rfc3339_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _atomic_write_bytes(target: Path, content: bytes, mode: int = 0o600) -> None:
    target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, tmp_name = tempfile.mkstemp(prefix=".tmp-", dir=target.parent)
    tmp_path = Path(tmp_name)
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, "wb") as f:
            f.write(content)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp_path, target)
        os.chmod(target, mode)
    except Exception:
        try:
            tmp_path.unlink(missing_ok=True)
        except Exception:
            pass
        raise


def _atomic_write_json(target: Path, data: Any, mode: int = 0o600) -> None:
    encoded = json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")
    _atomic_write_bytes(target, encoded, mode=mode)


class SceneArtifactStore:
    """Manages file storage, flock, snapshots, receipts, and manifests."""

    def __init__(self, output_dir: Path) -> None:
        self.output_dir = Path(output_dir).resolve()
        self._lock_fd: Optional[int] = None
        self._lock_file: Path = self.output_dir / ".run.lock"

    def acquire_lock(self, owner_context: Optional[str] = None) -> None:
        """Acquire nonblocking flock on .run.lock; raises LockBusyError if locked or managed."""
        if self._lock_fd is not None:
            return  # already acquired

        marker_file = self.output_dir / ".execution-owner.json"
        parent_marker = self.output_dir.parent / ".execution-owner.json"
        if marker_file.exists() or parent_marker.exists():
            if not owner_context:
                raise LockBusyError(
                    f"Output directory is a managed execution root; unmanaged standalone writes rejected: {self.output_dir}"
                )

        self.output_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        os.chmod(self.output_dir, 0o700)

        fd = os.open(
            self._lock_file,
            os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0),
            0o600,
        )
        try:
            os.fchmod(fd, 0o600)
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self._lock_fd = fd
        except (BlockingIOError, OSError) as exc:
            os.close(fd)
            if isinstance(exc, BlockingIOError) or exc.errno in (errno.EACCES, errno.EAGAIN):
                raise LockBusyError(f"Output directory lock is held by another process: {self.output_dir}") from exc
            raise

    def close(self) -> None:
        """Release OS lock and close file descriptor."""
        if self._lock_fd is not None:
            try:
                fcntl.flock(self._lock_fd, fcntl.LOCK_UN)
            except Exception:
                pass
            try:
                os.close(self._lock_fd)
            except Exception:
                pass
            self._lock_fd = None

    def __enter__(self) -> SceneArtifactStore:
        self.acquire_lock()
        return self

    def __exit__(self, exc_type: Any, exc_val: Any, exc_tb: Any) -> None:
        self.close()

    def _scene_rel_dir(self, lesson_ordinal: int, scene_ordinal: int) -> str:
        return f"lesson-{lesson_ordinal:04d}/scene-{scene_ordinal:04d}"

    def initialize_run(
        self,
        inventory: HandoffInventory,
        config: ProviderConfig,
        template_text: str,
        template_sha: str,
        input_raw_bytes: list[bytes],
        node_version: str = "v24.20.0",
        npm_version: str = "11.19.0",
        catalog_sha: str = "catalog-sha-placeholder",
    ) -> None:
        """Initialize or validate existing run bookkeeping in output directory."""
        run_file = self.output_dir / "run.json"
        if run_file.exists():
            # Validate run identity
            try:
                existing_run = json.loads(run_file.read_text(encoding="utf-8"))
            except Exception as exc:
                raise ConfigurationError(f"Corrupt existing run.json: {exc}") from exc

            if (
                existing_run.get("notebook_id") != inventory.notebook_id
                or existing_run.get("conversation_id") != inventory.conversation_id
                or existing_run.get("provider", {}).get("id") != config.provider_id
                or existing_run.get("provider", {}).get("requested_model") != config.requested_model
                or existing_run.get("provider", {}).get("endpoint") != config.endpoint
                or existing_run.get("provider", {}).get("request_timeout_seconds") != config.request_timeout
                or existing_run.get("provider", {}).get("gemini_thinking_budget") != config.gemini_thinking_budget
                or existing_run.get("provider", {}).get("gemini_max_output_tokens") != config.gemini_max_output_tokens
                or existing_run.get("template", {}).get("sha256") != template_sha
            ):
                raise ConfigurationError("Run identity mismatch in existing output directory.")

            existing_scene_keys = [row.get("scene_key") for row in existing_run.get("scene_inputs", []) if isinstance(row, dict)]
            current_scene_keys = [scene.scene_key for scene in inventory.scene_inputs]
            if existing_scene_keys != current_scene_keys:
                raise ConfigurationError("Run identity mismatch: selected scene set differs.")

            # Validate input file snapshots
            existing_inputs = existing_run.get("inputs", [])
            if len(existing_inputs) != len(inventory.input_snapshots):
                raise ConfigurationError("Run identity mismatch: input file count differs.")
            for ex, cur in zip(existing_inputs, inventory.input_snapshots):
                if ex.get("sha256") != cur.sha256 or ex.get("relative_name") != cur.relative_name:
                    raise ConfigurationError(f"Run identity mismatch: input {cur.relative_name} changed.")

            # Validate toolchain drift
            from v3_engine.scene_generation.builder import check_toolchain_drift
            drift_err = check_toolchain_drift(existing_run.get("toolchain", {}))
            if drift_err:
                raise ConfigurationError(drift_err)
            return

        # Check for nonempty output directory (ignoring .run.lock)
        existing_entries = [p for p in self.output_dir.iterdir() if p.name != ".run.lock"]
        if existing_entries:
            raise ConfigurationError(
                f"Output directory is not empty and does not contain a valid run: {self.output_dir}"
            )

        if len(input_raw_bytes) != len(inventory.input_snapshots):
            raise ConfigurationError("Input snapshot count does not match parsed inventory.")
        for snapshot, raw in zip(inventory.input_snapshots, input_raw_bytes):
            if len(raw) != snapshot.bytes or hashlib.sha256(raw).hexdigest() != snapshot.sha256:
                raise ConfigurationError("Input snapshot bytes do not match parsed inventory digest.")

        now = _rfc3339_now()
        import uuid
        run_id = str(uuid.uuid4())
        from v3_engine.scene_generation.builder import get_host_toolchain_info, toolchain_prerequisite_error
        host_toolchain = get_host_toolchain_info()
        prereq_error = toolchain_prerequisite_error(host_toolchain)
        if prereq_error:
            raise ConfigurationError(prereq_error)

        # Write snapshot/template.txt
        template_snapshot_rel = "snapshot/template.txt"
        _atomic_write_bytes(self.output_dir / template_snapshot_rel, template_text.encode("utf-8"))

        # Write snapshot/inputs/
        for snap, raw_bytes in zip(inventory.input_snapshots, input_raw_bytes):
            _atomic_write_bytes(self.output_dir / snap.snapshot_path, raw_bytes)

        run_doc = {
            "schema_version": 1,
            "kind": "local_scene_generation_run",
            "run_id": run_id,
            "created_at": now,
            "notebook_id": inventory.notebook_id,
            "conversation_id": inventory.conversation_id,
            "provider": {
                "id": config.provider_id,
                "requested_model": config.requested_model,
                "endpoint": config.endpoint,
                "request_timeout_seconds": config.request_timeout,
                "gemini_thinking_budget": config.gemini_thinking_budget,
                "gemini_max_output_tokens": config.gemini_max_output_tokens,
            },
            "template": {
                "sha256": template_sha,
                "bytes": len(template_text.encode("utf-8")),
                "snapshot_path": template_snapshot_rel,
            },
            "toolchain": {
                "sha256": host_toolchain["sha256"],
                "node_version": node_version if node_version != "v24.20.0" else host_toolchain["node_version"],
                "npm_version": npm_version if npm_version != "11.19.0" else host_toolchain["npm_version"],
                "catalog_sha256": catalog_sha if catalog_sha != "catalog-sha-placeholder" else host_toolchain["catalog_sha256"],
                "source_contract": "HtmlCssJsSceneV1",
            },
            "inputs": [
                {
                    "source_index": s.source_index,
                    "original_path": s.original_path,
                    "relative_name": s.relative_name,
                    "sha256": s.sha256,
                    "bytes": s.bytes,
                    "snapshot_path": s.snapshot_path,
                }
                for s in inventory.input_snapshots
            ],
            "scene_inputs": [
                {
                    "scene_key": sc.scene_key,
                    "lesson_ordinal": sc.lesson_ordinal,
                    "scene_ordinal": sc.scene_ordinal,
                    "lesson_title": sc.lesson_title,
                    "section_title": sc.section_title,
                    "scene_type": sc.scene_type,
                    "prompt_sha256": sc.prompt_sha256,
                    "input_error": sc.input_error,
                    "source_index": sc.source_index,
                    "source_json_pointer": sc.source_json_pointer,
                }
                for sc in inventory.scene_inputs
            ],
            "input_issues": [
                {
                    "lesson_ordinal": iss.lesson_ordinal,
                    "scene_ordinal": iss.scene_ordinal,
                    "code": iss.code,
                }
                for iss in inventory.input_issues
            ],
            "qualification": {
                "mode": "owner_waived_terminal_demo",
                "jev_called": False,
            },
        }

        _atomic_write_json(self.output_dir / "run.json", run_doc)

        # Initialize checkpoint.json
        checkpoint_items = []
        for sc in inventory.scene_inputs:
            status = "failed" if sc.input_error else "queued"
            checkpoint_items.append(
                {
                    "scene_key": sc.scene_key,
                    "status": status,
                    "active_attempt": None,
                    "successful_build": None,
                    "error_stage": "input" if sc.input_error else None,
                    "error_code": sc.input_error,
                    "attempts": [],
                }
            )

        queued_count = sum(1 for item in checkpoint_items if item["status"] == "queued")
        failed_count = sum(1 for item in checkpoint_items if item["status"] == "failed")
        checkpoint_doc = {
            "schema_version": 1,
            "run_id": run_id,
            "updated_at": now,
            "status": "partial",
            "items": checkpoint_items,
            "counts": {
                "generated": 0,
                "failed": failed_count,
                "uncertain": 0,
                "queued": queued_count,
                "input_issues": len(inventory.input_issues),
            },
        }

        _atomic_write_json(self.output_dir / "checkpoint.json", checkpoint_doc)

    def load_checkpoint(self) -> dict[str, Any]:
        ckpt_file = self.output_dir / "checkpoint.json"
        if not ckpt_file.exists():
            raise RuntimeError(f"checkpoint.json not found in {self.output_dir}")
        return json.loads(ckpt_file.read_text(encoding="utf-8"))

    def _save_checkpoint(self, ckpt_data: dict[str, Any]) -> None:
        ckpt_data["updated_at"] = _rfc3339_now()
        items = ckpt_data.get("items", [])
        generated = sum(1 for it in items if it["status"] == "generated")
        failed = sum(1 for it in items if it["status"] == "failed")
        uncertain = sum(1 for it in items if it["status"] == "uncertain")
        queued = sum(
            1
            for it in items
            if it["status"] in ("queued", "sending", "response_saved", "source_saved", "building")
        )
        input_issues = ckpt_data.get("counts", {}).get("input_issues", 0)

        ckpt_data["counts"] = {
            "generated": generated,
            "failed": failed,
            "uncertain": uncertain,
            "queued": queued,
            "input_issues": input_issues,
        }
        ckpt_data["status"] = (
            "complete"
            if generated == len(items) and failed == 0 and uncertain == 0 and queued == 0 and input_issues == 0
            else "partial"
        )
        _atomic_write_json(self.output_dir / "checkpoint.json", ckpt_data)

    def get_attempt_dir(self, scene: SceneInput, attempt_ordinal: int) -> Path:
        rel_scene = self._scene_rel_dir(scene.lesson_ordinal, scene.scene_ordinal)
        return self.output_dir / f"{rel_scene}/attempt-{attempt_ordinal:04d}"

    def load_verified_provider_receipt(
        self, scene: SceneInput, attempt_ordinal: int
    ) -> Optional[tuple[dict[str, Any], bytes]]:
        """Load a receipt only when its immutable identity and body digest verify."""
        attempt_dir = self.get_attempt_dir(scene, attempt_ordinal)
        receipt_path = attempt_dir / "response-receipt.json"
        body_path = attempt_dir / "provider-body.bin"
        if not receipt_path.exists() and not body_path.exists():
            return None

        try:
            root = self.output_dir.resolve(strict=True)
            attempt_resolved = attempt_dir.resolve(strict=True)
            if attempt_dir.is_symlink() or not attempt_resolved.is_relative_to(root):
                raise ArtifactCorruptError("artifact_corrupt")
            attempt_file = attempt_dir / "attempt.json"
            if not attempt_file.exists() or attempt_file.is_symlink():
                raise ArtifactCorruptError("artifact_corrupt")
            attempt_doc = json.loads(attempt_file.read_text(encoding="utf-8"))
            if (
                not isinstance(attempt_doc, dict)
                or attempt_doc.get("scene_key") != scene.scene_key
                or attempt_doc.get("ordinal") != attempt_ordinal
            ):
                raise ArtifactCorruptError("artifact_corrupt")
            if not receipt_path.exists() or receipt_path.is_symlink():
                raise ArtifactCorruptError("artifact_corrupt")
            receipt_resolved = receipt_path.resolve(strict=True)
            if not receipt_resolved.is_relative_to(attempt_resolved):
                raise ArtifactCorruptError("artifact_corrupt")
            receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
            if not isinstance(receipt, dict):
                raise ArtifactCorruptError("artifact_corrupt")
            if receipt.get("schema_version") != 1 or receipt.get("attempt_ordinal") != attempt_ordinal:
                raise ArtifactCorruptError("artifact_corrupt")
            if receipt.get("body_path") != "provider-body.bin":
                raise ArtifactCorruptError("artifact_corrupt")
            if not isinstance(receipt.get("complete"), bool) or not isinstance(receipt.get("truncated"), bool):
                raise ArtifactCorruptError("artifact_corrupt")
            status = receipt.get("http_status")
            if status is not None and (isinstance(status, bool) or not isinstance(status, int)):
                raise ArtifactCorruptError("artifact_corrupt")
            if not body_path.exists() or body_path.is_symlink():
                raise ArtifactCorruptError("artifact_corrupt")
            body_resolved = body_path.resolve(strict=True)
            if not body_resolved.is_relative_to(attempt_resolved):
                raise ArtifactCorruptError("artifact_corrupt")
            body = body_path.read_bytes()
            digest = hashlib.sha256(body).hexdigest()
            if (
                receipt.get("body_bytes") != len(body)
                or receipt.get("body_sha256") != digest
            ):
                raise ArtifactCorruptError("artifact_corrupt")
            return receipt, body
        except ArtifactCorruptError:
            raise
        except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
            raise ArtifactCorruptError("artifact_corrupt") from exc

    def start_attempt(self, scene: SceneInput, explicit_retry_of: Optional[int] = None) -> AttemptInfo:
        """Create new attempt directory and record attempt.json, marking checkpoint sending."""
        ckpt = self.load_checkpoint()
        item = next((it for it in ckpt["items"] if it["scene_key"] == scene.scene_key), None)
        if item is None:
            raise ValueError(f"Scene key {scene.scene_key} not found in checkpoint")

        existing_attempts = item.get("attempts", [])
        next_ordinal = len(existing_attempts) + 1
        now = _rfc3339_now()

        rel_scene = self._scene_rel_dir(scene.lesson_ordinal, scene.scene_ordinal)
        attempt_rel = f"{rel_scene}/attempt-{next_ordinal:04d}"
        attempt_dir = self.output_dir / attempt_rel
        attempt_dir.mkdir(parents=True, exist_ok=True, mode=0o700)

        run_doc = json.loads((self.output_dir / "run.json").read_text(encoding="utf-8"))
        attempt_doc = {
            "schema_version": 1,
            "run_id": run_doc["run_id"],
            "scene_key": scene.scene_key,
            "ordinal": next_ordinal,
            "created_at": now,
            "prompt_sha256": scene.prompt_sha256,
            "template_sha256": run_doc["template"]["sha256"],
            "provider_id": run_doc["provider"]["id"],
            "requested_model": run_doc["provider"]["requested_model"],
            "endpoint": run_doc["provider"]["endpoint"],
            "gemini_thinking_budget": run_doc["provider"]["gemini_thinking_budget"],
            "gemini_max_output_tokens": run_doc["provider"]["gemini_max_output_tokens"],
            "toolchain_sha256": run_doc["toolchain"]["sha256"],
            "explicit_retry_of": explicit_retry_of,
        }
        _atomic_write_json(attempt_dir / "attempt.json", attempt_doc)

        item["status"] = "sending"
        item["active_attempt"] = next_ordinal
        item["error_stage"] = None
        item["error_code"] = None
        item["attempts"].append(
            {
                "ordinal": next_ordinal,
                "state": "sending",
                "receipt_path": None,
                "source_manifest_path": None,
                "builds": [],
            }
        )
        self._save_checkpoint(ckpt)

        return AttemptInfo(
            scene_key=scene.scene_key,
            ordinal=next_ordinal,
            attempt_dir=attempt_dir,
            created_at=now,
        )

    def save_raw_receipt(
        self,
        scene: SceneInput,
        attempt_ordinal: int,
        body_bytes: bytes,
        http_status: Optional[int],
        complete: bool,
        truncated: bool,
    ) -> str:
        """Write provider-body.bin and response-receipt.json, returning relative receipt path."""
        rel_scene = self._scene_rel_dir(scene.lesson_ordinal, scene.scene_ordinal)
        attempt_rel = f"{rel_scene}/attempt-{attempt_ordinal:04d}"
        attempt_dir = self.output_dir / attempt_rel
        attempt_dir.mkdir(parents=True, exist_ok=True, mode=0o700)

        body_rel_name = "provider-body.bin"
        body_target = attempt_dir / body_rel_name
        _atomic_write_bytes(body_target, body_bytes)

        body_sha = hashlib.sha256(body_bytes).hexdigest()
        now = _rfc3339_now()

        receipt_doc = {
            "schema_version": 1,
            "attempt_ordinal": attempt_ordinal,
            "http_status": http_status,
            "complete": complete,
            "truncated": truncated,
            "body_path": body_rel_name,
            "body_bytes": len(body_bytes),
            "body_sha256": body_sha,
            "received_at": now,
        }

        receipt_target = attempt_dir / "response-receipt.json"
        _atomic_write_json(receipt_target, receipt_doc)

        receipt_rel = f"{attempt_rel}/response-receipt.json"

        # Update checkpoint attempt record
        ckpt = self.load_checkpoint()
        item = next((it for it in ckpt["items"] if it["scene_key"] == scene.scene_key), None)
        if item is not None:
            for att in item.get("attempts", []):
                if att.get("ordinal") == attempt_ordinal:
                    att["receipt_path"] = receipt_rel
                    att["state"] = "response_saved" if complete else "response_failed"
                    break
            self._save_checkpoint(ckpt)

        return receipt_rel

    def save_response_text(self, scene: SceneInput, attempt_ordinal: int, text: str) -> None:
        rel_scene = self._scene_rel_dir(scene.lesson_ordinal, scene.scene_ordinal)
        target = self.output_dir / rel_scene / f"attempt-{attempt_ordinal:04d}" / "response.txt"
        _atomic_write_bytes(target, text.encode("utf-8"))

    def save_provider_result(
        self, scene: SceneInput, attempt_ordinal: int, result_doc: dict[str, Any]
    ) -> None:
        rel_scene = self._scene_rel_dir(scene.lesson_ordinal, scene.scene_ordinal)
        target = self.output_dir / rel_scene / f"attempt-{attempt_ordinal:04d}" / "provider-result.json"
        _atomic_write_json(target, result_doc)

    def reserve_build(self, scene: SceneInput, attempt_ordinal: int) -> int:
        """Durably allocate a never-reused build ordinal before subprocess work."""
        if self._lock_fd is None:
            raise LockBusyError("Build reservation requires the output run lock.")
        ckpt = self.load_checkpoint()
        item = next((it for it in ckpt["items"] if it["scene_key"] == scene.scene_key), None)
        if item is None:
            raise ValueError("Scene key not found in checkpoint")
        attempt = next((a for a in item.get("attempts", []) if a.get("ordinal") == attempt_ordinal), None)
        if attempt is None:
            raise ValueError("Attempt not found in checkpoint")
        builds_dir = self.get_attempt_dir(scene, attempt_ordinal) / "builds"
        existing = []
        if builds_dir.exists():
            for path in builds_dir.iterdir():
                if path.name.startswith("build-"):
                    try:
                        existing.append(int(path.name[6:]))
                    except ValueError:
                        raise ArtifactCorruptError("artifact_corrupt")
        tracked = [b.get("ordinal", 0) for b in attempt.get("builds", []) if isinstance(b.get("ordinal"), int)]
        ordinal = max(existing + tracked + [0]) + 1
        attempt.setdefault("builds", []).append({"ordinal": ordinal, "status": "reserved", "reserved_at": _rfc3339_now()})
        self._save_checkpoint(ckpt)
        return ordinal

    def finish_build(self, scene: SceneInput, attempt_ordinal: int, build_ordinal: int, status: str, manifest_path: Optional[str] = None) -> None:
        ckpt = self.load_checkpoint()
        item = next((it for it in ckpt["items"] if it["scene_key"] == scene.scene_key), None)
        if item is None:
            raise ValueError("Scene key not found in checkpoint")
        attempt = next((a for a in item.get("attempts", []) if a.get("ordinal") == attempt_ordinal), None)
        if attempt is None:
            raise ValueError("Attempt not found in checkpoint")
        build = next((b for b in attempt.get("builds", []) if b.get("ordinal") == build_ordinal), None)
        if build is None:
            raise ValueError("Build reservation not found")
        build["status"] = status
        if manifest_path:
            build["manifest_path"] = manifest_path
        build["finished_at"] = _rfc3339_now()
        self._save_checkpoint(ckpt)

    def mark_build_running(self, scene: SceneInput, attempt_ordinal: int, build_ordinal: int) -> None:
        ckpt = self.load_checkpoint()
        item = next((it for it in ckpt["items"] if it["scene_key"] == scene.scene_key), None)
        attempt = next((a for a in item.get("attempts", []) if a.get("ordinal") == attempt_ordinal), None) if item else None
        build = next((b for b in attempt.get("builds", []) if b.get("ordinal") == build_ordinal), None) if attempt else None
        if build is None or build.get("status") != "reserved":
            raise ValueError("Build reservation is missing or already started")
        build["status"] = "running"
        build["started_at"] = _rfc3339_now()
        self._save_checkpoint(ckpt)

    def verify_current_build(self, scene: SceneInput, item: Mapping[str, Any]) -> bool:
        """Verify the current pointer, manifest digest, and every published dist file."""
        try:
            scene_dir = self.output_dir / self._scene_rel_dir(scene.lesson_ordinal, scene.scene_ordinal)
            pointer_path = scene_dir / "current.json"
            if pointer_path.is_symlink() or not pointer_path.is_file():
                return False
            pointer = json.loads(pointer_path.read_text(encoding="utf-8"))
            if not isinstance(pointer, dict) or pointer.get("scene_key") != scene.scene_key:
                return False
            if pointer.get("attempt_ordinal") != item.get("active_attempt") or pointer.get("build_ordinal") != item.get("successful_build"):
                return False
            rel = pointer.get("manifest_path")
            if not isinstance(rel, str):
                return False
            manifest_candidate = scene_dir / rel
            if manifest_candidate.is_symlink():
                return False
            manifest_path = manifest_candidate.resolve(strict=True)
            if not manifest_path.is_relative_to(self.output_dir.resolve(strict=True)):
                return False
            manifest_bytes = manifest_path.read_bytes()
            if hashlib.sha256(manifest_bytes).hexdigest() != pointer.get("manifest_sha256"):
                return False
            manifest = json.loads(manifest_bytes)
            if not isinstance(manifest, dict) or manifest.get("scene_key") != scene.scene_key:
                return False
            run_doc = json.loads((self.output_dir / "run.json").read_text(encoding="utf-8"))
            if manifest.get("run_id") != run_doc.get("run_id") or pointer.get("run_id") != run_doc.get("run_id"):
                return False
            if manifest.get("attempt_ordinal") != pointer.get("attempt_ordinal") or manifest.get("build_ordinal") != pointer.get("build_ordinal"):
                return False
            files = manifest.get("files")
            entrypoint = manifest.get("entrypoint")
            if not isinstance(files, list) or not files or len(files) > 1024 or entrypoint != "index.html":
                return False
            dist_dir = manifest_path.parent / "dist"
            observed_paths: set[str] = set()
            total_bytes = 0
            for entry in files:
                if not isinstance(entry, dict) or not isinstance(entry.get("path"), str):
                    return False
                relative = entry["path"]
                if relative.startswith("/") or "\\" in relative or relative in observed_paths:
                    return False
                observed_paths.add(relative)
                candidate = dist_dir / entry["path"]
                if candidate.is_symlink():
                    return False
                target = candidate.resolve(strict=True)
                if not target.is_relative_to(dist_dir.resolve(strict=True)):
                    return False
                body = target.read_bytes()
                total_bytes += len(body)
                if len(body) > 64 * 1024 * 1024 or total_bytes > 64 * 1024 * 1024:
                    return False
                if len(body) != entry.get("bytes") or hashlib.sha256(body).hexdigest() != entry.get("sha256"):
                    return False
            if entrypoint not in observed_paths:
                return False
            return True
        except (OSError, ValueError, TypeError, KeyError):
            return False

    def verify_source_package(self, scene: SceneInput, attempt_ordinal: int) -> bool:
        """Verify retained generated/scaffold source files against source-manifest.json."""
        try:
            attempt_dir = self.get_attempt_dir(scene, attempt_ordinal)
            run_doc = json.loads((self.output_dir / "run.json").read_text(encoding="utf-8"))
            from v3_engine.scene_generation.builder import check_toolchain_drift
            if check_toolchain_drift(run_doc.get("toolchain", {})):
                return False
            attempt_doc = json.loads((attempt_dir / "attempt.json").read_text(encoding="utf-8"))
            if not isinstance(attempt_doc, dict) or attempt_doc.get("scene_key") != scene.scene_key or attempt_doc.get("ordinal") != attempt_ordinal or attempt_doc.get("toolchain_sha256") != run_doc.get("toolchain", {}).get("sha256"):
                return False
            manifest_path = attempt_dir / "source-manifest.json"
            package_path = attempt_dir / "source-package.json"
            if manifest_path.is_symlink() or package_path.is_symlink():
                return False
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            package = json.loads(package_path.read_text(encoding="utf-8"))
            if not isinstance(manifest, dict) or manifest.get("scene_key") != scene.scene_key or manifest.get("attempt_ordinal") != attempt_ordinal:
                return False
            if not isinstance(package, dict) or package.get("schema_version") != 1:
                return False
            rows = manifest.get("files")
            pkg_files = package.get("files")
            if not isinstance(rows, list) or not isinstance(pkg_files, list) or not rows:
                return False
            root = (attempt_dir / "project").resolve(strict=True)
            for row in rows:
                if not isinstance(row, dict) or not isinstance(row.get("path"), str):
                    return False
                p = attempt_dir / "project" / row["path"]
                if p.is_symlink():
                    return False
                resolved = p.resolve(strict=True)
                if not resolved.is_relative_to(root):
                    return False
                body = resolved.read_bytes()
                if len(body) != row.get("bytes") or hashlib.sha256(body).hexdigest() != row.get("sha256"):
                    return False
            for row in pkg_files:
                if not isinstance(row, dict):
                    return False
                matching = next((x for x in rows if x.get("path") == row.get("path")), None)
                if matching is None or matching.get("bytes") != row.get("bytes") or matching.get("sha256") != row.get("sha256"):
                    return False
            lock_file = attempt_dir / "project" / "package-lock.json"
            prior_lock_digests: set[str] = set()
            ckpt = self.load_checkpoint()
            item = next((it for it in ckpt.get("items", []) if it.get("scene_key") == scene.scene_key), {})
            attempt_row = next((a for a in item.get("attempts", []) if a.get("ordinal") == attempt_ordinal), {})
            for build_row in attempt_row.get("builds", []):
                ordinal = build_row.get("ordinal")
                if not isinstance(ordinal, int):
                    continue
                report_path = attempt_dir / "builds" / f"build-{ordinal:04d}" / "build-report.json"
                if report_path.is_file() and not report_path.is_symlink():
                    report = json.loads(report_path.read_text(encoding="utf-8"))
                    digest = report.get("package_lock_sha256") if isinstance(report, dict) else None
                    if isinstance(digest, str):
                        prior_lock_digests.add(digest)
            if len(prior_lock_digests) > 1:
                return False
            if prior_lock_digests:
                if not lock_file.is_file() or lock_file.is_symlink() or hashlib.sha256(lock_file.read_bytes()).hexdigest() != next(iter(prior_lock_digests)):
                    return False
            return True
        except (OSError, ValueError, TypeError, KeyError):
            return False

    def save_source_package(
        self,
        scene: SceneInput,
        attempt_ordinal: int,
        source_pkg: ReactSceneSourceV1 | HtmlSceneSourceV1,
        scaffold_files: Mapping[str, str],
    ) -> str:
        """Write source-package.json, assemble attempt/project/, and write source-manifest.json."""
        rel_scene = self._scene_rel_dir(scene.lesson_ordinal, scene.scene_ordinal)
        attempt_dir = self.output_dir / rel_scene / f"attempt-{attempt_ordinal:04d}"
        project_dir = attempt_dir / "project"
        project_dir.mkdir(parents=True, exist_ok=True, mode=0o700)

        # 1. Write source-package.json
        pkg_doc = {
            "schema_version": source_pkg.schema_version,
            "format": "html_css_js_v1" if isinstance(source_pkg, HtmlSceneSourceV1) else "react_tsx_v1",
            "dependencies": getattr(source_pkg, "dependencies", []),
            "files": [
                {
                    "path": f.path,
                    "bytes": f.bytes,
                    "sha256": f.sha256,
                }
                for f in source_pkg.files
            ],
        }
        _atomic_write_json(attempt_dir / "source-package.json", pkg_doc)

        # 2. Materialize scaffold host files in project_dir
        for rel_path, content in scaffold_files.items():
            dest = project_dir / rel_path
            _atomic_write_bytes(dest, content.encode("utf-8"))

        # 3. Materialize generated files in project_dir
        for gen_f in source_pkg.files:
            dest = project_dir / gen_f.path
            _atomic_write_bytes(dest, gen_f.content.encode("utf-8"))

        # 4. Generate source-manifest.json
        manifest_files: list[dict[str, Any]] = []
        for f in source_pkg.files:
            manifest_files.append({"path": f.path, "bytes": f.bytes, "sha256": f.sha256})
        for rel_path, content in sorted(scaffold_files.items()):
            content_b = content.encode("utf-8")
            manifest_files.append(
                {
                    "path": rel_path,
                    "bytes": len(content_b),
                    "sha256": hashlib.sha256(content_b).hexdigest(),
                }
            )

        manifest_files.sort(key=lambda x: x["path"])
        manifest_doc = {
            "schema_version": 1,
            "scene_key": scene.scene_key,
            "attempt_ordinal": attempt_ordinal,
            "created_at": _rfc3339_now(),
            "files": manifest_files,
        }
        manifest_rel = f"{rel_scene}/attempt-{attempt_ordinal:04d}/source-manifest.json"
        _atomic_write_json(self.output_dir / manifest_rel, manifest_doc)

        # Update checkpoint
        ckpt = self.load_checkpoint()
        item = next((it for it in ckpt["items"] if it["scene_key"] == scene.scene_key), None)
        if item is not None:
            item["status"] = "source_saved"
            for att in item.get("attempts", []):
                if att.get("ordinal") == attempt_ordinal:
                    att["source_manifest_path"] = manifest_rel
                    att["state"] = "source_saved"
                    break
            self._save_checkpoint(ckpt)

        return manifest_rel

    def update_item_status(
        self,
        scene_key: str,
        status: str,
        error_stage: Optional[str] = None,
        error_code: Optional[str] = None,
        successful_build: Optional[int] = None,
        manifest_path: Optional[str] = None,
    ) -> None:
        ckpt = self.load_checkpoint()
        item = next((it for it in ckpt["items"] if it["scene_key"] == scene_key), None)
        if item is not None:
            item["status"] = status
            item["error_stage"] = error_stage
            item["error_code"] = error_code
            if successful_build is not None:
                item["successful_build"] = successful_build
            attempts = item.get("attempts", [])
            if attempts:
                lat = attempts[-1]
                if manifest_path is not None:
                    lat["dist_manifest_path"] = manifest_path
                if successful_build is not None:
                    b_list = lat.setdefault("builds", [])
                    if not any(b.get("ordinal") == successful_build for b in b_list):
                        b_list.append({"ordinal": successful_build, "status": "complete", "manifest_path": manifest_path})
            self._save_checkpoint(ckpt)
