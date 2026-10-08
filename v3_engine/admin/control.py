"""Deterministic admin control tracking for V3 workflows."""
from typing import Any, Dict, Optional
from temporalio import workflow

class AdminControlTracker:
    def __init__(self) -> None:
        self.current_mode: str = "normal"
        self.current_version: int = 0
        self.commands: Dict[str, Dict[str, Any]] = {}

    def receive_signal(self, payload: Dict[str, Any]) -> bool:
        """
        Process admin_control_v1 signal.
        payload: {schema_version: 1, case_id: str, command_ref: str, control_version: int, action: str}
        """
        if payload.get("schema_version") != 1:
            return False

        command_ref = payload.get("command_ref")
        control_version = payload.get("control_version")
        action = payload.get("action")

        if not command_ref or not isinstance(control_version, int) or not action:
            return False

        if action not in ("hold", "resume", "cancel"):
            return False

        # Terminal state: once cancelled, cannot transition to hold or resume
        if self.current_mode == "cancelled":
            return False

        # Ignore lower control versions
        if control_version < self.current_version:
            return False

        # Same control version must match existing command, otherwise ignore
        if control_version == self.current_version and command_ref not in self.commands:
            return False

        # Check existing command by command_ref
        if command_ref in self.commands:
            existing = self.commands[command_ref]
            # Exact duplicate signal ignored
            if existing.get("control_version") == control_version and existing.get("action") == action:
                return False
            # Changed replay of same command_ref is ignored or conflicting
            return False

        if action == "hold":
            self.current_mode = "held"
            self.current_version = control_version
            self.commands[command_ref] = {
                "command_ref": command_ref,
                "control_version": control_version,
                "action": "hold",
                "state": "applied",
            }
            return True
        elif action == "resume":
            self.current_mode = "normal"
            self.current_version = control_version
            self.commands[command_ref] = {
                "command_ref": command_ref,
                "control_version": control_version,
                "action": "resume",
                "state": "applied",
            }
            return True
        elif action == "cancel":
            self.current_mode = "cancelled"
            self.current_version = control_version
            self.commands[command_ref] = {
                "command_ref": command_ref,
                "control_version": control_version,
                "action": "cancel",
                "state": "applied",
            }
            return True

        return False

    def query_command(self, command_ref: str) -> Optional[Dict[str, Any]]:
        return self.commands.get(command_ref)

    def is_cancelled(self) -> bool:
        return self.current_mode == "cancelled"

    def is_held(self) -> bool:
        return self.current_mode == "held"

    async def wait_if_held(self) -> None:
        await workflow.wait_condition(lambda: self.current_mode != "held")
