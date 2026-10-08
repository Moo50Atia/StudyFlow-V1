import io
import os
import threading
import time

from v3_engine.review.preview_gateway import PreviewGatewaySession


class FakeProcess:
    def __init__(self, stdout):
        self.stdin = io.StringIO()
        self.stdout = stdout
        self.terminated = 0
        self.killed = 0

    def poll(self):
        return None

    def terminate(self):
        self.terminated += 1

    def wait(self, timeout=None):
        return 0

    def kill(self):
        self.killed += 1


def process_with_stdout(line: bytes):
    read_fd, write_fd = os.pipe()
    os.write(write_fd, line)
    os.close(write_fd)
    return FakeProcess(os.fdopen(read_fd, "r", encoding="utf-8"))


def process_with_partial_stdout():
    read_fd, write_fd = os.pipe()
    os.write(write_fd, b'{"success":')
    return FakeProcess(os.fdopen(read_fd, "r", encoding="utf-8")), write_fd


def test_malformed_init_response_cleans_child_without_recursive_lock(monkeypatch):
    session = PreviewGatewaySession("viewer", "candidate", "a" * 64, [], use_docker=False)
    proc = process_with_stdout(b"not-json\n")
    monkeypatch.setattr("v3_engine.review.preview_gateway.subprocess.Popen", lambda *a, **k: proc)

    result = session.start()

    assert result == {"success": False, "error": "review_unavailable"}
    assert session.closed is True
    assert session.proc is None
    assert proc.terminated == 1


def test_init_eof_cleans_child_and_close_is_idempotent(monkeypatch):
    session = PreviewGatewaySession("viewer", "candidate", "a" * 64, [], use_docker=False)
    proc = process_with_stdout(b"")
    monkeypatch.setattr("v3_engine.review.preview_gateway.subprocess.Popen", lambda *a, **k: proc)

    result = session.start()
    session.close()

    assert result["error"] == "review_unavailable"
    assert proc.terminated == 1


def test_startup_read_has_a_finite_timeout(monkeypatch):
    session = PreviewGatewaySession("viewer", "candidate", "a" * 64, [], use_docker=False)
    proc = process_with_stdout(b"")
    monkeypatch.setattr("v3_engine.review.preview_gateway.subprocess.Popen", lambda *a, **k: proc)
    monkeypatch.setattr("v3_engine.review.preview_gateway.select.select", lambda *args: ([], [], []))
    started = time.monotonic()
    result = session.start()
    assert time.monotonic() - started < 1
    assert result["error"] == "review_unavailable"
    assert proc.terminated == 1


def test_partial_json_line_times_out_instead_of_blocking_readline(monkeypatch):
    session = PreviewGatewaySession("viewer", "candidate", "a" * 64, [], use_docker=False)
    proc, write_fd = process_with_partial_stdout()
    monkeypatch.setattr("v3_engine.review.preview_gateway.subprocess.Popen", lambda *a, **k: proc)
    monkeypatch.setattr("v3_engine.review.preview_gateway.PREVIEW_START_TIMEOUT_SECONDS", 0.05)
    started = time.monotonic()
    try:
        result = session.start()
        assert time.monotonic() - started < 1
        assert result["error"] == "review_unavailable"
        assert proc.terminated == 1
    finally:
        os.close(write_fd)


def test_concurrent_close_and_failed_start_finish_once(monkeypatch):
    session = PreviewGatewaySession("viewer", "candidate", "a" * 64, [], use_docker=False)
    proc = process_with_stdout(b"bad\n")
    monkeypatch.setattr("v3_engine.review.preview_gateway.subprocess.Popen", lambda *a, **k: proc)
    results = []
    starter = threading.Thread(target=lambda: results.append(session.start()))
    closer = threading.Thread(target=session.close)
    starter.start()
    closer.start()
    starter.join(timeout=2)
    closer.join(timeout=2)
    assert not starter.is_alive() and not closer.is_alive()
    assert proc.terminated == 1
