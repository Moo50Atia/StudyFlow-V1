"""Explicitly gated real NotebookLM -> Temporal -> scene provider -> Vite build test.

This is intentionally opt-in because it creates a NotebookLM notebook, sends paid
provider requests, and runs a local build. No test-created result may stand in for
an actual workflow outcome.
"""

from __future__ import annotations

import asyncio
import json
import os
from pathlib import Path
import uuid

import pytest
from temporalio.client import WithStartWorkflowOperation, WorkflowUpdateStage
from temporalio.common import WorkflowIDConflictPolicy
from temporalio.worker import Worker

from v3_engine.local_env import load_local_env
from v3_engine.notebooklm.credentials import get_storage_state_path
from v3_engine.terminal_pipeline import activities as acts
from v3_engine.terminal_pipeline.manifest import capture_manifest
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow
from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow
from v3_engine.scene_generation.handoff import load_handoff_inventory


@pytest.mark.live
@pytest.mark.temporal
@pytest.mark.integration
@pytest.mark.asyncio
async def test_live_terminal_pipeline_small_source_execution(temporal_local_env, tmp_path: Path, monkeypatch) -> None:
    """Run actual registered Activities and assert a retained compiled scene exists."""
    if os.environ.get("V3_TERMINAL_LIVE") != "1":
        pytest.skip("Live gate skipped: explicitly set V3_TERMINAL_LIVE=1 to permit external paid calls")

    repo_root = Path(__file__).resolve().parents[3]
    load_local_env(repo_root / ".env")
    saved_handoff_dir = os.environ.get("V3_TERMINAL_LIVE_HANDOFF_DIR", "").strip()
    if saved_handoff_dir:
        handoff_path = Path(saved_handoff_dir).expanduser()
        if not handoff_path.is_dir():
            pytest.skip("Live gate skipped: configured saved handoff directory is unavailable")
        expected_scene_count = len(load_handoff_inventory(handoff_path).scene_inputs)
        credential_path = None
        proof_notebook_id = None
    else:
        expected_scene_count = None
        credential_path = get_storage_state_path()
        if not credential_path.is_file():
            pytest.skip("Live gate skipped: NotebookLM storage state is not provisioned")
        proof_notebook_id = os.environ.get("V3_PROOF_NOTEBOOK_ID", "").strip()
        if not proof_notebook_id:
            pytest.skip("Live gate skipped: V3_PROOF_NOTEBOOK_ID is not configured")

    provider_name = os.environ.get("V3_SCENE_PROVIDER", "gemini").lower()
    model_env = {"gemini": "GEMINI_MODEL", "glm": "GLM_MODEL", "deepseek": "DEEPSEEK_MODEL", "qwen": "QWEN_MODEL"}.get(provider_name)
    key_env = {"gemini": "GEMINI_API_KEY", "glm": "GLM_API_KEY", "deepseek": "DEEPSEEK_API_KEY", "qwen": "QWEN_API_KEY"}.get(provider_name)
    if not model_env or not key_env or not os.environ.get(model_env) or not os.environ.get(key_env):
        pytest.skip("Live gate skipped: selected scene provider model/key are not provisioned")

    if not saved_handoff_dir:
        pdf_path = Path(os.environ.get("V3_TERMINAL_LIVE_PDF", repo_root / "v3_engine/tests/sources/fixtures/simple_text.pdf"))
        pdf_processing = os.environ.get("V3_TERMINAL_LIVE_PDF_PROCESSING", "joint")
        second_pdf = os.environ.get("V3_TERMINAL_LIVE_PDF2", "").strip()
        pdf_paths = [pdf_path]
        if pdf_processing == "per_pdf":
            if not second_pdf or not Path(second_pdf).is_file():
                pytest.skip("Live per-PDF gate skipped: provision V3_TERMINAL_LIVE_PDF2 with a distinct small PDF")
            pdf_paths.append(Path(second_pdf))
        steps_path = Path(os.environ.get("V3_TERMINAL_LIVE_STEPS", repo_root / "steps.txt"))
        if not pdf_path.is_file() or not steps_path.is_file():
            pytest.skip("Live gate skipped: small PDF or approved framework file is unavailable")

    # The workflow itself must prove the selected account owns this notebook;
    # Playwright storage states do not carry a username/account metadata field.
    if credential_path is not None:
        monkeypatch.setenv("V3_NOTEBOOKLM_STORAGE_STATE", str(credential_path))
    terminal_root = tmp_path / "terminal"
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(terminal_root))

    subject_manifest = {
        "subject_key": "live-saved-handoff-scenes" if saved_handoff_dir else "live-single-pdf",
        "mode": "saved" if saved_handoff_dir else "new",
        "display_title": "Saved handoff live scene smoke" if saved_handoff_dir else "Single PDF live pipeline smoke",
        "provider": provider_name,
        "model": os.environ[model_env],
        "timeout_seconds": 300,
    }
    if saved_handoff_dir:
        subject_manifest["handoff"] = str(handoff_path.resolve())
    else:
        subject_manifest.update({
            "account_check_notebook_id": proof_notebook_id,
            "pdf_files": [str(path.resolve()) for path in pdf_paths],
            "steps": str(steps_path.resolve()),
            "pdf_processing": pdf_processing,
        })
    raw_manifest = {
        "schema_version": 1,
        "submission_id": str(uuid.uuid4()),
        "subjects": [subject_manifest],
    }
    snapshot, saved_manifest_path = capture_manifest(
        raw_manifest,
        terminal_root=terminal_root,
        project_root=repo_root,
    )
    subject = snapshot.subjects[0]
    subject_run_id = subject.subject_run_id
    source_rows = []
    for item in subject.inputs:
        if item.kind == "handoff":
            continue
        source_path = item.snapshot_ref.resolve(terminal_root)
        source_rows.append({
            "path": str(source_path),
            "mime_type": "application/pdf" if item.kind == "pdf" else "text/plain",
            "title": source_path.name,
            "role": "educational_pdf" if item.kind == "pdf" else "framework_steps",
            "ordinal": item.ordinal, "sha256": item.sha256,
        })
    submission_payload = {
        "schema_version": 1,
        "submission_id": snapshot.submission_id,
        "submission_digest": snapshot.manifest_sha256,
        "manifest_ref": str(saved_manifest_path),
        "subjects": [{
            "subject_key": subject.subject_key,
            "subject_run_id": subject.subject_run_id,
            "run_ref": f"runs/{subject.subject_run_id}",
            "title": subject.display_title,
            "mode": subject.mode,
            "sources": source_rows,
            "proof_notebook_id": subject.account_check_notebook_id or "",
            "provider": subject.provider,
            "model": subject.model,
            "timeout_seconds": subject.timeout_seconds,
            "subject_config": subject.to_dict(),
            "handoff_path": str(subject.handoff_ref.resolve(terminal_root)) if subject.handoff_ref else None,
            "handoff_kind": subject.handoff_kind,
        }],
    }

    control_activities = [
        acts.initialize_terminal_run, acts.admit_terminal_submission,
        acts.register_terminal_child, acts.settle_terminal_child,
        acts.capture_terminal_turn, acts.freeze_terminal_handoffs, acts.page_terminal_scenes,
        acts.archive_terminal_pdf,
        acts.materialize_terminal_scene, acts.finalize_terminal_run,
    ]
    io_activities = [
        acts.verify_notebook_session, acts.create_terminal_notebook, acts.upload_terminal_source,
        acts.poll_terminal_sources, acts.configure_terminal_chat, acts.send_terminal_turn,
        acts.reconcile_terminal_operation, acts.generate_terminal_scene,
        acts.reset_terminal_conversation,
    ]
    build_activities = [acts.build_terminal_scene]
    client = temporal_local_env.client
    workers = [
        Worker(
            client,
            task_queue="v3-terminal-control",
            workflows=[TerminalSupervisorWorkflow, TerminalSubjectWorkflow],
            activities=control_activities,
        ),
        Worker(client, task_queue="v3-terminal-io", activities=io_activities, max_concurrent_activities=4),
        Worker(client, task_queue="v3-terminal-build", activities=build_activities, max_concurrent_activities=2),
    ]
    worker_tasks = [asyncio.create_task(worker.run()) for worker in workers]
    try:
        operation = WithStartWorkflowOperation(
            TerminalSupervisorWorkflow.run,
            {},
            id="v3-terminal-supervisor",
            task_queue="v3-terminal-control",
            id_conflict_policy=WorkflowIDConflictPolicy.USE_EXISTING,
        )
        update = await client.start_update_with_start_workflow(
            TerminalSupervisorWorkflow.submit_v1,
            submission_payload,
            start_workflow_operation=operation,
            wait_for_stage=WorkflowUpdateStage.ACCEPTED,
        )
        admission = await update.result()
        assert admission.get("outcome") == "accepted", admission
        handle = client.get_workflow_handle(f"v3-terminal-subject-{subject_run_id}")
        from temporalio.service import RPCError, RPCStatusCode
        async def wait_for_child_start():
            while True:
                try:
                    await handle.describe()
                    return
                except RPCError as error:
                    if error.status != RPCStatusCode.NOT_FOUND:
                        raise
                    await asyncio.sleep(0.1)
        await asyncio.wait_for(wait_for_child_start(), 30)
        result = await asyncio.wait_for(handle.result(), 1200)
    finally:
        for task in worker_tasks:
            task.cancel()
        await asyncio.gather(*worker_tasks, return_exceptions=True)

    assert result["status"] == "completed", result
    assert result["scenes_built"] > 0, result
    if expected_scene_count is None:
        assert result["lessons_saved"] > 0, result
    else:
        assert result["scenes_built"] == expected_scene_count, result
    run_dir = terminal_root / "runs" / subject_run_id
    report = json.loads((run_dir / "final-report.json").read_text(encoding="utf-8"))
    assert report["status"] == "completed"
    assert report["counts"]["scenes_built"] == result["scenes_built"]
    if subject.pdf_processing == "per_pdf":
        assert len(report["pdfs"]) == 2
        assert report["counts"]["pdfs_completed"] == 2
        assert report["pdfs"][0]["pdf_source_id"] != report["pdfs"][1]["pdf_source_id"]
        # Google may reuse a cleared chat's ID. Local scene identities must
        # still distinguish both PDF epochs and route to separate artifacts.
        scene_keys = json.loads((run_dir / "private/scene-inventory.json").read_text())
        assert len(scene_keys) == len(set(scene_keys)) == result["scenes_built"]
        for pdf in report["pdfs"]:
            scene_root = run_dir / "pdfs" / f"pdf-{pdf['pdf_ordinal']:04d}" / "scene-generation"
            assert list(scene_root.rglob("current.json")), "No compiled scene retained for this PDF"
    else:
        assert list((run_dir / "scene-generation").rglob("current.json")), "No published compiled-scene pointer was retained"
