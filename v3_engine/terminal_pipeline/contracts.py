"""Contracts and data structures for Temporal Terminal Pipeline (Spec 018)."""

from __future__ import annotations

import enum
import hashlib
import json
import re
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any

MAX_PAYLOAD_BYTES = 32 * 1024  # 32 KiB


class SafeCode(str, enum.Enum):
    ok = "ok"
    input_invalid = "input_invalid"
    input_changed = "input_changed"
    ownership_conflict = "ownership_conflict"
    lock_busy = "lock_busy"
    authentication_required = "authentication_required"
    account_mismatch = "account_mismatch"
    provider_rate_limit = "provider_rate_limit"
    provider_unavailable = "provider_unavailable"
    delivery_uncertain = "delivery_uncertain"
    history_conflict = "history_conflict"
    source_error = "source_error"
    source_timeout = "source_timeout"
    map_unreadable = "map_unreadable"
    storage_unavailable = "storage_unavailable"
    receipt_invalid = "receipt_invalid"
    artifact_missing = "artifact_missing"
    provider_request = "provider_request"
    source_package_invalid = "source_package_invalid"
    build_failed = "build_failed"
    build_cancelled = "build_cancelled"
    config_drift = "config_drift"
    configuration_invalid = "configuration_invalid"
    configuration_drift = "configuration_drift"
    control_conflict = "control_conflict"
    control_pending = "control_pending"
    recovery_busy = "recovery_busy"
    queue_full = "queue_full"
    scheduler_draining = "scheduler_draining"
    worker_unavailable = "worker_unavailable"
    temporal_unavailable = "temporal_unavailable"
    supervisor_recovery_required = "supervisor_recovery_required"


class TerminalPhase(str, enum.Enum):
    preflight = "preflight"
    create = "create"
    upload = "upload"
    poll = "poll"
    configure = "configure"
    divide = "divide"
    execution = "execution"
    lesson = "lesson"
    freeze = "freeze"
    generate = "generate"
    parse = "parse"
    build = "build"
    finalize = "finalize"


class TerminalStatus(str, enum.Enum):
    queued = "queued"
    running = "running"
    paused = "paused"
    blocked = "blocked"
    completed = "completed"
    prompts_exported = "prompts_exported"
    partial = "partial"
    failed = "failed"
    cancelled = "cancelled"


class ActivityOutcome(str, enum.Enum):
    complete = "complete"
    pending = "pending"
    blocked = "blocked"
    failed = "failed"
    uncertain = "uncertain"


class ControlAction(str, enum.Enum):
    pause = "pause"
    resume = "resume"
    cancel = "cancel"
    retry = "retry"


class ControlState(str, enum.Enum):
    requested = "requested"
    applied = "applied"
    rejected = "rejected"


class SubjectLifecycle(str, enum.Enum):
    queued = "queued"
    launch_pending = "launch_pending"
    started = "started"
    settled = "settled"
    cancelled_before_start = "cancelled_before_start"


_UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
_SHA256_RE = re.compile(r"^[0-9a-f]{64}$")


def validate_canonical_uuid(val: str, field_name: str = "UUID") -> str:
    if not isinstance(val, str) or not _UUID_RE.match(val):
        raise ValueError(f"{field_name} must be a canonical lowercase UUID string, got: {val!r}")
    return val


def validate_sha256(val: str, field_name: str = "SHA256") -> str:
    if not isinstance(val, str) or not _SHA256_RE.match(val):
        raise ValueError(f"{field_name} must be a 64-character lowercase hex SHA-256 string, got: {val!r}")
    return val


