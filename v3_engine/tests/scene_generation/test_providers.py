"""Tests for AI provider wire contracts and normalization (Spec 017)."""

from __future__ import annotations

import json
from typing import Any
import httpx
import pytest

from v3_engine.scene_generation.config import load_provider_config
from v3_engine.scene_generation.contracts import (
    ProviderCheckResult,
    ProviderResponse,
    SceneGenerationRequest,
)
from v3_engine.scene_generation.providers import create_provider
from v3_engine.scene_generation.providers import decode_generation_response
from v3_engine.scene_generation.providers import MAX_RESPONSE_BODY_BYTES

SAMPLE_REQUEST = SceneGenerationRequest(
    system_text="SYSTEM PROMPT INSTRUCTIONS",
    prompt="Scene Type: Comparison | Two particles in motion.",
    output_contract="OUTPUT CONTRACT DETAILS",
    host_context={"entrypoint": "src/Scene.tsx"},
)


@pytest.mark.parametrize("payload", [b"[]", b"null", b'{"candidates":[null]}', b'{"candidates":[{"content":null,"finishReason":"STOP"}]}' ])
def test_decoder_rejects_malformed_gemini_shapes_without_throwing(payload: bytes) -> None:
    response = decode_generation_response("gemini", "m", payload, 200, "receipt")
    assert response.outcome == "failed"
    assert response.safe_code == "provider_response"


@pytest.mark.parametrize("payload", [b'{"choices":[null]}', b'{"choices":[{"message":null,"finish_reason":"stop"}]}', b'{"choices":[{"message":{"content":[]},"finish_reason":"stop"}]}'])
def test_decoder_rejects_malformed_chat_shapes_without_throwing(payload: bytes) -> None:
    response = decode_generation_response("glm", "m", payload, 200, "receipt")
    assert response.outcome == "failed"
    assert response.safe_code == "provider_response"


def test_decoder_does_not_claim_requested_model_was_reported_by_provider() -> None:
    payload = json.dumps({
        "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": "{}"}]}}],
    }).encode()
    response = decode_generation_response("gemini", "requested-model", payload, 200, "receipt")
    assert response.outcome == "complete"
    assert response.returned_model is None
    assert response.model_reported is False


@pytest.mark.asyncio
async def test_oversized_response_is_capped_before_receipt_persistence() -> None:
    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, content=b"x" * (MAX_RESPONSE_BODY_BYTES + 1024))

    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    provider = create_provider(cfg, client=client)
    captured: list[tuple[bytes, dict[str, Any]]] = []
    async def sink(body: bytes, meta: dict[str, Any]) -> str:
        captured.append((body, meta)); return "receipt"
    try:
        response = await provider.generate_scene(SAMPLE_REQUEST, receipt_sink=sink)
    finally:
        await provider.aclose()
    assert response.safe_code == "provider_response_oversize"
    assert len(captured[0][0]) == MAX_RESPONSE_BODY_BYTES
    assert captured[0][1]["complete"] is False and captured[0][1]["truncated"] is True


@pytest.mark.asyncio
async def test_stream_read_failure_retains_received_prefix_as_incomplete_receipt() -> None:
    class BrokenStream(httpx.AsyncByteStream):
        async def __aiter__(self):
            yield b'{"candidates":'
            raise httpx.ReadError("connection dropped")
        async def aclose(self) -> None:
            return None

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, stream=BrokenStream())

    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    provider = create_provider(cfg, client=client)
    captured: list[tuple[bytes, dict[str, Any]]] = []
    async def sink(body: bytes, meta: dict[str, Any]) -> str:
        captured.append((body, meta)); return "receipt"
    try:
        response = await provider.generate_scene(SAMPLE_REQUEST, receipt_sink=sink)
    finally:
        await provider.aclose()
    assert response.outcome == "uncertain"
    assert captured[0][0] == b'{"candidates":'
    assert captured[0][1]["http_status"] == 200
    assert captured[0][1]["complete"] is False


