"""Tests for Temporal subject workflow execution, ordering, and barriers (Spec 018 / T012)."""

from __future__ import annotations

import asyncio
import json
import uuid
from pathlib import Path
import pytest
from temporalio import activity, workflow
from temporalio.client import Client
from temporalio.worker import Worker

from v3_engine.terminal_pipeline.activities import (
    TERMINAL_ACTIVITIES,
)
from v3_engine.terminal_pipeline.contracts import (
    ActivityInput,
    ActivityResult,
    SafeErrorCode,
)
from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow


@pytest.mark.temporal
async def test_subject_workflow_end_to_end_barrier_and_bounds(
    temporal_local_env, tmp_path: Path
) -> None:
    client = temporal_local_env.client

    # Activity invocation trace
    activity_trace: list[str] = []
    lessons_captured_count = 0
    scenes_generated_count = 0
    barrier_violated = False

    # Create mock activities for testing workflow progression
    @activity.defn(name="initialize_terminal_run")
    async def mock_init(inp: dict) -> dict:
        activity_trace.append("initialize_terminal_run")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="register_terminal_child")
    async def mock_register(inp: dict) -> dict:
        activity_trace.append("register_terminal_child")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="verify_notebook_session")
    async def mock_verify_session(inp: dict) -> dict:
        activity_trace.append("verify_notebook_session")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="create_terminal_notebook")
    async def mock_create_nb(inp: dict) -> dict:
        activity_trace.append("create_terminal_notebook")
        return {"outcome": "complete", "safe_code": "ok", "result_ref": "nb-123"}

    @activity.defn(name="upload_terminal_source")
    async def mock_upload(inp: dict) -> dict:
        activity_trace.append("upload_terminal_source")
        return {"outcome": "complete", "safe_code": "ok", "result_ref": "src-1"}

    @activity.defn(name="poll_terminal_sources")
    async def mock_poll(inp: dict) -> dict:
        activity_trace.append("poll_terminal_sources")
        return {"outcome": "complete", "safe_code": "ok", "counts": {"ready": 2, "total": 2}}

    @activity.defn(name="configure_terminal_chat")
    async def mock_config_chat(inp: dict) -> dict:
        activity_trace.append("configure_terminal_chat")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="send_terminal_turn")
    async def mock_send_turn(inp: dict) -> dict:
        q = inp.get("question", "")
        activity_trace.append(f"send_terminal_turn:{q[:10]}")
        return {"outcome": "complete", "safe_code": "ok", "receipt_ref": "rcpt-1", "result_ref": "conv-1"}

    @activity.defn(name="capture_terminal_turn")
    async def mock_capture_turn(inp: dict) -> dict:
        nonlocal lessons_captured_count
        kind = inp.get("turn_kind", "")
        activity_trace.append(f"capture_terminal_turn:{kind}")
        if kind == "divide":
            return {"outcome": "complete", "safe_code": "ok", "counts": {"work_count": 2}}
        elif kind == "execution":
            return {"outcome": "complete", "safe_code": "ok"}
        elif kind == "lesson":
            lessons_captured_count += 1
            return {"outcome": "complete", "safe_code": "ok", "counts": {"scene_count": 1}}
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="freeze_terminal_handoffs")
    async def mock_freeze(inp: dict) -> dict:
        activity_trace.append("freeze_terminal_handoffs")
        return {
            "outcome": "complete",
            "safe_code": "ok",
            "counts": {"lesson_count": 2, "scene_count": 2},
            "result_ref": json.dumps(["scene_001_01", "scene_002_01"]),
        }

    @activity.defn(name="page_terminal_scenes")
    async def mock_page_scenes(inp: dict) -> dict:
        offset = inp.get("offset", 0)
        scene_keys = ["scene_001_01", "scene_002_01"]
        page = scene_keys[offset : offset + inp.get("limit", 50)]
        next_offset = offset + len(page) if offset + len(page) < len(scene_keys) else None
        return {
            "outcome": "complete",
            "safe_code": "ok",
            "scene_keys": page,
            "next_offset": next_offset,
            "counts": {"total": len(scene_keys)},
        }

    @activity.defn(name="generate_terminal_scene")
    async def mock_gen_scene(inp: dict) -> dict:
        nonlocal barrier_violated, scenes_generated_count
        if "freeze_terminal_handoffs" not in activity_trace:
            barrier_violated = True
        scenes_generated_count += 1
        activity_trace.append("generate_terminal_scene")
        return {"outcome": "complete", "safe_code": "ok", "receipt_ref": "scene-rcpt"}

    @activity.defn(name="materialize_terminal_scene")
    async def mock_mat_scene(inp: dict) -> dict:
        activity_trace.append("materialize_terminal_scene")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="build_terminal_scene")
    async def mock_build_scene(inp: dict) -> dict:
        activity_trace.append("build_terminal_scene")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="finalize_terminal_run")
    async def mock_finalize(inp: dict) -> dict:
        activity_trace.append("finalize_terminal_run")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="settle_terminal_child")
    async def mock_settle(inp: dict) -> dict:
        activity_trace.append("settle_terminal_child")
        return {"outcome": "complete", "safe_code": "ok"}

    control_activities = [mock_init, mock_register, mock_capture_turn, mock_freeze, mock_page_scenes, mock_mat_scene, mock_finalize, mock_settle]
    io_activities = [mock_verify_session, mock_create_nb, mock_upload, mock_poll, mock_config_chat, mock_send_turn, mock_gen_scene]
    build_activities = [mock_build_scene]

    async with (
        Worker(client, task_queue="v3-terminal-control", workflows=[TerminalSubjectWorkflow], activities=control_activities),
        Worker(client, task_queue="v3-terminal-io", activities=io_activities),
        Worker(client, task_queue="v3-terminal-build", activities=build_activities),
    ):
        subject_id = str(uuid.uuid4())
        workflow_id = f"v3-terminal-subject-{subject_id}"

        input_payload = {
            "schema_version": 1,
            "subject_run_id": subject_id,
            "run_ref": f"runs/{subject_id}",
            "manifest_ref": "manifests/sample.json",
            "proof_notebook_id": "nb-proof-123",
            "sources": [
                {"role": "educational_pdf", "title": "01.pdf", "path": "/path/01.pdf", "mime_type": "application/pdf"},
                {"role": "framework_steps", "title": "steps.txt", "path": "/path/steps.txt", "mime_type": "text/plain"},
            ],
        }

        result = await client.execute_workflow(
            TerminalSubjectWorkflow.run,
            input_payload,
            id=workflow_id,
            task_queue="v3-terminal-control",
        )

        assert result["outcome"] == "complete"
        assert result["safe_code"] == "ok"
        assert lessons_captured_count == 2
        assert scenes_generated_count == 2
        assert not barrier_violated, "Scenes MUST NOT be generated before all lessons are frozen!"
        assert "freeze_terminal_handoffs" in activity_trace
        assert "finalize_terminal_run" in activity_trace
        assert "settle_terminal_child" in activity_trace


