"""Temporal activities for Spec 013 Teacher Review and Corrections."""
import time
from typing import Any, Dict
from urllib.parse import urlparse
import httpx
from temporalio import activity
from temporalio.exceptions import ApplicationError

from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings


class ReviewClient:
    """Signed HMAC client for Spec 013 internal Laravel routes."""

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
        payload: dict | None = None,
    ) -> dict:
        body_bytes = b""
        if payload is not None:
            import json
            body_bytes = json.dumps(payload, separators=(",", ":")).encode("utf-8")

        headers = self._sign_headers(method, path, body_bytes)
        url = f"{self.base_url}{path}"

        async with httpx.AsyncClient(timeout=15.0) as client:
            resp = await client.request(method, url, content=body_bytes, headers=headers)
            if resp.status_code not in (200, 201):
                err_data = resp.json() if resp.headers.get("content-type") == "application/json" else {}
                err_code = err_data.get("error", {}).get("code", f"http_{resp.status_code}")
                err_msg = err_data.get("error", {}).get("message", resp.text)
                raise ApplicationError(f"Laravel error [{err_code}]: {err_msg}", non_retryable=(resp.status_code in (404, 409, 422)))
            return resp.json().get("data", {})

    async def claim_scene_repair(self, case_id: str, route_ref: str) -> dict:
        path = f"/api/internal/v1/cases/{case_id}/teacher-scene-repairs"
        return await self._request("POST", path, {"schema_version": 1, "route_ref": route_ref})

    async def claim_prompt_correction(self, case_id: str, route_ref: str) -> dict:
        path = f"/api/internal/v1/cases/{case_id}/teacher-prompt-corrections/{route_ref}/claim"
        return await self._request("POST", path, {"schema_version": 1})

    async def get_prompt_correction_context(self, case_id: str, effect_ref: str) -> dict:
        path = f"/api/internal/v1/cases/{case_id}/teacher-prompt-corrections/{effect_ref}/context"
        return await self._request("GET", path)

    async def record_prompt_correction_result(self, case_id: str, effect_ref: str, payload: dict) -> dict:
        path = f"/api/internal/v1/cases/{case_id}/teacher-prompt-corrections/{effect_ref}/result"
        return await self._request("POST", path, payload)

    async def record_prompt_correction_uncertain(self, case_id: str, effect_ref: str, error_code: str) -> dict:
        path = f"/api/internal/v1/cases/{case_id}/teacher-prompt-corrections/{effect_ref}/uncertain"
        return await self._request("POST", path, {"schema_version": 1, "error_code": error_code})

    async def ensure_review_ready(self, case_id: str, candidate_ref: str) -> dict:
        path = f"/api/internal/v1/cases/{case_id}/reviews/ready"
        return await self._request("POST", path, {"schema_version": 1, "candidate_ref": candidate_ref})

    async def get_review_eligible(self, case_id: str, candidate_ref: str) -> dict:
        path = f"/api/internal/v1/cases/{case_id}/scene-qualification/review-eligible/{candidate_ref}"
        return await self._request("GET", path)


def _get_review_client() -> ReviewClient:
    settings = get_settings()
    parsed = urlparse(settings.laravel_callback_url)
    base_url = f"{parsed.scheme}://{parsed.netloc}"
    return ReviewClient(base_url=base_url, hmac_key=settings.callback_hmac_key)


@activity.defn(name="claim_teacher_scene_repair")
async def claim_teacher_scene_repair_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    client = _get_review_client()
    return await client.claim_scene_repair(payload["case_id"], payload["route_ref"])


@activity.defn(name="claim_teacher_prompt_correction")
async def claim_teacher_prompt_correction_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    client = _get_review_client()
    return await client.claim_prompt_correction(payload["case_id"], payload["route_ref"])


