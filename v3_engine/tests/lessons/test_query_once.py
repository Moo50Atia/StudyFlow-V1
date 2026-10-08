import unittest
from typing import Dict, List, Optional
from v3_engine.sources.notebooklm_adapter import KnowledgeSourceAdapter
from v3_engine.sources.mcp_client import NotebookLmMcpClient

class FakeMcpClientForQuery(NotebookLmMcpClient):
    def __init__(self):
        self.call_count = 0
        self.last_query = ""
        self.last_source_ids = []
        self.last_conversation_id = None
        self.fail_send = False
        self.empty_conv_id = False

    async def notebook_query(
        self,
        notebook_id: str,
        query: str,
        source_ids: Optional[List[str]] = None,
        conversation_id: Optional[str] = None,
    ) -> dict:
        self.call_count += 1
        self.last_query = query
        self.last_source_ids = source_ids or []
        self.last_conversation_id = conversation_id

        if self.fail_send:
            raise RuntimeError("Provider error on send")

        return {
            "answer": f"Answer to: {query[:20]}",
            "conversation_id": "" if self.empty_conv_id else (conversation_id or "conv-resp-1"),
        }

class TestQueryOnce(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.mcp = FakeMcpClientForQuery()
        self.adapter = KnowledgeSourceAdapter(self.mcp)
        self.case_id = "00000000-0000-0000-0000-000000000010"
        self.binding = {
            "id": "00000000-0000-0000-0000-000000000020",
            "case_id": self.case_id,
            "notebook_id": "nb-1",
            "source_id": "src-1",
            "state": "bound",
        }

    async def test_query_once_success(self):
        res = await self.adapter.query_once(
            case_id=self.case_id,
            binding=self.binding,
            query_text="Divide the textbook",
            conversation_id=None,
        )
        self.assertEqual(self.mcp.call_count, 1)
        self.assertEqual(self.mcp.last_source_ids, ["src-1"])
        self.assertIn("answer", res)
        self.assertEqual(res["conversation_id"], "conv-resp-1")

    async def test_query_once_no_automatic_retry_on_failure(self):
        self.mcp.fail_send = True
        with self.assertRaises(RuntimeError):
            await self.adapter.query_once(
                case_id=self.case_id,
                binding=self.binding,
                query_text="Divide query",
            )
        # Exactly one send attempt was made
        self.assertEqual(self.mcp.call_count, 1)

    async def test_query_once_enforces_16384_byte_cap(self):
        huge_query = "A" * 16385
        with self.assertRaises(ValueError):
            await self.adapter.query_once(
                case_id=self.case_id,
                binding=self.binding,
                query_text=huge_query,
            )
        self.assertEqual(self.mcp.call_count, 0)

    async def test_query_once_rejects_empty_conversation_id(self):
        self.mcp.empty_conv_id = True
        with self.assertRaises(ValueError):
            await self.adapter.query_once(
                case_id=self.case_id,
                binding=self.binding,
                query_text="Divide query",
            )
        self.assertEqual(self.mcp.call_count, 1)

if __name__ == "__main__":
    unittest.main()
