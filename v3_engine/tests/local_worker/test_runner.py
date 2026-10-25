"""Unit and integration tests for StageContext, TaskRunner, and pre-send dispatch fence."""

import hashlib
import json
from pathlib import Path
import pytest
from unittest.mock import MagicMock
import uuid
from types import SimpleNamespace

from v3_engine.local_worker.journal import LocalEffectJournal
from v3_engine.local_worker.protocol import LocalWorkerProtocolError
from v3_engine.local_worker.runner import (
    HeartbeatManager,
    HeartbeatStaleError,
    LeaseExpiredError,
    OfflineProviderDouble,
    NotebookLMProviderAdapter,
    PermitExpiredError,
    ReconciliationRequiredError,
    StageContext,
    TaskRunner,
)
from v3_engine.notebooklm.ports import AuthenticationRequiredError


def test_notebooklm_adapter_uses_terminal_service_for_real_operation_sequence(tmp_path: Path):
    from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
    from v3_engine.notebooklm.credentials import REQUIRED_COOKIES
    from v3_engine.notebooklm.protocol_bundle import load_local_worker_protocol
    from v3_engine.notebooklm.ports import SourceReceipt, TurnAnswer

    credentials = tmp_path / "storage_state.json"
    credentials.write_text(json.dumps({"cookies": [
        {"name": name, "value": "test-session-value", "domain": ".google.com", "path": "/", "expires": -1}
        for name in sorted(REQUIRED_COOKIES)
    ], "origins": []}), encoding="utf-8")
    credentials.chmod(0o600)
    source = tmp_path / "part.pdf"
    source.write_bytes(b"sealed prepared PDF")

    class TerminalServiceDouble:
        def __init__(self):
            self.calls = []

        async def create_notebook(self, title, storage_path=None):
            self.calls.append(("create", title))
            return SimpleNamespace(id="notebook-1", title=title)

        async def upload_source(self, notebook_id, local_path, mime_type, title, storage_path=None):
            self.calls.append(("upload", notebook_id, local_path.read_bytes(), mime_type, title))
            return SourceReceipt(source_id=f"source-{title}", title=title, status="ready", mime_type=mime_type)

        async def configure_chat(self, notebook_id, format_text, storage_path=None):
            self.calls.append(("configure", notebook_id, format_text))

        async def send_turn(self, notebook_id, question, source_ids, conversation_id=None, storage_path=None):
            self.calls.append(("turn", notebook_id, question, list(source_ids), conversation_id))
            return TurnAnswer(text=f"actual response {len(self.calls)}", conversation_id="conversation-1", turn_number=1)

        async def poll_sources(self, notebook_id, storage_path=None):
            return [SourceReceipt("source-pdf", "pdf", "ready"), SourceReceipt("source-framework", "framework", "ready")]

        async def get_current_conversation_id(self, notebook_id, storage_path=None):
            return "conversation-1"

    service = TerminalServiceDouble()
    adapter = NotebookLMProviderAdapter(credentials_path=credentials, terminal_service=service)

    created = json.loads(adapter.execute_operation("notebook_create", {"title": "Sealed run"}, {}))
    assert created == {"notebook_id": "notebook-1", "title": "Sealed run"}
    uploaded = json.loads(adapter.execute_operation(
        "source_upload",
        {
            "notebook_id": "notebook-1", "artifact_sha256": hashlib.sha256(source.read_bytes()).hexdigest(),
            "mime_type": "application/pdf", "title": "prepared-pdf",
        },
        {"source_path": str(source)},
    ))
    assert uploaded["source_id"] == "source-prepared-pdf"
    protocol = load_local_worker_protocol()
    assert adapter.execute_operation("chat_configure", {"notebook_id": "notebook-1", "format_text": protocol["combined_response_format"]}, {}) == "System Ready"
    adapter.wait_sources_ready("notebook-1", ["source-pdf", "source-framework"])
    assert adapter.current_conversation_id("notebook-1") == "conversation-1"
    assert adapter.execute_operation("divide_map", {"notebook_id": "notebook-1", "source_ids": ["source-pdf"], "prompt": protocol["divide_prompt"]}, {}) == "actual response 4"
    assert adapter.execute_operation("execution_ready", {"notebook_id": "notebook-1", "source_ids": ["source-pdf", "source-framework"], "conversation_id": "conversation-1", "prompt": protocol["execution_prompt"]}, {}) == "actual response 5"
    assert adapter.execute_operation("lesson_bundle", {"notebook_id": "notebook-1", "source_ids": ["source-pdf", "source-framework"], "conversation_id": "conversation-1", "prompt": protocol["lesson_start_prompt"]}, {}) == "actual response 6"

    turns = [call for call in service.calls if call[0] == "turn"]
    assert [call[2] for call in turns] == [DIVIDE_PROMPT, EXECUTION_PROMPT, "Start with First lesson"]
    assert turns[0][3] == ["source-pdf"]
    assert turns[1][4] == turns[2][4] == "conversation-1"


