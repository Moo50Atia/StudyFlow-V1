"""Regression coverage for the production 009 activity services and client."""

import json
from pathlib import Path
from unittest.mock import AsyncMock

import httpx
import pytest

from v3_engine.contracts import verify_hmac_signature
from v3_engine.lessons.client import HttpLaravelLessonClient
from v3_engine.lessons.map_service import MapService

FIXTURES = Path(__file__).parent / "fixtures"


@pytest.mark.asyncio
async def test_map_service_runs_divide_and_coverage_and_persists_actual_map():
    client = AsyncMock()
    client.get_activation_context.return_value = {
        "data": {
            "case_id": "case-1",
            "binding_ref": "binding-1",
            "page_count": 1,
            "map_ref": None,
            "map_state": None,
            "work_items": [],
        }
    }
    client.claim_query.side_effect = [
        {"data": {"query_ref": "divide-1", "disposition": "send_now"}},
        {"data": {"query_ref": "coverage-1", "disposition": "send_now"}},
    ]
    client.post_query_result.side_effect = [
        {"data": {"map_ref": "map-1", "state": "recorded"}},
        {"data": {"map_ref": "map-1", "state": "recorded", "cursor_version": 1}},
    ]
    adapter = AsyncMock()
    adapter.query_once_by_binding.side_effect = [
        {"answer": (FIXTURES / "divide_structured.txt").read_text(), "conversation_id": "conv-divide"},
        {"answer": "PAGE 1: W1,W2,W3", "conversation_id": "conv-coverage"},
    ]

    result = await MapService(adapter, client).execute_mapping("case-1", "activation-1", "binding-1")

    assert result == {"status": "ready", "map_ref": "map-1", "work_count": 3}
    assert adapter.query_once_by_binding.await_count == 2
    coverage = client.claim_query.await_args_list[1].kwargs["query_text"]
    assert "W1 | Kinematics / Linear Motion / Uniform Velocity" in coverage
    assert "W2 | Kinematics / Linear Motion / Constant Acceleration" in coverage
    assert "W3 | Dynamics / Newton's Laws" in coverage
    first_result = client.post_query_result.await_args_list[0].kwargs["parsed"]["nodes"]
    assert len(first_result) == 6


@pytest.mark.asyncio
async def test_map_service_returns_only_committed_ready_map_on_replay():
    client = AsyncMock()
    client.get_activation_context.return_value = {
        "data": {
            "case_id": "case-1",
            "binding_ref": "binding-1",
            "page_count": 2,
            "map_ref": "map-committed",
            "map_state": "ready",
            "work_count": 4,
        }
    }
    adapter = AsyncMock()

    result = await MapService(adapter, client).execute_mapping("case-1", "activation-1", "binding-1")

    assert result == {"status": "ready", "map_ref": "map-committed", "work_count": 4}
    client.claim_query.assert_not_awaited()
    adapter.query_once_by_binding.assert_not_awaited()


