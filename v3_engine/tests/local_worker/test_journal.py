"""Tests for local SQLite effect journal and crash window tracking."""

import sqlite3
import pytest
from pathlib import Path
from v3_engine.local_worker.journal import LocalEffectJournal


def test_journal_initialization_and_wal_mode(tmp_path: Path):
    db_path = tmp_path / "journal.sqlite"
    journal = LocalEffectJournal(db_path)

    with journal._get_connection() as conn:
        cur = conn.execute("PRAGMA journal_mode;")
        mode = cur.fetchone()[0]
        assert mode.lower() == "wal"


def test_journal_record_intent_and_idempotency(tmp_path: Path):
    db_path = tmp_path / "journal.sqlite"
    journal = LocalEffectJournal(db_path)

    eff1 = journal.record_intent(
        effect_id="eff-1",
        task_id="task-1",
        logical_effect_key="job-1:divide:part-1",
        operation_kind="divide_map",
        request_digest="abc123sha",
    )
    assert eff1.effect_id == "eff-1"
    assert eff1.status == "reserved"

    # Same key and same digest returns existing
    eff2 = journal.record_intent(
        effect_id="eff-different-uuid",
        task_id="task-1",
        logical_effect_key="job-1:divide:part-1",
        operation_kind="divide_map",
        request_digest="abc123sha",
    )
    assert eff2.effect_id == "eff-1"

    # Same key with different digest conflicts
    with pytest.raises(ValueError, match="already exists with different request digest"):
        journal.record_intent(
            effect_id="eff-3",
            task_id="task-1",
            logical_effect_key="job-1:divide:part-1",
            operation_kind="divide_map",
            request_digest="different-sha",
        )


def test_journal_permit_and_may_have_been_sent(tmp_path: Path):
    db_path = tmp_path / "journal.sqlite"
    journal = LocalEffectJournal(db_path)

    eff = journal.record_intent("eff-1", "task-1", "key-1", "divide_map", "sha1")

    att = journal.record_permit(
        attempt_id="att-1",
        effect_id="eff-1",
        attempt_number=1,
        permit_id="permit-1",
        permit_expires_at="2026-10-18T12:00:00Z",
    )
    assert att.status == "issued"

    updated_eff = journal.get_effect("eff-1")
    assert updated_eff.status == "dispatching"
    assert updated_eff.current_attempt_id == "att-1"

    # Mark crash window
    journal.mark_may_have_been_sent("att-1")

    with journal._get_connection() as conn:
        cur = conn.execute("SELECT status FROM local_attempts WHERE attempt_id = 'att-1'")
        assert cur.fetchone()[0] == "may_have_been_sent"

    # Pending effects list should include it
    pending = journal.list_pending_effects("task-1")
    assert len(pending) == 1
    assert pending[0].effect_id == "eff-1"


def test_journal_outcome_recording(tmp_path: Path):
    db_path = tmp_path / "journal.sqlite"
    journal = LocalEffectJournal(db_path)

    journal.record_intent("eff-1", "task-1", "key-1", "divide_map", "sha1")
    journal.record_permit("att-1", "eff-1", 1, "permit-1", "2026-10-18T12:00:00Z")

    journal.record_outcome(
        attempt_id="att-1",
        status="succeeded",
        outcome_ref="out-123",
        raw_response_path="/tmp/response.raw",
    )

    eff = journal.get_effect("eff-1")
    assert eff.status == "succeeded"

    # Once succeeded, not in pending
    pending = journal.list_pending_effects("task-1")
    assert len(pending) == 0
