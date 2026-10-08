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
from v3_engine.notebooklm.protocol import DirectSourceProtocol, COMBINED_RESPONSE_FORMAT_TEXT


class MockFiles(NotebookFiles):
    def __init__(self):
        self.created_notebooks = []
        self.uploaded_files = []
        self.sources = {}

    async def create_notebook(self, title: str) -> NotebookRef:
        nb_id = f"nb-{len(self.created_notebooks) + 1}"
        ref = NotebookRef(id=nb_id, title=title)
        self.created_notebooks.append(ref)
        return ref

    async def get_notebook(self, id: str) -> NotebookInventory:
        if id == "nb-existing":
            return NotebookInventory(id=id, title="v3-case-case-200", source_ids=["src-pdf-existing", *self.sources])
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
            status="ready",
            mime_type=mime_type,
        )
        self.sources[src_id] = receipt
        return receipt

    async def get_source(self, notebook_id: str, source_id: str) -> SourceReceipt:
        if source_id in self.sources:
            return self.sources[source_id]
        if source_id == "src-pdf-existing":
            return SourceReceipt(source_id=source_id, title="v3-pdf-case-200", status="ready", mime_type="application/pdf")
        return SourceReceipt(source_id=source_id, title="unknown", status="ready", mime_type="application/pdf")


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
async def test_n06_pdf_confirmed_steps_uncertain_does_not_reupload_pdf(tmp_path):
    files = MockFiles()
    conversation = MockConversation()
    provider = MockSessionProvider(files, conversation)

    pdf_bytes = b"%PDF-1.4 sample binary"
    steps_bytes = b"steps text"
    pdf_hash = hashlib.sha256(pdf_bytes).hexdigest()
    steps_hash = hashlib.sha256(steps_bytes).hexdigest()

    mock_laravel = MagicMock()
    mock_laravel.stream_pdf = AsyncMock(side_effect=lambda act_id, dest: dest.write_bytes(pdf_bytes) or {"sha256": pdf_hash, "byte_count": len(pdf_bytes)})
    mock_laravel.get_steps = AsyncMock(return_value=(steps_bytes, steps_hash))

    # Disposition returns already_confirmed for create and pdf upload!
    async def mock_claim(case_id, op, act_id, dig):
        if op == "notebook_create":
            return {"effect_id": "eff-nb", "disposition": "already_confirmed", "provider_ref": "nb-existing", "state": "confirmed"}
        elif op == "notebook_upload_pdf":
            return {"effect_id": "eff-pdf", "disposition": "already_confirmed", "provider_ref": "src-pdf-existing", "state": "confirmed"}
        elif op == "notebook_upload_steps":
            return {"effect_id": "eff-steps", "disposition": "send_now", "provider_ref": None, "state": "sending"}
        elif op == "chat_configure":
            return {"effect_id": "eff-cfg", "disposition": "send_now", "provider_ref": None, "state": "sending"}
        raise ValueError(f"Unknown op {op}")

    mock_laravel.claim_source_effect = AsyncMock(side_effect=mock_claim)
    mock_laravel.record_source_effect_result = AsyncMock(return_value={"status": "confirmed"})
    mock_laravel.get_binding = AsyncMock(return_value={"binding_ref": "bnd-1", "notebook_id": "nb-existing", "state": "pending_sources"})

    protocol = DirectSourceProtocol(provider=provider, laravel_client=mock_laravel, temp_dir=tmp_path)
    result = await protocol.ingest(
        case_id="case-200",
        activation_id="act-200",
        binding_ref="bnd-1",
        pdf_title="v3-pdf-case-200",
        pdf_sha256=pdf_hash,
        steps_sha256=steps_hash,
    )

    assert result["status"] == "bound"
    # Never created a new notebook
    assert len(files.created_notebooks) == 0
    # Uploaded ONLY steps text, NOT PDF
    assert len(files.uploaded_files) == 1
    _, _, mime, title = files.uploaded_files[0]
    assert mime == "text/plain"
    assert title == "steps.txt"


