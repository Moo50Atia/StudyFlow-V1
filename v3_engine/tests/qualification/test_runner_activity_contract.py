"""
Tests for R10: Call the browser runner correctly and carry its real screenshot.
Governed by docs/reviews/V3-POC-REPAIR-TASKS-2026-10-04.md (R10) and Spec 012.
"""

import base64
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tarfile
import pytest

from v3_engine.qualification.container_runner import run_container_check
from v3_engine.qualification.activity import (
    qualify_scene_activity,
    build_candidate_tar,
    PackageValidationError,
)

FIXTURES_DIR = Path(__file__).parent / "fixtures"

# Minimal valid 1x1 PNG bytes for testing real PNG handling
MINIMAL_PNG_BYTES = (
    b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01"
    b"\x08\x06\x00\x00\x00\x1f\x15c4\x00\x00\x00\rIDATx\x9cc`\x00\x00"
    b"\x00\x02\x00\x01H\xaf\xa4q\x00\x00\x00\x00IEND\xaeB`\x82"
)


def load_valid_scene_fixture():
    pkg_dir = FIXTURES_DIR / "valid_scene"
    manifest_bytes = (pkg_dir / "manifest.json").read_bytes()
    manifest = json.loads(manifest_bytes.decode("utf-8"))
    manifest_sha = (pkg_dir / "manifest.sha256").read_text().strip()

    files = [
        {"path": "app.js", "content": (pkg_dir / "app.js").read_text(encoding="utf-8")},
        {"path": "index.html", "content": (pkg_dir / "index.html").read_text(encoding="utf-8")},
        {"path": "style.css", "content": (pkg_dir / "style.css").read_text(encoding="utf-8")},
    ]
    return manifest, manifest_bytes, manifest_sha, files


def test_build_candidate_tar_preserves_manifest_identity_and_safe_tar():
    manifest, manifest_bytes, manifest_sha, files = load_valid_scene_fixture()

    tar_bytes, computed_sha = build_candidate_tar(
        files=files,
        manifest=manifest,
        manifest_raw=manifest_bytes,
        expected_manifest_sha256=manifest_sha,
    )

    assert computed_sha == manifest_sha
    assert isinstance(tar_bytes, bytes)
    assert len(tar_bytes) > 0

    # Verify tar contents
    with tarfile.open(fileobj=io.BytesIO(tar_bytes), mode="r:*") as tar:
        names = tar.getnames()
        assert "manifest.json" in names
        assert "index.html" in names
        assert "app.js" in names
        assert "style.css" in names

        # Verify manifest extracted bytes match raw bytes exactly
        extracted_manifest = tar.extractfile("manifest.json").read()
        assert extracted_manifest == manifest_bytes
        assert hashlib.sha256(extracted_manifest).hexdigest() == manifest_sha


def test_build_candidate_tar_rejects_corrupted_artifact_bytes():
    manifest, manifest_bytes, manifest_sha, files = load_valid_scene_fixture()
    # Corrupt app.js content
    corrupted_files = [
        {"path": "app.js", "content": "corrupted content"},
        {"path": "index.html", "content": files[1]["content"]},
        {"path": "style.css", "content": files[2]["content"]},
    ]

    with pytest.raises(PackageValidationError, match="Corrupted artifact bytes|Mismatched digest"):
        build_candidate_tar(
            files=corrupted_files,
            manifest=manifest,
            manifest_raw=manifest_bytes,
            expected_manifest_sha256=manifest_sha,
        )


def test_build_candidate_tar_rejects_mismatched_manifest_digest():
    manifest, manifest_bytes, manifest_sha, files = load_valid_scene_fixture()
    wrong_sha = "0" * 64

    with pytest.raises(PackageValidationError, match="Mismatched manifest digest"):
        build_candidate_tar(
            files=files,
            manifest=manifest,
            manifest_raw=manifest_bytes,
            expected_manifest_sha256=wrong_sha,
        )


def test_build_candidate_tar_rejects_unsafe_tar_entries():
    manifest, manifest_bytes, manifest_sha, files = load_valid_scene_fixture()
    unsafe_files = list(files) + [{"path": "../outside.js", "content": "evil"}]

    with pytest.raises(PackageValidationError, match="Unsafe tar entry path"):
        build_candidate_tar(
            files=unsafe_files,
            manifest=manifest,
            manifest_raw=manifest_bytes,
        )


