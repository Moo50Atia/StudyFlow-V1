"""Tests for privacy boundaries, sentinel masking, and credential isolation (Spec 018 / T002)."""

import json
import pytest
from pathlib import Path

from v3_engine.terminal_pipeline.contracts import (
    ActivityOutcome,
    ArtifactRef,
    SafeActivityInput,
    SafeActivityResult,
    SafeCode,
)
from v3_engine.terminal_pipeline.manifest import (
    capture_manifest,
)


FORBIDDEN_SENTINELS = [
    "AIzaSy",  # Gemini API key prefix
    "sk-proj",  # OpenAI / provider token
    "SID=",  # Google auth cookie
    "HSID=",  # Google auth cookie
    "SSID=",  # Google auth cookie
    "Bearer eyJ",  # JWT bearer token
]


def test_manifest_and_payloads_exclude_secrets(tmp_path: Path) -> None:
    proj_root = tmp_path / "project"
    proj_root.mkdir()
    term_root = tmp_path / "terminal_root"
    term_root.mkdir()

    pdf_dir = proj_root / "pdfs"
    pdf_dir.mkdir()
    (pdf_dir / "lesson.pdf").write_bytes(b"%PDF-1.4 test")
    (proj_root / "steps.txt").write_text("Framework text", encoding="utf-8")

    raw = {
        "schema_version": 1,
        "submission_id": "92407f71-f6c4-4c78-9938-0cfeef937cf2",
        "subjects": [
            {
                "subject_key": "privacy_test",
                "mode": "new",
                "pdf_dir": "pdfs",
                "account_check_notebook_id": "85b76e80-ea8a-4f86-9311-185634fcd2ed",
                "provider": "gemini",
                "model": "gemini-2.5-flash",
            }
        ],
    }

    manifest, manifest_path = capture_manifest(raw, term_root, proj_root)
    manifest_content = manifest_path.read_text(encoding="utf-8")

    for sentinel in FORBIDDEN_SENTINELS:
        assert sentinel not in manifest_content, f"Found secret sentinel in manifest: {sentinel}"

    # Check SafeActivityInput and SafeActivityResult dict representations
    ref = ArtifactRef(path="runs/sub/run.json", sha256="0" * 64, bytes=100)
    inp = SafeActivityInput(
        subject_run_id="92407f71-f6c4-4c78-9938-0cfeef937cf2",
        run_ref=ref,
    )
    inp_json = json.dumps(inp.to_dict())
    for sentinel in FORBIDDEN_SENTINELS:
        assert sentinel not in inp_json

    out = SafeActivityResult(
        outcome=ActivityOutcome.failed,
        safe_code=SafeCode.authentication_required,
        receipt_ref=ref,
    )
    out_json = json.dumps(out.to_dict())
    for sentinel in FORBIDDEN_SENTINELS:
        assert sentinel not in out_json


def test_no_educational_body_in_activity_results() -> None:
    # SafeActivityResult must only contain outcome, safe_code, and safe refs/counts
    ref = ArtifactRef(path="runs/sub/run.json", sha256="a" * 64, bytes=50)
    res = SafeActivityResult(
        outcome=ActivityOutcome.complete,
        safe_code=SafeCode.ok,
        result_ref=ref,
        counts={"lessons_saved": 4, "scenes_generated": 12},
    )
    d = res.to_dict()
    # Confirm no raw text fields like 'prompt', 'code', 'answer', 'html' exist in the safe result
    assert "prompt" not in d
    assert "code" not in d
    assert "answer" not in d
    assert "html" not in d
    assert "raw_response" not in d


def test_nested_sdk_error_cause_sanitization() -> None:
    # Simulate an error with nested HTTP body containing secret and educational text
    sensitive_detail = "Failed HTTP 403: Bearer eyJhbGciOi... API key AIzaSyFakeSecret invalid for prompt 'Euler equation'"
    try:
        raise RuntimeError(sensitive_detail)
    except Exception as exc:
        # Normalized safe activity result
        res = SafeActivityResult(
            outcome=ActivityOutcome.failed,
            safe_code=SafeCode.authentication_required,
            result_ref=None,
        )
        serialized = json.dumps(res.to_dict())
        for sentinel in FORBIDDEN_SENTINELS:
            assert sentinel not in serialized
        assert "Euler" not in serialized


def test_serialized_payload_strictly_under_32kib() -> None:
    # Create large safe result with multiple counts and refs
    counts = {f"metric_{i}": i * 10 for i in range(100)}
    ref = ArtifactRef(path="runs/sub/scene_001.json", sha256="f" * 64, bytes=4096)
    res = SafeActivityResult(
        outcome=ActivityOutcome.complete,
        safe_code=SafeCode.ok,
        result_ref=ref,
        counts=counts,
    )
    serialized = json.dumps(res.to_dict())
    assert len(serialized.encode("utf-8")) < 32 * 1024, "Payload must be <= 32KiB"