@dataclass(frozen=True)
class ArtifactRef:
    path: str
    sha256: str
    bytes: int

    def __post_init__(self) -> None:
        if not self.path or self.path.startswith("/"):
            raise ValueError(f"ArtifactRef path must be relative without leading slash: {self.path!r}")
        parts = Path(self.path).parts
        if ".." in parts:
            raise ValueError(f"ArtifactRef path must not contain '..': {self.path!r}")
        validate_sha256(self.sha256, "ArtifactRef.sha256")
        if self.bytes < 0:
            raise ValueError(f"ArtifactRef bytes must be non-negative: {self.bytes}")

    def resolve(self, root: Path) -> Path:
        resolved = (root / self.path).resolve()
        resolved_root = root.resolve()
        try:
            resolved.relative_to(resolved_root)
        except ValueError:
            raise ValueError(f"ArtifactRef path escapes root: {self.path!r}")
        return resolved

    def verify_on_disk(self, root: Path) -> bool:
        target = self.resolve(root)
        if not target.is_file():
            return False
        stat = target.stat()
        if stat.st_size != self.bytes:
            return False
        h = hashlib.sha256()
        with open(target, "rb") as f:
            while chunk := f.read(65536):
                h.update(chunk)
        return h.hexdigest() == self.sha256

    def to_dict(self) -> dict[str, Any]:
        return {"path": self.path, "sha256": self.sha256, "bytes": self.bytes}

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> ArtifactRef:
        return cls(
            path=str(data["path"]),
            sha256=str(data["sha256"]),
            bytes=int(data["bytes"]),
        )


@dataclass(frozen=True)
class SafeActivityInput:
    subject_run_id: str
    run_ref: ArtifactRef
    schema_version: int = 1
    operation_ref: ArtifactRef | None = None
    owner_epoch: int = 1
    ordinal: int | None = None
    scene_ref: ArtifactRef | None = None

    def __post_init__(self) -> None:
        validate_canonical_uuid(self.subject_run_id, "SafeActivityInput.subject_run_id")
        if self.schema_version != 1:
            raise ValueError("SafeActivityInput schema_version must be 1")
        if self.owner_epoch < 1:
            raise ValueError("SafeActivityInput owner_epoch must be >= 1")

    def to_dict(self) -> dict[str, Any]:
        d: dict[str, Any] = {
            "schema_version": self.schema_version,
            "subject_run_id": self.subject_run_id,
            "run_ref": self.run_ref.to_dict(),
            "owner_epoch": self.owner_epoch,
        }
        if self.operation_ref is not None:
            d["operation_ref"] = self.operation_ref.to_dict()
        if self.ordinal is not None:
            d["ordinal"] = self.ordinal
        if self.scene_ref is not None:
            d["scene_ref"] = self.scene_ref.to_dict()
        return d

    def assert_payload_size(self) -> None:
        encoded = json.dumps(self.to_dict(), separators=(",", ":")).encode("utf-8")
        if len(encoded) > MAX_PAYLOAD_BYTES:
            raise ValueError(f"SafeActivityInput exceeds 32KiB limit ({len(encoded)} bytes)")


@dataclass(frozen=True)
class SafeActivityResult:
    outcome: ActivityOutcome
    safe_code: SafeCode
    schema_version: int = 1
    receipt_ref: ArtifactRef | None = None
    result_ref: ArtifactRef | None = None
    counts: dict[str, int] | None = None
    retry_after_seconds: float | None = None

    def __post_init__(self) -> None:
        if self.schema_version != 1:
            raise ValueError("SafeActivityResult schema_version must be 1")

    def to_dict(self) -> dict[str, Any]:
        d: dict[str, Any] = {
            "schema_version": self.schema_version,
            "outcome": self.outcome.value,
            "safe_code": self.safe_code.value,
        }
        if self.receipt_ref is not None:
            d["receipt_ref"] = self.receipt_ref.to_dict()
        if self.result_ref is not None:
            d["result_ref"] = self.result_ref.to_dict()
        if self.counts is not None:
            d["counts"] = dict(self.counts)
        if self.retry_after_seconds is not None:
            d["retry_after_seconds"] = self.retry_after_seconds
        return d

    def assert_payload_size(self) -> None:
        encoded = json.dumps(self.to_dict(), separators=(",", ":")).encode("utf-8")
        if len(encoded) > MAX_PAYLOAD_BYTES:
            raise ValueError(f"SafeActivityResult exceeds 32KiB limit ({len(encoded)} bytes)")


# Convenience aliases for activity definitions and tests
ActivityInput = SafeActivityInput
ActivityResult = SafeActivityResult
SafeErrorCode = SafeCode

