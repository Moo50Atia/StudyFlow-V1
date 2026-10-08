from __future__ import annotations

import hashlib
import json
import time
from pathlib import Path
from typing import Any, Optional
from urllib.parse import urlsplit

import httpx

from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings


class LaravelClientError(Exception):
    def __init__(self, status_code: int, code: str, message: str, data: Any = None):
        super().__init__(f"Laravel API error {status_code} ({code}): {message}")
        self.status_code = status_code
        self.code = code
        self.message = message
        self.data = data


class HttpLaravelNotebookClient:
    """Signed Laravel HTTP client for NotebookLM direct protocol worker activities."""

    def __init__(
        self,
        base_url: Optional[str] = None,
        hmac_key: Optional[str] = None,
        timeout: float = 30.0,
    ):
        settings = get_settings()
        self.base_url = (base_url or settings.laravel_api_origin).rstrip("/")
        self.hmac_key = hmac_key if hmac_key is not None else settings.callback_hmac_key
        if not self.hmac_key:
            raise RuntimeError("V3_CALLBACK_HMAC_KEY is not configured.")

        parsed = urlsplit(self.base_url)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            raise ValueError("Laravel API origin must be an absolute HTTP URL.")

        self.timeout = timeout

    def _headers(
        self,
        method: str,
        path: str,
        raw_body: bytes,
        content_type: str = "application/json",
        accept: str = "application/json",
    ) -> dict[str, str]:
        now_ts = int(time.time())
        sig = compute_hmac_signature(method, path, now_ts, raw_body, self.hmac_key)
        headers = {
            "X-V3-Timestamp": str(now_ts),
            "X-V3-Signature": sig,
            "X-V3-Contract-Version": "1",
            "Accept": accept,
        }
        if content_type:
            headers["Content-Type"] = content_type
        return headers

    async def _request(
        self,
        method: str,
        path: str,
        payload: Optional[dict[str, Any]] = None,
        timeout: Optional[float] = None,
    ) -> dict[str, Any]:
        raw_body = (
            json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
            if payload is not None
            else b""
        )
        headers = self._headers(method, path, raw_body)
        req_timeout = timeout or self.timeout

        async with httpx.AsyncClient(timeout=req_timeout) as client:
            if method.upper() == "GET":
                resp = await client.get(f"{self.base_url}{path}", headers=headers)
            else:
                resp = await client.post(f"{self.base_url}{path}", content=raw_body, headers=headers)

        if resp.status_code >= 400:
            error_code = "http_error"
            error_message = resp.text
            error_data = None
            try:
                err_json = resp.json()
                if "error" in err_json and isinstance(err_json["error"], dict):
                    error_code = err_json["error"].get("code", error_code)
                    error_message = err_json["error"].get("message", error_message)
                elif "message" in err_json:
                    error_message = err_json["message"]
                error_data = err_json
            except Exception:
                pass
            raise LaravelClientError(resp.status_code, error_code, error_message, error_data)

        if resp.status_code == 204 or not resp.content:
            return {}

        try:
            return resp.json().get("data", resp.json())
        except Exception:
            return {}

    async def get_steps(self, activation_id: str) -> tuple[bytes, str]:
        """Fetch captured framework steps.txt bytes and verify expected hash header."""
        path = f"/api/internal/v1/source-activations/{activation_id}/steps"
        headers = self._headers("GET", path, b"", content_type="", accept="text/plain")

        async with httpx.AsyncClient(timeout=self.timeout) as client:
            resp = await client.get(f"{self.base_url}{path}", headers=headers)

        if resp.status_code >= 400:
            raise LaravelClientError(resp.status_code, "fetch_steps_failed", resp.text)

        content = resp.content
        expected_sha256 = resp.headers.get("X-V3-Framework-SHA256", "")
        return content, expected_sha256

    async def stream_pdf(self, activation_id: str, dest_path: Path) -> dict[str, Any]:
        """Stream verified accepted PDF to a local private file."""
        path = f"/api/internal/v1/source-activations/{activation_id}/pdf"
        headers = self._headers("GET", path, b"", content_type="", accept="application/pdf, application/octet-stream")

        dest_path.parent.mkdir(parents=True, exist_ok=True)
        staging = dest_path.with_suffix(f".tmp-{int(time.time())}")

        hasher = hashlib.sha256()
        total_bytes = 0

        async with httpx.AsyncClient(timeout=120.0) as client:
            async with client.stream("GET", f"{self.base_url}{path}", headers=headers) as resp:
                if resp.status_code >= 400:
                    error_body = await resp.aread()
                    raise LaravelClientError(resp.status_code, "stream_pdf_failed", error_body.decode("utf-8", "replace"))
                with open(staging, "wb") as f:
                    async for chunk in resp.aiter_bytes():
                        hasher.update(chunk)
                        total_bytes += len(chunk)
                        f.write(chunk)

        staging.chmod(0o600)
        staging.replace(dest_path)
        dest_path.chmod(0o600)

        return {
            "path": dest_path,
            "byte_count": total_bytes,
            "sha256": hasher.hexdigest(),
        }

    async def claim_source_effect(
        self,
        case_id: str,
        operation: str,
        activation_id: str,
        request_digest: str,
    ) -> dict[str, Any]:
        path = f"/api/internal/v1/source-effects/{case_id}/{operation}/claim"
        payload = {
            "schema_version": 1,
            "activation_id": activation_id,
            "request_digest": request_digest,
        }
        return await self._request("POST", path, payload)

    async def record_source_effect_result(
        self,
        effect_id: str,
        payload: dict[str, Any],
    ) -> dict[str, Any]:
        path = f"/api/internal/v1/source-effects/{effect_id}/result"
        return await self._request("POST", path, payload)

    async def get_binding(self, case_id: str, activation_id: str) -> dict[str, Any]:
        path = f"/api/internal/v1/source-activations/{activation_id}/binding"
        return await self._request("GET", path)

    async def get_binding_by_ref(self, case_id: str, binding_ref: str) -> dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/source-bindings/{binding_ref}"
        return await self._request("GET", path)

    async def get_map_context(self, activation_id: str) -> dict[str, Any]:
        path = f"/api/internal/v1/map-activations/{activation_id}/context"
        return await self._request("GET", path)

    async def claim_lesson_query(
        self,
        case_id: str,
        kind: str,
        work_ordinal: int,
        payload: dict[str, Any],
    ) -> dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/lesson-queries/{kind}/{work_ordinal}/claim"
        return await self._request("POST", path, payload)

    async def find_lesson_query(self, case_id: str, kind: str, work_ordinal: int) -> Optional[dict[str, Any]]:
        path = f"/api/internal/v1/cases/{case_id}/lesson-queries/{kind}/{work_ordinal}"
        return await self._request("GET", path)

    async def record_lesson_query_result(
        self,
        query_ref: str,
        payload: dict[str, Any],
    ) -> dict[str, Any]:
        path = f"/api/internal/v1/lesson-queries/{query_ref}/result"
        return await self._request("POST", path, payload)

    async def record_divide_result(
        self,
        query_ref: str,
        answer: str,
        conversation_out: str,
    ) -> dict[str, Any]:
        return await self.record_lesson_query_result(
            query_ref,
            {
                "schema_version": 1,
                "answer": answer,
                "conversation_out": conversation_out,
            },
        )

    async def record_execution_result(
        self,
        query_ref: str,
        answer: str,
        conversation_out: str,
    ) -> dict[str, Any]:
        return await self.record_lesson_query_result(
            query_ref,
            {
                "schema_version": 1,
                "answer": answer,
                "answer_sha256": hashlib.sha256(answer.encode("utf-8")).hexdigest(),
                "conversation_out": conversation_out,
            },
        )

    async def record_combined_lesson_result(
        self,
        query_ref: str,
        payload: dict[str, Any],
    ) -> dict[str, Any]:
        path = f"/api/internal/v1/lesson-queries/{query_ref}/combined-result"
        return await self._request("POST", path, payload)

    async def record_lesson_query_uncertain(
        self,
        query_ref: str,
        payload: dict[str, Any],
    ) -> dict[str, Any]:
        path = f"/api/internal/v1/lesson-queries/{query_ref}/uncertain"
        return await self._request("POST", path, payload)

    async def get_lesson_query(self, query_ref: str) -> dict[str, Any]:
        path = f"/api/internal/v1/lesson-queries/{query_ref}"
        return await self._request("GET", path)

    async def get_lesson_context(self, case_id: str, work_ordinal: int) -> dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/lesson-context/{work_ordinal}"
        return await self._request("GET", path)
