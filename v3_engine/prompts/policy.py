import hashlib
import math
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Tuple


class JevWireValidationError(ValueError):
    """Raised when JEV response wire structure or types violate OpenAPI contract."""
    pass


class JevTechnicalError(Exception):
    """Raised on HTTP or network technical failures (401/429/5xx/timeout/policy_denied)."""
    def __init__(self, error_code: str, message: str, status_code: Optional[int] = None):
        super().__init__(message)
        self.error_code = error_code
        self.status_code = status_code


@dataclass(frozen=True)
class JevEvaluationResult:
    decision: str
    failed_checks: List[str]
    choice: str
    choice_confidence: float
    score: float
    score_confidence: float
    noul_probability: float
    noul_yes: bool


ELEVEN_FIELDS: List[str] = [
    "section_identity",
    "core_understanding",
    "visual_first_explanation",
    "visual_scene_script",
    "dynamic_view_blueprint",
    "visual_learning_outcome",
    "concept_check",
    "conceptual_summary",
    "colloquial_egyptian_explanation",
    "laws_if_any",
    "real_life_example",
]


def compute_section_fields_sha256(fields: Dict[str, Any]) -> str:
    """Compute SHA-256 digest of 11 section fields per 010 specification."""
    buffer = "010-section-v1\n"
    for field_name in ELEVEN_FIELDS:
        val = str(fields.get(field_name, "")).replace("\r\n", "\n").replace("\r", "\n")
        val_bytes = val.encode("utf-8")
        buffer += f"{field_name}\n{len(val_bytes)}:{val}\n"
    return hashlib.sha256(buffer.encode("utf-8")).hexdigest()


def compute_jev_request_digest(
    prompt_version_ref: str,
    prompt_body_sha256: str,
    section_fields_sha256: str,
    settings_checksum: str,
    question_sha256: str,
    requested_model: str,
) -> str:
    """Compute SHA-256 digest of JEV request per 010 specification."""
    payload = (
        f"010-jev-v1\n"
        f"{prompt_version_ref}\n"
        f"{prompt_body_sha256}\n"
        f"{section_fields_sha256}\n"
        f"{settings_checksum}\n"
        f"{question_sha256}\n"
        f"{requested_model}"
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def validate_jev_response(response: Dict[str, Any]) -> Tuple[Dict[str, Any], Dict[str, Any], Dict[str, Any]]:
    """Validate TypeSafe SystemOne response wire structure and types.
    
    Returns (choice_answer, score_answer, noul_answer).
    Raises JevWireValidationError on missing keys, invalid types, or out-of-bounds numbers.
    """
    if not isinstance(response, dict):
        raise JevWireValidationError("JEV response must be a JSON object.")

    model = response.get("model")
    if not model or not isinstance(model, str):
        raise JevWireValidationError("JEV response model must be a non-empty string.")

    answers = response.get("answers")
    if not isinstance(answers, dict):
        raise JevWireValidationError("JEV response answers must be a JSON object.")

    if not {"choice", "score", "noul"}.issubset(answers.keys()):
        raise JevWireValidationError("JEV response answers must contain 'choice', 'score', and 'noul'.")

    choice_ans = answers["choice"]
    score_ans = answers["score"]
    noul_ans = answers["noul"]

    if not isinstance(choice_ans, dict) or not isinstance(score_ans, dict) or not isinstance(noul_ans, dict):
        raise JevWireValidationError("Each answer in JEV response must be a JSON object.")

    # 1. Choice Answer validation
    if choice_ans.get("type") != "choice":
        raise JevWireValidationError("Choice answer type must be 'choice'.")
    choice_val = choice_ans.get("choice")
    if choice_val not in ("pass", "revise", "block"):
        raise JevWireValidationError(f"Invalid choice value '{choice_val}': must be 'pass', 'revise', or 'block'.")
    choice_conf = choice_ans.get("confidence")
    if not isinstance(choice_conf, (int, float)) or not math.isfinite(choice_conf) or not (0.0 <= choice_conf <= 1.0):
        raise JevWireValidationError(f"Confidence must be in [0, 1], got '{choice_conf}'.")

    # 2. Score Answer validation
    if score_ans.get("type") != "score":
        raise JevWireValidationError("Score answer type must be 'score'.")
    score_val = score_ans.get("score")
    if not isinstance(score_val, (int, float)) or not math.isfinite(score_val) or not (0.0 <= score_val <= 100.0):
        raise JevWireValidationError(f"Score must be in [0, 100], got '{score_val}'.")
    score_conf = score_ans.get("confidence")
    if not isinstance(score_conf, (int, float)) or not math.isfinite(score_conf) or not (0.0 <= score_conf <= 1.0):
        raise JevWireValidationError(f"Confidence must be in [0, 1], got '{score_conf}'.")

    # 3. Noul Answer validation
    if noul_ans.get("type") != "noul":
        raise JevWireValidationError("Noul answer type must be 'noul'.")
    noul_val = noul_ans.get("noul")
    if not isinstance(noul_val, (int, float)) or not math.isfinite(noul_val) or not (0.0 <= noul_val <= 1.0):
        raise JevWireValidationError(f"Noul probability must be in [0, 1], got '{noul_val}'.")

    return choice_ans, score_ans, noul_ans


def evaluate_jev_policy(
    choice_answer: Dict[str, Any],
    score_answer: Dict[str, Any],
    noul_answer: Dict[str, Any],
    min_score: float,
    min_confidence: float,
    revision_count: int,
    revision_limit: int,
) -> JevEvaluationResult:
    """Evaluate JEV policy directly on unrounded typed answers.
    
    Pass iff:
        choice == 'pass'
        score >= min_score
        100 * choice.confidence >= min_confidence
        100 * score.confidence >= min_confidence
        noul >= 0.5
        100 * noul >= min_confidence
    
    Choice 'block' yields 'block' with terminal precedence.
    Other non-passes yield 'revise' if budget remains, else 'block' with 'revision_budget_exhausted'.
    """
    choice = str(choice_answer.get("choice", ""))
    choice_conf = float(choice_answer.get("confidence", 0.0))
    score = float(score_answer.get("score", 0.0))
    score_conf = float(score_answer.get("confidence", 0.0))
    noul_prob = float(noul_answer.get("noul", 0.0))
    noul_yes = noul_prob >= 0.5

    failed_checks: List[str] = []

    # Check 1: choice
    if choice != "pass":
        failed_checks.append("choice")

    # Check 2: score
    if score < min_score:
        failed_checks.append("score")

    # Check 3: choice_confidence
    if (100.0 * choice_conf) < min_confidence:
        failed_checks.append("choice_confidence")

    # Check 4: score_confidence
    if (100.0 * score_conf) < min_confidence:
        failed_checks.append("score_confidence")

    # Check 5: noul (must be >= 0.5)
    if not noul_yes:
        failed_checks.append("noul")

    # Check 6: noul_confidence (100 * noul >= min_confidence)
    if (100.0 * noul_prob) < min_confidence:
        failed_checks.append("noul_confidence")

    # Decision derivation
    if len(failed_checks) == 0:
        decision = "pass"
    elif choice == "block":
        # Choice 'block' is terminal regardless of budget
        decision = "block"
    else:
        # Check budget
        if revision_count < revision_limit:
            decision = "revise"
        else:
            decision = "block"
            failed_checks.append("revision_budget_exhausted")

    return JevEvaluationResult(
        decision=decision,
        failed_checks=failed_checks,
        choice=choice,
        choice_confidence=choice_conf,
        score=score,
        score_confidence=score_conf,
        noul_probability=noul_prob,
        noul_yes=noul_yes,
    )
