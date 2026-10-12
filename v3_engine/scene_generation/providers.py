"""Provider adapters and factory for terminal scene generation (Spec 017)."""

from __future__ import annotations

from datetime import datetime, timezone
import json
import time
from typing import Any, Mapping, Optional
import httpx

from v3_engine.scene_generation.contracts import (
    AIProvider,
    ProviderCheckResult,
    ProviderConfig,
    ProviderResponse,
    RawReceiptSink,
    SceneGenerationRequest,
)
MAX_RESPONSE_BODY_BYTES = 8 * 1024 * 1024  # 8 MiB

GEMINI_SCENE_RESPONSE_SCHEMA = {
    "type": "OBJECT",
    "properties": {
        "schema_version": {"type": "INTEGER"},
        "files": {
            "type": "ARRAY",
            "minItems": 3,
            "maxItems": 3,
            "items": {
                "type": "OBJECT",
                "properties": {
                    "path": {"type": "STRING", "enum": ["index.html", "styles.css", "script.js"]},
                    "content": {"type": "STRING"},
                },
                "required": ["path", "content"],
                "propertyOrdering": ["path", "content"],
            },
        },
    },
    "required": ["schema_version", "files"],
    "propertyOrdering": ["schema_version", "files"],
}


def _rfc3339_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _format_user_payload(request: SceneGenerationRequest) -> str:
    return json.dumps(
        {
            "schema_version": 1,
            "prompt": request.prompt,
            "output_contract": request.output_contract,
            "host_context": request.host_context,
        },
        ensure_ascii=False,
    )


def _map_http_error(status_code: int) -> tuple[str, str]:
    if status_code in (401, 403):
        return "failed", "provider_auth"
    if status_code == 429:
        return "failed", "provider_rate_limit"
    if 400 <= status_code < 500:
        return "failed", "provider_request"
    if status_code >= 500:
        return "uncertain", "provider_uncertain"
    return "failed", "provider_response"


async def _bounded_post(client: httpx.AsyncClient, url: str, **kwargs: Any) -> httpx.Response:
    """Read at most MAX_RESPONSE_BODY_BYTES from a response and always close it."""
    request = client.build_request("POST", url, **kwargs)
    response = await client.send(request, stream=True)
    body = bytearray()
    truncated = False
    try:
        async for chunk in response.aiter_bytes():
            remaining = MAX_RESPONSE_BODY_BYTES - len(body)
            if len(chunk) > remaining:
                body.extend(chunk[:max(remaining, 0)])
                truncated = True
                break
            body.extend(chunk)
    finally:
        status = response.status_code
        await response.aclose()
    headers = dict(response.headers)
    if truncated:
        headers["x-v3-response-truncated"] = "true"
    return httpx.Response(status, headers=headers, content=bytes(body), request=request)