def test_notebooklm_adapter_rejects_source_bytes_changed_after_manifest_seal(tmp_path: Path):
    from v3_engine.notebooklm.credentials import REQUIRED_COOKIES

    credentials = tmp_path / "storage_state.json"
    credentials.write_text(json.dumps({"cookies": [
        {"name": name, "value": "test-session-value", "domain": ".google.com", "path": "/", "expires": -1}
        for name in sorted(REQUIRED_COOKIES)
    ], "origins": []}), encoding="utf-8")
    credentials.chmod(0o600)
    source = tmp_path / "part.pdf"
    source.write_bytes(b"changed bytes")
    service = MagicMock()
    adapter = NotebookLMProviderAdapter(credentials_path=credentials, terminal_service=service)

    with pytest.raises(Exception, match="sealed artifact digest"):
        adapter.execute_operation(
            "source_upload",
            {"notebook_id": "notebook-1", "artifact_sha256": "0" * 64, "mime_type": "application/pdf", "title": "prepared-pdf"},
            {"source_path": str(source)},
        )
    service.upload_source.assert_not_called()


def make_dummy_manifest(task_id: str, job_id: str, run_id: str, artifact_id: str, art_bytes: bytes) -> bytes:
    art_sha = hashlib.sha256(art_bytes).hexdigest()
    original_artifact_id = str(uuid.uuid4())
    original_bytes = b"%PDF-1.4 retained original"
    original_sha = hashlib.sha256(original_bytes).hexdigest()
    settings_id = str(uuid.uuid4())
    settings_sha = "a" * 64
    runtime_id = str(uuid.uuid4())
    runtime_bytes = json.dumps({
        "schema_version": 1,
        "settings_version_id": settings_id,
        "settings_sha256": settings_sha,
        "execution_mode": "local_worker_v1",
        "output_scope": "prompts_only",
        "captured_settings": {"limits": {}, "qualification": {}, "providers": {}, "templates": {}},
    }).encode()
    runtime_sha = hashlib.sha256(runtime_bytes).hexdigest()
    framework_id = str(uuid.uuid4())
    framework_bytes = b"Approved framework body"
    framework_sha = hashlib.sha256(framework_bytes).hexdigest()
    protocol_artifact_id = str(uuid.uuid4())
    protocol_bytes = (Path(__file__).resolve().parents[2] / "notebooklm" / "protocols" / "local_worker_v1.json").read_bytes()
    protocol_sha = hashlib.sha256(protocol_bytes).hexdigest()
    manifest = {
        "schema_version": 1,
        "kind": "local_worker_input_manifest",
        "task_id": task_id,
        "job_id": job_id,
        "run_id": run_id,
        "task_kind": "author_prompts",
        "execution_mode": "local_worker_v1",
        "output_scope": "prompts_only",
        "protocol_id": "notebooklm_v1",
        "protocol_version": "notebooklm_per_pdf_web_v1",
        "settings_snapshot_id": settings_id,
        "settings_sha256": settings_sha,
        "runtime_settings_artifact_id": runtime_id,
        "runtime_settings_sha256": runtime_sha,
        "preparation": {
            "policy_version": "v1",
            "trigger_bytes": 1000,
            "max_bytes": 5000,
            "inventory_artifact_id": str(uuid.uuid4()),
            "inventory_sha256": "b" * 64,
            "original_to_part_lineage": [],
        },
        "items": [
            {
                "item_id": str(uuid.uuid4()),
                "original_artifact_id": original_artifact_id,
                "original_sha256": original_sha,
                "original_byte_count": len(original_bytes),
                "original_page_count": 1,
                "upload_ordinal": 1,
                "source_ordinal": 1,
                "part_ordinal": 1,
                "original_page_start": 1,
                "original_page_end": 1,
                "prepared_artifact_id": artifact_id,
                "prepared_sha256": art_sha,
                "prepared_byte_count": len(art_bytes),
                "prepared_page_count": 1,
            }
        ],
        "instruction_artifacts": [
            {"name": "framework", "version": "1", "artifact_id": framework_id,
             "sha256": framework_sha, "size_bytes": len(framework_bytes)},
            {"name": "notebooklm_protocol", "version": "notebooklm_per_pdf_web_v1",
             "artifact_id": protocol_artifact_id, "sha256": protocol_sha, "size_bytes": len(protocol_bytes)},
        ],
        "artifacts": [
            {
                "artifact_id": runtime_id, "purpose": "runtime_settings", "size_bytes": len(runtime_bytes),
                "digest_sha256": runtime_sha, "media_type": "application/json", "logical_name": "runtime_settings.json",
            },
            {
                "artifact_id": framework_id, "purpose": "framework_body", "size_bytes": len(framework_bytes),
                "digest_sha256": framework_sha, "media_type": "text/plain", "logical_name": "framework.txt",
            },
            {
                "artifact_id": protocol_artifact_id, "purpose": "instruction_bundle", "size_bytes": len(protocol_bytes),
                "digest_sha256": protocol_sha, "media_type": "application/json", "logical_name": "notebooklm_protocol_v1.json",
            },
            {
                "artifact_id": artifact_id,
                "purpose": "source_pdf",
                "size_bytes": len(art_bytes),
                "digest_sha256": art_sha,
                "media_type": "application/pdf",
                "logical_name": "part1.pdf",
            },
            {
                "artifact_id": original_artifact_id,
                "purpose": "original_pdf",
                "size_bytes": len(original_bytes),
                "digest_sha256": original_sha,
                "media_type": "application/pdf",
                "logical_name": "original.pdf",
            },
        ],
        "required_outputs": ["prompts"],
        "resource_budget": {"max_effects": 10},
        "capabilities": ["notebooklm_authoring_v1"],
    }
    return json.dumps(manifest).encode("utf-8")


