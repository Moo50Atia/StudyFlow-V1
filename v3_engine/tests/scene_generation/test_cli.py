"""Tests for terminal scene generation CLI check subcommand (Spec 017)."""

from __future__ import annotations

import json
from typing import Any
import httpx
import pytest

from v3_engine.scene_generation.cli import main
from v3_engine.scene_generation.handoff import load_handoff_inventory


def test_cli_check_success(monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-secret-key-12345")

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": "V3_PROVIDER_OK"}]}}]
        })

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    exit_code = main(["check", "--provider", "gemini", "--model", "gemini-2.5-flash"], client=client)

    assert exit_code == 0
    captured = capsys.readouterr()
    assert "provider_check passed gemini_api gemini-2.5-flash ok" in captured.out
    # Secret key must NEVER appear in stdout or stderr
    assert "test-secret-key-12345" not in captured.out
    assert "test-secret-key-12345" not in captured.err


def test_cli_check_failed(monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]) -> None:
    monkeypatch.setenv("DEEPSEEK_API_KEY", "ds-secret")

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(401, json={"error": "Invalid API key"})

    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    exit_code = main(["check", "--provider", "deepseek", "--model", "deepseek-chat"], client=client)

    assert exit_code == 1
    captured = capsys.readouterr()
    assert "provider_check failed deepseek_api deepseek-chat provider_auth" in captured.out
    assert "ds-secret" not in captured.out
    assert "ds-secret" not in captured.err


def test_cli_check_configuration_error_missing_model(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "gemini-key")
    monkeypatch.delenv("GEMINI_MODEL", raising=False)

    exit_code = main(["check", "--provider", "gemini"])
    assert exit_code == 2
    captured = capsys.readouterr()
    assert "gemini-key" not in captured.out
    assert "gemini-key" not in captured.err


def test_cli_check_unknown_provider(capsys: pytest.CaptureFixture[str]) -> None:
    exit_code = main(["check", "--provider", "nonexistent_vendor"])
    assert exit_code == 2


