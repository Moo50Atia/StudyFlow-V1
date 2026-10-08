"""Authoring activity runner and signed client for Spec 011."""
import asyncio
from dataclasses import dataclass
import hashlib
import json
import time
from typing import Any, Dict, List, Optional
from urllib.parse import urlparse
import httpx

from v3_engine.contracts import compute_hmac_signature
from v3_engine.authoring.package import parse_scene_package, PackageValidationError
from v3_engine.authoring.providers import (
    format_user_payload,
    call_gemini_authoring,
    call_glm_authoring,
    call_deepseek_authoring,
)


@dataclass
class AuthoringOutcome:
    status: str  # "candidate_ready" | "blocked" | "uncertain" | "stale"
    candidate_ref: Optional[str] = None
    safe_error_code: Optional[str] = None


class AuthoringClient:
    """Signed HMAC client for 011 internal Laravel routes."""

    def __init__(self, base_url: str, hmac_key: str):
        self.base_url = base_url.rstrip("/")
        self.hmac_key = hmac_key

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
        timeout: float = 30.0,
    ) -> Dict[str, Any]:
        raw_body = json.dumps(payload, separators=(",", ":")).encode("utf-8") if payload is not None else b""
        headers = self._sign_headers(method, path, raw_body)
        url = f"{self.base_url}{path}"

        async with httpx.AsyncClient(timeout=timeout) as client:
            if method.upper() == "GET":
                resp = await client.get(url, headers=headers)
            else:
                resp = await client.post(url, content=raw_body, headers=headers)

            if resp.status_code >= 400:
                raise RuntimeError(f"Internal API {method} {path} returned {resp.status_code}: {resp.text}")

            return resp.json().get("data", {})

    async def get_eligible_prompts(self, case_id: str) -> List[Dict[str, str]]:
        path = f"/api/internal/v1/cases/{case_id}/scene-authoring/eligible-prompts"
        data = await self._request("GET", path)
        return data if isinstance(data, list) else []

    async def claim_request(self, case_id: str, prompt_version_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-authoring/requests"
        payload = {"schema_version": 1, "prompt_version_ref": prompt_version_ref}
        return await self._request("POST", path, payload)

    async def get_context(self, case_id: str, request_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-authoring/requests/{request_ref}/context"
        return await self._request("GET", path)

    async def reserve_attempt(self, case_id: str, request_ref: str, ordinal: int) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-authoring/requests/{request_ref}/attempts"
        payload = {"schema_version": 1, "ordinal": ordinal}
        return await self._request("POST", path, payload)

    async def get_attempt(self, case_id: str, attempt_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-authoring/attempts/{attempt_ref}"
        return await self._request("GET", path)

    async def record_result(self, case_id: str, attempt_ref: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-authoring/attempts/{attempt_ref}/result"
        return await self._request("POST", path, payload)

    async def get_candidate(self, case_id: str, candidate_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-candidates/{candidate_ref}"
        return await self._request("GET", path)


class AuthoringRunner:
    """Executes scene authoring attempts with bounded fallback and uncertainty halting."""

    def __init__(self, client: Any, api_keys: Dict[str, str], timeout_seconds: float = 60.0):
        self.client = client
        self.api_keys = api_keys
        self.timeout_seconds = timeout_seconds

    async def run(self, case_id: str, request_ref: str) -> AuthoringOutcome:
        context = await self.client.get_context(case_id, request_ref)

        prompt = context.get("prompt", "")
        template_text = context.get("template_text", "")
        coding_order = context.get("coding_order", [])
        coding_attempts = context.get("coding_attempts", 3)

        attempt_limit = min(coding_attempts, len(coding_order))

        for ordinal in range(1, attempt_limit + 1):
            reservation = await self.client.reserve_attempt(case_id, request_ref, ordinal)
            attempt_ref = reservation["attempt_ref"]
            provider_info = reservation["provider"]
            provider_id = provider_info["id"]
            model = provider_info["model"]
            cred_ref = provider_info["credential_ref"]

            # Credential check
            api_key = self.api_keys.get(cred_ref) or self.api_keys.get(cred_ref.lower()) or self.api_keys.get(cred_ref.upper())
            if not api_key:
                # Missing credential: consumes slot with 0 HTTP calls and advances
                result_payload = {
                    "schema_version": 1,
                    "outcome": "known_failure",
                    "returned_model": None,
                    "provider_response_id": None,
                    "provider_response_json": None,
                    "package": None,
                    "failure_code": "provider_unavailable",
                    "usage": None,
                    "duration_ms": 0,
                }
                await self.client.record_result(case_id=case_id, attempt_ref=attempt_ref, payload=result_payload)
                continue

            # Call provider with timeout
            start_time = time.time()
            try:
                if provider_id == "gemini_api":
                    coro = call_gemini_authoring(model, api_key, template_text, prompt)
                elif provider_id == "glm_api":
                    coro = call_glm_authoring(model, api_key, template_text, prompt)
                elif provider_id == "deepseek_api":
                    coro = call_deepseek_authoring(model, api_key, template_text, prompt)
                else:
                    raise ValueError(f"Unknown provider ID: {provider_id}")

                resp = await asyncio.wait_for(coro, timeout=self.timeout_seconds)

            except (asyncio.TimeoutError, TimeoutError):
                elapsed_ms = int((time.time() - start_time) * 1000)
                result_payload = {
                    "schema_version": 1,
                    "outcome": "uncertain",
                    "returned_model": None,
                    "provider_response_id": None,
                    "provider_response_json": None,
                    "package": None,
                    "failure_code": "timeout",
                    "usage": None,
                    "duration_ms": elapsed_ms,
                }
                await self.client.record_result(case_id=case_id, attempt_ref=attempt_ref, payload=result_payload)
                # Halts fallback!
                return AuthoringOutcome(status="uncertain", safe_error_code="authoring_attempt_uncertain")

            except Exception as e:
                elapsed_ms = int((time.time() - start_time) * 1000)
                err_msg = str(e).lower()

                # Definitive refusal 401 / 403 / 429
                is_known_refusal = any(code in err_msg for code in ["401", "403", "429", "unauthorized", "rate limit", "too many requests"])
                if is_known_refusal:
                    result_payload = {
                        "schema_version": 1,
                        "outcome": "known_failure",
                        "returned_model": None,
                        "provider_response_id": None,
                        "provider_response_json": None,
                        "package": None,
                        "failure_code": "provider_unavailable",
                        "usage": None,
                        "duration_ms": elapsed_ms,
                    }
                    await self.client.record_result(case_id=case_id, attempt_ref=attempt_ref, payload=result_payload)
                    continue

                # 5xx or connection loss is uncertain
                result_payload = {
                    "schema_version": 1,
                    "outcome": "uncertain",
                    "returned_model": None,
                    "provider_response_id": None,
                    "provider_response_json": None,
                    "package": None,
                    "failure_code": "internal",
                    "usage": None,
                    "duration_ms": elapsed_ms,
                }
                await self.client.record_result(case_id=case_id, attempt_ref=attempt_ref, payload=result_payload)
                return AuthoringOutcome(status="uncertain", safe_error_code="authoring_attempt_uncertain")

            # Provider adapters return the documented five-item tuple. Convert
            # it once at the boundary so authoring ledger code below can use a
            # uniform, named response shape.
            if isinstance(resp, tuple) and len(resp) == 5:
                text, returned_model, response_id, response_json, usage = resp
                resp = {
                    "text": text,
                    "returned_model": returned_model,
                    "provider_response_id": response_id,
                    "provider_response_json": response_json,
                    "usage": usage,
                }
            if not isinstance(resp, dict):
                raise TypeError("Authoring provider returned an unsupported response shape.")

            # Parse returned response
            raw_text = resp.get("text", "")
            duration_ms = resp.get("duration_ms", int((time.time() - start_time) * 1000))
            returned_model = resp.get("returned_model", model)
            provider_response_id = resp.get("provider_response_id")

            try:
                pkg = parse_scene_package(raw_text)
                # Candidate package is valid!
                result_payload = {
                    "schema_version": 1,
                    "outcome": "candidate",
                    "returned_model": returned_model,
                    "provider_response_id": provider_response_id,
                    "provider_response_json": resp.get("provider_response_json"),
                    "package": {
                        "schema_version": pkg.schema_version,
                        "files": [{"path": f.path, "content": f.content} for f in pkg.files],
                    },
                    "failure_code": None,
                    "usage": resp.get("usage"),
                    "duration_ms": duration_ms,
                }
                record_res = await self.client.record_result(case_id=case_id, attempt_ref=attempt_ref, payload=result_payload)
                return AuthoringOutcome(
                    status="candidate_ready",
                    candidate_ref=record_res.get("candidate_ref"),
                )

            except (PackageValidationError, ValueError, json.JSONDecodeError):
                # Structurally invalid response -> known failure validation
                result_payload = {
                    "schema_version": 1,
                    "outcome": "known_failure",
                    "returned_model": returned_model,
                    "provider_response_id": provider_response_id,
                    "provider_response_json": resp.get("provider_response_json"),
                    "package": None,
                    "failure_code": "validation",
                    "usage": resp.get("usage"),
                    "duration_ms": duration_ms,
                }
                await self.client.record_result(case_id=case_id, attempt_ref=attempt_ref, payload=result_payload)
                continue

        # All attempts exhausted
        return AuthoringOutcome(
            status="blocked",
            safe_error_code="authoring_provider_unavailable",
        )
