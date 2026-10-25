import json
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock
import uuid
import pytest

from v3_engine.notebooklm.ports import AccountMismatchError, AuthenticationRequiredError, ProviderError

from v3_engine.local_worker.progress import check_provider_health_once, publish_once


def test_reporter_only_observes_and_publishes_allowlisted_counters(tmp_path):
    task, job = str(uuid.uuid4()), str(uuid.uuid4())
    terminal = str(uuid.uuid5(uuid.UUID(job), 'terminal-subject-v1'))
    (tmp_path/'pending_claim.json').write_text(json.dumps({'claim_id':'existing'}))
    folder=tmp_path/'tasks'/task
    folder.mkdir(parents=True)
    (folder/'terminal_link.json').write_text(json.dumps({'job_id':job,'terminal_run_id':terminal}))
    client=Mock()
    client.get_claim.return_value={'task_id':task,'task_kind':'author_prompts'}
    adapter=Mock()
    adapter.command.return_value={'freshness':'realtime','subject_run_id':terminal,
        'phase':'generating_lessons','status':'running','lessons_saved':13,'current_pdf_ordinal':1,
        'total_pdfs':1,'private_prompt':'never send this'}
    assert publish_once(SimpleNamespace(workspace_dir=str(tmp_path)),client,adapter)
    adapter.command.assert_called_once_with('status','--run-id',terminal,'--json')
    payload=client.report_progress.call_args.args[1]
    assert payload['lessons_saved']==13
    assert 'private_prompt' not in payload
    assert client.method_calls[0][0]=='get_claim'
    assert len(client.method_calls)==2


def test_worker_health_probe_uses_shared_profile_and_reports_only_safe_fields(monkeypatch):
    import v3_engine.local_worker.progress as progress

    class Authority:
        def profile_status(self):
            return {'generation': 7, 'verified': True}

        def proof_notebook_id(self):
            return 'proof-notebook'

    class TerminalService:
        async def verify_session(self, proof_id):
            assert proof_id == 'proof-notebook'
            return True

    client = Mock()
    client.get_provider_health_requests.return_value = [{'probe_id': str(uuid.uuid4())}]
    monkeypatch.setattr(progress, 'NotebookCredentialAuthority', Authority)
    monkeypatch.setattr(progress, 'NotebookTerminalService', TerminalService)

    check_provider_health_once(client)

    report = client.report_provider_health.call_args.args[0]
    assert report == {
        'probe_id': client.get_provider_health_requests.return_value[0]['probe_id'],
        'state': 'ready',
        'safe_code': 'ok',
        'profile_generation': 7,
    }


@pytest.mark.parametrize('error,state,code', [
    (AuthenticationRequiredError(), 'authentication_required', 'authentication_required'),
    (AccountMismatchError(), 'authentication_required', 'account_mismatch'),
    (ProviderError('notebook_verification_failed', 'Proof notebook unavailable.'), 'unavailable', 'provider_unavailable'),
    (ProviderError('ownership_unverified', 'Ownership unknown.'), 'unavailable', 'provider_unavailable'),
])
def test_health_probe_preserves_authentication_and_ownership_failure_categories(monkeypatch, error, state, code):
    import v3_engine.local_worker.progress as progress
    monkeypatch.setattr(progress, 'NotebookCredentialAuthority', lambda: SimpleNamespace(
        profile_status=lambda: {'generation': 7}, proof_notebook_id=lambda: 'proof-notebook'))
    class Service:
        async def verify_session(self, proof_id):
            raise error
    monkeypatch.setattr(progress, 'NotebookTerminalService', Service)
    client = Mock()
    client.get_provider_health_requests.return_value = [{'probe_id': str(uuid.uuid4())}]

    check_provider_health_once(client)

    report = client.report_provider_health.call_args.args[0]
    assert report['state'] == state and report['safe_code'] == code
