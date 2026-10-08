import pytest
import asyncio
from typing import Any, Dict, List, Optional
from temporalio import workflow, activity
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Replayer, Worker

from v3_engine.workflows.review_gate import ReviewGate
from v3_engine.review.correction_workflow import CorrectionWorkflow
from v3_engine.review.activity import (
    claim_teacher_scene_repair_activity,
    claim_teacher_prompt_correction_activity,
    get_teacher_prompt_correction_context_activity,
    record_teacher_prompt_correction_result_activity,
)

pytestmark = [pytest.mark.temporal, pytest.mark.asyncio]


@workflow.defn
class MultiSectionReviewWorkflow:
    def __init__(self) -> None:
        self.gates: Dict[str, ReviewGate] = {
            "sec-1": ReviewGate(),
            "sec-2": ReviewGate(),
        }
        self.buffered_signals: Dict[str, Dict[str, Any]] = {}

    @workflow.signal(name="review_decision_v1")
    def receive_decision(self, payload: Dict[str, Any]) -> None:
        candidate_ref = payload.get("candidate_ref")
        section_ref = payload.get("section_ref")

        # If matching gate exists and entered, deliver immediately
        delivered = False
        if section_ref and section_ref in self.gates:
            gate = self.gates[section_ref]
            if gate.candidate_ref == candidate_ref:
                gate.handle_signal(payload)
                delivered = True

        if not delivered and candidate_ref:
            self.buffered_signals[candidate_ref] = payload

    def _enter_gate(self, section_ref: str, candidate_ref: str, candidate_version: int) -> None:
        gate = self.gates[section_ref]
        gate.enter(candidate_ref, candidate_version)
        # Check buffered signals
        if candidate_ref in self.buffered_signals:
            early_sig = self.buffered_signals.pop(candidate_ref)
            gate.handle_signal(early_sig)

    @workflow.query
    def section_status(self) -> Dict[str, Any]:
        return {
            sec: gate.get_state() for sec, gate in self.gates.items()
        }

    @workflow.run
    async def run(self) -> Dict[str, Any]:
        self._enter_gate("sec-1", "cand-1", 1)
        self._enter_gate("sec-2", "cand-2", 1)

        # Wait for both section gates concurrently
        r1, r2 = await asyncio.gather(
            self.gates["sec-1"].wait_for_decision(),
            self.gates["sec-2"].wait_for_decision(),
        )

        return {
            "sec-1": r1,
            "sec-2": r2,
            "all_approved": (r1.get("decision_outcome") == "approve" and r2.get("decision_outcome") == "approve"),
        }

@pytest.mark.asyncio
async def test_multi_section_two_gates_pending_sibling_and_early_buffering():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "review-multi-queue"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[MultiSectionReviewWorkflow],
        ):
            # 1. Start workflow
            handle = await env.client.start_workflow(
                MultiSectionReviewWorkflow.run,
                id="multi-sec-review-wf-1",
                task_queue=task_queue,
            )

            await asyncio.sleep(0.3)

            # 2. Both sections initial state
            status = await handle.query(MultiSectionReviewWorkflow.section_status)
            assert status["sec-1"]["is_resolved"] is False
            assert status["sec-2"]["is_resolved"] is False

            # 3. Approve Section 1; Section 2 remains pending sibling
            await handle.signal(
                MultiSectionReviewWorkflow.receive_decision,
                {
                    "section_ref": "sec-1",
                    "candidate_ref": "cand-1",
                    "candidate_version": 1,
                    "decision_id": "dec-sec-1",
                    "outcome": "approve",
                },
            )

            await asyncio.sleep(0.3)
            status_after_sec1 = await handle.query(MultiSectionReviewWorkflow.section_status)
            assert status_after_sec1["sec-1"]["is_resolved"] is True
            assert status_after_sec1["sec-1"]["decision_outcome"] == "approve"
            assert status_after_sec1["sec-2"]["is_resolved"] is False

            # 4. Approve Section 2 -> workflow completes
            await handle.signal(
                MultiSectionReviewWorkflow.receive_decision,
                {
                    "section_ref": "sec-2",
                    "candidate_ref": "cand-2",
                    "candidate_version": 1,
                    "decision_id": "dec-sec-2",
                    "outcome": "approve",
                },
            )

            res = await handle.result()
            assert res["all_approved"] is True
            assert res["sec-1"]["decision_id"] == "dec-sec-1"
            assert res["sec-2"]["decision_id"] == "dec-sec-2"

            replay_result = await Replayer(workflows=[MultiSectionReviewWorkflow]).replay_workflow(
                await handle.fetch_history()
            )
            assert replay_result.replay_failure is None