def test_stage_context_resolve_and_download(tmp_path: Path):
    client = MagicMock()
    task_id = str(uuid.uuid4())
    job_id = str(uuid.uuid4())
    run_id = str(uuid.uuid4())
    art_id = str(uuid.uuid4())
    art_bytes = b"%PDF-1.4 sample content"

    manifest_bytes = make_dummy_manifest(task_id, job_id, run_id, art_id, art_bytes)
    manifest_sha = hashlib.sha256(manifest_bytes).hexdigest()

    claim_data = {
        "task_id": task_id,
        "job_id": job_id,
        "run_id": run_id,
        "task_kind": "author_prompts",
        "assignment_id": str(uuid.uuid4()),
        "assignment_epoch": 1,
        "control_version": 1,
        "input_manifest_sha256": manifest_sha,
    }

    client.get_input_manifest.return_value = manifest_bytes
    manifest_doc = json.loads(manifest_bytes)
    runtime_bytes = json.dumps({
        "schema_version": 1,
        "settings_version_id": manifest_doc["settings_snapshot_id"],
        "settings_sha256": manifest_doc["settings_sha256"],
        "execution_mode": manifest_doc["execution_mode"],
        "output_scope": manifest_doc["output_scope"],
        "captured_settings": {"limits": {}, "qualification": {}, "providers": {}, "templates": {}},
    }).encode()
    artifact_content = {
        art_id: art_bytes,
        manifest_doc["runtime_settings_artifact_id"]: runtime_bytes,
        manifest_doc["instruction_artifacts"][0]["artifact_id"]: b"Approved framework body",
        manifest_doc["instruction_artifacts"][1]["artifact_id"]: (Path(__file__).resolve().parents[2] / "notebooklm" / "protocols" / "local_worker_v1.json").read_bytes(),
    }
    def download_side_effect(t_id, a_id, target_path, *_assignment_fence):
        target_path.write_bytes(artifact_content[a_id])
        return target_path

    client.download_input_artifact.side_effect = download_side_effect

    ctx = StageContext.resolve_and_download(client, claim_data, tmp_path)
    assert ctx.task_id == task_id
    assert ctx.protocol_bundle["protocol_version"] == "notebooklm_per_pdf_web_v1"
    assert (ctx.inputs_dir / "part1.pdf").read_bytes() == art_bytes
    assert ctx.runtime_settings["settings_version_id"] == manifest_doc["settings_snapshot_id"]
    assert ctx.instruction_texts["framework"] == "Approved framework body"
    assert client.download_input_artifact.call_count == 4
    assert set(call.args[1] for call in client.download_input_artifact.call_args_list) == set(artifact_content)


