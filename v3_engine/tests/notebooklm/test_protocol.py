"""Tests for NotebookLM direct protocol execution sequence (Spec 016).

Covers FR-007, FR-008, FR-011, FR-016 / N10, N11.
"""
from __future__ import annotations

from pathlib import Path
from unittest.mock import AsyncMock, MagicMock
import pytest

from v3_engine.notebooklm.ports import (
    NotebookConversation,
    NotebookFiles,
    ProviderError,
    ProviderSession,
    SessionProvider,
    TurnAnswer,
)
from v3_engine.notebooklm.protocol import (
    DirectLessonProtocol,
    DirectSourceProtocol,
    parse_direct_divide_map,
)
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT

FIXTURES_DIR = Path(__file__).resolve().parent.parent.parent.parent / "tests" / "Fixtures" / "V3" / "NotebookLM"


def test_direct_divide_parser_accepts_notebooklm_arrow_hierarchy():
    """NotebookLM may express child relationships with an arrow instead of indentation."""
    result = parse_direct_divide_map(
        "Chapter 1: Fractions [1]\n"
        "=> Lesson 1: Equivalent Fractions [1]\n\n"
        "Chapter 2: Geometry [1]\n"
        "=> Lesson 2: Area of a Rectangle [1]"
    )

    work_items = [node for node in result if node.work_ordinal is not None]
    assert [(node.title, node.parent_position_path, node.work_ordinal) for node in work_items] == [
        ("Equivalent Fractions [1]", "001", 1),
        ("Area of a Rectangle [1]", "002", 2),
    ]


def test_direct_divide_parser_splits_one_line_chapter_and_lesson():
    nodes = parse_direct_divide_map("Chapter 1: Motion => Lesson 1: Average Velocity [1]")
    assert [(node.title, node.work_ordinal) for node in nodes] == [
        ("Motion", None), ("Average Velocity [1]", 1),
    ]


def test_direct_divide_parser_accepts_notebooklm_unicode_tree_hierarchy():
    """NotebookLM may render a valid map with Unicode tree branches and vertical guides."""
    result = parse_direct_divide_map(
        "Chapter: Unit 3: Geometry\n"
        "├── Mini Chapter: Similarity\n"
        "│   ├── Lesson 1: Similar Polygons\n"
        "│   └── Lesson 2: Scale Factors\n"
        "└── Lesson 3: Parallel Lines\n"
        "└── Lesson 4: Perpendicular Lines"
    )

    work_items = [node for node in result if node.work_ordinal is not None]
    assert [(node.title, node.parent_position_path, node.work_ordinal) for node in work_items] == [
        ("Similar Polygons", "001.001", 1),
        ("Scale Factors", "001.001", 2),
        ("Parallel Lines", "001", 3),
        ("Perpendicular Lines", "001", 4),
    ]


def test_direct_divide_parser_normalizes_repeated_tree_guides_for_lesson_leaf():
    """Repeated Unicode continuation bars do not add unsupported semantic levels."""
    result = parse_direct_divide_map(
        "Chapter: Unit 3\n"
        "├── Mini Chapter: Parallel Lines\n"
        "│   │   ├── Lesson 6: Relationship Between Slopes [1]"
    )

    lesson = next(node for node in result if node.kind == "lesson")
    assert lesson.parent_position_path == "001.001"
    assert lesson.work_ordinal == 1


def test_direct_divide_parser_accepts_provider_arrow_with_three_space_lesson_indent():
    """Observed NotebookLM arrow formatting must not block lesson iteration."""
    result = parse_direct_divide_map(
        "Chapter 1: Similarity\n"
        "   => Lesson 1: Similarity of Polygons\n"
        "   => Lesson 2: Scale Factor\n"
        "   => Lesson 3: Perimeter Ratios"
    )

    work_items = [node for node in result if node.work_ordinal is not None]
    assert [node.title for node in work_items] == [
        "Similarity of Polygons",
        "Scale Factor",
        "Perimeter Ratios",
    ]
    assert [node.work_ordinal for node in work_items] == [1, 2, 3]


class MockConversation(NotebookConversation):
    def __init__(self):
        self.turns: list[dict] = []
        self.next_answers: list[TurnAnswer] = []
        self.history_turns: list[tuple[str, str]] = []

    def queue_answer(self, text: str, conversation_id: str):
        self.next_answers.append(TurnAnswer(text=text, conversation_id=conversation_id))

    async def configure_format(self, notebook_id: str, format_text: str) -> None:
        pass

    async def get_format(self, notebook_id: str):
        from v3_engine.notebooklm.ports import ChatFormatSettings
        return ChatFormatSettings(custom_prompt="configured")

    async def ask_once(
        self,
        notebook_id: str,
        question: str,
        source_ids: list[str],
        conversation_id: str | None = None,
    ) -> TurnAnswer:
        self.turns.append({
            "notebook_id": notebook_id,
            "question": question,
            "source_ids": list(source_ids),
            "conversation_id": conversation_id,
        })
        if not self.next_answers:
            raise RuntimeError("No queued answers in MockConversation")
        answer = self.next_answers.pop(0)
        self.history_turns.append((question, answer.text))
        return answer

    async def history(self, notebook_id: str, conversation_id: str, limit: int = 100) -> list[tuple[str, str]]:
        return self.history_turns[:limit]


