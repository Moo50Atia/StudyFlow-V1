import hashlib
import json
from pathlib import Path
import pytest
import httpx

from v3_engine.prompts.policy import (
    evaluate_jev_policy,
    validate_jev_response,
    compute_jev_request_digest,
    JevWireValidationError,
    JevTechnicalError,
    JevEvaluationResult,
)
from v3_engine.prompts.jev import TypeSafeJevClient

QUESTIONS_FILE = Path(__file__).resolve().parents[3] / "resources" / "v3" / "jev" / "prompt-questions-v1.json"


def test_question_asset_101_rubric():
    """Verify resources/v3/jev/prompt-questions-v1.json exists and has 101 levels."""
    assert QUESTIONS_FILE.exists(), f"Question asset not found at {QUESTIONS_FILE}"
    content = QUESTIONS_FILE.read_bytes()
    expected_hash = hashlib.sha256(content).hexdigest()
    assert len(expected_hash) == 64

    data = json.loads(content.decode("utf-8"))
    assert set(data.keys()) == {"choice", "score", "noul"}

    # Choice question
    choice_q = data["choice"]
    assert choice_q["type"] == "choice"
    assert "Classify the prompt against the supplied Section" in choice_q["instructions"]
    assert set(choice_q["criteria"].keys()) == {"pass", "revise", "block"}

    # Score question: exactly 101 levels (0 to 100)
    score_q = data["score"]
    assert score_q["type"] == "score"
    assert "Rate the prompt's source fidelity" in score_q["instructions"]
    criteria = score_q["criteria"]
    assert isinstance(criteria, list)
    assert len(criteria) == 101

    for idx, item in enumerate(criteria):
        assert item.startswith(f"{idx}/100: ")
        if 0 <= idx <= 19:
            assert "contradictory or missing learning scene" in item
        elif 20 <= idx <= 39:
            assert "major source or coverage gaps" in item
        elif 40 <= idx <= 59:
            assert "partial scene without clear misconception correction" in item
        elif 60 <= idx <= 79:
            assert "grounded visual scene with notable gaps" in item
        else:
            assert "grounded, complete, focused visual explanation" in item

    # Noul question
    noul_q = data["noul"]
    assert noul_q["type"] == "noul"
    assert "Does this exact prompt remain supported" in noul_q["instructions"]
    assert set(noul_q["criteria"].keys()) == {"true", "false"}


def test_typed_pass_at_exact_equality():
    """Exact equality to thresholds passes with no failed checks."""
    choice_answer = {
        "type": "choice",
        "choice": "pass",
        "confidence": 0.75,
        "probabilities": {"pass": 0.75, "revise": 0.20, "block": 0.05},
    }
    score_answer = {
        "type": "score",
        "score": 80.0,
        "confidence": 0.75,
        "probabilities": [0.0] * 101,
    }
    noul_answer = {
        "type": "noul",
        "noul": 0.75,
    }

    result = evaluate_jev_policy(
        choice_answer=choice_answer,
        score_answer=score_answer,
        noul_answer=noul_answer,
        min_score=80,
        min_confidence=75,
        revision_count=0,
        revision_limit=2,
    )

    assert result.decision == "pass"
    assert result.failed_checks == []
    assert result.choice == "pass"
    assert result.choice_confidence == 0.75
    assert result.score == 80.0
    assert result.score_confidence == 0.75
    assert result.noul_probability == 0.75
    assert result.noul_yes is True