def test_stage_context_digest_mismatch_fails(tmp_path: Path):
    client = MagicMock()
    task_id = str(uuid.uuid4())
    art_id = str(uuid.uuid4())
    manifest_bytes = make_dummy_manifest(task_id, str(uuid.uuid4()), str(uuid.uuid4()), art_id, b"pdf")
    client.get_input_manifest.return_value = manifest_bytes

    claim_data = {
        "task_id": task_id,
        "job_id": str(uuid.uuid4()),
        "run_id": str(uuid.uuid4()),
        "task_kind": "author_prompts",
        "assignment_id": str(uuid.uuid4()),
        "assignment_epoch": 1,
        "control_version": 1,
        "input_manifest_sha256": "f" * 64,  # wrong digest
    }

    with pytest.raises(LocalWorkerProtocolError, match="Input manifest digest mismatch"):
        StageContext.resolve_and_download(client, claim_data, tmp_path)


@pytest.mark.parametrize('new_assignment', [False, True])
def test_authoring_restart_replays_saved_result_without_redownloading_or_regenerating(tmp_path: Path, new_assignment):
    client = MagicMock()
    task_id, job_id, run_id, assignment_id = [str(uuid.uuid4()) for _ in range(4)]
    prompt = "A saved scene prompt"
    claim = {
        "task_id": task_id,
        "job_id": job_id,
        "run_id": run_id,
        "task_kind": "author_prompts",
        "assignment_id": assignment_id,
        "assignment_epoch": 2,
        "input_manifest_sha256": "a" * 64,
    }
    payload = {
        "schema_version": 1,
        "kind": "local_worker_authoring_result",
        "result_id": str(uuid.uuid4()),
        "task_id": task_id,
        "job_id": job_id,
        "run_id": run_id,
        "assignment_id": assignment_id,
        "assignment_epoch": 2,
        "input_manifest_sha256": "a" * 64,
        "protocol_version": "direct_v1",
        "settings_sha256": "b" * 64,
        "preparation_sha256": "c" * 64,
        "artifact_references": [],
        "lessons": [{
            "item_id": str(uuid.uuid4()),
            "lesson_ordinal": 0,
            "lesson_title": "Lesson",
            "raw_response_artifact_id": str(uuid.uuid4()),
            "raw_response_sha256": "d" * 64,
            "scenes": [{
                "scene_ordinal": 0,
                "scene_title": "Scene",
                "prompt_text": prompt,
                "prompt_sha256": hashlib.sha256(prompt.encode()).hexdigest(),
                "generation_context": {"template_version": "v1", "scene_type": "simulation"},
            }],
        }],
        "archive_inventory": [],
        "metadata": {"worker_version": "1.0.0", "worker_process_id": "test", "produced_at": 1.0},
    }
    task_dir = tmp_path / "tasks" / task_id
    task_dir.mkdir(parents=True)
    (task_dir / "result_manifest.json").write_text(json.dumps(payload), encoding="utf-8")
    expected_payload = json.loads(json.dumps(payload))
    if new_assignment:
        assignment_id = str(uuid.uuid4())
        claim.update(assignment_id=assignment_id, assignment_epoch=3, assignment_status='active', task_status='leased')
        expected_payload.update(assignment_id=assignment_id, assignment_epoch=3)
    client.submit_result.return_value = {"result_id": payload["result_id"], "status": "accepted"}
    runner = TaskRunner(client, LocalEffectJournal(tmp_path / "effects.sqlite"), tmp_path)

    receipt = runner.run_authoring_stage(claim)

    assert receipt["result_id"] == payload["result_id"]
    client.submit_result.assert_called_once_with(
        task_id=task_id,
        result_payload=expected_payload,
        assignment_id=assignment_id,
        assignment_epoch=claim['assignment_epoch'],
    )
    client.get_input_manifest.assert_not_called()


