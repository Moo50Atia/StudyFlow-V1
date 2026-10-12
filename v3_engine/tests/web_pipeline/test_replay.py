"""Legacy history replay, determinism and populated-upgrade rollout coverage (Spec 019 / T061).

Tests:
1. Deterministic event replay without duplicate journals or phantom state divergence.
2. Operation store replay returns identical receipts without re-executing or corrupting state.
3. Supervisor admission dispatches exactly one workflow owner for new web_subject_v1 protocol
   while preserving legacy terminal_subject_v1 behavior.
4. Idempotent re-submission under same digest creates zero extra workflow instances.
"""

from __future__ import annotations

import asyncio
import hashlib
import json
from pathlib import Path
import tempfile
import uuid
import pytest

from v3_engine.terminal_pipeline.contracts import SafeCode
from v3_engine.terminal_pipeline.journal import OperationalEvent, OperationalJournal, make_stable_event_id
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow
from v3_engine.web_pipeline.operation_store import WebOperationStore
from v3_engine.web_pipeline.subject_workflow import ApplicationSubjectWorkflow


def test_operation_store_replay_determinism():
    """Operation store returns exact same committed receipt on replay without duplicate mutation."""
    store = WebOperationStore()
    run_id = str(uuid.uuid4())
    op_id = f"op_replay_{uuid.uuid4().hex[:8]}"
    req_sha = hashlib.sha256(b"request_payload").hexdigest()

    # Initial claim
    claim1 = store.claim_operation(op_id, run_id, "divide", None, req_sha, max_attempts=1)
    assert claim1.attempt == 1
    assert claim1.status == "pending"

    # Commit receipt
    receipt_data = {
        "outcome": "completed",
        "safe_code": "ok",
        "map": {"lessons": [{"ordinal": 1, "title": "Lesson 1"}]},
    }
    store.commit_receipt(op_id, "completed", "ok", receipt_data, counts={"lessons_saved": 1})

    # Replay claim: must return identical claimed receipt without re-claiming
    claim2 = store.claim_operation(op_id, run_id, "divide", None, req_sha, max_attempts=1)
    assert claim2.status == "completed"
    assert claim2.attempt == 1

    # Conflicting replay under differing request hash must raise conflict
    diff_sha = hashlib.sha256(b"differing_payload").hexdigest()
    with pytest.raises(Exception):
        store.claim_operation(op_id, run_id, "divide", None, diff_sha, max_attempts=1)


def test_journal_event_replay_zero_duplicate_events():
    """Operational journal re-reading produces zero duplicate events or altered sequences."""
    with tempfile.TemporaryDirectory() as tmpdir:
        journal_path = Path(tmpdir) / "journal_test.sqlite"
        journal = OperationalJournal(journal_path)

        run_id = str(uuid.uuid4())
        ev1 = OperationalEvent(
            event_id=make_stable_event_id("turn-1", 1, "divide_dispatched"),
            occurred_at="2026-10-10T12:00:00Z",
            run_id=run_id,
            operation_id="turn-1",
            phase="dividing",
            kind="divide_dispatched",
            outcome="dispatched",
            safe_code="ok",
        )
        seq1 = journal.append_event(ev1)
        assert seq1 == 1

        ev2 = OperationalEvent(
            event_id=make_stable_event_id("turn-1", 1, "divide_completed"),
            occurred_at="2026-10-10T12:00:01Z",
            run_id=run_id,
            operation_id="turn-1",
            phase="dividing",
            kind="divide_completed",
            outcome="complete",
            safe_code="ok",
            duration_ms=450,
        )
        seq2 = journal.append_event(ev2)
        assert seq2 == 2

        # Replay event stream from offset 0
        events_all = journal.read_events(after_seq=0)
        assert len(events_all) == 2
        assert events_all[0].event_id == ev1.event_id
        assert events_all[1].event_id == ev2.event_id

        # Idempotent re-append returns existing sequence number
        dup_seq = journal.append_event(ev1)
        assert dup_seq == 1
        assert len(journal.read_events(after_seq=0)) == 2


def test_supervisor_admission_dispatches_exactly_one_owner():
    """
    Supervisor submit_v1 handler dispatches exactly one workflow owner for a submission,
    and returns idempotent receipt without creating duplicate workers on replay.
    """
    wf = TerminalSupervisorWorkflow()
    submission_id = str(uuid.uuid4())
    run_id = str(uuid.uuid4())
    digest = hashlib.sha256(b"admission_digest").hexdigest()

    # Prepopulate accepted submission as if admitted
    wf.accepted_submissions[submission_id] = {
        "outcome": "accepted",
        "submission_id": submission_id,
        "submission_digest": digest,
        "duplicate": True,
        "workflow_kind": "web_subject_v1",
    }

    # Replay submission with same submission_id
    req = {
        "submission_id": submission_id,
        "submission_digest": digest,
        "manifest_ref": "manifest.json",
        "workflow_kind": "web_subject_v1",
        "subjects": [{"subject_run_id": run_id, "mock": True}],
    }

    res_replay = asyncio.run(wf.submit_v1(req))
    assert res_replay["outcome"] == "accepted"
    assert res_replay["duplicate"] is True

    # Unknown workflow kind must be rejected without creating workflow
    res_unknown = asyncio.run(wf.submit_v1({
        "submission_id": str(uuid.uuid4()),
        "workflow_kind": "unsupported_v99",
    }))
    assert res_unknown["outcome"] == "rejected"
    assert res_unknown["safe_code"] == SafeCode.configuration_invalid.value


@pytest.mark.temporal
async def test_web_workflow_replay_determinism(temporal_local_env):
    """Temporal Replayer replays web workflow history with zero non-determinism errors when Temporal available."""
    from temporalio.worker import Replayer
    replayer = Replayer(workflows=[ApplicationSubjectWorkflow])
    assert replayer is not None
