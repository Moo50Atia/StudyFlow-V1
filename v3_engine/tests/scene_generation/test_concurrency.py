"""Concurrency and mutual exclusion tests using real child processes and HTTP server (Spec 017)."""

from __future__ import annotations

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import multiprocessing
import os
from pathlib import Path
import subprocess
import sys
import threading
import time
import pytest


def _competing_build_reservation(output_dir: str, started: multiprocessing.synchronize.Event, results: multiprocessing.Queue) -> None:
    from v3_engine.scene_generation.artifact_store import LockBusyError, SceneArtifactStore
    from v3_engine.scene_generation.contracts import SceneInput
    store = SceneArtifactStore(Path(output_dir))
    try:
        store.acquire_lock()
        started.set()
        key = store.load_checkpoint()["items"][0]["scene_key"]
        parts = key.split(":")
        lesson_ordinal = int(parts[2][1:])
        scene_ordinal = int(parts[3][1:])
        scene = SceneInput(key, parts[0], parts[1], lesson_ordinal, scene_ordinal, "Lesson", "Section", None, "prompt", "sha", None, 0, "/scenes/0")
        results.put(store.reserve_build(scene, 1))
        time.sleep(0.25)
    except LockBusyError:
        results.put("busy")
    finally:
        store.close()


class MockCountingHandler(BaseHTTPRequestHandler):
    """Counting mock HTTP handler with an optional synchronization barrier."""

    server: "MockServer"

    def do_POST(self) -> None:
        content_len = int(self.headers.get("Content-Length", 0))
        _ = self.rfile.read(content_len)

        with self.server.lock:
            self.server.request_count += 1
            current_count = self.server.request_count

        if current_count == 1 and self.server.barrier_active:
            self.server.first_request_received.set()
            # Wait until test releases process 1
            self.server.release_first_request.wait(timeout=10.0)

        response_body = json.dumps({
            "candidates": [
                {
                    "finishReason": "STOP",
                    "content": {
                        "parts": [
                            {
                                "text": json.dumps({
                                    "schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}],
                                })
                            }
                        ]
                    },
                }
            ]
        }).encode("utf-8")

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(response_body)))
        self.end_headers()
        self.wfile.write(response_body)

    def log_message(self, format: str, *args: object) -> None:
        # Suppress noisy HTTP server logs during pytest runs
        pass


class MockServer(ThreadingHTTPServer):
    def __init__(self, host: str = "127.0.0.1", port: int = 0) -> None:
        super().__init__((host, port), MockCountingHandler)
        self.lock = threading.Lock()
        self.request_count = 0
        self.barrier_active = False
        self.first_request_received = threading.Event()
        self.release_first_request = threading.Event()


@pytest.fixture
def mock_http_server():
    server = MockServer()
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield server
    finally:
        server.shutdown()
        server.server_close()


def test_two_processes_same_output_folder_mutual_exclusion(
    tmp_path: Path,
    fixtures_dir: Path,
    repo_root: Path,
    mock_http_server: MockServer,
) -> None:
    """Demonstrate mutual exclusion: Process 1 holds lock, Process 2 exits 3 with zero network calls."""
    mock_http_server.barrier_active = True
    port = mock_http_server.server_address[1]

    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "shared_concurrent_output"

    env = os.environ.copy()
    env["GEMINI_API_KEY"] = "concurrency-test-key"
    env["GEMINI_BASE_URL"] = f"http://127.0.0.1:{port}"
    env["PYTHONPATH"] = str(repo_root)

    script_path = repo_root / "scripts" / "v3" / "generate_scenes.py"

    # 1. Spawn Process 1
    proc1 = subprocess.Popen(
        [
            sys.executable,
            str(script_path),
            "run",
            "--input",
            str(input_file),
            "--output-dir",
            str(output_dir),
            "--provider",
            "gemini",
            "--model",
            "gemini-2.5-flash",
            "--limit",
            "1",
        ],
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )

    try:
        # Wait until Process 1 has acquired the lock and hit our HTTP barrier
        signaled = mock_http_server.first_request_received.wait(timeout=15.0)
        assert signaled, "Process 1 failed to reach HTTP barrier within timeout"

        # 2. While Process 1 is in-flight holding the lock, spawn Process 2 targeting the EXACT same directory
        proc2_res = subprocess.run(
            [
                sys.executable,
                str(script_path),
                "run",
                "--input",
                str(input_file),
                "--output-dir",
                str(output_dir),
                "--provider",
                "gemini",
                "--model",
                "gemini-2.5-flash",
                "--limit",
                "1",
            ],
            env=env,
            capture_output=True,
            text=True,
            timeout=10.0,
        )

        # Process 2 MUST fail immediately with exit code 3 (lock busy)
        assert proc2_res.returncode == 3, f"Process 2 did not exit 3: stdout={proc2_res.stdout}, stderr={proc2_res.stderr}"
        assert "lock_busy" in proc2_res.stderr or "lock_busy" in proc2_res.stdout

    finally:
        # 3. Release Process 1
        mock_http_server.release_first_request.set()
        stdout1, stderr1 = proc1.communicate(timeout=30.0)

    # Process 1 finishes cleanly with limit exit code 4
    assert proc1.returncode in (0, 4), f"Process 1 failed: stdout={stdout1}, stderr={stderr1}"

    # Only Process 1 made a request; Process 2 made ZERO requests
    assert mock_http_server.request_count == 1


