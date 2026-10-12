"""Review effects, decision consumption verification, and correction routing for Spec 019 web pipelines."""

from __future__ import annotations

from dataclasses import dataclass, field
import hashlib
import json
from typing import Any, Callable, Dict, List, Optional


@dataclass(frozen=True)
class CorrectionRoutingRequest:
    schema_version: int
    case_id: str
    section_ref: str
    route_ref: str
    correction_ref: str
    feedback_kind: str  # visual_interaction, factual_source, mixed
    mode: str           # export_manual, api
    notes: str
    current_prompt_version_ref: str
    current_candidate_ref: str
    captured_pdf_sha256: Optional[str] = None
    provider_id: Optional[str] = None


@dataclass
class CorrectionExecutionResult:
    status: str  # awaiting_manual_replacement, candidate_ready, blocked, failed
    correction_ref: str
    route_ref: str
    feedback_kind: str
    disposition: str  # manual_hold, api_generated
    paid_api_calls: int
    prompt_version_ref: str
    successor_candidate_ref: Optional[str] = None
    exported_instruction_sha256: Optional[str] = None
    exported_instructions: Optional[str] = None
    error_code: Optional[str] = None


def verify_decision_consumed(
    query_fn: Callable[[str, str], Dict[str, Any]],
    workflow_id: str,
    decision_ref: str,
) -> bool:
    """
    Verify exact decision consumption against the Temporal workflow.
    A signal ACK (202 accepted) or workflow completion alone does NOT constitute proof.
    The workflow MUST explicitly return consumed=True for the exact decision_ref via query.
    """
    if not workflow_id or not decision_ref:
        return False
    try:
        response = query_fn(workflow_id, decision_ref)
        if not isinstance(response, dict):
            return False
        # Must be successful status (< 400) and consumed must be strictly True
        status = response.get("status", 200)
        if status >= 400:
            return False
        return response.get("consumed") is True
    except Exception:
        return False


