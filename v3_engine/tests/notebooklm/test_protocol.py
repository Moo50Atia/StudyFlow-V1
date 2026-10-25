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


def test_markdown_structural_labels_preserve_exact_hierarchy():
    raw = "Chapter: Objects\nMini Chapter 1: Basics\nLesson 1.1: Classes\nLesson 1.2: Objects"
    styled = "# Structural Map: Objects\n\n## Chapter: Objects\n### Mini Chapter 1: Basics\n* **Lesson 1.1:** Classes\n* **Lesson 1.2:** Objects"
    assert parse_direct_divide_map(styled) == parse_direct_divide_map(raw)


def test_tree_subtopics_stay_with_named_lesson_and_preserve_mini_chapter_parent():
    nodes = parse_direct_divide_map((FIXTURES_DIR / "map_tree_subtopics.txt").read_text())
    work = [n for n in nodes if n.work_ordinal is not None]
    assert [(n.title, n.parent_position_path, n.work_ordinal) for n in work] == [
        ("Objects and Classes", "001.001", 1),
        ("Class Notation", "001.001", 2),
        ("Object Relationships", "001.002", 3),
    ]


@pytest.mark.parametrize("raw", [
    "Chapter: Objects\nMini Chapter: Foundations\n├── Unlabelled Lesson",
    "Chapter: Objects\n├── Lesson 1: Objects\n├── Unlabelled Sibling",
    "Chapter: Objects\n├── Lesson 1: Objects\n│   └── Lesson 2",
    "Chapter: Objects\n├── Lesson 1: Objects\nExtra prose",
])
def test_unlabelled_tree_nodes_cannot_replace_structural_lessons(raw):
    with pytest.raises(ProviderError):
        parse_direct_divide_map(raw)


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
async def test_mapped_topic_questions_are_sent_and_signed_exactly(mock_conv, mock_provider, mock_laravel):
    import hashlib
    q1 = 'Start with First lesson\n\nProcess ONLY mapped work item W1 of 2.\nMapped topic: "Fields"'
    q2 = 'Continue\n\nProcess ONLY mapped work item W2 of 2.\nMapped topic: "Methods"'
    mock_laravel.get_binding_by_ref.return_value.update({
        "lesson_request_mode": "mapped_topic_v1", "lesson_questions": {"1": q1, "2": q2},
    })
    mock_conv.history_turns = [(DIVIDE_PROMPT, "map"), (EXECUTION_PROMPT, "System Ready")]
    mock_conv.queue_answer("Fields answer", conversation_id="conv-1")
    mock_conv.queue_answer("Methods answer", conversation_id="conv-1")
    protocol = DirectLessonProtocol(mock_provider, mock_laravel)
    for ordinal, question in ((1, q1), (2, q2)):
        result = await protocol.process_lesson(
            "case-100", "bind-100", "nb-100", "pdf", "steps", "conv-1", ordinal, 2 * ordinal + 1,
        )
        assert result["status"] == "completed"
        assert mock_conv.turns[-1]["question"] == question
        payload = mock_laravel.claim_lesson_query.call_args.args[-1]
        assert payload["query_text"] == question
        from v3_engine.notebooklm.protocol import compute_query_request_digest, COMBINED_RESPONSE_FORMAT_SHA256
        assert payload["request_sha256"] == compute_query_request_digest(
            "case-100", "bind-100", "lesson_start" if ordinal == 1 else "lesson_continue", ordinal,
            2 * ordinal + 1, "conv-1", ["educational_pdf", "framework_steps"],
            "f" * 64, COMBINED_RESPONSE_FORMAT_SHA256, hashlib.sha256(question.encode()).hexdigest(),
        )
    assert len(mock_conv.turns) == 2


@pytest.mark.asyncio
@pytest.mark.parametrize("wrong_prefix", [False, True])
async def test_mapped_topic_recovery_anchors_full_question_prefix_without_resend(mock_conv, mock_provider, mock_laravel, wrong_prefix):
    q1, q2 = 'Start with First lesson\nMapped topic: "Fields"', 'Continue\nMapped topic: "Methods"'
    mock_laravel.get_binding_by_ref.return_value.update({
        "lesson_request_mode": "mapped_topic_v1", "lesson_questions": {"1": q1, "2": q2},
    })
    mock_laravel.find_lesson_query.return_value = {
        "binding_ref": "bind-100", "conversation_in": "conv-1", "state": "sending",
        "query_ref": "pending-2", "expected_cursor_version": 5,
    }
    mock_conv.history_turns = [
        (DIVIDE_PROMPT, "map"), (EXECUTION_PROMPT, "System Ready"),
        ("Start with First lesson" if wrong_prefix else q1, "Fields answer"), (q2, "Methods answer"),
    ]
    protocol = DirectLessonProtocol(mock_provider, mock_laravel)
    if wrong_prefix:
        with pytest.raises(ProviderError, match="history_conflict"):
            await protocol.process_lesson("case-100", "bind-100", "nb-100", "pdf", "steps", "conv-1", 2, 4)
        mock_laravel.record_combined_lesson_result.assert_not_awaited()
    else:
        result = await protocol.process_lesson("case-100", "bind-100", "nb-100", "pdf", "steps", "conv-1", 2, 4)
        assert result["status"] == "completed"
        assert mock_laravel.record_combined_lesson_result.call_args.args[1]["answer"] == "Methods answer"
    assert mock_conv.turns == []
    mock_laravel.claim_lesson_query.assert_not_awaited()


@pytest.mark.asyncio
async def test_mapped_topic_mode_cannot_fall_back_to_bare_continue(mock_conv, mock_provider, mock_laravel):
    mock_laravel.get_binding_by_ref.return_value.update({
        "lesson_request_mode": "mapped_topic_v1", "lesson_questions": {},
    })
    with pytest.raises(ProviderError, match="Mapped lesson question is unavailable"):
        await DirectLessonProtocol(mock_provider, mock_laravel).process_lesson(
            "case-100", "bind-100", "nb-100", "pdf", "steps", "conv-1", 2, 4,
        )
    assert mock_conv.turns == []
    mock_laravel.claim_lesson_query.assert_not_awaited()


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
