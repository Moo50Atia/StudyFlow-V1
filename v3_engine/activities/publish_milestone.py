import json
import time
from typing import Any, Dict
import httpx
from temporalio import activity
from temporalio.exceptions import ApplicationError

from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings

class ContractInvalidError(ApplicationError):
    def __init__(self, message: str):
        super().__init__(message, non_retryable=True, type="ContractInvalidError")

class EventConflictError(ApplicationError):
    def __init__(self, message: str):
        super().__init__(message, non_retryable=True, type="EventConflictError")

@activity.defn
async def publish_workflow_started(payload: Dict[str, Any]) -> Dict[str, Any]:
    settings = get_settings()
    url = settings.laravel_callback_url
    secret_key = settings.callback_hmac_key

    # Path without query
    from urllib.parse import urlparse
    parsed = urlparse(url)
    path = parsed.path

    body_bytes = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    now_ts = int(time.time())
    sig = compute_hmac_signature("POST", path, now_ts, body_bytes, secret_key)

    headers = {
        "Content-Type": "application/json",
        "X-V3-Timestamp": str(now_ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
    }

    async with httpx.AsyncClient(timeout=10.0) as client:
        try:
            resp = await client.post(url, content=body_bytes, headers=headers)
        except (httpx.TimeoutException, httpx.NetworkError) as e:
            raise ApplicationError(f"Network error calling Laravel callback: {str(e)}", non_retryable=False)

        if resp.status_code in (200, 201):
            return resp.json()
        elif resp.status_code == 409:
            raise EventConflictError(f"Conflict 409 from Laravel callback: {resp.text}")
        elif resp.status_code in (401, 422):
            raise ContractInvalidError(f"Client error {resp.status_code} from Laravel callback: {resp.text}")
        else:
            raise ApplicationError(f"Server error {resp.status_code} from Laravel callback", non_retryable=False)

@activity.defn(name="publish_case_event")
async def publish_case_event(payload: Dict[str, Any]) -> Dict[str, Any]:
    return await publish_workflow_started(payload)

