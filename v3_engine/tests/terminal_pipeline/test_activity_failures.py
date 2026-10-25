"""Provider failures crossing the Activity boundary must be safe and bounded."""

from __future__ import annotations

import json
import pytest

from v3_engine.terminal_pipeline import activities


@pytest.mark.asyncio
async def test_history_auth_failure_is_identified_before_a_lesson_is_dispatched(tmp_path, monkeypatch):
    from v3_engine.notebooklm.ports import AuthenticationRequiredError
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path / "terminal"))
    calls = []
    class UnauthenticatedService:
        async def get_history(self, *args, **kwargs):
            raise AuthenticationRequiredError(raw_detail="private-csrf-and-cookie-details")
        async def send_turn(self, *args, **kwargs):
            calls.append("sent")
    monkeypatch.setattr(activities, "NotebookTerminalService", UnauthenticatedService)
    result = await activities.send_terminal_turn({
        "subject_run_id": "existing-run", "notebook_id": "processing-notebook",
        "conversation_id": "existing-chat", "question": "Continue", "source_ids": ["pdf-3", "steps"],
        "operation_id": "pdf-0003:lesson-003",
    })
    assert result["outcome"] == "failed" and result["safe_code"] == "authentication_required"
    assert result["receipt_ref"] is None and calls == []
    assert "private-csrf-and-cookie-details" not in str(result)
    assert not list((tmp_path / "terminal/runs/existing-run/private/turn-receipts").glob("*.json"))


@pytest.mark.asyncio
async def test_notebook_create_failure_does_not_return_or_raise_vendor_details(tmp_path, monkeypatch):
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path / "terminal"))

    calls = {"create": 0}

    class FailingNotebookService:
        def __init__(self):
            pass

        async def create_notebook(self, title, storage_path=None):
            calls["create"] += 1
            try:
                raise RuntimeError("private response body and access-token-value")
            except RuntimeError as cause:
                raise RuntimeError("provider SDK wrapper") from cause

    monkeypatch.setattr(activities, "NotebookTerminalService", FailingNotebookService)
    request = {
        "subject_run_id": "test-subject", "title": "test", "credential_path": str(tmp_path / "creds.json"),
    }
    result = await activities.create_terminal_notebook(request)
    serialized = str(result)
    assert result["outcome"] == "uncertain"
    assert result["safe_code"] == "delivery_uncertain"
    assert "private response body" not in serialized
    assert "access-token-value" not in serialized
    # The durable dispatch intent prevents an automatic/manual duplicate create
    # when the remote operation may have committed before the exception.
    repeated = await activities.create_terminal_notebook(request)
    assert repeated["outcome"] == "uncertain"
    assert calls["create"] == 1


@pytest.mark.asyncio
async def test_saved_run_initialization_does_not_require_notebooklm_credentials(tmp_path, monkeypatch):
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path / "terminal"))
    monkeypatch.setenv("V3_NOTEBOOKLM_STORAGE_STATE", str(tmp_path / "missing-storage-state.json"))
    result = await activities.initialize_terminal_run({
        "schema_version": 1,
        "subject_run_id": "saved-handoff-run",
        "mode": "saved",
        "is_recovery": False,
    })
    assert result["outcome"] == "complete"
    assert not (tmp_path / "terminal/runs/saved-handoff-run/private/notebooklm-credentials.json").exists()


@pytest.mark.asyncio
async def test_source_readiness_polls_have_distinct_retry_safe_journal_events(tmp_path, monkeypatch):
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path / "terminal"))
    statuses = iter([["processing"], ["ready"], ["ready"]])

    class FakeSource:
        def __init__(self, status):
            self.status = status

    class PollingNotebookService:
        def __init__(self):
            pass

        async def poll_sources(self, notebook_id, storage_path=None):
            return [FakeSource(status) for status in next(statuses)]

    monkeypatch.setattr(activities, "NotebookTerminalService", PollingNotebookService)
    request = {"subject_run_id": "source-poll-test", "notebook_id": "nb-test"}
    pending = await activities.poll_terminal_sources({**request, "poll_ordinal": 1})
    ready = await activities.poll_terminal_sources({**request, "poll_ordinal": 2})
    replayed = await activities.poll_terminal_sources({**request, "poll_ordinal": 2})

    assert pending["outcome"] == "pending"
    assert ready["outcome"] == replayed["outcome"] == "complete"
    events = activities.OperationalJournal(tmp_path / "terminal").read_events(after_seq=0)
    assert [(event.operation_id, event.outcome) for event in events] == [
        ("source-readiness-poll:1", "pending"),
        ("source-readiness-poll:2", "complete"),
    ]


