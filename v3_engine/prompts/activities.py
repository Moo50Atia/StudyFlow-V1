"""Prompt activities for Spec 010."""

import hashlib
import json
from pathlib import Path
from typing import Any, Dict, List, Optional
import httpx
from temporalio import activity
from temporalio.exceptions import ApplicationError

from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings
from v3_engine.prompts.cycle import PromptCycleRunner
from v3_engine.sources.notebooklm_adapter import KnowledgeSourceAdapter
from v3_engine.prompts.client import HttpLaravelPromptQualificationClient, PromptClientError
from v3_engine.prompts.jev import TypeSafeJevClient, QUESTIONS_PATH
from v3_engine.prompts.policy import (
    compute_jev_request_digest,
    compute_section_fields_sha256,
    JevTechnicalError,
)
from v3_engine.prompts.feedback import (
    FeedbackTextAdapter,
    compute_feedback_request_digest,
)


@activity.defn(name="generate_prompts")
async def generate_prompts(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Run the canonical Execution/Second/Third cycle against the bound source."""
    required = ("case_id", "work_ordinal", "binding_ref")
    if any(payload.get(key) in (None, "") for key in required):
        raise ApplicationError("Prompt generation identity is incomplete.", non_retryable=True)
    settings = get_settings()
    if not settings.callback_hmac_key:
        raise ApplicationError("V3_CALLBACK_HMAC_KEY is not configured.", non_retryable=True)
    adapter = KnowledgeSourceAdapter()
    runner = PromptCycleRunner(settings.laravel_api_origin, settings.callback_hmac_key, adapter)
    try:
        result = await runner.run_cycle(
            case_id=str(payload["case_id"]),
            work_ordinal=int(payload["work_ordinal"]),
            binding_ref=str(payload["binding_ref"]),
        )
    except Exception as exc:
        # Never claim a successful prompt stage on transport, parse or storage
        # failure. Activity retry policy owns only technical retries.
        raise ApplicationError("Prompt generation did not complete.", non_retryable=False) from exc
    return result


@activity.defn(name="qualify_prompts")
async def qualify_prompts(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Execute typed JEV qualification and feedback revision loop per Section."""
    case_id = payload.get("case_id")
    if not case_id:
        raise ApplicationError("case_id is required for prompt qualification.", non_retryable=True)
    work_ordinal = int(payload.get("work_ordinal", 1))

    settings = get_settings()
    if not settings.callback_hmac_key:
        raise ApplicationError("V3_CALLBACK_HMAC_KEY is not configured.", non_retryable=True)

    client = HttpLaravelPromptQualificationClient()
    jev_client = TypeSafeJevClient()
    if not settings.typesafe_api_key:
        return {
            "status": "uncertain",
            "error_code": "provider_unavailable",
            "qualified_count": 0,
            "blocked_count": 0,
            "passing_prompt_refs": [],
        }
    if not settings.jev_model.strip():
        raise ApplicationError("JEV_MODEL is not configured.", non_retryable=True)

    # Determine prompt versions to qualify:
    prompt_version_refs = payload.get("prompt_version_refs")
    if not prompt_version_refs:
        try:
            prompt_version_refs = await client.get_work_prompt_versions(case_id, work_ordinal)
        except Exception as exc:
            raise ApplicationError(f"Failed to fetch work prompt versions: {exc}", non_retryable=False) from exc

    if not prompt_version_refs:
        raise ApplicationError(f"No prompt versions found for work ordinal {work_ordinal}.", non_retryable=True)

    if not QUESTIONS_PATH.exists():
        raise ApplicationError(f"Prompt questions asset not found at {QUESTIONS_PATH}", non_retryable=True)
    questions_bytes = QUESTIONS_PATH.read_bytes()
    question_sha = hashlib.sha256(questions_bytes).hexdigest()

    passing_prompt_refs: List[str] = []
    blocked_count = 0

    for initial_ref in prompt_version_refs:
        current_ref = initial_ref
        while True:
            # 1. Fetch qualification context
            try:
                qual_ctx = await client.get_qualification_context(case_id, current_ref)
            except Exception as exc:
                raise ApplicationError(f"Failed to fetch qualification context for {current_ref}: {exc}", non_retryable=False) from exc

            prompt_text = qual_ctx["prompt"]
            fields = qual_ctx["fields"]
            coverage_pages = qual_ctx.get("coverage_pages", [])
            settings_checksum = qual_ctx["settings_checksum"]
            requested_model = settings.jev_model

            prompt_body_sha = hashlib.sha256(prompt_text.encode("utf-8")).hexdigest()
            section_fields_sha = compute_section_fields_sha256(fields)

            request_sha = compute_jev_request_digest(
                prompt_version_ref=current_ref,
                prompt_body_sha256=prompt_body_sha,
                section_fields_sha256=section_fields_sha,
                settings_checksum=settings_checksum,
                question_sha256=question_sha,
                requested_model=requested_model,
            )

            # 2. Claim JEV attempt
            try:
                claim_res = await client.claim_jev_attempt(
                    case_id=case_id,
                    prompt_version_ref=current_ref,
                    payload={
                        "schema_version": 1,
                        "request_sha256": request_sha,
                        "question_sha256": question_sha,
                        "requested_model": requested_model,
                    },
                )
            except Exception as exc:
                raise ApplicationError(f"Failed to claim JEV attempt for {current_ref}: {exc}", non_retryable=False) from exc

            attempt_ref = claim_res["attempt_ref"]
            if claim_res.get("disposition") != "send_now":
                # The provider request may already have been sent. A duplicate
                # Temporal activity must never issue a second billable JEV call.
                return {
                    "status": "uncertain",
                    "error_code": "prompt_jev_uncertain",
                    "qualified_count": len(passing_prompt_refs),
                    "blocked_count": blocked_count,
                    "passing_prompt_refs": passing_prompt_refs,
                }

            # 3. Evaluate prompt with TypeSafe JEV
            state = {
                "section": fields,
                "coverage_pages": coverage_pages,
                "prompt": prompt_text,
            }

            try:
                jev_response = await jev_client.evaluate_prompt(state=state, model=requested_model)
            except JevTechnicalError as exc:
                try:
                    await client.record_jev_technical_failure(
                        case_id=case_id,
                        attempt_ref=attempt_ref,
                        payload={
                            "schema_version": 1,
                            "error_code": exc.error_code,
                        },
                    )
                except Exception:
                    pass
                raise ApplicationError(f"Technical failure evaluating prompt: {exc}", non_retryable=False) from exc
            except Exception as exc:
                raise ApplicationError(f"Error calling TypeSafe JEV: {exc}", non_retryable=False) from exc

            # 4. Record result in Laravel
            try:
                result_payload = {
                    "schema_version": 1,
                    "response": jev_response,
                    "request_sha256": request_sha,
                }
                last_error = None
                # Retry persistence of this exact response, never the provider call.
                for attempt in range(3):
                    try:
                        result_res = await client.record_jev_result(
                            case_id=case_id,
                            attempt_ref=attempt_ref,
                            payload=result_payload,
                        )
                        last_error = None
                        break
                    except Exception as exc:
                        last_error = exc
                        if attempt < 2:
                            await asyncio.sleep(0.2 * (attempt + 1))
                if last_error is not None:
                    raise last_error
            except Exception as exc:
                raise ApplicationError(f"Failed to record JEV result for attempt {attempt_ref}: {exc}", non_retryable=False) from exc

            decision = result_res.get("decision")
            if decision == "pass":
                passing_prompt_refs.append(current_ref)
                break
            elif decision == "block":
                blocked_count += 1
                break
            elif decision == "revise":
                # 5. Feedback revision loop
                try:
                    fb_ctx = await client.get_feedback_context(case_id, current_ref)
                except Exception as exc:
                    raise ApplicationError(f"Failed to get feedback context for {current_ref}: {exc}", non_retryable=False) from exc

                qual_ans_sha = fb_ctx.get("qualification_answer_sha256") or fb_ctx.get("qualification_answer_sha")
                template_id = fb_ctx["template_id"]
                template_sha = fb_ctx["template_sha256"]
                template_bytes = fb_ctx["template_bytes"]
                provider_desc = fb_ctx["provider"]

                fb_request_sha = compute_feedback_request_digest(
                    failed_version_uuid=current_ref,
                    qualification_answer_sha=qual_ans_sha,
                    captured_template_sha=template_sha,
                    provider_id=provider_desc["id"],
                    provider_model=provider_desc["model"],
                    credential_ref=provider_desc["credential_ref"],
                )

                try:
                    claim_fb_res = await client.claim_feedback(
                        case_id=case_id,
                        prompt_version_ref=current_ref,
                        payload={
                            "schema_version": 1,
                            "qualification_ref": fb_ctx["qualification_ref"],
                            "template_id": template_id,
                            "template_sha256": template_sha,
                            "provider_id": provider_desc["id"],
                            "provider_model": provider_desc["model"],
                            "credential_ref": provider_desc["credential_ref"],
                            "request_sha256": fb_request_sha,
                        },
                    )
                except Exception as exc:
                    raise ApplicationError(f"Failed to claim feedback for {current_ref}: {exc}", non_retryable=False) from exc

                effect_ref = claim_fb_res["effect_ref"]
                if claim_fb_res.get("disposition") != "send_now":
                    return {
                        "status": "uncertain",
                        "error_code": "prompt_feedback_uncertain",
                        "qualified_count": len(passing_prompt_refs),
                        "blocked_count": blocked_count,
                        "passing_prompt_refs": passing_prompt_refs,
                    }

                user_payload = {
                    "section_ref": fb_ctx["section_ref"],
                    "fields": fb_ctx["fields"],
                    "previous_prompt": fb_ctx["previous_prompt"],
                    "failed_checks": fb_ctx["failed_checks"],
                    "typed_values": fb_ctx["typed_values"],
                }

                try:
                    rev_res = await FeedbackTextAdapter.revise(
                        descriptor=provider_desc,
                        template_bytes=template_bytes,
                        user_payload=user_payload,
                        effect_ref=effect_ref,
                    )
                except Exception as exc:
                    safe_error_code = "timeout" if "timeout" in str(exc).lower() else "provider_unavailable"
                    try:
                        await client.record_feedback_uncertain(
                            case_id,
                            effect_ref,
                            {"schema_version": 1, "error_code": safe_error_code},
                        )
                    except Exception:
                        pass
                    return {
                        "status": "uncertain",
                        "error_code": "prompt_feedback_uncertain",
                        "qualified_count": len(passing_prompt_refs),
                        "blocked_count": blocked_count,
                        "passing_prompt_refs": passing_prompt_refs,
                    }

                try:
                    post_fb_res = await client.record_feedback_result(
                        case_id=case_id,
                        effect_ref=effect_ref,
                        payload={
                            "schema_version": 1,
                            "revised_prompt": rev_res.text,
                            "output_sha256": rev_res.output_sha256,
                            "provider_response_json": rev_res.raw_response_json,
                            "provider_response_sha256": rev_res.raw_response_sha256,
                            "returned_model": rev_res.returned_model,
                            "provider_response_id": rev_res.provider_response_id,
                        },
                    )
                except Exception as exc:
                    raise ApplicationError(f"Failed to record feedback result: {exc}", non_retryable=False) from exc

                # Advance to successor version
                current_ref = post_fb_res["prompt_version_ref"]
            else:
                raise ApplicationError(f"Unexpected JEV decision '{decision}' for {current_ref}", non_retryable=True)

    return {
        "status": "completed",
        "qualified_count": len(passing_prompt_refs),
        "blocked_count": blocked_count,
        "passing_prompt_refs": passing_prompt_refs,
    }


@activity.defn(name="resolve_qualified_prompt")
async def resolve_qualified_prompt(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Call signed 010 QualifiedPromptResolver at 011 handoff boundary."""
    case_id = payload["case_id"]
    prompt_version_ref = payload["prompt_version_ref"]

    client = HttpLaravelPromptQualificationClient()
    try:
        data = await client.resolve_qualified_prompt(case_id, prompt_version_ref)
        return {
            "status": "resolved",
            "case_id": data.get("case_id"),
            "section_ref": data.get("section_ref"),
            "prompt_version_ref": data.get("prompt_version_ref"),
            "body_sha256": data.get("body_sha256"),
        }
    except PromptClientError as exc:
        if exc.status_code == 404:
            raise ApplicationError(f"Prompt unavailable: {exc.response_text}", non_retryable=True)
        raise ApplicationError(f"Server error {exc.status_code}: {exc.response_text}", non_retryable=False)
    except Exception as exc:
        raise ApplicationError(f"Network error resolving qualified prompt: {str(exc)}", non_retryable=False)
