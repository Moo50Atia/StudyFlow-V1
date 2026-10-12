"""Tests for terminal pipeline index, submission/subject lifecycle, and ownership store (Spec 018 / T004)."""

import json
import sqlite3
import pytest
from pathlib import Path

from v3_engine.terminal_pipeline.contracts import (
    ArtifactRef,
    SafeCode,
    SubjectLifecycle,
    TerminalPhase,
    TerminalStatus,
)
from v3_engine.terminal_pipeline.store import (
    ControlRecord,
    ExecutionOwnerMarker,
    LifecycleTransitionError,
    RunLockError,
    TerminalIndexStore,
    TerminalRunStore,
)


@pytest.fixture
def store_env(tmp_path: Path) -> tuple[Path, TerminalIndexStore]:
    terminal_root = tmp_path / "terminal_root"
    terminal_root.mkdir()
    index_store = TerminalIndexStore(terminal_root)
    index_store.initialize_schema()
    return terminal_root, index_store


def test_sqlite_schema_version_and_tables(store_env: tuple[Path, TerminalIndexStore]) -> None:
    term_root, index_store = store_env
    db_path = term_root / "index.sqlite"
    assert db_path.is_file()

    with sqlite3.connect(db_path) as conn:
        cursor = conn.cursor()
        cursor.execute("PRAGMA user_version;")
        ver = cursor.fetchone()[0]
        assert ver == 1

        cursor.execute("SELECT name FROM sqlite_master WHERE type='table';")
        tables = {row[0] for row in cursor.fetchall()}
        assert {"submissions", "subjects", "controls", "recoveries", "events"}.issubset(tables)


def test_submission_registration_and_deduplication(store_env: tuple[Path, TerminalIndexStore]) -> None:
    _, index_store = store_env
    sub_id = "92407f71-f6c4-4c78-9938-0cfeef937cf2"
    digest = "a" * 64
    manifest_ref = "submissions/sub-1/manifest.json"

    # Initial registration
    receipt = index_store.register_submission(sub_id, digest, manifest_ref)
    assert receipt["status"] == "accepted"

    # Duplicate same digest returns existing accepted receipt
    dup_receipt = index_store.register_submission(sub_id, digest, manifest_ref)
    assert dup_receipt == receipt

    # Conflicting digest raises input conflict
    with pytest.raises(LifecycleTransitionError, match="conflicts with existing submission"):
        index_store.register_submission(sub_id, "b" * 64, manifest_ref)


def test_subject_lifecycle_and_settlement_proof(store_env: tuple[Path, TerminalIndexStore]) -> None:
    _, index_store = store_env
    sub_id = "92407f71-f6c4-4c78-9938-0cfeef937cf2"
    index_store.register_submission(sub_id, "a" * 64, "manifest.json")

    run_id = "11111111-2222-3333-4444-555555555555"
    workflow_id = f"v3-terminal-subject-{run_id}"

    # Queue subject
    index_store.enqueue_subject(
        subject_run_id=run_id,
        submission_id=sub_id,
        subject_key="mechanics",
        workflow_id=workflow_id,
        manifest_ref="manifest.json",
        run_ref=f"runs/{run_id}/run.json",
        generation_root_ref=f"runs/{run_id}",
    )

    subj = index_store.get_subject(run_id)
    assert subj is not None
    assert subj["lifecycle"] == SubjectLifecycle.queued.value

    # queued -> launch_pending
    index_store.transition_subject_lifecycle(run_id, SubjectLifecycle.launch_pending)
    assert index_store.get_subject(run_id)["lifecycle"] == SubjectLifecycle.launch_pending.value

    # launch_pending -> started
    index_store.transition_subject_lifecycle(run_id, SubjectLifecycle.started, started_run_id="run-temporal-123")
    assert index_store.get_subject(run_id)["lifecycle"] == SubjectLifecycle.started.value

    # settlement requires close_confirmation_ref, not final report alone!
    with pytest.raises(LifecycleTransitionError, match="Close confirmation proof required for settlement"):
        index_store.transition_subject_lifecycle(run_id, SubjectLifecycle.settled, final_report_ref="report.json")

    # Settlement with real close confirmation succeeds
    index_store.transition_subject_lifecycle(
        run_id,
        SubjectLifecycle.settled,
        final_report_ref="report.json",
        close_confirmation_ref="runs/close-proof.json",
    )
    assert index_store.get_subject(run_id)["lifecycle"] == SubjectLifecycle.settled.value