@pytest.mark.asyncio
async def test_gemini_generation_wire_and_multipart_text() -> None:
    captured_request: dict[str, Any] = {}
    receipt_log: list[tuple[bytes, dict[str, Any]]] = []

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        captured_request["url"] = str(request.url)
        captured_request["headers"] = dict(request.headers)
        captured_request["body"] = json.loads(request.read())

        payload = {
            "modelVersion": "gemini-2.5-flash",
            "responseId": "resp-12345",
            "candidates": [
                {
                    "finishReason": "STOP",
                    "content": {
                        "parts": [
                            {"text": "{\"schema_version\":1,"},
                            {"text": "ignoring internal thought", "thought": True},
                            {"text": "\"files\":[]}"},
                        ]
                    },
                }
            ],
            "usageMetadata": {
                "promptTokenCount": 150,
                "candidatesTokenCount": 45,
            },
        }
        return httpx.Response(200, json=payload)

    async def receipt_sink(body: bytes, meta: dict[str, Any]) -> str:
        receipt_log.append((body, meta))
        return "attempt-0001/response-receipt.json"

    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "test-gemini-key"})
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    provider = create_provider(cfg, client=client)

    try:
        resp = await provider.generate_scene(SAMPLE_REQUEST, receipt_sink=receipt_sink)
    finally:
        await provider.aclose()

    assert captured_request["url"] == "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent"
    assert captured_request["headers"]["x-goog-api-key"] == "test-gemini-key"
    assert "systemInstruction" in captured_request["body"]
    generation_config = captured_request["body"]["generationConfig"]
    assert generation_config["maxOutputTokens"] == 65536
    assert generation_config["thinkingConfig"] == {"thinkingBudget": 24576}
    assert generation_config["responseMimeType"] == "application/json"
    assert generation_config["responseSchema"]["required"] == ["schema_version", "files"]
    assert generation_config["responseSchema"]["properties"]["files"]["minItems"] == 3
    assert generation_config["responseSchema"]["properties"]["files"]["maxItems"] == 3
    assert set(generation_config["responseSchema"]["properties"]["files"]["items"]["properties"]["path"]["enum"]) == {
        "index.html", "styles.css", "script.js",
    }
    assert generation_config["responseSchema"]["properties"]["files"]["items"]["required"] == ["path", "content"]

    # Multipart joined, thought excluded
    assert resp.outcome == "complete"
    assert resp.safe_code == "ok"
    assert resp.text == "{\"schema_version\":1,\"files\":[]}"
    assert resp.response_id == "resp-12345"
    assert resp.returned_model == "gemini-2.5-flash"
    assert resp.usage == {"input_tokens": 150, "output_tokens": 45}
    assert resp.raw_receipt_ref == "attempt-0001/response-receipt.json"
    assert len(receipt_log) == 1
    assert receipt_log[0][1]["http_status"] == 200
    assert resp.duration_ms is not None and resp.duration_ms >= 0


@pytest.mark.asyncio
async def test_gemini_check_wire() -> None:
    async def mock_ok_handler(request: httpx.Request) -> httpx.Response:
        assert "x-goog-api-key" in request.headers
        body = json.loads(request.read())
        # Check system prompt and user prompt
        assert body["systemInstruction"]["parts"][0]["text"] == "Respond briefly."
        assert body["contents"][0]["parts"][0]["text"] == "Reply exactly: V3_PROVIDER_OK"
        return httpx.Response(200, json={
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": " V3_PROVIDER_OK \n"}]}}]
        })

    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_ok_handler))
    provider = create_provider(cfg, client=client)
    try:
        check = await provider.check_connection()
        assert check.passed is True
        assert check.safe_code == "ok"
    finally:
        await provider.aclose()


@pytest.mark.asyncio
@pytest.mark.parametrize("provider_name,body", [
    ("gemini", {"candidates": [{"finishReason": "MAX_TOKENS", "content": {"parts": [{"text": "V3_PROVIDER_OK"}]}}]}),
    ("deepseek", {"choices": [{"finish_reason": "length", "message": {"content": "V3_PROVIDER_OK"}}]}),
])
async def test_provider_check_rejects_truncated_or_multiple_choices(provider_name: str, body: dict[str, Any]) -> None:
    async def mock_handler(request: httpx.Request) -> httpx.Response:
        if provider_name == "gemini":
            body["candidates"].append(body["candidates"][0])
        else:
            body["choices"].append(body["choices"][0])
        return httpx.Response(200, json=body)

    env = {"GEMINI_API_KEY": "key"} if provider_name == "gemini" else {"DEEPSEEK_API_KEY": "key"}
    model = "gemini-2.5-flash" if provider_name == "gemini" else "deepseek-chat"
    cfg = load_provider_config(provider_name, model=model, env=env)
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    provider = create_provider(cfg, client=client)
    try:
        check = await provider.check_connection()
    finally:
        await provider.aclose()
    assert check.passed is False


