"""Tests for Temporal supervisor workflow scheduling, capacity limits, and queueing (Spec 018 / T012)."""

from __future__ import annotations

import asyncio
import uuid
from pathlib import Path
import pytest
from temporalio import activity, workflow
from temporalio.client import Client
from temporalio.worker import Worker

from v3_engine.terminal_pipeline.contracts import (
    ActivityInput,
    ActivityResult,
    SafeCode,
    SafeErrorCode,
)
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow
from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow


@activity.defn(name="admit_terminal_submission")
async def _admit_terminal_submission_for_test(inp: dict) -> dict:
    return {"outcome": "complete", "safe_code": "ok", "counts": {"duplicate": False}}


@pytest.mark.temporal
async def test_supervisor_submits_and_limits_concurrency(
    temporal_local_env, tmp_path: Path
) -> None:
    client = temporal_local_env.client

    # Dummy tracking activities
    started_subjects: list[str] = []
    completed_subjects: list[str] = []
    max_concurrent_observed = 0
    active_now = 0

    @activity.defn(name="mock_child_execute")
    async def mock_child_execute(inp: dict) -> dict:
        nonlocal max_concurrent_observed, active_now
        subj_id = inp.get("subject_run_id", "")
        started_subjects.append(subj_id)
        active_now += 1
        if active_now > max_concurrent_observed:
            max_concurrent_observed = active_now

        # Sleep briefly to simulate work and test concurrency overlap
        await asyncio.sleep(0.2)

        active_now -= 1
        completed_subjects.append(subj_id)
        return {"outcome": "complete", "safe_code": "ok", "subject_run_id": subj_id}

    async with Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow, TerminalSubjectWorkflow],
        activities=[_admit_terminal_submission_for_test, mock_child_execute],
    ):
        # Start supervisor workflow
        supervisor_handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id="v3-terminal-supervisor",
            task_queue="v3-terminal-control",
        )

        # Submit 5 subjects across 2 submissions
        sub1_id = str(uuid.uuid4())
        subjects_sub1 = [str(uuid.uuid4()) for _ in range(3)]
        sub1_payload = {
            "schema_version": 1,
            "submission_id": sub1_id,
            "submission_digest": "a" * 64,
            "manifest_ref": "manifests/sub1.json",
            "subjects": [
                {"subject_run_id": s, "run_ref": f"runs/{s}", "title": f"Subj {s[:4]}", "mock": True}
                for s in subjects_sub1
            ],
        }

        sub2_id = str(uuid.uuid4())
        subjects_sub2 = [str(uuid.uuid4()) for _ in range(2)]
        sub2_payload = {
            "schema_version": 1,
            "submission_id": sub2_id,
            "submission_digest": "b" * 64,
            "manifest_ref": "manifests/sub2.json",
            "subjects": [
                {"subject_run_id": s, "run_ref": f"runs/{s}", "title": f"Subj {s[:4]}", "mock": True}
                for s in subjects_sub2
            ],
        }

        # Send submit_v1 update for submission 1
        res1 = await supervisor_handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            sub1_payload,
        )
        assert res1["outcome"] == "accepted"
        assert res1["submission_id"] == sub1_id

        # Send submit_v1 update for submission 2
        res2 = await supervisor_handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            sub2_payload,
        )
        assert res2["outcome"] == "accepted"
        assert res2["submission_id"] == sub2_id

        # Duplicate submit_v1 update returns accepted without duplicating subjects
        res1_dup = await supervisor_handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            sub1_payload,
        )
        assert res1_dup["outcome"] == "accepted"
        assert res1_dup["submission_id"] == sub1_id

        # Wait for all 5 subjects to complete
        for _ in range(50):
            if len(completed_subjects) == 5:
                break
            await asyncio.sleep(0.1)

        assert len(completed_subjects) == 5
        # The global limit is 4 subjects admitted at any one time!
        assert max_concurrent_observed <= 4


@pytest.mark.temporal
async def test_supervisor_rejects_when_queue_full(temporal_local_env) -> None:
    client = temporal_local_env.client

    async with Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow],
        activities=[_admit_terminal_submission_for_test],
    ):
        supervisor_handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id=f"v3-terminal-supervisor-full-{uuid.uuid4().hex[:6]}",
            task_queue="v3-terminal-control",
        )

        # Craft submission with 65 subjects (> 64 limit)
        sub_id = str(uuid.uuid4())
        subjects_65 = [
            {"subject_run_id": str(uuid.uuid4()), "run_ref": "ref", "title": "t"}
            for _ in range(65)
        ]
        payload = {
            "schema_version": 1,
            "submission_id": sub_id,
            "submission_digest": "c" * 64,
            "manifest_ref": "manifests/sub65.json",
            "subjects": subjects_65,
        }

        res = await supervisor_handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            payload,
        )
        assert res["outcome"] == "rejected"
        assert res["safe_code"] == SafeCode.queue_full.value


def test_worker_process_lock_blocks_second_instance(tmp_path: Path) -> None:
    from v3_engine.terminal_pipeline.worker import (
        TerminalWorkerProcessLock,
        TerminalWorkerLockError,
    )

    lock_file = tmp_path / ".worker.lock"
    lock1 = TerminalWorkerProcessLock(lock_file)
    lock1.acquire()

    # Second instance attempting to acquire raises TerminalWorkerLockError
    lock2 = TerminalWorkerProcessLock(lock_file)
    with pytest.raises(TerminalWorkerLockError, match="Another terminal worker instance is already running"):
        lock2.acquire()

    # Once lock1 releases, lock2 can acquire
    lock1.release()
    lock2.acquire()
    lock2.release()

