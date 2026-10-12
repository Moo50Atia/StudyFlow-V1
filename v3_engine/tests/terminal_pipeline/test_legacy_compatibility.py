"""Tests for legacy CLI compatibility and regression safety of run_multi_pdf_notebooklm.py (Spec 018 / T010)."""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path
import pytest

from scripts.v3.run_multi_pdf_notebooklm import (
    Run,
    local_sources,
    main,
)
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.notebooklm.ports import (
    ChatFormatSettings,
    NotebookInventory,
    NotebookRef,
    SourceReceipt,
    TurnAnswer,
)
from v3_engine.tests.terminal_pipeline.test_notebook_steps import (
    MockProviderSession,
    MockSessionProvider,
    SAMPLE_CANONICAL_DIVIDE,
    SAMPLE_LESSON_RAW_ANSWER_1,
    SAMPLE_LESSON_RAW_ANSWER_2,
)


def test_legacy_cli_check_only_flag(tmp_path: Path) -> None:
    # Prepare dummy files
    pdf_dir = tmp_path / "pdfs"
    pdf_dir.mkdir()
    pdf1 = pdf_dir / "01.pdf"
    pdf1.write_bytes(b"%PDF-1.4 test1")
    pdf2 = pdf_dir / "02.pdf"
    pdf2.write_bytes(b"%PDF-1.4 test2")

    steps_file = tmp_path / "steps.txt"
    steps_file.write_text("dummy framework steps", encoding="utf-8")
    output_dir = tmp_path / "output"

    cmd = [
        sys.executable,
        "scripts/v3/run_multi_pdf_notebooklm.py",
        "--pdf-dir", str(pdf_dir),
        "--steps", str(steps_file),
        "--expected-pdfs", "2",
        "--output-dir", str(output_dir),
        "--account-check-notebook-id", "nb-proof-123",
        "--check-only",
    ]
    res = subprocess.run(cmd, capture_output=True, text=True, cwd=str(Path.cwd()))
    assert res.returncode == 0
    assert "inputs_verified 2 PDFs plus steps.txt" in res.stdout


