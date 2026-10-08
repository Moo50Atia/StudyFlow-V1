from __future__ import annotations

import hashlib
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock
import pytest

from v3_engine.notebooklm.ports import (
    NotebookFiles,
    NotebookConversation,
    ProviderSession,
    SessionProvider,
    NotebookRef,
    NotebookInventory,
    SourceReceipt,
    TurnAnswer,
    ChatFormatSettings,
    ProviderError,
)
from v3_engine.notebooklm.protocol import DirectSourceProtocol


class MockFiles(NotebookFiles):
    def __init__(self):
        self.created_notebooks = []
        self.uploaded_files = []
        self.sources = {}
        self.poll_counts = {}

    async def create_notebook(self, title: str) -> NotebookRef:
        nb_id = f"nb-{len(self.created_notebooks) + 1}"
        ref = NotebookRef(id=nb_id, title=title)
        self.created_notebooks.append(ref)
        return ref

    async def get_notebook(self, id: str) -> NotebookInventory:
        for nb in self.created_notebooks:
            if nb.id == id:
                return NotebookInventory(id=nb.id, title=nb.title, source_ids=list(self.sources))
        raise ProviderError("not_found", f"Notebook {id} not found")

    async def list_notebooks(self) -> list[NotebookInventory]:
        return [NotebookInventory(id=nb.id, title=nb.title, sources=[]) for nb in self.created_notebooks]

    async def upload_file(self, notebook_id: str, local_path: Path, mime_type: str, title: str) -> SourceReceipt:
        src_id = f"src-{len(self.uploaded_files) + 1}"
        self.uploaded_files.append((notebook_id, local_path, mime_type, title))
        receipt = SourceReceipt(
            source_id=src_id,
            title=title,
            status="processing",
            mime_type=mime_type,
        )
        self.sources[src_id] = receipt
        self.poll_counts[src_id] = 0
        return receipt

    async def get_source(self, notebook_id: str, source_id: str) -> SourceReceipt:
        if source_id not in self.sources:
            raise ProviderError("not_found", f"Source {source_id} not found")
        self.poll_counts[source_id] += 1
        current = self.sources[source_id]
        # Simulate turning ready on 2nd poll
        if self.poll_counts[source_id] >= 2:
            current = SourceReceipt(
                source_id=current.source_id,
                title=current.title,
                status="ready",
                mime_type=current.mime_type,
            )
            self.sources[source_id] = current
        return current


class MockConversation(NotebookConversation):
    def __init__(self):
        self.configured_formats = {}

    async def configure_format(self, notebook_id: str, format_text: str) -> None:
        self.configured_formats[notebook_id] = format_text

    async def get_format(self, notebook_id: str) -> ChatFormatSettings:
        fmt = self.configured_formats.get(notebook_id, "")
        return ChatFormatSettings(goal="custom", response_length="longer", custom_prompt=fmt)

    async def ask_once(self, notebook_id: str, question: str, source_ids: list[str], conversation_id: str | None = None) -> TurnAnswer:
        raise NotImplementedError

    async def history(self, notebook_id: str, conversation_id: str, limit: int = 20) -> list[tuple[str, str]]:
        return []


class MockSession(ProviderSession):
    def __init__(self, files: MockFiles, conversation: MockConversation):
        self._files = files
        self._conversation = conversation

    @property
    def files(self) -> NotebookFiles:
        return self._files

    @property
    def conversation(self) -> NotebookConversation:
        return self._conversation


class MockSessionProvider(SessionProvider):
    def __init__(self, files: MockFiles, conversation: MockConversation):
        self.session = MockSession(files, conversation)

    def open(self, profile_ref: str):
        class _Ctx:
            def __init__(self, sess):
                self.sess = sess
            async def __aenter__(self):
                return self.sess
            async def __aexit__(self, *args):
                pass
        return _Ctx(self.session)


@pytest.mark.asyncio
async def test_n03_n04_direct_source_upload_and_exact_mime(tmp_path):
    files = MockFiles()
    conversation = MockConversation()
    provider = MockSessionProvider(files, conversation)

    pdf_bytes = b"%PDF-1.4 sample binary original pdf content"
    steps_bytes = b"RESPONSE FORMAT combined-envelope-v1\nSteps content..."
    pdf_hash = hashlib.sha256(pdf_bytes).hexdigest()
    steps_hash = hashlib.sha256(steps_bytes).hexdigest()

    mock_laravel = MagicMock()
    mock_laravel.stream_pdf = AsyncMock(side_effect=lambda act_id, dest: dest.write_bytes(pdf_bytes) or {"sha256": pdf_hash, "byte_count": len(pdf_bytes)})
    mock_laravel.get_steps = AsyncMock(return_value=(steps_bytes, steps_hash))
    mock_laravel.claim_source_effect = AsyncMock(side_effect=lambda case_id, op, act_id, dig: {"effect_id": f"eff-{op}", "disposition": "send_now", "state": "sending"})
    mock_laravel.record_source_effect_result = AsyncMock(return_value={"status": "confirmed"})
    mock_laravel.get_binding = AsyncMock(return_value={"binding_ref": "bnd-1", "notebook_id": None, "state": "pending_notebook"})

    protocol = DirectSourceProtocol(provider=provider, laravel_client=mock_laravel, temp_dir=tmp_path, poll_interval=0.01)
    result = await protocol.ingest(
        case_id="case-100",
        activation_id="act-100",
        binding_ref="bnd-1",
        pdf_title="v3-pdf-case-100-test",
        pdf_sha256=pdf_hash,
        steps_sha256=steps_hash,
    )

    assert result["status"] == "bound"
    assert len(files.created_notebooks) == 1
    assert len(files.uploaded_files) == 2

    # Check upload 1 is PDF
    _, pdf_path, pdf_mime, pdf_title = files.uploaded_files[0]
    assert pdf_mime == "application/pdf"
    assert pdf_title == "v3-pdf-case-100-test"

    # Check upload 2 is steps text
    _, steps_path, steps_mime, steps_title = files.uploaded_files[1]
    assert steps_mime == "text/plain"
    assert steps_title == "steps.txt"


