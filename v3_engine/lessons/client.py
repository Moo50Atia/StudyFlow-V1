"""Signed Laravel client for the 009 lesson activity routes."""

import json
import time
from typing import Any, Dict, Optional
from urllib.parse import urlsplit

import httpx

from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings


class LessonClientError(RuntimeError):
    def __init__(self, status_code: int):
        super().__init__(f"Lesson API returned HTTP {status_code}.")
        self.status_code = status_code


class HttpLaravelLessonClient:
    """Use the configured callback origin and sign the exact bytes sent to Laravel."""

    def __init__(self, base_url: Optional[str] = None, hmac_key: Optional[str] = None):
        settings = get_settings()
        self.base_url = (base_url or settings.laravel_api_origin).rstrip("/")
        self.hmac_key = hmac_key if hmac_key is not None else settings.callback_hmac_key
        if not self.hmac_key:
            raise RuntimeError("V3_CALLBACK_HMAC_KEY is not configured.")
        parsed = urlsplit(self.base_url)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            raise ValueError("Laravel API origin must be an absolute HTTP URL.")

    async def _request(
        self,
        method: str,
        path: str,
        payload: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        raw_body = (
            json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
            if payload is not None
            else b""
        )
        timestamp = int(time.time())
        headers = {
            "X-V3-Timestamp": str(timestamp),
            "X-V3-Signature": compute_hmac_signature(method, path, timestamp, raw_body, self.hmac_key),
            "X-V3-Contract-Version": "1",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }
        async with httpx.AsyncClient(timeout=30.0) as client:
            if method.upper() == "GET":
                response = await client.get(f"{self.base_url}{path}", headers=headers)
            else:
                response = await client.post(f"{self.base_url}{path}", content=raw_body, headers=headers)
        if response.status_code >= 400:
            raise LessonClientError(response.status_code)
        return response.json().get("data", {})

    async def get_activation_context(self, activation_id: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/map-activations/{activation_id}/context"
        return {"data": await self._request("GET", path)}

    async def get_lesson_context(self, case_id: str, work_ordinal: int) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/lesson-context/{work_ordinal}"
        return {"data": await self._request("GET", path)}

    async def claim_query(
        self,
        *,
        case_id: str,
        kind: str,
        ordinal: int,
        activation_id: str,
        binding_ref: str,
        query_text: str,
        conversation_in: Optional[str],
    ) -> Dict[str, Any]:
        path = f"/api/internal/v1/lesson-queries/{case_id}/{kind}/{ordinal}/claim"
        payload = {
            "schema_version": 1,
            "activation_id": activation_id,
            "binding_ref": binding_ref,
            "query_text": query_text,
            "conversation_in": conversation_in,
        }
        return {"data": await self._request("POST", path, payload)}

    async def post_query_result(
        self,
        *,
        query_ref: str,
        answer: str,
        conversation_out: str,
        parsed: Dict[str, Any],
    ) -> Dict[str, Any]:
        path = f"/api/internal/v1/lesson-queries/{query_ref}/result"
        payload = {
            "schema_version": 1,
            "answer": answer,
            "conversation_out": conversation_out,
            "parsed": parsed,
        }
        return {"data": await self._request("POST", path, payload)}