class MockSession(ProviderSession):
    def __init__(self, conversation: MockConversation):
        self._conv = conversation

    @property
    def files(self) -> NotebookFiles:
        return MagicMock()

    @property
    def conversation(self) -> NotebookConversation:
        return self._conv


class MockSessionProvider(SessionProvider):
    def __init__(self, conversation: MockConversation):
        self.conv = conversation

    def open(self, profile_ref: str):
        session = MockSession(self.conv)
        cm = AsyncMock()
        cm.__aenter__.return_value = session
        cm.__aexit__.return_value = None
        return cm


@pytest.fixture
def mock_conv():
    return MockConversation()


@pytest.fixture
def mock_provider(mock_conv):
    return MockSessionProvider(mock_conv)


@pytest.fixture
def mock_laravel():
    client = MagicMock()
    client.get_binding_by_ref = AsyncMock(return_value={
        "notebook_id": "nb-100", "framework_sha256": "f" * 64,
    })
    client.find_lesson_query = AsyncMock(return_value=None)
    async def claim(case_id, kind, ordinal, payload):
        return {
            "query_ref": f"qry-{kind}-{ordinal}", "disposition": "send_now",
            "claimed_cursor_version": (payload["expected_cursor_version"] or 0) + (kind.startswith("lesson_") and 1),
        }
    client.claim_lesson_query = AsyncMock(side_effect=claim)
    client.record_divide_result = AsyncMock(return_value={"status": "recorded"})
    client.record_execution_result = AsyncMock(return_value={"status": "recorded"})
    client.record_combined_lesson_result = AsyncMock(return_value={"bundle_ref": "bnd-1", "cursor_version": 2})
    return client


@pytest.mark.asyncio
async def test_n10_divide_uses_pdf_only_and_records_conversation(mock_conv, mock_provider, mock_laravel):
    """N10: Divide prompt scopes only the PDF source and records stable server conversation ID."""
    divide_map_text = (FIXTURES_DIR / "valid_divide_map.txt").read_text(encoding="utf-8")
    mock_conv.queue_answer(divide_map_text, conversation_id="conv-srv-1")

    protocol = DirectLessonProtocol(
        provider=mock_provider,
        laravel_client=mock_laravel,
    )

    result = await protocol.divide(
        case_id="case-100",
        binding_ref="bind-100",
        notebook_id="nb-100",
        pdf_source_id="src-pdf-1",
        expected_cursor_version=None,
    )

    # 1. Assert exactly 1 turn sent
    assert len(mock_conv.turns) == 1
    turn = mock_conv.turns[0]

    # 2. Assert question is exact canonical Divide prompt
    assert turn["question"] == DIVIDE_PROMPT

    # 3. Assert source scope is PDF only (no steps source)
    assert turn["source_ids"] == ["src-pdf-1"]

    # 4. Assert conversation_id was None (new chat)
    assert turn["conversation_id"] is None

    # 5. Assert result contains server conversation ID and parsed nodes
    assert result["conversation_id"] == "conv-srv-1"
    assert result["work_count"] == 5

    # 6. Assert laravel record call was made
    mock_laravel.record_divide_result.assert_awaited_once()
    mock_laravel.claim_lesson_query.assert_awaited_once()
    assert mock_laravel.record_divide_result.call_args.kwargs["query_ref"] == "qry-divide-0"


@pytest.mark.asyncio
async def test_n11_execution_uses_both_sources_and_requires_system_ready(mock_conv, mock_provider, mock_laravel):
    """N11: Execution prompt uses both sources and strictly requires exact System Ready acknowledgement."""
    mock_conv.queue_answer("System Ready", conversation_id="conv-srv-1")
    mock_conv.history_turns = [(DIVIDE_PROMPT, "Chapter: C\nLesson: L")]

    protocol = DirectLessonProtocol(
        provider=mock_provider,
        laravel_client=mock_laravel,
    )

    result = await protocol.execution(
        case_id="case-100",
        binding_ref="bind-100",
        notebook_id="nb-100",
        pdf_source_id="src-pdf-1",
        steps_source_id="src-steps-1",
        conversation_id="conv-srv-1",
        expected_cursor_version=None,
    )

    assert len(mock_conv.turns) == 1
    turn = mock_conv.turns[0]

    # 1. Exact canonical Execution prompt
    assert turn["question"] == EXECUTION_PROMPT

    # 2. Both sources scoped
    assert turn["source_ids"] == ["src-pdf-1", "src-steps-1"]

    # 3. Divide conversation ID passed
    assert turn["conversation_id"] == "conv-srv-1"
    assert result["status"] == "acknowledged"


