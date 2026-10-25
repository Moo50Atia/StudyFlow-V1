"""Tests for the local-worker CLI."""

import hashlib
import json
from pathlib import Path
import pytest
from unittest.mock import MagicMock, patch

from v3_engine.local_worker.cli import (
    cmd_doctor,
    cmd_export_prompts,
    cmd_import_scenes,
    cmd_run,
    cmd_status,
    cmd_stop,
)
from v3_engine.local_worker.config import WorkerConfig, save_config
from v3_engine.local_worker.protocol import LocalWorkerProtocolError
from v3_engine.local_worker.terminal import TerminalPipelineAdapter
from types import SimpleNamespace
import uuid


def test_cli_doctor_healthy(tmp_path: Path, capsys):
    config_path = tmp_path / "config.json"
    ws = tmp_path / "workspace"
    config = WorkerConfig(
        server_origin="http://127.0.0.1:8000",
        device_id="dev-1",
        device_token="tok-1",
        workspace_dir=str(ws),
        capabilities=["notebooklm_authoring_v1"],
    )
    save_config(config, config_path)

    args = MagicMock()
    args.config = str(config_path)
    args.check_provider_live = False

    with patch("v3_engine.local_worker.cli.LocalWorkerHttpClient") as MockClient:
        instance = MockClient.return_value
        instance.get_device.return_value = {"server_time": "2026-10-18T12:00:00Z"}

        code = cmd_doctor(args)
        assert code == 0

    captured = capsys.readouterr()
    data = json.loads(captured.out)
    assert data["healthy"] is True
    assert data["checks"]["config_file"]["status"] == "ok"
    assert data["checks"]["workspace"]["status"] == "ok"
    assert data["checks"]["server_connection"]["status"] == "ok"


def test_cli_production_backend_uses_the_existing_terminal_pipeline(tmp_path):
    template = tmp_path / 'terminal.json'
    template.write_text(json.dumps({'subjects': [{'mode': 'new', 'provider': 'gemini',
        'model': 'gemini-2.5-flash', 'account_check_notebook_id': 'existing-proof'}]}))
    config = tmp_path / 'config.json'
    save_config(WorkerConfig('http://127.0.0.1:8000', 'device', 'test-token', str(tmp_path / 'workspace'),
                             ['notebooklm_authoring_v1'], str(template)), config)
    args = SimpleNamespace(config=str(config), offline_double=False, terminal_manifest=None,
                            once=True, poll_interval=0)
    with patch('v3_engine.local_worker.cli.LocalWorkerHttpClient') as Client, \
         patch('v3_engine.local_worker.cli.TaskRunner') as Runner:
        Client.return_value.claim.return_value = None
        assert cmd_run(args) == 0
        assert isinstance(Runner.call_args.kwargs['provider_adapter'], TerminalPipelineAdapter)


def test_cli_premature_pull_releases_task_and_continues_other_work(tmp_path):
    config = tmp_path / 'config.json'
    workspace = tmp_path / 'workspace'
    save_config(WorkerConfig('http://127.0.0.1:8000', 'device', 'test-token', str(workspace),
                             ['scene_return_v1']), config)
    claim = {'task_id': str(uuid.uuid4()), 'task_kind': 'return_scenes',
             'assignment_id': str(uuid.uuid4()), 'assignment_epoch': 1, 'platform_owner_id': 7}
    args = SimpleNamespace(config=str(config), offline_double=True, once=True, poll_interval=0,
                            operator_id=None, provider=None, model=None, effort=None)
    with patch('v3_engine.local_worker.cli.LocalWorkerHttpClient') as Client, \
         patch('v3_engine.local_worker.cli.TaskRunner') as Runner:
        Client.return_value.claim.return_value = claim
        Runner.return_value.run_scene_return_stage.side_effect = LocalWorkerProtocolError('Files missing', code='missing_packages')
        assert cmd_run(args) == 0
        Client.return_value.release.assert_called_once_with(claim['task_id'], reason='manual_scenes_missing',
            assignment_id=claim['assignment_id'], assignment_epoch=1)
    assert not (workspace / 'pending_claim.json').exists()