def test_runner_handles_timeout(monkeypatch):
    def mock_run(*args, **kwargs):
        raise subprocess.TimeoutExpired(cmd=["docker"], timeout=60)

    monkeypatch.setattr(subprocess, "run", mock_run)

    result = run_container_check(b"fake-tar", "cand-1", "sha-1")
    assert result["success"] is False
    assert result["error"] == "checker_unavailable"
    assert result["report"] is None
    assert result["screenshot_png"] is None


def test_runner_handles_nonzero_exit_without_json(monkeypatch):
    class MockCompletedProcess:
        returncode = 125
        stdout = b"docker: daemon error: out of memory"
        stderr = b""

    monkeypatch.setattr(subprocess, "run", lambda *a, **kw: MockCompletedProcess())

    result = run_container_check(b"fake-tar", "cand-1", "sha-1")
    assert result["success"] is False
    assert result["error"] == "checker_unavailable"
    assert result["report"] is None
    assert result["screenshot_png"] is None


def test_runner_handles_bad_result_shape(monkeypatch):
    class MockCompletedProcess:
        returncode = 0
        stdout = b'["not", "a", "dict"]'
        stderr = b""

    monkeypatch.setattr(subprocess, "run", lambda *a, **kw: MockCompletedProcess())

    result = run_container_check(b"fake-tar", "cand-1", "sha-1")
    assert result["success"] is False
    assert result["error"] == "checker_unavailable"
    assert result["report"] is None
    assert result["screenshot_png"] is None


@pytest.mark.asyncio
async def test_activity_calls_runner_with_exact_tar_bytes_and_preserves_real_screenshot(monkeypatch):
    manifest, manifest_bytes, manifest_sha, files = load_valid_scene_fixture()
    case_id = "00000000-0000-0000-0000-000000000010"
    candidate_ref = "00000000-0000-0000-0000-000000000020"

    real_png_b64 = base64.b64encode(MINIMAL_PNG_BYTES).decode("ascii")
    real_png_sha = hashlib.sha256(MINIMAL_PNG_BYTES).hexdigest()

    runner_calls = []

    def mock_run_container_check(tar_bytes, cand_ref, man_sha):
        runner_calls.append({
            "tar_bytes": tar_bytes,
            "candidate_ref": cand_ref,
            "manifest_sha256": man_sha,
        })
        return {
            "success": True,
            "error": None,
            "report": {
                "schema_version": 1,
                "candidate_ref": cand_ref,
                "manifest_sha256": man_sha,
                "checker_version": "chromium-pw-1.63.0-v1",
                "checks": [
                    {"code": "integrity", "status": "pass", "detail_codes": []},
                    {"code": "entrypoint", "status": "pass", "detail_codes": []},
                    {"code": "assets", "status": "pass", "detail_codes": []},
                    {"code": "runtime", "status": "pass", "detail_codes": []},
                    {"code": "network", "status": "pass", "detail_codes": []},
                    {"code": "interaction", "status": "pass", "detail_codes": []},
                    {"code": "accessibility", "status": "pass", "detail_codes": []},
                ],
                "observations": {
                    "dom_text": "Valid Scene Content",
                    "controls": [],
                    "resource_events": [],
                    "runtime_errors": [],
                    "asset_failures": [],
                    "console_error_count": 0,
                    "page_error_count": 0,
                    "forbidden_request_count": 0,
                },
                "screenshot_sha256": real_png_sha,
                "screenshot_png_base64": real_png_b64,
                "started_at": "2026-10-04T12:00:00Z",
                "finished_at": "2026-10-04T12:00:01Z",
            },
            "screenshot_png": real_png_b64,
        }

    recorded_check_results = []

    class MockCheckClient:
        async def claim_attempt(self, case_id, candidate_ref, attempt_no):
            return {"attempt_ref": "chk-att-001", "disposition": "send_now"}

        async def record_result(self, case_id, attempt_ref, report_json, report_sha, screenshot_b64, screenshot_sha):
            recorded_check_results.append({
                "case_id": case_id,
                "attempt_ref": attempt_ref,
                "report_json": report_json,
                "report_sha": report_sha,
                "screenshot_b64": screenshot_b64,
                "screenshot_sha": screenshot_sha,
            })
            return {"attempt_ref": attempt_ref, "check_ref": "chk-run-001", "state": "pass"}

    class MockJevClient:
        async def claim_attempt(self, c_id, cand_ref, check_ref, attempt_no):
            return {"attempt_ref": "jev-att-001", "disposition": "send_now"}

        async def get_context(self, c_id, attempt_ref):
            return {
                "attempt_ref": attempt_ref,
                "model": "gemini-2.5-pro",
                "question_sha256": "q-sha",
                "evidence": {"schema_version": 1},
            }

        async def record_result(self, c_id, attempt_ref, raw_answer_json, answer_sha, returned_model):
            return {
                "attempt_ref": attempt_ref,
                "state": "recorded",
                "qualification_ref": "qual-001",
                "decision": "pass",
                "failed_checks": [],
            }

    from v3_engine.qualification import activity
    monkeypatch.setattr(activity, "run_container_check", mock_run_container_check)
    monkeypatch.setattr(activity, "get_check_client", lambda: MockCheckClient())
    monkeypatch.setattr(activity, "get_jev_client", lambda: MockJevClient())

    async def mock_call_jev(*args, **kwargs):
        return json.dumps({
            "schema_version": 1,
            "answers": {
                "choice": {"choice": "pass", "confidence": 0.95},
                "score": {"score": 95.0, "confidence": 0.95},
                "noul": {"noul": 0.95},
            },
        })

    monkeypatch.setattr(activity, "call_jev_provider", mock_call_jev)

    res = await qualify_scene_activity({
        "case_id": case_id,
        "candidate_ref": candidate_ref,
        "manifest_sha256": manifest_sha,
        "manifest": manifest,
        "manifest_raw": manifest_bytes,
        "package_files": files,
    })

    assert res["status"] == "qualified"
    assert res["candidate_ref"] == candidate_ref
    assert res["qualification_ref"] == "qual-001"

    # Assert runner received exact parameters
    assert len(runner_calls) == 1
    call = runner_calls[0]
    assert call["candidate_ref"] == candidate_ref
    assert call["manifest_sha256"] == manifest_sha

    # Verify tar bytes passed to runner can be unpacked and contain manifest and all files
    with tarfile.open(fileobj=io.BytesIO(call["tar_bytes"]), mode="r:*") as tar:
        tar_names = set(tar.getnames())
        assert {"manifest.json", "index.html", "app.js", "style.css"}.issubset(tar_names)

    # Assert the real runner screenshot was recorded and NOT the fake placeholder
    assert len(recorded_check_results) == 1
    recorded = recorded_check_results[0]
    assert recorded["screenshot_b64"] == real_png_b64
    assert recorded["screenshot_sha"] == real_png_sha
    assert b"fake-screenshot" not in base64.b64decode(recorded["screenshot_b64"])


