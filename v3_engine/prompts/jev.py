import json
from pathlib import Path
from typing import Any, Dict, List, Optional
import httpx

from v3_engine.prompts.policy import JevTechnicalError, JevWireValidationError, validate_jev_response
from v3_engine.settings import get_settings

QUESTIONS_PATH = Path(__file__).resolve().parents[2] / "resources" / "v3" / "jev" / "prompt-questions-v1.json"


class TypeSafeJevClient:
    def __init__(
        self,
        api_key: Optional[str] = None,
        base_url: Optional[str] = None,
        model: Optional[str] = None,
    ):
        settings = get_settings()
        self.api_key = api_key if api_key is not None else settings.typesafe_api_key
        self.base_url = (base_url if base_url is not None else settings.typesafe_base_url).rstrip("/")
        self.model = model if model is not None else settings.jev_model
        self._questions_cache: Optional[Dict[str, Any]] = None

    def load_questions(self) -> Dict[str, Any]:
        if self._questions_cache is None:
            if not QUESTIONS_PATH.exists():
                raise FileNotFoundError(f"JEV question asset not found at {QUESTIONS_PATH}")
            self._questions_cache = json.loads(QUESTIONS_PATH.read_text(encoding="utf-8"))
        return self._questions_cache

    async def evaluate_prompt(
        self,
        state: Optional[Dict[str, Any]] = None,
        client: Optional[httpx.AsyncClient] = None,
        model: Optional[str] = None,
        section_fields: Optional[Dict[str, Any]] = None,
        coverage_pages: Optional[List[int]] = None,
        prompt_text: Optional[str] = None,
    ) -> Dict[str, Any]:
        """Submit a prompt evaluation to TypeSafe SystemOne endpoint."""
        if state is None:
            state = {
                "section": section_fields or {},
                "coverage_pages": coverage_pages or [],
                "prompt": prompt_text or "",
            }
        url = f"{self.base_url}/v1/systemone"
        target_model = model or self.model
        payload = {
            "state": state,
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
            raise JevTechnicalError(
                error_code="timeout",
                message=f"TypeSafe JEV request timed out: {e}",
            ) from e
        except (httpx.ConnectError, httpx.NetworkError) as e:
            raise JevTechnicalError(
                error_code="provider_unavailable",
                message=f"TypeSafe JEV connection failed: {e}",
            ) from e
        finally:
            if should_close:
                await client.aclose()

        if resp.status_code == 401:
            raise JevTechnicalError(
                error_code="provider_unavailable",
                message="TypeSafe JEV authentication failed (401).",
                status_code=401,
            )
        elif resp.status_code == 429:
            raise JevTechnicalError(
                error_code="provider_unavailable",
                message="TypeSafe JEV rate limit exceeded (429).",
                status_code=429,
            )
        elif resp.status_code >= 500:
            raise JevTechnicalError(
                error_code="provider_unavailable",
                message=f"TypeSafe JEV service error ({resp.status_code}).",
                status_code=resp.status_code,
            )
        elif resp.status_code != 200:
            raise JevTechnicalError(
                error_code="policy_denied",
                message=f"TypeSafe JEV returned unexpected HTTP status {resp.status_code}.",
                status_code=resp.status_code,
            )

        try:
            data = resp.json()
        except Exception as e:
            raise JevTechnicalError(
                error_code="policy_denied",
                message=f"TypeSafe JEV returned non-JSON response: {e}",
            ) from e

        try:
            validate_jev_response(data)
        except JevWireValidationError as e:
            raise JevTechnicalError(
                error_code="policy_denied",
                message=f"TypeSafe JEV response schema violation: {e}",
            ) from e

        return data