@activity.defn(name="get_teacher_prompt_correction_context")
async def get_teacher_prompt_correction_context_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    client = _get_review_client()
    return await client.get_prompt_correction_context(payload["case_id"], payload["effect_ref"])


@activity.defn(name="record_teacher_prompt_correction_result")
async def record_teacher_prompt_correction_result_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    client = _get_review_client()
    return await client.record_prompt_correction_result(payload["case_id"], payload["effect_ref"], payload["result_payload"])


@activity.defn(name="record_teacher_prompt_correction_uncertain")
async def record_teacher_prompt_correction_uncertain_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    client = _get_review_client()
    return await client.record_prompt_correction_uncertain(payload["case_id"], payload["effect_ref"], payload["error_code"])


@activity.defn(name="revise_teacher_prompt_correction")
async def revise_teacher_prompt_correction_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Generate and persist the one captured, teacher-requested prompt revision."""
    from v3_engine.prompts.feedback import FeedbackTextAdapter
    client = _get_review_client()
    case_id, effect_ref = payload["case_id"], payload["effect_ref"]
    context = await client.get_prompt_correction_context(case_id, effect_ref)
    user_payload = {
        "section_ref": context["section_ref"],
        "fields": context["fields"],
        "previous_prompt": context["previous_prompt"],
        "teacher_note": context["teacher_note"],
        "previous_qualification": context["previous_qualification"],
    }
    try:
        result = await FeedbackTextAdapter.revise(
            descriptor=context["provider"],
            template_bytes=context["template_bytes"],
            user_payload=user_payload,
            effect_ref=effect_ref,
        )
    except Exception as exc:
        safe_code = "timeout" if "timeout" in str(exc).lower() else "provider_unavailable"
        await client.record_prompt_correction_uncertain(case_id, effect_ref, safe_code)
        return {"status": "uncertain", "error_code": safe_code}
    recorded = await client.record_prompt_correction_result(case_id, effect_ref, {
        "schema_version": 1,
        "revised_prompt": result.text,
        "output_sha256": result.output_sha256,
        "provider_response_json": result.raw_response_json,
        "provider_response_sha256": result.raw_response_sha256,
        "returned_model": result.returned_model,
        "provider_response_id": result.provider_response_id,
    })
    return {"status": "recorded", "work_ordinal": int(context.get("work_ordinal", 1)), **recorded}


@activity.defn(name="execute_teacher_scene_repair")
async def execute_teacher_scene_repair_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Execute the already-claimed 013 visual route through the 012 repair gate."""
    from v3_engine.qualification.activity import get_authoring_client, get_repair_client
    from v3_engine.qualification.repair import RepairRunner
    from v3_engine.settings import get_settings
    case_id = payload["case_id"]
    repair_ref = payload["repair_ref"]
    request_ref = payload["authoring_request_ref"]
    ordinal = int(payload["ordinal"])
    repair_client = get_repair_client()
    context = await repair_client.get_repair_context(case_id, repair_ref)
    authoring_client = get_authoring_client()
    authoring_context = await authoring_client.get_context(case_id, request_ref)
    settings = get_settings()
    runner = RepairRunner(authoring_client, {
        "gemini_api_key": settings.gemini_api_key,
        "glm_api_key": settings.glm_api_key,
        "deepseek_api_key": settings.deepseek_api_key,
    }, timeout_seconds=60.0)
    outcome = await runner.run_repair_attempt(
        case_id=case_id,
        request_ref=request_ref,
        ordinal=ordinal,
        prompt=authoring_context["prompt"],
        template_text=authoring_context["template_text"],
        repair_context=context,
    )
    if outcome.status != "candidate_ready" or not outcome.candidate_ref:
        return {"status": outcome.status, "safe_error_code": outcome.safe_error_code}
    await repair_client.link_repair_candidate(case_id, repair_ref, outcome.candidate_ref)
    return {"status": "candidate_ready", "candidate_ref": outcome.candidate_ref, "repair_ref": repair_ref}
