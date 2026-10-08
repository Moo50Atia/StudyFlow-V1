"""Behavioral tests for turn history recovery in NotebookLM protocol (N17)."""
import pytest
from v3_engine.notebooklm.protocol import (
    compute_history_anchor,
    recover_turn_from_history,
)


def test_n17_compute_history_anchor_compact_utf8():
    q = "Start with First lesson"
    a = "✅ Lesson [1] Completed. Awaiting 'Continue' for the next lesson."
    anchor = compute_history_anchor(q, a)
    assert len(anchor) == 64
    # Deterministic
    assert anchor == compute_history_anchor(q, a)


def test_n17_anchored_history_recovery_succeeds():
    q0 = "Divide prompt text"
    a0 = "Chapter 1\nLesson 1: Introduction"
    q1 = "Execution prompt text"
    a1 = "System Ready"

    history = [
        {"question": q0, "answer": a0},
        {"question": q1, "answer": a1},
        {"question": "Start with First lesson", "answer": "=== FIRST ===\nLecture: W1\n=== END ===\n✅ Lesson [1] Completed. Awaiting 'Continue' for the next lesson."},
    ]

    anchor1 = compute_history_anchor(q1, a1)

    # Recovery after 2 turns (expecting turn index 2)
    res = recover_turn_from_history(
        history_turns=history,
        history_length_before=2,
        expected_question="Start with First lesson",
        expected_anchor_sha256=anchor1,
    )

    assert res["status"] == "recovered"
    assert "=== FIRST ===" in res["answer"]
    assert res["turn_index"] == 2


def test_n17_anchor_mismatch_due_to_interleaving_returns_uncertain():
    q0 = "Divide prompt text"
    a0 = "Chapter 1\nLesson 1: Introduction"
    q1 = "Execution prompt text"
    a1 = "System Ready"

    # Someone manually asked another question in between
    history = [
        {"question": q0, "answer": a0},
        {"question": "What is the weather?", "answer": "Sunny"},
        {"question": q1, "answer": a1},
        {"question": "Start with First lesson", "answer": "Lesson output"},
    ]

    anchor1 = compute_history_anchor(q1, a1)

    # At position 2, the previous question is "What is the weather?", not q1!
    res = recover_turn_from_history(
        history_turns=history,
        history_length_before=2,
        expected_question="Start with First lesson",
        expected_anchor_sha256=anchor1,
    )

    assert res["status"] == "uncertain"
    assert res["reason"] == "history_anchor_mismatch"


def test_n17_repeated_continue_without_anchor_is_rejected():
    # In a long conversation with multiple "Continue" queries
    history = [
        {"question": "Divide", "answer": "Map"},
        {"question": "Execution", "answer": "System Ready"},
        {"question": "Start with First lesson", "answer": "Lesson 1"},
        {"question": "Continue", "answer": "Lesson 2"},
        {"question": "Continue", "answer": "Lesson 3"},
    ]

    # Trying to recover with wrong anchor for position 3
    wrong_anchor = compute_history_anchor("Continue", "Wrong answer")
    res = recover_turn_from_history(
        history_turns=history,
        history_length_before=3,
        expected_question="Continue",
        expected_anchor_sha256=wrong_anchor,
    )

    assert res["status"] == "uncertain"
    assert res["reason"] == "history_anchor_mismatch"


def test_n17_truncated_history_returns_uncertain():
    history = [
        {"question": "Divide", "answer": "Map"},
    ]

    # History only has 1 turn, but history_length_before was 3
    res = recover_turn_from_history(
        history_turns=history,
        history_length_before=3,
        expected_question="Continue",
        expected_anchor_sha256="some_anchor",
    )

    assert res["status"] == "uncertain"
    assert res["reason"] == "history_truncated"


def test_n17_history_exceeding_1000_turns_returns_uncertain():
    huge_history = [{"question": "q", "answer": "a"} for _ in range(1005)]

    res = recover_turn_from_history(
        history_turns=huge_history,
        history_length_before=2,
        expected_question="Continue",
        expected_anchor_sha256="some_anchor",
    )

    assert res["status"] == "uncertain"
    assert res["reason"] == "history_exceeds_limit"


def test_n17_no_successor_turn_returns_not_found():
    history = [
        {"question": "Divide", "answer": "Map"},
        {"question": "Execution", "answer": "System Ready"},
    ]
    anchor = compute_history_anchor("Execution", "System Ready")

    # Claimed at length 2, but provider never executed the 3rd turn
    res = recover_turn_from_history(
        history_turns=history,
        history_length_before=2,
        expected_question="Start with First lesson",
        expected_anchor_sha256=anchor,
    )

    assert res["status"] == "not_found"
    assert res["reason"] == "no_successor_turn"