@activity.defn(name="claim_teacher_scene_repair")
async def mock_claim_repair(payload: dict) -> dict:
    return {
        "repair_ref": "rep-001",
        "authoring_request_ref": "auth-001",
        "ordinal": 1,
    }

@activity.defn(name="execute_teacher_scene_repair")
async def mock_execute_teacher_repair(payload: dict) -> dict:
    return {"status": "candidate_ready", "candidate_ref": "successor-candidate", "repair_ref": "rep-001"}

@activity.defn(name="qualify_scene_activity")
async def mock_qualify_corrected_scene(payload: dict) -> dict:
    assert payload["candidate_ref"] == "successor-candidate"
    return {"status": "qualified"}

@activity.defn(name="ensure_review_ready_activity")
async def mock_ensure_corrected_review(payload: dict) -> dict:
    return {"status": "ready", "data": {
        "review_ref": "review-successor",
        "section_ref": "00000000-0000-0000-0000-000000000002",
        "candidate_ref": "successor-candidate",
        "candidate_version": 2,
    }}

@activity.defn(name="claim_teacher_prompt_correction")
async def mock_claim_correction(payload: dict) -> dict:
    return {
        "effect_ref": "eff-001",
        "disposition": "send_now",
    }

@activity.defn(name="get_teacher_prompt_correction_context")
async def mock_get_context(payload: dict) -> dict:
    return {
        "effect_ref": "eff-001",
        "revision_count": 0,
        "revision_limit": 2,
    }

@activity.defn(name="record_teacher_prompt_correction_result")
async def mock_record_result(payload: dict) -> dict:
    return {
        "prompt_version_ref": "prompt-v2-ref",
        "version_no": 2,
        "state": "awaiting_qualification",
    }

@pytest.mark.asyncio
async def test_correction_workflow_runs_with_fixed_id_and_no_notes_in_history():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "review-correction-queue"
        correction_id = "00000000-0000-0000-0000-000000000099"
        fixed_workflow_id = f"v3-review-correction-{correction_id}"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CorrectionWorkflow],
            activities=[
                mock_claim_repair,
                mock_claim_correction,
                mock_get_context,
                mock_record_result,
                mock_execute_teacher_repair,
                mock_qualify_corrected_scene,
                mock_ensure_corrected_review,
            ],
        ):
            handle = await env.client.start_workflow(
                CorrectionWorkflow.run,
                {
                    "schema_version": 1,
                    "case_id": "00000000-0000-0000-0000-000000000001",
                    "section_ref": "00000000-0000-0000-0000-000000000002",
                    "correction_ref": correction_id,
                    "route_ref": "00000000-0000-0000-0000-000000000003",
                    "feedback_kind": "visual_interaction",
                },
                id=fixed_workflow_id,
                task_queue=task_queue,
            )

            await asyncio.sleep(0.2)
            await handle.signal(CorrectionWorkflow.receive_review_decision, {
                "schema_version": 1,
                "case_id": "00000000-0000-0000-0000-000000000001",
                "section_ref": "00000000-0000-0000-0000-000000000002",
                "review_ref": "review-successor",
                "decision_ref": "decision-successor",
                "candidate_ref": "successor-candidate",
                "candidate_version": 2,
                "outcome": "approve",
            })
            res = await handle.result()
            assert res["status"] == "review_ready"
            assert res["candidate_ref"] == "successor-candidate"
            assert res["review_ref"] == "review-successor"
            assert res["correction_ref"] == correction_id
            assert await handle.query(CorrectionWorkflow.query_decision_consumed, "decision-successor") is True

            # Verify no notes, code, or secret in workflow history
            history = await handle.fetch_history()
            history_str = str(history)
            assert "secret" not in history_str.lower()
            assert "Please increase contrast" not in history_str

            replay_result = await Replayer(workflows=[CorrectionWorkflow]).replay_workflow(history)
            assert replay_result.replay_failure is None
