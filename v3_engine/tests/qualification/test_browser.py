import os
import json
import pytest
from pathlib import Path
from v3_engine.qualification.browser_checks import run_browser_checks

pytestmark = [pytest.mark.browser_ui, pytest.mark.asyncio]


FIXTURES_DIR = Path(__file__).parent / "fixtures"

def load_fixture_manifest(name: str) -> dict:
    with open(FIXTURES_DIR / name / "manifest.json", "r", encoding="utf-8") as f:
        return json.load(f)

@pytest.mark.asyncio
async def test_s05_valid_scene_passes_all_checks():
    fixture_name = "valid_scene"
    candidate_dir = str(FIXTURES_DIR / fixture_name)
    manifest = load_fixture_manifest(fixture_name)
    
    report = await run_browser_checks(candidate_dir, "00000000-0000-0000-0000-000000000001", manifest)
    
    assert report["schema_version"] == 1
    assert report["checker_version"] == "chromium-pw-1.63.0-v1"
    check_map = {c["code"]: c for c in report["checks"]}
    
    for code in ["integrity", "entrypoint", "assets", "runtime", "network", "interaction", "accessibility"]:
        assert code in check_map
        assert check_map[code]["status"] == "pass", f"Check {code} failed with {check_map[code].get("detail_codes")}"
    
    assert report["observations"]["console_error_count"] == 0
    assert report["observations"]["page_error_count"] == 0
    assert len(report["observations"]["controls"]) >= 1
    ctrl = report["observations"]["controls"][0]
    assert ctrl["pointer_changed"] is True
    assert ctrl["keyboard_changed"] is True
    assert ctrl["focusable"] is True

@pytest.mark.asyncio
async def test_s06_broken_asset_scene_fails():
    fixture_name = "broken_asset_scene"
    candidate_dir = str(FIXTURES_DIR / fixture_name)
    manifest = load_fixture_manifest(fixture_name)
    
    report = await run_browser_checks(candidate_dir, "00000000-0000-0000-0000-000000000002", manifest)
    check_map = {c["code"]: c for c in report["checks"]}
    
    assert check_map["assets"]["status"] == "fail"
    assert "asset_missing" in check_map["assets"]["detail_codes"] or "asset_undeclared" in check_map["assets"]["detail_codes"]

@pytest.mark.asyncio
async def test_s06_runtime_error_scene_fails():
    fixture_name = "runtime_error_scene"
    candidate_dir = str(FIXTURES_DIR / fixture_name)
    manifest = load_fixture_manifest(fixture_name)
    
    report = await run_browser_checks(candidate_dir, "00000000-0000-0000-0000-000000000003", manifest)
    check_map = {c["code"]: c for c in report["checks"]}
    
    assert check_map["runtime"]["status"] == "fail"
    assert "script_error" in check_map["runtime"]["detail_codes"] or "console_error" in check_map["runtime"]["detail_codes"]
    assert report["observations"]["console_error_count"] > 0 or report["observations"]["page_error_count"] > 0

@pytest.mark.asyncio
async def test_s07_noop_control_autoplay_fails_interaction():
    fixture_name = "noop_control_scene"
    candidate_dir = str(FIXTURES_DIR / fixture_name)
    manifest = load_fixture_manifest(fixture_name)
    
    report = await run_browser_checks(candidate_dir, "00000000-0000-0000-0000-000000000004", manifest)
    check_map = {c["code"]: c for c in report["checks"]}
    
    assert check_map["interaction"]["status"] == "fail"
    assert "interaction_no_change" in check_map["interaction"]["detail_codes"]

@pytest.mark.asyncio
async def test_s08_inaccessible_scene_fails():
    fixture_name = "inaccessible_scene"
    candidate_dir = str(FIXTURES_DIR / fixture_name)
    manifest = load_fixture_manifest(fixture_name)
    
    report = await run_browser_checks(candidate_dir, "00000000-0000-0000-0000-000000000005", manifest)
    check_map = {c["code"]: c for c in report["checks"]}
    
    assert check_map["accessibility"]["status"] == "fail"
    details = check_map["accessibility"]["detail_codes"]
    assert any(c in details for c in ["accessible_name_missing", "document_lang_missing", "document_title_missing"])