def test_queued_cancellation_atomicity(store_env: tuple[Path, TerminalIndexStore]) -> None:
    _, index_store = store_env
    sub_id = "92407f71-f6c4-4c78-9938-0cfeef937cf2"
    index_store.register_submission(sub_id, "a" * 64, "manifest.json")
    run_id = "22222222-3333-4444-5555-666666666666"

    index_store.enqueue_subject(
        subject_run_id=run_id,
        submission_id=sub_id,
        subject_key="chem",
        workflow_id=f"v3-terminal-subject-{run_id}",
        manifest_ref="manifest.json",
        run_ref=f"runs/{run_id}/run.json",
        generation_root_ref=f"runs/{run_id}",
    )

    # Cancel while still queued -> cancelled_before_start
    index_store.cancel_queued_subject(
        subject_run_id=run_id,
        command_id="cmd-cancel-01",
        cancellation_receipt_ref="receipts/cancel.json",
    )
    subj = index_store.get_subject(run_id)
    assert subj["lifecycle"] == SubjectLifecycle.cancelled_before_start.value

    # Cannot launch once cancelled_before_start
    with pytest.raises(LifecycleTransitionError, match="Cannot transition from cancelled_before_start"):
        index_store.transition_subject_lifecycle(run_id, SubjectLifecycle.launch_pending)


def test_recovery_mutex_per_generation_root(store_env: tuple[Path, TerminalIndexStore]) -> None:
    _, index_store = store_env
    gen_root = "runs/original-run"

    rec1 = index_store.reserve_recovery(
        recovery_id="rec-001",
        original_generation_ref=gen_root,
        recovery_subject_id="sub-rec-1",
        mode="generate",
        command_digest="d1",
    )
    assert rec1 is True

    # Duplicate command returns True (idempotent)
    dup = index_store.reserve_recovery(
        recovery_id="rec-001",
        original_generation_ref=gen_root,
        recovery_subject_id="sub-rec-1",
        mode="generate",
        command_digest="d1",
    )
    assert dup is True

    # Second active recovery on same generation root must be rejected (recovery_busy)
    with pytest.raises(LifecycleTransitionError, match="recovery_busy"):
        index_store.reserve_recovery(
            recovery_id="rec-002",
            original_generation_ref=gen_root,
            recovery_subject_id="sub-rec-2",
            mode="build",
            command_digest="d2",
        )


def test_recovery_admission_registers_subject_and_settlement_releases_reservation(store_env) -> None:
    _, store = store_env
    submission_id = "92407f71-f6c4-4c78-9938-0cfeef937cf2"
    original_id = "11111111-2222-3333-4444-555555555555"
    recovery_id = "33333333-4444-5555-6666-777777777777"
    recovery_subject_id = "44444444-5555-6666-7777-888888888888"
    store.register_submission(submission_id, "a" * 64, "submissions/original/manifest.json")
    store.enqueue_subject(original_id, submission_id, "unit3", f"v3-terminal-subject-{original_id}",
                          "submissions/original/manifest.json", f"runs/{original_id}", f"runs/{original_id}")
    request = {
        "recovery_id": recovery_id,
        "recovery_subject_id": recovery_subject_id,
        "original_generation_ref": f"runs/{original_id}",
        "mode": "build",
        "command_digest": "b" * 64,
    }
    accepted = store.admit_recovery(request)
    assert accepted["outcome"] == "complete"
    assert store.admit_recovery(request)["duplicate"] is True
    child = store.get_subject(recovery_subject_id)
    assert child["lifecycle"] == SubjectLifecycle.queued.value
    assert child["generation_root_ref"] == f"runs/{original_id}"
    store.transition_subject_lifecycle(recovery_subject_id, SubjectLifecycle.started)
    store.settle_subject(recovery_subject_id, "partial", "reports/child.json", {"closed": True}, recovery_id)

    with store._get_connection() as conn:
        recovery = conn.execute("SELECT state FROM recoveries WHERE recovery_id=?", (recovery_id,)).fetchone()
    assert recovery["state"] == "settled"
    assert store.get_subject(recovery_subject_id)["lifecycle"] == SubjectLifecycle.settled.value


def test_managed_run_lock_and_marker(tmp_path: Path) -> None:
    terminal_root = tmp_path / "terminal_root"
    terminal_root.mkdir()
    subject_run_id = "33333333-4444-5555-6666-777777777777"

    run_store = TerminalRunStore(terminal_root, subject_run_id)
    run_store.initialize_run_dir()

    # Acquire lock and set managed execution marker
    with run_store.acquire_run_lock(owner_workflow_id="wf-1", owner_epoch=1) as marker:
        assert marker.current_owner_workflow_id == "wf-1"
        assert marker.owner_epoch == 1
        assert run_store.is_managed_root() is True

        # Second lock attempt on same run raises RunLockError (busy)
        second_store = TerminalRunStore(terminal_root, subject_run_id)
        with pytest.raises(RunLockError, match="lock_busy"):
            with second_store.acquire_run_lock(owner_workflow_id="wf-2", owner_epoch=1):
                pass

    # Once lock is released, marker file remains on disk indicating managed root
    assert run_store.is_managed_root() is True
    # Standalone code attempting unmanaged access to managed root is rejected
    assert run_store.check_standalone_access_allowed() is False
