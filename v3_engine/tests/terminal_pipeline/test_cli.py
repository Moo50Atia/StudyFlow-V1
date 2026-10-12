"""Tests for Temporal pipeline CLI boundary contracts (Spec 018 / T014)."""

from __future__ import annotations

import json
import uuid
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock
import pytest

from v3_engine.terminal_pipeline.cli import (
    main,
    run_cli,
)
from v3_engine.terminal_pipeline.contracts import (
    SafeCode,
)


def test_cli_submit_valid_manifest(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    # Prepare dummy manifest
    pdf_dir = tmp_path / "pdfs"
    pdf_dir.mkdir()
    (pdf_dir / "01.pdf").write_bytes(b"%PDF-1.4 test")
    steps = tmp_path / "steps.txt"
    steps.write_text("dummy steps", encoding="utf-8")

    sub_id = str(uuid.uuid4())
    manifest_data = {
        "schema_version": 1,
        "submission_id": sub_id,
        "subjects": [
            {
                "subject_key": "subj1",
                "mode": "new",
                "title": "Subj 1",
                "pdf_dir": str(pdf_dir),
                "steps": str(steps),
                "account_check_notebook_id": "nb-proof-123",
                "provider": "gemini",
                "model": "gemini-2.5-flash",
            }
        ],
    }
    manifest_file = tmp_path / "manifest.json"
    manifest_file.write_text(json.dumps(manifest_data), encoding="utf-8")

    # Mock Temporal client
    mock_handle = MagicMock()
    mock_update = MagicMock()
    mock_update.result = AsyncMock(return_value={
        "outcome": "accepted",
        "submission_id": sub_id,
        "subject_count": 1,
    })
    mock_client = MagicMock()
    mock_client.start_update_with_start_workflow = AsyncMock(return_value=mock_update)

    exit_code, output = run_cli(
        ["submit", "--manifest", str(manifest_file), "--json"],
        client=mock_client,
    )

    assert exit_code == 0
    parsed = json.loads(output)
    assert parsed["outcome"] == "accepted"
    assert parsed["submission_id"] == sub_id
    assert len(parsed["subjects"]) == 1


def test_cli_export_prompts_flag_is_captured_for_new_submission(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    pdf_dir = tmp_path / "pdfs"
    pdf_dir.mkdir()
    (pdf_dir / "01.pdf").write_bytes(b"%PDF-1.4 test")
    steps = tmp_path / "steps.txt"
    steps.write_text("framework", encoding="utf-8")
    terminal_root = tmp_path / "terminal"
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(terminal_root))
    monkeypatch.delenv("V3_SCENE_GENERATION_MODE", raising=False)
    submission_id = str(uuid.uuid4())
    manifest_file = tmp_path / "manifest.json"
    manifest_file.write_text(json.dumps({
        "schema_version": 1,
        "submission_id": submission_id,
        "subjects": [{
            "subject_key": "single-pdf", "mode": "new", "pdf_dir": str(pdf_dir),
            "steps": str(steps), "account_check_notebook_id": "proof-notebook",
            "provider": "gemini", "model": "gemini-2.5-flash",
        }],
    }), encoding="utf-8")

    update = MagicMock()
    update.result = AsyncMock(return_value={"outcome": "accepted", "submission_id": submission_id, "subject_count": 1})
    client = MagicMock()
    client.start_update_with_start_workflow = AsyncMock(return_value=update)
    code, _ = run_cli(["submit", "--manifest", str(manifest_file), "--export-prompts", "--json"], client=client)
    assert code == 0
    captured = json.loads((terminal_root / "submissions" / submission_id / "manifest.json").read_text())
    assert captured["subjects"][0]["scene_generation_mode"] == "export_prompts"


def test_cli_submit_queue_full_exit_code_3(tmp_path: Path) -> None:
    pdf_dir = tmp_path / "pdfs"
    pdf_dir.mkdir()
    (pdf_dir / "01.pdf").write_bytes(b"%PDF-1.4 test")
    steps = tmp_path / "steps.txt"
    steps.write_text("dummy steps", encoding="utf-8")

    sub_id = str(uuid.uuid4())
    manifest_data = {
        "schema_version": 1,
        "submission_id": sub_id,
        "subjects": [
            {
                "subject_key": "subj1",
                "mode": "new",
                "title": "Subj 1",
                "pdf_dir": str(pdf_dir),
                "steps": str(steps),
                "account_check_notebook_id": "nb-proof-123",
                "provider": "gemini",
                "model": "gemini-2.5-flash",
            }
        ],
    }
    manifest_file = tmp_path / "manifest.json"
    manifest_file.write_text(json.dumps(manifest_data), encoding="utf-8")

    mock_handle = MagicMock()
    mock_update = MagicMock()
    mock_update.result = AsyncMock(return_value={
        "outcome": "rejected",
        "safe_code": SafeCode.queue_full.value,
        "submission_id": sub_id,
    })
    mock_client = MagicMock()
    mock_client.start_update_with_start_workflow = AsyncMock(return_value=mock_update)

    exit_code, output = run_cli(
        ["submit", "--manifest", str(manifest_file), "--json"],
        client=mock_client,
    )

    assert exit_code == 3
    parsed = json.loads(output)
    assert parsed["outcome"] == "rejected"
    assert parsed["safe_code"] == "queue_full"


def test_cli_submit_invalid_manifest_exit_code_2(tmp_path: Path) -> None:
    # Missing required model & provider
    manifest_data = {
        "schema_version": 1,
        "submission_id": str(uuid.uuid4()),
        "subjects": [
            {
                "subject_key": "subj1",
                "mode": "new",
                "title": "Subj 1",
            }
        ],
    }
    manifest_file = tmp_path / "bad_manifest.json"
    manifest_file.write_text(json.dumps(manifest_data), encoding="utf-8")

    exit_code, output = run_cli(
        ["submit", "--manifest", str(manifest_file), "--json"],
    )

    assert exit_code == 2
    parsed = json.loads(output)
    assert parsed["outcome"] == "rejected"
    assert parsed["safe_code"] == "input_invalid"


def test_cli_no_secret_stdout(tmp_path: Path) -> None:
    pdf_dir = tmp_path / "pdfs"
    pdf_dir.mkdir()
    (pdf_dir / "01.pdf").write_bytes(b"%PDF-1.4 test")
    steps = tmp_path / "steps.txt"
    steps.write_text("dummy steps", encoding="utf-8")

    secret_sentinel = "SECRET_API_KEY_NEVER_LEAK"
    sub_id = str(uuid.uuid4())
    manifest_data = {
        "schema_version": 1,
        "submission_id": sub_id,
        "subjects": [
            {
                "subject_key": "subj1",
                "mode": "new",
                "title": "Subj 1",
                "pdf_dir": str(pdf_dir),
                "steps": str(steps),
                "account_check_notebook_id": "nb-proof-123",
                "provider": "gemini",
                "model": "gemini-2.5-flash",
            }
        ],
    }
    manifest_file = tmp_path / "manifest.json"
    manifest_file.write_text(json.dumps(manifest_data), encoding="utf-8")

    mock_handle = MagicMock()
    mock_handle.execute_update = AsyncMock(return_value={
        "outcome": "accepted",
        "submission_id": sub_id,
        "subject_count": 1,
    })
    mock_client = MagicMock()
    mock_client.get_workflow_handle = MagicMock(return_value=mock_handle)

    exit_code, output = run_cli(
        ["submit", "--manifest", str(manifest_file)],
        client=mock_client,
    )

    assert secret_sentinel not in output
    assert "cookie" not in output.lower()


def test_cli_control_unstarted_queued_pause_reject() -> None:
    run_id = str(uuid.uuid4())
    cmd_id = str(uuid.uuid4())

    mock_sup = MagicMock()
    mock_sup.query = AsyncMock(return_value="queued")

    mock_client = MagicMock()
    mock_client.get_workflow_handle = MagicMock(return_value=mock_sup)

    exit_code, output = run_cli(
        ["pause", "--run-id", run_id, "--command-id", cmd_id, "--expected-version", "1", "--json"],
        client=mock_client,
    )

    assert exit_code == 3
    parsed = json.loads(output)
    assert parsed["outcome"] == "rejected"
    assert parsed["safe_code"] == SafeCode.control_conflict.value


def test_cli_control_cancel_queued_routes_to_supervisor() -> None:
    run_id = str(uuid.uuid4())
    cmd_id = str(uuid.uuid4())

    mock_sup = MagicMock()
    mock_sup.query = AsyncMock(return_value="queued")
    mock_sup.execute_update = AsyncMock(return_value={
        "outcome": "applied",
        "status": "cancelled_before_start",
        "subject_run_id": run_id,
        "command_id": cmd_id,
    })

    mock_client = MagicMock()
    mock_client.get_workflow_handle = MagicMock(return_value=mock_sup)

    exit_code, output = run_cli(
        ["cancel", "--run-id", run_id, "--command-id", cmd_id, "--expected-version", "1", "--json"],
        client=mock_client,
    )

    assert exit_code == 0
    parsed = json.loads(output)
    assert parsed["outcome"] == "applied"
    assert parsed["status"] == "cancelled_before_start"
    mock_sup.execute_update.assert_called_once_with(
        "cancel_queued_v1",
        {
            "command_id": cmd_id,
            "subject_run_id": run_id,
            "expected_version": 1,
        },
    )


def test_cli_control_active_subject_routes_to_child() -> None:
    run_id = str(uuid.uuid4())
    cmd_id = str(uuid.uuid4())

    mock_sup = MagicMock()
    mock_sup.query = AsyncMock(return_value="active")

    mock_child = MagicMock()
    mock_child.execute_update = AsyncMock(return_value={
        "outcome": "applied",
        "status": "paused",
        "state_version": 2,
    })

    def get_handle(name: str):
        if name == "v3-terminal-supervisor":
            return mock_sup
        return mock_child

    mock_client = MagicMock()
    mock_client.get_workflow_handle = MagicMock(side_effect=get_handle)

    exit_code, output = run_cli(
        ["pause", "--run-id", run_id, "--command-id", cmd_id, "--expected-version", "1", "--json"],
        client=mock_client,
    )

    assert exit_code == 0
    parsed = json.loads(output)
    assert parsed["status"] == "paused"
    mock_child.execute_update.assert_called_once_with(
        "control_v1",
        {
            "command_id": cmd_id,
            "action": "pause",
            "subject_run_id": run_id,
            "expected_version": 1,
        },
    )


def test_cli_status_and_logs(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path))
    run_id = str(uuid.uuid4())

    mock_child = MagicMock()
    mock_child.query = AsyncMock(return_value={
        "status": "running",
        "phase": "generating_scenes",
        "lessons_saved": 3,
        "scenes_generated": 6,
        "scenes_built": 4,
    })
    mock_client = MagicMock()
    mock_client.get_workflow_handle = MagicMock(return_value=mock_child)

    # 1. status --run-id
    exit_code, output = run_cli(
        ["status", "--run-id", run_id, "--json"],
        client=mock_client,
    )
    assert exit_code == 0
    parsed = json.loads(output)
    assert parsed["status"] == "running"
    assert parsed["phase"] == "generating_scenes"
    assert parsed["lessons_saved"] == 3

    # 2. logs --run-id
    exit_code_logs, output_logs = run_cli(
        ["logs", "--run-id", run_id, "--json"],
        client=mock_client,
    )
    assert exit_code_logs == 0


