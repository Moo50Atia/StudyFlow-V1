import pytest
import asyncio
from typing import Any, Dict
from temporalio import workflow
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Worker

from v3_engine.workflows.review_gate import ReviewGate

pytestmark = [pytest.mark.temporal, pytest.mark.asyncio]


@workflow.defn
class SyntheticReviewWorkflow:
    def __init__(self) -> None:
        self.gate = ReviewGate()

    @workflow.run
    async def run(self, candidate_ref: str, candidate_version: int) -> Dict[str, Any]:
        self.gate.enter(candidate_ref, candidate_version)
        result = await self.gate.wait_for_decision()
        return result

    @workflow.signal
    def review_decision(self, payload: Dict[str, Any]) -> None:
        self.gate.handle_signal(payload)

    @workflow.query
    def gate_state(self) -> Dict[str, Any]:
        return self.gate.get_state()

@pytest.mark.asyncio
async def test_w13_valid_signal_releases_gate_with_decision():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "review-gate-queue-1"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[SyntheticReviewWorkflow],
        ):
            handle = await env.client.start_workflow(
                SyntheticReviewWorkflow.run,
                args=["cand-uuid-1", 1],
                id="review-wf-1",
                task_queue=task_queue,
            )

            await asyncio.sleep(0.3)

            # Query initial gate state
            state = await handle.query(SyntheticReviewWorkflow.gate_state)
            assert state["candidate_ref"] == "cand-uuid-1"
            assert state["candidate_version"] == 1
            assert state["is_resolved"] is False

            # Send valid decision signal
            await handle.signal(
                SyntheticReviewWorkflow.review_decision,
                {
                    "candidate_ref": "cand-uuid-1",
                    "candidate_version": 1,
                    "decision_id": "dec-100",
                    "outcome": "approve",
                },
            )

            result = await handle.result()
            assert result["decision_id"] == "dec-100"
            assert result["decision_outcome"] == "approve"
            assert result["candidate_ref"] == "cand-uuid-1"
            assert result["candidate_version"] == 1

@pytest.mark.asyncio
async def test_w13_stale_or_mismatched_signals_ignored():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "review-gate-queue-2"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[SyntheticReviewWorkflow],
        ):
            handle = await env.client.start_workflow(
                SyntheticReviewWorkflow.run,
                args=["cand-uuid-2", 2],
                id="review-wf-2",
                task_queue=task_queue,
            )

            await asyncio.sleep(0.3)

            # 1. Stale version (version 1 for candidate with version 2)
            await handle.signal(
                SyntheticReviewWorkflow.review_decision,
                {
                    "candidate_ref": "cand-uuid-2",
                    "candidate_version": 1,
                    "decision_id": "dec-stale",
                    "outcome": "approve",
                },
            )
            await asyncio.sleep(0.3)

            state1 = await handle.query(SyntheticReviewWorkflow.gate_state)
            assert state1["is_resolved"] is False
            assert state1["decision_id"] is None

            # 2. Wrong candidate ref
            await handle.signal(
                SyntheticReviewWorkflow.review_decision,
                {
                    "candidate_ref": "foreign-cand",
                    "candidate_version": 2,
                    "decision_id": "dec-foreign",
                    "outcome": "approve",
                },
            )
            await asyncio.sleep(0.3)

            state2 = await handle.query(SyntheticReviewWorkflow.gate_state)
            assert state2["is_resolved"] is False
            assert state2["decision_id"] is None

            # 3. Invalid outcome
            await handle.signal(
                SyntheticReviewWorkflow.review_decision,
                {
                    "candidate_ref": "cand-uuid-2",
                    "candidate_version": 2,
                    "decision_id": "dec-invalid",
                    "outcome": "auto_approved",
                },
            )
            await asyncio.sleep(0.3)

            state3 = await handle.query(SyntheticReviewWorkflow.gate_state)
            assert state3["is_resolved"] is False

            # Finally, send matching signal with request_changes
            await handle.signal(
                SyntheticReviewWorkflow.review_decision,
                {
                    "candidate_ref": "cand-uuid-2",
                    "candidate_version": 2,
                    "decision_id": "dec-valid-req",
                    "outcome": "request_changes",
                },
            )

            result = await handle.result()
            assert result["decision_outcome"] == "request_changes"
            assert result["decision_id"] == "dec-valid-req"

@pytest.mark.asyncio
async def test_w13_duplicate_signals_no_op_and_reused_id_conflict_ignored():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "review-gate-queue-3"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[SyntheticReviewWorkflow],
        ):
            handle = await env.client.start_workflow(
                SyntheticReviewWorkflow.run,
                args=["cand-uuid-3", 1],
                id="review-wf-3",
                task_queue=task_queue,
            )

            await asyncio.sleep(0.3)

            # Signal 1: approve
            await handle.signal(
                SyntheticReviewWorkflow.review_decision,
                {
                    "candidate_ref": "cand-uuid-3",
                    "candidate_version": 1,
                    "decision_id": "dec-single",
                    "outcome": "approve",
                },
            )

            # Duplicate signal 1: identical approve
            await handle.signal(
                SyntheticReviewWorkflow.review_decision,
                {
                    "candidate_ref": "cand-uuid-3",
                    "candidate_version": 1,
                    "decision_id": "dec-single",
                    "outcome": "approve",
                },
            )

            # Conflicting signal: same decision_id with request_changes
            await handle.signal(
                SyntheticReviewWorkflow.review_decision,
                {
                    "candidate_ref": "cand-uuid-3",
                    "candidate_version": 1,
                    "decision_id": "dec-single",
                    "outcome": "request_changes",
                },
            )

            result = await handle.result()
            assert result["decision_outcome"] == "approve"
            assert result["decision_id"] == "dec-single"

@pytest.mark.asyncio
async def test_w15_review_gate_survives_worker_restart_without_auto_approval():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "review-gate-queue-4"

        # Worker 1 starts workflow
        worker1 = Worker(
            env.client,
            task_queue=task_queue,
            workflows=[SyntheticReviewWorkflow],
        )

        async with worker1:
            handle = await env.client.start_workflow(
                SyntheticReviewWorkflow.run,
                args=["cand-uuid-4", 1],
                id="review-wf-4",
                task_queue=task_queue,
            )
            await asyncio.sleep(0.3)
            state = await handle.query(SyntheticReviewWorkflow.gate_state)
            assert state["is_resolved"] is False

        # Worker 1 is stopped. Workflow continues waiting in Temporal
        # Start Worker 2
        worker2 = Worker(
            env.client,
            task_queue=task_queue,
            workflows=[SyntheticReviewWorkflow],
        )

        async with worker2:
            stateAfterRestart = await handle.query(SyntheticReviewWorkflow.gate_state)
            assert stateAfterRestart["is_resolved"] is False
            assert stateAfterRestart["candidate_ref"] == "cand-uuid-4"

            # No dormancy auto-approval: state remains unresolved until valid signal
            await handle.signal(
                SyntheticReviewWorkflow.review_decision,
                {
                    "candidate_ref": "cand-uuid-4",
                    "candidate_version": 1,
                    "decision_id": "dec-restart",
                    "outcome": "approve",
                },
            )
            result = await handle.result()
            assert result["decision_outcome"] == "approve"
