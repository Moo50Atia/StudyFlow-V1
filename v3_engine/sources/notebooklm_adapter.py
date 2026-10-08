"""V3 Engine KnowledgeSourceAdapter implementation (FR-004, FR-005, FR-006 / K09-K14)."""

import hashlib
import json
import time
from typing import Any, Dict, List, Optional
try:
    import httpx
except ImportError:
    httpx = None

from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings
from v3_engine.sources.mcp_client import NotebookLmMcpClient

ADAPTER_KIND = "notebooklm_mcp"
ADAPTER_VERSION = "0.3.1"

class HttpLaravelSourceClient:
    """Real HTTP client for worker endpoints on Laravel using HMAC signatures."""

    def __init__(self, base_url: Optional[str] = None, hmac_key: Optional[str] = None):
        settings = get_settings()
        self.hmac_key = hmac_key or settings.callback_hmac_key
        # Extract base url from laravel_callback_url (e.g. http://127.0.0.1:8000/api/internal/v1)
        raw_url = base_url or settings.laravel_callback_url
        if "/case-events" in raw_url:
            self.base_url = raw_url.rsplit("/case-events", 1)[0]
        else:
            self.base_url = raw_url.rstrip("/")

    def _headers(self, method: str, path: str, raw_body: bytes) -> Dict[str, str]:
        now_ts = int(time.time())
        sig = compute_hmac_signature(method, path, now_ts, raw_body, self.hmac_key)
        return {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-V3-Timestamp": str(now_ts),
            "X-V3-Signature": sig,
            "X-V3-Contract-Version": "1",
        }

    async def get_extracted_text(self, activation_id: str, extracted_source_ref: str) -> str:
        path = f"/api/internal/v1/source-activations/{activation_id}/extracted-sources/{extracted_source_ref}/text"
        url = f"{self.base_url}/source-activations/{activation_id}/extracted-sources/{extracted_source_ref}/text"
        headers = self._headers("GET", path, b"")
        async with httpx.AsyncClient(timeout=30.0) as client:
            resp = await client.get(url, headers=headers)
            if resp.status_code != 200:
                raise RuntimeError(f"Failed to fetch extracted text: {resp.status_code} {resp.text}")
            return resp.text

    async def claim_effect(self, case_id: str, operation: str, activation_id: str, request_digest: str) -> dict:
        path = f"/api/internal/v1/source-effects/{case_id}/{operation}/claim"
        url = f"{self.base_url}/source-effects/{case_id}/{operation}/claim"
        payload = {
            "schema_version": 1,
            "activation_id": activation_id,
            "request_digest": request_digest,
        }
        body_bytes = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        headers = self._headers("POST", path, body_bytes)
        async with httpx.AsyncClient(timeout=30.0) as client:
            resp = await client.post(url, content=body_bytes, headers=headers)
            if resp.status_code != 200:
                raise RuntimeError(f"Claim failed with {resp.status_code}: {resp.text}")
            return resp.json()["data"]

    async def record_result(
        self,
        effect_id: str,
        state: str,
        provider_ref: Optional[str],
        proof_title: Optional[str],
        proof_sha256: Optional[str],
        error_code: Optional[str] = None
    ) -> dict:
        path = f"/api/internal/v1/source-effects/{effect_id}/result"
        url = f"{self.base_url}/source-effects/{effect_id}/result"
        payload = {
            "schema_version": 1,
            "state": state,
            "provider_ref": provider_ref,
            "proof_title": proof_title,
            "proof_sha256": proof_sha256,
            "error_code": error_code,
        }
        body_bytes = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        headers = self._headers("POST", path, body_bytes)
        async with httpx.AsyncClient(timeout=30.0) as client:
            resp = await client.post(url, content=body_bytes, headers=headers)
            if resp.status_code != 200:
                raise RuntimeError(f"Record result failed with {resp.status_code}: {resp.text}")
            return resp.json()["data"]

    async def get_binding(self, case_id: str, activation_id: str) -> dict:
        path = f"/api/internal/v1/source-activations/{activation_id}/binding"
        url = f"{self.base_url}/source-activations/{activation_id}/binding"
        headers = self._headers("GET", path, b"")
        async with httpx.AsyncClient(timeout=30.0) as client:
            resp = await client.get(url, headers=headers)
            if resp.status_code != 200:
                raise RuntimeError(f"Get binding failed with {resp.status_code}: {resp.text}")
            return resp.json()["data"]

    async def get_binding_for_query(self, case_id: str, binding_ref: str) -> dict:
        path = f"/api/internal/v1/cases/{case_id}/source-bindings/{binding_ref}"
        url = f"{self.base_url}/cases/{case_id}/source-bindings/{binding_ref}"
        headers = self._headers("GET", path, b"")
        async with httpx.AsyncClient(timeout=30.0) as client:
            resp = await client.get(url, headers=headers)
            if resp.status_code != 200:
                raise RuntimeError(f"Get binding for query failed with {resp.status_code}: {resp.text}")
            return resp.json()["data"]

