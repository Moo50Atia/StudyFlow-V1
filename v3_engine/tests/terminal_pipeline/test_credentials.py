"""Tests for SDK credentials isolation, owner proof, and browser-free bootstrap (Spec 018 / T006)."""

import os
import json
import shutil
import stat
import pytest
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock

from v3_engine.notebooklm.ports import (
    AccountMismatchError,
    AuthenticationRequiredError,
    ProviderError,
    ProviderRateLimitError,
    SourceReceipt,
)
from v3_engine.notebooklm.terminal_service import (
    NotebookTerminalService,
    bootstrap_subject_credentials,
)
from v3_engine.notebooklm.credentials import REQUIRED_COOKIES


@pytest.fixture
def master_state(tmp_path):
    path = tmp_path/'master_storage_state.json'
    path.write_text(json.dumps({'cookies': [
        {'name': name, 'value': f'test-{name}', 'domain': '.google.com', 'path': '/', 'expires': -1}
        for name in sorted(REQUIRED_COOKIES)
    ], 'origins': []}))
    path.chmod(0o600)
    return path


class FakeSdkNotebookFiles:
    def __init__(self, owner_role: str = "owner"):
        self.owner_role = owner_role
        self.list_sources_calls = 0
        self.verify_owner_calls = 0

    async def list_sources(self, notebook_id: str) -> list[SourceReceipt]:
        self.list_sources_calls += 1
        return [
            SourceReceipt(source_id="src-1", title="PDF 0001", status="ready", mime_type="application/pdf"),
        ]

    async def verify_owner(self, proof_notebook_id: str) -> str:
        self.verify_owner_calls += 1
        return self.owner_role


class FakeSdkSession:
    def __init__(self, owner_role: str = "owner"):
        self.files = FakeSdkNotebookFiles(owner_role)
        self.conversation = MagicMock()
        self.closed = False

    async def close(self) -> None:
        self.closed = True


class FakeSessionProvider:
    def __init__(self, session: FakeSdkSession):
        self.session = session
        self.opened_paths: list[Path] = []

    def open(self, profile_ref=None, storage_path=None):
        self.opened_paths.append(storage_path)
        provider = self

        class _Context:
            async def __aenter__(self):
                return provider.session

            async def __aexit__(self, exc_type, exc, tb):
                await provider.session.close()

        return _Context()


def test_per_subject_credential_isolation_and_permissions(tmp_path: Path, master_state: Path) -> None:
    master_file = master_state
    master_bytes = master_file.read_bytes()

    session_root = tmp_path / "sessions"
    subject_run_id = "44444444-5555-6666-7777-888888888888"

    subject_path = bootstrap_subject_credentials(
        master_storage_path=master_file,
        subject_run_id=subject_run_id,
        session_base_dir=session_root,
    )

    assert subject_path.is_file()
    assert subject_path.read_bytes() == master_bytes

    # Directory permissions 0700, file permissions 0600
    subject_dir = subject_path.parent
    dir_mode = stat.S_IMODE(subject_dir.stat().st_mode)
    file_mode = stat.S_IMODE(subject_path.stat().st_mode)
    assert dir_mode == 0o700
    assert file_mode == 0o600

    # Modify subject copy and verify master file remains unchanged
    subject_path.write_bytes(b'{"cookies": [{"name": "SID", "value": "modified"}]}')
    assert master_file.read_bytes() == master_bytes


def test_refresh_command_disabled_in_environment(monkeypatch: pytest.MonkeyPatch, tmp_path: Path, master_state: Path) -> None:
    monkeypatch.setenv("NOTEBOOKLM_REFRESH_CMD", "launch_browser.sh")
    master_file = master_state

    bootstrap_subject_credentials(
        master_storage_path=master_file,
        subject_run_id="55555555-6666-7777-8888-999999999999",
        session_base_dir=tmp_path / "sessions",
    )

    # Must be disabled (unset or empty)
    assert os.getenv("NOTEBOOKLM_REFRESH_CMD") in (None, "")


@pytest.mark.asyncio
async def test_owner_verification_passes_for_explicit_owner(monkeypatch) -> None:
    record = MagicMock()
    monkeypatch.setattr('v3_engine.notebooklm.credentials.NotebookCredentialAuthority.record_verified', record)
    fake_session = FakeSdkSession(owner_role="owner")
    provider = FakeSessionProvider(fake_session)
    service = NotebookTerminalService(session_provider=provider)

    verified = await service.verify_session(proof_notebook_id="proof-nb-123")
    assert verified is True
    assert fake_session.files.verify_owner_calls == 1
    record.assert_called_once()


@pytest.mark.asyncio
async def test_owner_verification_fails_for_shared_or_viewer_role() -> None:
    fake_session = FakeSdkSession(owner_role="not_owner")
    provider = FakeSessionProvider(fake_session)
    service = NotebookTerminalService(session_provider=provider)

    with pytest.raises(AccountMismatchError) as error:
        await service.verify_session(proof_notebook_id="proof-nb-123")
    assert error.value.code == "account_mismatch"
    assert fake_session.files.verify_owner_calls == 1


@pytest.mark.asyncio
async def test_unknown_ownership_does_not_claim_the_session_expired(monkeypatch) -> None:
    record = MagicMock()
    monkeypatch.setattr('v3_engine.notebooklm.credentials.NotebookCredentialAuthority.record_verified', record)
    session = FakeSdkSession(owner_role="unknown")
    service = NotebookTerminalService(session_provider=FakeSessionProvider(session))

    with pytest.raises(ProviderError) as error:
        await service.verify_session(proof_notebook_id="proof-nb-123")

    assert error.value.code == "ownership_unverified"
    assert not isinstance(error.value, AuthenticationRequiredError)
    record.assert_not_called()
