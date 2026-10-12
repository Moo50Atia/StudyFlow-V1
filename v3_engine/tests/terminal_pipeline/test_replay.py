"""Tests for deterministic history replay using Replayer and zero journal duplicate events (Spec 018 / T026)."""

from __future__ import annotations

import asyncio
from pathlib import Path
import uuid
import pytest
from temporalio import activity, workflow
from temporalio.client import Client
from temporalio.worker import Replayer, Worker

from v3_engine.terminal_pipeline.journal import OperationalJournal
from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow


@activity.defn(name="admit_terminal_submission")
async def _admit_terminal_submission_for_test(inp: dict) -> dict:
    return {"outcome": "complete", "safe_code": "ok", "counts": {"duplicate": False}}


@pytest.mark.temporal
async def test_supervisor_workflow_replay_determinism(temporal_local_env) -> None:
    """Temporal Replayer must replay supervisor workflow history with zero non-determinism errors."""
    client = temporal_local_env.client

    @activity.defn(name="mock_child_execute")
    async def mock_child_execute(inp: dict) -> dict:
        return {"outcome": "complete", "safe_code": "ok", "subject_run_id": inp.get("subject_run_id")}

    worker = Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow],
        activities=[_admit_terminal_submission_for_test, mock_child_execute],
    )

    wf_id = f"v3-sup-replay-{uuid.uuid4().hex[:8]}"
    async with worker:
        handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id=wf_id,
            task_queue="v3-terminal-control",
        )

        sub_id = str(uuid.uuid4())
        s1_id = str(uuid.uuid4())
        await handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            {
                "submission_id": sub_id,
                "submission_digest": "digest1",
                "manifest_ref": "manifest.json",
                "subjects": [{"subject_run_id": s1_id, "mock": True}],
            },
        )

        # Wait for child to complete
        async def _wait():
            while True:
                prog = await handle.query("progress_v1")
                if prog["active_count"] == 0 and prog["queued_count"] == 0:
                    break
                await asyncio.sleep(0.05)

        await asyncio.wait_for(_wait(), timeout=5.0)

    # Fetch history
    history = await handle.fetch_history()
    assert history is not None

    # Replay using Temporal Replayer
    replayer = Replayer(workflows=[TerminalSupervisorWorkflow])
    replay_result = await replayer.replay_workflow(history)
    assert replay_result.replay_failure is None


def test_journal_replay_zero_duplicate_events(tmp_path: Path) -> None:
    """Journal reading and local publication add zero duplicate events."""
    from v3_engine.terminal_pipeline.journal import OperationalEvent, make_stable_event_id

    journal_path = tmp_path / "journal.sqlite"
    journal = OperationalJournal(journal_path)

    run_id = str(uuid.uuid4())
    # Emit events
    ev1 = OperationalEvent(
        event_id=make_stable_event_id("op-1", 1, "send_dispatched"),
        occurred_at="2026-10-09T10:00:00Z",
        run_id=run_id,
        operation_id="op-1",
        phase="dividing",
        kind="send_dispatched",
        outcome="dispatched",
        safe_code="ok",
    )
    seq1 = journal.append_event(ev1)

    ev2 = OperationalEvent(
        event_id=make_stable_event_id("op-1", 1, "response_received"),
        occurred_at="2026-10-09T10:00:01Z",
        run_id=run_id,
        operation_id="op-1",
        phase="dividing",
        kind="response_received",
        outcome="complete",
        safe_code="ok",
        duration_ms=120,
    )
    seq2 = journal.append_event(ev2)

    assert seq1 == 1
    assert seq2 == 2

    # Read events from beginning
    events = journal.read_events(after_seq=0)
    assert len(events) == 2
    assert [e.seq for e in events] == [1, 2]

    # Re-read after seq 1
    events_after_1 = journal.read_events(after_seq=1)
    assert len(events_after_1) == 1
    assert events_after_1[0].seq == 2

    # Re-reading after seq 2 yields empty list (no phantom duplicates)
    events_after_2 = journal.read_events(after_seq=2)
    assert len(events_after_2) == 0

    # Idempotent re-append of exact same event yields same sequence and does not duplicate
    dup_seq1 = journal.append_event(ev1)
    assert dup_seq1 == 1
    events_all = journal.read_events(after_seq=0)
    assert len(events_all) == 2