def test_fractional_boundaries_unrounded():
    """Unrounded comparisons: 79.9999 < 80 fails score, 0.74999 < 0.75 fails confidence."""
    base_choice = {"type": "choice", "choice": "pass", "confidence": 0.75, "probabilities": {}}
    base_score = {"type": "score", "score": 80.0, "confidence": 0.75, "probabilities": []}
    base_noul = {"type": "noul", "noul": 0.75}

    # 1. Score just below threshold
    res_score = evaluate_jev_policy(
        choice_answer=base_choice,
        score_answer={**base_score, "score": 79.99999},
        noul_answer=base_noul,
        min_score=80,
        min_confidence=75,
        revision_count=0,
        revision_limit=2,
    )
    assert res_score.decision == "revise"
    assert res_score.failed_checks == ["score"]

    # 2. Choice confidence just below threshold (0.74999 * 100 < 75)
    res_cc = evaluate_jev_policy(
        choice_answer={**base_choice, "confidence": 0.74999},
        score_answer=base_score,
        noul_answer=base_noul,
        min_score=80,
        min_confidence=75,
        revision_count=0,
        revision_limit=2,
    )
    assert res_cc.decision == "revise"
    assert res_cc.failed_checks == ["choice_confidence"]

    # 3. Score confidence just below threshold
    res_sc = evaluate_jev_policy(
        choice_answer=base_choice,
        score_answer={**base_score, "confidence": 0.74999},
        noul_answer=base_noul,
        min_score=80,
        min_confidence=75,
        revision_count=0,
        revision_limit=2,
    )
    assert res_sc.decision == "revise"
    assert res_sc.failed_checks == ["score_confidence"]

    # 4. Noul just below 0.5 (0.49999) fails noul even with low min_confidence=40
    res_noul = evaluate_jev_policy(
        choice_answer=base_choice,
        score_answer=base_score,
        noul_answer={"type": "noul", "noul": 0.49999},
        min_score=80,
        min_confidence=40,
        revision_count=0,
        revision_limit=2,
    )
    assert res_noul.decision == "revise"
    assert "noul" in res_noul.failed_checks
    assert res_noul.noul_yes is False


def test_noul_yes_and_confidence():
    """Noul probability is both the yes decision (>= 0.5) and confidence (100*noul >= min_confidence)."""
    base_choice = {"type": "choice", "choice": "pass", "confidence": 0.9, "probabilities": {}}
    base_score = {"type": "score", "score": 90.0, "confidence": 0.9, "probabilities": []}

    # noul = 0.6: passes noul >= 0.5, but 100*0.6 = 60 < min_confidence (75) -> fails noul_confidence
    res_noul_conf = evaluate_jev_policy(
        choice_answer=base_choice,
        score_answer=base_score,
        noul_answer={"type": "noul", "noul": 0.60},
        min_score=80,
        min_confidence=75,
        revision_count=0,
        revision_limit=2,
    )
    assert res_noul_conf.decision == "revise"
    assert res_noul_conf.failed_checks == ["noul_confidence"]
    assert res_noul_conf.noul_yes is True

    # noul = 0.45: fails noul (< 0.5), and 45 < 75 fails noul_confidence
    res_both_fail = evaluate_jev_policy(
        choice_answer=base_choice,
        score_answer=base_score,
        noul_answer={"type": "noul", "noul": 0.45},
        min_score=80,
        min_confidence=75,
        revision_count=0,
        revision_limit=2,
    )
    assert res_both_fail.decision == "revise"
    assert "noul" in res_both_fail.failed_checks
    assert "noul_confidence" in res_both_fail.failed_checks
    assert res_both_fail.noul_yes is False


def test_choice_block_terminal_precedence():
    """Choice 'block' yields 'block' immediately, even with perfect scores and budget remaining."""
    choice_answer = {"type": "choice", "choice": "block", "confidence": 0.99, "probabilities": {}}
    score_answer = {"type": "score", "score": 100.0, "confidence": 0.99, "probabilities": []}
    noul_answer = {"type": "noul", "noul": 0.99}

    result = evaluate_jev_policy(
        choice_answer=choice_answer,
        score_answer=score_answer,
        noul_answer=noul_answer,
        min_score=80,
        min_confidence=75,
        revision_count=0,
        revision_limit=2,
    )

    assert result.decision == "block"
    assert "choice" in result.failed_checks
    # Even though revision budget remains, block is terminal!
    assert "revision_budget_exhausted" not in result.failed_checks


def test_low_policy_answer_with_remaining_budget_vs_exhausted():
    """Non-pass with budget returns revise; when budget is exhausted returns block with code."""
    choice_answer = {"type": "choice", "choice": "revise", "confidence": 0.8, "probabilities": {}}
    score_answer = {"type": "score", "score": 70.0, "confidence": 0.8, "probabilities": []}
    noul_answer = {"type": "noul", "noul": 0.8}

    # Case 1: Budget remaining (revision_count=1 < revision_limit=2)
    res_budget = evaluate_jev_policy(
        choice_answer=choice_answer,
        score_answer=score_answer,
        noul_answer=noul_answer,
        min_score=80,
        min_confidence=75,
        revision_count=1,
        revision_limit=2,
    )
    assert res_budget.decision == "revise"
    assert res_budget.failed_checks == ["choice", "score"]

    # Case 2: Budget exhausted (revision_count=2 == revision_limit=2)
    res_exhausted = evaluate_jev_policy(
        choice_answer=choice_answer,
        score_answer=score_answer,
        noul_answer=noul_answer,
        min_score=80,
        min_confidence=75,
        revision_count=2,
        revision_limit=2,
    )
    assert res_exhausted.decision == "block"
    assert "choice" in res_exhausted.failed_checks
    assert "score" in res_exhausted.failed_checks
    assert "revision_budget_exhausted" in res_exhausted.failed_checks


