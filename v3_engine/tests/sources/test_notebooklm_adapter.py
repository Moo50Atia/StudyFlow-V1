"""Unit and behavioral tests for NotebookLM adapter (FR-004, FR-005, FR-006 / K09-K13, K16)."""
import hashlib
import pytest
from typing import Any, Dict, List, Optional

from v3_engine.sources.notebooklm_adapter import KnowledgeSourceAdapter
from v3_engine.sources.mcp_client import NotebookLmMcpClient

class FakeNotebookLmMcpClient(NotebookLmMcpClient):
    def __init__(self):
        self.notebooks: Dict[str, dict] = {}
        self.sources: Dict[str, dict] = {}
        self.call_history: List[str] = []
        self.lost_create_response: bool = False
        self.lost_create_zero_candidates: bool = False
        self.lost_add_response: bool = False
        self.corrupt_content: bool = False
        self.duplicate_notebooks: bool = False
        self._nb_counter = 1
        self._src_counter = 1

    async def check_tools_schema(self) -> bool:
        return True

    async def notebook_create(self, title: str) -> dict:
        self.call_history.append(f"notebook_create:{title}")
        if self.lost_create_zero_candidates:
            raise RuntimeError("Provider dropped request before creation")
        nb_id = f"nb-{self._nb_counter}"
        self._nb_counter += 1
        nb = {"id": nb_id, "title": title, "sources": []}
        self.notebooks[nb_id] = nb
        if self.lost_create_response:
            raise RuntimeError("Network timeout waiting for notebook_create response")
        return {"id": nb_id, "title": title}

    async def notebook_list(self, max_results: int = 100) -> List[dict]:
        self.call_history.append("notebook_list")
        if self.duplicate_notebooks:
            # Return multiple notebooks with same title
            return list(self.notebooks.values()) + list(self.notebooks.values())
        return list(self.notebooks.values())

    async def notebook_get(self, notebook_id: str) -> dict:
        self.call_history.append(f"notebook_get:{notebook_id}")
        if notebook_id not in self.notebooks:
            raise KeyError(f"Notebook {notebook_id} not found")
        nb = self.notebooks[notebook_id]
        return {
            "id": nb["id"],
            "title": nb["title"],
            "sources": list(nb["sources"]),
        }

    async def notebook_add_text(self, notebook_id: str, content: str, title: str) -> dict:
        self.call_history.append(f"notebook_add_text:{title}")
        if notebook_id not in self.notebooks:
            raise KeyError(f"Notebook {notebook_id} not found")
        src_id = f"src-{self._src_counter}"
        self._src_counter += 1
        source_data = {"id": src_id, "title": title, "content": content}
        self.sources[src_id] = source_data
        self.notebooks[notebook_id]["sources"].append({"id": src_id, "title": title})
        if self.lost_add_response:
            raise RuntimeError("Network timeout waiting for notebook_add_text response")
        return {"message": "Text source added"}

    async def source_get_content(self, source_id: str, notebook_id: str) -> dict:
        assert any(s['id'] == source_id for s in self.notebooks[notebook_id]['sources'])
        self.call_history.append(f"source_get_content:{source_id}")
        if source_id not in self.sources:
            raise KeyError(f"Source {source_id} not found")
        content = self.sources[source_id]["content"]
        if self.corrupt_content:
            content = content + "\n[CORRUPTED]"
        return {"content": content}

    async def notebook_query(self, notebook_id: str, query: str, source_ids: List[str], conversation_id: Optional[str] = None) -> dict:
        self.call_history.append(f"notebook_query:{query}")
        return {"answer": f"Answer to {query}", "conversation_id": conversation_id or "conv-1"}

class FakeLaravelClient:
    """Mock client for Laravel worker endpoints during adapter testing."""
    def __init__(self, canonical_text: str):
        self.canonical_text = canonical_text
        self.text_sha256 = hashlib.sha256(canonical_text.encode("utf-8")).hexdigest()
        self.claims: Dict[str, dict] = {}
        self.results: Dict[str, dict] = {}
        self.bindings: Dict[str, dict] = {}
        self.claim_counts: Dict[str, int] = {}

    async def claim_effect(self, case_id: str, operation: str, activation_id: str, request_digest: str) -> dict:
        key = f"{case_id}:{operation}"
        self.claim_counts[key] = self.claim_counts.get(key, 0) + 1
        if key in self.claims:
            return {"effect_id": self.claims[key]["effect_id"], "disposition": "reconcile", "provider_ref": self.claims[key].get("provider_ref")}
        
        effect_id = f"eff-{key}"
        self.claims[key] = {"effect_id": effect_id, "provider_ref": None, "state": "sending"}
        return {"effect_id": effect_id, "disposition": "send_now", "provider_ref": None}

    async def record_result(self, effect_id: str, state: str, provider_ref: Optional[str], proof_title: Optional[str], proof_sha256: Optional[str], error_code: Optional[str] = None) -> dict:
        self.results[effect_id] = {
            "state": state,
            "provider_ref": provider_ref,
            "proof_title": proof_title,
            "proof_sha256": proof_sha256,
        }
        return {"state": state, "provider_ref": provider_ref}

    async def get_extracted_text(self, activation_id: str, extracted_source_ref: str) -> str:
        return self.canonical_text

    async def get_binding(self, case_id: str, binding_ref: str) -> dict:
        return self.bindings.get(binding_ref, {"state": "bound", "notebook_id": "nb-1", "source_id": "src-1", "text_sha256": self.text_sha256})