def test_saved_result_with_other_assignment_fails_closed(tmp_path: Path):
    client = MagicMock()
    task_id, job_id, run_id = [str(uuid.uuid4()) for _ in range(3)]
    claim = {
        "task_id": task_id,
        "job_id": job_id,
        "run_id": run_id,
        "task_kind": "author_prompts",
        "assignment_id": str(uuid.uuid4()),
        "assignment_epoch": 1,
        "input_manifest_sha256": "a" * 64,
    }
    task_dir = tmp_path / "tasks" / task_id
    task_dir.mkdir(parents=True)
    (task_dir / "result_manifest.json").write_text(json.dumps({"kind": "local_worker_authoring_result"}), encoding="utf-8")
    runner = TaskRunner(client, LocalEffectJournal(tmp_path / "effects.sqlite"), tmp_path)

    with pytest.raises(LocalWorkerProtocolError, match="Saved result"):
        runner.run_authoring_stage(claim)
    client.submit_result.assert_not_called()


def test_scene_return_requires_operator_provenance_before_network_access(tmp_path: Path):
    client = MagicMock()
    claim = {
        "task_id": str(uuid.uuid4()),
        "job_id": str(uuid.uuid4()),
        "run_id": str(uuid.uuid4()),
        "task_kind": "return_scenes",
        "assignment_id": str(uuid.uuid4()),
        "assignment_epoch": 1,
        "input_manifest_sha256": "a" * 64,
        "device_id": str(uuid.uuid4()),
    }
    runner = TaskRunner(client, LocalEffectJournal(tmp_path / "effects.sqlite"), tmp_path)

    with pytest.raises(LocalWorkerProtocolError, match="explicit operator provenance"):
        runner.run_scene_return_stage(claim)
    client.get_input_manifest.assert_not_called()
    client.create_upload_slot.assert_not_called()


def test_runner_presend_dispatch_fence(tmp_path: Path):
    client = MagicMock()
    journal = LocalEffectJournal(tmp_path / "journal.sqlite")
    runner = TaskRunner(client=client, journal=journal, workspace_dir=tmp_path)

    task_id = str(uuid.uuid4())
    ctx = StageContext(
        task_id=task_id,
        job_id=str(uuid.uuid4()),
        run_id=str(uuid.uuid4()),
        task_kind="author_prompts",
        assignment_id=str(uuid.uuid4()),
        assignment_epoch=1,
        control_version=1,
        manifest={},
        manifest_sha256="0" * 64,
        workspace_dir=tmp_path,
    )

    hb = MagicMock()
    hb.is_lease_healthy.return_value = True

    client.reserve_effect.return_value = {"effect_id": "server-eff-1"}
    client.dispatch_effect.return_value = {
        "permit_id": "permit-1",
        "attempt_id": "attempt-1",
        "attempt_number": 1,
        "send_not_after": "2030-01-01T00:00:00Z",
    }
    client.create_upload_slot.return_value = {"upload_id": "up-1"}
    client.upload_bytes.return_value = {"status": "verified"}

    res = runner.execute_effect(
        ctx=ctx,
        hb=hb,
        operation_kind="divide_map",
        logical_effect_key="job-1:divide:item-1",
        request_payload={"item_id": "item-1"},
        inputs={},
    )

    assert res["effect_id"] == "server-eff-1"
    assert "Chapter 1" in res["raw_text"]
    worker_process_id = client.dispatch_effect.call_args.kwargs["worker_process_id"]
    assert worker_process_id == runner.process_incarnation_id
    assert str(uuid.UUID(worker_process_id)) == worker_process_id
    restarted_runner = TaskRunner(client=client, journal=journal, workspace_dir=tmp_path)
    assert restarted_runner.process_incarnation_id != worker_process_id

    # Verify journal recorded succeeded
    eff = journal.get_effect("server-eff-1")
    assert eff.status == "succeeded"


