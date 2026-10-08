import pytest
from v3_engine.qualification.repair import (
    format_repair_user_payload,
    SceneRepairClient,
    RepairRunner,
)


def test_format_repair_user_payload_exact_keys():
    prompt = "Create a 3D atom simulation"
    repair_context = {
        "previous_candidate_ref": "c-1234",
        "previous_files": [{"path": "index.html", "content": "<html></html>"}],
        "criteria_sha256": "abcdef123456",
        "failed_gate": "browser",
        "failed_checks": ["asset_missing"],
        "check_observations": {
            "asset_failures": ["texture.png"],
            "runtime_errors": [],
            "forbidden_request_count": 0,
            "control_roles": ["button"],
        },
        "teacher_note": "Increase the motion label size and keep the interaction.",
    }

    payload = format_repair_user_payload(prompt, repair_context)

    assert payload["schema_version"] == 1
    assert payload["prompt"] == prompt
    assert "output_contract" in payload
    assert "repair" in payload

    rep = payload["repair"]
    assert rep["previous_candidate_ref"] == "c-1234"
    assert rep["files"] == [{"path": "index.html", "content": "<html></html>"}]
    assert rep["criteria_sha256"] == "abcdef123456"
    assert rep["failed_gate"] == "browser"
    assert rep["failed_checks"] == ["asset_missing"]
    assert rep["check_observations"]["asset_failures"] == ["texture.png"]
    assert rep["teacher_note"] == "Increase the motion label size and keep the interaction."


@pytest.mark.asyncio
async def test_repair_runner_uncertain_halt(monkeypatch):
    class MockClient:
        async def reserve_attempt(self, case_id, request_ref, ordinal):
            return {
                "attempt_ref": "att-1",
                "provider": {
                    "id": "gemini_api",
                    "model": "gemini-2.5-pro",
                    "credential_ref": "GEMINI_API_KEY",
                },
            }

        async def record_result(self, case_id, attempt_ref, payload):
            self.recorded = payload
            return {"attempt_ref": attempt_ref, "state": payload["outcome"]}

    mock_client = MockClient()
    runner = RepairRunner(mock_client, {"GEMINI_API_KEY": "fake-key"})

    # Simulate timeout during repair call
    async def mock_call(*args, **kwargs):
        import asyncio
        raise asyncio.TimeoutError()

    from v3_engine.qualification import repair
    monkeypatch.setattr(repair, "call_gemini_repair", mock_call)

    outcome = await runner.run_repair_attempt(
        case_id="case-1",
        request_ref="req-1",
        ordinal=1,
        prompt="Test prompt",
        template_text="Template",
        repair_context={"previous_candidate_ref": "c-1", "previous_files": [], "criteria_sha256": "abc", "failed_checks": [], "check_observations": {}},
    )

    assert outcome.status == "uncertain"
    assert mock_client.recorded["outcome"] == "uncertain"


@pytest.mark.asyncio
async def test_repair_runner_serializes_validated_package_to_authoring_contract(monkeypatch):
    import json

    class MockClient:
        async def reserve_attempt(self, case_id, request_ref, ordinal):
            return {
                "attempt_ref": "attempt-repair-1",
                "provider": {
                    "id": "gemini_api",
                    "model": "gemini-2.5-pro",
                    "credential_ref": "GEMINI_API_KEY",
                },
            }

        async def record_result(self, case_id, attempt_ref, payload):
            self.recorded = payload
            return {"candidate_ref": "candidate-successor"}

    mock_client = MockClient()
    package_json = json.dumps({
        "schema_version": 1,
        "files": [{"path": "index.html", "content": "<!doctype html><html></html>"}],
    })

    async def mock_call(**kwargs):
        return {
            "candidates": [{"content": {"parts": [{"text": package_json}]}}],
            "responseId": "repair-response-1",
        }

    from v3_engine.qualification import repair
    monkeypatch.setattr(repair, "call_gemini_repair", mock_call)
    runner = RepairRunner(mock_client, {"GEMINI_API_KEY": "fixture-key"})

    outcome = await runner.run_repair_attempt(
        case_id="case-1",
        request_ref="request-repair-1",
        ordinal=1,
        prompt="Create the corrected scene.",
        template_text="Approved template",
        repair_context={
            "previous_candidate_ref": "candidate-prior",
            "previous_files": [],
            "criteria_sha256": "criteria-sha",
            "failed_checks": [],
            "check_observations": {},
            "teacher_note": "Increase the motion label size.",
        },
    )

    assert outcome.status == "candidate_ready"
    assert outcome.candidate_ref == "candidate-successor"
    assert mock_client.recorded["outcome"] == "candidate"
    assert mock_client.recorded["package"] == {
        "schema_version": 1,
        "files": [{"path": "index.html", "content": "<!doctype html><html></html>"}],
    }