def decode_generation_response(
    provider: str,
    requested_model: str,
    raw_bytes: bytes,
    http_status: Optional[int],
    receipt_ref: Optional[str],
    *,
    started_at: Optional[str] = None,
    finished_at: Optional[str] = None,
    duration_ms: Optional[int] = None,
) -> ProviderResponse:
    """Normalize a complete retained provider body without performing I/O."""
    common = {
        "http_status": http_status,
        "requested_model": requested_model,
        "raw_receipt_ref": receipt_ref,
        "request_started_at": started_at,
        "request_finished_at": finished_at,
        "duration_ms": duration_ms,
    }
    if http_status != 200:
        if http_status is None:
            return ProviderResponse(outcome="uncertain", safe_code="provider_uncertain", **common)
        outcome, code = _map_http_error(http_status)
        return ProviderResponse(outcome=outcome, safe_code=code, **common)
    try:
        data = json.loads(raw_bytes.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return ProviderResponse(outcome="failed", safe_code="provider_response", **common)
    if not isinstance(data, dict):
        return ProviderResponse(outcome="failed", safe_code="provider_response", **common)

    if provider == "gemini":
        entries = data.get("candidates")
        if not isinstance(entries, list) or len(entries) != 1 or not isinstance(entries[0], dict):
            return ProviderResponse(outcome="failed", safe_code="provider_response", **common)
        candidate = entries[0]
        finish = candidate.get("finishReason")
        content = candidate.get("content")
        if finish != "STOP" or not isinstance(content, dict):
            return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish if isinstance(finish, str) else None, **common)
        parts = content.get("parts")
        if not isinstance(parts, list) or any(not isinstance(part, dict) for part in parts):
            return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish, **common)
        text_parts: list[str] = []
        for part in parts:
            thought = part.get("thought", False)
            if not isinstance(thought, bool):
                return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish, **common)
            if "text" in part:
                if not isinstance(part["text"], str):
                    return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish, **common)
                if not thought:
                    text_parts.append(part["text"])
        text = "".join(text_parts)
        returned = data.get("modelVersion")
        response_id = data.get("responseId")
        usage_meta = data.get("usageMetadata", {})
        usage_keys = ("promptTokenCount", "candidatesTokenCount")
        normalized_usage_keys = ("input_tokens", "output_tokens")
    elif provider in {"glm", "deepseek", "qwen"}:
        entries = data.get("choices")
        if not isinstance(entries, list) or len(entries) != 1 or not isinstance(entries[0], dict):
            return ProviderResponse(outcome="failed", safe_code="provider_response", **common)
        choice = entries[0]
        finish = choice.get("finish_reason")
        message = choice.get("message")
        if finish != "stop" or not isinstance(message, dict) or not isinstance(message.get("content"), str):
            return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish if isinstance(finish, str) else None, **common)
        text = message["content"]
        returned = data.get("model")
        response_id = data.get("id")
        usage_meta = data.get("usage", {})
        usage_keys = ("prompt_tokens", "completion_tokens")
        normalized_usage_keys = ("input_tokens", "output_tokens")
    else:
        return ProviderResponse(outcome="failed", safe_code="provider_response", **common)

    if not text.strip():
        return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish, **common)
    if returned is not None and (not isinstance(returned, str) or not returned.strip()):
        return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish, **common)
    if response_id is not None and not isinstance(response_id, str):
        return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish, **common)
    if usage_meta is None:
        usage_meta = {}
    if not isinstance(usage_meta, dict):
        return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish, **common)
    usage: dict[str, Optional[int]] = {}
    for source_key, target_key in zip(usage_keys, normalized_usage_keys):
        value = usage_meta.get(source_key)
        if value is not None and (isinstance(value, bool) or not isinstance(value, int) or value < 0):
            return ProviderResponse(outcome="failed", safe_code="provider_response", finish_reason=finish, **common)
        usage[target_key] = value

    if provider == "gemini":
        model_reported = isinstance(returned, str)
        response_id = response_id if response_id else None
    else:
        model_reported = isinstance(returned, str)
    return ProviderResponse(
        outcome="complete",
        safe_code="ok",
        # Do not claim the provider confirmed the requested model when the
        # response omitted its model identity. The request remains recorded
        # separately as requested_model.
        returned_model=returned,
        model_reported=model_reported,
        response_id=response_id,
        finish_reason=finish,
        text=text,
        usage=usage,
        **common,
    )


class BaseAIProvider(AIProvider):
    """Base class for scene generation providers handling client lifecycle."""

    def __init__(self, config: ProviderConfig, client: Optional[httpx.AsyncClient] = None) -> None:
        self.config = config
        self._owns_client = client is None
        self._client = client or httpx.AsyncClient(timeout=float(config.request_timeout))

    async def aclose(self) -> None:
        if self._owns_client and self._client is not None:
            await self._client.aclose()


