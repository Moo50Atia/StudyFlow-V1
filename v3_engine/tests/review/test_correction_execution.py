from types import SimpleNamespace

import pytest

from v3_engine.review import activity as review_activity


@pytest.mark.asyncio
async def test_visual_route_runs_repair_runner_and_links_only_successor(monkeypatch):
    calls = []

    class RepairClient:
        async def get_repair_context(self, case_id, repair_ref):
            calls.append(("context", case_id, repair_ref))
            return {"previous_candidate_ref": "old", "teacher_note": "Make the motion label larger."}

        async def link_repair_candidate(self, case_id, repair_ref, candidate_ref):
            calls.append(("link", case_id, repair_ref, candidate_ref))

    class AuthoringClient:
        async def get_context(self, case_id, request_ref):
            calls.append(("authoring_context", case_id, request_ref))
            return {"prompt": "captured scene prompt", "template_text": "approved scene template"}

    class FakeRunner:
        def __init__(self, authoring_client, api_keys, timeout_seconds):
            assert api_keys["gemini_api_key"] == "test-key"

        async def run_repair_attempt(self, **kwargs):
            assert kwargs["request_ref"] == "request-1"
            assert kwargs["prompt"] == "captured scene prompt"
            assert kwargs["template_text"] == "approved scene template"
            assert kwargs["repair_context"]["teacher_note"] == "Make the motion label larger."
            return SimpleNamespace(status="candidate_ready", candidate_ref="successor-1")

    monkeypatch.setattr("v3_engine.qualification.activity.get_repair_client", lambda: RepairClient())
    monkeypatch.setattr("v3_engine.qualification.activity.get_authoring_client", lambda: AuthoringClient())
    monkeypatch.setattr("v3_engine.qualification.repair.RepairRunner", FakeRunner)
    monkeypatch.setattr("v3_engine.settings.get_settings", lambda: SimpleNamespace(
        gemini_api_key="test-key", glm_api_key="", deepseek_api_key=""))

    result = await review_activity.execute_teacher_scene_repair_activity({
        "case_id": "case-1", "repair_ref": "repair-1", "authoring_request_ref": "request-1", "ordinal": 1,
    })
    assert result == {"status": "candidate_ready", "candidate_ref": "successor-1", "repair_ref": "repair-1"}
    assert calls == [
        ("context", "case-1", "repair-1"),
        ("authoring_context", "case-1", "request-1"),
        ("link", "case-1", "repair-1", "successor-1"),
    ]


@pytest.mark.asyncio
async def test_factual_route_persists_provider_revision_and_uncertainty(monkeypatch):
    recorded = []

    class Client:
        async def get_prompt_correction_context(self, case_id, effect_ref):
            return {
                "section_ref": "sec", "fields": {"core_understanding": "fact"},
                "previous_prompt": "Comparison: old", "teacher_note": "Correct source fact",
                "previous_qualification": {"choice": "pass"}, "provider": {"id": "gemini_api"},
                "template_bytes": "approved", "work_ordinal": 3,
            }

        async def record_prompt_correction_result(self, case_id, effect_ref, payload):
            recorded.append(payload)
            return {"prompt_version_ref": "prompt-next", "version_no": 2}

        async def record_prompt_correction_uncertain(self, *args):
            raise AssertionError("success path must not record uncertainty")

    class FakeFeedbackAdapter:
        @staticmethod
        async def revise(**kwargs):
            assert kwargs["user_payload"]["teacher_note"] == "Correct source fact"
            return SimpleNamespace(
                text="Comparison: corrected", output_sha256="out-sha", raw_response_json="{}",
                raw_response_sha256="resp-sha", returned_model="gemini-model", provider_response_id="provider-1",
            )

    monkeypatch.setattr(review_activity, "_get_review_client", lambda: Client())
    monkeypatch.setattr("v3_engine.prompts.feedback.FeedbackTextAdapter", FakeFeedbackAdapter)
    result = await review_activity.revise_teacher_prompt_correction_activity({"case_id": "case-1", "effect_ref": "effect-1"})
    assert result["status"] == "recorded"
    assert result["prompt_version_ref"] == "prompt-next"
    assert result["work_ordinal"] == 3
    assert recorded[0]["provider_response_id"] == "provider-1"
