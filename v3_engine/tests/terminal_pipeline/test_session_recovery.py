"""Recovery must not turn a successful shared auth check into an old journal failure."""
from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from v3_engine.terminal_pipeline import activities
from v3_engine.terminal_pipeline.journal import OperationalJournal
from v3_engine.notebooklm.ports import AccountMismatchError, AuthenticationRequiredError, ProviderError


@pytest.mark.asyncio
async def test_successful_auth_after_old_failure_is_recorded_as_success(tmp_path, monkeypatch):
    monkeypatch.setenv('V3_TERMINAL_ROOT', str(tmp_path))
    activities._record_event('subject', 'notebook_session_verified', 'preflight', 'failed', 'account_mismatch')
    service = SimpleNamespace(verify_session=AsyncMock(return_value=True))
    monkeypatch.setattr(activities, 'NotebookTerminalService', lambda: service)
    monkeypatch.setattr(activities.activity, 'info', lambda: SimpleNamespace(
        workflow_run_id='workflow-run', activity_id='recovery-check', attempt=1))

    result = await activities.verify_notebook_session({'subject_run_id': 'subject', 'proof_notebook_id': 'proof'})

    assert result['outcome'] == 'complete'
    assert result['safe_code'] == 'ok'
    service.verify_session.assert_awaited_once_with('proof', storage_path=None)
    events = OperationalJournal(tmp_path).read_events(after_seq=0)
    assert [(e.outcome, e.safe_code) for e in events] == [('failed', 'account_mismatch'), ('complete', 'ok')]
    # Delivery of the same activity attempt remains idempotent.
    await activities.verify_notebook_session({'subject_run_id': 'subject', 'proof_notebook_id': 'proof'})
    assert len(OperationalJournal(tmp_path).read_events(after_seq=0)) == 2


@pytest.mark.asyncio
async def test_journal_failure_is_not_reported_as_an_authentication_failure(monkeypatch):
    service = SimpleNamespace(verify_session=AsyncMock(return_value=True))
    monkeypatch.setattr(activities, 'NotebookTerminalService', lambda: service)
    def storage_failure(*args, **kwargs):
        raise OSError('disk unavailable')
    monkeypatch.setattr(activities, '_record_event', storage_failure)

    result = await activities.verify_notebook_session({'subject_run_id': 'subject', 'proof_notebook_id': 'proof'})

    assert result['outcome'] == 'failed'
    assert result['safe_code'] == 'storage_unavailable'


@pytest.mark.asyncio
@pytest.mark.parametrize('error,code', [
    (AuthenticationRequiredError(), 'authentication_required'),
    (AccountMismatchError(), 'account_mismatch'),
    (ProviderError('notebook_verification_failed', 'Proof notebook unavailable.'), 'provider_unavailable'),
    (ProviderError('ownership_unverified', 'Ownership unknown.'), 'provider_unavailable'),
])
async def test_activity_distinguishes_authentication_ownership_and_provider_failures(tmp_path, monkeypatch, error, code):
    monkeypatch.setenv('V3_TERMINAL_ROOT', str(tmp_path))
    service = SimpleNamespace(verify_session=AsyncMock(side_effect=error))
    monkeypatch.setattr(activities, 'NotebookTerminalService', lambda: service)

    result = await activities.verify_notebook_session({'subject_run_id': 'subject', 'proof_notebook_id': 'proof'})

    assert result['outcome'] == 'failed'
    assert result['safe_code'] == code
    events = OperationalJournal(tmp_path).read_events(after_seq=0)
    assert len(events) == 1 and events[0].safe_code == code
