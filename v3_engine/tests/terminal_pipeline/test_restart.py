"""Tests for same-DB restart, durable bootstrap, and lifecycle persistence (Spec 018 / T026)."""

from __future__ import annotations

import asyncio
from pathlib import Path
import uuid
import pytest
from temporalio import activity, workflow
from temporalio.client import Client, RPCError
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Worker

from v3_engine.terminal_pipeline.contracts import (
    ActivityOutcome,
    SafeCode,
    SubjectLifecycle,
)
from v3_engine.terminal_pipeline.store import (
    LifecycleTransitionError,
    TerminalIndexStore,
)
from v3_engine.terminal_pipeline.supervisor import (
    SupervisorBootstrapError,
    TerminalSupervisorWorkflow,
    bootstrap_supervisor_state,
)
from v3_engine.terminal_pipeline.status import get_subject_status


@activity.defn(name="admit_terminal_submission")
async def _admit_terminal_submission_for_test(inp: dict) -> dict:
    return {"outcome": "complete", "safe_code": "ok", "counts": {"duplicate": False}}


def test_durable_subject_lifecycle_transitions_and_settlement_proof(tmp_path: Path) -> None:
    """Validate subject lifecycle schema: queued -> launch_pending -> started -> settled with proof."""
    store = TerminalIndexStore(tmp_path / "index.sqlite")
    sub_id = str(uuid.uuid4())
    s1_id = str(uuid.uuid4())
    store.register_submission(sub_id, "digest1", f"manifests/{sub_id}.json")
    seq = store.enqueue_subject(
        subject_run_id=s1_id,
        submission_id=sub_id,
        subject_key="subject-001",
        workflow_id=f"v3-terminal-subject-{s1_id}",
        manifest_ref=f"manifests/{sub_id}.json",
        run_ref=f"runs/{s1_id}",
        generation_root_ref=f"runs/{s1_id}",
    )
    assert seq == 1
    subj = store.get_subject(s1_id)
    assert subj is not None
    assert subj["lifecycle"] == "queued"

    # Transition to launch_pending
    store.transition_subject_lifecycle(s1_id, SubjectLifecycle.launch_pending)
    subj = store.get_subject(s1_id)
    assert subj["lifecycle"] == "launch_pending"
    assert store.count_active_subjects() == 1

    # Transition to started
    store.transition_subject_lifecycle(s1_id, SubjectLifecycle.started, started_run_id="run-temporal-123")
    subj = store.get_subject(s1_id)
    assert subj["lifecycle"] == "started"
    assert subj["started_run_id"] == "run-temporal-123"
    assert store.count_active_subjects() == 1

    # Attempt settlement WITHOUT close proof -> must raise receipt_invalid
    with pytest.raises(LifecycleTransitionError) as exc_info:
        store.transition_subject_lifecycle(s1_id, SubjectLifecycle.settled)
    assert exc_info.value.safe_code == SafeCode.receipt_invalid

    # Settlement WITH close proof -> succeeds
    store.transition_subject_lifecycle(
        s1_id,
        SubjectLifecycle.settled,
        final_report_ref=f"runs/{s1_id}/final-report.json",
        close_confirmation_ref=f"runs/{s1_id}/close-proof.json",
    )
    subj = store.get_subject(s1_id)
    assert subj["lifecycle"] == "settled"
    assert subj["close_confirmation_ref"] == f"runs/{s1_id}/close-proof.json"
    assert store.count_active_subjects() == 0

    # Once settled, transitioning back to started or queued is forbidden
    with pytest.raises(LifecycleTransitionError) as exc_info:
        store.transition_subject_lifecycle(s1_id, SubjectLifecycle.started)
    assert exc_info.value.safe_code == SafeCode.control_conflict


def test_queued_cancellation_permanently_excluded_from_bootstrap(tmp_path: Path) -> None:
    """Queued cancellation must persist cancelled_before_start and be excluded permanently on bootstrap."""
    store = TerminalIndexStore(tmp_path / "index.sqlite")
    sub_id = str(uuid.uuid4())
    s1_id = str(uuid.uuid4())
    s2_id = str(uuid.uuid4())
    s3_id = str(uuid.uuid4())

    store.register_submission(sub_id, "digest1", f"manifests/{sub_id}.json")
    for s_id, s_key in [(s1_id, "sub-1"), (s2_id, "sub-2"), (s3_id, "sub-3")]:
        store.enqueue_subject(
            subject_run_id=s_id,
            submission_id=sub_id,
            subject_key=s_key,
            workflow_id=f"v3-terminal-subject-{s_id}",
            manifest_ref=f"manifests/{sub_id}.json",
            run_ref=f"runs/{s_id}",
            generation_root_ref=f"runs/{s_id}",
        )

    # Cancel s2 while queued
    cmd_id = str(uuid.uuid4())
    store.cancel_queued_subject(s2_id, command_id=cmd_id, cancellation_receipt_ref=f"receipts/{cmd_id}.json")

    s2_row = store.get_subject(s2_id)
    assert s2_row["lifecycle"] == "cancelled_before_start"

    # Simulate supervisor crash and bootstrap from SQLite index
    bootstrapped = bootstrap_supervisor_state(store)

    # s2 must be in cancelled_queued_runs and NEVER in the reconstructed queue
    assert s2_id in bootstrapped["cancelled_queued_runs"]
    queued_ids = [s["subject_run_id"] for s in bootstrapped["queue"]]
    assert s2_id not in queued_ids
    assert queued_ids == [s1_id, s3_id]


