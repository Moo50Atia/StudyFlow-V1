import hashlib
import httpx
import pytest
from v3_engine.prompts.feedback import (
    compute_feedback_request_digest,
)


def test_feedback_request_digest_stability():
    failed_version = "22222222-2222-4222-8222-222222222222"
    qual_ans_sha = "a" * 64
    template_sha = "b" * 64
    provider_id = "gemini_api"
    provider_model = "gemini-2.5-flash"
    credential_ref = "GEMINI_API_KEY"

    expected_payload = (
        f"010-feedback-v1\n"
        f"{failed_version}\n"
        f"{qual_ans_sha}\n"
        f"{template_sha}\n"
        f"{provider_id}\n"
        f"{provider_model}\n"
        f"{credential_ref}"
    )
    expected_digest = hashlib.sha256(expected_payload.encode("utf-8")).hexdigest()

    digest = compute_feedback_request_digest(
        failed_version_uuid=failed_version,
        qualification_answer_sha=qual_ans_sha,
        captured_template_sha=template_sha,
        provider_id=provider_id,
        provider_model=provider_model,
        credential_ref=credential_ref,
    )

    assert digest == expected_digest


@pytest.mark.asyncio
async def test_feedback_text_adapter_gemini_success(monkeypatch):
    from v3_engine.prompts.feedback import FeedbackTextAdapter

    monkeypatch.setenv("GEMINI_API_KEY", "test-gemini-key")

    mock_resp = {
        "candidates": [
            {
                "finishReason": "STOP",
                "content": {
                    "parts": [{"text": "Process: Revised prompt showing particles in wave motion."}]
                },
            }
        ],
        "modelVersion": "gemini-2.5-flash-001",
        "responseId": "resp-123",
    }

    transport = httpx.MockTransport(lambda req: httpx.Response(200, json=mock_resp))
    async with httpx.AsyncClient(transport=transport) as http_client:
        res = await FeedbackTextAdapter.revise(
            descriptor={
                "id": "gemini_api",
                "model": "gemini-2.5-flash",
                "credential_ref": "GEMINI_API_KEY",
            },
            template_bytes="You are a prompt editor.",
            user_payload={"test": "payload"},
            effect_ref="effect-1",
            client=http_client,
        )

    assert res.text == "Process: Revised prompt showing particles in wave motion."
    assert res.returned_model == "gemini-2.5-flash-001"
    assert res.provider_response_id == "resp-123"
    assert len(res.output_sha256) == 64


@pytest.mark.asyncio
async def test_feedback_text_adapter_rejects_unallowed_scene_type(monkeypatch):
    from v3_engine.prompts.feedback import FeedbackTextAdapter

    monkeypatch.setenv("GEMINI_API_KEY", "test-gemini-key")

    mock_resp = {
        "candidates": [
            {
                "finishReason": "STOP",
                "content": {
                    "parts": [{"text": "InvalidType: Some description without an allowed scene type."}]
                },
            }
        ],
    }

    transport = httpx.MockTransport(lambda req: httpx.Response(200, json=mock_resp))
    async with httpx.AsyncClient(transport=transport) as http_client:
        with pytest.raises(ValueError, match="does not start with an allowed scene type"):
            await FeedbackTextAdapter.revise(
                descriptor={
                    "id": "gemini_api",
                    "model": "gemini-2.5-flash",
                    "credential_ref": "GEMINI_API_KEY",
                },
                template_bytes="You are a prompt editor.",
                user_payload={"test": "payload"},
                effect_ref="effect-1",
                client=http_client,
            )