def test_wire_validation_malformed_answers():
    """Verify wire validation rejects missing keys, wrong types, and non-finite values."""
    # 1. Missing answer keys
    with pytest.raises(JevWireValidationError, match="answers must contain"):
        validate_jev_response({"model": "m1", "answers": {"choice": {}}})

    # 2. Invalid choice value
    invalid_choice_resp = {
        "model": "m1",
        "answers": {
            "choice": {"type": "choice", "choice": "maybe", "confidence": 0.8},
            "score": {"type": "score", "score": 80.0, "confidence": 0.8},
            "noul": {"type": "noul", "noul": 0.8},
        },
    }
    with pytest.raises(JevWireValidationError, match="Invalid choice value"):
        validate_jev_response(invalid_choice_resp)

    # 3. Score out of range (negative or > 100)
    invalid_score_resp = {
        "model": "m1",
        "answers": {
            "choice": {"type": "choice", "choice": "pass", "confidence": 0.8},
            "score": {"type": "score", "score": 105.0, "confidence": 0.8},
            "noul": {"type": "noul", "noul": 0.8},
        },
    }
    with pytest.raises(JevWireValidationError, match="Score must be in"):
        validate_jev_response(invalid_score_resp)

    # 4. Confidence out of range
    invalid_conf_resp = {
        "model": "m1",
        "answers": {
            "choice": {"type": "choice", "choice": "pass", "confidence": 1.5},
            "score": {"type": "score", "score": 80.0, "confidence": 0.8},
            "noul": {"type": "noul", "noul": 0.8},
        },
    }
    with pytest.raises(JevWireValidationError, match="Confidence must be in"):
        validate_jev_response(invalid_conf_resp)

    # 5. Valid wire passes
    valid_resp = {
        "model": "typesafe-systemone-v1",
        "answers": {
            "choice": {"type": "choice", "choice": "pass", "confidence": 0.9, "probabilities": {"pass": 0.9}},
            "score": {"type": "score", "score": 85.0, "confidence": 0.85, "probabilities": [0.0] * 101},
            "noul": {"type": "noul", "noul": 0.88},
        },
    }
    c, s, n = validate_jev_response(valid_resp)
    assert c["choice"] == "pass"
    assert s["score"] == 85.0
    assert n["noul"] == 0.88


@pytest.mark.asyncio
async def test_technical_failures_no_verdict():
    """Verify HTTP/network technical failures raise JevTechnicalError with no verdict produced."""
    client = TypeSafeJevClient(api_key="test-key", base_url="https://api.typesafe.ai")

    # 401 Unauthorized
    transport_401 = httpx.MockTransport(lambda req: httpx.Response(401, json={"error": "unauthorized"}))
    async with httpx.AsyncClient(transport=transport_401) as http_client:
        with pytest.raises(JevTechnicalError) as exc_info:
            await client.evaluate_prompt(state={"test": 1}, client=http_client)
        assert exc_info.value.error_code == "provider_unavailable"
        assert exc_info.value.status_code == 401

    # 429 Rate Limit
    transport_429 = httpx.MockTransport(lambda req: httpx.Response(429, json={"error": "rate limit"}))
    async with httpx.AsyncClient(transport=transport_429) as http_client:
        with pytest.raises(JevTechnicalError) as exc_info:
            await client.evaluate_prompt(state={"test": 1}, client=http_client)
        assert exc_info.value.error_code == "provider_unavailable"
        assert exc_info.value.status_code == 429

    # 503 Service Unavailable
    transport_503 = httpx.MockTransport(lambda req: httpx.Response(503, json={"error": "unavailable"}))
    async with httpx.AsyncClient(transport=transport_503) as http_client:
        with pytest.raises(JevTechnicalError) as exc_info:
            await client.evaluate_prompt(state={"test": 1}, client=http_client)
        assert exc_info.value.error_code == "provider_unavailable"
        assert exc_info.value.status_code == 503

    # Timeout
    def timeout_handler(req):
        raise httpx.ReadTimeout("Read timed out")

    transport_timeout = httpx.MockTransport(timeout_handler)
    async with httpx.AsyncClient(transport=transport_timeout) as http_client:
        with pytest.raises(JevTechnicalError) as exc_info:
            await client.evaluate_prompt(state={"test": 1}, client=http_client)
        assert exc_info.value.error_code == "timeout"