@pytest.mark.temporal
async def test_prompt_export_mode_settles_without_scene_generation_or_build(temporal_local_env) -> None:
    """Export mode completes after freezing prompts and does not dispatch paid provider/build Activities."""
    client = temporal_local_env.client
    trace: list[str] = []

    @activity.defn(name="initialize_terminal_run")
    async def init(inp: dict) -> dict:
        trace.append("initialize")
        assert inp["subject_config"]["scene_generation_mode"] == "export_prompts"
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="register_terminal_child")
    async def register(inp: dict) -> dict:
        trace.append("register")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="freeze_terminal_handoffs")
    async def freeze(inp: dict) -> dict:
        trace.append("freeze")
        return {"outcome": "complete", "safe_code": "ok", "counts": {"issue_count": 0}}

    @activity.defn(name="page_terminal_scenes")
    async def page(inp: dict) -> dict:
        trace.append("page")
        return {"outcome": "complete", "safe_code": "ok", "scene_keys": ["nb:conv:W1:S1", "nb:conv:W1:S2"],
                "next_offset": None, "counts": {"total": 2}}

    @activity.defn(name="export_terminal_scene_prompts")
    async def export(inp: dict) -> dict:
        trace.append("export")
        return {"outcome": "complete", "safe_code": "ok", "result_ref": "runs/x/private/scene-prompt-exports/scene-prompts.json",
                "counts": {"lesson_count": 1, "scene_count": 2}}

    @activity.defn(name="finalize_terminal_run")
    async def finalize(inp: dict) -> dict:
        trace.append("finalize")
        assert inp["terminal_status"] == "prompts_exported"
        assert inp["prompt_export_ref"].endswith("scene-prompts.json")
        assert inp["prompt_export_count"] == 2
        return {"outcome": "complete", "safe_code": "ok", "result_ref": "runs/x/final-report.json"}

    @activity.defn(name="settle_terminal_child")
    async def settle(inp: dict) -> dict:
        trace.append("settle")
        return {"outcome": "complete", "safe_code": "ok"}

    control = [init, register, freeze, page, export, finalize, settle]
    async with Worker(client, task_queue="v3-terminal-control", workflows=[TerminalSubjectWorkflow], activities=control):
        subject_id = str(uuid.uuid4())
        result = await client.execute_workflow(
            TerminalSubjectWorkflow.run,
            {"schema_version": 1, "subject_run_id": subject_id, "run_ref": f"runs/{subject_id}",
             "mode": "saved", "handoff_path": "/private/handoff.json",
             "subject_config": {"scene_generation_mode": "export_prompts"}},
            id=f"v3-terminal-subject-{subject_id}", task_queue="v3-terminal-control",
        )

    assert result["status"] == "prompts_exported"
    assert result["scene_prompts_exported"] == 2
    assert result["scenes_generated"] == result["scenes_built"] == 0
    assert trace == ["initialize", "register", "freeze", "page", "export", "finalize", "settle"]


