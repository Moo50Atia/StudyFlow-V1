import asyncio
import os
from typing import Any, Dict, List
import pytest

from temporalio import activity
from temporalio.client import WorkflowExecutionStatus
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Worker

from v3_engine.workflows.case_workflow import CaseWorkflow

pytestmark = [pytest.mark.temporal, pytest.mark.asyncio]


executed_activities: List[str] = []
published_events: List[str] = []


@activity.defn(name="publish_workflow_started")
async def mock_publish_started(payload: dict) -> dict:
    executed_activities.append("publish_workflow_started")
    return {"data": {"event_id": "ev-start-1", "case_id": payload["case_id"], "state_version": 2}}


@activity.defn(name="publish_case_event")
async def mock_publish_event(payload: dict) -> dict:
    executed_activities.append(f"publish_event:{payload['event_key']}")
    published_events.append(payload["event_key"])
    return {
        "data": {
            "event_id": f"ev-{payload['event_key']}",
            "case_id": payload["case_id"],
            "state_version": payload["expected_state_version"] + 1,
        }
    }


@activity.defn(name="extract_case_source")
async def mock_extract_case_source(payload: dict) -> dict:
    executed_activities.append("extract_case_source")
    return {"status": "ready", "extracted_source_ref": "00000000-0000-0000-0000-000000000100"}


@activity.defn(name="ingest_case_source")
async def mock_ingest_case_source(payload: dict) -> dict:
    executed_activities.append("ingest_case_source")
    return {"status": "bound", "binding_ref": "00000000-0000-0000-0000-000000000200"}


@activity.defn(name="map_case")
async def mock_map_case(payload: dict) -> dict:
    executed_activities.append("map_case")
    return {"status": "ready", "map_ref": "00000000-0000-0000-0000-000000000300", "work_count": 1}


@activity.defn(name="prepare_lesson")
async def mock_prepare_lesson(payload: dict) -> dict:
    executed_activities.append("prepare_lesson")
    return {"status": "ready", "cursor_phase": "awaiting_010", "section_count": 1}


@activity.defn(name="generate_prompts")
async def mock_generate_prompts(payload: dict) -> dict:
    executed_activities.append("generate_prompts")
    return {"status": "completed", "work_ordinal": payload.get("work_ordinal", 1)}


@activity.defn(name="qualify_prompts")
async def mock_qualify_prompts(payload: dict) -> dict:
    executed_activities.append("qualify_prompts")
    prompt_refs = payload.get("prompt_version_refs") or payload.get("passing_prompt_refs") or []
    if prompt_refs:
        return {
            "status": "completed",
            "qualified_count": len(prompt_refs),
            "blocked_count": 0,
            "passing_prompt_refs": prompt_refs,
        }
    return {
        "status": "completed",
        "qualified_count": 0,
        "blocked_count": 1,
        "passing_prompt_refs": [],
    }


@activity.defn(name="resolve_qualified_prompt")
async def mock_resolve_qualified_prompt(payload: dict) -> dict:
    executed_activities.append(f"resolve_qualified_prompt:{payload['prompt_version_ref']}")
    return {
        "status": "resolved",
        "case_id": payload["case_id"],
        "prompt_version_ref": payload["prompt_version_ref"],
    }


@pytest.mark.asyncio
async def test_workflow_progresses_through_prompt_milestones_and_011_handoff():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-prompt-workflow-queue"
        case_id = "00000000-0000-0000-0000-000000000050"
        src_act_id = "00000000-0000-0000-0000-000000000051"
        map_act_id = "00000000-0000-0000-0000-000000000052"
        prompt_ref = "11111111-1111-1111-1111-111111111111"
        wf_id = f"v3-case-{case_id}"

        activities = [
            mock_publish_started,
            mock_publish_event,
            mock_extract_case_source,
            mock_ingest_case_source,
            mock_map_case,
            mock_prepare_lesson,
            mock_generate_prompts,
            mock_qualify_prompts,
            mock_resolve_qualified_prompt,
        ]

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=activities,
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": case_id,
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id=wf_id,
                task_queue=task_queue,
            )

            # Advance to extraction
            await asyncio.sleep(0.3)
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": src_act_id})
            await asyncio.sleep(0.5)

            # Advance through mapping
            await handle.signal("mapping_activation_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "binding_ref": "bind-1",
                "activation_id": map_act_id,
            })
            await asyncio.sleep(0.8)

            assert "map:completed" in published_events
            # The production ordering requirement is that Third generation is
            # scheduled immediately after First persistence, before this test
            # sends the lesson-completion signal.
            assert "generate_prompts" in executed_activities

            # Signal lesson_ready_v1 with 010 completion ref and passing prompt refs
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": map_act_id,
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-001",
                "passing_prompt_refs": [prompt_ref],
            })

            await asyncio.sleep(1.0)

            # Verify prompt milestones were emitted in exact sequence
            assert "prompt:work:1:started" in published_events
            assert "prompt:work:1:originals" in published_events
            assert "prompt:qualification:started" in published_events
            assert "prompt:qualification:completed" in published_events

            # Verify activities executed
            assert "qualify_prompts" in executed_activities
            assert f"resolve_qualified_prompt:{prompt_ref}" in executed_activities


@pytest.mark.asyncio
async def test_workflow_handles_all_blocked_prompt_qualification():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-prompt-all-blocked-queue"
        case_id = "00000000-0000-0000-0000-000000000060"
        src_act_id = "00000000-0000-0000-0000-000000000061"
        map_act_id = "00000000-0000-0000-0000-000000000062"
        wf_id = f"v3-case-{case_id}"

        activities = [
            mock_publish_started,
            mock_publish_event,
            mock_extract_case_source,
            mock_ingest_case_source,
            mock_map_case,
            mock_prepare_lesson,
            mock_generate_prompts,
            mock_qualify_prompts,
            mock_resolve_qualified_prompt,
        ]

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=activities,
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": case_id,
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id=wf_id,
                task_queue=task_queue,
            )

            await asyncio.sleep(0.3)
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": src_act_id})
            await asyncio.sleep(0.5)

            await handle.signal("mapping_activation_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "binding_ref": "bind-1",
                "activation_id": map_act_id,
            })
            await asyncio.sleep(0.8)

            # Signal lesson_ready_v1 with NO passing prompt refs -> triggers all_blocked
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": map_act_id,
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-002",
                "passing_prompt_refs": [],
            })

            await asyncio.sleep(1.0)

            assert "prompt:work:1:started" in published_events
            assert "prompt:work:1:originals" in published_events
            assert "prompt:qualification:started" in published_events
            assert "prompt:qualification:all_blocked" in published_events
