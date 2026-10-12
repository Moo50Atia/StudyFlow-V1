"""Temporal activities for Spec 011 Scene Authoring."""
from typing import Any, Dict, List
from urllib.parse import urlparse
from temporalio import activity
from temporalio.exceptions import ApplicationError

from v3_engine.authoring.activity import AuthoringClient, AuthoringRunner
from v3_engine.settings import get_settings


def _get_authoring_client() -> AuthoringClient:
    settings = get_settings()
    parsed = urlparse(settings.laravel_callback_url)
    base_url = f"{parsed.scheme}://{parsed.netloc}"
    return AuthoringClient(base_url=base_url, hmac_key=settings.callback_hmac_key)


@activity.defn(name="authoring_list_eligible_prompts")
async def authoring_list_eligible_prompts(payload: Dict[str, Any]) -> List[Dict[str, Any]]:
    """List eligible prompt versions in 009 ordinal order for the case."""
    case_id = payload["case_id"]
    client = _get_authoring_client()
    try:
        return await client.get_eligible_prompts(case_id)
    except Exception as e:
        raise ApplicationError(f"Failed to list eligible prompts: {str(e)}", non_retryable=False)


@activity.defn(name="authoring_claim_request")
async def authoring_claim_request(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Claim an initial authoring request for a qualified prompt."""
    case_id = payload["case_id"]
    prompt_version_ref = payload["prompt_version_ref"]
    client = _get_authoring_client()
    try:
        return await client.claim_request(case_id, prompt_version_ref)
    except Exception as e:
        raise ApplicationError(f"Failed to claim authoring request: {str(e)}", non_retryable=False)


@activity.defn(name="authoring_execute_attempts")
async def authoring_execute_attempts(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Execute scene authoring attempts with bounded fallback and uncertainty halting."""
    case_id = payload["case_id"]
    request_ref = payload["request_ref"]
    settings = get_settings()
    client = _get_authoring_client()

    api_keys = {
        "gemini_api_key": settings.gemini_api_key,
        "glm_api_key": settings.glm_api_key,
        "deepseek_api_key": settings.deepseek_api_key,
        "qwen_api_key": settings.qwen_api_key,
    }

    runner = AuthoringRunner(client=client, api_keys=api_keys, timeout_seconds=60.0)
    outcome = await runner.run(case_id, request_ref)

    return {
        "status": outcome.status,
        "candidate_ref": outcome.candidate_ref,
        "safe_error_code": outcome.safe_error_code,
    }
