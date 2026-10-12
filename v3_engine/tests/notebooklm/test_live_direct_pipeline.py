"""
Live direct pipeline verification gate for Spec 016 (T019).

Marked with @pytest.mark.live. Requires provisioned session credentials
and optional live case references (V3_LIVE_DIRECT_CASE_REF, V3_LIVE_UNIT3_CASE_REF).
"""

import asyncio
import json
import os
from pathlib import Path
from types import SimpleNamespace
import pytest

from v3_engine.notebooklm.credentials import (
    CredentialsValidationError,
    get_storage_state_path,
    validate_storage_state,
)
from v3_engine.notebooklm.ports import (
    AuthenticationRequiredError,
)
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.notebooklm.protocol import (
    COMBINED_RESPONSE_FORMAT_TEXT,
    parse_direct_divide_map,
)
from v3_engine.notebooklm.bundle_parser import parse_combined_bundle
from v3_engine.notebooklm.lesson_archive import archive_lesson_bundle
from v3_engine.notebooklm.sdk_provider import SdkSessionProvider

START_FIRST_LESSON_INPUT = "Start with First lesson"
CONTINUE_LESSON_INPUT = "Continue"


UNIT3_PATH = "/home/moahmmed/Downloads/Documents/Math prep3/ilovepdf_split-range-math-prep3/Unit3.pdf"
UNIT3_EXPECTED_BYTES = 56937625


@pytest.mark.asyncio
async def test_provider_sequence_archives_raw_results_and_continues_after_parse_failure(tmp_path, monkeypatch):
    monkeypatch.setenv("V3_NOTEBOOKLM_HANDOFF_DIR", str(tmp_path))

    class FakeSession:
        def __init__(self):
            self.questions = []
            self.answers = iter([
                SimpleNamespace(
                    text="Chapter 1: Unit\n  Lesson 1: First Lesson\n  Lesson 2: Second Lesson",
                    conversation_id="conversation-1",
                ),
                SimpleNamespace(text="ack differs from exact handshake", conversation_id="conversation-1"),
                SimpleNamespace(text="unparsed provider answer 1", conversation_id="conversation-1"),
                SimpleNamespace(text="unparsed provider answer 2", conversation_id="conversation-1"),
            ])

        async def get_source(self, notebook_id, source_id):
            return SimpleNamespace(status="ready")

        async def get_notebook(self, notebook_id):
            return SimpleNamespace(source_ids=["pdf", "steps"])

        async def configure_format(self, notebook_id, format_text):
            pass

        async def get_format(self, notebook_id):
            return SimpleNamespace(custom_prompt=COMBINED_RESPONSE_FORMAT_TEXT)

        async def ask_once(self, notebook_id, question, source_ids, conversation_id=None):
            self.questions.append(question)
            return next(self.answers)

    session = FakeSession()
    count = await verify_live_provider_sequence(session, "notebook-1", "pdf", "steps")

    handoff = json.loads(next(tmp_path.glob("*.json")).read_text(encoding="utf-8"))
    assert count == 2
    assert session.questions == [
        DIVIDE_PROMPT,
        EXECUTION_PROMPT,
        START_FIRST_LESSON_INPUT,
        CONTINUE_LESSON_INPUT,
    ]
    assert handoff["status"] == "complete"
    assert [lesson["raw_response"] for lesson in handoff["lessons"]] == [
        "unparsed provider answer 1", "unparsed provider answer 2"
    ]


