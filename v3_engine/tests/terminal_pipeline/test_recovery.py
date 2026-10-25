"""Tests for interruption recovery, reconciliation proofs, and anchoring (Spec 018 / T016)."""

from __future__ import annotations

import asyncio
import json
import uuid
from pathlib import Path
import pytest

from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.notebooklm.ports import (
    NotebookInventory,
    NotebookRef,
    SourceReceipt,
    TurnAnswer,
)
from v3_engine.notebooklm.protocol import COMBINED_RESPONSE_FORMAT_TEXT
from v3_engine.notebooklm.terminal_service import NotebookTerminalService
from v3_engine.terminal_pipeline.contracts import (
    ActivityOutcome,
    SafeCode,
)
from v3_engine.tests.terminal_pipeline.test_notebook_steps import (
    MockNotebookFiles,
    MockNotebookConversation,
    MockProviderSession,
    MockSessionProvider,
)


def test_reconcile_create_unique_vs_ambiguous(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    subj_id = str(uuid.uuid4())
    exact_title = f"V3 Mechanics {subj_id}"

    # 1. No matching notebook -> outcome is uncertain
    res = asyncio.run(
        service.reconcile(
            operation_type="create",
            title=exact_title,
        )
    )
    assert res["outcome"] == "uncertain"

    # 2. Exactly one matching notebook -> outcome is complete with notebook_id
    session.files.notebooks["nb-created-1"] = NotebookRef(id="nb-created-1", title=exact_title)
    res_one = asyncio.run(
        service.reconcile(
            operation_type="create",
            title=exact_title,
        )
    )
    assert res_one["outcome"] == "complete"
    assert res_one["notebook_id"] == "nb-created-1"

    # 3. Multiple matching notebooks -> blocked
    session.files.notebooks["nb-created-2"] = NotebookRef(id="nb-created-2", title=exact_title)
    res_multi = asyncio.run(
        service.reconcile(
            operation_type="create",
            title=exact_title,
        )
    )
    assert res_multi["outcome"] == "blocked"


def test_reconcile_format_settings(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    nb_id = "nb-test-fmt"
    # Format not set
    res_unset = asyncio.run(
        service.reconcile(
            operation_type="format",
            notebook_id=nb_id,
            format_text=COMBINED_RESPONSE_FORMAT_TEXT,
        )
    )
    assert res_unset["outcome"] == "uncertain"

    # Format matches COMBINED_RESPONSE_FORMAT_TEXT
    session.conversation.format_settings[nb_id] = COMBINED_RESPONSE_FORMAT_TEXT
    res_match = asyncio.run(
        service.reconcile(
            operation_type="format",
            notebook_id=nb_id,
            format_text=COMBINED_RESPONSE_FORMAT_TEXT,
        )
    )
    assert res_match["outcome"] == "complete"


def test_reconcile_chat_anchoring_and_refuse_uncertain_resend(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    nb_id = "nb-test-chat"
    conv_id = "conv-test-chat"
    session.conversation.conversation_id = conv_id

    # Seed history with Divide and Execution turns
    session.conversation.turns = [
        {"question": DIVIDE_PROMPT, "answer": "Map response"},
        {"question": EXECUTION_PROMPT, "answer": "System Ready"},
    ]

    # Reconciling Turn 3 ("Start with First lesson") when turn was NOT recorded
    res_missing = asyncio.run(
        service.reconcile_chat_turn(
            notebook_id=nb_id,
            conversation_id=conv_id,
            expected_question="Start with First lesson",
            expected_turn_index=2,  # 0-indexed: 3rd turn
        )
    )
    # Not in history -> delivery uncertain
    assert res_missing["outcome"] == "uncertain"

    # Invariant: Uncertain chat MUST NEVER be blind-resent in the same conversation!
    with pytest.raises(RuntimeError, match="Uncertain NotebookLM chat cannot be resent"):
        service.assert_safe_to_resend_chat(res_missing["outcome"], accept_duplicate_risk=True)

    # Now simulate the turn arrived in history before crash
    session.conversation.turns.append({
        "question": "Start with First lesson",
        "answer": "Lesson 1 complete answer",
    })

    res_recovered = asyncio.run(
        service.reconcile_chat_turn(
            notebook_id=nb_id,
            conversation_id=conv_id,
            expected_question="Start with First lesson",
            expected_turn_index=2,
        )
    )
    assert res_recovered["outcome"] == "complete"
    assert res_recovered["raw_answer"] == "Lesson 1 complete answer"


def test_complete_receipt_reuse_skips_remote_calls(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    run_dir = tmp_path / "run_test"
    run_dir.mkdir()
    receipt_file = run_dir / "turn_rcpt-001.json"
    receipt_file.write_text(
        json.dumps({
            "receipt_id": "rcpt-001",
            "question": "Continue",
            "raw_answer": "Retained answer text",
            "conversation_id": "conv-1",
            "status": "complete",
        }),
        encoding="utf-8",
    )

    # Calling check or restore receipt
    receipt = service.load_existing_receipt(receipt_file)
    assert receipt is not None
    assert receipt["status"] == "complete"
    assert receipt["raw_answer"] == "Retained answer text"
    # Zero adapter calls were made
    assert len(session.conversation.turns) == 0


def test_reconcile_upload_unique_vs_ambiguous(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))
    nb_id = "nb-upload-test"

    # 1. No matching source in notebook -> uncertain
    res_none = asyncio.run(
        service.reconcile(
            operation_type="upload",
            notebook_id=nb_id,
            title="doc1.pdf",
        )
    )
    assert res_none["outcome"] == "uncertain"

    # 2. Exactly one matching source -> complete with source_id
    session.files.sources[nb_id] = [
        SourceReceipt(source_id="src-101", title="doc1.pdf", status="ready")
    ]
    res_one = asyncio.run(
        service.reconcile(
            operation_type="upload",
            notebook_id=nb_id,
            title="doc1.pdf",
        )
    )
    assert res_one["outcome"] == "complete"
    assert res_one["source_id"] == "src-101"

    # 3. Multiple matching sources with same title -> blocked
    session.files.sources[nb_id].append(
        SourceReceipt(source_id="src-102", title="doc1.pdf", status="ready")
    )
    res_multi = asyncio.run(
        service.reconcile(
            operation_type="upload",
            notebook_id=nb_id,
            title="doc1.pdf",
        )
    )
    assert res_multi["outcome"] == "blocked"
    assert res_multi["error"] == "multiple_matching_sources"


@pytest.mark.parametrize('status', ['processing', 'error'])
def test_registered_source_is_not_proof_that_upload_completed(status):
    session = MockProviderSession()
    session.files.sources['notebook'] = [SourceReceipt('source', 'part.pdf', status)]
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))

    result = asyncio.run(service.reconcile('upload', notebook_id='notebook', title='part.pdf'))

    assert result == {'outcome': 'uncertain', 'source_id': 'source', 'source_status': status}


def test_reconcile_chat_full_prefix_anchoring(tmp_path: Path) -> None:
    session = MockProviderSession()
    service = NotebookTerminalService(session_provider=MockSessionProvider(session))
    nb_id = "nb-anchor-test"
    conv_id = "conv-anchor-test"

    prefix = [
        DIVIDE_PROMPT,
        EXECUTION_PROMPT,
        "Start with First lesson",
    ]

    session.conversation.turns = [
        {"question": DIVIDE_PROMPT, "answer": "Map response"},
        {"question": EXECUTION_PROMPT, "answer": "Ready"},
        {"question": "Start with First lesson", "answer": "Lesson 1"},
    ]

    # Reconciling Turn 3 ("Continue") when turn hasn't arrived yet
    res_pending = asyncio.run(
        service.reconcile_chat_turn(
            notebook_id=nb_id,
            conversation_id=conv_id,
            expected_question="Continue",
            expected_turn_index=3,
            expected_history_prefix=prefix,
        )
    )
    assert res_pending["outcome"] == "uncertain"

    # Turn 3 arrives
    session.conversation.turns.append({"question": "Continue", "answer": "Lesson 2"})
    res_ok = asyncio.run(
        service.reconcile_chat_turn(
            notebook_id=nb_id,
            conversation_id=conv_id,
            expected_question="Continue",
            expected_turn_index=3,
            expected_history_prefix=prefix,
        )
    )
    assert res_ok["outcome"] == "complete"
    assert res_ok["raw_answer"] == "Lesson 2"

    # Test truncated history: only 2 turns present when expecting turn index 3
    session.conversation.turns = session.conversation.turns[:2]
    res_trunc = asyncio.run(
        service.reconcile_chat_turn(
            notebook_id=nb_id,
            conversation_id=conv_id,
            expected_question="Continue",
            expected_turn_index=3,
            expected_history_prefix=prefix,
        )
    )
    assert res_trunc["outcome"] == "blocked"
    assert res_trunc["error"] == "history_truncated"

    # Test prefix mismatch: manual edit or unexpected question in prefix
    session.conversation.turns = [
        {"question": DIVIDE_PROMPT, "answer": "Map response"},
        {"question": "Unexpected question", "answer": "Wrong"},
        {"question": "Start with First lesson", "answer": "Lesson 1"},
        {"question": "Continue", "answer": "Lesson 2"},
    ]
    res_mismatch = asyncio.run(
        service.reconcile_chat_turn(
            notebook_id=nb_id,
            conversation_id=conv_id,
            expected_question="Continue",
            expected_turn_index=3,
            expected_history_prefix=prefix,
        )
    )
    assert res_mismatch["outcome"] == "blocked"
    assert res_mismatch["error"] == "history_prefix_mismatch"