def test_cli_status(tmp_path: Path, capsys):
    config_path = tmp_path / "config.json"
    ws = tmp_path / "workspace"
    config = WorkerConfig(
        server_origin="http://127.0.0.1:8000",
        device_id="dev-123",
        device_token="tok-123",
        workspace_dir=str(ws),
        capabilities=["notebooklm_authoring_v1"],
    )
    save_config(config, config_path)

    args = MagicMock()
    args.config = str(config_path)

    code = cmd_status(args)
    assert code == 0

    captured = capsys.readouterr()
    data = json.loads(captured.out)
    assert data["device_id"] == "dev-123"
    assert data["server_origin"] == "http://127.0.0.1:8000"
    assert data["daemon_running"] is False


def test_cli_stop_drain(tmp_path: Path, capsys):
    config_path = tmp_path / "config.json"
    ws = tmp_path / "workspace"
    ws.mkdir(parents=True, exist_ok=True)
    config = WorkerConfig(
        server_origin="http://127.0.0.1:8000",
        device_id="dev-1",
        device_token="tok-1",
        workspace_dir=str(ws),
        capabilities=["notebooklm_authoring_v1"],
    )
    save_config(config, config_path)

    args = MagicMock()
    args.config = str(config_path)

    code = cmd_stop(args)
    assert code == 0
    assert (ws / "drain.signal").is_file()


def test_cli_preserves_claim_after_execution_error_without_releasing(tmp_path: Path, capsys):
    config_path = tmp_path / "config.json"
    workspace = tmp_path / "workspace"
    save_config(WorkerConfig(
        server_origin="http://127.0.0.1:8000",
        device_id="dev-1",
        device_token="tok-1",
        workspace_dir=str(workspace),
        capabilities=["notebooklm_authoring_v1"],
    ), config_path)
    claim = {
        "task_id": "task-1",
        "task_kind": "author_prompts",
        "assignment_id": "assignment-1",
        "assignment_epoch": 1,
    }
    args = MagicMock()
    args.config = str(config_path)
    args.offline_double = True
    args.once = True
    args.poll_interval = 0

    with patch("v3_engine.local_worker.cli.LocalWorkerHttpClient") as MockClient, \
         patch("v3_engine.local_worker.cli.TaskRunner.run_authoring_stage", side_effect=ValueError("ambiguous submit")):
        client = MockClient.return_value
        client.claim.return_value = claim
        assert cmd_run(args) == 2
        client.release.assert_not_called()

    pending = json.loads((workspace / "pending_claim.json").read_text(encoding="utf-8"))
    assert pending["claim_id"]