def test_n17_later_manual_turn_prevents_automatic_recovery():
    anchor = compute_history_anchor("Execution", "System Ready")
    history = [
        {"question": "Execution", "answer": "System Ready"},
        {"question": "Start with First lesson", "answer": "Lesson 1"},
        {"question": "Manual follow-up", "answer": "Unrelated"},
    ]
    res = recover_turn_from_history(history, 1, "Start with First lesson", anchor)
    assert res == {"status": "uncertain", "reason": "additional_turns_after_claim"}


from unittest.mock import AsyncMock, MagicMock, patch
from v3_engine.activities.reconcile_notebook_query import reconcile_notebook_query
from v3_engine.notebooklm.ports import ProviderSession, SessionProvider, NotebookConversation


@pytest.mark.asyncio
async def test_n17_reconcile_activity_lost_start_recovers_without_chat_send():
    q0 = "Divide prompt text"
    a0 = "Chapter 1\nLesson 1: Introduction"
    q1 = "Execution prompt text"
    a1 = "System Ready"
    q2 = "Start with First lesson"
    a2 = "=== FIRST ===\nLecture: W1 | Math\n=== END ===\n✅ Lesson [1] Completed. Awaiting 'Continue' for the next lesson."

    history_pairs = [(q0, a0), (q1, a1), (q2, a2)]
    anchor = compute_history_anchor(q1, a1)

    mock_client = MagicMock()
    mock_client.find_lesson_query = AsyncMock(return_value={
        "query_ref": "query-start-1",
        "binding_ref": "binding-1",
        "state": "uncertain",
        "conversation_in": "convo-1",
        "history_length_before": 2,
        "history_anchor_sha256": anchor,
        "expected_cursor_version": 3,
    })
    mock_client.get_binding_by_ref = AsyncMock(return_value={
        "notebook_id": "nb-1",
    })
    mock_client.record_combined_lesson_result = AsyncMock(return_value={"status": 201})

    chat_send_count = 0

    class MockConv(NotebookConversation):
        async def configure_format(self, notebook_id: str, format_text: str) -> None:
            pass
        async def get_format(self, notebook_id: str):
            pass
        async def ask_once(self, notebook_id: str, question: str, source_ids: list[str], conversation_id: str | None = None):
            nonlocal chat_send_count
            chat_send_count += 1
            raise AssertionError("chat.ask must not be called during read-only turn reconciliation")
        async def history(self, notebook_id: str, conversation_id: str, limit: int = 20):
            return history_pairs

    class MockSess(ProviderSession):
        @property
        def files(self):
            return MagicMock()
        @property
        def conversation(self):
            return MockConv()

    class MockProv(SessionProvider):
        def open(self, profile_ref: str = ""):
            class _Ctx:
                async def __aenter__(self):
                    return MockSess()
                async def __aexit__(self, *args):
                    pass
            return _Ctx()

    with patch("v3_engine.activities.reconcile_notebook_query.HttpLaravelNotebookClient", return_value=mock_client), \
         patch("v3_engine.activities.reconcile_notebook_query.SdkSessionProvider", return_value=MockProv()):
        res = await reconcile_notebook_query({
            "case_id": "case-1",
            "kind": "lesson_start",
            "work_ordinal": 1,
            "binding_ref": "binding-1",
        })

    assert res == {"status": "recorded", "query_ref": "query-start-1"}
    assert chat_send_count == 0
    mock_client.record_combined_lesson_result.assert_awaited_once()
    payload = mock_client.record_combined_lesson_result.call_args[0][1]
    assert payload["answer"] == a2
    assert payload["conversation_out"] == "convo-1"


