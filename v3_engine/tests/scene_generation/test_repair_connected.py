"""Connected Spec 017 recovery regression using real CLI/provider/store/build boundaries."""
from __future__ import annotations

import json
import asyncio
from pathlib import Path

import httpx

from v3_engine.scene_generation.cli import main


def test_connected_fake_provider_build_and_healthy_resume(monkeypatch, tmp_path: Path, fixtures_dir: Path) -> None:
    monkeypatch.setenv("GEMINI_API_KEY", "test-only-key")
    input_path = fixtures_dir / "sample_multi_pdf_parsed.json"
    output = tmp_path / "connected-run"
    sends: list[dict] = []
    source_json = json.dumps({
        "schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}],
    })

    async def handler(request: httpx.Request) -> httpx.Response:
        sends.append(json.loads(request.content))
        response = {
            "responseId": f"connected-{len(sends)}",
            "usageMetadata": {"promptTokenCount": 9, "candidatesTokenCount": 13},
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": source_json}]}}],
        }
        if len(sends) == 1:
            response["modelVersion"] = "gemini-2.5-flash"
        return httpx.Response(200, json=response)

    first_client = httpx.AsyncClient(transport=httpx.MockTransport(handler))
    first = main(["run", "--input", str(input_path), "--output-dir", str(output), "--provider", "gemini", "--model", "gemini-2.5-flash"], client=first_client)
    assert first == 0
    assert len(sends) == 2

    run_doc = json.loads((output / "run.json").read_text())
    checkpoint = json.loads((output / "checkpoint.json").read_text())
    assert checkpoint["counts"]["generated"] == 2
    for index, item in enumerate(checkpoint["items"]):
        current = output / "lesson-0002" / f"scene-{item['scene_key'].split(':')[-1][1:]:0>4}" / "current.json"
        pointer = json.loads(current.read_text())
        manifest = json.loads((current.parent / pointer["manifest_path"]).read_text())
        assert manifest["source_manifest_sha256"]
        assert manifest["provider_response_id"] in {"connected-1", "connected-2"}
        assert manifest["usage"] == {"input_tokens": 9, "output_tokens": 13}
        assert manifest["scene_key"] == item["scene_key"]
        if index == 0:
            assert manifest["returned_model"] == "gemini-2.5-flash"
            assert manifest["model_reported"] is True
        else:
            assert manifest["returned_model"] is None
            assert manifest["model_reported"] is False
    assert run_doc["toolchain"]["node_version"] != "unknown"
    asyncio.run(first_client.aclose())

    second_client = httpx.AsyncClient(transport=httpx.MockTransport(handler))
    second = main(["run", "--input", str(input_path), "--output-dir", str(output), "--provider", "gemini", "--model", "gemini-2.5-flash"], client=second_client)
    assert second == 0
    assert len(sends) == 2
    asyncio.run(second_client.aclose())