def test_cli_export_prompts(tmp_path: Path, capsys):
    config_path = tmp_path / "config.json"
    ws = tmp_path / "workspace"
    jobs_dir = ws / "jobs"
    job_id = "11111111-1111-1111-1111-111111111111"
    prompt_text = "Accepted server prompt for Fluid Velocity Distribution."
    prompt_sha = hashlib.sha256(prompt_text.encode()).hexdigest()
    job1_dir = jobs_dir / job_id
    job1_dir.mkdir(parents=True, exist_ok=True)

    # Job 101 authoring result
    job1_result = {
        "job_id": job_id,
        "lessons": [
            {
                "item_id": "item-1",
                "lesson_ordinal": 0,
                "lesson_title": "Lesson 1",
                "scenes": [
                    {
                        "scene_ordinal": 0,
                        "scene_title": "Fluid Velocity Distribution",
                        "prompt_text": "Interactive visualization of fluid velocity profile.",
                        "prompt_sha256": "a" * 64,
                    }
                ],
            }
        ],
    }
    (job1_dir / "authoring_result.json").write_text(json.dumps(job1_result), encoding="utf-8")

    scene_manifest = {
        "schema_version": 1,
        "kind": "local_worker_input_manifest",
        "task_id": "22222222-2222-2222-2222-222222222222",
        "job_id": job_id,
        "run_id": "33333333-3333-3333-3333-333333333333",
        "task_kind": "return_scenes",
        "execution_mode": "local_worker_v1",
        "output_scope": "prompts_and_scenes",
        "protocol_id": "notebooklm_v1",
        "protocol_version": "notebooklm_per_pdf_web_v1",
        "settings_snapshot_id": "44444444-4444-4444-4444-444444444444",
        "settings_sha256": "a" * 64,
        "runtime_settings_artifact_id": "55555555-5555-5555-5555-555555555555",
        "runtime_settings_sha256": "b" * 64,
        "preparation": {
            "policy_version": "v1",
            "trigger_pages": 50,
            "max_part_pages": 50,
            "inventory_artifact_id": "66666666-6666-6666-6666-666666666666",
            "inventory_sha256": "c" * 64,
        },
        "items": [{
            "item_id": "77777777-7777-7777-7777-777777777777",
            "original_artifact_id": "88888888-8888-8888-8888-888888888888",
            "original_sha256": "d" * 64,
            "original_byte_count": 100,
            "original_page_count": 1,
            "upload_ordinal": 1,
            "source_ordinal": 1,
            "part_ordinal": 1,
            "original_page_start": 1,
            "original_page_end": 1,
            "prepared_artifact_id": "99999999-9999-9999-9999-999999999999",
            "prepared_sha256": "e" * 64,
            "prepared_byte_count": 100,
            "prepared_page_count": 1,
        }],
        "instruction_artifacts": [],
        "artifacts": [],
        "required_outputs": {"profile": "static_three_file_v1", "format": "static_three_file_package"},
        "resource_budget": {"max_provider_effects": 1, "max_output_bytes": 1000, "timeout_seconds": 30},
        "capabilities": ["scene_return_v1", "static_three_file_v1"],
        "required_scenes": [{
            "scene_ordinal": 0,
            "scene_id": "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
            "scene_title": "Fluid Velocity Distribution",
            "prompt_version_id": "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb",
            "prompt_text": prompt_text,
            "prompt_sha256": prompt_sha,
        }],
    }
    manifest_bytes = json.dumps(scene_manifest, separators=(",", ":")).encode()
    (job1_dir / "scene_return_manifest.json").write_bytes(manifest_bytes)
    (job1_dir / "scene_return_manifest.sha256").write_text(hashlib.sha256(manifest_bytes).hexdigest() + "\n")

    # Another job (job-999) that must NOT be exported
    job2_dir = jobs_dir / "99999999-9999-9999-9999-999999999998"
    job2_dir.mkdir(parents=True, exist_ok=True)
    job2_result = {
        "job_id": "99999999-9999-9999-9999-999999999998",
        "lessons": [
            {
                "item_id": "item-2",
                "lesson_ordinal": 0,
                "lesson_title": "Other Job Lesson",
                "scenes": [
                    {
                        "scene_ordinal": 0,
                        "scene_title": "Other Job Scene",
                        "prompt_text": "Prompt from different job.",
                        "prompt_sha256": "b" * 64,
                    }
                ],
            }
        ],
    }
    (job2_dir / "authoring_result.json").write_text(json.dumps(job2_result), encoding="utf-8")

    config = WorkerConfig(
        server_origin="http://127.0.0.1:8000",
        device_id="dev-1",
        device_token="tok-1",
        workspace_dir=str(ws),
        capabilities=["notebooklm_authoring_v1"],
    )
    save_config(config, config_path)

    out_dir = tmp_path / "prompts_out"
    args = MagicMock()
    args.job = job_id
    args.output = str(out_dir)
    args.config = str(config_path)

    code = cmd_export_prompts(args)
    assert code == 0
    assert (out_dir / "scene_001.prompt.txt").is_file()
    assert (out_dir / "scene_001.prompt.txt").read_text() == prompt_text
    assert (out_dir / "prompts_manifest.json").is_file()

    # Verify no cross-job prompt was exported
    manifest = json.loads((out_dir / "prompts_manifest.json").read_text())
    assert manifest["job_id"] == job_id
    assert manifest["exported_files_count"] == 1
    assert "different job" not in (out_dir / "scene_001.prompt.txt").read_text()