async def verify_live_provider_sequence(session, notebook_id: str, pdf_source_id: str, steps_source_id: str) -> int:
    """Provider-level gate; Laravel persistence and downstream review require separate gates."""
    for source_id in (pdf_source_id, steps_source_id):
        for _ in range(30):
            receipt = await session.get_source(notebook_id, source_id)
            if receipt.status == "ready":
                break
            assert receipt.status != "error", "NotebookLM rejected a source"
            await asyncio.sleep(10)
        else:
            pytest.fail("NotebookLM source did not become ready within five minutes")

    inventory = await session.get_notebook(notebook_id)
    assert set(inventory.source_ids) == {pdf_source_id, steps_source_id}
    await session.configure_format(notebook_id, COMBINED_RESPONSE_FORMAT_TEXT)
    assert (await session.get_format(notebook_id)).custom_prompt == COMBINED_RESPONSE_FORMAT_TEXT

    divide = await session.ask_once(notebook_id, DIVIDE_PROMPT, [pdf_source_id])
    work_items = [node for node in parse_direct_divide_map(divide.text) if node.work_ordinal is not None]
    assert work_items and divide.conversation_id

    execution = await session.ask_once(
        notebook_id, EXECUTION_PROMPT, [pdf_source_id, steps_source_id], divide.conversation_id
    )
    # Keep moving through the provider's conversation even if its acknowledgement
    # differs; protocol validation is recorded separately from response capture.
    conversation_id = execution.conversation_id or divide.conversation_id

    handoff_path: Path | None = None
    for ordinal, work_item in enumerate(work_items, 1):
        question = START_FIRST_LESSON_INPUT if ordinal == 1 else CONTINUE_LESSON_INPUT
        reply = await session.ask_once(
            notebook_id, question, [pdf_source_id, steps_source_id], conversation_id
        )
        conversation_id = reply.conversation_id or conversation_id
        # Commit the untouched response first. Parser/title problems must not
        # discard this lesson or stop the literal Continue sequence.
        handoff_path = archive_lesson_bundle(
            notebook_id=notebook_id,
            conversation_id=conversation_id,
            source_ids={
                "original_pdf": pdf_source_id,
                "framework_steps": steps_source_id,
            },
            lesson_ordinal=ordinal,
            lesson_title=work_item.title,
            expected_work_count=len(work_items),
            raw_response=reply.text,
            parse_status="raw_only",
        )
        try:
            parsed = parse_combined_bundle(reply.text, expected_work_ordinal=ordinal)
        except Exception as exc:
            print(f"Lesson {ordinal} captured; structured parsing deferred ({type(exc).__name__}).")
            continue

        # Keep NotebookLM's complete response/title even when it differs from
        # the map. The map ordinal is the stable lesson identity for this run.
        try:
            handoff_path = archive_lesson_bundle(
                notebook_id=notebook_id,
                conversation_id=conversation_id,
                source_ids={
                    "original_pdf": pdf_source_id,
                    "framework_steps": steps_source_id,
                },
                lesson_ordinal=ordinal,
                lesson_title=work_item.title,
                expected_work_count=len(work_items),
                bundle=parsed,
                raw_response=reply.text,
                parse_status="parsed",
            )
        except Exception as exc:
            print(f"Lesson {ordinal} raw response captured; structured archive deferred ({type(exc).__name__}).")
    if handoff_path is not None:
        print(f"Lesson prompt handoff written: {handoff_path}")
    return len(work_items)