def test_legacy_run_facade_and_checkpoint_schema(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    pdf_dir = tmp_path / "pdfs"
    pdf_dir.mkdir()
    pdf1 = pdf_dir / "01.pdf"
    pdf1.write_bytes(b"%PDF-1.4 test1")
    pdf2 = pdf_dir / "02.pdf"
    pdf2.write_bytes(b"%PDF-1.4 test2")

    steps_file = tmp_path / "steps.txt"
    steps_file.write_text("dummy framework steps", encoding="utf-8")
    output_dir = tmp_path / "output"
    output_dir.mkdir()

    sources = local_sources(pdf_dir, steps_file, expected_pdfs=2)
    assert len(sources) == 3

    import argparse
    args = argparse.Namespace(
        pdf_dir=pdf_dir,
        steps=steps_file,
        expected_pdfs=2,
        output_dir=output_dir,
        account_check_notebook_id="nb-proof-123",
        title="V3 Mechanics 2 PDFs",
        source_timeout=30,
        check_only=False,
        capture_raw_output=True,
    )

    mock_session = MockProviderSession()
    # Proof notebook must exist in account
    mock_session.files.notebooks["nb-proof-123"] = NotebookRef(id="nb-proof-123", title="Proof Notebook")
    mock_session.conversation.canned_answers[DIVIDE_PROMPT] = SAMPLE_CANONICAL_DIVIDE
    mock_session.conversation.canned_answers[EXECUTION_PROMPT] = "System Ready"
    mock_session.conversation.canned_answers["Start with First lesson"] = SAMPLE_LESSON_RAW_ANSWER_1
    mock_session.conversation.canned_answers["Continue"] = SAMPLE_LESSON_RAW_ANSWER_2

    # Monkeypatch SdkSessionProvider in run_multi_pdf_notebooklm
    monkeypatch.setattr(
        "scripts.v3.run_multi_pdf_notebooklm.SdkSessionProvider",
        lambda **kw: MockSessionProvider(mock_session),
    )

    run = Run(args, sources)
    import asyncio
    asyncio.run(run.execute())

    checkpoint_file = output_dir / "checkpoint.json"
    assert checkpoint_file.exists()
    checkpoint_data = json.loads(checkpoint_file.read_text(encoding="utf-8"))

    assert checkpoint_data["kind"] == "notebooklm_multi_pdf_run"
    assert checkpoint_data["status"] == "complete"
    assert len(checkpoint_data["lessons"]) == 2
    assert checkpoint_data["divide"]["work_count"] == 2

    # Verify lesson handoff files exist and match legacy schema
    h1_file = output_dir / "lesson_handoffs" / "lesson-001.json"
    h2_file = output_dir / "lesson_handoffs" / "lesson-002.json"
    assert h1_file.exists()
    assert h2_file.exists()

    h1_data = json.loads(h1_file.read_text(encoding="utf-8"))
    assert h1_data["kind"] == "notebooklm_multi_pdf_lesson_raw_handoff"
    assert h1_data["lesson_ordinal"] == 1
    assert len(h1_data["scenes"]) == 2


def test_legacy_resume_without_resending_turns(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    pdf_dir = tmp_path / "pdfs"
    pdf_dir.mkdir()
    pdf1 = pdf_dir / "01.pdf"
    pdf1.write_bytes(b"%PDF-1.4 test1")
    pdf2 = pdf_dir / "02.pdf"
    pdf2.write_bytes(b"%PDF-1.4 test2")

    steps_file = tmp_path / "steps.txt"
    steps_file.write_text("dummy framework steps", encoding="utf-8")
    output_dir = tmp_path / "output"
    output_dir.mkdir()

    sources = local_sources(pdf_dir, steps_file, expected_pdfs=2)

    import argparse
    args = argparse.Namespace(
        pdf_dir=pdf_dir,
        steps=steps_file,
        expected_pdfs=2,
        output_dir=output_dir,
        account_check_notebook_id="nb-proof-123",
        title="V3 Mechanics 2 PDFs",
        source_timeout=30,
        check_only=False,
        capture_raw_output=True,
    )

    # Pre-populate checkpoint with completed divide and execution, and lesson 1 saved
    checkpoint_data = {
        "schema_version": 1,
        "kind": "notebooklm_multi_pdf_run",
        "run_id": "test-resumed-run-id",
        "account_proof_notebook": "nb-proof-123",
        "notebook_title": "V3 Mechanics 2 PDFs test-res",
        "notebook_id": "nb-1",
        "sources": [
            {**s, "source_id": f"src-{i+1}", "status": "ready"} for i, s in enumerate(sources)
        ],
        "conversation_id": "conv-resumed",
        "divide": {
            "question": DIVIDE_PROMPT,
            "raw_answer": SAMPLE_CANONICAL_DIVIDE,
            "nodes": [
                {"kind": "lesson", "title": "Intro", "work_ordinal": 1},
                {"kind": "lesson", "title": "Navier", "work_ordinal": 2},
            ],
            "work_count": 2,
            "map_parse_mode": "strict",
        },
        "execution": {
            "question": EXECUTION_PROMPT,
            "raw_answer": "System Ready",
        },
        "lessons": [
            {
                "lesson_ordinal": 1,
                "mapped_title": "Intro",
                "question": "Start with First lesson",
                "raw_answer": SAMPLE_LESSON_RAW_ANSWER_1,
                "handoff_path": "lesson_handoffs/lesson-001.json",
                "scene_count": 2,
            }
        ],
        "status": "lesson_saved",
        "capture_raw_output": True,
    }
    (output_dir / "checkpoint.json").write_text(json.dumps(checkpoint_data), encoding="utf-8")
    (output_dir / "lesson_handoffs").mkdir()
    (output_dir / "lesson_handoffs" / "lesson-001.json").write_text("{}", encoding="utf-8")

    mock_session = MockProviderSession()
    mock_session.files.notebooks["nb-proof-123"] = NotebookRef(id="nb-proof-123", title="Proof Notebook")
    mock_session.files.notebooks["nb-1"] = NotebookRef(id="nb-1", title="V3 Mechanics 2 PDFs test-res")
    for s in checkpoint_data["sources"]:
        mock_session.files.sources.setdefault("nb-1", []).append(
            SourceReceipt(
                source_id=s["source_id"],
                title=s["title"],
                status="ready",
                mime_type="application/pdf" if s["role"] == "educational_pdf" else "text/plain",
            )
        )
    mock_session.conversation.conversation_id = "conv-resumed"
    # Seed history to match: divide, execution, lesson 1
    mock_session.conversation.turns = [
        {"question": DIVIDE_PROMPT, "answer": SAMPLE_CANONICAL_DIVIDE},
        {"question": EXECUTION_PROMPT, "answer": "System Ready"},
        {"question": "Start with First lesson", "answer": SAMPLE_LESSON_RAW_ANSWER_1},
    ]
    mock_session.conversation.canned_answers["Continue"] = SAMPLE_LESSON_RAW_ANSWER_2

    monkeypatch.setattr(
        "scripts.v3.run_multi_pdf_notebooklm.SdkSessionProvider",
        lambda **kw: MockSessionProvider(mock_session),
    )

    run = Run(args, sources)
    import asyncio
    asyncio.run(run.execute())

    # Only 1 additional turn ("Continue" for Lesson 2) should have been asked!
    turns_asked = [t["question"] for t in mock_session.conversation.turns]
    assert turns_asked == [
        DIVIDE_PROMPT,
        EXECUTION_PROMPT,
        "Start with First lesson",
        "Continue",
    ]
    assert (output_dir / "lesson_handoffs" / "lesson-002.json").exists()
