"""Exercise registered direct activities with the actual SDK port wrapper."""
from __future__ import annotations

from contextlib import asynccontextmanager
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from v3_engine.activities.map_case import map_case
from v3_engine.activities.process_notebook_lesson import process_notebook_lesson
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.notebooklm.sdk_provider import SdkProviderSession


@pytest.mark.asyncio
async def test_registered_mapping_activity_claims_divide_then_execution(monkeypatch):
    divide_text = (
        Path(__file__).resolve().parents[3]
        / "tests/Fixtures/V3/NotebookLM/valid_divide_map.txt"
    ).read_text(encoding="utf-8")
    sdk = AsyncMock()
    sent: list[tuple[str, list[str], str | None]] = []

    async def ask(_notebook, question, *, source_ids, conversation_id=None):
        sent.append((question, source_ids, conversation_id))
        return SimpleNamespace(
            answer=divide_text if question == DIVIDE_PROMPT else "System Ready",
            conversation_id="conversation-1", turn_number=len(sent),
        )

    sdk.chat.ask.side_effect = ask
    sdk.chat.get_history.return_value = [(DIVIDE_PROMPT, divide_text)]

    class Provider:
        @asynccontextmanager
        async def open(self, _profile_ref=None):
            yield SdkProviderSession(sdk)

    class Laravel:
        def __init__(self):
            self.map_ready = False
            self.execution_recorded = False
            self.claims: list[tuple[str, int]] = []

        async def get_binding_by_ref(self, case_id, binding_ref):
            assert (case_id, binding_ref) == ("case-1", "binding-1")
            return {
                "notebook_id": "notebook-1", "framework_sha256": "f" * 64,
                "manifest": [
                    {"role": "educational_pdf", "provider_source_id": "pdf-1"},
                    {"role": "framework_steps", "provider_source_id": "steps-1"},
                ],
            }

        async def get_map_context(self, _activation_id):
            return {
                "map_ref": "map-1" if self.map_ready else None,
                "map_state": "ready" if self.map_ready else None,
                "divide_conversation_ref": "conversation-1" if self.map_ready else None,
                "work_count": 5 if self.map_ready else None,
            }

        async def get_lesson_context(self, _case_id, _ordinal):
            return {"phase": "start_pending", "cursor_version": 1}

        async def find_lesson_query(self, *_args):
            return None

        async def claim_lesson_query(self, case_id, kind, ordinal, body):
            assert case_id == "case-1"
            assert len(body["request_sha256"]) == 64
            self.claims.append((kind, ordinal))
            return {"query_ref": f"query-{kind}", "disposition": "send_now",
                    "claimed_cursor_version": body["expected_cursor_version"]}

        async def record_divide_result(self, *, query_ref, answer, conversation_out):
            assert query_ref == "query-divide" and answer == divide_text
            assert conversation_out == "conversation-1"
            self.map_ready = True
            return {"map_ref": "map-1"}

        async def record_execution_result(self, *, query_ref, answer, conversation_out):
            assert (query_ref, answer, conversation_out) == (
                "query-execution", "System Ready", "conversation-1"
            )
            self.execution_recorded = True
            return {"state": "recorded"}

    laravel = Laravel()
    monkeypatch.setattr("v3_engine.notebooklm.laravel_client.HttpLaravelNotebookClient", lambda: laravel)
    monkeypatch.setattr("v3_engine.notebooklm.sdk_provider.SdkSessionProvider", Provider)

    result = await map_case({
        "case_id": "case-1", "activation_id": "map-activation-1",
        "binding_ref": "binding-1", "protocol": "notebooklm_combined_v1",
    })
    assert result == {"status": "ready", "map_ref": "map-1", "work_count": 5}
    assert laravel.claims == [("divide", 0), ("execution", 0)]
    assert laravel.execution_recorded
    assert sent == [
        (DIVIDE_PROMPT, ["pdf-1"], None),
        (EXECUTION_PROMPT, ["pdf-1", "steps-1"], "conversation-1"),
    ]


@pytest.mark.asyncio
async def test_registered_lesson_activity_preserves_flat_callback_refs(monkeypatch):
    class Laravel:
        async def get_lesson_context(self, case_id, ordinal):
            assert (case_id, ordinal) == ("case-1", 2)
            return {
                "cursor_version": 4, "conversation_ref": "conversation-1",
                "work_node_ref": "node-2",
            }

        async def get_binding_by_ref(self, case_id, binding_ref):
            assert (case_id, binding_ref) == ("case-1", "binding-1")
            return {
                "notebook_id": "notebook-1",
                "manifest": [
                    {"role": "educational_pdf", "provider_source_id": "pdf-1"},
                    {"role": "framework_steps", "provider_source_id": "steps-1"},
                ],
            }

    class Protocol:
        def __init__(self, provider, laravel_client):
            pass

        async def process_lesson(self, **kwargs):
            assert kwargs == {
                "case_id": "case-1", "binding_ref": "binding-1",
                "notebook_id": "notebook-1", "pdf_source_id": "pdf-1",
                "steps_source_id": "steps-1", "conversation_id": "conversation-1",
                "work_ordinal": 2, "expected_cursor_version": 4,
            }
            return {
                "status": "completed", "bundle_ref": "bundle-2",
                "completion_ref": "completion-2", "cursor_version": 6,
                "section_refs": ["section-2"], "original_prompt_refs": ["prompt-2"],
            }

    monkeypatch.setattr(
        "v3_engine.activities.process_notebook_lesson.HttpLaravelNotebookClient", Laravel
    )
    monkeypatch.setattr(
        "v3_engine.activities.process_notebook_lesson.DirectLessonProtocol", Protocol
    )
    result = await process_notebook_lesson({
        "case_id": "case-1", "binding_ref": "binding-1", "work_ordinal": 2,
    })
    assert result == {
        "status": "completed", "bundle_ref": "bundle-2",
        "completion_ref": "completion-2", "work_ordinal": 2,
        "cursor_version": 6, "section_refs": ["section-2"],
        "original_prompt_refs": ["prompt-2"],
    }