@pytest.mark.asyncio
async def test_n17_reconcile_activity_lost_continue_recovers_without_chat_send():
    q0 = "Divide prompt text"
    a0 = "Chapter 1\nLesson 1: Introduction"
    q1 = "Execution prompt text"
    a1 = "System Ready"
    q2 = "Start with First lesson"
    a2 = "=== FIRST ===\nLecture: W1 | Math\n=== END ===\n✅ Lesson [1] Completed."
    q3 = "Continue"
    a3 = "=== FIRST ===\nLecture: W2 | Algebra\n=== END ===\n✅ Lesson [2] Completed."

    history_pairs = [(q0, a0), (q1, a1), (q2, a2), (q3, a3)]
    anchor = compute_history_anchor(q2, a2)

    mock_client = MagicMock()
    mock_client.find_lesson_query = AsyncMock(return_value={
        "query_ref": "query-cont-2",
        "binding_ref": "binding-1",
        "state": "uncertain",
        "conversation_in": "convo-1",
        "history_length_before": 3,
        "history_anchor_sha256": anchor,
        "expected_cursor_version": 5,
    })
    mock_client.get_binding_by_ref = AsyncMock(return_value={"notebook_id": "nb-1"})
    mock_client.record_combined_lesson_result = AsyncMock(return_value={"status": 201})

    chat_send_count = 0

    class MockConv(NotebookConversation):
        async def configure_format(self, notebook_id: str, format_text: str) -> None:
            pass
        async def get_format(self, notebook_id: str):
            pass
        async def ask_once(self, *args, **kwargs):
            nonlocal chat_send_count
            chat_send_count += 1
            raise AssertionError("chat.ask must not be called during read-only turn reconciliation")
        async def history(self, notebook_id: str, conversation_id: str, limit: int = 20):
            return history_pairs

    class MockSess(ProviderSession):
        @property
        def files(self):
            return MagicMock()
        @property
        def conversation(self):
            return MockConv()

    class MockProv(SessionProvider):
        def open(self, profile_ref: str = ""):
            class _Ctx:
                async def __aenter__(self):
                    return MockSess()
                async def __aexit__(self, *args):
                    pass
            return _Ctx()

    with patch("v3_engine.activities.reconcile_notebook_query.HttpLaravelNotebookClient", return_value=mock_client), \
         patch("v3_engine.activities.reconcile_notebook_query.SdkSessionProvider", return_value=MockProv()):
        res = await reconcile_notebook_query({
            "case_id": "case-1",
            "kind": "lesson_continue",
            "work_ordinal": 2,
            "binding_ref": "binding-1",
        })

    assert res == {"status": "recorded", "query_ref": "query-cont-2"}
    assert chat_send_count == 0
    mock_client.record_combined_lesson_result.assert_awaited_once()


@pytest.mark.asyncio
async def test_n06_reconcile_activity_lost_divide_without_conversation_blocks_without_chat_send():
    mock_client = MagicMock()
    mock_client.find_lesson_query = AsyncMock(return_value={
        "query_ref": "query-divide-lost",
        "binding_ref": "binding-1",
        "state": "uncertain",
        "conversation_in": None,  # Lost divide has no conversation_in
    })

    with patch("v3_engine.activities.reconcile_notebook_query.HttpLaravelNotebookClient", return_value=mock_client):
        res = await reconcile_notebook_query({
            "case_id": "case-1",
            "kind": "divide",
            "work_ordinal": 0,
            "binding_ref": "binding-1",
        })

    assert res == {"status": "blocked", "error_code": "provider_uncertain"}


@pytest.mark.asyncio
async def test_n17_reconcile_activity_turn_not_in_history_returns_uncertain():
    q0 = "Divide"
    a0 = "Map"
    q1 = "Execution"
    a1 = "System Ready"
    anchor = compute_history_anchor(q1, a1)

    mock_client = MagicMock()
    mock_client.find_lesson_query = AsyncMock(return_value={
        "query_ref": "query-start-unreached",
        "binding_ref": "binding-1",
        "state": "uncertain",
        "conversation_in": "convo-1",
        "history_length_before": 2,
        "history_anchor_sha256": anchor,
        "expected_cursor_version": 3,
    })
    mock_client.get_binding_by_ref = AsyncMock(return_value={"notebook_id": "nb-1"})

    class MockConv(NotebookConversation):
        async def configure_format(self, *args):
            pass
        async def get_format(self, *args):
            pass
        async def ask_once(self, *args, **kwargs):
            raise AssertionError("chat.ask must not be called")
        async def history(self, *args, **kwargs):
            return [(q0, a0), (q1, a1)]

    class MockSess(ProviderSession):
        @property
        def files(self):
            return MagicMock()
        @property
        def conversation(self):
            return MockConv()

    class MockProv(SessionProvider):
        def open(self, *args):
            class _Ctx:
                async def __aenter__(self):
                    return MockSess()
                async def __aexit__(self, *args):
                    pass
            return _Ctx()

    with patch("v3_engine.activities.reconcile_notebook_query.HttpLaravelNotebookClient", return_value=mock_client), \
         patch("v3_engine.activities.reconcile_notebook_query.SdkSessionProvider", return_value=MockProv()):
        res = await reconcile_notebook_query({
            "case_id": "case-1",
            "kind": "lesson_start",
            "work_ordinal": 1,
            "binding_ref": "binding-1",
        })

    assert res == {"status": "uncertain", "query_ref": "query-start-unreached"}