@pytest.mark.asyncio
async def test_n11_execution_rejects_non_system_ready(mock_conv, mock_provider, mock_laravel):
    """N11: Execution prompt rejects answer that does not equal System Ready."""
    mock_conv.queue_answer("I understand the instructions and I am ready.", conversation_id="conv-srv-1")
    mock_conv.history_turns = [(DIVIDE_PROMPT, "Chapter: C\nLesson: L")]

    protocol = DirectLessonProtocol(
        provider=mock_provider,
        laravel_client=mock_laravel,
    )

    with pytest.raises(ProviderError) as exc_info:
        await protocol.execution(
            case_id="case-100",
            binding_ref="bind-100",
            notebook_id="nb-100",
            pdf_source_id="src-pdf-1",
            steps_source_id="src-steps-1",
            conversation_id="conv-srv-1",
            expected_cursor_version=None,
        )
    assert exc_info.value.code == "protocol_validation"


@pytest.mark.asyncio
async def test_n11_lesson_start_and_continue_sequence(mock_conv, mock_provider, mock_laravel):
    """N11: Fixed sequence sends 'Start with First lesson' then 'Continue' per subsequent work item."""
    valid_combined = (FIXTURES_DIR / "valid_combined_lesson_1.txt").read_text(encoding="utf-8")
    mock_conv.history_turns = [(DIVIDE_PROMPT, "Chapter: C\nLesson: L"), (EXECUTION_PROMPT, "System Ready")]

    # Queue answer for start (ordinal 1) and continue (ordinal 2)
    mock_conv.queue_answer(valid_combined, conversation_id="conv-srv-1")
    # Change ordinal to 2 for continue response
    valid_combined_w2 = (
        valid_combined.replace("✅ Lesson [1] Completed.", "✅ Lesson [2] Completed.")
        .replace("Lecture: W1", "Lecture: W2")
        .replace("Lec1", "Lec2")
    )
    mock_conv.queue_answer(valid_combined_w2, conversation_id="conv-srv-1")

    protocol = DirectLessonProtocol(
        provider=mock_provider,
        laravel_client=mock_laravel,
    )

    # 1. Lesson Start for ordinal 1
    start_res = await protocol.process_lesson(
        case_id="case-100",
        binding_ref="bind-100",
        notebook_id="nb-100",
        pdf_source_id="src-pdf-1",
        steps_source_id="src-steps-1",
        conversation_id="conv-srv-1",
        work_ordinal=1,
        expected_cursor_version=1,
    )

    assert mock_conv.turns[0]["question"] == "Start with First lesson"
    assert mock_conv.turns[0]["source_ids"] == ["src-pdf-1", "src-steps-1"]
    assert mock_conv.turns[0]["conversation_id"] == "conv-srv-1"

    # 2. Lesson Continue for ordinal 2
    continue_res = await protocol.process_lesson(
        case_id="case-100",
        binding_ref="bind-100",
        notebook_id="nb-100",
        pdf_source_id="src-pdf-1",
        steps_source_id="src-steps-1",
        conversation_id="conv-srv-1",
        work_ordinal=2,
        expected_cursor_version=2,
    )

    assert mock_conv.turns[1]["question"] == "Continue"
    assert mock_conv.turns[1]["source_ids"] == ["src-pdf-1", "src-steps-1"]
    assert mock_conv.turns[1]["conversation_id"] == "conv-srv-1"


@pytest.mark.asyncio
async def test_n10_n11_no_coverage_no_separate_steps(mock_conv, mock_provider, mock_laravel):
    """N10/N11: Ensure direct protocol never sends Coverage question or standalone First/Second/Third."""
    protocol = DirectLessonProtocol(
        provider=mock_provider,
        laravel_client=mock_laravel,
    )

    # All questions asked in turns must be in the approved closed set:
    approved_questions = {
        DIVIDE_PROMPT,
        EXECUTION_PROMPT,
        "Start with First lesson",
        "Continue",
    }

    divide_map_text = (FIXTURES_DIR / "valid_divide_map.txt").read_text(encoding="utf-8")
    mock_conv.queue_answer(divide_map_text, conversation_id="conv-srv-1")

    await protocol.divide(
        case_id="case-100",
        binding_ref="bind-100",
        notebook_id="nb-100",
        pdf_source_id="src-pdf-1",
        expected_cursor_version=None,
    )

    for turn in mock_conv.turns:
        assert turn["question"] in approved_questions
        assert "coverage" not in turn["question"].lower()
        assert turn["question"] != "FIRST"
        assert turn["question"] != "SECOND"
        assert turn["question"] != "THIRD"
