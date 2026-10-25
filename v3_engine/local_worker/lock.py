"""Exclusive process locking for local worker workspace."""

from __future__ import annotations

import fcntl
import os
from pathlib import Path
from typing import Optional


class WorkerLockError(RuntimeError):
    """Raised when workspace lock cannot be acquired."""
    pass


class ExclusiveWorkspaceLock:
    def __init__(self, workspace_dir: Path):
        self.workspace_dir = Path(workspace_dir).expanduser().resolve()
        self.lock_file_path = self.workspace_dir / "worker.lock"
        self._fd: Optional[int] = None

    def acquire(self) -> None:
        self.workspace_dir.mkdir(parents=True, exist_ok=True)
        try:
            self._fd = os.open(str(self.lock_file_path), os.O_CREAT | os.O_RDWR, 0o600)
            fcntl.flock(self._fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            os.write(self._fd, f"{os.getpid()}\n".encode("utf-8"))
        except (BlockingIOError, OSError) as e:
            if self._fd is not None:
                try:
                    os.close(self._fd)
                except OSError:
                    pass
                self._fd = None
            raise WorkerLockError(
                f"Cannot acquire exclusive workspace lock on {self.lock_file_path}. Another worker daemon is already running."
            ) from e

    def release(self) -> None:
        if self._fd is not None:
            try:
                fcntl.flock(self._fd, fcntl.LOCK_UN)
                os.close(self._fd)
            except OSError:
                pass
            finally:
                self._fd = None

    def __enter__(self) -> ExclusiveWorkspaceLock:
        self.acquire()
        return self

    def __exit__(self, exc_type, exc_val, exc_tb) -> None:
        self.release()