@pytest.mark.asyncio
async def test_unresolved_divide_claim_is_not_resent_or_reported_ready():
    client = AsyncMock()
    client.get_activation_context.side_effect = [
        {"data": {"case_id": "case-1", "binding_ref": "binding-1", "page_count": 3}},
        {"data": {"case_id": "case-1", "binding_ref": "binding-1", "page_count": 3}},
    ]
    client.claim_query.return_value = {"data": {"query_ref": "q-1", "disposition": "reconcile"}}
    adapter = AsyncMock()

    result = await MapService(adapter, client).execute_mapping("case-1", "activation-1", "binding-1")

    assert result == {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": "q-1"}
    adapter.query_once_by_binding.assert_not_awaited()


@pytest.mark.asyncio
async def test_lost_divide_result_response_reconciles_committed_pending_map_without_resend():
    client = AsyncMock()
    client.get_activation_context.side_effect = [
        {"data": {"case_id": "case-1", "binding_ref": "binding-1", "page_count": 3}},
        {"data": {
            "case_id": "case-1", "binding_ref": "binding-1", "page_count": 3,
            "map_ref": "map-pending", "map_state": "pending", "work_count": 1,
            "divide_conversation_ref": "conv-divide",
            "work_items": [{"work_ordinal": 1, "work_path": "Chapter / Work"}],
        }},
    ]
    client.claim_query.side_effect = [
        {"data": {"query_ref": "divide-1", "disposition": "send_now"}},
        {"data": {"query_ref": "coverage-1", "disposition": "send_now"}},
    ]
    client.post_query_result.side_effect = [
        TimeoutError("divide committed; response lost"),
        {"data": {"state": "recorded", "map_ref": "map-pending"}},
    ]
    adapter = AsyncMock()
    adapter.query_once_by_binding.side_effect = [
        {"answer": (FIXTURES / "divide_structured.txt").read_text(), "conversation_id": "conv-divide"},
        {"answer": "PAGE 1: W1\nPAGE 2: W1\nPAGE 3: W1", "conversation_id": "conv-coverage"},
    ]

    result = await MapService(adapter, client).execute_mapping("case-1", "activation-1", "binding-1")

    assert result == {"status": "ready", "map_ref": "map-pending", "work_count": 1}
    assert adapter.query_once_by_binding.await_count == 2
    assert client.claim_query.await_count == 2
    assert client.post_query_result.await_count == 2


@pytest.mark.asyncio
async def test_lost_coverage_result_response_reconciles_ready_map():
    client = AsyncMock()
    pending = {
        "case_id": "case-1", "binding_ref": "binding-1", "page_count": 1,
        "map_ref": "map-1", "map_state": "pending", "work_count": 1,
        "divide_conversation_ref": "conv-divide",
        "work_items": [{"work_ordinal": 1, "work_path": "Chapter / Work"}],
    }
    client.get_activation_context.side_effect = [
        {"data": pending},
        {"data": {**pending, "map_state": "ready"}},
    ]
    client.claim_query.return_value = {"data": {"query_ref": "coverage-1", "disposition": "send_now"}}
    client.post_query_result.side_effect = TimeoutError("response lost after commit")
    adapter = AsyncMock()
    adapter.query_once_by_binding.return_value = {
        "answer": "PAGE 1: W1", "conversation_id": "conv-coverage",
    }

    result = await MapService(adapter, client).execute_mapping("case-1", "activation-1", "binding-1")

    assert result == {"status": "ready", "map_ref": "map-1", "work_count": 1}
    adapter.query_once_by_binding.assert_awaited_once()
    client.claim_query.assert_awaited_once()
    client.post_query_result.assert_awaited_once()


@pytest.mark.asyncio
async def test_laravel_lesson_client_signs_and_sends_identical_unicode_json_bytes(monkeypatch):
    secret = "callback-secret-for-client-test"
    captured = []

    def handler(request: httpx.Request) -> httpx.Response:
        captured.append(request)
        return httpx.Response(200, json={"data": {"query_ref": "q-1", "disposition": "send_now"}})

    original_client = httpx.AsyncClient
    monkeypatch.setattr(
        "v3_engine.lessons.client.httpx.AsyncClient",
        lambda **kwargs: original_client(transport=httpx.MockTransport(handler), **kwargs),
    )
    client = HttpLaravelLessonClient("http://laravel.test", secret)

    result = await client.claim_query(
        case_id="case-1",
        kind="first",
        ordinal=1,
        activation_id="activation-1",
        binding_ref="binding-1",
        query_text="تعليم",
        conversation_in="conv-1",
    )

    assert result["data"]["query_ref"] == "q-1"
    request = captured[0]
    assert request.url.path == "/api/internal/v1/lesson-queries/case-1/first/1/claim"
    assert "تعليم".encode() in request.content
    assert json.loads(request.content)["binding_ref"] == "binding-1"
    assert verify_hmac_signature(
        "POST",
        request.url.path,
        request.headers["X-V3-Timestamp"],
        request.headers["X-V3-Signature"],
        request.content,
        secret,
    )