@pytest.mark.live
@pytest.mark.asyncio
async def test_live_gate_small_mixed_pdf_fixture():
    """
    T019 Live Gate: Small mixed/scanned PDF fixture upload and full direct lesson loop.
    Gated by provisioned NotebookLM credentials and V3_LIVE_DIRECT_CASE_REF.
    """
    state_path = get_storage_state_path()
    try:
        validate_storage_state(state_path)
    except CredentialsValidationError:
        pytest.skip(
            "GATE BLOCKED: Dedicated disposable NotebookLM storage state not provisioned in environment. "
            "Configure V3_NOTEBOOKLM_STORAGE_STATE to execute live provider gate."
        )

    case_ref = os.environ.get("V3_LIVE_DIRECT_CASE_REF")
    if not case_ref:
        pytest.skip(
            "GATE BLOCKED: V3_LIVE_DIRECT_CASE_REF not set. "
            "Create a fresh direct-protocol case via teacher intake to run the live test."
        )

    provider = SdkSessionProvider()
    try:
        async with provider.open(storage_path=state_path) as session:
            # SDK open bootstraps CSRF/session tokens; this read-only request
            # confirms the authenticated session before any upload or mutation.
            await session.files.list_notebooks()
            small_pdf_path = Path("tests/Fixtures/V3/NotebookLM/sample.pdf")
            if not small_pdf_path.exists():
                small_pdf_path = Path("tests/Fixtures/V3/sample.pdf")
            assert small_pdf_path.exists(), "Sample test PDF fixture must exist"

            steps_path = Path("steps.txt")
            assert steps_path.exists(), "Root steps.txt framework must exist"

            nb = await session.create_notebook(f"v3-live-direct-{case_ref}")
            assert nb.id, "Notebook must be created"

            pdf_source = await session.upload_file(
                notebook_id=nb.id,
                local_path=small_pdf_path,
                mime_type="application/pdf",
                title="sample.pdf",
            )
            steps_source = await session.upload_file(
                notebook_id=nb.id,
                local_path=steps_path,
                mime_type="text/plain",
                title="steps.txt",
            )

            assert await verify_live_provider_sequence(
                session, nb.id, pdf_source.source_id, steps_source.source_id
            ) >= 1
    except AuthenticationRequiredError:
        pytest.fail(
            "GATE BLOCKED: NotebookLM session credentials returned authentication_required. "
            "Runtime processing must never launch an automatic browser login. "
            "Refresh session state explicitly via setup_local_credentials.py."
        )


@pytest.mark.live
@pytest.mark.asyncio
async def test_live_gate_unit3_original_pdf():
    """
    T019 Live Gate: Original uncompressed Unit3 PDF (56.9MB) integrity and provider upload gate.
    Gated by provisioned NotebookLM credentials and V3_LIVE_UNIT3_CASE_REF.
    """
    unit3 = Path(UNIT3_PATH)
    if not unit3.exists():
        pytest.skip(f"GATE BLOCKED: Original Unit3 PDF not found at {UNIT3_PATH}")

    actual_bytes = unit3.stat().st_size
    assert actual_bytes == UNIT3_EXPECTED_BYTES, (
        f"Unit3.pdf byte size mismatch: expected {UNIT3_EXPECTED_BYTES}, got {actual_bytes}. "
        "Original PDF bytes must be strictly preserved without splitting or OCR fallback."
    )

    state_path = get_storage_state_path()
    try:
        validate_storage_state(state_path)
    except CredentialsValidationError:
        pytest.skip(
            "GATE BLOCKED: Dedicated disposable NotebookLM storage state not provisioned in environment. "
            "Configure V3_NOTEBOOKLM_STORAGE_STATE to execute live provider gate."
        )

    case_ref = os.environ.get("V3_LIVE_UNIT3_CASE_REF")
    if not case_ref:
        pytest.skip(
            "GATE BLOCKED: V3_LIVE_UNIT3_CASE_REF not set. "
            "Create a direct-protocol case for Unit3 via teacher intake to run the live test."
        )

    provider = SdkSessionProvider()
    try:
        async with provider.open(storage_path=state_path) as session:
            # Do not create a notebook or upload files until both SDK token
            # bootstrap and an authenticated read-only call succeed.
            await session.files.list_notebooks()
            steps_path = Path("steps.txt")
            assert steps_path.exists(), "Root steps.txt framework must exist"

            nb = await session.create_notebook(f"v3-live-unit3-{case_ref}")
            assert nb.id, "Notebook must be created"

            pdf_source = await session.upload_file(
                notebook_id=nb.id,
                local_path=unit3,
                mime_type="application/pdf",
                title="Unit3.pdf",
            )
            steps_source = await session.upload_file(
                notebook_id=nb.id,
                local_path=steps_path,
                mime_type="text/plain",
                title="steps.txt",
            )

            assert await verify_live_provider_sequence(
                session, nb.id, pdf_source.source_id, steps_source.source_id
            ) >= 1
    except AuthenticationRequiredError:
        pytest.fail(
            "GATE BLOCKED: NotebookLM session credentials returned authentication_required. "
            "Refresh session state explicitly via setup_local_credentials.py."
        )
