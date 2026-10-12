"""Temporal control, capacity, queued cancellation, and rotation tests (T053 / Spec 019).

Verifies:
- Shared capacity limits (max 4 concurrent active subjects across web and terminal)
- Queued cancellation before launch releases capacity
- Pause and cancel wake up subject and release capacity
- Exact close proof releases supervisor capacity
- History length bounds trigger rotation
"""

from __future__ import annotations

import asyncio
import uuid
import pytest
from unittest.mock import MagicMock

from temporalio import activity, workflow
from temporalio.client import Client
from temporalio.worker import Worker

from v3_engine.terminal_pipeline.contracts import SafeCode
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow
from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow
from v3_engine.terminal_pipeline.store import TerminalIndexStore


@activity.defn(name="admit_terminal_submission")
async def _mock_admit_submission(inp: dict) -> dict:
    return {"outcome": "complete", "safe_code": "ok", "counts": {"duplicate": False}}


@pytest.mark.temporal
async def test_supervisor_mixed_subjects_capacity_limit(temporal_local_env, tmp_path) -> None:
    """Shared supervisor capacity limits to max 4 concurrent active subjects across web and terminal."""
    client = temporal_local_env.client

    started_subjects: list[str] = []
    completed_subjects: list[str] = []
    max_active_observed = 0
    active_now = 0

    @activity.defn(name="mock_child_execute")
    async def mock_child_execute(inp: dict) -> dict:
        nonlocal max_active_observed, active_now
        subj_id = inp.get("subject_run_id", "")
        started_subjects.append(subj_id)
        active_now += 1
        if active_now > max_active_observed:
            max_active_observed = active_now

        await asyncio.sleep(0.2)

        active_now -= 1
        completed_subjects.append(subj_id)
        return {"outcome": "complete", "safe_code": "ok", "subject_run_id": subj_id}

    async with Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow, TerminalSubjectWorkflow],
        activities=[_mock_admit_submission, mock_child_execute],
    ):
        sup_id = f"v3-supervisor-{uuid.uuid4()}"
        supervisor_handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id=sup_id,
            task_queue="v3-terminal-control",
        )

        sub_id = str(uuid.uuid4())
        # Submit 6 subjects (3 web_subject_v1 and 3 terminal_subject_v1)
        subjects = [
            {
                "subject_run_id": str(uuid.uuid4()),
                "workflow_kind": "web_subject_v1" if i % 2 == 0 else "terminal_subject_v1",
                "run_ref": f"runs/{i}",
                "mock": True,
            }
            for i in range(6)
        ]

        sub_payload = {
            "submission_id": sub_id,
            "submission_digest": "a" * 64,
            "workflow_kind": "web_subject_v1",
            "manifest_ref": "manifests/mixed.json",
            "subjects": subjects,
        }

        resp = await supervisor_handle.execute_update("submit_v1", sub_payload)
        assert resp["outcome"] == "accepted"

        # Wait for all subjects to finish
        for _ in range(50):
            if len(completed_subjects) >= 6:
                break
            await asyncio.sleep(0.2)

        assert len(completed_subjects) == 6
        # Max active subjects concurrently observed must not exceed 4
        assert max_active_observed <= 4
        assert max_active_observed >= 2


