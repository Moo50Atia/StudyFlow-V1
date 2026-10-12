"""Durable review-decision consumer for web-created cases.

The web PDF workflow completes when prompts are exported, so a review made
later needs its own Temporal owner. Each review item has one stable workflow
ID and a captured identity fence; a signal counts as consumed only when every
identity field matches the committed review item.
"""

from __future__ import annotations

from typing import Any
from temporalio import workflow


@workflow.defn(name="WebReviewWorkflow")
class WebReviewWorkflow:
    def __init__(self) -> None:
        self.expected: dict[str, Any] = {}
        self.consumed: dict[str, dict[str, Any]] = {}
        self.closed = False

    @workflow.run
    async def run(self, expected: dict[str, Any]) -> dict[str, Any]:
        self.expected = dict(expected)
        await workflow.wait_condition(lambda: self.closed)
        return {"outcome": "closed", "consumed_count": len(self.consumed)}

    @workflow.signal(name="review_decision_v1")
    def review_decision_v1(self, payload: dict[str, Any]) -> None:
        if not isinstance(payload, dict) or payload.get("schema_version") != 1:
            return
        for key in ("case_id", "section_ref", "review_ref", "candidate_ref", "candidate_version"):
            if payload.get(key) != self.expected.get(key):
                return
        if payload.get("outcome") not in ("approve", "request_changes"):
            return
        decision_ref = payload.get("decision_ref")
        if not isinstance(decision_ref, str) or not decision_ref:
            return
        self.consumed.setdefault(decision_ref, {
            "case_id": payload["case_id"],
            "review_ref": payload["review_ref"],
            "outcome": payload["outcome"],
        })

    @workflow.query(name="query_decision_consumed")
    def query_decision_consumed(self, decision_ref: str) -> bool:
        return decision_ref in self.consumed

    @workflow.query(name="review_identity_v1")
    def review_identity_v1(self) -> dict[str, Any]:
        return dict(self.expected)
