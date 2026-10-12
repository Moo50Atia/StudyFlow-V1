"""HMAC-signed HTTP client communicating with Laravel Web Pipeline endpoints (T073, Spec 019)."""

from __future__ import annotations

import json
import time
from typing import Any
import httpx

from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings


class ApplicationPipelineError(Exception):
    def __init__(self, status_code: int, code: str, message: str) -> None:
        super().__init__(f"[{status_code}] {code}: {message}")
        self.status_code = status_code
        self.code = code
        self.message = message


class ApplicationClient:
    def __init__(
        self,
        base_url: str | None = None,
        hmac_key: str | None = None,
        timeout: float = 15.0,
    ) -> None:
        settings = get_settings()
        self.base_url = (base_url or settings.laravel_api_origin).rstrip("/")
        self.hmac_key = hmac_key or settings.callback_hmac_key or settings.bridge_hmac_key
        self.timeout = timeout

    def _sign_headers(self, method: str, path: str, raw_body: bytes) -> dict[str, str]:
        ts = int(time.time())
        sig = compute_hmac_signature(method, path, ts, raw_body, self.hmac_key)
        return {
            "X-V3-Timestamp": str(ts),
            "X-V3-Signature": sig,
            "X-V3-Contract-Version": "1",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    async def _request(
        self,
        method: str,
        path: str,
        body: dict[str, Any] | bytes | None = None,
    ) -> httpx.Response:
        clean_path = "/" + path.lstrip("/").split("?")[0]
        if body is None:
            raw_body = b""
        elif isinstance(body, bytes):
            raw_body = body
        else:
            raw_body = json.dumps(body, separators=(",", ":")).encode("utf-8")

        headers = self._sign_headers(method, clean_path, raw_body)
        url = f"{self.base_url}{clean_path}"

        async with httpx.AsyncClient(timeout=self.timeout) as client:
            resp = await client.request(
                method=method,
                url=url,
                headers=headers,
                content=raw_body,
            )

        if resp.status_code >= 400:
            try:
                err_json = resp.json().get("error", {})
                code = err_json.get("code", "unknown_error")
                msg = err_json.get("message", resp.text)
            except Exception:
                code = "http_error"
                msg = resp.text
            raise ApplicationPipelineError(resp.status_code, code, msg)

        return resp

    async def claim(self, payload: dict[str, Any]) -> dict[str, Any]:
        resp = await self._request("POST", "/api/v3/internal/web-pipeline/claims", payload)
        return resp.json()

    async def commit(self, payload: dict[str, Any]) -> dict[str, Any]:
        resp = await self._request("POST", "/api/v3/internal/web-pipeline/results", payload)
        return resp.json()

    async def get_manifest(self, manifest_ref: str) -> dict[str, Any]:
        resp = await self._request("GET", f"/api/v3/internal/web-pipeline/manifests/{manifest_ref}")
        return resp.json()

    async def get_scene_candidate(self, case_id: str, candidate_ref: str) -> dict[str, Any]:
        resp = await self._request(
            "GET", f"/api/v3/internal/cases/{case_id}/scene-candidates/{candidate_ref}"
        )
        return resp.json()

    async def claim_scene_check(self, case_id: str, candidate_ref: str, attempt_no: int) -> dict[str, Any]:
        resp = await self._request(
            "POST",
            f"/api/v3/internal/cases/{case_id}/scene-check-attempts",
            {"schema_version": 1, "candidate_ref": candidate_ref, "attempt_no": attempt_no},
        )
        return resp.json()

    async def record_scene_check(
        self, case_id: str, attempt_ref: str, payload: dict[str, Any]
    ) -> dict[str, Any]:
        resp = await self._request(
            "POST",
            f"/api/v3/internal/cases/{case_id}/scene-check-attempts/{attempt_ref}/result",
            payload,
        )
        return resp.json()

    async def materialize_import(self, payload: dict[str, Any]) -> dict[str, Any]:
        """Ask Laravel to re-verify and atomically materialize a historical archive."""
        resp = await self._request("POST", "/api/v3/internal/web-pipeline/imports/materialize", payload)
        return resp.json()

    async def prepare_run_item(self, run_id: str, item_id: str) -> dict[str, Any]:
        """Run Laravel's real intake path and return only opaque input receipt metadata."""
        resp = await self._request(
            "POST",
            f"/api/v3/internal/web-pipeline/items/{item_id}/prepare",
            {"run_id": run_id},
        )
        return resp.json()

    async def record_run_notebook(
        self,
        run_id: str,
        notebook_id: str,
        framework_source_id: str | None = None,
        framework_sha256: str | None = None,
        configured: bool = False,
    ) -> dict[str, Any]:
        payload: dict[str, Any] = {"notebook_id": notebook_id, "configured": configured}
        if framework_source_id is not None:
            payload["framework_source_id"] = framework_source_id
            payload["framework_sha256"] = framework_sha256
        resp = await self._request("POST", f"/api/v3/internal/web-pipeline/runs/{run_id}/notebook", payload)
        return resp.json()

    async def record_run_item_source(
        self,
        run_id: str,
        item_id: str,
        source_id: str,
        input_sha256: str,
    ) -> dict[str, Any]:
        resp = await self._request(
            "POST", f"/api/v3/internal/web-pipeline/items/{item_id}/source",
            {"run_id": run_id, "source_id": source_id, "input_sha256": input_sha256},
        )
        return resp.json()

    async def record_run_item_archive(self, item_id: str, payload: dict[str, Any]) -> dict[str, Any]:
        resp = await self._request(
            "POST", f"/api/v3/internal/web-pipeline/items/{item_id}/archive", payload,
        )
        return resp.json()

    async def mark_run_item_reset_verified(self, item_id: str, payload: dict[str, Any]) -> dict[str, Any]:
        resp = await self._request(
            "POST", f"/api/v3/internal/web-pipeline/items/{item_id}/reset-verified", payload,
        )
        return resp.json()

    async def export_run_prompts(self, run_id: str) -> dict[str, Any]:
        resp = await self._request("POST", f"/api/v3/internal/web-pipeline/runs/{run_id}/export", {})
        return resp.json()

    async def get_accepted_input(self, item_id: str) -> bytes:
        """Fetch verified accepted PDF bytes for a worker-local upload operation."""
        resp = await self._request("GET", f"/api/v3/internal/web-pipeline/items/{item_id}/input")
        return resp.content

    async def get_framework_body(self, framework_version_id: str) -> bytes:
        """Fetch the exact approved immutable framework artifact bytes."""
        resp = await self._request(
            "GET", f"/api/v3/internal/web-pipeline/frameworks/{framework_version_id}/body"
        )
        return resp.content

    async def get_context(self, context_ref: str) -> dict[str, Any]:
        resp = await self._request("GET", f"/api/v3/internal/web-pipeline/contexts/{context_ref}")
        return resp.json()

    async def get_artifact(self, artifact_ref: str) -> bytes:
        resp = await self._request("GET", f"/api/v3/internal/web-pipeline/artifacts/{artifact_ref}")
        return resp.content

    async def put_artifact(self, operation_id: str, artifact_ref: str, content: bytes) -> dict[str, Any]:
        resp = await self._request(
            "PUT",
            f"/api/v3/internal/web-pipeline/operations/{operation_id}/artifacts/{artifact_ref}",
            content,
        )
        return resp.json()

    async def record_event(self, payload: dict[str, Any]) -> dict[str, Any]:
        resp = await self._request("POST", "/api/v3/internal/web-pipeline/events", payload)
        return resp.json()

    async def get_command_receipt(self, command_id: str) -> dict[str, Any]:
        resp = await self._request("GET", f"/api/v3/internal/web-pipeline/commands/{command_id}/receipt")
        return resp.json()