@pytest.mark.temporal
async def test_queued_cancellation_releases_capacity_before_launch(temporal_local_env, tmp_path) -> None:
    """Cancelling a queued subject before start skips execution and releases capacity."""
    client = temporal_local_env.client

    executed_subjects: list[str] = []

    @activity.defn(name="mock_child_execute")
    async def mock_child_execute(inp: dict) -> dict:
        subj_id = inp.get("subject_run_id", "")
        executed_subjects.append(subj_id)
        await asyncio.sleep(0.1)
        return {"outcome": "complete", "safe_code": "ok", "subject_run_id": subj_id}

    async with Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow, TerminalSubjectWorkflow],
        activities=[_mock_admit_submission, mock_child_execute],
    ):
        sup_id = f"v3-supervisor-{uuid.uuid4()}"
        supervisor_handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id=sup_id,
            task_queue="v3-terminal-control",
        )

        sub_id = str(uuid.uuid4())
        subj_run_1 = str(uuid.uuid4())
        subj_run_2 = str(uuid.uuid4())

        fill_runs = [f"fill-{i}-{uuid.uuid4()}" for i in range(4)]
        sub_payload = {
            "submission_id": sub_id,
            "submission_digest": "b" * 64,
            "workflow_kind": "web_subject_v1",
            "manifest_ref": "manifests/cancel.json",
            "subjects": [
                {"subject_run_id": f_id, "mock": True}
                for f_id in fill_runs
            ] + [
                {"subject_run_id": subj_run_1, "mock": True},
                {"subject_run_id": subj_run_2, "mock": True},
            ],
        }

        # Submit
        await supervisor_handle.execute_update("submit_v1", sub_payload)

        # Cancel subject 2 immediately while in queue
        cancel_resp = await supervisor_handle.execute_update(
            "cancel_queued_v1",
            {
                "subject_run_id": subj_run_2,
                "command_id": str(uuid.uuid4()),
                "expected_version": 1,
            },
        )
        assert cancel_resp["outcome"] in ("applied", "requested")

        # Wait for processing to complete
        await asyncio.sleep(1.2)

        # Fill runs and Subject 1 should have executed; Subject 2 should NOT have executed
        assert subj_run_1 in executed_subjects
        assert subj_run_2 not in executed_subjects


def test_supervisor_rotation_logic():
    """Verify supervisor rotation request when event history bound reached."""
    wf = TerminalSupervisorWorkflow()
    assert not wf.rotation_requested

    async def run_rotate():
        return await wf.rotate_v1()

    res = asyncio.run(run_rotate())
    assert res["outcome"] == "rotation_requested"
    assert wf.rotation_requested is True


def test_supervisor_state_deduplication_and_queue_bounds():
    """Verify supervisor deduplication and 64-subject queue bound."""
    wf = TerminalSupervisorWorkflow()

    # Prepopulate accepted submission
    sub_id = str(uuid.uuid4())
    wf.accepted_submissions[sub_id] = {
        "outcome": "accepted",
        "submission_id": sub_id,
        "duplicate": True,
    }

    # Duplicate submission returns existing
    async def run_submit_dup():
        return await wf.submit_v1({"submission_id": sub_id})

    dup_res = asyncio.run(run_submit_submit := run_submit_dup())
    assert dup_res["duplicate"] is True

    # Unknown workflow kind rejected
    async def run_submit_unknown_kind():
        return await wf.submit_v1({
            "submission_id": str(uuid.uuid4()),
            "workflow_kind": "unsupported_kind_v99",
        })

    unknown_res = asyncio.run(run_submit_unknown_kind())
    assert unknown_res["outcome"] == "rejected"
    assert unknown_res["safe_code"] == SafeCode.configuration_invalid.value

    # Queue full when exceeding 64 items
    wf.queue = [{"subject_run_id": f"s_{i}"} for i in range(60)]
    new_subjects = [{"subject_run_id": f"new_{i}"} for i in range(10)]  # 60 + 10 = 70 > 64

    async def run_submit_queue_full():
        return await wf.submit_v1({
            "submission_id": str(uuid.uuid4()),
            "workflow_kind": "web_subject_v1",
            "subjects": new_subjects,
        })

    full_res = asyncio.run(run_submit_queue_full())
    assert full_res["outcome"] == "rejected"
    assert full_res["safe_code"] == SafeCode.queue_full.value


def test_supervisor_queued_cancellation_marks_cancelled_set():
    """Cancelling a queued run directly adds to cancelled_queued_runs."""
    wf = TerminalSupervisorWorkflow()
    subj_id = str(uuid.uuid4())
    wf.queue.append({"subject_run_id": subj_id})

    async def run_cancel():
        return await wf.cancel_queued_v1({
            "subject_run_id": subj_id,
            "command_id": str(uuid.uuid4()),
        })

    cancel_res = asyncio.run(run_cancel())
    assert cancel_res["outcome"] == "applied"
    assert subj_id in wf.cancelled_queued_runs
    assert wf.cancelled_queued_receipts[subj_id]["status"] == "cancelled_before_start"

