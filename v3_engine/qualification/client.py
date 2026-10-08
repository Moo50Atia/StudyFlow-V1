"""
Signed HMAC client for internal Laravel scene JEV attempt routes.
Governed by specs/012-v3-scene-qualification/contracts/scene-qualification.md.
"""

import json
import time
from typing import Any, Dict, Optional
import httpx

from v3_engine.settings import get_settings
from v3_engine.contracts import compute_hmac_signature


class SceneJevAttemptClient:
    """Signed HMAC client for internal scene JEV attempt lifecycle."""

    def __init__(
        self,
        base_url: str,
        hmac_key: str,
        transport: Optional[httpx.AsyncBaseTransport] = None,
    ):
        self.base_url = base_url.rstrip("/")
        self.hmac_key = hmac_key
        self.transport = transport

    def _sign_headers(self, method: str, path: str, body_bytes: bytes) -> Dict[str, str]:
        ts = int(time.time())
        sig = compute_hmac_signature(method, path, ts, body_bytes, self.hmac_key)
        return {
            "X-V3-Timestamp": str(ts),
            "X-V3-Signature": sig,
            "X-V3-Contract-Version": "1",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    async def _request(
        self,
        method: str,
        path: str,
        payload: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        raw_body = json.dumps(payload, separators=(",", ":")).encode("utf-8") if payload is not None else b""
        headers = self._sign_headers(method, path, raw_body)
        url = f"{self.base_url}{path}"

        client_kwargs = {"timeout": 30.0}
        if self.transport is not None:
            client_kwargs["transport"] = self.transport

        async with httpx.AsyncClient(**client_kwargs) as client:
            if method.upper() == "GET":
                resp = await client.get(url, headers=headers)
            else:
                resp = await client.post(url, content=raw_body, headers=headers)

            if resp.status_code >= 400:
                raise RuntimeError(f"Internal API {method} {path} returned {resp.status_code}: {resp.text}")

            return resp.json().get("data", {})

    async def claim_attempt(
        self,
        case_id: str,
        candidate_ref: str,
        check_ref: str,
        attempt_no: int = 1,
    ) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-jev-attempts"
        payload = {
            "schema_version": 1,
            "candidate_ref": candidate_ref,
            "check_ref": check_ref,
            "attempt_no": attempt_no,
        }
        return await self._request("POST", path, payload)

    async def get_attempt(self, case_id: str, attempt_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-jev-attempts/{attempt_ref}"
        return await self._request("GET", path)

    async def get_context(self, case_id: str, attempt_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-jev-attempts/{attempt_ref}/context"
        return await self._request("GET", path)

    async def record_result(
        self,
        case_id: str,
        attempt_ref: str,
        raw_answer_json: str,
        answer_sha256: str,
        returned_model: str,
    ) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-jev-attempts/{attempt_ref}/result"
        payload = {
            "schema_version": 1,
            "raw_answer_json": raw_answer_json,
            "answer_sha256": answer_sha256,
            "returned_model": returned_model,
        }
        return await self._request("POST", path, payload)

    async def record_technical_failure(
        self,
        case_id: str,
        attempt_ref: str,
        error_code: str,
        detail_code: Optional[str] = None,
    ) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-jev-attempts/{attempt_ref}/technical-failure"
        payload = {
            "schema_version": 1,
            "error_code": error_code,
        }
        if detail_code:
            payload["detail_code"] = detail_code
        return await self._request("POST", path, payload)


def get_jev_attempt_client() -> SceneJevAttemptClient:
    settings = get_settings()
    if not settings.callback_hmac_key:
        raise RuntimeError("V3_CALLBACK_HMAC_KEY is not configured.")
    return SceneJevAttemptClient(settings.laravel_api_origin, settings.callback_hmac_key)