@pytest.mark.asyncio
async def test_unknown_configuration_requires_successful_readback_before_reapplying_setter(tmp_path, monkeypatch):
    monkeypatch.setenv('V3_TERMINAL_ROOT', str(tmp_path / 'terminal'))
    state = {'writes': 0, 'matches': False, 'readable': False}
    class ConfigurationService:
        async def configure_chat(self, notebook, text, storage_path=None):
            state['writes'] += 1
            if state['writes'] == 1:
                raise RuntimeError('fixture lost configuration acknowledgment')
            state['matches'] = True
        async def reconcile(self, **kwargs):
            if not state['readable']:
                raise RuntimeError('fixture cannot read current settings')
            return {'outcome': 'complete'} if state['matches'] else {'outcome': 'uncertain', 'configuration_matches': False}
    monkeypatch.setattr(activities, 'NotebookTerminalService', ConfigurationService)
    request = {'subject_run_id': 'config-recovery', 'notebook_id': 'existing-notebook', 'format_text': 'Captured format'}
    assert (await activities.configure_terminal_chat(request))['outcome'] == 'uncertain'
    assert (await activities.configure_terminal_chat(request))['outcome'] == 'uncertain'
    assert state['writes'] == 1  # unreadable settings never authorize another write
    state['readable'] = True
    assert (await activities.configure_terminal_chat(request))['outcome'] == 'complete'
    assert state['writes'] == 2  # positive mismatch read + convergent setter + readback
    assert (await activities.configure_terminal_chat(request))['outcome'] == 'complete'
    assert state['writes'] == 2


@pytest.mark.asyncio
async def test_lost_create_acknowledgment_uses_unique_inventory_without_second_create(tmp_path, monkeypatch):
    monkeypatch.setenv('V3_TERMINAL_ROOT', str(tmp_path / 'terminal'))
    calls = []
    class NotebookService:
        async def create_notebook(self, *args, **kwargs):
            calls.append('create')
            raise RuntimeError('fixture lost create acknowledgment')
        async def reconcile(self, **kwargs):
            calls.append('read_inventory')
            return {'outcome': 'complete', 'notebook_id': 'retained-notebook'}
    monkeypatch.setattr(activities, 'NotebookTerminalService', NotebookService)
    request = {'subject_run_id': 'create-recovery', 'title': 'Unique run title'}
    result = await activities.create_terminal_notebook(request)
    assert result['outcome'] == 'complete' and result['result_ref'] == 'retained-notebook'
    replay = await activities.create_terminal_notebook(request)
    assert replay['outcome'] == 'complete' and replay['result_ref'] == 'retained-notebook'
    assert calls == ['create', 'read_inventory']


@pytest.mark.asyncio
async def test_lost_upload_acknowledgment_reconciles_existing_source_without_resend(tmp_path, monkeypatch):
    monkeypatch.setenv('V3_TERMINAL_ROOT', str(tmp_path / 'terminal'))
    source = tmp_path / 'part-1.pdf'
    source.write_bytes(b'fixture pdf bytes')
    calls = []

    class NotebookService:
        async def upload_source(self, **kwargs):
            calls.append('upload')
            raise RuntimeError('fixture lost upload acknowledgment')

        async def reconcile(self, operation_type, notebook_id=None, title=None, **kwargs):
            calls.append('read_sources')
            assert operation_type == 'upload'
            assert notebook_id == 'retained-notebook'
            assert title == 'part-1.pdf'
            return {'outcome': 'complete', 'source_id': 'retained-source'}

    monkeypatch.setattr(activities, 'NotebookTerminalService', NotebookService)
    request = {
        'subject_run_id': 'upload-recovery', 'notebook_id': 'retained-notebook',
        'source_path': str(source), 'mime_type': 'application/pdf', 'title': 'part-1.pdf',
        'credential_path': str(tmp_path / 'credentials.json'),
    }
    result = await activities.upload_terminal_source(request)
    assert result['outcome'] == 'complete' and result['result_ref'] == 'retained-source'
    replay = await activities.upload_terminal_source(request)
    assert replay['outcome'] == 'complete' and replay['result_ref'] == 'retained-source'
    assert calls == ['upload', 'read_sources']


