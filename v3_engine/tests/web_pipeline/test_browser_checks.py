"""Actual browser/check report tests for Web Pipeline (Spec 019 / T042 / A16)."""

from __future__ import annotations

import asyncio
import base64
import hashlib
import json
import os
import tempfile
from typing import Any
import pytest

from v3_engine.qualification.browser_checks import run_browser_checks


def _make_static_package(
    tmpdir: str,
    *,
    html: str | None = None,
    css: str | None = None,
    js: str | None = None,
    manifest_files: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    if html is None:
        html = """<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Interactive Harmonic Oscillator</title>
<link rel="stylesheet" href="styles.css">
</head>
<body>
<h1>Harmonic Oscillator Simulation</h1>
<button id="toggle" aria-label="Toggle Simulation">Start</button>
<canvas id="stage" width="300" height="200"></canvas>
<script src="script.js"></script>
</body>
</html>"""

    if css is None:
        css = """body { font-family: sans-serif; margin: 16px; }
canvas { border: 1px solid #ccc; display: block; margin-top: 8px; }"""

    if js is None:
        js = """let running = false;
let count = 0;
const btn = document.getElementById('toggle');
btn.addEventListener('click', function() {
    count++;
    this.innerText = 'Step ' + count;
});"""

    files_written = {"index.html": html, "styles.css": css, "script.js": js}
    for name, content in files_written.items():
        with open(os.path.join(tmpdir, name), "w", encoding="utf-8") as f:
            f.write(content)

    if manifest_files is None:
        manifest_files = [
            {"path": name, "sha256": hashlib.sha256(content.encode("utf-8")).hexdigest(), "bytes": len(content.encode("utf-8"))}
            for name, content in files_written.items()
        ]

    manifest = {
        "schema_version": 2,
        "profile": "static_three_file_v1",
        "entrypoint": "index.html",
        "files": manifest_files,
    }
    return manifest


@pytest.mark.asyncio
async def test_real_browser_checks_passing_scene() -> None:
    with tempfile.TemporaryDirectory() as tmpdir:
        manifest = _make_static_package(tmpdir)
        result = await run_browser_checks(tmpdir, "cand-pass-1", manifest)

        assert result["schema_version"] == 1
        assert result["candidate_ref"] == "cand-pass-1"
        assert result["checker_version"] == "chromium-pw-1.63.0-v1"

        checks = {c["code"]: c["status"] for c in result["checks"]}
        assert checks == {
            "integrity": "pass",
            "entrypoint": "pass",
            "assets": "pass",
            "runtime": "pass",
            "network": "pass",
            "interaction": "pass",
            "accessibility": "pass",
        }

        # Verify real screenshot was captured
        screenshot_b64 = result["screenshot_png_base64"]
        assert len(screenshot_b64) > 0
        screenshot_bytes = base64.b64decode(screenshot_b64)
        assert screenshot_bytes.startswith(b"\x89PNG\r\n\x1a\n")
        assert hashlib.sha256(screenshot_bytes).hexdigest() == result["screenshot_sha256"]

        # Verify timestamps
        assert result["started_at"] is not None
        assert result["finished_at"] is not None
        assert result["finished_at"] >= result["started_at"]


@pytest.mark.asyncio
async def test_browser_checks_integrity_failure_blocks_remaining() -> None:
    with tempfile.TemporaryDirectory() as tmpdir:
        manifest = _make_static_package(tmpdir)
        # Tamper with script.js file to cause integrity failure
        with open(os.path.join(tmpdir, "script.js"), "w", encoding="utf-8") as f:
            f.write("console.log('tampered content');")

        result = await run_browser_checks(tmpdir, "cand-tampered", manifest)
        checks = {c["code"]: c for c in result["checks"]}

        assert checks["integrity"]["status"] == "fail"
        assert "integrity_mismatch" in checks["integrity"]["detail_codes"]

        # Subsequent checks must be blocked and marked not_run_due_to_prior_failure
        for code in ["entrypoint", "assets", "runtime", "network", "interaction", "accessibility"]:
            assert checks[code]["status"] == "fail"
            assert "not_run_due_to_prior_failure" in checks[code]["detail_codes"]


@pytest.mark.asyncio
async def test_browser_checks_runtime_error_fails() -> None:
    with tempfile.TemporaryDirectory() as tmpdir:
        broken_js = """document.getElementById('toggle').addEventListener('click', function() {
    throw new Error('Simulation crashed intentionally');
});
console.error('Fatal initialization error');"""
        manifest = _make_static_package(tmpdir, js=broken_js)
        result = await run_browser_checks(tmpdir, "cand-runtime-err", manifest)
        checks = {c["code"]: c for c in result["checks"]}

        assert checks["runtime"]["status"] == "fail"
        assert "console_error" in checks["runtime"]["detail_codes"]
        assert result["observations"]["console_error_count"] > 0


@pytest.mark.asyncio
async def test_browser_checks_network_violation_fails() -> None:
    with tempfile.TemporaryDirectory() as tmpdir:
        leaky_js = """fetch('https://external-leak-site.example.com/api/track');
let count = 0;
document.getElementById('toggle').addEventListener('click', function() {
    count++;
    this.innerText = 'Step ' + count;
});"""
        manifest = _make_static_package(tmpdir, js=leaky_js)
        result = await run_browser_checks(tmpdir, "cand-network-leak", manifest)
        checks = {c["code"]: c for c in result["checks"]}

        assert checks["network"]["status"] == "fail"
        assert "forbidden_request" in checks["network"]["detail_codes"]


@pytest.mark.asyncio
async def test_browser_checks_accessibility_missing_lang_and_title_fails() -> None:
    with tempfile.TemporaryDirectory() as tmpdir:
        inaccessible_html = """<!DOCTYPE html>
<html>
<head>
<link rel="stylesheet" href="styles.css">
</head>
<body>
<h1>Harmonic Oscillator</h1>
<button id="toggle">No Label</button>
<script src="script.js"></script>
</body>
</html>"""
        manifest = _make_static_package(tmpdir, html=inaccessible_html)
        result = await run_browser_checks(tmpdir, "cand-inaccessible", manifest)
        checks = {c["code"]: c for c in result["checks"]}

        assert checks["accessibility"]["status"] == "fail"
        assert "document_lang_missing" in checks["accessibility"]["detail_codes"]
        assert "document_title_missing" in checks["accessibility"]["detail_codes"]


@pytest.mark.asyncio
async def test_browser_checks_interaction_absent_fails() -> None:
    with tempfile.TemporaryDirectory() as tmpdir:
        static_html = """<!DOCTYPE html>
<html lang="en">
<head>
<title>Non-interactive Page</title>
<link rel="stylesheet" href="styles.css">
</head>
<body>
<h1>Just text without controls</h1>
<script src="script.js"></script>
</body>
</html>"""
        manifest = _make_static_package(tmpdir, html=static_html)
        result = await run_browser_checks(tmpdir, "cand-no-interaction", manifest)
        checks = {c["code"]: c for c in result["checks"]}

        assert checks["interaction"]["status"] == "fail"
        assert "interaction_absent" in checks["interaction"]["detail_codes"]