class KnowledgeSourceAdapter:
    """Adapter for NotebookLM via MCP with strict external effect ledger tracking."""

    def __init__(self, mcp_client: Optional[NotebookLmMcpClient] = None, laravel_client: Optional[Any] = None):
        self.mcp = mcp_client or NotebookLmMcpClient()
        self.laravel = laravel_client or HttpLaravelSourceClient()

    async def ingest(self, case_id: str, extracted_source_ref: str, activation_id: str) -> dict:
        """Execute guarded source ingestion against NotebookLM via MCP."""
        # 1. Fetch verified canonical text
        canonical_text = await self.laravel.get_extracted_text(activation_id, extracted_source_ref)
        text_sha256 = hashlib.sha256(canonical_text.encode("utf-8")).hexdigest()

        notebook_title = f"v3-case-{case_id}"
        source_title = f"v3-text-{case_id}-{text_sha256[:16]}"

        provenance = {
            "adapter_kind": ADAPTER_KIND,
            "adapter_version": ADAPTER_VERSION,
            "text_sha256": text_sha256,
        }

        # 2. Claim and execute notebook_create
        create_digest = hashlib.sha256(f"notebook_create\n{case_id}\n{notebook_title}".encode("utf-8")).hexdigest()
        claim = await self.laravel.claim_effect(case_id, "notebook_create", activation_id, create_digest)
        effect_id = claim["effect_id"]
        disp = claim["disposition"]

        notebook_id = claim.get("provider_ref")

        if not notebook_id:
            if disp == "send_now":
                try:
                    res = await self.mcp.notebook_create(notebook_title)
                    candidate_id = res.get("id")
                    if candidate_id:
                        nb = await self.mcp.notebook_get(candidate_id)
                        if nb.get("title") == notebook_title and len(nb.get("sources", [])) == 0:
                            notebook_id = candidate_id
                except Exception:
                    pass

            if not notebook_id:
                # Reconcile via notebook_list
                try:
                    candidates = await self.mcp.notebook_list()
                    matching = [nb for nb in candidates if nb.get("title") == notebook_title]
                    if len(matching) == 1:
                        nb = await self.mcp.notebook_get(matching[0]["id"])
                        if nb.get("title") == notebook_title and len(nb.get("sources", [])) == 0:
                            notebook_id = matching[0]["id"]
                except Exception:
                    pass

            if notebook_id:
                await self.laravel.record_result(
                    effect_id=effect_id,
                    state="confirmed",
                    provider_ref=notebook_id,
                    proof_title=notebook_title,
                    proof_sha256=None,
                )
            else:
                await self.laravel.record_result(
                    effect_id=effect_id,
                    state="uncertain",
                    provider_ref=None,
                    proof_title=None,
                    proof_sha256=None,
                )
                return {
                    "status": "uncertain",
                    "error_code": "provider_unavailable",
                    "provenance": provenance,
                }

        # 3. Claim and execute notebook_add_text
        add_digest = hashlib.sha256(f"notebook_add_text\n{case_id}\n{notebook_id}\n{source_title}\n{text_sha256}".encode("utf-8")).hexdigest()
        add_claim = await self.laravel.claim_effect(case_id, "notebook_add_text", activation_id, add_digest)
        add_effect_id = add_claim["effect_id"]
        add_disp = add_claim["disposition"]

        source_id = add_claim.get("provider_ref")

        if not source_id:
            if add_disp == "send_now":
                try:
                    await self.mcp.notebook_add_text(notebook_id, canonical_text, source_title)
                except Exception:
                    pass

            # Reconcile via notebook_get and source_get_content
            try:
                nb = await self.mcp.notebook_get(notebook_id)
                sources = nb.get("sources", [])
                matching_sources = [s for s in sources if s.get("title") == source_title]

                valid_sources = []
                for ms in matching_sources:
                    s_id = ms.get("id")
                    content_res = await self.mcp.source_get_content(s_id, notebook_id)
                    raw_content = content_res.get("content", "")
                    normalized = raw_content.replace("\r\n", "\n").replace("\r", "\n")
                    if normalized == canonical_text:
                        valid_sources.append(s_id)

                if len(valid_sources) == 1:
                    source_id = valid_sources[0]
            except Exception:
                pass

            if source_id:
                await self.laravel.record_result(
                    effect_id=add_effect_id,
                    state="confirmed",
                    provider_ref=source_id,
                    proof_title=source_title,
                    proof_sha256=text_sha256,
                )
            else:
                await self.laravel.record_result(
                    effect_id=add_effect_id,
                    state="uncertain",
                    provider_ref=None,
                    proof_title=None,
                    proof_sha256=None,
                )
                return {
                    "status": "uncertain",
                    "error_code": "provider_unavailable",
                    "provenance": provenance,
                }

        # 4. Resolve binding ref from Laravel
        binding_data = await self.laravel.get_binding(case_id, activation_id)
        binding_ref = binding_data.get("binding_ref", binding_data.get("id", f"bnd-{case_id}"))

        return {
            "status": "bound",
            "binding_ref": binding_ref,
            "provenance": provenance,
        }

    async def query(self, case_id: str, binding_ref: str, query_text: str, conversation_ref: Optional[str] = None) -> dict:
        """Activity-local source-scoped query contract (K14)."""
        if not query_text or len(query_text.strip()) == 0:
            raise ValueError("query_text must not be empty")

        if len(query_text.encode("utf-8")) > 16384:
            raise ValueError("query_text exceeds 16384 bytes limit")

        binding_data = await self.laravel.get_binding_for_query(case_id, binding_ref)
        if binding_data.get("state") != "bound":
            raise ValueError("Binding must be bound for query")

        notebook_id = binding_data.get("notebook_id")
        source_id = binding_data.get("source_id")

        if not notebook_id or not source_id:
            raise ValueError("Bound binding missing provider IDs")

        res = await self.mcp.notebook_query(
            notebook_id=notebook_id,
            query=query_text,
            source_ids=[source_id],
            conversation_id=conversation_ref,
        )

        return {
            "answer": res.get("answer", str(res)),
            "conversation_ref": res.get("conversation_id", conversation_ref or "conv-default"),
        }

    async def query_once(
        self,
        case_id: str,
        binding: Dict[str, Any],
        query_text: str,
        conversation_id: Optional[str] = None,
    ) -> Dict[str, Any]:
        """009 Activity-local source-scoped single-send query contract (FR-003, FR-005, FR-006 / L05, L10, L12)."""
        if not query_text or not query_text.strip():
            raise ValueError("query_text must not be empty")

        if len(query_text.encode("utf-8")) > 16384:
            raise ValueError("query_text exceeds 16384 bytes limit")

        if binding.get("state") != "bound":
            raise ValueError("Binding must be bound for query")

        if binding.get("case_id") != case_id:
            raise ValueError("Cross-case binding mismatch")

        notebook_id = binding.get("notebook_id")
        source_id = binding.get("source_id")

        if not notebook_id or not source_id:
            raise ValueError("Bound binding missing provider IDs")

        # Send exactly once through MCP notebook_query with source_ids scoped
        res = await self.mcp.notebook_query(
            notebook_id=notebook_id,
            query=query_text,
            source_ids=[source_id],
            conversation_id=conversation_id,
        )

        conv_out = res.get("conversation_id")
        if not conv_out or not str(conv_out).strip():
            raise ValueError("Provider response missing valid conversation_id")

        return {
            "answer": res.get("answer", ""),
            "conversation_id": conv_out,
        }

    async def query_once_by_binding(
        self,
        case_id: str,
        binding_ref: str,
        query_text: str,
        conversation_id: Optional[str] = None,
    ) -> Dict[str, str]:
        """Resolve private provider IDs in Laravel, then perform one scoped query."""
        binding_data = await self.laravel.get_binding_for_query(case_id, binding_ref)
        return await self.query_once(case_id, binding_data, query_text, conversation_id)