def test_request_digest_stability():
    """Verify request digest adheres to exact SHA-256 specification."""
    prompt_version = "11111111-1111-4111-8111-111111111111"
    prompt_body_sha = "a" * 64
    section_fields_sha = "b" * 64
    settings_checksum = "c" * 64
    question_sha = "d" * 64
    requested_model = "typesafe-systemone-v1"

    expected_payload = (
        f"010-jev-v1\n"
        f"{prompt_version}\n"
        f"{prompt_body_sha}\n"
        f"{section_fields_sha}\n"
        f"{settings_checksum}\n"
        f"{question_sha}\n"
        f"{requested_model}"
    )
    expected_digest = hashlib.sha256(expected_payload.encode("utf-8")).hexdigest()

    digest = compute_jev_request_digest(
        prompt_version_ref=prompt_version,
        prompt_body_sha256=prompt_body_sha,
        section_fields_sha256=section_fields_sha,
        settings_checksum=settings_checksum,
        question_sha256=question_sha,
        requested_model=requested_model,
    )

    assert digest == expected_digest


def test_section_fields_sha256_canonical_and_crlf():
    """Verify section fields digest normalizes CRLF and encodes UTF-8 properly."""
    from v3_engine.prompts.policy import compute_section_fields_sha256

    fields = {
        "section_identity": "Wave Propagation\r\nPart 1",
        "core_understanding": "Waves transfer energy\rwithout transferring matter.",
        "visual_first_explanation": "Visual particles oscillating.",
        "visual_scene_script": "Particles connected by springs.",
        "dynamic_view_blueprint": "Wave pattern.",
        "visual_learning_outcome": "Particle vs wave motion.",
        "concept_check": "Do particles travel with the wave?",
        "conceptual_summary": "Energy travels.",
        "colloquial_egyptian_explanation": "الموجة بتنقل الطاقة مش المادة.",
        "laws_if_any": "",
        "real_life_example": "Stadium wave.",
    }
    digest = compute_section_fields_sha256(fields)
    assert len(digest) == 64

    # Compare with LF normalized version
    normalized_fields = {
        k: v.replace("\r\n", "\n").replace("\r", "\n")
        for k, v in fields.items()
    }
    digest2 = compute_section_fields_sha256(normalized_fields)
    assert digest == digest2


@pytest.mark.asyncio
async def test_evaluate_prompt_with_section_fields_and_coverage_pages():
    """Verify TypeSafeJevClient packs section_fields, coverage_pages and prompt_text into state."""
    captured_payload = None

    def handler(request: httpx.Request):
        nonlocal captured_payload
        captured_payload = json.loads(request.content.decode("utf-8"))
        return httpx.Response(
            200,
            json={
                "model": "typesafe-systemone-v1",
                "answers": {
                    "choice": {"type": "choice", "choice": "pass", "confidence": 0.9},
                    "score": {"type": "score", "score": 90.0, "confidence": 0.9},
                    "noul": {"type": "noul", "noul": 0.95},
                },
            },
        )

    client = TypeSafeJevClient(api_key="test-key", base_url="https://api.typesafe.ai")
    async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as http_client:
        res = await client.evaluate_prompt(
            section_fields={"section_identity": "Sec1"},
            coverage_pages=[1, 2],
            prompt_text="Process: Wave motion.",
            client=http_client,
        )

    assert res["model"] == "typesafe-systemone-v1"
    assert captured_payload is not None
    assert captured_payload["state"]["section"] == {"section_identity": "Sec1"}
    assert captured_payload["state"]["coverage_pages"] == [1, 2]
    assert captured_payload["state"]["prompt"] == "Process: Wave motion."

