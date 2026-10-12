"""Tests for genuine concurrency, OS process locks, barriers, and subprocess reaping (Spec 018 / T020)."""

from __future__ import annotations

import multiprocessing
import os
import signal
import sys
import time
from pathlib import Path
import pytest

from v3_engine.scene_generation.builder import _run_step
from v3_engine.terminal_pipeline.contracts import (
    SafeCode,
)
from v3_engine.terminal_pipeline.store import (
    ExecutionOwnerMarker,
    RunLockError,
    TerminalIndexStore,
    TerminalRunStore,
)
from v3_engine.terminal_pipeline.worker import (
    TerminalWorkerLockError,
    TerminalWorkerProcessLock,
)


def _worker_lock_proc(lock_path_str: str, queue_in: multiprocessing.Queue, queue_out: multiprocessing.Queue) -> None:
    lock_path = Path(lock_path_str)
    lock = TerminalWorkerProcessLock(lock_path)
    try:
        lock.acquire()
        queue_out.put("acquired")
        # Wait for signal to release
        _ = queue_in.get(timeout=10)
    except Exception as exc:
        queue_out.put(f"error:{type(exc).__name__}")
    finally:
        lock.release()
        queue_out.put("released")


def _run_lock_proc(root_str: str, run_id: str, owner_id: str, epoch: int, queue_in: multiprocessing.Queue, queue_out: multiprocessing.Queue) -> None:
    store = TerminalRunStore(Path(root_str), run_id)
    try:
        with store.acquire_run_lock(owner_id, epoch) as marker:
            queue_out.put("acquired")
            # Wait for signal to release
            _ = queue_in.get(timeout=10)
    except Exception as exc:
        queue_out.put(f"error:{type(exc).__name__}")


def test_competing_worker_process_lock_across_os_processes(tmp_path: Path) -> None:
    lock_path = tmp_path / ".worker.lock"

    q_in1 = multiprocessing.Queue()
    q_out1 = multiprocessing.Queue()
    p1 = multiprocessing.Process(
        target=_worker_lock_proc,
        args=(str(lock_path), q_in1, q_out1),
    )
    p1.start()

    try:
        # Wait for process 1 to acquire lock
        status1 = q_out1.get(timeout=5)
        assert status1 == "acquired"

        # Now attempt to acquire in process 2
        q_in2 = multiprocessing.Queue()
        q_out2 = multiprocessing.Queue()
        p2 = multiprocessing.Process(
            target=_worker_lock_proc,
            args=(str(lock_path), q_in2, q_out2),
        )
        p2.start()

        try:
            status2 = q_out2.get(timeout=5)
            # Must raise TerminalWorkerLockError
            assert status2 == "error:TerminalWorkerLockError"
        finally:
            p2.join(timeout=2)

        # Signal process 1 to release
        q_in1.put("release")
        status1_rel = q_out1.get(timeout=5)
        assert status1_rel == "released"

    finally:
        p1.join(timeout=2)


def test_competing_run_lock_and_late_sender_rejection(tmp_path: Path) -> None:
    root = tmp_path / "terminal"
    run_id = "00000000-0000-4000-8000-000000000001"

    q_in1 = multiprocessing.Queue()
    q_out1 = multiprocessing.Queue()
    p1 = multiprocessing.Process(
        target=_run_lock_proc,
        args=(str(root), run_id, "wf-original", 1, q_in1, q_out1),
    )
    p1.start()

    try:
        status1 = q_out1.get(timeout=5)
        assert status1 == "acquired"

        # Attempt to acquire in competing second process (e.g. recovery or late timed-out sender)
        q_in2 = multiprocessing.Queue()
        q_out2 = multiprocessing.Queue()
        p2 = multiprocessing.Process(
            target=_run_lock_proc,
            args=(str(root), run_id, "wf-competing", 2, q_in2, q_out2),
        )
        p2.start()

        try:
            status2 = q_out2.get(timeout=5)
            # Must raise RunLockError because original owner holds OS fcntl lock
            assert status2 == "error:RunLockError"
        finally:
            p2.join(timeout=2)

        q_in1.put("release")
    finally:
        p1.join(timeout=2)


def test_standalone_interleave_blocked_on_managed_root(tmp_path: Path) -> None:
    root = tmp_path / "terminal"
    run_id = "00000000-0000-4000-8000-000000000002"
    store = TerminalRunStore(root, run_id)

    # Initial unmanaged root allows standalone access
    assert not store.is_managed_root()
    assert store.check_standalone_access_allowed()

    # Once Temporal acquires the managed run lock, it marks the root as managed
    with store.acquire_run_lock("v3-terminal-subject-wf", owner_epoch=1) as marker:
        assert store.is_managed_root()
        assert not store.check_standalone_access_allowed()

    # Even after lock release, marker file remains, keeping root managed
    assert store.is_managed_root()
    assert not store.check_standalone_access_allowed()


def test_cancellable_process_group_reaping(tmp_path: Path) -> None:
    log_file = tmp_path / "step.log"
    # Long-running command simulating build
    cmd = [
        sys.executable,
        "-c",
        "import time, sys; sys.stdout.write('started\\n'); sys.stdout.flush(); time.sleep(30)",
    ]

    cancel_flag = False

    def cancel_check() -> bool:
        return cancel_flag

    def cancel_after_start() -> None:
        time.sleep(0.3)
        nonlocal cancel_flag
        cancel_flag = True

    import threading
    t = threading.Thread(target=cancel_after_start)
    t.start()

    exit_code, timed_out, duration_ms, cancelled = _run_step(
        cmd=cmd,
        cwd=tmp_path,
        log_file=log_file,
        env=os.environ.copy(),
        timeout=10.0,
        cancel_check=cancel_check,
    )
    t.join()

    assert cancelled is True
    assert timed_out is False
    assert exit_code == -1
    log_content = log_file.read_text(encoding="utf-8")
    assert "[CANCELLED]" in log_content
