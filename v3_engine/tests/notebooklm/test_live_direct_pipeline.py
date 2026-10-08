"""
Live direct pipeline verification gate for Spec 016 (T019).

Marked with @pytest.mark.live. Requires provisioned session credentials
and optional live case references (V3_LIVE_DIRECT_CASE_REF, V3_LIVE_UNIT3_CASE_REF).
"""

import asyncio
import os
from pathlib import Path
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
from v3_engine.notebooklm.sdk_provider import SdkSessionProvider

SYSTEM_READY_ACKNOWLEDGEMENT = "System Ready"
START_FIRST_LESSON_INPUT = "Start with First lesson"
CONTINUE_LESSON_INPUT = "Continue"


UNIT3_PATH = "/home/moahmmed/Downloads/Documents/Math prep3/ilovepdf_split-range-math-prep3/Unit3.pdf"
UNIT3_EXPECTED_BYTES = 56937625


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
    assert execution.conversation_id == divide.conversation_id
    assert execution.text.strip() == SYSTEM_READY_ACKNOWLEDGEMENT

    for ordinal, work_item in enumerate(work_items, 1):
        question = START_FIRST_LESSON_INPUT if ordinal == 1 else CONTINUE_LESSON_INPUT
        reply = await session.ask_once(
            notebook_id, question, [pdf_source_id, steps_source_id], divide.conversation_id
        )
        assert reply.conversation_id == divide.conversation_id
        parsed = parse_combined_bundle(reply.text, expected_work_ordinal=ordinal)
        assert parsed.lecture_title == f"W{ordinal} | {work_item.title}"
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