@pytest.mark.temporal
async def test_failed_generate_recovery_settles_partial_and_releases_child(
    temporal_local_env,
) -> None:
    """A failed provider retry must settle honestly so another retry can be admitted."""
    client = temporal_local_env.client
    trace: list[str] = []

    @activity.defn(name="initialize_terminal_run")
    async def init(inp: dict) -> dict:
        trace.append("initialize")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="register_terminal_child")
    async def register(inp: dict) -> dict:
        trace.append("register")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="generate_terminal_scene")
    async def generate(inp: dict) -> dict:
        trace.append("generate")
        return {"outcome": "failed", "safe_code": "provider_response"}

    @activity.defn(name="finalize_terminal_run")
    async def finalize(inp: dict) -> dict:
        trace.append(f"finalize:{inp['terminal_status']}")
        return {"outcome": "complete", "safe_code": "ok", "result_ref": "reports/recovery.json"}

    @activity.defn(name="settle_terminal_child")
    async def settle(inp: dict) -> dict:
        trace.append(f"settle:{inp['terminal_status']}")
        return {"outcome": "complete", "safe_code": "ok"}

    async with (
        Worker(client, task_queue="v3-terminal-control", workflows=[TerminalSubjectWorkflow], activities=[init, register, finalize, settle]),
        Worker(client, task_queue="v3-terminal-io", activities=[generate]),
    ):
        subject_id = str(uuid.uuid4())
        result = await client.execute_workflow(
            TerminalSubjectWorkflow.run,
            {
                "schema_version": 1,
                "subject_run_id": subject_id,
                "run_ref": f"runs/{subject_id}",
                "is_recovery": True,
                "source_subject_run_id": str(uuid.uuid4()),
                "mode": "generate",
                "selected_scene_keys": ["notebook:conversation:W1:S1"],
            },
            id=f"v3-terminal-subject-{subject_id}",
            task_queue="v3-terminal-control",
        )

    assert result["status"] == "partial"
    assert result["safe_code"] == "provider_response"
    assert trace == ["initialize", "register", "generate", "finalize:partial", "settle:partial"]


@pytest.mark.temporal
async def test_build_recovery_materializes_retained_receipt_before_build(temporal_local_env) -> None:
    """Build retry restores a saved provider receipt without making another request."""
    client = temporal_local_env.client
    trace: list[str] = []

    @activity.defn(name="initialize_terminal_run")
    async def init(inp: dict) -> dict:
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="register_terminal_child")
    async def register(inp: dict) -> dict:
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="materialize_terminal_scene")
    async def materialize(inp: dict) -> dict:
        trace.append("materialize")
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="build_terminal_scene")
    async def build(inp: dict) -> dict:
        trace.append("build")
        return {"outcome": "complete", "safe_code": "ok", "counts": {"build_ordinal": 1}}

    @activity.defn(name="finalize_terminal_run")
    async def finalize(inp: dict) -> dict:
        trace.append("finalize")
        return {"outcome": "complete", "safe_code": "ok", "result_ref": "reports/recovery.json"}

    @activity.defn(name="settle_terminal_child")
    async def settle(inp: dict) -> dict:
        trace.append("settle")
        return {"outcome": "complete", "safe_code": "ok"}

    async with (
        Worker(client, task_queue="v3-terminal-control", workflows=[TerminalSubjectWorkflow], activities=[init, register, materialize, finalize, settle]),
        Worker(client, task_queue="v3-terminal-build", activities=[build]),
    ):
        subject_id = str(uuid.uuid4())
        result = await client.execute_workflow(
            TerminalSubjectWorkflow.run,
            {"schema_version": 1, "subject_run_id": subject_id, "run_ref": f"runs/{subject_id}",
             "is_recovery": True, "recovery_id": str(uuid.uuid4()),
             "source_subject_run_id": str(uuid.uuid4()), "mode": "build",
             "selected_scene_keys": ["notebook:conversation:W1:S1"]},
            id=f"v3-terminal-subject-{subject_id}", task_queue="v3-terminal-control",
        )

    assert result["status"] == "completed"
    assert result["scenes_built"] == 1
    assert trace == ["materialize", "build", "finalize", "settle"]
