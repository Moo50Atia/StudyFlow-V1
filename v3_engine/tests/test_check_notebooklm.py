"""The public auth command must distinguish notebook failures from expired sessions."""
from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from scripts.v3 import check_notebooklm as checker
from v3_engine.notebooklm.credentials import CredentialsValidationError
from v3_engine.notebooklm.ports import AccountMismatchError, AuthenticationRequiredError, ProviderError


@pytest.mark.asyncio
@pytest.mark.parametrize('error,expected_text', [
    (AuthenticationRequiredError(raw_detail='private cookie'), 'Refresh the NotebookLM cookie'),
    (AccountMismatchError(), 'does not own the configured proof notebook'),
    (CredentialsValidationError('account_mismatch', 'private profile details'), 'does not own the configured proof notebook'),
    (ProviderError('notebook_verification_failed', 'safe', raw_detail='private response'), 'Check its link and access'),
    (ProviderError('ownership_unverified', 'safe'), 'did not return ownership information'),
    (ProviderError('provider_unavailable', 'safe'), 'connection failed'),
])
async def test_check_reports_the_actual_failure_category(tmp_path, monkeypatch, capsys, error, expected_text):
    monkeypatch.setattr(checker, 'get_storage_state_path', lambda: tmp_path/'session.json')
    monkeypatch.setattr(checker, 'validate_storage_state', lambda path: None)
    monkeypatch.setattr(checker, 'SdkSessionProvider', lambda **kwargs: object())
    service = SimpleNamespace(verify_session=AsyncMock(side_effect=error))
    monkeypatch.setattr(checker, 'NotebookTerminalService', lambda **kwargs: service)

    assert await checker.check('https://notebook.google.com', 'proof', 'notebooklm_py') == 2

    output = capsys.readouterr().out
    assert error.code in output and expected_text in output
    assert 'private' not in output and 'CSRF' not in output
    if error.code != 'authentication_required':
        assert 'Refresh the NotebookLM cookie' not in output


@pytest.mark.asyncio
async def test_missing_proof_is_a_setup_problem(monkeypatch, capsys):
    monkeypatch.setattr(checker, 'NotebookCredentialAuthority', lambda: SimpleNamespace(proof_notebook_id=lambda: None))

    assert await checker.check('https://notebook.google.com', None, 'notebooklm_py') == 2

    output = capsys.readouterr().out
    assert 'profile_unverified' in output and '--notebook-id' in output
    assert 'authentication_required' not in output


@pytest.mark.asyncio
async def test_unreadable_session_is_a_storage_problem(monkeypatch, capsys):
    def unavailable():
        raise OSError('private filesystem details')
    monkeypatch.setattr(checker, 'get_storage_state_path', unavailable)

    assert await checker.check('https://notebook.google.com', 'proof', 'notebooklm_py') == 2

    output = capsys.readouterr().out
    assert 'credential_storage_unavailable' in output
    assert 'authentication_required' not in output and 'private' not in output