def test_cli_export_does_not_export_unaccepted_local_authoring_checkpoint(tmp_path: Path, capsys):
    config_path = tmp_path / "config.json"
    workspace = tmp_path / "workspace"
    job_id = "11111111-1111-1111-1111-111111111111"
    job_dir = workspace / "jobs" / job_id
    job_dir.mkdir(parents=True)
    (job_dir / "authoring_result.json").write_text(json.dumps({
        "job_id": job_id,
        "lessons": [{"scenes": [{"prompt_text": "Unaccepted checkpoint", "scene_ordinal": 0}]}],
    }), encoding="utf-8")
    save_config(WorkerConfig(
        server_origin="http://127.0.0.1:8000",
        device_id="dev-1",
        device_token="tok-1",
        workspace_dir=str(workspace),
        capabilities=["notebooklm_authoring_v1"],
    ), config_path)
    args = MagicMock(job=job_id, output=str(tmp_path / "prompts"), config=str(config_path))

    assert cmd_export_prompts(args) == 1
    assert not list((tmp_path / "prompts").glob("*.prompt.txt"))
    assert "not authorized yet" in capsys.readouterr().err


def test_cli_import_scenes(tmp_path: Path, capsys):
    scenes_in = tmp_path / "scenes_in"
    scene1_dir = scenes_in / "scene_001"
    scene1_dir.mkdir(parents=True, exist_ok=True)

    (scene1_dir / "index.html").write_text("<!DOCTYPE html><html><body>Visual</body></html>")
    (scene1_dir / "styles.css").write_text("body { background: #000; }")
    (scene1_dir / "script.js").write_text("console.log('init');")

    ws = tmp_path / "ws"
    config_path = tmp_path / "config.json"
    config = WorkerConfig(
        server_origin="http://127.0.0.1:8000",
        device_id="dev-1",
        device_token="tok-1",
        workspace_dir=str(ws),
        capabilities=["scene_return_v1"],
    )
    save_config(config, config_path)

    args = MagicMock()
    args.job = "job-101"
    args.input = str(scenes_in)
    args.config = str(config_path)

    code = cmd_import_scenes(args)
    assert code == 0

    captured = capsys.readouterr()
    data = json.loads(captured.out)
    assert data["status"] == "imported_and_verified"
    assert data["valid_packages_found"] == 1

    # Verify staged files in workspace for genuine return (LW-R22)
    staged_pkg = ws / "scenes" / "job-101" / "scene_001"
    assert staged_pkg.is_dir()
    assert (staged_pkg / "index.html").read_text() == "<!DOCTYPE html><html><body>Visual</body></html>"
    assert (staged_pkg / "styles.css").read_text() == "body { background: #000; }"
    assert (staged_pkg / "script.js").read_text() == "console.log('init');"


def test_owner_requested_recovery_is_collected_once_without_resuming(tmp_path):
    from v3_engine.local_worker.cli import collect_requested_recovery
    client, runner = MagicMock(), MagicMock()
    client.get_assignment.return_value = {'assignment_status': 'expired'}
    assert not collect_requested_recovery(client, runner, tmp_path, 'task')
    runner.reconcile_task.assert_not_called()
    client.get_assignment.return_value = {'assignment_status': 'reconciliation_only',
                                           'assignment_id': 'owner-assignment', 'assignment_epoch': 2}
    assert collect_requested_recovery(client, runner, tmp_path, 'task')
    assert not collect_requested_recovery(client, runner, tmp_path, 'task')
    runner.reconcile_task.assert_called_once_with('task')
    runner.run_authoring_stage.assert_not_called()
    client.get_assignment.return_value['assignment_epoch'] = 3
    assert collect_requested_recovery(client, runner, tmp_path, 'task')
    assert runner.reconcile_task.call_count == 2