@pytest.mark.asyncio
async def test_k10_happy_path_ingest_and_grounding():
    """K10: Full ingest creates notebook, adds text, verifies exact content, confirms binding."""
    text = "[PAGE 1/1]\nHappy path test content\n[/PAGE 1/1]\n"
    mcp = FakeNotebookLmMcpClient()
    laravel = FakeLaravelClient(text)
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    case_id = "00000000-0000-0000-0000-000000000001"
    ref = "00000000-0000-0000-0000-000000000011"
    act_id = "00000000-0000-0000-0000-000000000021"

    result = await adapter.ingest(case_id=case_id, extracted_source_ref=ref, activation_id=act_id)

    assert result["status"] == "bound"
    assert "binding_ref" in result
    assert result["provenance"]["adapter_kind"] == "notebooklm_mcp"
    assert result["provenance"]["adapter_version"] == "0.3.1"
    # Provider IDs are never exposed in workflow result
    assert "notebook_id" not in result
    assert "source_id" not in result

    # Check MCP tool invocations
    assert "notebook_create:v3-case-00000000-0000-0000-0000-000000000001" in mcp.call_history
    assert any("notebook_add_text:" in call for call in mcp.call_history)
    assert any("source_get_content:" in call for call in mcp.call_history)

@pytest.mark.asyncio
async def test_k09_lost_create_response_reconciles_single_candidate():
    """K09: Lost notebook_create response reconciles one exact notebook from inventory without sending twice."""
    text = "[PAGE 1/1]\nReconcile create test\n[/PAGE 1/1]\n"
    mcp = FakeNotebookLmMcpClient()
    mcp.lost_create_response = True  # Network drop on create response
    laravel = FakeLaravelClient(text)
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    case_id = "00000000-0000-0000-0000-000000000002"
    ref = "00000000-0000-0000-0000-000000000012"
    act_id = "00000000-0000-0000-0000-000000000022"

    result = await adapter.ingest(case_id=case_id, extracted_source_ref=ref, activation_id=act_id)

    assert result["status"] == "bound"
    # Verify notebook_create was called only ONCE
    create_calls = [c for c in mcp.call_history if c.startswith("notebook_create")]
    assert len(create_calls) == 1
    # Verify notebook_list was called to reconcile
    assert "notebook_list" in mcp.call_history

@pytest.mark.asyncio
async def test_k09_lost_create_with_zero_candidates_stays_uncertain():
    """K09: Lost create with 0 candidates stays uncertain, no second create send."""
    text = "[PAGE 1/1]\nZero candidate test\n[/PAGE 1/1]\n"
    mcp = FakeNotebookLmMcpClient()
    mcp.lost_create_zero_candidates = True

    laravel = FakeLaravelClient(text)
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    case_id = "00000000-0000-0000-0000-000000000003"
    ref = "00000000-0000-0000-0000-000000000013"
    act_id = "00000000-0000-0000-0000-000000000023"

    result = await adapter.ingest(case_id=case_id, extracted_source_ref=ref, activation_id=act_id)

    assert result["status"] == "uncertain"
    create_calls = [c for c in mcp.call_history if c.startswith("notebook_create")]
    assert len(create_calls) == 1

@pytest.mark.asyncio
async def test_k11_content_mismatch_fails_proof_and_stays_uncertain():
    """K11: If source_get_content does not match canonical artifact byte-for-byte, transfer is uncertain."""
    text = "[PAGE 1/1]\nContent mismatch test\n[/PAGE 1/1]\n"
    mcp = FakeNotebookLmMcpClient()
    mcp.corrupt_content = True  # Simulates provider returning altered content

    laravel = FakeLaravelClient(text)
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    case_id = "00000000-0000-0000-0000-000000000004"
    ref = "00000000-0000-0000-0000-000000000014"
    act_id = "00000000-0000-0000-0000-000000000024"

    result = await adapter.ingest(case_id=case_id, extracted_source_ref=ref, activation_id=act_id)

    assert result["status"] == "uncertain"
    # Never confirmed on ledger
    confirmed_results = [r for r in laravel.results.values() if r.get("state") == "confirmed" and r.get("proof_sha256") is not None]
    assert len(confirmed_results) == 0

@pytest.mark.asyncio
async def test_k13_two_distinct_cases_have_isolated_notebooks():
    """K13: Same PDF content ingested in two distinct cases produces isolated bindings."""
    text = "[PAGE 1/1]\nShared PDF in distinct cases\n[/PAGE 1/1]\n"
    mcp = FakeNotebookLmMcpClient()
    laravel = FakeLaravelClient(text)
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    case1 = "00000000-0000-0000-0000-000000000005"
    case2 = "00000000-0000-0000-0000-000000000006"

    res1 = await adapter.ingest(case_id=case1, extracted_source_ref="ref-1", activation_id="act-1")
    res2 = await adapter.ingest(case_id=case2, extracted_source_ref="ref-2", activation_id="act-2")

    assert res1["status"] == "bound"
    assert res2["status"] == "bound"
    assert res1["binding_ref"] != res2["binding_ref"]
    assert "notebook_create:v3-case-00000000-0000-0000-0000-000000000005" in mcp.call_history
    assert "notebook_create:v3-case-00000000-0000-0000-0000-000000000006" in mcp.call_history