@pytest.mark.asyncio
async def test_activity_handles_absent_or_invalid_screenshot_as_technical_failure(monkeypatch):
    manifest, manifest_bytes, manifest_sha, files = load_valid_scene_fixture()
    case_id = "00000000-0000-0000-0000-000000000010"
    candidate_ref = "00000000-0000-0000-0000-000000000020"

    def mock_run_missing_screenshot(tar_bytes, cand_ref, man_sha):
        return {
            "success": True,
            "error": None,
            "report": {
                "schema_version": 1,
                "candidate_ref": cand_ref,
                "manifest_sha256": man_sha,
                "checker_version": "chromium-pw-1.63.0-v1",
                "checks": [{"code": "integrity", "status": "pass", "detail_codes": []}],
                "observations": {"dom_text": "Scene", "controls": [], "resource_events": [], "runtime_errors": [], "asset_failures": [], "console_error_count": 0, "page_error_count": 0, "forbidden_request_count": 0},
                "screenshot_sha256": hashlib.sha256(b"").hexdigest(),
                "screenshot_png_base64": "",  # Empty/missing screenshot
            },
            "screenshot_png": None,
        }

    class MockCheckClient:
        async def claim_attempt(self, c_id, cand_ref, attempt_no):
            return {"attempt_ref": "chk-att-001", "disposition": "send_now"}

        async def record_result(self, *a, **kw):
            raise AssertionError("record_result should NOT be called on missing screenshot")

    class MockJevClient:
        async def claim_attempt(self, *a, **kw):
            raise AssertionError("JEV should NOT be called on missing screenshot")

    from v3_engine.qualification import activity
    async def mock_sandbox_missing_screenshot(tar_bytes, cand_ref, man_sha):
        return mock_run_missing_screenshot(tar_bytes, cand_ref, man_sha)

    monkeypatch.setattr(activity, "run_browser_checks_in_sandbox", mock_sandbox_missing_screenshot)
    monkeypatch.setattr(activity, "get_check_client", lambda: MockCheckClient())
    monkeypatch.setattr(activity, "get_jev_client", lambda: MockJevClient())

    res = await qualify_scene_activity({
        "case_id": case_id,
        "candidate_ref": candidate_ref,
        "manifest_sha256": manifest_sha,
        "manifest": manifest,
        "manifest_raw": manifest_bytes,
        "package_files": files,
    })

    assert res["status"] == "technical_failure"
    assert "screenshot" in res["error"].lower()