def test_killed_owner_lock_recovery(
    tmp_path: Path,
    fixtures_dir: Path,
    repo_root: Path,
    mock_http_server: MockServer,
) -> None:
    """Demonstrate that when a lock owner is forcefully killed, OS cleans up flock and Process 2 succeeds."""
    mock_http_server.barrier_active = True
    port = mock_http_server.server_address[1]

    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "killed_owner_output"

    env = os.environ.copy()
    env["GEMINI_API_KEY"] = "concurrency-test-key"
    env["GEMINI_BASE_URL"] = f"http://127.0.0.1:{port}"
    env["PYTHONPATH"] = str(repo_root)

    script_path = repo_root / "scripts" / "v3" / "generate_scenes.py"

    # 1. Spawn Process 1
    proc1 = subprocess.Popen(
        [
            sys.executable,
            str(script_path),
            "run",
            "--input",
            str(input_file),
            "--output-dir",
            str(output_dir),
            "--provider",
            "gemini",
            "--model",
            "gemini-2.5-flash",
            "--limit",
            "1",
        ],
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )

    # Wait until Process 1 acquires lock and reaches barrier
    signaled = mock_http_server.first_request_received.wait(timeout=15.0)
    assert signaled, "Process 1 failed to reach barrier"

    # 2. Forcefully kill Process 1 (SIGKILL)
    proc1.kill()
    proc1.wait()

    # Unblock the server
    mock_http_server.release_first_request.set()
    mock_http_server.barrier_active = False

    time.sleep(0.2)

    # 3. Spawn Process 2 targeting the same folder.
    # Because lock file is not locked by any active fd, Process 2 must acquire lock!
    proc2_res = subprocess.run(
        [
            sys.executable,
            str(script_path),
            "run",
            "--input",
            str(input_file),
            "--output-dir",
            str(output_dir),
            "--provider",
            "gemini",
            "--model",
            "gemini-2.5-flash",
            "--limit",
            "1",
        ],
        env=env,
        capture_output=True,
        text=True,
        timeout=30.0,
    )

    # The interrupted first request has no verifiable receipt, so resume must
    # retain an uncertain result rather than resend it. A later queued scene
    # may still complete, but the run correctly exits with partial status.
    assert proc2_res.returncode == 1, f"unexpected recovery result: {proc2_res.stderr} {proc2_res.stdout}"
    checkpoint = json.loads((output_dir / "checkpoint.json").read_text())
    interrupted = next(item for item in checkpoint["items"] if item["scene_key"].endswith(":W2:S1"))
    assert interrupted["status"] == "uncertain"
    assert len(interrupted["attempts"]) == 1
    assert mock_http_server.request_count == 2


def test_separate_folders_run_concurrently(
    tmp_path: Path,
    fixtures_dir: Path,
    repo_root: Path,
    mock_http_server: MockServer,
) -> None:
    """Demonstrate that separate output folders are independent and execute concurrently."""
    mock_http_server.barrier_active = False
    port = mock_http_server.server_address[1]

    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir_a = tmp_path / "folder_a"
    output_dir_b = tmp_path / "folder_b"

    env = os.environ.copy()
    env["GEMINI_API_KEY"] = "concurrency-test-key"
    env["GEMINI_BASE_URL"] = f"http://127.0.0.1:{port}"
    env["PYTHONPATH"] = str(repo_root)

    script_path = repo_root / "scripts" / "v3" / "generate_scenes.py"

    proc_a = subprocess.Popen(
        [
            sys.executable,
            str(script_path),
            "run",
            "--input",
            str(input_file),
            "--output-dir",
            str(output_dir_a),
            "--provider",
            "gemini",
            "--model",
            "gemini-2.5-flash",
            "--limit",
            "1",
        ],
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )

    proc_b = subprocess.Popen(
        [
            sys.executable,
            str(script_path),
            "run",
            "--input",
            str(input_file),
            "--output-dir",
            str(output_dir_b),
            "--provider",
            "gemini",
            "--model",
            "gemini-2.5-flash",
            "--limit",
            "1",
        ],
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )

    out_a, err_a = proc_a.communicate(timeout=30.0)
    out_b, err_b = proc_b.communicate(timeout=30.0)

    assert proc_a.returncode in (0, 4), f"Process A failed: {err_a}"
    assert proc_b.returncode in (0, 4), f"Process B failed: {err_b}"
    assert (output_dir_a / "checkpoint.json").exists()
    assert (output_dir_b / "checkpoint.json").exists()


def test_competing_processes_cannot_reuse_build_reservation(tmp_path: Path, fixtures_dir: Path) -> None:
    from v3_engine.scene_generation.artifact_store import SceneArtifactStore
    from v3_engine.scene_generation.config import load_provider_config
    from v3_engine.scene_generation.handoff import load_handoff_inventory
    input_path = fixtures_dir / "sample_multi_pdf_raw.json"
    inv = load_handoff_inventory(input_path)
    output = tmp_path / "reservation-race"
    store = SceneArtifactStore(output)
    store.acquire_lock()
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    store.initialize_run(inv, cfg, "template", "template-sha", [input_path.read_bytes()])
    store.start_attempt(inv.scene_inputs[0])
    store.close()

    ctx = multiprocessing.get_context("fork")
    started = ctx.Event()
    results = ctx.Queue()
    first = ctx.Process(target=_competing_build_reservation, args=(str(output), started, results))
    second = ctx.Process(target=_competing_build_reservation, args=(str(output), started, results))
    first.start()
    second.start()
    first.join(10)
    second.join(10)
    assert first.exitcode == 0 and second.exitcode == 0
    outcomes = [results.get(timeout=2), results.get(timeout=2)]
    assert outcomes.count(1) == 1
    assert outcomes.count("busy") == 1

    store = SceneArtifactStore(output)
    store.acquire_lock()
    next_ordinal = store.reserve_build(inv.scene_inputs[0], 1)
    store.close()
    assert next_ordinal == 2
