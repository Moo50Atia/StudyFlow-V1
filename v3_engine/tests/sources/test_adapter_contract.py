"""Tests for KnowledgeSourceAdapter.query contract (FR-006 / K14)."""

import pytest
from typing import Optional
from v3_engine.sources.notebooklm_adapter import KnowledgeSourceAdapter
from v3_engine.tests.sources.test_notebooklm_adapter import FakeNotebookLmMcpClient, FakeLaravelClient

class QueryTestLaravelClient(FakeLaravelClient):
    def __init__(self):
        super().__init__("[PAGE 1/1]\nText\n[/PAGE 1/1]\n")
        self.bindings = {
            "bnd-valid-1": {
                "case_id": "case-1",
                "extracted_source_ref": "ref-1",
                "notebook_id": "nb-provider-1",
                "source_id": "src-provider-1",
                "state": "bound",
                "text_sha256": "abcdef123456",
            },
            "bnd-unbound-2": {
                "case_id": "case-2",
                "extracted_source_ref": "ref-2",
                "notebook_id": "nb-provider-2",
                "source_id": None,
                "state": "notebook_ready",
                "text_sha256": "abcdef654321",
            },
        }

    async def get_binding_for_query(self, case_id: str, binding_ref: str) -> dict:
        if binding_ref not in self.bindings:
            raise ValueError("Unknown binding_ref")
        b = self.bindings[binding_ref]
        if b["case_id"] != case_id:
            raise ValueError("Cross-case access denied")
        if b["state"] != "bound":
            raise ValueError("Binding is not in bound state")
        return b

@pytest.mark.asyncio
async def test_k14_bound_source_scoped_query():
    """K14: Bound binding executes source-scoped query filtering only on bound source_id."""
    mcp = FakeNotebookLmMcpClient()
    laravel = QueryTestLaravelClient()
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    res = await adapter.query(
        case_id="case-1",
        binding_ref="bnd-valid-1",
        query_text="What are the key findings?",
    )

    assert "answer" in res
    assert res["answer"] == "Answer to What are the key findings?"
    assert res["conversation_ref"] == "conv-1"

    # Verify query was called
    assert "notebook_query:What are the key findings?" in mcp.call_history

@pytest.mark.asyncio
async def test_k14_conversation_ref_reuse():
    """K14: Existing conversation_ref is passed to notebook_query and returned."""
    mcp = FakeNotebookLmMcpClient()
    laravel = QueryTestLaravelClient()
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    res = await adapter.query(
        case_id="case-1",
        binding_ref="bnd-valid-1",
        query_text="Follow-up question?",
        conversation_ref="existing-conv-99",
    )

    assert res["conversation_ref"] == "existing-conv-99"

@pytest.mark.asyncio
async def test_k14_cross_case_rejection():
    """K14: Requesting binding belonging to another case is rejected."""
    mcp = FakeNotebookLmMcpClient()
    laravel = QueryTestLaravelClient()
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    with pytest.raises(ValueError, match="Cross-case"):
        await adapter.query(
            case_id="other-case",
            binding_ref="bnd-valid-1",
            query_text="Cross-case query",
        )

@pytest.mark.asyncio
async def test_k14_unbound_binding_rejection():
    """K14: Query on unbound binding is rejected."""
    mcp = FakeNotebookLmMcpClient()
    laravel = QueryTestLaravelClient()
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    with pytest.raises(ValueError, match="Binding is not in bound state"):
        await adapter.query(
            case_id="case-2",
            binding_ref="bnd-unbound-2",
            query_text="Query on unbound",
        )

@pytest.mark.asyncio
async def test_k14_empty_or_oversize_query_rejection():
    """K14: Empty query or query exceeding 16384 bytes is rejected."""
    mcp = FakeNotebookLmMcpClient()
    laravel = QueryTestLaravelClient()
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)

    with pytest.raises(ValueError, match="empty"):
        await adapter.query(
            case_id="case-1",
            binding_ref="bnd-valid-1",
            query_text="   ",
        )

    oversize_query = "A" * 16385
    with pytest.raises(ValueError, match="exceeds 16384"):
        await adapter.query(
            case_id="case-1",
            binding_ref="bnd-valid-1",
            query_text=oversize_query,
        )
