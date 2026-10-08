import time
import pytest
from httpx import ASGITransport, AsyncClient
from temporalio.client import WorkflowExecutionStatus

from v3_engine.bridge import app
from v3_engine.contracts import compute_hmac_signature
from v3_engine.workflows.case_workflow import CaseWorkflow


def decision(decision_ref="decision-1", **overrides):
    payload = {
        "schema_version": 1,
        "case_id": "case-1",
        "section_ref": "section-1",
        "review_ref": "review-1",
        "decision_ref": decision_ref,
        "candidate_ref": "candidate-1",
        "candidate_version": 2,
        "outcome": "approve",
    }
    payload.update(overrides)
    return payload


def test_early_signal_is_not_consumed_until_exact_review_identity_is_registered():
    wf = CaseWorkflow()
    wf.case_id = "case-1"
    payload = decision()

    wf.receive_review_decision(payload)
    assert wf.query_decision_consumed("decision-1") is False

    wf._register_review({
        "review_ref": "review-1", "section_ref": "section-1",
        "candidate_ref": "candidate-1", "candidate_version": 2,
    })
    assert wf.query_decision_consumed("decision-1") is True
    assert wf.query_decision_consumed("different-decision") is False


def test_stale_or_incompatible_signal_never_proves_consumption():
    wf = CaseWorkflow()
    wf.case_id = "case-1"
    wf._register_review({
        "review_ref": "review-1", "section_ref": "section-1",
        "candidate_ref": "candidate-1", "candidate_version": 2,
    })
    wf.receive_review_decision(decision("stale", candidate_version=1))
    wf.receive_review_decision(decision("wrong-section", section_ref="section-2"))
    wf.receive_review_decision(decision("bad-outcome", outcome="ignore"))
    assert wf.query_decision_consumed("stale") is False
    assert wf.query_decision_consumed("wrong-section") is False
    assert wf.query_decision_consumed("bad-outcome") is False


def test_duplicate_exact_decision_is_idempotent():
    wf = CaseWorkflow()
    wf.case_id = "case-1"
    wf._register_review({
        "review_ref": "review-1", "section_ref": "section-1",
        "candidate_ref": "candidate-1", "candidate_version": 2,
    })
    payload = decision()
    wf.receive_review_decision(payload)
    wf.receive_review_decision(payload)
    assert wf.query_decision_consumed("decision-1") is True
    assert len(wf.consumed_decisions) == 1


class QueryFailureHandle:
    async def describe(self):
        return QueryFailureDescription()

    async def query(self, *_args):
        raise RuntimeError("query handler unavailable")


class QueryFailureDescription:
    status = WorkflowExecutionStatus.COMPLETED

    async def memo(self):
        return {"case_id": "case-1"}


class QueryFailureClient:
    def get_workflow_handle(self, _workflow_id):
        return QueryFailureHandle()


@pytest.mark.asyncio
async def test_completed_workflow_query_failure_is_unavailable_not_consumed(monkeypatch):
    secret = "test-bridge-secret"
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", secret)
    app.state.temporal_client = QueryFailureClient()
    path = "/internal/v1/workflows/v3-case-test/review-decisions/decision-exact"
    timestamp = int(time.time())
    signature = compute_hmac_signature("GET", path, timestamp, b"", secret)
    headers = {"X-V3-Timestamp": str(timestamp), "X-V3-Signature": signature, "X-V3-Contract-Version": "1"}
    try:
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            response = await client.get(path, headers=headers)
        assert response.status_code == 503
        assert response.json()["error"]["code"] == "decision_proof_unavailable"
        assert "consumed" not in response.json().get("data", {})
    finally:
        app.state.temporal_client = None
