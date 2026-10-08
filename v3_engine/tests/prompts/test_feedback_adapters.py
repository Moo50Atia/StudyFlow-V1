import json
import pytest
import httpx

from v3_engine.prompts.feedback import (
    FeedbackTextAdapter,
    compute_feedback_request_digest,
)


@pytest.mark.asyncio
async def test_gemini_adapter_fixture():
    descriptor = {
        "id": "gemini_api",
        "model": "gemini-2.5-flash",
        "credential_ref": "GEMINI_API_KEY",
    }
    template_bytes = "You are a prompt reviser. Fix misconception and keep scene type."
    user_payload = {
        "section_ref": "sec-1",
        "fields": {"section_identity": "Waves"},
        "previous_prompt": "Process: Old prompt",
        "failed_checks": ["score"],
        "typed_values": {"choice": "revise", "score": 60.0, "noul": 0.7},
    }

    mock_resp = {
        "candidates": [
            {
                "content": {
                    "parts": [{"text": "Process: Revised wave step with particle oscillators."}]
                },
                "finishReason": "STOP",
            }
        ],
        "modelVersion": "gemini-2.5-flash-v1",
        "responseId": "resp-gem-1",
    }

    def handler(request: httpx.Request):
        assert "generativelanguage.googleapis.com" in str(request.url)
        assert request.headers.get("x-goog-api-key") == "mock-gemini-key"
        body = json.loads(request.content.decode("utf-8"))
        assert body["systemInstruction"]["parts"][0]["text"] == template_bytes
        assert json.loads(body["contents"][0]["parts"][0]["text"]) == user_payload
        return httpx.Response(200, json=mock_resp)

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        import os
        os.environ["GEMINI_API_KEY"] = "mock-gemini-key"
        result = await FeedbackTextAdapter.revise(
            descriptor=descriptor,
            template_bytes=template_bytes,
            user_payload=user_payload,
            effect_ref="eff-1",
            client=client,
        )

    assert result.text == "Process: Revised wave step with particle oscillators."
    assert result.returned_model == "gemini-2.5-flash-v1"
    assert result.provider_response_id == "resp-gem-1"


@pytest.mark.asyncio
async def test_glm_adapter_fixture():
    descriptor = {
        "id": "glm_api",
        "model": "glm-4-plus",
        "credential_ref": "GLM_API_KEY",
    }
    template_bytes = "System template for GLM"
    user_payload = {"section_ref": "sec-2"}

    mock_resp = {
        "id": "glm-resp-1",
        "model": "glm-4-plus",
        "choices": [
            {
                "finish_reason": "stop",
                "message": {
                    "role": "assistant",
                    "content": "Comparison: Comparing longitudinal and transverse waves.",
                },
            }
        ],
    }

    def handler(request: httpx.Request):
        assert "api.z.ai" in str(request.url)
        assert request.headers.get("Authorization") == "Bearer mock-glm-key"
        body = json.loads(request.content.decode("utf-8"))
        assert body["messages"][0]["content"] == template_bytes
        assert body["stream"] is False
        return httpx.Response(200, json=mock_resp)

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        import os
        os.environ["GLM_API_KEY"] = "mock-glm-key"
        result = await FeedbackTextAdapter.revise(
            descriptor=descriptor,
            template_bytes=template_bytes,
            user_payload=user_payload,
            effect_ref="eff-2",
            client=client,
        )

    assert result.text == "Comparison: Comparing longitudinal and transverse waves."
    assert result.returned_model == "glm-4-plus"
    assert result.provider_response_id == "glm-resp-1"


@pytest.mark.asyncio
async def test_deepseek_adapter_fixture():
    descriptor = {
        "id": "deepseek_api",
        "model": "deepseek-chat",
        "credential_ref": "DEEPSEEK_API_KEY",
    }
    template_bytes = "System template for DeepSeek"
    user_payload = {"section_ref": "sec-3"}

    mock_resp = {
        "id": "ds-resp-1",
        "model": "deepseek-chat",
        "choices": [
            {
                "finish_reason": "stop",
                "message": {
                    "role": "assistant",
                    "content": "Accumulation: Total energy builds as wave front advances.",
                },
            }
        ],
    }

    def handler(request: httpx.Request):
        assert "api.deepseek.com" in str(request.url)
        assert request.headers.get("Authorization") == "Bearer mock-ds-key"
        return httpx.Response(200, json=mock_resp)

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        import os
        os.environ["DEEPSEEK_API_KEY"] = "mock-ds-key"
        result = await FeedbackTextAdapter.revise(
            descriptor=descriptor,
            template_bytes=template_bytes,
            user_payload=user_payload,
            effect_ref="eff-3",
            client=client,
        )

    assert result.text == "Accumulation: Total energy builds as wave front advances."
    assert result.returned_model == "deepseek-chat"
    assert result.provider_response_id == "ds-resp-1"


@pytest.mark.asyncio
async def test_adapter_rejects_invalid_scene_type_or_finish_reason():
    descriptor = {"id": "gemini_api", "model": "gemini-2.5-flash", "credential_ref": "GEMINI_API_KEY"}

    # 1. Non-STOP finish reason
    mock_safety = {
        "candidates": [{"content": {"parts": [{"text": "Process: text"}]}, "finishReason": "SAFETY"}],
        "modelVersion": "gemini-2.5-flash",
    }
    transport = httpx.MockTransport(lambda req: httpx.Response(200, json=mock_safety))
    async with httpx.AsyncClient(transport=transport) as client:
        import os
        os.environ["GEMINI_API_KEY"] = "key"
        with pytest.raises(ValueError, match="finishReason"):
            await FeedbackTextAdapter.revise(descriptor, "t", {}, "eff", client=client)

    # 2. Invalid scene type
    mock_bad_scene = {
        "candidates": [{"content": {"parts": [{"text": "InvalidType: Some description"}]}, "finishReason": "STOP"}],
        "modelVersion": "gemini-2.5-flash",
    }
    transport2 = httpx.MockTransport(lambda req: httpx.Response(200, json=mock_bad_scene))
    async with httpx.AsyncClient(transport=transport2) as client:
        with pytest.raises(ValueError, match="allowed scene type"):
            await FeedbackTextAdapter.revise(descriptor, "t", {}, "eff", client=client)
