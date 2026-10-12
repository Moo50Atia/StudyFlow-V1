"""Operator controls, command intents, version fences, and receipts (Spec 018 / T019)."""

from __future__ import annotations

import dataclasses
import hashlib
import json
from dataclasses import dataclass, field
from typing import Any, Optional

from v3_engine.terminal_pipeline.contracts import (
    SafeCode,
    SafeErrorCode,
)


@dataclass(frozen=True)
class CommandIntent:
    command_id: str
    action: str  # 'pause' | 'resume' | 'cancel' | 'retry'
    subject_run_id: str
    expected_version: Optional[int] = None
    command_digest: Optional[str] = None
    phase: Optional[str] = None
    scene_keys: Optional[list[str]] = None
    accept_duplicate_risk: bool = False


@dataclass(frozen=True)
class CommandReceipt:
    command_id: str
    action: str
    outcome: str  # 'applied' | 'requested' | 'rejected' | 'pending'
    safe_code: str
    status: str
    state_version: int
    subject_run_id: str
    receipt_ref: Optional[str] = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "command_id": self.command_id,
            "action": self.action,
            "outcome": self.outcome,
            "safe_code": self.safe_code,
            "status": self.status,
            "state_version": self.state_version,
            "subject_run_id": self.subject_run_id,
            "receipt_ref": self.receipt_ref,
        }


def _command_digest(intent: CommandIntent) -> str:
    if intent.command_digest:
        return intent.command_digest
    canonical = {
        "action": intent.action,
        "phase": intent.phase,
        "scene_keys": intent.scene_keys or [],
        "accept_duplicate_risk": intent.accept_duplicate_risk,
    }
    return hashlib.sha256(json.dumps(canonical, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def _remember(receipts: dict[str, dict[str, Any]], intent: CommandIntent, receipt: CommandReceipt) -> None:
    payload = receipt.to_dict()
    payload["command_digest"] = _command_digest(intent)
    receipts[intent.command_id] = payload


def validate_and_apply_control(
    intent: CommandIntent,
    current_state: dict[str, Any],
    receipts_cache: dict[str, dict[str, Any]],
) -> CommandReceipt:
    """Validate operator control with version fence and apply state transition idempotently."""
    cmd_id = intent.command_id
    current_version = current_state.get("state_version", 1)

    # 1. Idempotency check: if command already handled, return saved receipt before version testing
    if cmd_id in receipts_cache:
        saved = receipts_cache[cmd_id]
        current_identity = _command_digest(intent)
        if saved.get("action") != intent.action or saved.get("command_digest") != current_identity:
            # Conflicting action for same command_id
            return CommandReceipt(
                command_id=cmd_id,
                action=intent.action,
                outcome="rejected",
                safe_code=SafeCode.control_conflict.value,
                status=current_state.get("status", "unknown"),
                state_version=current_version,
                subject_run_id=intent.subject_run_id,
            )
        return CommandReceipt(
            command_id=cmd_id,
            action=saved["action"],
            outcome=saved["outcome"],
            safe_code=saved["safe_code"],
            status=saved["status"],
            state_version=saved["state_version"],
            subject_run_id=intent.subject_run_id,
            receipt_ref=saved.get("receipt_ref"),
        )

    # 2. Version fence: if expected_version does not match current state version, reject
    if intent.expected_version is not None and intent.expected_version != current_version:
        receipt = CommandReceipt(
            command_id=cmd_id,
            action=intent.action,
            outcome="rejected",
            safe_code=SafeCode.control_conflict.value,
            status=current_state.get("status", "unknown"),
            state_version=current_version,
            subject_run_id=intent.subject_run_id,
        )
        _remember(receipts_cache, intent, receipt)
        return receipt

    # 3. Terminal state check: cancelled or completed workflows cannot be paused or resumed
    is_cancelled = current_state.get("cancelled", False) or current_state.get("status") == "cancelled"
    is_completed = current_state.get("status") in ("completed", "prompts_exported", "failed")

    if (is_cancelled or is_completed) and intent.action in ("pause", "resume"):
        receipt = CommandReceipt(
            command_id=cmd_id,
            action=intent.action,
            outcome="rejected",
            safe_code=SafeCode.control_conflict.value,
            status=current_state.get("status", "unknown"),
            state_version=current_version,
            subject_run_id=intent.subject_run_id,
        )
        _remember(receipts_cache, intent, receipt)
        return receipt

    # 4. Apply transition
    action = intent.action
    if action == "pause":
        if not current_state.get("paused", False):
            current_state["paused"] = True
            current_state["status"] = "paused"
            current_state["state_version"] = current_version + 1
            new_version = current_version + 1
        else:
            new_version = current_version

        receipt = CommandReceipt(
            command_id=cmd_id,
            action="pause",
            outcome="requested" if current_state.get("operation_in_flight") else "applied",
            safe_code=SafeCode.ok.value,
            status="paused",
            state_version=new_version,
            subject_run_id=intent.subject_run_id,
        )

    elif action == "resume":
        if current_state.get("paused", False):
            current_state["paused"] = False
            current_state["status"] = "running"
            current_state["state_version"] = current_version + 1
            new_version = current_version + 1
        else:
            new_version = current_version

        receipt = CommandReceipt(
            command_id=cmd_id,
            action="resume",
            outcome="applied",
            safe_code=SafeCode.ok.value,
            status="running",
            state_version=new_version,
            subject_run_id=intent.subject_run_id,
        )

    elif action == "cancel":
        if not current_state.get("cancelled", False):
            current_state["cancelled"] = True
            current_state["status"] = "cancelled"
            current_state["state_version"] = current_version + 1
            new_version = current_version + 1
        else:
            new_version = current_version

        receipt = CommandReceipt(
            command_id=cmd_id,
            action="cancel",
            outcome="requested" if current_state.get("operation_in_flight") else "applied",
            safe_code=SafeCode.ok.value,
            status="cancelled",
            state_version=new_version,
            subject_run_id=intent.subject_run_id,
        )

    elif action == "retry":
        # Retry must be admitted by the supervisor's recovery workflow, which
        # verifies retained artifacts and actually schedules linked work. A
        # subject update cannot truthfully claim that retry work was applied.
        receipt = CommandReceipt(
            command_id=cmd_id,
            action="retry",
            outcome="rejected",
            safe_code=SafeCode.control_pending.value,
            status=current_state.get("status", "unknown"),
            state_version=current_version,
            subject_run_id=intent.subject_run_id,
        )
        _remember(receipts_cache, intent, receipt)
        return receipt

    else:
        receipt = CommandReceipt(
            command_id=cmd_id,
            action=action,
            outcome="rejected",
            safe_code=SafeCode.control_conflict.value,
            status=current_state.get("status", "unknown"),
            state_version=current_version,
            subject_run_id=intent.subject_run_id,
        )

    saved = receipt.to_dict()
    _remember(receipts_cache, intent, receipt)
    return receipt
