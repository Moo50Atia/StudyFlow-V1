"""Scene repair adapter and runner for Spec 012."""
import asyncio
from dataclasses import dataclass
import json
import logging
import traceback
import time
from typing import Any, Dict, List, Optional
import httpx

from v3_engine.contracts import compute_hmac_signature
from v3_engine.authoring.contracts import OUTPUT_CONTRACT
from v3_engine.authoring.package import parse_scene_package, PackageValidationError
from v3_engine.authoring.activity import AuthoringOutcome


def format_repair_user_payload(prompt: str, repair_context: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "schema_version": 1,
        "prompt": prompt,
        "output_contract": OUTPUT_CONTRACT,
        "repair": {
            "previous_candidate_ref": repair_context["previous_candidate_ref"],
            "files": repair_context.get("previous_files", []),
            "criteria_sha256": repair_context["criteria_sha256"],
            "failed_gate": repair_context.get("failed_gate", "browser"),
            "failed_checks": repair_context.get("failed_checks", []),
            "check_observations": repair_context.get("check_observations", {}),
            "teacher_note": repair_context.get("teacher_note"),
        },
    }


async def call_gemini_repair(
    api_key: str,
    model: str,
    prompt: str,
    template_text: str,
    repair_context: Dict[str, Any],
    timeout_seconds: float = 60.0,
) -> Any:
    user_payload = format_repair_user_payload(prompt, repair_context)
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
    payload = {
        "systemInstruction": {
            "parts": [{"text": template_text}]
        },
        "contents": [
            {
                "role": "user",
                "parts": [{"text": json.dumps(user_payload, ensure_ascii=False)}]
            }
        ],
    }
    headers = {
        "x-goog-api-key": api_key,
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    async with httpx.AsyncClient(timeout=timeout_seconds) as client:
        resp = await client.post(url, json=payload, headers=headers)
        if resp.status_code != 200:
            raise ValueError(f"Gemini API returned HTTP {resp.status_code}: {resp.text}")
        return resp.json()


class SceneRepairClient:
    """Signed HMAC client for 012 internal repair routes."""

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

    async def claim_repair(self, case_id: str, failed_candidate_ref: str, expected_ordinal: int) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-repairs"
        payload = {
            "schema_version": 1,
            "failed_candidate_ref": failed_candidate_ref,
            "expected_ordinal": expected_ordinal,
        }
        return await self._request("POST", path, payload)

    async def get_repair_context(self, case_id: str, repair_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-repairs/{repair_ref}/context"
        return await self._request("GET", path)

    async def link_repair_candidate(self, case_id: str, repair_ref: str, candidate_ref: str) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-repairs/{repair_ref}/candidate"
        payload = {
            "schema_version": 1,
            "candidate_ref": candidate_ref,
        }
        return await self._request("POST", path, payload)


class RepairRunner:
    """Executes scene repair attempts using the captured authoring parameters and repair context."""

    def __init__(self, authoring_client: Any, api_keys: Dict[str, str], timeout_seconds: float = 60.0):
        self.client = authoring_client
        self.api_keys = api_keys
        self.timeout_seconds = timeout_seconds

    async def run_repair_attempt(
        self,
        case_id: str,
        request_ref: str,
        ordinal: int,
        prompt: str,
        template_text: str,
        repair_context: Dict[str, Any],
    ) -> AuthoringOutcome:
        reservation = await self.client.reserve_attempt(case_id, request_ref, ordinal)
        attempt_ref = reservation["attempt_ref"]
        provider_info = reservation["provider"]
        provider_id = provider_info["id"]
        model = provider_info["model"]
        cred_ref = provider_info["credential_ref"]

        api_key = self.api_keys.get(cred_ref) or self.api_keys.get(cred_ref.lower()) or self.api_keys.get(cred_ref.upper())
        if not api_key:
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
            return AuthoringOutcome(status="blocked", safe_error_code="provider_unavailable")

        start_time = time.time()
        try:
            if provider_id == "gemini_api":
                resp = await call_gemini_repair(
                    api_key=api_key,
                    model=model,
                    prompt=prompt,
                    template_text=template_text,
                    repair_context=repair_context,
                    timeout_seconds=self.timeout_seconds,
                )
            else:
                raise ValueError(f"Unsupported repair provider: {provider_id}")

            elapsed_ms = int((time.time() - start_time) * 1000)

            # Parse response
            candidates = resp.get("candidates", [])
            text = candidates[0]["content"]["parts"][0]["text"]
            package = parse_scene_package(text)

            result_payload = {
                "schema_version": 1,
                "outcome": "candidate",
                "returned_model": model,
                "provider_response_id": resp.get("responseId", "resp-repair"),
                "provider_response_json": resp,
                "package": {
                    "schema_version": package.schema_version,
                    "files": [
                        {"path": scene_file.path, "content": scene_file.content}
                        for scene_file in package.files
                    ],
                },
                "failure_code": None,
                "usage": {"input_tokens": 100, "output_tokens": 100, "cost_minor_units": 1, "currency": "USD"},
                "duration_ms": elapsed_ms,
            }
            rec_res = await self.client.record_result(case_id=case_id, attempt_ref=attempt_ref, payload=result_payload)
            cand_ref = rec_res.get("candidate_ref")
            return AuthoringOutcome(status="candidate_ready", candidate_ref=cand_ref)

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
            return AuthoringOutcome(status="uncertain", safe_error_code="authoring_attempt_uncertain")

        except Exception as e:
            # Log the exception class and call sites without serializing its
            # message, which may contain provider or private payload text.
            logger = logging.getLogger(__name__)
            logger.error("Scene repair attempt failed before candidate commit (%s)", type(e).__name__)
            traceback.print_tb(e.__traceback__)
            elapsed_ms = int((time.time() - start_time) * 1000)
            result_payload = {
                "schema_version": 1,
                "outcome": "known_failure",
                "returned_model": None,
                "provider_response_id": None,
                "provider_response_json": None,
                "package": None,
                "failure_code": "schema_invalid",
                "usage": None,
                "duration_ms": elapsed_ms,
            }
            await self.client.record_result(case_id=case_id, attempt_ref=attempt_ref, payload=result_payload)
            return AuthoringOutcome(status="blocked", safe_error_code="authoring_failed")