@pytest.mark.asyncio
async def test_n07_lost_create_id_uncertainty_refuses_new_create(tmp_path):
    files = MockFiles()
    conversation = MockConversation()
    provider = MockSessionProvider(files, conversation)

    pdf_bytes = b"%PDF-1.4 dummy"
    steps_bytes = b"steps text"
    h1 = hashlib.sha256(pdf_bytes).hexdigest()
    h2 = hashlib.sha256(steps_bytes).hexdigest()

    mock_laravel = MagicMock()
    mock_laravel.stream_pdf = AsyncMock(side_effect=lambda act_id, dest: dest.write_bytes(pdf_bytes) or {"sha256": h1, "byte_count": len(pdf_bytes)})
    mock_laravel.get_steps = AsyncMock(return_value=(steps_bytes, h2))

    # When create claim returns reconcile_only with no known provider_ref (uncertain)
    mock_laravel.claim_source_effect = AsyncMock(return_value={
        "effect_id": "eff-create",
        "disposition": "reconcile_only",
        "provider_ref": None,
        "state": "uncertain",
    })
    mock_laravel.get_binding = AsyncMock(return_value={"binding_ref": "bnd-1", "notebook_id": None, "state": "pending_notebook"})

    protocol = DirectSourceProtocol(provider=provider, laravel_client=mock_laravel, temp_dir=tmp_path)
    with pytest.raises(ProviderError) as exc_info:
        await protocol.ingest(
            case_id="case-300",
            activation_id="act-300",
            binding_ref="bnd-1",
            pdf_title="v3-pdf-case-300",
            pdf_sha256=h1,
            steps_sha256=h2,
        )

    assert exc_info.value.code == "provider_uncertain"
    # Zero notebooks created by the worker
    assert len(files.created_notebooks) == 0


@pytest.mark.asyncio
async def test_n06_duplicate_source_reconciliation_is_idempotent_and_does_not_reupload(tmp_path):
    files = MockFiles()
    conversation = MockConversation()
    provider = MockSessionProvider(files, conversation)

    pdf_bytes = b"%PDF-1.4 dummy"
    steps_bytes = b"steps text"
    h1 = hashlib.sha256(pdf_bytes).hexdigest()
    h2 = hashlib.sha256(steps_bytes).hexdigest()

    mock_laravel = MagicMock()
    mock_laravel.stream_pdf = AsyncMock(side_effect=lambda act_id, dest: dest.write_bytes(pdf_bytes) or {"sha256": h1, "byte_count": len(pdf_bytes)})
    mock_laravel.get_steps = AsyncMock(return_value=(steps_bytes, h2))

    # All claims return already_confirmed
    async def mock_claim(case_id, op, act_id, dig):
        if op == "notebook_create":
            return {"effect_id": "eff-nb", "disposition": "already_confirmed", "provider_ref": "nb-existing", "state": "confirmed"}
        elif op == "notebook_upload_pdf":
            return {"effect_id": "eff-pdf", "disposition": "already_confirmed", "provider_ref": "src-pdf-existing", "state": "confirmed"}
        elif op == "notebook_upload_steps":
            return {"effect_id": "eff-steps", "disposition": "already_confirmed", "provider_ref": "src-steps-existing", "state": "confirmed"}
        elif op == "chat_configure":
            return {"effect_id": "eff-cfg", "disposition": "already_confirmed", "provider_ref": "configured", "state": "confirmed"}
        raise ValueError(f"Unknown op {op}")

    files.sources["src-pdf-existing"] = SourceReceipt(source_id="src-pdf-existing", title="v3-pdf-case-200", status="ready", mime_type="application/pdf")
    files.sources["src-steps-existing"] = SourceReceipt(source_id="src-steps-existing", title="steps.txt", status="ready", mime_type="text/plain")
    conversation.configured_formats["nb-existing"] = COMBINED_RESPONSE_FORMAT_TEXT

    mock_laravel.claim_source_effect = AsyncMock(side_effect=mock_claim)
    mock_laravel.record_source_effect_result = AsyncMock(return_value={"status": "confirmed"})
    mock_laravel.get_binding = AsyncMock(return_value={"binding_ref": "bnd-1", "notebook_id": "nb-existing", "state": "bound"})

    protocol = DirectSourceProtocol(provider=provider, laravel_client=mock_laravel, temp_dir=tmp_path)
    result = await protocol.ingest(
        case_id="case-200",
        activation_id="act-200",
        binding_ref="bnd-1",
        pdf_title="v3-pdf-case-200",
        pdf_sha256=h1,
        steps_sha256=h2,
    )

    assert result["status"] == "bound"
    assert len(files.created_notebooks) == 0
    assert len(files.uploaded_files) == 0
