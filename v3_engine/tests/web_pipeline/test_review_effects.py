"""Unit and integration tests for review effects and correction routing (Spec 019 US4 T046)."""

from __future__ import annotations

import uuid
import pytest

from v3_engine.web_pipeline.review_effects import (
    CorrectionRoutingRequest,
    CorrectionExecutionResult,
    route_review_correction,
    verify_decision_consumed,
)


def test_exact_decision_consumption_required_not_signal_ack():
    """FR-017 / A18: Signal ACK (202 accepted) or closed workflow cannot replace exact query receipt."""
    decision_ref = str(uuid.uuid4())
    workflow_id = f"v3-review-correction-{uuid.uuid4()}"

    # 1. Query returns 200 but consumed is False -> False
    def mock_query_not_consumed(wf_id: str, dec_ref: str) -> dict:
        return {"status": 200, "consumed": False}

    assert verify_decision_consumed(mock_query_not_consumed, workflow_id, decision_ref) is False

    # 2. Query returns 503 error -> False
    def mock_query_503(wf_id: str, dec_ref: str) -> dict:
        return {"status": 503, "error": "Temporal service unavailable"}

    assert verify_decision_consumed(mock_query_503, workflow_id, decision_ref) is False

    # 3. Query throws exception -> False
    def mock_query_exception(wf_id: str, dec_ref: str) -> dict:
        raise RuntimeError("Connection dropped")

    assert verify_decision_consumed(mock_query_exception, workflow_id, decision_ref) is False

    # 4. Query strictly returns consumed: True -> True
    def mock_query_consumed(wf_id: str, dec_ref: str) -> dict:
        assert wf_id == workflow_id
        assert dec_ref == decision_ref
        return {"status": 200, "consumed": True}

    assert verify_decision_consumed(mock_query_consumed, workflow_id, decision_ref) is True


def test_closed_workflow_cannot_fabricate_receipt():
    """FR-017 / A18: 404 or missing workflow cannot fabricate receipt."""
    decision_ref = str(uuid.uuid4())
    workflow_id = "non-existent-wf"

    def mock_query_404(wf_id: str, dec_ref: str) -> dict:
        return {"status": 404, "consumed": None, "message": "Workflow not found"}

    assert verify_decision_consumed(mock_query_404, workflow_id, decision_ref) is False
    assert verify_decision_consumed(mock_query_404, "", decision_ref) is False
    assert verify_decision_consumed(mock_query_404, workflow_id, "") is False


def test_manual_mode_waits_replacement_zero_paid_calls():
    """FR-018 / A19: In manual mode, teacher correction must await manual replacement without paid calls."""
    req = CorrectionRoutingRequest(
        schema_version=1,
        case_id=str(uuid.uuid4()),
        section_ref=str(uuid.uuid4()),
        route_ref=str(uuid.uuid4()),
        correction_ref=str(uuid.uuid4()),
        feedback_kind="visual_interaction",
        mode="export_manual",
        notes="Please make the orbit line neon cyan instead of grey.",
        current_prompt_version_ref=str(uuid.uuid4()),
        current_candidate_ref=str(uuid.uuid4()),
    )

    paid_call_tracker = {"calls": 0}

    def mock_authoring(payload: dict) -> dict:
        paid_call_tracker["calls"] += 1
        return {"status": "candidate_ready", "candidate_ref": str(uuid.uuid4())}

    def mock_prompt_revise(payload: dict) -> dict:
        paid_call_tracker["calls"] += 1
        return {"status": "recorded", "prompt_version_ref": str(uuid.uuid4())}

    result = route_review_correction(
        req,
        authoring_fn=mock_authoring,
        prompt_revise_fn=mock_prompt_revise,
    )

    # In manual mode, zero paid calls must be executed
    assert result.paid_api_calls == 0
    assert paid_call_tracker["calls"] == 0
    assert result.status == "awaiting_manual_replacement"
    assert result.disposition == "manual_hold"
    assert result.prompt_version_ref == req.current_prompt_version_ref
    assert result.successor_candidate_ref is None
    assert result.exported_instruction_sha256 is not None
    assert "Teacher Correction Instructions" in (result.exported_instructions or "")
    assert "neon cyan" in (result.exported_instructions or "")