def test_expired_completed_history_with_retained_close_proof(tmp_path: Path) -> None:
    """Settled subjects with close proof survive history expiration and do not block bootstrap."""
    store = TerminalIndexStore(tmp_path / "index.sqlite")
    sub_id = str(uuid.uuid4())
    s1_id = str(uuid.uuid4())
    store.register_submission(sub_id, "digest1", f"manifests/{sub_id}.json")
    store.enqueue_subject(
        subject_run_id=s1_id,
        submission_id=sub_id,
        subject_key="sub-1",
        workflow_id=f"v3-terminal-subject-{s1_id}",
        manifest_ref=f"manifests/{sub_id}.json",
        run_ref=f"runs/{s1_id}",
        generation_root_ref=f"runs/{s1_id}",
    )
    store.transition_subject_lifecycle(s1_id, SubjectLifecycle.started, started_run_id="run-old")
    store.settle_subject(
        subject_run_id=s1_id,
        terminal_status="completed",
        final_report_ref=f"runs/{s1_id}/final-report.json",
        close_proof=f"runs/{s1_id}/close-proof.json",
    )

    # Mock describe function simulating history expiry (30 days past retention)
    def mock_describe_expired(workflow_id: str) -> None:
        raise RPCError("Workflow not found")

    # Bootstrap must NOT call Describe for settled subjects with close proof, and must succeed
    bootstrapped = bootstrap_supervisor_state(store, describe_fn=mock_describe_expired)
    assert s1_id in bootstrapped["completed_runs"]

    # Status query offline fallback recognizes settled subject without live temporal
    status = get_subject_status(s1_id, client=None, index_store=store)
    assert status.lifecycle == "settled"
    assert status.status == "settled"


def test_missing_unresolved_child_status_refuses_bootstrap(tmp_path: Path) -> None:
    """Unresolved started/launch_pending chain without close proof blocks bootstrap if history is missing."""
    store = TerminalIndexStore(tmp_path / "index.sqlite")
    sub_id = str(uuid.uuid4())
    s1_id = str(uuid.uuid4())
    store.register_submission(sub_id, "digest1", f"manifests/{sub_id}.json")
    store.enqueue_subject(
        subject_run_id=s1_id,
        submission_id=sub_id,
        subject_key="sub-1",
        workflow_id=f"v3-terminal-subject-{s1_id}",
        manifest_ref=f"manifests/{sub_id}.json",
        run_ref=f"runs/{s1_id}",
        generation_root_ref=f"runs/{s1_id}",
    )
    # Marked started, but NEVER settled and NO close proof!
    store.transition_subject_lifecycle(s1_id, SubjectLifecycle.started, started_run_id="run-unresolved")

    # When history is unavailable/missing
    def mock_describe_missing(workflow_id: str) -> None:
        raise RPCError("Workflow not found")

    with pytest.raises(SupervisorBootstrapError) as exc_info:
        bootstrap_supervisor_state(store, describe_fn=mock_describe_missing)

    assert exc_info.value.safe_code == SafeCode.supervisor_recovery_required


@pytest.mark.temporal
async def test_same_db_server_and_worker_restart(tmp_path: Path) -> None:
    """Real same-DB server and worker restart preserves state and histories."""
    db_file = tmp_path / "server.sqlite"
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not Path(temporal_bin).is_file():
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    store = TerminalIndexStore(tmp_path / "index.sqlite")
    sub_id = str(uuid.uuid4())
    s1_id = str(uuid.uuid4())
    store.register_submission(sub_id, "digest1", f"manifests/{sub_id}.json")
    store.enqueue_subject(
        subject_run_id=s1_id,
        submission_id=sub_id,
        subject_key="sub-1",
        workflow_id=f"v3-terminal-subject-{s1_id}",
        manifest_ref=f"manifests/{sub_id}.json",
        run_ref=f"runs/{s1_id}",
        generation_root_ref=f"runs/{s1_id}",
    )

    # 1. Start server with persistent database
    env1 = await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        namespace="v3-terminal",
        dev_server_database_filename=str(db_file),
    )

    @activity.defn(name="mock_child_execute")
    async def mock_child_execute(inp: dict) -> dict:
        return {"outcome": "complete", "safe_code": "ok", "subject_run_id": inp.get("subject_run_id")}

    worker1 = Worker(
        env1.client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow],
        activities=[_admit_terminal_submission_for_test, mock_child_execute],
    )

    async with worker1:
        handle1 = await env1.client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id="v3-terminal-supervisor",
            task_queue="v3-terminal-control",
        )
        res = await handle1.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            {
                "submission_id": sub_id,
                "submission_digest": "digest1",
                "manifest_ref": f"manifests/{sub_id}.json",
                "subjects": [{"subject_run_id": s1_id, "mock": True}],
            },
        )
        assert res["outcome"] == "accepted"

    # Stop server 1
    await env1.shutdown()

    # 2. Restart server 2 with the EXACT SAME database file
    env2 = await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        namespace="v3-terminal",
        dev_server_database_filename=str(db_file),
    )

    try:
        # Reconnect to restarted server
        handle2 = env2.client.get_workflow_handle("v3-terminal-supervisor")
        desc = await handle2.describe()
        assert desc.status is not None

        # Start worker 2 with the same SQLite store
        worker2 = Worker(
            env2.client,
            task_queue="v3-terminal-control",
            workflows=[TerminalSupervisorWorkflow],
            activities=[_admit_terminal_submission_for_test, mock_child_execute],
        )
        async with worker2:
            prog = await handle2.query("progress_v1")
            assert prog is not None
    finally:
        await env2.shutdown()
