import pytest
from v3_engine.qualification.activity import qualify_scene_activity


@pytest.mark.asyncio
async def test_qualify_scene_activity_direct_pass(monkeypatch):
    class MockCheckClient:
        async def claim_attempt(self, case_id, candidate_ref, attempt_no):
            return {"attempt_ref": "chk-att-1", "disposition": "send_now"}

        async def record_result(self, case_id, attempt_ref, report_json, report_sha, screenshot_b64, screenshot_sha):
            return {"attempt_ref": attempt_ref, "check_ref": "chk-run-1", "state": "pass"}

    class MockJevClient:
        async def claim_attempt(self, case_id, candidate_ref, check_ref, attempt_no):
            return {"attempt_ref": "jev-att-1", "disposition": "send_now"}

        async def get_context(self, case_id, attempt_ref):
            return {"attempt_ref": attempt_ref, "model": "gemini-2.5-pro", "question_sha256": "q-sha", "evidence": {"schema_version": 1}}

        async def record_result(self, case_id, attempt_ref, raw_answer_json, answer_sha, returned_model):
            return {"attempt_ref": attempt_ref, "state": "recorded", "qualification_ref": "qual-1", "decision": "pass", "failed_checks": []}

    from v3_engine.qualification import activity
    monkeypatch.setattr(activity, "get_check_client", lambda: MockCheckClient())
    monkeypatch.setattr(activity, "get_jev_client", lambda: MockJevClient())

    valid_png_b64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
    async def mock_run_checks(*args, **kwargs):
        return {
            "checks": [{"code": "integrity", "status": "pass", "detail_codes": []}],
            "observations": {"dom_text": "pass", "controls": [], "resource_events": [], "runtime_errors": [], "asset_failures": [], "console_error_count": 0, "page_error_count": 0, "forbidden_request_count": 0},
            "screenshot_sha256": "4355a46b19d348dc2f57c046f8ef63d4538ebb936000f3c9ee954a27460dd865",
            "screenshot_png_base64": valid_png_b64,
            "started_at": "2026-10-01T00:00:00Z",
            "finished_at": "2026-10-01T00:00:01Z",
        }

    monkeypatch.setattr(activity, "run_browser_checks_in_sandbox", mock_run_checks)

    async def mock_call_jev(*args, **kwargs):
        import json
        return json.dumps({
            "schema_version": 1,
            "answers": {
                "choice": {"choice": "pass", "confidence": 0.9},
                "score": {"score": 90.0, "confidence": 0.9},
                "noul": {"noul": 0.9},
            }
        })

    monkeypatch.setattr(activity, "call_jev_provider", mock_call_jev)

    res = await qualify_scene_activity({
        "case_id": "case-1",
        "candidate_ref": "cand-1",
        "manifest_sha256": "m-sha",
        "package_files": [{"path": "index.html", "content": "<html></html>"}],
    })

    assert res["status"] == "qualified"
    assert res["candidate_ref"] == "cand-1"
    assert res["qualification_ref"] == "qual-1"


@pytest.mark.asyncio
async def test_qualify_scene_activity_check_failure_never_calls_jev(monkeypatch):
    class MockCheckClient:
        async def claim_attempt(self, case_id, candidate_ref, attempt_no):
            return {"attempt_ref": "chk-att-1", "disposition": "send_now"}

        async def record_result(self, case_id, attempt_ref, report_json, report_sha, screenshot_b64, screenshot_sha):
            # Check failed!
            return {"attempt_ref": attempt_ref, "check_ref": "chk-run-1", "state": "fail"}

    class MockJevClient:
        async def claim_attempt(self, *args, **kwargs):
            raise AssertionError("JEV claim_attempt MUST NOT be called when checks fail")

    from v3_engine.qualification import activity
    monkeypatch.setattr(activity, "get_check_client", lambda: MockCheckClient())
    monkeypatch.setattr(activity, "get_jev_client", lambda: MockJevClient())

    valid_png_b64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
    async def mock_run_checks(*args, **kwargs):
        return {
            "checks": [{"code": "integrity", "status": "fail", "detail_codes": ["checksum_mismatch"]}],
            "observations": {"dom_text": "", "controls": [], "resource_events": [], "runtime_errors": ["Error"], "asset_failures": [], "console_error_count": 1, "page_error_count": 0, "forbidden_request_count": 0},
            "screenshot_sha256": "4355a46b19d348dc2f57c046f8ef63d4538ebb936000f3c9ee954a27460dd865",
            "screenshot_png_base64": valid_png_b64,
            "started_at": "2026-10-01T00:00:00Z",
            "finished_at": "2026-10-01T00:00:01Z",
        }

    monkeypatch.setattr(activity, "run_browser_checks_in_sandbox", mock_run_checks)

    res = await qualify_scene_activity({
        "case_id": "case-1",
        "candidate_ref": "cand-1",
        "manifest_sha256": "m-sha",
        "package_files": [{"path": "index.html", "content": "<html></html>"}],
    })

    assert res["status"] == "check_failed"
    assert res["candidate_ref"] == "cand-1"
    assert res["check_ref"] == "chk-run-1"
    assert res["qualification_ref"] is None


