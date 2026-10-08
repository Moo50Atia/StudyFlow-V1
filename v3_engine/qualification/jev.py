"""
Scene JEV client and wire validation for V3 Scene Qualification.
Governed by specs/012-v3-scene-qualification/contracts/scene-qualification.md.
"""

import hashlib
import json
import math
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
import httpx

SCENE_QUESTIONS_PATH = Path(__file__).resolve().parents[2] / "resources" / "v3" / "jev" / "scene-questions-v1.json"
MAX_EVIDENCE_BYTES = 6 * 1024 * 1024 # 6 MiB

class SceneJevWireValidationError(ValueError):
    """Raised when JEV response wire structure or types violate OpenAPI contract."""
    pass

class SceneJevTechnicalError(Exception):
    """Raised on HTTP, network, or evidence-limit technical failures."""
    def __init__(self, error_code: str, message: str, status_code: Optional[int] = None):
        super().__init__(message)
        self.error_code = error_code
        self.status_code = status_code


def compute_scene_jev_request_digest(
    candidate_id: str,
    manifest_sha: str,
    report_sha: str,
    evidence_sha: str,
    question_sha: str,
    settings_checksum: str,
    requested_model: str,
) -> str:
    """Compute SHA-256 digest of scene JEV request per 012 contract."""
    payload = (
        f"012-jev-v1\n"
        f"{candidate_id}\n"
        f"{manifest_sha}\n"
        f"{report_sha}\n"
        f"{evidence_sha}\n"
        f"{question_sha}\n"
        f"{settings_checksum}\n"
        f"{requested_model}"
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def validate_scene_jev_response(response: Dict[str, Any]) -> Tuple[Dict[str, Any], Dict[str, Any], Dict[str, Any]]:
    """Validate the current TypeSafe SystemOne typed response used by Spec 012.

    The official wire names are Choice ``choice``, Score ``score`` and Noul
    ``noul``. Do not accept older prose/value aliases: successful typed JEV
    answers are authoritative and malformed wire must remain a technical error.
    """
    if not isinstance(response, dict):
        raise SceneJevWireValidationError("JEV response must be a JSON object.")

    model = response.get("model")
    if not isinstance(model, str) or not model.strip():
        raise SceneJevWireValidationError("JEV response missing a non-empty model name.")

    answers = response.get("answers")
    if not isinstance(answers, dict):
        raise SceneJevWireValidationError("JEV response missing 'answers' object.")

    if set(answers) != {"choice", "score", "noul"}:
        raise SceneJevWireValidationError("JEV response must contain exactly choice, score, and noul answers.")
    if any(not isinstance(answers[name], dict) for name in ("choice", "score", "noul")):
        raise SceneJevWireValidationError("Each JEV answer must be a JSON object.")

    choice = answers["choice"]
    score = answers["score"]
    noul = answers["noul"]

    if choice.get("type") != "choice":
        raise SceneJevWireValidationError("Choice answer type must be 'choice'.")
    choice_ans = choice.get("choice")
    if choice_ans not in {"pass", "revise", "block"}:
        raise SceneJevWireValidationError(f"Invalid choice answer: {choice_ans}")

    choice_conf = choice.get("confidence")
    if isinstance(choice_conf, bool) or not isinstance(choice_conf, (int, float)) or not math.isfinite(choice_conf) or not (0.0 <= choice_conf <= 1.0):
        raise SceneJevWireValidationError(f"Invalid choice confidence: {choice_conf}")
    choice_probs = choice.get("probabilities")
    if not isinstance(choice_probs, dict) or set(choice_probs) != {"pass", "revise", "block"}:
        raise SceneJevWireValidationError("Choice probabilities must contain pass, revise, and block.")
    if any(isinstance(v, bool) or not isinstance(v, (int, float)) or not math.isfinite(v) or not 0.0 <= v <= 1.0 for v in choice_probs.values()):
        raise SceneJevWireValidationError("Choice probabilities must be finite numbers in [0, 1].")

    if score.get("type") != "score":
        raise SceneJevWireValidationError("Score answer type must be 'score'.")
    score_val = score.get("score")
    if isinstance(score_val, bool) or not isinstance(score_val, (int, float)) or not math.isfinite(score_val) or not (0.0 <= score_val <= 100.0):
        raise SceneJevWireValidationError(f"Invalid score value: {score_val}")

    score_conf = score.get("confidence")
    if isinstance(score_conf, bool) or not isinstance(score_conf, (int, float)) or not math.isfinite(score_conf) or not (0.0 <= score_conf <= 1.0):
        raise SceneJevWireValidationError(f"Invalid score confidence: {score_conf}")
    score_keys = {str(i) for i in range(101)}
    legend = score.get("legend")
    score_probs = score.get("probabilities")
    if not isinstance(legend, dict) or set(legend) != score_keys or any(not isinstance(v, str) for v in legend.values()):
        raise SceneJevWireValidationError("Score legend must contain the requested levels 0 through 100.")
    if not isinstance(score_probs, dict) or set(score_probs) != score_keys:
        raise SceneJevWireValidationError("Score probabilities must contain the requested levels 0 through 100.")
    if any(isinstance(v, bool) or not isinstance(v, (int, float)) or not math.isfinite(v) or not 0.0 <= v <= 1.0 for v in score_probs.values()):
        raise SceneJevWireValidationError("Score probabilities must be finite numbers in [0, 1].")

    if noul.get("type") != "noul":
        raise SceneJevWireValidationError("Noul answer type must be 'noul'.")
    noul_prob = noul.get("noul")
    if isinstance(noul_prob, bool) or not isinstance(noul_prob, (int, float)) or not math.isfinite(noul_prob) or not (0.0 <= noul_prob <= 1.0):
        raise SceneJevWireValidationError(f"Invalid noul probability: {noul_prob}")

    return choice, score, noul


class SceneJevClient:
    def __init__(
        self,
        api_key: str = "",
        base_url: str = "https://api.typesafe.ai",
        model: str = "jev-latest",
    ):
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")
        self.model = model
        self._questions_cache: Optional[Dict[str, Any]] = None

    def load_questions(self) -> Dict[str, Any]:
        if self._questions_cache is None:
            if not SCENE_QUESTIONS_PATH.exists():
                raise FileNotFoundError(f"Scene JEV question asset not found at {SCENE_QUESTIONS_PATH}")
            self._questions_cache = json.loads(SCENE_QUESTIONS_PATH.read_text(encoding="utf-8"))
        return self._questions_cache

    async def evaluate_scene(
        self,
        evidence: Dict[str, Any],
        client: Optional[httpx.AsyncClient] = None,
        model: Optional[str] = None,
    ) -> Dict[str, Any]:
        # Serialized evidence byte limit check
        evidence_json = json.dumps(evidence, sort_keys=True)
        evidence_bytes = evidence_json.encode("utf-8")
        if len(evidence_bytes) > MAX_EVIDENCE_BYTES:
            raise SceneJevTechnicalError(
                error_code="evidence_limit",
                message=f"Evidence payload {len(evidence_bytes)} bytes exceeds 6 MiB limit."
            )

        url = f"{self.base_url}/v1/systemone"
        target_model = model or self.model
        payload = {
            "state": evidence,
            "model": target_model,
            "questions": self.load_questions(),
        }

        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

        should_close = False
        if client is None:
            client = httpx.AsyncClient(timeout=60.0)
            should_close = True

        try:
            resp = await client.post(url, json=payload, headers=headers)
        except (httpx.ConnectTimeout, httpx.ReadTimeout, httpx.WriteTimeout, httpx.PoolTimeout) as e:
            raise SceneJevTechnicalError(
                error_code="timeout",
                message=f"TypeSafe SystemOne request timed out: {e}",
            ) from e
        except httpx.RequestError as e:
            raise SceneJevTechnicalError(
                error_code="provider_unavailable",
                message=f"TypeSafe SystemOne connection failed: {e}",
            ) from e
        finally:
            if should_close:
                await client.aclose()

        if resp.status_code >= 500 or resp.status_code in [408, 429]:
            raise SceneJevTechnicalError(
                error_code="provider_unavailable",
                message=f"TypeSafe SystemOne temporary failure ({resp.status_code}): {resp.text[:256]}",
                status_code=resp.status_code,
            )

        if resp.status_code != 200:
            raise SceneJevTechnicalError(
                error_code="internal",
                message=f"TypeSafe SystemOne returned unexpected HTTP {resp.status_code}: {resp.text[:256]}",
                status_code=resp.status_code,
            )

        try:
            return resp.json()
        except Exception as e:
            raise SceneJevTechnicalError(
                error_code="internal",
                message=f"Invalid JSON returned from TypeSafe SystemOne: {e}",
                status_code=200,
            ) from e


def get_scene_jev_client() -> SceneJevClient:
    from v3_engine.settings import get_settings
    settings = get_settings()
    if not settings.typesafe_api_key:
        raise RuntimeError("TYPESAFE_API_KEY is not configured.")
    if not settings.jev_model:
        raise RuntimeError("JEV_MODEL is not configured.")
    return SceneJevClient(
        api_key=settings.typesafe_api_key,
        base_url=settings.typesafe_base_url,
        model=settings.jev_model,
    )