@pytest.mark.asyncio
async def test_lost_chat_acknowledgment_reconciles_history_without_resend(tmp_path, monkeypatch):
    monkeypatch.setenv('V3_TERMINAL_ROOT', str(tmp_path / 'terminal'))
    calls = []

    class NotebookService:
        async def get_history(self, *args, **kwargs):
            calls.append('read_prefix')
            return [('Earlier question', 'Earlier answer')]

        async def send_turn(self, *args, **kwargs):
            calls.append('send')
            raise RuntimeError('fixture lost chat acknowledgment')

        async def reconcile_chat_turn(self, notebook_id, conversation_id, expected_question,
                                      expected_turn_index, expected_history_prefix, storage_path=None):
            calls.append('read_history')
            assert conversation_id == 'retained-conversation'
            assert expected_question == 'Continue'
            assert expected_turn_index == 1
            assert expected_history_prefix == ['Earlier question']
            return {'outcome': 'complete', 'raw_answer': 'retained response', 'turn_index': 1}

    monkeypatch.setattr(activities, 'NotebookTerminalService', NotebookService)
    request = {
        'subject_run_id': 'chat-recovery', 'notebook_id': 'retained-notebook',
        'conversation_id': 'retained-conversation', 'question': 'Continue',
        'source_ids': ['pdf-source'], 'operation_id': 'pdf-0001:lesson-0002',
    }
    result = await activities.send_terminal_turn(request)
    assert result['outcome'] == 'complete' and result['result_ref'] == 'retained-conversation'
    assert 'retained response' not in str(result)
    replay = await activities.send_terminal_turn(request)
    assert replay['outcome'] == 'complete' and replay['result_ref'] == 'retained-conversation'
    assert calls == ['read_prefix', 'send', 'read_history']
    saved = next((tmp_path / 'terminal/runs/chat-recovery/private/turn-receipts').glob('*.json'))
    assert json.loads(saved.read_text())['raw_answer'] == 'retained response'


@pytest.mark.asyncio
async def test_partial_upload_keeps_safe_transfer_diagnostics_and_does_not_resend(tmp_path, monkeypatch, caplog):
    import httpx
    from v3_engine.notebooklm.ports import ProviderUnavailableError
    monkeypatch.setenv('V3_TERMINAL_ROOT', str(tmp_path/'terminal'))
    source = tmp_path/'part.pdf'
    source.write_bytes(b'fixture')
    calls = []
    class Service:
        async def upload_source(self, **kwargs):
            calls.append('upload')
            response = httpx.Response(403, request=httpx.Request('POST', 'https://notebook.google.com/upload?secret=private'))
            transport = httpx.HTTPStatusError('private response and cookie', request=response.request, response=response)
            sdk = RuntimeError('private SDK details')
            sdk.stage = 'start_session'
            sdk.__cause__ = transport
            raise ProviderUnavailableError(raw_detail='private') from sdk
        async def reconcile(self, **kwargs):
            return {'outcome': 'uncertain', 'source_id': 'registered-source', 'source_status': 'processing'}
    monkeypatch.setattr(activities, 'NotebookTerminalService', Service)
    request = {'subject_run_id': 'partial-upload', 'notebook_id': 'notebook', 'source_path': str(source),
               'mime_type': 'application/pdf', 'title': 'part.pdf'}

    assert (await activities.upload_terminal_source(request))['outcome'] == 'uncertain'
    assert (await activities.upload_terminal_source(request))['outcome'] == 'uncertain'

    operation = next((tmp_path/'terminal/runs/partial-upload/private/notebook-operations').glob('*.json'))
    saved = json.loads(operation.read_text())
    assert saved['state'] == 'dispatching'
    assert saved['failure'] == {'code': 'provider_unavailable', 'exception_type': 'HTTPStatusError',
                                'http_status': 403, 'upload_stage': 'start_session',
                                'request_host': 'notebook.google.com', 'product_cookie_present': False,
                                'account_cookie_present': False}
    assert calls == ['upload']
    assert 'private' not in operation.read_text() and 'private' not in caplog.text
