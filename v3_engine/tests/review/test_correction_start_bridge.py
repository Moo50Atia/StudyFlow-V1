import asyncio
import json
import time
import threading
import uuid

import pytest
from httpx import ASGITransport, AsyncClient
from temporalio import activity
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Replayer, Worker

from v3_engine.bridge import app
from v3_engine.contracts import compute_hmac_signature
from v3_engine.review.correction_workflow import CorrectionWorkflow


class StartedHandle:
    result_run_id = "run-correction-1"


class FakeClient:
    def __init__(self):
        self.starts = []

    async def start_workflow(self, workflow, payload, **kwargs):
        self.starts.append((workflow, payload, kwargs))
        return StartedHandle()


class UnavailableClient:
    async def start_workflow(self, *_args, **_kwargs):
        raise ConnectionError("Temporal unavailable")


correction_activity_order = []


@activity.defn(name="claim_teacher_scene_repair")
async def temporal_claim_scene_repair(payload):
    correction_activity_order.append(("claim", payload["route_ref"]))
    return {"repair_ref": "repair-ref-1", "authoring_request_ref": "authoring-ref-1", "ordinal": 1}


@activity.defn(name="execute_teacher_scene_repair")
async def temporal_execute_scene_repair(payload):
    correction_activity_order.append(("repair", payload["repair_ref"]))
    return {"status": "candidate_ready", "candidate_ref": "candidate-ref-2"}


@activity.defn(name="qualify_scene_activity")
async def temporal_qualify_successor(payload):
    correction_activity_order.append(("qualify", payload["candidate_ref"]))
    return {"status": "qualified"}


@activity.defn(name="ensure_review_ready_activity")
async def temporal_ensure_successor_review(payload):
    correction_activity_order.append(("review", payload["candidate_ref"]))
    return {"status": "ready", "data": {
        "review_ref": "review-ref-2", "case_id": payload["case_id"],
        "section_ref": payload["section_ref"], "candidate_ref": payload["candidate_ref"],
        "candidate_version": 2,
    }}


@activity.defn(name="claim_teacher_prompt_correction")
async def temporal_claim_prompt_correction(payload):
    correction_activity_order.append(("claim_prompt", payload["route_ref"]))
    return {"effect_ref": "prompt-effect-1", "disposition": "send_now"}


@activity.defn(name="revise_teacher_prompt_correction")
async def temporal_revise_prompt(payload):
    correction_activity_order.append(("revise", payload["effect_ref"]))
    return {"status": "recorded", "prompt_version_ref": "prompt-version-2", "work_ordinal": 1}


@activity.defn(name="qualify_prompts")
async def temporal_qualify_prompt(payload):
    correction_activity_order.append(("qualify_prompt", payload["prompt_version_refs"][0]))
    return {"status": "completed", "passing_prompt_refs": payload["prompt_version_refs"]}


@activity.defn(name="authoring_list_eligible_prompts")
async def temporal_list_eligible(payload):
    correction_activity_order.append(("list_eligible", payload["case_id"]))
    return [{"prompt_version_ref": "prompt-version-2"}]


@activity.defn(name="authoring_claim_request")
async def temporal_claim_authoring(payload):
    correction_activity_order.append(("claim_authoring", payload["prompt_version_ref"]))
    return {"request_ref": "authoring-request-2"}


@activity.defn(name="authoring_execute_attempts")
async def temporal_execute_authoring(payload):
    correction_activity_order.append(("author", payload["request_ref"]))
    return {"status": "candidate_ready", "candidate_ref": "candidate-ref-factual"}


@activity.defn(name="qualify_scene_activity")
async def temporal_qualify_factual_candidate(payload):
    correction_activity_order.append(("qualify_scene", payload["candidate_ref"]))
    return {"status": "qualified"}


@activity.defn(name="ensure_review_ready_activity")
async def temporal_ensure_factual_review(payload):
    correction_activity_order.append(("review_factual", payload["candidate_ref"]))
    return {"status": "ready", "data": {
        "review_ref": "review-ref-factual", "case_id": payload["case_id"],
        "section_ref": payload["section_ref"], "candidate_ref": payload["candidate_ref"],
        "candidate_version": 2,
    }}


def headers(path, body, secret):
    raw = json.dumps(body, separators=(",", ":")).encode()
    timestamp = int(time.time())
    return {
        "X-V3-Timestamp": str(timestamp),
        "X-V3-Signature": compute_hmac_signature("POST", path, timestamp, raw, secret),
        "X-V3-Contract-Version": "1",
        "Content-Type": "application/json",
    }


