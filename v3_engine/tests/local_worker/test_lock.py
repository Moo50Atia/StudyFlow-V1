"""Tests for exclusive workspace locking."""

import pytest
from pathlib import Path
from v3_engine.local_worker.lock import ExclusiveWorkspaceLock, WorkerLockError


def test_exclusive_lock_acquire_and_release(tmp_path: Path):
    ws = tmp_path / "workspace"
    lock1 = ExclusiveWorkspaceLock(ws)
    lock1.acquire()

    assert (ws / "worker.lock").is_file()

    # Second lock on same workspace should fail
    lock2 = ExclusiveWorkspaceLock(ws)
    with pytest.raises(WorkerLockError):
        lock2.acquire()

    lock1.release()

    # Now lock2 can acquire
    lock2.acquire()
    lock2.release()


def test_lock_context_manager(tmp_path: Path):
    ws = tmp_path / "workspace"
    with ExclusiveWorkspaceLock(ws):
        assert (ws / "worker.lock").is_file()
        with pytest.raises(WorkerLockError):
            with ExclusiveWorkspaceLock(ws):
                pass

    # Released after context exit
    with ExclusiveWorkspaceLock(ws):
        pass