def route_review_correction(
    req: CorrectionRoutingRequest,
    *,
    authoring_fn: Optional[Callable[[Dict[str, Any]], Dict[str, Any]]] = None,
    prompt_revise_fn: Optional[Callable[[Dict[str, Any]], Dict[str, Any]]] = None,
    browser_check_fn: Optional[Callable[[Dict[str, Any]], Dict[str, Any]]] = None,
) -> CorrectionExecutionResult:
    """
    Route and execute a teacher correction.
    - If mode is export_manual: enforces zero paid API calls, exports correction instructions,
      and leaves the route awaiting manual replacement import.
    - If mode is API: routes to visual repair (preserving prompt) or factual revision (revising prompt),
      executes candidate generation, and verifies browser checks.
    """
    if req.schema_version != 1:
        return CorrectionExecutionResult(
            status="blocked",
            correction_ref=req.correction_ref,
            route_ref=req.route_ref,
            feedback_kind=req.feedback_kind,
            disposition="rejected",
            paid_api_calls=0,
            prompt_version_ref=req.current_prompt_version_ref,
            error_code="contract_invalid",
        )

    # 1. Manual mode branch: zero remote API calls, export instructions, await manual import
    if req.mode == "export_manual":
        instruction_doc = (
            f"# Teacher Correction Instructions\n\n"
            f"- Section Ref: {req.section_ref}\n"
            f"- Correction Ref: {req.correction_ref}\n"
            f"- Route Ref: {req.route_ref}\n"
            f"- Feedback Category: {req.feedback_kind}\n"
            f"- Current Prompt Ref: {req.current_prompt_version_ref}\n"
            f"- Prior Candidate Ref: {req.current_candidate_ref}\n\n"
            f"## Teacher Feedback\n\n"
            f"{req.notes}\n\n"
            f"Please implement the requested corrections and manually import the replacement 3-file package."
        )
        doc_sha = hashlib.sha256(instruction_doc.encode("utf-8")).hexdigest()

        return CorrectionExecutionResult(
            status="awaiting_manual_replacement",
            correction_ref=req.correction_ref,
            route_ref=req.route_ref,
            feedback_kind=req.feedback_kind,
            disposition="manual_hold",
            paid_api_calls=0,
            prompt_version_ref=req.current_prompt_version_ref,
            successor_candidate_ref=None,
            exported_instruction_sha256=doc_sha,
            exported_instructions=instruction_doc,
            error_code=None,
        )

    # 2. API mode branch
    paid_calls = 0
    prompt_ref = req.current_prompt_version_ref

    if req.feedback_kind == "visual_interaction":
        # Visual repair preserves the current prompt
        if authoring_fn is None:
            return CorrectionExecutionResult(
                status="blocked",
                correction_ref=req.correction_ref,
                route_ref=req.route_ref,
                feedback_kind=req.feedback_kind,
                disposition="api_generated",
                paid_api_calls=0,
                prompt_version_ref=prompt_ref,
                error_code="authoring_handler_missing",
            )
        paid_calls += 1
        authored = authoring_fn({
            "case_id": req.case_id,
            "section_ref": req.section_ref,
            "prompt_version_ref": prompt_ref,
            "route_ref": req.route_ref,
            "kind": "visual_repair",
            "notes": req.notes,
        })
        cand_ref = authored.get("candidate_ref")
        if not cand_ref or authored.get("status") != "candidate_ready":
            return CorrectionExecutionResult(
                status="failed",
                correction_ref=req.correction_ref,
                route_ref=req.route_ref,
                feedback_kind=req.feedback_kind,
                disposition="api_generated",
                paid_api_calls=paid_calls,
                prompt_version_ref=prompt_ref,
                error_code=authored.get("error_code", "visual_repair_failed"),
            )

    else:
        # Factual or mixed correction: revises prompt first
        if prompt_revise_fn is None or authoring_fn is None:
            return CorrectionExecutionResult(
                status="blocked",
                correction_ref=req.correction_ref,
                route_ref=req.route_ref,
                feedback_kind=req.feedback_kind,
                disposition="api_generated",
                paid_api_calls=0,
                prompt_version_ref=prompt_ref,
                error_code="correction_handlers_missing",
            )
        revised = prompt_revise_fn({
            "case_id": req.case_id,
            "section_ref": req.section_ref,
            "source_prompt_version_ref": prompt_ref,
            "feedback_kind": req.feedback_kind,
            "notes": req.notes,
            "pdf_sha256": req.captured_pdf_sha256,
        })
        new_prompt_ref = revised.get("prompt_version_ref")
        if not new_prompt_ref or revised.get("status") != "recorded":
            return CorrectionExecutionResult(
                status="failed",
                correction_ref=req.correction_ref,
                route_ref=req.route_ref,
                feedback_kind=req.feedback_kind,
                disposition="api_generated",
                paid_api_calls=paid_calls,
                prompt_version_ref=prompt_ref,
                error_code=revised.get("error_code", "prompt_revision_failed"),
            )
        prompt_ref = new_prompt_ref
        paid_calls += 1

        authored = authoring_fn({
            "case_id": req.case_id,
            "section_ref": req.section_ref,
            "prompt_version_ref": prompt_ref,
            "route_ref": req.route_ref,
            "kind": "fresh_authoring",
            "notes": req.notes,
        })
        paid_calls += 1
        cand_ref = authored.get("candidate_ref")
        if not cand_ref or authored.get("status") != "candidate_ready":
            return CorrectionExecutionResult(
                status="failed",
                correction_ref=req.correction_ref,
                route_ref=req.route_ref,
                feedback_kind=req.feedback_kind,
                disposition="api_generated",
                paid_api_calls=paid_calls,
                prompt_version_ref=prompt_ref,
                error_code=authored.get("error_code", "authoring_failed"),
            )

    # Browser check verification on successor candidate
    if browser_check_fn is not None:
        check_res = browser_check_fn({
            "candidate_ref": cand_ref,
            "prompt_version_ref": prompt_ref,
        })
        if check_res.get("verdict") != "pass":
            return CorrectionExecutionResult(
                status="failed",
                correction_ref=req.correction_ref,
                route_ref=req.route_ref,
                feedback_kind=req.feedback_kind,
                disposition="api_generated",
                paid_api_calls=paid_calls,
                prompt_version_ref=prompt_ref,
                successor_candidate_ref=cand_ref,
                error_code="browser_checks_failed",
            )

    return CorrectionExecutionResult(
        status="candidate_ready",
        correction_ref=req.correction_ref,
        route_ref=req.route_ref,
        feedback_kind=req.feedback_kind,
        disposition="api_generated",
        paid_api_calls=paid_calls,
        prompt_version_ref=prompt_ref,
        successor_candidate_ref=cand_ref,
        error_code=None,
    )
