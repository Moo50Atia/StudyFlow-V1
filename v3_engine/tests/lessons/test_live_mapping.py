"""Live MCP mapping integration test (FR-002, FR-003, FR-004, FR-008 / L02, L04, L06, L13)."""
import os
import unittest
import pytest
from v3_engine.sources.mcp_client import NotebookLmMcpClient
from v3_engine.sources.notebooklm_adapter import KnowledgeSourceAdapter

pytestmark = pytest.mark.live


class TestLiveMapping(unittest.IsolatedAsyncioTestCase):
    async def test_l13_live_mcp_tools_list_schema_gate(self):
        """L13: Live installed @m4ykeldev/notebooklm-mcp@0.3.1 satisfies strict tools/list schema."""
        client = NotebookLmMcpClient()
        try:
            is_valid = await client.check_tools_schema()
            self.assertTrue(is_valid, "Installed NotebookLM MCP tools/list missing required tool names")
        except RuntimeError as e:
            self.skipTest(f"GATE BLOCKED: @m4ykeldev/notebooklm-mcp@0.3.1 process not runnable: {e}")
        finally:
            await client.close()

    async def test_l13_live_notebooklm_account_gate(self):
        """L13: Live account test executes if disposable session credentials provided, else marks honest blocked gate."""
        has_cookies = bool(
            os.getenv("NOTEBOOKLM_SESSION_COOKIES")
            or os.getenv("NOTEBOOKLM_COOKIES")
            or os.getenv("NOTEBOOKLM_SESSION")
        )
        if not has_cookies:
            self.skipTest("GATE BLOCKED: Dedicated disposable NotebookLM session credentials not provisioned in environment")

        client = NotebookLmMcpClient()
        adapter = KnowledgeSourceAdapter(client)
        try:
            title = "v3-case-test-live-map-gate"
            nb = await client.notebook_create(title)
            self.assertIsNotNone(nb.get("id"))
        finally:
            await client.close()

if __name__ == "__main__":
    unittest.main()
