"""Provider failures crossing the Activity boundary must be safe and bounded."""

from __future__ import annotations

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

