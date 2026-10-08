from typing import Any, Dict, Optional
from temporalio import workflow

class ReviewGate:
    """
    Reusable deterministic review gate primitive.
    Maintains candidate reference/version and accepts at most one valid decision signal.
    """
    def __init__(self) -> None:
        self.candidate_ref: Optional[str] = None
        self.candidate_version: Optional[int] = None
        self.decision_id: Optional[str] = None
        self.decision_outcome: Optional[str] = None
        self._is_resolved: bool = False

    def enter(self, candidate_ref: str, candidate_version: int) -> None:
        self.candidate_ref = candidate_ref
        self.candidate_version = candidate_version
        self.decision_id = None
        self.decision_outcome = None
        self._is_resolved = False

    def handle_signal(self, signal_payload: Dict[str, Any]) -> None:
        """
        Process review decision signal:
        {decision_id: str, candidate_ref: str, candidate_version: int, outcome: "approve" | "request_changes"}
        """
        candidate_ref = signal_payload.get("candidate_ref")
        candidate_version = signal_payload.get("candidate_version")
        decision_id = signal_payload.get("decision_id")
        outcome = signal_payload.get("outcome")

        # Must have entered candidate state
        if self.candidate_ref is None or self.candidate_version is None:
            return

        # Must match current candidate and version
        if candidate_ref != self.candidate_ref or candidate_version != self.candidate_version:
            return

        # Allowed outcomes
        if outcome not in ("approve", "request_changes"):
            return

        # If already resolved:
        if self._is_resolved:
            # Exact duplicate signal leaves state unchanged
            if self.decision_id == decision_id and self.decision_outcome == outcome:
                return
            # Reused decision_id with different outcome or different decision_id ignored
            return

        # If not resolved:
        self.decision_id = decision_id
        self.decision_outcome = outcome
        self._is_resolved = True

    async def wait_for_decision(self) -> Dict[str, Any]:
        await workflow.wait_condition(lambda: self._is_resolved)
        return {
            "candidate_ref": self.candidate_ref,
            "candidate_version": self.candidate_version,
            "decision_id": self.decision_id,
            "decision_outcome": self.decision_outcome,
        }

    def get_state(self) -> Dict[str, Any]:
        return {
            "candidate_ref": self.candidate_ref,
            "candidate_version": self.candidate_version,
            "decision_id": self.decision_id,
            "decision_outcome": self.decision_outcome,
            "is_resolved": self._is_resolved,
        }
