"""Tests for Spec 017 resumption, retry, and drift rejection."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any
import httpx
import pytest

from v3_engine.scene_generation.cli import main
from v3_engine.scene_generation.artifact_store import SceneArtifactStore


def test_completed_artifact_skip_zero_provider_calls(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"  # 2 scenes
    output_dir = tmp_path / "resume_skip_run"

    call_count = 0

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        nonlocal call_count
        call_count += 1
        body = json.dumps({
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
        })
        return httpx.Response(200, text=body)

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))

    # Run 1: limit to 1 scene
    exit_code_1 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--limit", "1",
        ],
        client=client,
    )
    assert exit_code_1 == 4
    assert call_count == 1
    capsys.readouterr()

    # Run 2: resume with limit 2 (the remaining 1 scene will be generated, first will be skipped)
    exit_code_2 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
        ],
        client=client,
    )
    assert exit_code_2 == 0
    # Exactly one additional call was made for the second scene; first was skipped!
    assert call_count == 2
    captured_2 = capsys.readouterr()
    assert "scene_skipped" in captured_2.out

    # Run 3: everything generated, 0 calls should be made
    exit_code_3 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
        ],
        client=client,
    )
    assert exit_code_3 == 0
    assert call_count == 2  # No new calls!


def test_input_drift_refuses_run(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file_1 = tmp_path / "input1.json"
    input_file_2 = tmp_path / "input2.json"

    original_data = json.loads((fixtures_dir / "sample_multi_pdf_parsed.json").read_text(encoding="utf-8"))
    input_file_1.write_text(json.dumps(original_data), encoding="utf-8")

    modified_data = json.loads((fixtures_dir / "sample_multi_pdf_parsed.json").read_text(encoding="utf-8"))
    modified_data["scenes"][0]["generated_prompt"] = "Changed prompt text entirely."
    input_file_2.write_text(json.dumps(modified_data), encoding="utf-8")

    output_dir = tmp_path / "drift_run"

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": json.dumps({"schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}]})}]}}]
        })

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))

    # Run 1
    code_1 = main(
        [
            "run",
            "--input", str(input_file_1),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--limit", "1",
        ],
        client=client,
    )
    assert code_1 in (0, 4)

    # Run 2 with modified input targeting the same output dir
    code_2 = main(
        [
            "run",
            "--input", str(input_file_2),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
        ],
        client=client,
    )
    assert code_2 == 2


def test_provider_model_drift_refuses_run(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "model_drift_run"

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": json.dumps({"schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}]})}]}}]
        })

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))

    # Run 1 with flash
    code_1 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--limit", "1",
        ],
        client=client,
    )
    assert code_1 in (0, 4)

    # Run 2 with pro model
    code_2 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-pro",
        ],
        client=client,
    )
    assert code_2 == 2


def test_toolchain_drift_refuses_build(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "toolchain_drift_run"

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": json.dumps({"schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}]})}]}}]
        })

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    code_1 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--limit", "1",
        ],
        client=client,
    )
    assert code_1 in (0, 4)

    # Mutate run.json toolchain identity
    run_file = output_dir / "run.json"
    run_data = json.loads(run_file.read_text(encoding="utf-8"))
    run_data["toolchain"]["node_version"] = "v99.99.99"
    run_file.write_text(json.dumps(run_data), encoding="utf-8")

    # Attempt build
    code_build = main(["build", "--output-dir", str(output_dir)])
    assert code_build == 2


def test_build_skips_healthy_dist(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "build_skip_healthy"

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": json.dumps({"schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}]})}]}}]
        })

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    code_1 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--limit", "1",
        ],
        client=client,
    )
    assert code_1 in (0, 4)
    capsys.readouterr()

    # Run build: already healthy
    code_build = main(["build", "--output-dir", str(output_dir)])
    assert code_build == 0
    captured = capsys.readouterr()
    assert "scene_build_skipped" in captured.out


def test_build_recovers_corrupted_dist(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "build_recovers_corrupted"

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": json.dumps({"schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}]})}]}}]
        })

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    code_1 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--limit", "1",
        ],
        client=client,
    )
    assert code_1 in (0, 4)

    # Find the dist/index.html and corrupt it
    dist_files = list(output_dir.glob("**/dist/index.html"))
    assert len(dist_files) >= 1
    dist_index = dist_files[0]
    dist_index.write_text("CORRUPTED BYTES", encoding="utf-8")

    capsys.readouterr()
    code_build = main(["build", "--output-dir", str(output_dir)])
    assert code_build == 0
    captured = capsys.readouterr()
    assert "scene_built" in captured.out
    assert "build 2" in captured.out or "build 0002" in captured.out or "build 2" in captured.out.lower()


def test_retry_scene_rejected_if_already_generated(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "retry_gen_run"

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": json.dumps({"schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}]})}]}}]
        })

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    code_1 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--limit", "1",
        ],
        client=client,
    )
    assert code_1 in (0, 4)

    # The first scene is generated. Attempting to retry it must fail with exit 2
    store = SceneArtifactStore(output_dir)
    ckpt = store.load_checkpoint()
    generated_key = ckpt["items"][0]["scene_key"]

    code_retry = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--retry-scene", generated_key,
        ],
        client=client,
    )
    assert code_retry == 2


def test_retry_scene_rejected_if_unknown_key(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "retry_unknown_run"

    code = main([
        "run",
        "--input", str(input_file),
        "--output-dir", str(output_dir),
        "--provider", "gemini",
        "--model", "gemini-2.5-flash",
        "--retry-scene", "unknown_nb:unknown_conv:L0001:S0001",
    ])
    assert code == 2


def test_explicit_retry_uncertain_or_failed_scene(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "retry_uncertain_run"

    should_fail = True

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        nonlocal should_fail
        if should_fail:
            return httpx.Response(500, json={"error": "Internal Server Error"})
        return httpx.Response(200, json={
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": json.dumps({"schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}]})}]}}]
        })

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))

    # Run 1: will fail
    code_1 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--limit", "1",
        ],
        client=client,
    )
    assert code_1 == 1

    store = SceneArtifactStore(output_dir)
    ckpt = store.load_checkpoint()
    failed_key = ckpt["items"][0]["scene_key"]
    assert ckpt["items"][0]["status"] in ("failed", "uncertain")

    # Run 2: resume without retry-scene -> scene remains failed, not resent
    should_fail = False
    code_2 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--limit", "1",
        ],
        client=client,
    )
    # The first item wasn't retried, so 0 sends made, status still failed
    assert code_2 == 1

    # Run 3: explicit retry with --retry-scene
    capsys.readouterr()
    code_3 = main(
        [
            "run",
            "--input", str(input_file),
            "--output-dir", str(output_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
            "--retry-scene", failed_key,
            "--limit", "1",
        ],
        client=client,
    )
    assert code_3 in (0, 4)
    captured = capsys.readouterr()
    assert f"retry_may_duplicate_provider_work {failed_key}" in captured.out
    assert f"scene_sent {failed_key} attempt 2" in captured.out

    # Verify checkpoint now has 2 attempts
    store_after = SceneArtifactStore(output_dir)
    ckpt_after = store_after.load_checkpoint()
    assert len(ckpt_after["items"][0]["attempts"]) == 2
