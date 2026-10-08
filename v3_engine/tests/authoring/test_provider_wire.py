import pytest
import json
import httpx
from v3_engine.authoring.contracts import OUTPUT_CONTRACT
from v3_engine.authoring.providers import (
    call_gemini_authoring,
    call_glm_authoring,
    call_deepseek_authoring,
    format_user_payload,
)


def test_format_user_payload():
    prompt = "Simulate transverse wave motion with Three.js."
    payload = format_user_payload(prompt)

    assert set(payload.keys()) == {"schema_version", "prompt", "output_contract"}
    assert payload["schema_version"] == 1
    assert payload["prompt"] == prompt
    assert payload["output_contract"] == OUTPUT_CONTRACT


@pytest.mark.asyncio
async def test_gemini_official_shape_fixture():
    package_obj = {
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<!DOCTYPE html><html><body><canvas></canvas></body></html>"}
        ]
    }
    fixture_response = {
        "candidates": [
            {
                "content": {
                    "parts": [{"text": json.dumps(package_obj)}],
                    "role": "model"
                },
                "finishReason": "STOP"
            }
        ],
        "modelVersion": "gemini-2.5-pro",
        "responseId": "gemini-resp-1",
        "usageMetadata": {
            "promptTokenCount": 120,
            "candidatesTokenCount": 350
        }
    }

    async def mock_handler(request: httpx.Request):
        assert request.url.path == "/v1beta/models/gemini-2.5-pro:generateContent"
        assert request.headers["x-goog-api-key"] == "test-gemini-key"
        body = json.loads(request.content.decode("utf-8"))
        assert body["systemInstruction"]["parts"][0]["text"] == "Scene Template Text"
        user_msg = json.loads(body["contents"][0]["parts"][0]["text"])
        assert user_msg["output_contract"] == OUTPUT_CONTRACT
        return httpx.Response(200, json=fixture_response)

    transport = httpx.MockTransport(mock_handler)
    async with httpx.AsyncClient(transport=transport) as client:
        text, model, resp_id, raw_json, usage = await call_gemini_authoring(
            model="gemini-2.5-pro",
            api_key="test-gemini-key",
            template_text="Scene Template Text",
            prompt="Wave simulation prompt",
            client=client,
        )

    assert model == "gemini-2.5-pro"
    assert resp_id == "gemini-resp-1"
    assert json.loads(text) == package_obj
    assert usage["input_tokens"] == 120
    assert usage["output_tokens"] == 350


@pytest.mark.asyncio
async def test_glm_official_shape_fixture():
    package_obj = {
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<!DOCTYPE html><html><body>GLM scene</body></html>"}
        ]
    }
    fixture_response = {
        "id": "glm-resp-1",
        "model": "glm-4-plus",
        "choices": [
            {
                "finish_reason": "stop",
                "message": {
                    "role": "assistant",
                    "content": json.dumps(package_obj)
                }
            }
        ],
        "usage": {
            "prompt_tokens": 150,
            "completion_tokens": 400
        }
    }

    async def mock_handler(request: httpx.Request):
        assert request.url.path == "/api/paas/v4/chat/completions"
        assert request.headers["authorization"] == "Bearer test-glm-key"
        body = json.loads(request.content.decode("utf-8"))
        assert body["messages"][0]["role"] == "system"
        assert body["messages"][0]["content"] == "Scene Template Text"
        user_msg = json.loads(body["messages"][1]["content"])
        assert user_msg["output_contract"] == OUTPUT_CONTRACT
        return httpx.Response(200, json=fixture_response)

    transport = httpx.MockTransport(mock_handler)
    async with httpx.AsyncClient(transport=transport) as client:
        text, model, resp_id, raw_json, usage = await call_glm_authoring(
            model="glm-4-plus",
            api_key="test-glm-key",
            template_text="Scene Template Text",
            prompt="Wave simulation prompt",
            client=client,
        )

    assert model == "glm-4-plus"
    assert resp_id == "glm-resp-1"
    assert json.loads(text) == package_obj
    assert usage["input_tokens"] == 150
    assert usage["output_tokens"] == 400


@pytest.mark.asyncio
async def test_deepseek_official_shape_fixture():
    package_obj = {
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<!DOCTYPE html><html><body>DeepSeek scene</body></html>"}
        ]
    }
    fixture_response = {
        "id": "deepseek-resp-1",
        "model": "deepseek-coder",
        "choices": [
            {
                "finish_reason": "stop",
                "message": {
                    "role": "assistant",
                    "content": json.dumps(package_obj)
                }
            }
        ],
        "usage": {
            "prompt_tokens": 140,
            "completion_tokens": 420
        }
    }

    async def mock_handler(request: httpx.Request):
        assert request.url.path == "/chat/completions"
        assert request.headers["authorization"] == "Bearer test-deepseek-key"
        body = json.loads(request.content.decode("utf-8"))
        assert body["messages"][0]["role"] == "system"
        assert body["messages"][0]["content"] == "Scene Template Text"
        user_msg = json.loads(body["messages"][1]["content"])
        assert user_msg["output_contract"] == OUTPUT_CONTRACT
        return httpx.Response(200, json=fixture_response)

    transport = httpx.MockTransport(mock_handler)
    async with httpx.AsyncClient(transport=transport) as client:
        text, model, resp_id, raw_json, usage = await call_deepseek_authoring(
            model="deepseek-coder",
            api_key="test-deepseek-key",
            template_text="Scene Template Text",
            prompt="Wave simulation prompt",
            client=client,
        )

    assert model == "deepseek-coder"
    assert resp_id == "deepseek-resp-1"
    assert json.loads(text) == package_obj
    assert usage["input_tokens"] == 140
    assert usage["output_tokens"] == 420


@pytest.mark.asyncio
async def test_provider_rejects_truncated_or_filtered_output():
    # Gemini filtered response
    filtered_gemini = {
        "candidates": [
            {
                "finishReason": "SAFETY"
            }
        ]
    }
    transport = httpx.MockTransport(lambda req: httpx.Response(200, json=filtered_gemini))
    async with httpx.AsyncClient(transport=transport) as client:
        with pytest.raises(ValueError, match="finishReason"):
            await call_gemini_authoring(
                model="gemini-2.5-pro",
                api_key="key",
                template_text="tmpl",
                prompt="prompt",
                client=client,
            )

    # DeepSeek truncated response (finish_reason = length)
    truncated_deepseek = {
        "id": "ds-1",
        "model": "deepseek-coder",
        "choices": [
            {
                "finish_reason": "length",
                "message": {"role": "assistant", "content": "incomplete"}
            }
        ]
    }
    transport2 = httpx.MockTransport(lambda req: httpx.Response(200, json=truncated_deepseek))
    async with httpx.AsyncClient(transport=transport2) as client:
        with pytest.raises(ValueError, match="finish_reason"):
            await call_deepseek_authoring(
                model="deepseek-coder",
                api_key="key",
                template_text="tmpl",
                prompt="prompt",
                client=client,
            )
