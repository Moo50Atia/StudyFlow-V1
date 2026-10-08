"""The prompt cycle must use the source-bound NotebookLM adapter contract."""

from unittest.mock import AsyncMock

import pytest

from v3_engine.prompts.cycle import PromptCycleRunner
from v3_engine.sources.notebooklm_adapter import KnowledgeSourceAdapter


@pytest.mark.asyncio
async def test_cycle_resolves_case_binding_and_preserves_conversation_for_each_turn():
    laravel = AsyncMock()
    laravel.get_binding_for_query.return_value = {
        "case_id": "case-1",
        "state": "bound",
        "notebook_id": "notebook-private-1",
        "source_id": "source-private-1",
    }
    mcp = AsyncMock()
    mcp.notebook_query.return_value = {
        "answer": "response",
        "conversation_id": "conversation-next",
    }
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)
    runner = PromptCycleRunner("http://laravel.test", "callback-secret", adapter)

    for prompt, conversation in [("Execution prompt bytes", "conversation-start"), ("Second prompt bytes", "conversation-next")]:
        result = await runner._source_query("case-1", "binding-1", prompt, conversation)
        assert result == {"answer": "response", "conversation_id": "conversation-next"}

    assert laravel.get_binding_for_query.await_count == 2
    assert [call.args for call in laravel.get_binding_for_query.await_args_list] == [
        ("case-1", "binding-1"), ("case-1", "binding-1"),
    ]
    assert [call.kwargs for call in mcp.notebook_query.await_args_list] == [
        {
            "notebook_id": "notebook-private-1",
            "query": "Execution prompt bytes",
            "source_ids": ["source-private-1"],
            "conversation_id": "conversation-start",
        },
        {
            "notebook_id": "notebook-private-1",
            "query": "Second prompt bytes",
            "source_ids": ["source-private-1"],
            "conversation_id": "conversation-next",
        },
    ]


@pytest.mark.asyncio
async def test_cycle_rejects_cross_case_binding_before_provider_query():
    laravel = AsyncMock()
    laravel.get_binding_for_query.return_value = {
        "case_id": "different-case", "state": "bound",
        "notebook_id": "private", "source_id": "private-source",
    }
    mcp = AsyncMock()
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)
    runner = PromptCycleRunner("http://laravel.test", "callback-secret", adapter)

    with pytest.raises(ValueError, match="Cross-case binding"):
        await runner._source_query("case-1", "binding-1", "Execution prompt bytes", None)

    mcp.notebook_query.assert_not_awaited()


@pytest.mark.asyncio
async def test_cycle_runs_sent_second_and_third_for_work_ordinal_greater_than_one():
    laravel = AsyncMock()
    mcp = AsyncMock()
    adapter = KnowledgeSourceAdapter(mcp_client=mcp, laravel_client=laravel)
    runner = PromptCycleRunner("http://laravel.test", "callback-secret", adapter)

    # Mock _api_call
    api_calls = []

    async def mock_api(client, method, path, payload=None):
        api_calls.append({"method": method, "path": path, "payload": payload})
        if "prompt-context/2" in path:
            return {
                "map_ref": "map-1",
                "work_node_ref": "node-2",
                "section_refs": ["sec-21", "sec-22"],
                "cursor_version": 3,
                "conversation_ref": "conv-continue-2",
                "sections": [
                    {"ordinal_in_work": 1, "section_ref": "sec-21"},
                    {"ordinal_in_work": 2, "section_ref": "sec-22"},
                ],
                "continue_origin": None,
            }
        elif "prompt-work-items/2/claim" in path:
            return {"work_item_ref": "work-item-2"}
        elif "turns/second/claim" in path:
            return {"turn_ref": "turn-sec-2", "disposition": "send_now"}
        elif "turns/third/claim" in path:
            return {"turn_ref": "turn-third-2", "disposition": "send_now"}
        elif "prompt-turns/turn-sec-2/result" in path:
            return {"status": "recorded"}
        elif "prompt-turns/turn-third-2/result" in path:
            return {"status": "recorded", "original_prompt_refs": ["prompt-21", "prompt-22"]}
        elif "prompt-work-items/work-item-2/complete" in path:
            return {"completion_ref": "comp-2", "cursor_version": 4}
        return {}

    runner._api_call = mock_api

    valid_second_reply = "Lecture: L2\n\nLec2 (Sec1):\n\"First second placeholder\"\n\nLec2 (Sec2):\n\"Second second placeholder\""
    valid_third_reply = "Lecture: L2\n\nLec2 (Sec1):\n\"Process scene for sec 1\"\n\nLec2 (Sec2):\n\"Comparison scene for sec 2\""

    async def mock_source_query(case_id, binding_ref, prompt_text, conv_id):
        if "Second" in prompt_text or "preparation engine" in prompt_text:
            return {"answer": valid_second_reply, "conversation_id": "conv-after-sec"}
        elif "Third" in prompt_text or "Dynamic View prompts" in prompt_text:
            return {"answer": valid_third_reply, "conversation_id": "conv-after-third"}
        return {"answer": "default", "conversation_id": conv_id}

    runner._source_query = mock_source_query

    result = await runner.run_cycle(case_id="case-1", work_ordinal=2, binding_ref="bind-1")

    assert result["status"] == "completed"
    assert result["work_item_ref"] == "work-item-2"
    assert result["original_prompt_refs"] == ["prompt-21", "prompt-22"]
    assert result["completion_ref"] == "comp-2"
    assert result["cursor_version"] == 4

    # Verify no execution turn was claimed
    claimed_paths = [c["path"] for c in api_calls]
    assert not any("turns/execution/claim" in p for p in claimed_paths)
    assert any("turns/second/claim" in p for p in claimed_paths)
    assert any("turns/third/claim" in p for p in claimed_paths)

