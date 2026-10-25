"""Tests for operational event journal and status projections (Spec 018 / T004)."""

import json
import sqlite3
import pytest
from pathlib import Path

from v3_engine.terminal_pipeline.contracts import SafeCode, TerminalPhase
from v3_engine.terminal_pipeline.journal import (
    JournalError,
    OperationalEvent,
    OperationalJournal,
)
from v3_engine.terminal_pipeline.store import TerminalIndexStore
from v3_engine.terminal_pipeline.activities import _record_event


@pytest.fixture
def journal_env(tmp_path: Path) -> tuple[Path, OperationalJournal]:
    terminal_root = tmp_path / "terminal_root"
    terminal_root.mkdir()
    index_store = TerminalIndexStore(terminal_root)
    index_store.initialize_schema()
    journal = OperationalJournal(terminal_root)
    return terminal_root, journal


def test_journal_append_and_sequential_ordering(journal_env: tuple[Path, OperationalJournal]) -> None:
    _, journal = journal_env

    e1 = OperationalEvent(
        event_id="evt-001",
        kind="operation_prepared",
        occurred_at="2026-10-09T10:00:00Z",
        subject_run_id="sub-1",
        phase=TerminalPhase.upload.value,
        operation_id="op-1",
        attempt=1,
    )
    seq1 = journal.append_event(e1)

    e2 = OperationalEvent(
        event_id="evt-002",
        kind="operation_dispatched",
        occurred_at="2026-10-09T10:00:01Z",
        subject_run_id="sub-1",
        phase=TerminalPhase.upload.value,
        operation_id="op-1",
        attempt=1,
    )
    seq2 = journal.append_event(e2)

    assert seq2 > seq1

    # Fetch events in order
    events = journal.read_events(after_seq=0)
    assert len(events) == 2
    assert events[0].event_id == "evt-001"
    assert events[1].event_id == "evt-002"


def test_journal_duplicate_event_id_idempotency(journal_env: tuple[Path, OperationalJournal]) -> None:
    _, journal = journal_env

    e1 = OperationalEvent(
        event_id="evt-dup-001",
        kind="receipt_saved",
        occurred_at="2026-10-09T10:00:00Z",
        subject_run_id="sub-1",
        safe_code=SafeCode.ok.value,
    )
    seq1 = journal.append_event(e1)

    # Identical replay returns existing seq without error
    seq2 = journal.append_event(e1)
    assert seq1 == seq2

    # Conflicting payload for same event_id raises error
    conflicting_e = OperationalEvent(
        event_id="evt-dup-001",
        kind="receipt_saved",
        occurred_at="2026-10-09T10:00:00Z",
        subject_run_id="sub-1",
        safe_code=SafeCode.input_invalid.value,  # different safe_code!
    )
    with pytest.raises(JournalError, match="Conflicting event payload"):
        journal.append_event(conflicting_e)


def test_event_duration_preserves_null_for_unknown(journal_env: tuple[Path, OperationalJournal]) -> None:
    _, journal = journal_env

    event = OperationalEvent(
        event_id="evt-dur-001",
        kind="operation_completed",
        occurred_at="2026-10-09T10:00:00Z",
        duration_ms=None,  # unknown duration must NOT be converted to 0
    )
    journal.append_event(event)

    fetched = journal.read_events(after_seq=0)[0]
    assert fetched.duration_ms is None


def test_same_scene_identity_in_different_subjects_gets_distinct_journal_events(
    journal_env: tuple[Path, OperationalJournal], monkeypatch: pytest.MonkeyPatch
) -> None:
    terminal_root, journal = journal_env
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(terminal_root))
    scene_key = "notebook:conversation:W1:S1"

    _record_event("subject-a", "scene_generation", "generate", "failed", "provider_response",
                  operation_id=scene_key, attempt=1)
    _record_event("subject-b", "scene_generation", "generate", "complete", "ok",
                  operation_id=scene_key, attempt=1)

    events = journal.read_events(after_seq=0)
    assert len(events) == 2
    assert {event.subject_run_id for event in events} == {"subject-a", "subject-b"}


def test_export_failure_then_success_does_not_conflict_in_journal(journal_env,monkeypatch):
    root,journal=journal_env
    monkeypatch.setenv('V3_TERMINAL_ROOT',str(root))
    _record_event('subject','scene_prompts_exported','export_prompts','failed','input_invalid')
    _record_event('subject','scene_prompts_exported','export_prompts','complete','ok')
    _record_event('subject','scene_prompts_exported','export_prompts','complete','ok')
    assert [event.outcome for event in journal.read_events(after_seq=0)]==['failed','complete']