@pytest.mark.asyncio
async def test_activity_fetches_and_preserves_exact_stored_manifest_bytes(monkeypatch):
    manifest, manifest_bytes, manifest_sha, files = load_valid_scene_fixture()
    seen = {}

    class MockAuthoringClient:
        async def get_candidate(self, case_id, candidate_ref):
            return {
                "manifest": manifest,
                "manifest_sha256": manifest_sha,
                "manifest_raw_base64": base64.b64encode(manifest_bytes).decode("ascii"),
                "files": files,
            }

    class MockCheckClient:
        async def claim_attempt(self, case_id, candidate_ref, attempt_no):
            return {"attempt_ref": "check-1", "disposition": "send_now"}

    async def capture_tar(tar_bytes, candidate_ref, supplied_sha):
        seen.update(candidate_ref=candidate_ref, manifest_sha256=supplied_sha, tar_bytes=tar_bytes)
        return {"success": False, "error": "fixture_stop_after_tar_capture", "report": None, "screenshot_png": None}

    from v3_engine.qualification import activity
    monkeypatch.setattr(activity, "get_authoring_client", lambda: MockAuthoringClient())
    monkeypatch.setattr(activity, "get_check_client", lambda: MockCheckClient())
    monkeypatch.setattr(activity, "run_browser_checks_in_sandbox", capture_tar)

    result = await qualify_scene_activity({
        "case_id": "case-1",
        "candidate_ref": "candidate-1",
    })

    assert result["status"] == "technical_failure"
    assert seen["manifest_sha256"] == manifest_sha
    with tarfile.open(fileobj=io.BytesIO(seen["tar_bytes"]), mode="r:*") as archive:
        assert archive.extractfile("manifest.json").read() == manifest_bytes


@pytest.mark.asyncio
async def test_activity_reuses_recorded_check_and_does_not_rerun_browser(monkeypatch):
    manifest, manifest_bytes, manifest_sha, files = load_valid_scene_fixture()
    jev_claims = []

    class MockCheckClient:
        async def claim_attempt(self, case_id, candidate_ref, attempt_no):
            return {
                "attempt_ref": "check-attempt-1",
                "disposition": "recorded",
                "state": "recorded",
                "check_ref": "check-run-1",
                "verdict": "pass",
            }

        async def record_result(self, *args, **kwargs):
            raise AssertionError("Recorded browser evidence must not be submitted again.")

    class MockJevClient:
        async def claim_attempt(self, case_id, candidate_ref, check_ref, attempt_no):
            jev_claims.append(check_ref)
            return {"attempt_ref": "jev-attempt-1"}

        async def get_context(self, case_id, attempt_ref):
            return {"model": "typesafe-systemone-v1", "evidence": {"schema_version": 1}}

        async def record_result(self, *args):
            return {"decision": "pass", "qualification_ref": "qualification-1"}

    from v3_engine.qualification import activity
    monkeypatch.setattr(activity, "get_check_client", lambda: MockCheckClient())
    monkeypatch.setattr(activity, "get_jev_client", lambda: MockJevClient())

    async def unexpected_browser_run(*args, **kwargs):
        raise AssertionError("Recorded browser evidence must not rerun Chromium.")

    async def typed_pass(*args, **kwargs):
        return {"model": "typesafe-systemone-v1", "answers": {}}

    monkeypatch.setattr(activity, "run_browser_checks_in_sandbox", unexpected_browser_run)
    monkeypatch.setattr(activity, "call_jev_provider", typed_pass)

    result = await qualify_scene_activity({
        "case_id": "case-1",
        "candidate_ref": "candidate-1",
        "manifest_sha256": manifest_sha,
        "manifest": manifest,
        "manifest_raw": manifest_bytes,
        "package_files": files,
    })

    assert result["status"] == "qualified"
    assert result["check_ref"] == "check-run-1"
    assert jev_claims == ["check-run-1"]