@pytest.mark.asyncio
async def test_chat_provider_wire_and_reasoning_exclusion() -> None:
    captured_request: dict[str, Any] = {}

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        captured_request["url"] = str(request.url)
        captured_request["headers"] = dict(request.headers)
        captured_request["body"] = json.loads(request.read())

        payload = {
            "id": "chat-9876",
            "model": "deepseek-chat",
            "choices": [
                {
                    "finish_reason": "stop",
                    "message": {
                        "role": "assistant",
                        "content": "{\"schema_version\":1,\"files\":[{\"path\":\"src/Scene.tsx\"}]}",
                        "reasoning_content": "This internal reasoning must not be used as output code.",
                    },
                }
            ],
            "usage": {
                "prompt_tokens": 120,
                "completion_tokens": 80,
            },
        }
        return httpx.Response(200, json=payload)

    async def receipt_sink(body: bytes, meta: dict[str, Any]) -> str:
        return "ref"

    cfg = load_provider_config("deepseek", env={"DEEPSEEK_API_KEY": "ds-secret", "DEEPSEEK_MODEL": "deepseek-chat"})
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    provider = create_provider(cfg, client=client)

    try:
        resp = await provider.generate_scene(SAMPLE_REQUEST, receipt_sink=receipt_sink)
    finally:
        await provider.aclose()

    assert captured_request["url"] == "https://api.deepseek.com/chat/completions"
    assert captured_request["headers"]["authorization"] == "Bearer ds-secret"
    assert captured_request["body"]["model"] == "deepseek-chat"
    assert captured_request["body"]["stream"] is False

    assert resp.outcome == "complete"
    assert resp.safe_code == "ok"
    # Never substituted reasoning_content for content
    assert resp.text == "{\"schema_version\":1,\"files\":[{\"path\":\"src/Scene.tsx\"}]}"
    assert resp.response_id == "chat-9876"
    assert resp.usage == {"input_tokens": 120, "output_tokens": 80}


@pytest.mark.asyncio
async def test_qwen_endpoint_wire() -> None:
    captured_url: list[str] = []

    async def mock_handler(request: httpx.Request) -> httpx.Response:
        captured_url.append(str(request.url))
        return httpx.Response(200, json={
            "id": "qwen-1",
            "model": "qwen-plus",
            "choices": [{"finish_reason": "stop", "message": {"content": "V3_PROVIDER_OK"}}],
        })

    env = {
        "QWEN_API_KEY": "qwen-key",
        "QWEN_MODEL": "qwen-plus",
        "QWEN_BASE_URL": "https://dashscope-intl.aliyuncs.com/compatible-mode/v1",
    }
    cfg = load_provider_config("qwen", env=env)
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    provider = create_provider(cfg, client=client)
    try:
        check = await provider.check_connection()
        assert check.passed is True
        assert captured_url[0] == "https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions"
    finally:
        await provider.aclose()


@pytest.mark.asyncio
@pytest.mark.parametrize(
    "status_code,expected_outcome,expected_code",
    [
        (401, "failed", "provider_auth"),
        (403, "failed", "provider_auth"),
        (429, "failed", "provider_rate_limit"),
        (400, "failed", "provider_request"),
        (500, "uncertain", "provider_uncertain"),
        (503, "uncertain", "provider_uncertain"),
    ],
)
async def test_http_error_outcomes(
    status_code: int, expected_outcome: str, expected_code: str
) -> None:
    async def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(status_code, text="Error payload")

    async def receipt_sink(body: bytes, meta: dict[str, Any]) -> str:
        return "err-receipt"

    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    provider = create_provider(cfg, client=client)

    try:
        resp = await provider.generate_scene(SAMPLE_REQUEST, receipt_sink=receipt_sink)
    finally:
        await provider.aclose()

    assert resp.outcome == expected_outcome
    assert resp.safe_code == expected_code
    assert resp.http_status == status_code


@pytest.mark.asyncio
async def test_timeout_results_in_uncertain() -> None:
    async def mock_handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("Timeout waiting for provider")

    async def receipt_sink(body: bytes, meta: dict[str, Any]) -> str:
        return "timeout-receipt"

    cfg = load_provider_config("glm", env={"GLM_API_KEY": "k", "GLM_MODEL": "glm-4-plus"})
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    provider = create_provider(cfg, client=client)

    try:
        resp = await provider.generate_scene(SAMPLE_REQUEST, receipt_sink=receipt_sink)
    finally:
        await provider.aclose()

    assert resp.outcome == "uncertain"
    assert resp.safe_code == "provider_uncertain"


@pytest.mark.asyncio
async def test_connect_error_results_in_connection_failure() -> None:
    async def mock_handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("Failed to connect")

    async def receipt_sink(body: bytes, meta: dict[str, Any]) -> str:
        return "connect-receipt"

    cfg = load_provider_config("deepseek", env={"DEEPSEEK_API_KEY": "k", "DEEPSEEK_MODEL": "m"})
    client = httpx.AsyncClient(transport=httpx.MockTransport(mock_handler))
    provider = create_provider(cfg, client=client)

    try:
        resp = await provider.generate_scene(SAMPLE_REQUEST, receipt_sink=receipt_sink)
    finally:
        await provider.aclose()

    assert resp.outcome == "failed"
    assert resp.safe_code == "provider_connection"
