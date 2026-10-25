"""Server-authoritative lease checks for mutations in the hosted terminal run."""

from __future__ import annotations

from datetime import datetime
import json
from pathlib import Path
from typing import Any


class HostedAssignmentFenceError(RuntimeError):
    """A hosted terminal mutation no longer owns a live task assignment."""


def _parse_time(value: Any) -> datetime:
    if not isinstance(value, str):
        raise HostedAssignmentFenceError("Hosted assignment lease metadata is invalid.")
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        raise HostedAssignmentFenceError("Hosted assignment lease metadata is invalid.") from None
    if parsed.tzinfo is None:
        raise HostedAssignmentFenceError("Hosted assignment lease metadata is invalid.")
    return parsed


def _fence_path(storage_path: Path | None) -> Path | None:
    if storage_path is None:
        return None
    resolved = Path(storage_path).expanduser().resolve()
    for parent in resolved.parents:
        candidate = parent / "hosted-assignment-fence.json"
        if candidate.is_file():
            return candidate
    return None


def verify_hosted_assignment_fence(storage_path: Path | None) -> None:
    """Fail closed immediately before each provider mutation; standalone runs have no marker."""
    marker_path = _fence_path(storage_path)
    if marker_path is None:
        return
    if marker_path.is_symlink():
        raise HostedAssignmentFenceError("Hosted assignment fence is unavailable.")
    try:
        marker = json.loads(marker_path.read_text(encoding="utf-8"))
    except (OSError, ValueError, TypeError):
        raise HostedAssignmentFenceError("Hosted assignment fence is unreadable.") from None
    required = {"schema_version", "task_id", "assignment_id", "assignment_epoch", "device_id"}
    if (not isinstance(marker, dict) or set(marker) != required or marker.get("schema_version") != 1
            or any(not isinstance(marker.get(key), str) or not marker[key] for key in ("task_id", "assignment_id", "device_id"))
            or isinstance(marker.get("assignment_epoch"), bool) or not isinstance(marker.get("assignment_epoch"), int)
            or marker["assignment_epoch"] < 1):
        raise HostedAssignmentFenceError("Hosted assignment fence is invalid.")

    try:
        from v3_engine.local_worker.client import LocalWorkerHttpClient
        from v3_engine.local_worker.config import load_config

        config = load_config()
        if config.device_id != marker["device_id"]:
            raise HostedAssignmentFenceError("Hosted assignment belongs to a different worker device.")
        client = LocalWorkerHttpClient(config.server_origin, config.device_token, timeout=8.0)
        assignment = client.get_assignment(
            marker["task_id"], marker["assignment_id"], marker["assignment_epoch"],
        )
        if (assignment.get("task_id") != marker["task_id"]
                or assignment.get("assignment_id") != marker["assignment_id"]
                or assignment.get("assignment_epoch") != marker["assignment_epoch"]
                or assignment.get("assignment_status") != "active"
                or assignment.get("task_status") not in {"leased", "running"}):
            raise HostedAssignmentFenceError("Hosted task assignment is no longer active.")
        lease_remaining = (_parse_time(assignment.get("lease_expires_at"))
                           - _parse_time(assignment.get("server_time"))).total_seconds()
        if lease_remaining < 30:
            raise HostedAssignmentFenceError("Hosted task assignment has insufficient lease time for a provider mutation.")
    except HostedAssignmentFenceError:
        raise
    except Exception:
        # Network and credential failures deny the mutation; they never grant
        # the terminal worker an offline authorization window.
        raise HostedAssignmentFenceError("Hosted task assignment could not be verified.") from None
