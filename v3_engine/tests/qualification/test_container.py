import os
import tarfile
import io
import json
import pytest
from pathlib import Path
from v3_engine.qualification.container_runner import run_container_check

FIXTURES_DIR = Path(__file__).parent / "fixtures"

def make_tar_bytes(fixture_name: str) -> tuple[bytes, str]:
    pkg_dir = FIXTURES_DIR / fixture_name
    with open(pkg_dir / "manifest.json", "rb") as f:
        manifest_bytes = f.read()
    import hashlib
    manifest_sha = hashlib.sha256(manifest_bytes).hexdigest()
    
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        for root, _, files in os.walk(pkg_dir):
            for file in files:
                full_path = os.path.join(root, file)
                rel_path = os.path.relpath(full_path, pkg_dir)
                tar.add(full_path, arcname=rel_path)
    return buf.getvalue(), manifest_sha

@pytest.mark.container
def test_s03_container_hostile_network_denial():
    tar_bytes, manifest_sha = make_tar_bytes("hostile_network_scene")
    result = run_container_check(tar_bytes, "00000000-0000-0000-0000-000000000004", manifest_sha)
    
    assert result["success"] is True
    report = result["report"]
    assert report is not None
    check_map = {c["code"]: c for c in report["checks"]}
    assert check_map["network"]["status"] == "fail"
    assert "forbidden_request" in check_map["network"]["detail_codes"]
    assert report["observations"]["forbidden_request_count"] > 0

@pytest.mark.container
def test_s04_container_invalid_tar_unavailable():
    corrupt_tar = b"not-a-valid-tar-file"
    result = run_container_check(corrupt_tar, "00000000-0000-0000-0000-000000000099", "0"*64)
    assert result["success"] is False
    assert result["error"] in ["checker_unavailable", "integrity_mismatch"]


@pytest.mark.container
def test_s01_container_valid_scene_produces_real_screenshot_and_passes():
    import base64
    tar_bytes, manifest_sha = make_tar_bytes("valid_scene")
    result = run_container_check(tar_bytes, "00000000-0000-0000-0000-000000000001", manifest_sha)
    assert result["success"] is True
    assert result["report"] is not None
    assert result["screenshot_png"] is not None
    # Validate screenshot is valid base64 PNG
    screenshot_bytes = base64.b64decode(result["screenshot_png"])
    assert screenshot_bytes.startswith(b"\x89PNG\r\n\x1a\n")
    import hashlib
    assert hashlib.sha256(screenshot_bytes).hexdigest() == result["report"]["screenshot_sha256"]
    # All 7 checks should pass
    checks = result["report"]["checks"]
    assert len(checks) == 7
    for c in checks:
        assert c["status"] == "pass"


@pytest.mark.container
def test_s05_container_corrupted_tar_returns_failure():
    result = run_container_check(b"bad-data", "cand-1", "0" * 64)
    assert result["success"] is False
    assert result["error"] in ("checker_unavailable", "integrity_mismatch")