def test_runner_lease_unhealthy_aborts_dispatch(tmp_path: Path):
    client = MagicMock()
    journal = LocalEffectJournal(tmp_path / "journal.sqlite")
    runner = TaskRunner(client=client, journal=journal, workspace_dir=tmp_path)

    ctx = StageContext(
        task_id=str(uuid.uuid4()),
        job_id=str(uuid.uuid4()),
        run_id=str(uuid.uuid4()),
        task_kind="author_prompts",
        assignment_id=str(uuid.uuid4()),
        assignment_epoch=1,
        control_version=1,
        manifest={},
        manifest_sha256="0" * 64,
        workspace_dir=tmp_path,
    )

    hb = MagicMock()
    hb.is_lease_healthy.return_value = False
    hb.is_paused = False
    hb.is_cancelled = False

    with pytest.raises(LeaseExpiredError):
        runner.execute_effect(ctx, hb, "divide_map", "key-1", {}, {})


def test_runner_reconciliation_required_on_may_have_been_sent(tmp_path: Path):
    client = MagicMock()
    journal = LocalEffectJournal(tmp_path / "journal.sqlite")
    runner = TaskRunner(client=client, journal=journal, workspace_dir=tmp_path)

    task_id = str(uuid.uuid4())
    ctx = StageContext(
        task_id=task_id,
        job_id=str(uuid.uuid4()),
        run_id=str(uuid.uuid4()),
        task_kind="author_prompts",
        assignment_id=str(uuid.uuid4()),
        assignment_epoch=1,
        control_version=1,
        manifest={},
        manifest_sha256="0" * 64,
        workspace_dir=tmp_path,
    )

    # Seed an effect in crash state
    journal.record_intent("eff-crashed", task_id, "crash-key", "divide_map", "sha1")
    journal.record_permit("att-crashed", "eff-crashed", 1, "perm-1", "2030-01-01T00:00:00Z")
    journal.mark_may_have_been_sent("att-crashed")

    hb = MagicMock()
    hb.is_lease_healthy.return_value = True

    # Re-running same logical key must raise ReconciliationRequiredError
    with pytest.raises(ReconciliationRequiredError):
        runner.execute_effect(ctx, hb, "divide_map", "crash-key", {}, {})