@pytest.mark.asyncio
async def test_correction_start_calls_temporal_once_with_deterministic_identity(monkeypatch):
    secret = "test-bridge-secret"
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", secret)
    correction = str(uuid.uuid4())
    case_id, section_id, route_id = str(uuid.uuid4()), str(uuid.uuid4()), str(uuid.uuid4())
    workflow_id = f"v3-review-correction-{correction}"
    path = f"/internal/v1/workflows/{workflow_id}/start-correction"
    payload = {
        "schema_version": 1, "case_id": case_id, "section_ref": section_id,
        "correction_ref": correction, "route_ref": route_id, "feedback_kind": "visual_interaction",
    }
    client = FakeClient()
    app.state.temporal_client = client
    try:
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as http:
            response = await http.post(path, content=json.dumps(payload, separators=(",", ":")), headers=headers(path, payload, secret))
        assert response.status_code == 202, response.text
        assert len(client.starts) == 1
        run, actual, options = client.starts[0]
        assert run.__name__ == "run"
        assert actual == payload
        assert options["id"] == workflow_id
        assert response.json()["data"]["correction_ref"] == correction
    finally:
        app.state.temporal_client = None


@pytest.mark.asyncio
async def test_correction_start_rejects_wrong_identity_without_starting(monkeypatch):
    secret = "test-bridge-secret"
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", secret)
    correction = str(uuid.uuid4())
    path = "/internal/v1/workflows/wrong/start-correction"
    payload = {
        "schema_version": 1, "case_id": str(uuid.uuid4()), "section_ref": str(uuid.uuid4()),
        "correction_ref": correction, "route_ref": str(uuid.uuid4()), "feedback_kind": "mixed",
    }
    app.state.temporal_client = FakeClient()
    try:
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as http:
            response = await http.post(path, content=json.dumps(payload, separators=(",", ":")), headers=headers(path, payload, secret))
        assert response.status_code == 409
        assert app.state.temporal_client.starts == []
    finally:
        app.state.temporal_client = None


@pytest.mark.asyncio
async def test_temporal_failure_cannot_return_accepted(monkeypatch):
    secret = "test-bridge-secret"
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", secret)
    correction = str(uuid.uuid4())
    workflow_id = f"v3-review-correction-{correction}"
    path = f"/internal/v1/workflows/{workflow_id}/start-correction"
    payload = {
        "schema_version": 1, "case_id": str(uuid.uuid4()), "section_ref": str(uuid.uuid4()),
        "correction_ref": correction, "route_ref": str(uuid.uuid4()), "feedback_kind": "mixed",
    }
    app.state.temporal_client = UnavailableClient()
    try:
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as http:
            response = await http.post(path, content=json.dumps(payload, separators=(",", ":")), headers=headers(path, payload, secret))
        assert response.status_code == 503
        assert response.json()["error"]["code"] == "workflow_unavailable"
        assert "accepted" not in response.json().get("data", {})
    finally:
        app.state.temporal_client = None


@pytest.mark.temporal
@pytest.mark.asyncio
async def test_signed_start_endpoint_runs_visual_correction_on_temporal_and_replays(monkeypatch):
    secret = "test-bridge-secret"
    task_queue = "test-correction-endpoint-queue"
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", secret)
    monkeypatch.setenv("V3_TASK_QUEUE", task_queue)
    correction_activity_order.clear()
    correction = str(uuid.uuid4())
    case_id, section_id, route_id = str(uuid.uuid4()), str(uuid.uuid4()), str(uuid.uuid4())
    workflow_id = f"v3-review-correction-{correction}"
    path = f"/internal/v1/workflows/{workflow_id}/start-correction"
    payload = {
        "schema_version": 1,
        "case_id": case_id,
        "section_ref": section_id,
        "correction_ref": correction,
        "route_ref": route_id,
        "feedback_kind": "visual_interaction",
    }
    review_activity_entered = threading.Event()
    release_review_activity = threading.Event()

    @activity.defn(name="ensure_review_ready_activity")
    async def gated_successor_review(payload):
        correction_activity_order.append(("review", payload["candidate_ref"]))
        review_activity_entered.set()
        released = await asyncio.to_thread(release_review_activity.wait, 10)
        if not released:
            raise TimeoutError("test did not release the review handoff")
        return {"status": "ready", "data": {
            "review_ref": "review-ref-2", "case_id": payload["case_id"],
            "section_ref": payload["section_ref"], "candidate_ref": payload["candidate_ref"],
            "candidate_version": 2,
        }}

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        app.state.temporal_client = env.client
        try:
            async with Worker(
                env.client,
                task_queue=task_queue,
                workflows=[CorrectionWorkflow],
                activities=[
                    temporal_claim_scene_repair,
                    temporal_execute_scene_repair,
                    temporal_qualify_successor,
                    gated_successor_review,
                ],
            ):
                async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as http:
                    response = await http.post(
                        path,
                        content=json.dumps(payload, separators=(",", ":")),
                        headers=headers(path, payload, secret),
                    )
                    assert response.status_code == 202, response.text
                    assert response.json()["data"]["accepted"] is True

                    handle = env.client.get_workflow_handle(workflow_id)
                    decision = {
                        "schema_version": 1, "case_id": case_id, "section_ref": section_id,
                        "review_ref": "review-ref-2", "decision_ref": "decision-2",
                        "candidate_ref": "candidate-ref-2", "candidate_version": 2,
                        "outcome": "approve",
                    }
                    assert await asyncio.to_thread(review_activity_entered.wait, 10), (
                        "review handoff did not reach the barrier"
                    )
                    stale = {
                        **decision,
                        "decision_ref": "decision-stale",
                        "candidate_ref": "candidate-ref-1",
                        "candidate_version": 1,
                    }
                    await handle.signal(CorrectionWorkflow.receive_review_decision, stale)
                    # Repeated exact signals arrive before the workflow registers the
                    # review expectation. They must buffer idempotently, not release
                    # the gate early or create multiple consumed decisions.
                    await asyncio.gather(
                        handle.signal(CorrectionWorkflow.receive_review_decision, decision),
                        handle.signal(CorrectionWorkflow.receive_review_decision, decision),
                    )
                    assert await handle.query(CorrectionWorkflow.query_decision_consumed, "decision-2") is False
                    release_review_activity.set()
                    result = await handle.result()
                    assert result["status"] == "review_ready"
                    assert result["candidate_ref"] == "candidate-ref-2"
                    assert result["review_ref"] == "review-ref-2"
                    assert await handle.query(CorrectionWorkflow.query_decision_consumed, "decision-2") is True
                    assert await handle.query(CorrectionWorkflow.query_decision_consumed, "decision-stale") is False

                    replay = await Replayer(workflows=[CorrectionWorkflow]).replay_workflow(
                        await handle.fetch_history()
                    )
                    assert replay.replay_failure is None

                    duplicate = await http.post(
                        path,
                        content=json.dumps(payload, separators=(",", ":")),
                        headers=headers(path, payload, secret),
                    )
                    assert duplicate.status_code == 200
                    assert duplicate.json()["data"]["correction_ref"] == correction
        finally:
            app.state.temporal_client = None

    assert correction_activity_order == [
        ("claim", route_id),
        ("repair", "repair-ref-1"),
        ("qualify", "candidate-ref-2"),
        ("review", "candidate-ref-2"),
    ]