def test_api_mode_visual_correction_preserves_prompt():
    """FR-018 / A19: In API mode, visual_interaction preserves current prompt and calls repair authoring."""
    current_prompt = str(uuid.uuid4())
    successor_candidate = str(uuid.uuid4())
    req = CorrectionRoutingRequest(
        schema_version=1,
        case_id=str(uuid.uuid4()),
        section_ref=str(uuid.uuid4()),
        route_ref=str(uuid.uuid4()),
        correction_ref=str(uuid.uuid4()),
        feedback_kind="visual_interaction",
        mode="api",
        notes="Adjust camera orbit speed.",
        current_prompt_version_ref=current_prompt,
        current_candidate_ref=str(uuid.uuid4()),
    )

    authoring_called = []

    def mock_authoring(payload: dict) -> dict:
        authoring_called.append(payload)
        return {"status": "candidate_ready", "candidate_ref": successor_candidate}

    def mock_browser_check(payload: dict) -> dict:
        assert payload["candidate_ref"] == successor_candidate
        assert payload["prompt_version_ref"] == current_prompt
        return {"verdict": "pass"}

    result = route_review_correction(
        req,
        authoring_fn=mock_authoring,
        browser_check_fn=mock_browser_check,
    )

    assert result.status == "candidate_ready"
    assert result.paid_api_calls == 1
    assert result.prompt_version_ref == current_prompt
    assert result.successor_candidate_ref == successor_candidate
    assert len(authoring_called) == 1
    assert authoring_called[0]["kind"] == "visual_repair"


def test_api_mode_factual_correction_revises_prompt():
    """FR-018 / A19: In API mode, factual_source revises prompt first then generates candidate."""
    old_prompt = str(uuid.uuid4())
    new_prompt = str(uuid.uuid4())
    successor_candidate = str(uuid.uuid4())
    pdf_sha = "abc123pdfsha"

    req = CorrectionRoutingRequest(
        schema_version=1,
        case_id=str(uuid.uuid4()),
        section_ref=str(uuid.uuid4()),
        route_ref=str(uuid.uuid4()),
        correction_ref=str(uuid.uuid4()),
        feedback_kind="factual_source",
        mode="api",
        notes="Orbit eccentricity for Mars is 0.0934, not 0.2.",
        current_prompt_version_ref=old_prompt,
        current_candidate_ref=str(uuid.uuid4()),
        captured_pdf_sha256=pdf_sha,
    )

    call_sequence = []

    def mock_prompt_revise(payload: dict) -> dict:
        call_sequence.append(("prompt_revise", payload))
        return {"status": "recorded", "prompt_version_ref": new_prompt}

    def mock_authoring(payload: dict) -> dict:
        call_sequence.append(("authoring", payload))
        assert payload["prompt_version_ref"] == new_prompt
        return {"status": "candidate_ready", "candidate_ref": successor_candidate}

    def mock_browser_check(payload: dict) -> dict:
        assert payload["candidate_ref"] == successor_candidate
        assert payload["prompt_version_ref"] == new_prompt
        return {"verdict": "pass"}

    result = route_review_correction(
        req,
        authoring_fn=mock_authoring,
        prompt_revise_fn=mock_prompt_revise,
        browser_check_fn=mock_browser_check,
    )

    assert result.status == "candidate_ready"
    assert result.paid_api_calls == 2
    assert result.prompt_version_ref == new_prompt
    assert result.successor_candidate_ref == successor_candidate
    assert [c[0] for c in call_sequence] == ["prompt_revise", "authoring"]