@pytest.mark.asyncio
async def test_qualify_scene_activity_jev_revise_returns_repair_pending(monkeypatch):
    class MockCheckClient:
        async def claim_attempt(self, case_id, candidate_ref, attempt_no):
            return {"attempt_ref": "chk-att-1", "disposition": "send_now"}

        async def record_result(self, case_id, attempt_ref, report_json, report_sha, screenshot_b64, screenshot_sha):
            return {"attempt_ref": attempt_ref, "check_ref": "chk-run-1", "state": "pass"}

    class MockJevClient:
        async def claim_attempt(self, case_id, candidate_ref, check_ref, attempt_no):
            return {"attempt_ref": "jev-att-1", "disposition": "send_now"}

        async def get_context(self, case_id, attempt_ref):
            return {"attempt_ref": attempt_ref, "model": "gemini-2.5-pro", "question_sha256": "q-sha", "evidence": {"schema_version": 1}}

        async def record_result(self, case_id, attempt_ref, raw_answer_json, answer_sha, returned_model):
            return {"attempt_ref": attempt_ref, "state": "recorded", "qualification_ref": "qual-1", "decision": "revise", "failed_checks": ["pedagogical_clarity"]}

    from v3_engine.qualification import activity
    monkeypatch.setattr(activity, "get_check_client", lambda: MockCheckClient())
    monkeypatch.setattr(activity, "get_jev_client", lambda: MockJevClient())

    valid_png_b64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
    async def mock_run_checks(*args, **kwargs):
        return {
            "checks": [{"code": "integrity", "status": "pass", "detail_codes": []}],
            "observations": {"dom_text": "pass", "controls": [], "resource_events": [], "runtime_errors": [], "asset_failures": [], "console_error_count": 0, "page_error_count": 0, "forbidden_request_count": 0},
            "screenshot_sha256": "4355a46b19d348dc2f57c046f8ef63d4538ebb936000f3c9ee954a27460dd865",
            "screenshot_png_base64": valid_png_b64,
            "started_at": "2026-10-01T00:00:00Z",
            "finished_at": "2026-10-01T00:00:01Z",
        }

    monkeypatch.setattr(activity, "run_browser_checks_in_sandbox", mock_run_checks)

    async def mock_call_jev(*args, **kwargs):
        import json
        return json.dumps({
            "schema_version": 1,
            "answers": {
                "choice": {"choice": "revise", "confidence": 0.8},
                "score": {"score": 60.0, "confidence": 0.8},
                "noul": {"noul": 0.6},
            }
        })

    monkeypatch.setattr(activity, "call_jev_provider", mock_call_jev)

    res = await qualify_scene_activity({
        "case_id": "case-1",
        "candidate_ref": "cand-1",
        "manifest_sha256": "m-sha",
        "package_files": [{"path": "index.html", "content": "<html></html>"}],
    })

    assert res["status"] == "repair_pending"
    assert res["candidate_ref"] == "cand-1"
    assert res["qualification_ref"] == "qual-1"


@pytest.mark.asyncio
async def test_repair_scene_activity_execution(monkeypatch):
    from v3_engine.qualification.activity import repair_scene_activity

    class MockRepairClient:
        async def claim_repair(self, case_id, failed_candidate_ref, expected_ordinal):
            return {
                "repair_ref": "rep-1",
                "authoring_request_ref": "req-1",
                "ordinal": expected_ordinal,
                "state": "claimed",
            }

        async def get_repair_context(self, case_id, repair_ref):
            return {
                "repair_ref": repair_ref,
                "authoring_request_ref": "req-1",
                "ordinal": 1,
                "prompt": "Test prompt",
                "template_text": "Template",
                "previous_candidate_ref": "c-prev",
                "previous_files": [],
                "criteria_sha256": "crit-sha",
                "failed_gate": "browser",
                "failed_checks": ["integrity"],
                "check_observations": {},
            }

        async def link_repair_candidate(self, case_id, repair_ref, candidate_ref):
            return {
                "repair_ref": repair_ref,
                "candidate_ref": candidate_ref,
                "candidate_version": 2,
                "state": "candidate_ready",
            }

    from v3_engine.qualification import activity
    monkeypatch.setattr(activity, "get_repair_client", lambda: MockRepairClient())

    class MockAuthoringOutcome:
        status = "candidate_ready"
        candidate_ref = "cand-repaired-1"
        safe_error_code = None

    class MockRepairRunner:
        def __init__(self, *args, **kwargs):
            pass

        async def run_repair_attempt(self, *args, **kwargs):
            return MockAuthoringOutcome()

    monkeypatch.setattr(activity, "RepairRunner", MockRepairRunner)

    res = await repair_scene_activity({
        "case_id": "case-1",
        "failed_candidate_ref": "cand-1",
        "expected_ordinal": 1,
    })

    assert res["status"] == "candidate_ready"
    assert res["candidate_ref"] == "cand-repaired-1"
    assert res["repair_ref"] == "rep-1"

