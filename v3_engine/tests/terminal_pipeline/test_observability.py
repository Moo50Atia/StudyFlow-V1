"""Tests for observability, journal events, log cursors, and status fallback (Spec 018 / T024)."""

from __future__ import annotations

import json
import uuid
from pathlib import Path
import pytest
from unittest.mock import AsyncMock, MagicMock

from v3_engine.terminal_pipeline.contracts import (
    SafeCode,
    SafeErrorCode,
)
from v3_engine.terminal_pipeline.journal import (
    JournalError,
    OperationalEvent,
    OperationalJournal,
    make_stable_event_id,
)
from v3_engine.terminal_pipeline.status import (
    SubjectProgressProjection,
    get_subject_status,
)
from v3_engine.terminal_pipeline.store import (
    TerminalIndexStore,
)


def test_journal_event_sequence_durations_and_nulls(tmp_path: Path) -> None:
    journal = OperationalJournal(tmp_path)
    sub_run_id = str(uuid.uuid4())

    # 1. Started event: duration_ms is None (ongoing)
    ev_start = OperationalEvent(
        event_id=make_stable_event_id("create_notebook", 1, "start"),
        kind="operation_started",
        occurred_at="2026-10-09T10:00:00Z",
        subject_run_id=sub_run_id,
        operation_id="op-nb-create",
        attempt=1,
        phase="creating_notebook",
        duration_ms=None,
    )
    seq1 = journal.append_event(ev_start)
    assert seq1 == 1

    # 2. Completed event: duration_ms is measured integer >= 0
    ev_comp = OperationalEvent(
        event_id=make_stable_event_id("create_notebook", 1, "complete"),
        kind="operation_completed",
        occurred_at="2026-10-09T10:00:02Z",
        subject_run_id=sub_run_id,
        operation_id="op-nb-create",
        attempt=1,
        phase="creating_notebook",
        outcome="complete",
        duration_ms=2150,
        safe_code=SafeCode.ok.value,
        artifact_ref="notebooks/nb-1.json",
    )
    seq2 = journal.append_event(ev_comp)
    assert seq2 == 2

    # Query with cursor
    events = journal.read_events(after_seq=0)
    assert len(events) == 2
    assert events[0].duration_ms is None
    assert events[1].duration_ms == 2150

    # Query after seq 1
    events_after = journal.read_events(after_seq=1)
    assert len(events_after) == 1
    assert events_after[0].seq == 2


def test_journal_duplicate_publication_idempotency_and_conflict(tmp_path: Path) -> None:
    journal = OperationalJournal(tmp_path)
    ev_id = make_stable_event_id("send_turn", 1, "complete")

    ev1 = OperationalEvent(
        event_id=ev_id,
        kind="turn_sent",
        occurred_at="2026-10-09T10:00:00Z",
        outcome="complete",
        safe_code=SafeCode.ok.value,
        duration_ms=1200,
    )
    seq1 = journal.append_event(ev1)

    # Identical replay returns same seq
    seq1_dup = journal.append_event(ev1)
    assert seq1_dup == seq1

    # Conflicting payload with same event_id raises JournalError
    ev_conflict = OperationalEvent(
        event_id=ev_id,
        kind="turn_sent",
        occurred_at="2026-10-09T10:00:00Z",
        outcome="failed",  # CONFLICT
        safe_code=SafeCode.delivery_uncertain.value,
    )
    with pytest.raises(JournalError, match="Conflicting event payload"):
        journal.append_event(ev_conflict)


def test_status_online_vs_offline_last_known_fallback(tmp_path: Path) -> None:
    db_file = tmp_path / "index.sqlite"
    index_store = TerminalIndexStore(db_file)
    index_store.initialize_schema()

    sub_id = str(uuid.uuid4())
    run_id = str(uuid.uuid4())
    index_store.register_submission(sub_id, "d"*64, "manifest.json")
    index_store.enqueue_subject(
        subject_run_id=run_id,
        submission_id=sub_id,
        subject_key="physics",
        workflow_id=f"v3-terminal-subject-{run_id}",
        manifest_ref="manifest.json",
        run_ref=f"runs/{run_id}",
        generation_root_ref=f"runs/{run_id}",
    )

    # 1. Online query succeeds -> freshness="realtime"
    mock_handle = MagicMock()
    mock_handle.query = AsyncMock(return_value={
        "phase": "generating_scenes",
        "status": "running",
        "lessons_saved": 4,
        "scenes_generated": 10,
        "scenes_built": 8,
        "state_version": 5,
        "paused": False,
        "cancelled": False,
    })
    mock_client = MagicMock()
    mock_client.get_workflow_handle = MagicMock(return_value=mock_handle)

    status_online = get_subject_status(run_id, client=mock_client, index_store=index_store)
    assert status_online.freshness == "realtime"
    assert status_online.status == "running"
    assert status_online.phase == "generating_scenes"
    assert status_online.lessons_saved == 4
    assert status_online.scenes_built == 8

    # 2. Online query fails (e.g. worker / temporal offline) -> fallback to index store with freshness="last_known"
    mock_handle_err = MagicMock()
    mock_handle_err.query = AsyncMock(side_effect=RuntimeError("Temporal server unreachable"))
    mock_client_err = MagicMock()
    mock_client_err.get_workflow_handle = MagicMock(return_value=mock_handle_err)

    status_offline = get_subject_status(run_id, client=mock_client_err, index_store=index_store)
    assert status_offline.freshness == "last_known"
    assert status_offline.status == "queued"
    # When offline and counts are not recorded, counts must be None (not 0!)
    assert status_offline.lessons_saved is None
    assert status_offline.scenes_built is None

    # 3. Completely unknown subject yields status=None and unknown counts
    unknown_id = str(uuid.uuid4())
    status_unknown = get_subject_status(unknown_id, client=None, index_store=index_store)
    assert status_unknown.status is None
    assert status_unknown.freshness == "last_known"
    assert status_unknown.lessons_saved is None
