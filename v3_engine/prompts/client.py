"""Signed Laravel client for 010 prompt qualification and revision routes."""

import json
import time
from typing import Any, Dict, List, Optional
from urllib.parse import urlsplit

import httpx

from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings


class PromptClientError(RuntimeError):
    def __init__(self, status_code: int, response_text: str = ""):
        super().__init__(f"Prompt API returned HTTP {status_code}: {response_text}")
        self.status_code = status_code
        self.response_text = response_text


class HttpLaravelPromptQualificationClient:
    """Use configured callback origin and sign exact bytes sent to Laravel."""

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
        timeout: float = 30.0,
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
        async with httpx.AsyncClient(timeout=timeout) as client:
            if method.upper() == "GET":
                response = await client.get(f"{self.base_url}{path}", headers=headers)
            else:
                response = await client.post(f"{self.base_url}{path}", content=raw_body, headers=headers)
        if response.status_code >= 400:
            raise PromptClientError(response.status_code, response.text)
        return response.json().get("data", {})

    async def get_work_prompt_versions(self, case_id: str, work_ordinal: int) -> List[str]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_ordinal}/prompt-versions"
        data = await self._request("GET", path)
        return data.get("prompt_version_refs", [])

    async def get_qualification_context(self, case_id: str, prompt_version_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/qualification-context"
        return await self._request("GET", path)

    async def claim_jev_attempt(self, case_id: str, prompt_version_ref: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/jev-attempts/claim"
        return await self._request("POST", path, payload)

    async def record_jev_result(self, case_id: str, attempt_ref: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-jev-attempts/{attempt_ref}/result"
        return await self._request("POST", path, payload)

    async def record_jev_technical_failure(self, case_id: str, attempt_ref: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-jev-attempts/{attempt_ref}/technical-failure"
        return await self._request("POST", path, payload)

    async def get_feedback_context(self, case_id: str, prompt_version_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/feedback-context"
        return await self._request("GET", path)

    async def claim_feedback(self, case_id: str, prompt_version_ref: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/feedback/claim"
        return await self._request("POST", path, payload)

    async def record_feedback_result(self, case_id: str, effect_ref: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-feedback-effects/{effect_ref}/result"
        return await self._request("POST", path, payload)

    async def record_feedback_uncertain(self, case_id: str, effect_ref: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-feedback-effects/{effect_ref}/uncertain"
        return await self._request("POST", path, payload)

    async def resolve_qualified_prompt(self, case_id: str, prompt_version_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/qualified-prompts/{prompt_version_ref}"
        return await self._request("GET", path)