@pytest.mark.asyncio
async def test_n05_polling_readiness_does_not_reupload(tmp_path):
    files = MockFiles()
    conversation = MockConversation()
    provider = MockSessionProvider(files, conversation)

    pdf_bytes = b"%PDF-1.4 test"
    steps_bytes = b"steps text"
    pdf_hash = hashlib.sha256(pdf_bytes).hexdigest()
    steps_hash = hashlib.sha256(steps_bytes).hexdigest()

    mock_laravel = MagicMock()
    mock_laravel.stream_pdf = AsyncMock(side_effect=lambda act_id, dest: dest.write_bytes(pdf_bytes) or {"sha256": pdf_hash, "byte_count": len(pdf_bytes)})
    mock_laravel.get_steps = AsyncMock(return_value=(steps_bytes, steps_hash))
    mock_laravel.claim_source_effect = AsyncMock(side_effect=lambda case_id, op, act_id, dig: {"effect_id": f"eff-{op}", "disposition": "send_now", "state": "sending"})
    mock_laravel.record_source_effect_result = AsyncMock(return_value={"status": "confirmed"})
    mock_laravel.get_binding = AsyncMock(return_value={"binding_ref": "bnd-1", "notebook_id": None, "state": "pending_notebook"})

    protocol = DirectSourceProtocol(provider=provider, laravel_client=mock_laravel, temp_dir=tmp_path, poll_interval=0.01)
    await protocol.ingest(
        case_id="case-101",
        activation_id="act-101",
        binding_ref="bnd-1",
        pdf_title="v3-pdf-case-101",
        pdf_sha256=pdf_hash,
        steps_sha256=steps_hash,
    )

    # Exactly 2 upload calls total, despite polling until ready
    assert len(files.uploaded_files) == 2
    for src_id, count in files.poll_counts.items():
        assert count >= 2


@pytest.mark.asyncio
async def test_processing_timeout_never_claims_ready_or_configures_chat(tmp_path):
    files = MockFiles()
    async def processing(notebook_id: str, source_id: str) -> SourceReceipt:
        return files.sources[source_id]
    files.get_source = processing
    chat = MockConversation()
    pdf = b"%PDF-1.4 processing fixture"
    steps = b"framework fixture"
    pdf_sha = hashlib.sha256(pdf).hexdigest()
    steps_sha = hashlib.sha256(steps).hexdigest()
    laravel = MagicMock()
    laravel.stream_pdf = AsyncMock(side_effect=lambda _, path: path.write_bytes(pdf))
    laravel.get_steps = AsyncMock(return_value=(steps, steps_sha))
    laravel.claim_source_effect = AsyncMock(side_effect=lambda _, operation, __, ___: {
        "effect_id": f"effect-{operation}", "disposition": "send_now",
    })
    laravel.record_source_effect_result = AsyncMock(return_value={})

    result = await DirectSourceProtocol(
        MockSessionProvider(files, chat), laravel, tmp_path,
        max_poll_seconds=0, poll_interval=0,
    ).ingest("case", "activation", "binding", "pdf-title", pdf_sha, steps_sha)

    assert result == {"status": "processing", "binding_ref": "binding"}
    assert chat.configured_formats == {}
    calls = laravel.record_source_effect_result.call_args_list
    assert [call.args[1]["state"] for call in calls] == ["confirmed", "registered", "registered"]


@pytest.mark.asyncio
async def test_terminal_pdf_processing_error_records_block_and_never_configures_chat(tmp_path):
    files = MockFiles()

    async def failed(_notebook_id: str, source_id: str) -> SourceReceipt:
        original = files.sources[source_id]
        return SourceReceipt(source_id, original.title, "error", original.mime_type)

    files.get_source = failed
    chat = MockConversation()
    pdf = b"%PDF-1.4 terminal fixture"
    steps = b"framework fixture"
    pdf_sha = hashlib.sha256(pdf).hexdigest()
    steps_sha = hashlib.sha256(steps).hexdigest()
    laravel = MagicMock()
    laravel.stream_pdf = AsyncMock(side_effect=lambda _, path: path.write_bytes(pdf))
    laravel.get_steps = AsyncMock(return_value=(steps, steps_sha))
    laravel.claim_source_effect = AsyncMock(side_effect=lambda _, operation, __, ___: {
        "effect_id": f"effect-{operation}", "disposition": "send_now",
    })
    laravel.record_source_effect_result = AsyncMock(return_value={})

    result = await DirectSourceProtocol(
        MockSessionProvider(files, chat), laravel, tmp_path,
        max_poll_seconds=0, poll_interval=0,
    ).ingest("case", "activation", "binding", "pdf-title", pdf_sha, steps_sha)
    assert result == {"status": "blocked", "error_code": "source_processing_failed"}
    assert chat.configured_formats == {}
    assert laravel.record_source_effect_result.call_args_list[-1].args == (
        "effect-notebook_upload_pdf",
        {"state": "blocked", "error_code": "source_processing_failed"},
    )
