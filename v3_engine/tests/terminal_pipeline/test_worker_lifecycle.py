import asyncio
import pytest
from v3_engine.terminal_pipeline import worker


@pytest.mark.asyncio
async def test_failed_poller_exits_and_releases_process_lock(tmp_path, monkeypatch):
    stopped = set()

    class Poller:
        def __init__(self, client, *, task_queue, **kwargs):
            self.queue = task_queue

        async def run(self):
            try:
                if self.queue == 'v3-terminal-io':
                    raise RuntimeError('poller failed')
                await asyncio.Event().wait()
            finally:
                stopped.add(self.queue)

    monkeypatch.setattr(worker, 'Worker', Poller)
    path = tmp_path / '.worker.lock'
    with pytest.raises(RuntimeError, match='poller failed'):
        await asyncio.wait_for(worker.run_workers(object(), lock_path=path, stop_event=asyncio.Event()), 5)
    assert stopped == {'v3-terminal-control', 'v3-terminal-io', 'v3-terminal-build'}
    lock = worker.TerminalWorkerProcessLock(path)
    lock.acquire()
    lock.release()
