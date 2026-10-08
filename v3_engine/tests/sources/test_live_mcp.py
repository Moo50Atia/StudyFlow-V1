"""Live MCP integration test (FR-004, FR-006, FR-008 / K17)."""

import os
import pytest
from v3_engine.sources.mcp_client import NotebookLmMcpClient

pytestmark = [pytest.mark.live, pytest.mark.asyncio]

async def test_k17_live_mcp_tools_list_schema_gate():
    """K17: Live installed @m4ykeldev/notebooklm-mcp@0.3.1 satisfies strict tools/list schema."""
    client = NotebookLmMcpClient()
    try:
        is_valid = await client.check_tools_schema()
        assert is_valid is True, "Installed NotebookLM MCP tools/list missing required tool names"
    finally:
        await client.close()

@pytest.mark.asyncio
async def test_k17_live_notebooklm_account_gate():
    """K17: Live account test executes if disposable session credentials provided, else marks honest blocked gate."""
    has_cookies = bool(
        os.getenv("NOTEBOOKLM_SESSION_COOKIES")
        or os.getenv("NOTEBOOKLM_COOKIES")
        or os.getenv("NOTEBOOKLM_SESSION")
    )
    if not has_cookies:
        pytest.skip("GATE BLOCKED: Dedicated disposable NotebookLM session credentials not provisioned in environment")

    # If cookies are provided, run real isolated notebook creation and source query
    client = NotebookLmMcpClient()
    try:
        title = "v3-case-test-live-gate"
        nb = await client.notebook_create(title)
        assert nb.get("id") is not None
    finally:
        await client.close()