@pytest.mark.temporal
@pytest.mark.asyncio
async def test_signed_start_endpoint_runs_factual_correction_through_prompt_and_scene_gates(monkeypatch):
    secret = "test-bridge-secret"
    task_queue = "test-factual-correction-endpoint-queue"
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", secret)
    monkeypatch.setenv("V3_TASK_QUEUE", task_queue)
    correction_activity_order.clear()
    correction = str(uuid.uuid4())
    case_id, section_id, route_id = str(uuid.uuid4()), str(uuid.uuid4()), str(uuid.uuid4())
    workflow_id = f"v3-review-correction-{correction}"
    path = f"/internal/v1/workflows/{workflow_id}/start-correction"
    payload = {
        "schema_version": 1,
        "case_id": case_id,
        "section_ref": section_id,
        "correction_ref": correction,
        "route_ref": route_id,
        "feedback_kind": "factual_content",
    }

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        app.state.temporal_client = env.client
        try:
            async with Worker(
                env.client,
                task_queue=task_queue,
                workflows=[CorrectionWorkflow],
                activities=[
                    temporal_claim_prompt_correction,
                    temporal_revise_prompt,
                    temporal_qualify_prompt,
                    temporal_list_eligible,
                    temporal_claim_authoring,
                    temporal_execute_authoring,
                    temporal_qualify_factual_candidate,
                    temporal_ensure_factual_review,
                ],
            ):
                async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as http:
                    response = await http.post(
                        path,
                        content=json.dumps(payload, separators=(",", ":")),
                        headers=headers(path, payload, secret),
                    )
                    assert response.status_code == 202, response.text
                    handle = env.client.get_workflow_handle(workflow_id)
                    await handle.signal(CorrectionWorkflow.receive_review_decision, {
                        "schema_version": 1, "case_id": case_id, "section_ref": section_id,
                        "review_ref": "review-ref-factual", "decision_ref": "decision-factual",
                        "candidate_ref": "candidate-ref-factual", "candidate_version": 2,
                        "outcome": "approve",
                    })
                    result = await handle.result()
                    assert result["status"] == "review_ready"
                    assert result["candidate_ref"] == "candidate-ref-factual"
                    assert result["review_ref"] == "review-ref-factual"
                    replay = await Replayer(workflows=[CorrectionWorkflow]).replay_workflow(
                        await handle.fetch_history()
                    )
                    assert replay.replay_failure is None
        finally:
            app.state.temporal_client = None

    assert correction_activity_order == [
        ("claim_prompt", route_id),
        ("revise", "prompt-effect-1"),
        ("qualify_prompt", "prompt-version-2"),
        ("list_eligible", case_id),
        ("claim_authoring", "prompt-version-2"),
        ("author", "authoring-request-2"),
        ("qualify_scene", "candidate-ref-factual"),
        ("review_factual", "candidate-ref-factual"),
    ]
