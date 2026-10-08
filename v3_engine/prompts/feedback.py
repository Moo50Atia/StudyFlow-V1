import hashlib
import os
import re
from dataclasses import dataclass
from typing import Any, Dict, Optional
import httpx

from v3_engine.prompts.parser import ALLOWED_SCENE_TYPES
from v3_engine.prompts.providers.gemini import call_gemini_feedback
from v3_engine.prompts.providers.glm import call_glm_feedback
from v3_engine.prompts.providers.deepseek import call_deepseek_feedback


@dataclass(frozen=True)
class FeedbackRevisionResult:
    text: str
    returned_model: str
    provider_response_id: Optional[str]
    raw_response_json: str
    raw_response_sha256: str
    output_sha256: str


class FeedbackTextAdapter:
    @staticmethod
    async def revise(
        descriptor: Dict[str, Any],
        template_bytes: str,
        user_payload: Dict[str, Any],
        effect_ref: str,
        client: Optional[httpx.AsyncClient] = None,
    ) -> FeedbackRevisionResult:
        provider_id = descriptor.get("id")
        model = descriptor.get("model")
        credential_ref = descriptor.get("credential_ref")

        if not provider_id or not model or not credential_ref:
            raise ValueError("Invalid provider descriptor: must contain id, model, and credential_ref.")

        api_key = os.getenv(credential_ref, "")
        if not api_key:
            raise ValueError(f"Environment credential '{credential_ref}' not set.")

        if provider_id == "gemini_api":
            text, returned_model, response_id, raw_json = await call_gemini_feedback(
                model=model,
                api_key=api_key,
                template_bytes=template_bytes,
                user_payload=user_payload,
                client=client,
            )
        elif provider_id == "glm_api":
            text, returned_model, response_id, raw_json = await call_glm_feedback(
                model=model,
                api_key=api_key,
                template_bytes=template_bytes,
                user_payload=user_payload,
                client=client,
            )
        elif provider_id == "deepseek_api":
            text, returned_model, response_id, raw_json = await call_deepseek_feedback(
                model=model,
                api_key=api_key,
                template_bytes=template_bytes,
                user_payload=user_payload,
                client=client,
            )
        else:
            raise ValueError(f"Unsupported provider id '{provider_id}'")

        # Strip outer whitespace only
        text = text.strip()
        text_bytes = text.encode("utf-8")
        if len(text_bytes) > 65536:
            raise ValueError(f"Revised prompt exceeds 65536 bytes: {len(text_bytes)}")

        # Check scene type structure
        matched_type = None
        for scene_type in ALLOWED_SCENE_TYPES:
            pattern = rf"^{re.escape(scene_type)}[\s:.,\-—]"
            if re.match(pattern, text, re.IGNORECASE):
                matched_type = scene_type
                break

        if not matched_type:
            raise ValueError(f"Revised prompt does not start with an allowed scene type: {ALLOWED_SCENE_TYPES}")

        output_sha = hashlib.sha256(text_bytes).hexdigest()
        raw_resp_sha = hashlib.sha256(raw_json.encode("utf-8")).hexdigest()

        return FeedbackRevisionResult(
            text=text,
            returned_model=returned_model,
            provider_response_id=response_id,
            raw_response_json=raw_json,
            raw_response_sha256=raw_resp_sha,
            output_sha256=output_sha,
        )


def compute_feedback_request_digest(
    failed_version_uuid: str,
    qualification_answer_sha: str,
    captured_template_sha: str,
    provider_id: str,
    provider_model: str,
    credential_ref: str,
) -> str:
    """Compute SHA-256 digest of feedback request per 010 specification."""
    payload = (
        f"010-feedback-v1\n"
        f"{failed_version_uuid}\n"
        f"{qualification_answer_sha}\n"
        f"{captured_template_sha}\n"
        f"{provider_id}\n"
        f"{provider_model}\n"
        f"{credential_ref}"
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()
