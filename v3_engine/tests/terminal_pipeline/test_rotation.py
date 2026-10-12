"""Tests for Continue-As-New, supervisor drain, child slots, and history rotation (Spec 018 / T026)."""

from __future__ import annotations

import asyncio
from pathlib import Path
import uuid
import pytest
from temporalio import activity, workflow
from temporalio.client import Client
from temporalio.worker import Worker

from v3_engine.terminal_pipeline.contracts import SafeCode
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow


@activity.defn(name="admit_terminal_submission")
async def _admit_terminal_submission_for_test(inp: dict) -> dict:
    return {"outcome": "complete", "safe_code": "ok", "counts": {"duplicate": False}}


@workflow.defn(name="IterativeChildWorkflow")
class IterativeChildWorkflow:
    """Mock child workflow that performs Continue-As-New twice before completing."""

    @workflow.run
    async def run(self, iteration: int = 1) -> str:
        if iteration < 3:
            # Continue as new to next iteration
            workflow.continue_as_new(iteration + 1)
        # Final iteration completes
        return f"completed_at_iter_{iteration}"


@pytest.mark.temporal
async def test_child_can_occupied_slots_in_supervisor(temporal_local_env) -> None:
    """Supervisor must retain occupied child slot until final child-chain result, not first CAN closure."""
    client = temporal_local_env.client

    worker = Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow, IterativeChildWorkflow],
        activities=[_admit_terminal_submission_for_test],
    )

    async with worker:
        handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id="v3-can-supervisor",
            task_queue="v3-terminal-control",
        )

        s_id = str(uuid.uuid4())
        await handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            {
                "submission_id": str(uuid.uuid4()),
                "submission_digest": "digest1",
                "manifest_ref": "manifest.json",
                "subjects": [
                    {
                        "subject_run_id": s_id,
                        "workflow_type": "IterativeChildWorkflow",
                    }
                ],
            },
        )

        # Poll until the supervisor sees child as complete
        async def _wait_complete():
            while True:
                prog = await handle.query("progress_v1")
                if prog["active_count"] == 0 and prog["queued_count"] == 0:
                    break
                await asyncio.sleep(0.1)

        await asyncio.wait_for(_wait_complete(), timeout=10.0)

        adm = await handle.query("get_subject_admission_v1", s_id)
        assert adm == "completed"


@pytest.mark.temporal
async def test_supervisor_drain_with_paused_child_and_queue(temporal_local_env) -> None:
    """Supervisor drain rejects new submissions, accepts duplicates, and waits for paused/active children."""
    client = temporal_local_env.client

    is_paused = True
    child_done = False

    @activity.defn(name="pausable_child_activity")
    async def pausable_child_activity(inp: dict) -> dict:
        nonlocal child_done
        while is_paused:
            await asyncio.sleep(0.1)
        child_done = True
        return {"outcome": "complete", "safe_code": "ok"}

    worker = Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow],
        activities=[_admit_terminal_submission_for_test, pausable_child_activity],
    )

    async with worker:
        handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id="v3-drain-supervisor",
            task_queue="v3-terminal-control",
        )

        sub_id = str(uuid.uuid4())
        s1_id = str(uuid.uuid4())
        # Submit first subject
        res1 = await handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            {
                "submission_id": sub_id,
                "submission_digest": "digest1",
                "manifest_ref": "manifest.json",
                "subjects": [
                    {
                        "subject_run_id": s1_id,
                        "mock": True,
                        "activity_name": "pausable_child_activity",
                    }
                ],
            },
        )
        assert res1["outcome"] == "accepted"

        # Wait until child is active
        async def _wait_active():
            while True:
                prog = await handle.query("progress_v1")
                if prog["active_count"] > 0:
                    break
                await asyncio.sleep(0.05)

        await asyncio.wait_for(_wait_active(), timeout=5.0)

        # Set supervisor draining
        await handle.execute_update(TerminalSupervisorWorkflow.set_draining_v1)
        prog = await handle.query("progress_v1")
        assert prog["draining"] is True
        assert prog["active_count"] == 1

        # 1. New submission MUST be rejected with scheduler_draining
        sub2_id = str(uuid.uuid4())
        res_new = await handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            {
                "submission_id": sub2_id,
                "submission_digest": "digest2",
                "manifest_ref": "manifest2.json",
                "subjects": [{"subject_run_id": str(uuid.uuid4())}],
            },
        )
        assert res_new["outcome"] == "rejected"
        assert res_new["safe_code"] == SafeCode.scheduler_draining.value

        # 2. Duplicate submission MUST return existing receipt even during drain
        res_dup = await handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            {
                "submission_id": sub_id,
                "submission_digest": "digest1",
                "manifest_ref": "manifest.json",
                "subjects": [{"subject_run_id": s1_id}],
            },
        )
        assert res_dup["outcome"] == "accepted"
        assert res_dup["submission_id"] == sub_id

        # Supervisor must not complete while child is paused/active
        assert not child_done

        # Unpause child
        is_paused = False

        # Supervisor will now drain and complete
        result = await handle.result()
        assert result["outcome"] == "complete"
        assert child_done is True


@pytest.mark.temporal
async def test_supervisor_continue_as_new_preserves_state_and_dedup(temporal_local_env) -> None:
    """Supervisor continue-as-new preserves queue, accepted submissions, cancellations, and recoveries."""
    client = temporal_local_env.client

    @activity.defn(name="quick_child_activity")
    async def quick_child_activity(inp: dict) -> dict:
        return {"outcome": "complete", "safe_code": "ok"}

    worker = Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow],
        activities=[_admit_terminal_submission_for_test, quick_child_activity],
    )

    async with worker:
        handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id="v3-rotatable-supervisor",
            task_queue="v3-terminal-control",
        )

        sub_id = str(uuid.uuid4())
        s_ids = [str(uuid.uuid4()) for _ in range(5)]

        await handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            {
                "submission_id": sub_id,
                "submission_digest": "digest1",
                "manifest_ref": "manifest.json",
                "subjects": [
                    {"subject_run_id": sid, "mock": True, "activity_name": "quick_child_activity"}
                    for sid in s_ids
                ],
            },
        )

        # Cancel 5th subject before start (it is queued because max capacity is 4)
        cancel_res = await handle.execute_update(
            TerminalSupervisorWorkflow.cancel_queued_v1,
            {"subject_run_id": s_ids[4], "command_id": "cmd-cancel-s5"},
        )
        assert cancel_res["status"] == "cancelled_before_start"

        # Force rotation (Continue-As-New)
        await handle.execute_update(TerminalSupervisorWorkflow.rotate_v1)

        # Wait briefly for rotated generation to be active
        await asyncio.sleep(0.5)

        # In rotated workflow generation:
        # 1. Duplicate submission must still return original receipt
        dup_res = await handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            {
                "submission_id": sub_id,
                "submission_digest": "digest1",
                "manifest_ref": "manifest.json",
                "subjects": [{"subject_run_id": s_ids[0]}],
            },
        )
        assert dup_res["outcome"] == "accepted"
        assert dup_res["submission_id"] == sub_id

        # 2. Cancelled run s_ids[4] still returns cancelled receipt
        cancel_dup = await handle.execute_update(
            TerminalSupervisorWorkflow.cancel_queued_v1,
            {"subject_run_id": s_ids[4], "command_id": "cmd-cancel-s5"},
        )
        assert cancel_dup["status"] == "cancelled_before_start"