class GeminiProvider(BaseAIProvider):
    """Adapter for Google Gemini generateContent API."""

    async def check_connection(self) -> ProviderCheckResult:
        payload = {
            "systemInstruction": {"parts": [{"text": "Respond briefly."}]},
            "contents": [{"role": "user", "parts": [{"text": "Reply exactly: V3_PROVIDER_OK"}]}],
            "generationConfig": {"maxOutputTokens": 1024},
        }
        headers = {
            "x-goog-api-key": self.config.api_key,
            "Content-Type": "application/json",
            "Accept": "application/json",
        }
        try:
            resp = await _bounded_post(
                self._client,
                self.config.endpoint, json=payload, headers=headers, timeout=30.0
            )
        except httpx.TimeoutException:
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_timeout",
                requested_model=self.config.requested_model,
            )
        except httpx.ConnectError:
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_connection",
                requested_model=self.config.requested_model,
            )
        except httpx.RequestError:
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_check_failed",
                requested_model=self.config.requested_model,
            )

        if resp.status_code != 200:
            _, safe_code = _map_http_error(resp.status_code)
            return ProviderCheckResult(
                passed=False,
                safe_code=safe_code,
                requested_model=self.config.requested_model,
                http_status=resp.status_code,
            )
        if resp.headers.get("x-v3-response-truncated") == "true":
            return ProviderCheckResult(False, "provider_check_failed", self.config.requested_model, http_status=200)

        try:
            data = resp.json()
            candidates = data.get("candidates") if isinstance(data, dict) else None
            if not isinstance(candidates, list) or len(candidates) != 1 or not isinstance(candidates[0], dict):
                return ProviderCheckResult(
                    passed=False,
                    safe_code="provider_check_failed",
                    requested_model=self.config.requested_model,
                    http_status=200,
                )
            content = candidates[0].get("content")
            parts = content.get("parts") if isinstance(content, dict) else None
            if candidates[0].get("finishReason") != "STOP" or not isinstance(parts, list) or any(not isinstance(p, dict) for p in parts):
                return ProviderCheckResult(False, "provider_check_failed", self.config.requested_model, http_status=200)
            text_parts = [p.get("text", "") for p in parts if p.get("thought") is not True and isinstance(p.get("text", ""), str)]
            full_text = "".join(text_parts).strip()
            returned_model = data.get("modelVersion") or self.config.requested_model

            if full_text == "V3_PROVIDER_OK":
                return ProviderCheckResult(
                    passed=True,
                    safe_code="ok",
                    requested_model=self.config.requested_model,
                    returned_model=returned_model,
                    http_status=200,
                )
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_check_failed",
                requested_model=self.config.requested_model,
                returned_model=returned_model,
                http_status=200,
            )
        except Exception:
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_check_failed",
                requested_model=self.config.requested_model,
                http_status=200,
            )

    async def generate_scene(
        self, request: SceneGenerationRequest, *, receipt_sink: RawReceiptSink
    ) -> ProviderResponse:
        user_json_str = _format_user_payload(request)
        payload = {
            "systemInstruction": {"parts": [{"text": request.system_text}]},
            "contents": [{"role": "user", "parts": [{"text": user_json_str}]}],
            "generationConfig": {
                "maxOutputTokens": self.config.gemini_max_output_tokens,
                # Keep reasoning enabled for interactive educational scenes.
                # Both thinking and visible output consume this model limit.
                "thinkingConfig": {"thinkingBudget": self.config.gemini_thinking_budget},
                "responseMimeType": "application/json",
                "responseSchema": GEMINI_SCENE_RESPONSE_SCHEMA,
            },
        }
        headers = {
            "x-goog-api-key": self.config.api_key,
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

        start_wall = _rfc3339_now()
        start_mono = time.monotonic()

        raw_buffer = bytearray()
        response_status: Optional[int] = None
        truncated = False
        resp: Optional[httpx.Response] = None
        try:
            request = self._client.build_request("POST", self.config.endpoint, json=payload, headers=headers, timeout=float(self.config.request_timeout))
            resp = await self._client.send(request, stream=True)
            response_status = resp.status_code
            async for chunk in resp.aiter_bytes():
                remaining = MAX_RESPONSE_BODY_BYTES - len(raw_buffer)
                if len(chunk) > remaining:
                    raw_buffer.extend(chunk[:max(0, remaining)])
                    truncated = True
                    break
                raw_buffer.extend(chunk)
            raw_bytes = bytes(raw_buffer)
            if truncated:
                await resp.aclose()
        except httpx.TimeoutException:
            if resp is not None:
                await resp.aclose()
            finish_wall = _rfc3339_now()
            duration_ms = int((time.monotonic() - start_mono) * 1000)
            receipt_ref = await receipt_sink(bytes(raw_buffer), {"http_status": response_status, "complete": False, "truncated": truncated})
            return ProviderResponse(
                outcome="uncertain",
                safe_code="provider_uncertain",
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )
        except httpx.ConnectError:
            if resp is not None:
                await resp.aclose()
            finish_wall = _rfc3339_now()
            duration_ms = int((time.monotonic() - start_mono) * 1000)
            receipt_ref = await receipt_sink(bytes(raw_buffer), {"http_status": response_status, "complete": False, "truncated": truncated})
            return ProviderResponse(
                outcome="failed",
                safe_code="provider_connection",
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )
        except httpx.RequestError:
            if resp is not None:
                await resp.aclose()
            finish_wall = _rfc3339_now()
            duration_ms = int((time.monotonic() - start_mono) * 1000)
            receipt_ref = await receipt_sink(bytes(raw_buffer), {"http_status": response_status, "complete": False, "truncated": truncated})
            return ProviderResponse(
                outcome="uncertain",
                safe_code="provider_uncertain",
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )

        finish_wall = _rfc3339_now()
        duration_ms = int((time.monotonic() - start_mono) * 1000)

        # Check body size limit
        complete = not truncated
        if len(raw_bytes) > MAX_RESPONSE_BODY_BYTES:
            raw_bytes = raw_bytes[:MAX_RESPONSE_BODY_BYTES]
            truncated = True
            complete = False

        receipt_meta = {
            "http_status": resp.status_code,
            "complete": complete,
            "truncated": truncated,
        }
        receipt_ref = await receipt_sink(raw_bytes, receipt_meta)

        if truncated:
            return ProviderResponse(
                outcome="failed",
                safe_code="provider_response_oversize",
                http_status=resp.status_code,
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )

        if resp.status_code != 200:
            outcome, safe_code = _map_http_error(resp.status_code)
            return ProviderResponse(
                outcome=outcome,
                safe_code=safe_code,
                http_status=resp.status_code,
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )

        return decode_generation_response(
            "gemini", self.config.requested_model, raw_bytes, resp.status_code, receipt_ref,
            started_at=start_wall, finished_at=finish_wall, duration_ms=duration_ms,
        )


class BaseChatProvider(BaseAIProvider):
    """Adapter for OpenAI-compatible Chat completion APIs (GLM, DeepSeek, Qwen)."""

    async def check_connection(self) -> ProviderCheckResult:
        payload = {
            "model": self.config.requested_model,
            "messages": [
                {"role": "system", "content": "Respond briefly."},
                {"role": "user", "content": "Reply exactly: V3_PROVIDER_OK"},
            ],
            "max_tokens": 128,
            "stream": False,
        }
        headers = {
            "Authorization": f"Bearer {self.config.api_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }
        try:
            resp = await _bounded_post(
                self._client,
                self.config.endpoint, json=payload, headers=headers, timeout=30.0
            )
        except httpx.TimeoutException:
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_timeout",
                requested_model=self.config.requested_model,
            )
        except httpx.ConnectError:
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_connection",
                requested_model=self.config.requested_model,
            )
        except httpx.RequestError:
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_check_failed",
                requested_model=self.config.requested_model,
            )

        if resp.status_code != 200:
            _, safe_code = _map_http_error(resp.status_code)
            return ProviderCheckResult(
                passed=False,
                safe_code=safe_code,
                requested_model=self.config.requested_model,
                http_status=resp.status_code,
            )
        if resp.headers.get("x-v3-response-truncated") == "true":
            return ProviderCheckResult(False, "provider_check_failed", self.config.requested_model, http_status=200)

        try:
            data = resp.json()
            choices = data.get("choices") if isinstance(data, dict) else None
            if not isinstance(choices, list) or len(choices) != 1 or not isinstance(choices[0], dict):
                return ProviderCheckResult(
                    passed=False,
                    safe_code="provider_check_failed",
                    requested_model=self.config.requested_model,
                    http_status=200,
                )
            choice = choices[0]
            message = choice.get("message")
            content = message.get("content") if isinstance(message, dict) else None
            returned_model = data.get("model") or self.config.requested_model

            if choice.get("finish_reason") == "stop" and isinstance(content, str) and content.strip() == "V3_PROVIDER_OK":
                return ProviderCheckResult(
                    passed=True,
                    safe_code="ok",
                    requested_model=self.config.requested_model,
                    returned_model=returned_model,
                    http_status=200,
                )
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_check_failed",
                requested_model=self.config.requested_model,
                returned_model=returned_model,
                http_status=200,
            )
        except Exception:
            return ProviderCheckResult(
                passed=False,
                safe_code="provider_check_failed",
                requested_model=self.config.requested_model,
                http_status=200,
            )

    async def generate_scene(
        self, request: SceneGenerationRequest, *, receipt_sink: RawReceiptSink
    ) -> ProviderResponse:
        user_json_str = _format_user_payload(request)
        payload = {
            "model": self.config.requested_model,
            "messages": [
                {"role": "system", "content": request.system_text},
                {"role": "user", "content": user_json_str},
            ],
            "stream": False,
        }
        headers = {
            "Authorization": f"Bearer {self.config.api_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

        start_wall = _rfc3339_now()
        start_mono = time.monotonic()

        raw_buffer = bytearray()
        response_status: Optional[int] = None
        truncated = False
        resp: Optional[httpx.Response] = None
        try:
            request = self._client.build_request("POST", self.config.endpoint, json=payload, headers=headers, timeout=float(self.config.request_timeout))
            resp = await self._client.send(request, stream=True)
            response_status = resp.status_code
            async for chunk in resp.aiter_bytes():
                remaining = MAX_RESPONSE_BODY_BYTES - len(raw_buffer)
                if len(chunk) > remaining:
                    raw_buffer.extend(chunk[:max(0, remaining)])
                    truncated = True
                    break
                raw_buffer.extend(chunk)
            raw_bytes = bytes(raw_buffer)
            if truncated:
                await resp.aclose()
        except httpx.TimeoutException:
            if resp is not None:
                await resp.aclose()
            finish_wall = _rfc3339_now()
            duration_ms = int((time.monotonic() - start_mono) * 1000)
            receipt_ref = await receipt_sink(bytes(raw_buffer), {"http_status": response_status, "complete": False, "truncated": truncated})
            return ProviderResponse(
                outcome="uncertain",
                safe_code="provider_uncertain",
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )
        except httpx.ConnectError:
            if resp is not None:
                await resp.aclose()
            finish_wall = _rfc3339_now()
            duration_ms = int((time.monotonic() - start_mono) * 1000)
            receipt_ref = await receipt_sink(bytes(raw_buffer), {"http_status": response_status, "complete": False, "truncated": truncated})
            return ProviderResponse(
                outcome="failed",
                safe_code="provider_connection",
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )
        except httpx.RequestError:
            if resp is not None:
                await resp.aclose()
            finish_wall = _rfc3339_now()
            duration_ms = int((time.monotonic() - start_mono) * 1000)
            receipt_ref = await receipt_sink(bytes(raw_buffer), {"http_status": response_status, "complete": False, "truncated": truncated})
            return ProviderResponse(
                outcome="uncertain",
                safe_code="provider_uncertain",
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )

        finish_wall = _rfc3339_now()
        duration_ms = int((time.monotonic() - start_mono) * 1000)

        # Check body size limit
        complete = not truncated
        if len(raw_bytes) > MAX_RESPONSE_BODY_BYTES:
            raw_bytes = raw_bytes[:MAX_RESPONSE_BODY_BYTES]
            truncated = True
            complete = False

        receipt_meta = {
            "http_status": resp.status_code,
            "complete": complete,
            "truncated": truncated,
        }
        receipt_ref = await receipt_sink(raw_bytes, receipt_meta)

        if truncated:
            return ProviderResponse(
                outcome="failed",
                safe_code="provider_response_oversize",
                http_status=resp.status_code,
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )

        if resp.status_code != 200:
            outcome, safe_code = _map_http_error(resp.status_code)
            return ProviderResponse(
                outcome=outcome,
                safe_code=safe_code,
                http_status=resp.status_code,
                requested_model=self.config.requested_model,
                raw_receipt_ref=receipt_ref,
                request_started_at=start_wall,
                request_finished_at=finish_wall,
                duration_ms=duration_ms,
            )

        return decode_generation_response(
            self.config.provider, self.config.requested_model, raw_bytes, resp.status_code, receipt_ref,
            started_at=start_wall, finished_at=finish_wall, duration_ms=duration_ms,
        )


class GLMProvider(BaseChatProvider):
    """Adapter for Z.AI GLM chat completions API."""


class DeepSeekProvider(BaseChatProvider):
    """Adapter for DeepSeek chat completions API."""


class QwenProvider(BaseChatProvider):
    """Adapter for Alibaba Model Studio Qwen chat completions API."""


def create_provider(config: ProviderConfig, client: Optional[httpx.AsyncClient] = None) -> AIProvider:
    """Factory to instantiate the configured AI provider."""
    if config.provider == "gemini":
        return GeminiProvider(config, client=client)
    if config.provider == "glm":
        return GLMProvider(config, client=client)
    if config.provider == "deepseek":
        return DeepSeekProvider(config, client=client)
    if config.provider == "qwen":
        return QwenProvider(config, client=client)
    raise ValueError(f"Unsupported provider: {config.provider}")
