"""Browser smoke test executing real local build, HTTP serving, and Playwright verification (Spec 017)."""

from __future__ import annotations

import functools
import http.server
import json
import hashlib
from pathlib import Path
import threading
from typing import Any, Callable, Generator
import pytest

from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.contracts import SceneInput
from v3_engine.scene_generation.source_package import GeneratedFile, ReactSceneSourceV1


@pytest.fixture
def http_dist_server() -> Generator[tuple[threading.Thread, http.server.HTTPServer, list[str]], None, None]:
    """Helper to serve a directory over local HTTP and clean up cleanly."""
    server_holder: list[http.server.HTTPServer] = []

    def start_serving(dist_path: Path) -> int:
        handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=str(dist_path))
        httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
        server_holder.append(httpd)
        thread = threading.Thread(target=httpd.serve_forever, daemon=True)
        thread.start()
        return httpd.server_address[1]

    yield start_serving  # type: ignore

    for s in server_holder:
        s.shutdown()
        s.server_close()


@pytest.mark.browser_ui
def test_browser_smoke_fixture_interaction(
    tmp_path: Path,
    http_dist_server: Any,
) -> None:
    """Build a real interactive scene, serve over local HTTP, and verify in headless Playwright."""
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        pytest.fail("Browser gate open: playwright is not installed")

    # 1. Build an interactive component

    dummy_scene = SceneInput(
        scene_key="smoke-nb:smoke-conv:W1:S1",
        notebook_id="smoke-nb",
        conversation_id="smoke-conv",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Smoke Lesson",
        section_title="Interactive Smoke Scene",
        scene_type=None,
        prompt="smoke prompt",
        prompt_sha256="smoke-sha",
        input_error=None,
        source_index=0,
        source_json_pointer="/scenes/0",
    )

    component_source = """import React, { useState } from 'react';

export default function Scene() {
  const [count, setCount] = useState(0);
  return (
    <div style={{ padding: 20 }}>
      <h1 id="title">Interactive Learning Scene</h1>
      <p id="counter">Current Count: {count}</p>
      <button id="btn-count" onClick={() => setCount(c => c + 1)}>
        Click to Increment
      </button>
    </div>
  );
}
"""

    source_pkg = ReactSceneSourceV1(
        schema_version=1,
        dependencies=[],
        files=[
            GeneratedFile(
                path="src/Scene.tsx",
                content=component_source,
                bytes=len(component_source.encode("utf-8")),
                    sha256=hashlib.sha256(component_source.encode("utf-8")).hexdigest(),
            )
        ],
    )

    from v3_engine.scene_generation.artifact_store import SceneArtifactStore
    from v3_engine.scene_generation.config import load_provider_config
    from v3_engine.scene_generation.contracts import HandoffInventory, InputSnapshot

    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    try:
        snap = InputSnapshot(0, "in.json", "in.json", hashlib.sha256(b"{}").hexdigest(), 2, "snapshot/inputs/0001.json")
        inv = HandoffInventory("smoke-nb", "smoke-conv", [dummy_scene], [], [snap], 1)
        cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
        store.initialize_run(inv, cfg, "tmpl", "8845", [b"{}"])

        attempt = store.start_attempt(dummy_scene)
        builder = SceneBuilder()
        scaffold_files = builder.get_scaffold_files(source_pkg.dependencies)
        store.save_source_package(dummy_scene, attempt.ordinal, source_pkg, scaffold_files)
        response_text = json.dumps({"schema_version": 1, "dependencies": [], "files": [{"path": "src/Scene.tsx", "content": component_source}]})
        raw_body = json.dumps({"modelVersion": cfg.requested_model, "responseId": "browser-smoke", "usageMetadata": {"promptTokenCount": 1, "candidatesTokenCount": 1}, "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": response_text}]}}]}).encode()
        receipt_ref = store.save_raw_receipt(dummy_scene, attempt.ordinal, raw_body, 200, True, False)
        store.save_response_text(dummy_scene, attempt.ordinal, response_text)
        store.save_provider_result(dummy_scene, attempt.ordinal, {"outcome": "complete", "safe_code": "ok", "http_status": 200, "requested_model": cfg.requested_model, "returned_model": cfg.requested_model, "model_reported": True, "response_id": "browser-smoke", "finish_reason": "STOP", "usage": {"input_tokens": 1, "output_tokens": 1}, "raw_receipt_ref": receipt_ref, "duration_ms": 1})

        build_result = builder.build_scene(
            attempt_dir=attempt.attempt_dir,
            scene=dummy_scene,
            source_pkg=source_pkg,
            build_ordinal=1,
        )

        assert build_result["outcome"] == "complete", f"Build failed: {build_result}"
        dist_dir = attempt.attempt_dir / "builds" / "build-0001" / "dist"
        assert (dist_dir / "index.html").exists()
    finally:
        store.close()

    # 2. Serve dist directory over local HTTP
    port = http_dist_server(dist_dir)
    target_url = f"http://127.0.0.1:{port}/index.html"

    # 3. Launch headless Playwright
    page_errors: list[str] = []
    try:
        with sync_playwright() as p:
            try:
                browser = p.chromium.launch(headless=True)
            except Exception as exc:
                pytest.fail(f"Browser gate open: Playwright Chromium launch failed: {exc}")

            try:
                page = browser.new_page()
                page.on("pageerror", lambda err: page_errors.append(str(err)))

                page.goto(target_url, timeout=15000)

                # Verify rendered title and initial counter
                page.wait_for_selector("#title", timeout=10000)
                counter_el = page.wait_for_selector("#counter", timeout=10000)
                assert counter_el is not None
                assert counter_el.inner_text() == "Current Count: 0"

                # Click button and verify state update
                btn = page.wait_for_selector("#btn-count")
                assert btn is not None
                btn.click()

                page.wait_for_function("document.getElementById('counter').innerText === 'Current Count: 1'", timeout=5000)
                assert counter_el.inner_text() == "Current Count: 1"

                # Ensure zero unhandled page errors occurred
                assert len(page_errors) == 0, f"Page errors encountered: {page_errors}"
            finally:
                browser.close()
    except Exception as exc:
        if "Browser gate open" in str(exc):
            raise
        pytest.fail(f"Browser interaction failed: {exc}")