def test_cli_run_input_output_overlap(tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    input_dir = tmp_path / "inputs"
    input_dir.mkdir()
    output_dir = input_dir / "outputs"  # Inside input directory!

    exit_code = main([
        "run",
        "--input", str(input_dir),
        "--output-dir", str(output_dir),
        "--provider", "gemini",
        "--model", "gemini-2.5-flash",
    ])
    assert exit_code == 2
    captured = capsys.readouterr()
    assert "overlap" in captured.err.lower() or "overlap" in captured.out.lower()


@pytest.mark.parametrize("limit", ["0", "-1"])
def test_cli_rejects_nonpositive_limit_before_creating_run(tmp_path: Path, limit: str, capsys: pytest.CaptureFixture[str]) -> None:
    output = tmp_path / "no-run"
    code = main(["run", "--input", "/does/not/matter", "--output-dir", str(output), "--provider", "gemini", "--model", "gemini-2.5-flash", "--limit", limit])
    assert code == 2
    assert not (output / "run.json").exists()


def test_cli_rejects_symlinked_input_root(tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    target = tmp_path / "input.json"
    target.write_text("{}")
    link = tmp_path / "input-link.json"
    link.symlink_to(target)
    code = main(["run", "--input", str(link), "--output-dir", str(tmp_path / "output"), "--provider", "gemini", "--model", "gemini-2.5-flash"])
    assert code == 2
    assert "symbolic_link_root_rejected" in capsys.readouterr().err


def test_cli_rejects_symlinked_output_root(tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    target = tmp_path / "real-output"
    target.mkdir()
    link = tmp_path / "output-link"
    link.symlink_to(target, target_is_directory=True)
    code = main(["run", "--input", "/missing/input", "--output-dir", str(link), "--provider", "gemini", "--model", "gemini-2.5-flash"])
    assert code == 2
    assert "symbolic_link_root_rejected" in capsys.readouterr().err


def test_cli_rejects_input_changed_after_inventory_parse(monkeypatch: pytest.MonkeyPatch, tmp_path: Path, fixtures_dir: Path, capsys: pytest.CaptureFixture[str]) -> None:
    from v3_engine.scene_generation import cli
    monkeypatch.setenv("GEMINI_API_KEY", "key")
    input_file = tmp_path / "handoff.json"
    input_file.write_bytes((fixtures_dir / "sample_multi_pdf_raw.json").read_bytes())
    original = cli.load_handoff_inventory
    def load_then_mutate(path):
        inventory = original(path)
        input_file.write_bytes(input_file.read_bytes() + b" ")
        return inventory
    monkeypatch.setattr(cli, "load_handoff_inventory", load_then_mutate)
    output = tmp_path / "out"
    code = main(["run", "--input", str(input_file), "--output-dir", str(output), "--provider", "gemini", "--model", "gemini-2.5-flash"])
    assert code == 2
    assert "input_changed_during_run_initialization" in capsys.readouterr().err
    assert not (output / "run.json").exists()


def test_cli_missing_build_prerequisites_stops_before_run_publication(monkeypatch: pytest.MonkeyPatch, tmp_path: Path, fixtures_dir: Path, capsys: pytest.CaptureFixture[str]) -> None:
    from v3_engine.scene_generation import builder
    monkeypatch.setenv("GEMINI_API_KEY", "key")
    monkeypatch.setattr(builder, "get_host_toolchain_info", lambda scaffold_dir=None: {"node_version": "unknown", "npm_version": "unknown", "sha256": "unknown", "catalog_sha256": "unknown"})
    output = tmp_path / "no-toolchain"
    code = main(["run", "--input", str(fixtures_dir / "sample_multi_pdf_raw.json"), "--output-dir", str(output), "--provider", "gemini", "--model", "gemini-2.5-flash"])
    assert code == 2
    assert "Node.js 22.12 or newer is required" in capsys.readouterr().err
    assert not (output / "run.json").exists()


def test_selected_build_without_retained_source_is_an_error(tmp_path: Path, fixtures_dir: Path, capsys: pytest.CaptureFixture[str]) -> None:
    from v3_engine.scene_generation.artifact_store import SceneArtifactStore
    from v3_engine.scene_generation.config import load_provider_config, resolve_template
    from v3_engine.scene_generation.handoff import load_handoff_inventory
    source = fixtures_dir / "sample_multi_pdf_raw.json"
    inventory = load_handoff_inventory(source)
    output = tmp_path / "empty-source-run"
    store = SceneArtifactStore(output)
    store.acquire_lock()
    config = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    template, digest = resolve_template()
    store.initialize_run(inventory, config, template, digest, [source.read_bytes()])
    store.close()
    code = main(["build", "--output-dir", str(output), "--scene-key", inventory.scene_inputs[0].scene_key])
    assert code == 1
    assert "source_manifest_missing" in capsys.readouterr().out


def test_build_recovery_restores_captured_lesson_scene_and_prompt_metadata(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    """The build CLI reconstructs the original educational association from run evidence."""
    from v3_engine.scene_generation.builder import SceneBuilder
    from v3_engine.scene_generation.handoff import load_handoff_inventory

    monkeypatch.setenv("GEMINI_API_KEY", "test-only-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    output_dir = tmp_path / "metadata-recovery"
    provider_sends: list[bytes] = []
    built_scenes = []

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        provider_sends.append(request.content)
        response_text = json.dumps({
            "schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}],
        })
        return httpx.Response(200, json={
            "modelVersion": "gemini-2.5-flash",
            "responseId": "metadata-test-response",
            "usageMetadata": {"promptTokenCount": 3, "candidatesTokenCount": 7},
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": response_text}]}}],
        })

    def transient_build_failure(self, *, attempt_dir, scene, source_pkg, build_ordinal=1, cancel_check=None, heartbeat_cb=None):
        built_scenes.append(scene)
        return {"outcome": "failed", "safe_code": "test_transient_build_failure"}

    monkeypatch.setattr(SceneBuilder, "build_scene", transient_build_failure)
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    run_exit = main([
        "run", "--input", str(input_file), "--output-dir", str(output_dir),
        "--provider", "gemini", "--model", "gemini-2.5-flash", "--limit", "1",
    ], client=client)
    capsys.readouterr()
    assert run_exit == 1
    assert len(provider_sends) == 1

    run_doc = json.loads((output_dir / "run.json").read_text(encoding="utf-8"))
    captured = run_doc["scene_inputs"][0]
    source_scene = load_handoff_inventory(input_file).scene_inputs[0]
    captured_prompt = source_scene.prompt
    assert captured_prompt is not None
    built_scenes.clear()
    build_exit = main(["build", "--output-dir", str(output_dir), "--scene-key", captured["scene_key"]])
    capsys.readouterr()

    assert build_exit == 1  # The deliberately injected local tool failure is reported honestly.
    assert len(provider_sends) == 1  # Build recovery is local; it makes no provider request.
    assert len(built_scenes) == 1
    recovered = built_scenes[0]
    assert recovered.scene_key == captured["scene_key"]
    assert recovered.lesson_title == captured["lesson_title"]
    assert recovered.section_title == captured["section_title"]
    assert recovered.scene_type == captured["scene_type"]
    assert recovered.prompt == captured_prompt
    assert recovered.prompt_sha256 == captured["prompt_sha256"]


def test_cli_run_lock_busy_exits_3(tmp_path: Path) -> None:
    from v3_engine.scene_generation.artifact_store import SceneArtifactStore
    input_dir = tmp_path / "inputs"
    input_dir.mkdir()
    out_dir = tmp_path / "locked_output"
    store = SceneArtifactStore(out_dir)
    store.acquire_lock()

    try:
        exit_code = main([
            "run",
            "--input", str(input_dir),
            "--output-dir", str(out_dir),
            "--provider", "gemini",
            "--model", "gemini-2.5-flash",
        ])
        assert exit_code == 3
    finally:
        store.close()


def test_cli_run_limit_exits_4(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_raw.json"  # 4 scenes
    output_dir = tmp_path / "run_limit_output"

    async def mock_handler(request: httpx.Request) -> httpx.Response:
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
    exit_code = main(
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

    assert exit_code == 4
    captured = capsys.readouterr()
    assert "run_summary generated 1 failed 0 uncertain 0 queued 3 input_issues 0 status partial" in captured.out


def test_cli_build_without_credentials(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    fixtures_dir: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    # First, do a limited run to populate saved source package
    monkeypatch.setenv("GEMINI_API_KEY", "test-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"  # 2 scenes
    output_dir = tmp_path / "build_test_run"

    async def mock_handler(request: httpx.Request) -> httpx.Response:
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
    run_code = main(
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
    assert run_code in (0, 4)

    # Now strip all credentials
    monkeypatch.delenv("GEMINI_API_KEY", raising=False)
    monkeypatch.delenv("GLM_API_KEY", raising=False)
    monkeypatch.delenv("DEEPSEEK_API_KEY", raising=False)
    monkeypatch.delenv("QWEN_API_KEY", raising=False)

    # Remove dist to force rebuild from saved source
    import shutil
    for d in output_dir.glob("**/dist"):
        shutil.rmtree(d)

    # Execute build subcommand
    build_code = main(["build", "--output-dir", str(output_dir)])
    assert build_code == 0
    captured = capsys.readouterr()
    assert "build_summary succeeded 1 failed 0" in captured.out


def test_cli_selected_scene_uses_plain_html_css_js_without_toolchain_build(
    monkeypatch: pytest.MonkeyPatch, tmp_path, fixtures_dir, capsys: pytest.CaptureFixture[str]
) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-scene-key")
    input_file = fixtures_dir / "sample_multi_pdf_parsed.json"
    inventory = load_handoff_inventory(input_file)
    selected = inventory.scene_inputs[-1]
    output_dir = tmp_path / "one-scene-retry"
    calls: list[str] = []
    html_bundle = {
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": '<!doctype html><html><head><link rel="stylesheet" href="./styles.css"></head><body><button id="control">Change</button><output id="result">Ready</output><script src="./script.js"></script></body></html>'},
            {"path": "styles.css", "content": "body { font: 1rem sans-serif; }"},
            {"path": "script.js", "content": "document.querySelector('#control').addEventListener('click',()=>document.querySelector('#result').textContent='Changed');"},
        ],
    }

    async def handler(request: httpx.Request) -> httpx.Response:
        payload = json.loads(request.content)
        calls.append(payload["contents"][0]["parts"][0]["text"])
        return httpx.Response(200, json={
            "modelVersion": "gemini-2.5-flash",
            "responseId": "one-scene-static-response",
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": json.dumps(html_bundle)}]}}],
        })

    client = httpx.AsyncClient(transport=httpx.MockTransport(handler))
    exit_code = main([
        "run", "--input", str(input_file), "--output-dir", str(output_dir),
        "--provider", "gemini", "--model", "gemini-2.5-flash", "--scene-key", selected.scene_key,
    ], client=client)
    assert exit_code == 0
    assert len(calls) == 1 and selected.prompt in calls[0]
    run_doc = json.loads((output_dir / "run.json").read_text(encoding="utf-8"))
    assert [row["scene_key"] for row in run_doc["scene_inputs"]] == [selected.scene_key]
    checkpoint = json.loads((output_dir / "checkpoint.json").read_text(encoding="utf-8"))
    assert checkpoint["items"][0]["status"] == "generated"
    manifest_path = output_dir / f"lesson-{selected.lesson_ordinal:04d}/scene-{selected.scene_ordinal:04d}/attempt-0001/builds/build-0001/manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    assert manifest["kind"] == "local_static_html_scene"
    assert manifest["verification"]["static_files_materialized"] is True
    assert manifest["verification"]["typecheck"] is False
    assert {row["path"] for row in manifest["files"]} == {"index.html", "styles.css", "script.js"}
    assert "scene_generated " + selected.scene_key in capsys.readouterr().out