def test_runner_reuses_server_confirmed_effect_after_restart_without_provider_dispatch(tmp_path: Path):
    client = MagicMock()
    journal = LocalEffectJournal(tmp_path / "journal.sqlite")
    provider = MagicMock()
    runner = TaskRunner(client=client, journal=journal, workspace_dir=tmp_path, provider_adapter=provider)
    task_id, job_id, run_id, assignment_id = [str(uuid.uuid4()) for _ in range(4)]
    effect_id = str(uuid.uuid4())
    logical_key = f"{job_id}:author_prompts:divide:item-1"
    request = {"item_id": "item-1", "operation": "divide_map"}
    request_digest = hashlib.sha256(json.dumps(request, sort_keys=True).encode("utf-8")).hexdigest()
    journal.record_intent(effect_id, task_id, logical_key, "divide_map", request_digest)
    journal.record_permit("attempt-one", effect_id, 1, "permit-one", "2030-01-01T00:00:00Z")
    response = "Chapter 1: Fluids\nLesson 1: Flow"
    response_path = tmp_path / "tasks" / task_id / "artifacts" / f"{effect_id}.raw.txt"
    response_path.parent.mkdir(parents=True)
    response_path.write_text(response, encoding="utf-8")
    journal.record_outcome("attempt-one", "succeeded", outcome_ref=effect_id, raw_response_path=str(response_path))
    client.reserve_effect.return_value = {"effect_id": effect_id, "status": "succeeded"}
    client.create_upload_slot.return_value = {"status": "uploaded"}
    ctx = StageContext(
        task_id=task_id, job_id=job_id, run_id=run_id, task_kind="author_prompts",
        assignment_id=assignment_id, assignment_epoch=1, control_version=1,
        manifest={}, manifest_sha256="a" * 64, workspace_dir=tmp_path,
    )
    heartbeat = MagicMock()
    heartbeat.is_lease_healthy.return_value = True

    result = runner.execute_effect(ctx, heartbeat, "divide_map", logical_key, request, {"item_id": "item-1"})

    assert result["raw_text"] == response
    assert result["raw_sha256"] == hashlib.sha256(response.encode()).hexdigest()
    assert result["raw_artifact_id"] == client.create_upload_slot.call_args.kwargs["artifact_id"]
    assert provider.execute_operation.call_count == 0
    client.dispatch_effect.assert_not_called()
    client.upload_bytes.assert_not_called()


def test_runner_auth_required_halts_without_fake_result(tmp_path: Path):
    client = MagicMock()
    journal = LocalEffectJournal(tmp_path / "journal.sqlite")

    mock_provider = MagicMock()
    mock_provider.execute_operation.side_effect = AuthenticationRequiredError("Session expired")

    runner = TaskRunner(client=client, journal=journal, workspace_dir=tmp_path, provider_adapter=mock_provider)

    task_id = str(uuid.uuid4())
    ctx = StageContext(
        task_id=task_id,
        job_id=str(uuid.uuid4()),
        run_id=str(uuid.uuid4()),
        task_kind="author_prompts",
        assignment_id=str(uuid.uuid4()),
        assignment_epoch=1,
        control_version=1,
        manifest={},
        manifest_sha256="0" * 64,
        workspace_dir=tmp_path,
    )

    hb = MagicMock()
    hb.is_lease_healthy.return_value = True

    client.reserve_effect.return_value = {"effect_id": "eff-auth"}
    client.dispatch_effect.return_value = {
        "permit_id": "perm-auth",
        "attempt_id": "att-auth",
        "attempt_number": 1,
        "send_not_after": "2030-01-01T00:00:00Z",
    }

    with pytest.raises(AuthenticationRequiredError):
        runner.execute_effect(ctx, hb, "divide_map", "key-auth", {}, {})

    # Journal marked authentication_required, not succeeded!
    eff = journal.get_effect("eff-auth")
    assert eff.status == "authentication_required"


def test_runner_drain_stops_before_reserving_the_next_provider_effect(tmp_path: Path):
    client = MagicMock()
    journal = LocalEffectJournal(tmp_path / "journal.sqlite")
    runner = TaskRunner(client=client, journal=journal, workspace_dir=tmp_path)
    task_id = str(uuid.uuid4())
    ctx = StageContext(
        task_id=task_id,
        job_id=str(uuid.uuid4()),
        run_id=str(uuid.uuid4()),
        task_kind="author_prompts",
        assignment_id=str(uuid.uuid4()),
        assignment_epoch=1,
        control_version=1,
        manifest={},
        manifest_sha256="a" * 64,
        workspace_dir=tmp_path,
    )

    with pytest.raises(RuntimeError, match="Drain requested"):
        runner.execute_effect(
            ctx,
            None,
            "divide_map",
            "next-pdf-divide-map",
            {},
            {},
            drain_check=lambda: True,
        )

    client.reserve_effect.assert_not_called()
    assert journal.list_pending_effects(task_id) == []
