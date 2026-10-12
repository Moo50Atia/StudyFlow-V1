"""Tests for multi-PDF NotebookLM execution steps, scoping, turn sequence, and capture (Spec 018 / T010)."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
from typing import Any, Sequence
import pytest

from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.notebooklm.ports import (
    ChatFormatSettings,
    NotebookInventory,
    NotebookRef,
    SourceReceipt,
    TurnAnswer,
)
from v3_engine.notebooklm.protocol import COMBINED_RESPONSE_FORMAT_TEXT
from v3_engine.notebooklm.terminal_service import (
    NotebookTerminalService,
)


class MockNotebookFiles:
    def __init__(self, owner_role: str = "owner"):
        self.owner_role = owner_role
        self.notebooks: dict[str, NotebookRef] = {}
        self.sources: dict[str, list[SourceReceipt]] = {}
        self.upload_calls: list[dict[str, Any]] = []

    async def create_notebook(self, title: str) -> NotebookRef:
        nb_id = f"nb-{len(self.notebooks) + 1}"
        ref = NotebookRef(id=nb_id, title=title)
        self.notebooks[nb_id] = ref
        self.sources[nb_id] = []
        return ref

    async def get_notebook(self, id: str) -> NotebookInventory:
        source_ids = [s.source_id for s in self.sources.get(id, [])]
        title = self.notebooks[id].title if id in self.notebooks else "Notebook"
        return NotebookInventory(id=id, title=title, source_ids=source_ids)

    async def list_notebooks(self) -> list[NotebookRef]:
        return list(self.notebooks.values())

    async def upload_file(
        self, notebook_id: str, local_path: Path, mime_type: str, title: str
    ) -> SourceReceipt:
        src_id = f"src-{len(self.upload_calls) + 1}"
        self.upload_calls.append({
            "notebook_id": notebook_id,
            "path": local_path,
            "mime_type": mime_type,
            "title": title,
            "source_id": src_id,
        })
        receipt = SourceReceipt(
            source_id=src_id,
            title=title,
            status="ready",
            mime_type=mime_type,
        )
        self.sources.setdefault(notebook_id, []).append(receipt)
        return receipt

    async def get_source(self, notebook_id: str, source_id: str) -> SourceReceipt:
        for s in self.sources.get(notebook_id, []):
            if s.source_id == source_id:
                return s
        raise KeyError(f"Source not found: {source_id}")

    async def list_sources(self, notebook_id: str) -> list[SourceReceipt]:
        return list(self.sources.get(notebook_id, []))

    async def verify_owner(self, proof_notebook_id: str) -> str:
        return self.owner_role


class MockNotebookConversation:
    def __init__(self):
        self.format_settings: dict[str, str] = {}
        self.turns: list[dict[str, Any]] = []
        self.canned_answers: dict[str, str] = {}
        self.conversation_id: str = "conv-terminal-test"
        self.turn_hooks: list[Any] = []  # callbacks called when ask_once is invoked

    async def configure_format(self, notebook_id: str, format_text: str) -> None:
        self.format_settings[notebook_id] = format_text

    async def get_format(self, notebook_id: str) -> ChatFormatSettings:
        fmt = self.format_settings.get(notebook_id, "")
        return ChatFormatSettings(goal="Custom", response_length="Default", custom_prompt=fmt)

    async def ask_once(
        self,
        notebook_id: str,
        question: str,
        source_ids: Sequence[str],
        conversation_id: str | None = None,
    ) -> TurnAnswer:
        for hook in self.turn_hooks:
            hook(question, source_ids)

        turn_num = len(self.turns) + 1
        answer_text = self.canned_answers.get(question, f"Answer for turn {turn_num}")
        self.turns.append({
            "notebook_id": notebook_id,
            "question": question,
            "source_ids": list(source_ids),
            "conversation_id": conversation_id or self.conversation_id,
            "turn_number": turn_num,
            "answer": answer_text,
        })
        return TurnAnswer(
            text=answer_text,
            conversation_id=self.conversation_id,
            turn_number=turn_num,
        )

    async def history(
        self, notebook_id: str, conversation_id: str, limit: int = 100
    ) -> list[tuple[str, str]]:
        return [(t["question"], t["answer"]) for t in self.turns]

    async def current_conversation_id(self, notebook_id: str) -> str | None:
        return self.conversation_id if self.turns else None


class MockProviderSession:
    def __init__(self, owner_role: str = "owner"):
        self.files = MockNotebookFiles(owner_role)
        self.conversation = MockNotebookConversation()
        self.closed = False

    async def close(self) -> None:
        self.closed = True


class MockSessionProvider:
    def __init__(self, session: MockProviderSession):
        self.session = session

    def open(self, profile_ref=None, storage_path=None):
        sess = self.session

        class _Ctx:
            async def __aenter__(self):
                return sess

            async def __aexit__(self, exc_type, exc, tb):
                await sess.close()

        return _Ctx()


SAMPLE_CANONICAL_DIVIDE = """Chapter 1: Fluid Mechanics
├── Lesson 1: Introduction to Viscosity
└── Lesson 2: Navier-Stokes Fundamentals
"""

SAMPLE_LOOSE_DIVIDE = """Here is the breakdown of the course:
* Lesson 1 - Intro to Fluid Statics
* Lesson 2 - Pressure Distribution
* Lesson 3 - Buoyancy and Floatation
"""

SAMPLE_EMPTY_DIVIDE = """I have reviewed the documents and am ready to assist you."""

SAMPLE_LESSON_RAW_ANSWER_1 = """=== FIRST ===
Lecture: W1 Introduction to Viscosity
Sec1: Viscosity Concept
Sec2: Shear Stress
=== SECOND ===
Placeholder Sec1
Perspective Map Sec1
=== THIRD ===
Lec 1 (Sec 1): "Scene Type: 2D Simulation
Viscosity flow simulation prompt text."
Lec 1 (Sec 2): "Scene Type: 3D Model
Shear stress 3D diagram prompt text."
=== END ===
✅ Lesson [1] Completed. Awaiting 'Continue' for the next lesson.
"""

SAMPLE_LESSON_RAW_ANSWER_2 = """=== FIRST ===
Lecture: W2 Navier-Stokes Fundamentals
Sec1: Governing Equations
=== SECOND ===
Placeholder Sec1
Perspective Map Sec1
=== THIRD ===
Lec 2 (Sec 1): "Scene Type: Interactive Equation
Interactive Navier-Stokes equation terms breakdown."
=== END ===
✅ Lesson [2] Completed. Awaiting 'Continue' for the next lesson.
"""


def test_source_upload_and_alias_tolerance(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    # Create dummy pdfs and steps file
    pdf1 = tmp_path / "lecture_01.pdf"
    pdf1.write_bytes(b"%PDF-1.4 test 1")
    pdf2 = tmp_path / "lecture_02.pdf"
    pdf2.write_bytes(b"%PDF-1.4 test 2")
    steps = tmp_path / "steps.txt"
    steps.write_text("Framework steps", encoding="utf-8")

    # Upload sources
    import asyncio
    nb = asyncio.run(service.create_notebook("Mechanics Run"))

    r1 = asyncio.run(service.upload_source(nb.id, pdf1, "application/pdf", "Lecture 01 - lecture_01.pdf"))
    r2 = asyncio.run(service.upload_source(nb.id, pdf2, "application/pdf", "Lecture 02 - lecture_02.pdf"))
    r3 = asyncio.run(service.upload_source(nb.id, steps, "text/plain", "steps.txt"))

    assert len(session.files.upload_calls) == 3
    assert r1.status == "ready"
    assert r2.status == "ready"
    assert r3.status == "ready"

    # Verify poll sources lists all receipts
    sources = asyncio.run(service.poll_sources(nb.id))
    assert len(sources) == 3
    assert {s.source_id for s in sources} == {r1.source_id, r2.source_id, r3.source_id}


def test_format_configuration_and_divide_scoping(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    import asyncio
    nb = asyncio.run(service.create_notebook("Mechanics Run"))

    # Configure format
    asyncio.run(service.configure_chat(nb.id, COMBINED_RESPONSE_FORMAT_TEXT))
    assert session.conversation.format_settings[nb.id] == COMBINED_RESPONSE_FORMAT_TEXT

    # Divide prompt scoping: MUST only include educational PDFs, NOT steps.txt
    pdf_source_ids = ["src-pdf-1", "src-pdf-2"]
    framework_source_id = "src-steps"

    session.conversation.canned_answers[DIVIDE_PROMPT] = SAMPLE_CANONICAL_DIVIDE

    turn_ans = asyncio.run(
        service.send_turn(
            notebook_id=nb.id,
            question=DIVIDE_PROMPT,
            source_ids=pdf_source_ids,  # Excludes framework
        )
    )

    assert len(session.conversation.turns) == 1
    assert session.conversation.turns[0]["question"] == DIVIDE_PROMPT
    assert session.conversation.turns[0]["source_ids"] == pdf_source_ids
    assert framework_source_id not in session.conversation.turns[0]["source_ids"]

    # Test capture_turn for divide in strict mode
    parsed_divide = service.capture_turn(
        turn_kind="divide",
        raw_answer=turn_ans.text,
        capture_raw=True,
    )
    assert parsed_divide["work_count"] == 2
    assert parsed_divide["map_parse_mode"] == "strict"
    assert len(parsed_divide["nodes"]) == 3
    assert sum(n["work_ordinal"] is not None for n in parsed_divide["nodes"]) == 2


def test_divide_fallback_and_empty_map_block(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    # Test fallback parsing on noncanonical format with capture_raw=True
    parsed_loose = service.capture_turn(
        turn_kind="divide",
        raw_answer=SAMPLE_LOOSE_DIVIDE,
        capture_raw=True,
    )
    assert parsed_loose["work_count"] == 3
    assert parsed_loose["map_parse_mode"] == "loose"
    assert "map_parse_warning" in parsed_loose

    # Test empty / unreadable map blocks execution (0 work count)
    with pytest.raises(RuntimeError, match="no identifiable work items"):
        service.capture_turn(
            turn_kind="divide",
            raw_answer=SAMPLE_EMPTY_DIVIDE,
            capture_raw=True,
        )


def test_execution_turn_scoping_and_warning(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    import asyncio
    nb = asyncio.run(service.create_notebook("Mechanics Run"))
    all_source_ids = ["src-pdf-1", "src-pdf-2", "src-steps"]

    # Execution response not exactly "System Ready"
    session.conversation.canned_answers[EXECUTION_PROMPT] = "Ready to proceed."

    turn_ans = asyncio.run(
        service.send_turn(
            notebook_id=nb.id,
            question=EXECUTION_PROMPT,
            source_ids=all_source_ids,  # Includes framework
        )
    )

    assert session.conversation.turns[0]["source_ids"] == all_source_ids

    # Capture execution turn with capture_raw=True records warning instead of failing
    parsed_exec = service.capture_turn(
        turn_kind="execution",
        raw_answer=turn_ans.text,
        capture_raw=True,
    )
    assert parsed_exec["status"] == "execution_ready"
    assert "output_warning" in parsed_exec

    # With capture_raw=False, it should raise
    with pytest.raises(RuntimeError, match="System Ready"):
        service.capture_turn(
            turn_kind="execution",
            raw_answer=turn_ans.text,
            capture_raw=False,
        )


def test_lesson_save_before_next_send_and_arbitrary_scenes(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    import asyncio
    nb = asyncio.run(service.create_notebook("Mechanics Run"))
    all_source_ids = ["src-pdf-1", "src-pdf-2", "src-steps"]
    handoff_dir = tmp_path / "lesson_handoffs"
    handoff_dir.mkdir(parents=True)

    session.conversation.canned_answers["Start with First lesson"] = SAMPLE_LESSON_RAW_ANSWER_1
    session.conversation.canned_answers["Continue"] = SAMPLE_LESSON_RAW_ANSWER_2

    # Verification hook: When "Continue" is asked, lesson-001.json MUST already exist on disk!
    checked_save_before_next = False

    def hook(question: str, source_ids: Sequence[str]) -> None:
        nonlocal checked_save_before_next
        if question == "Continue":
            lesson_001_file = handoff_dir / "lesson-001.json"
            assert lesson_001_file.exists(), "lesson-001.json MUST be persisted before asking Continue!"
            data = json.loads(lesson_001_file.read_text(encoding="utf-8"))
            assert data["lesson_ordinal"] == 1
            assert len(data["scenes"]) == 2  # Not hardcoded to 3!
            checked_save_before_next = True

    session.conversation.turn_hooks.append(hook)

    # 1. Turn 1: "Start with First lesson"
    ans1 = asyncio.run(
        service.send_turn(
            notebook_id=nb.id,
            question="Start with First lesson",
            source_ids=all_source_ids,
        )
    )
    # Save lesson 1
    h1 = service.capture_turn(
        turn_kind="lesson",
        raw_answer=ans1.text,
        lesson_ordinal=1,
        mapped_title="Intro to Viscosity",
        output_dir=handoff_dir,
        notebook_id=nb.id,
        pdf_source_ids=["src-pdf-1", "src-pdf-2"],
        framework_source_id="src-steps",
        capture_raw=True,
    )
    assert (handoff_dir / "lesson-001.json").exists()

    # 2. Turn 2: "Continue"
    ans2 = asyncio.run(
        service.send_turn(
            notebook_id=nb.id,
            question="Continue",
            source_ids=all_source_ids,
        )
    )
    h2 = service.capture_turn(
        turn_kind="lesson",
        raw_answer=ans2.text,
        lesson_ordinal=2,
        mapped_title="Navier-Stokes Fundamentals",
        output_dir=handoff_dir,
        notebook_id=nb.id,
        pdf_source_ids=["src-pdf-1", "src-pdf-2"],
        framework_source_id="src-steps",
        capture_raw=True,
    )
    assert (handoff_dir / "lesson-002.json").exists()
    assert checked_save_before_next

    # Check variable scene count (Lesson 1 had 2 scenes, Lesson 2 has 1 scene)
    assert h1["scene_count"] == 2
    assert h2["scene_count"] == 1

    # Freeze handoffs
    frozen = service.freeze_handoffs(handoff_dir=handoff_dir, expected_lesson_count=2)
    assert frozen["status"] == "frozen"
    assert len(frozen["handoffs"]) == 2
