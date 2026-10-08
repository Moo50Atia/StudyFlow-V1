"""Tests for Temporal workflow replay compatibility per Spec 016 (N19)."""
import asyncio
import os
from typing import Any, Dict, List
import pytest

from temporalio import activity
from temporalio.client import WorkflowExecutionStatus, WorkflowHistory
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Replayer, Worker

from v3_engine.workflows.case_workflow import CaseWorkflow

pytestmark = [pytest.mark.temporal, pytest.mark.asyncio]

executed_activities: List[Dict[str, Any]] = []


@activity.defn(name="publish_workflow_started")
async def mock_publish_started(payload: dict) -> dict:
    executed_activities.append({"name": "publish_workflow_started", "payload": payload})
    return {"data": {"event_id": "ev-start", "case_id": payload["case_id"], "state_version": 2}}


@activity.defn(name="publish_case_event")
async def mock_publish_event(payload: dict) -> dict:
    executed_activities.append({"name": f"publish_event:{payload['event_key']}", "payload": payload})
    return {
        "data": {
            "event_id": f"ev-{payload['event_key']}",
            "case_id": payload["case_id"],
            "state_version": payload["expected_state_version"] + 1,
        }
    }


@activity.defn(name="extract_case_source")
async def mock_extract(payload: dict) -> dict:
    executed_activities.append({"name": "extract_case_source", "payload": payload})
    return {"status": "ready", "extracted_source_ref": "ext-src-1"}


@activity.defn(name="ingest_case_source")
async def mock_ingest(payload: dict) -> dict:
    executed_activities.append({"name": "ingest_case_source", "payload": payload})
    return {"status": "bound", "binding_ref": "binding-1"}


@activity.defn(name="map_case")
async def mock_map(payload: dict) -> dict:
    executed_activities.append({"name": "map_case", "payload": payload})
    return {"status": "ready", "map_ref": "map-1", "work_count": 1}


@activity.defn(name="prepare_lesson")
async def mock_prepare(payload: dict) -> dict:
    executed_activities.append({"name": "prepare_lesson", "payload": payload})
    return {"status": "ready", "cursor_phase": "awaiting_010", "section_count": 1}


@activity.defn(name="generate_prompts")
async def mock_generate(payload: dict) -> dict:
    executed_activities.append({"name": "generate_prompts", "payload": payload})
    return {"status": "completed", "work_ordinal": payload.get("work_ordinal", 1)}


@activity.defn(name="qualify_prompts")
async def mock_qualify(payload: dict) -> dict:
    executed_activities.append({"name": "qualify_prompts", "payload": payload})
    refs = payload.get("passing_prompt_refs") or ["prompt-v1"]
    return {"status": "completed", "qualified_count": len(refs), "passing_prompt_refs": refs}


@activity.defn(name="resolve_qualified_prompt")
async def mock_resolve(payload: dict) -> dict:
    executed_activities.append({"name": "resolve_qualified_prompt", "payload": payload})
    return {
        "status": "ready",
        "current_prompt_version_ref": payload.get("prompt_version_ref", "prompt-v1"),
        "qualified": True,
    }


@activity.defn(name="authoring_list_eligible_prompts")
async def mock_auth_list(payload: dict) -> List[Dict[str, Any]]:
    executed_activities.append({"name": "authoring_list_eligible_prompts", "payload": payload})
    return [{"prompt_version_ref": "prompt-v1", "section_ref": "sec-1"}]


@activity.defn(name="authoring_claim_request")
async def mock_auth_claim(payload: dict) -> dict:
    executed_activities.append({"name": "authoring_claim_request", "payload": payload})
    return {"status": "claimed", "request_ref": "auth-req-1"}


@activity.defn(name="authoring_execute_attempts")
async def mock_auth_exec(payload: dict) -> dict:
    executed_activities.append({"name": "authoring_execute_attempts", "payload": payload})
    return {"status": "candidate_ready", "candidate_ref": "cand-1", "candidate_version": 1}


@activity.defn(name="qualify_scene_activity")
async def mock_qualify_scene(payload: dict) -> dict:
    executed_activities.append({"name": "qualify_scene_activity", "payload": payload})
    return {"status": "qualified", "candidate_ref": payload.get("candidate_ref", "cand-1")}


@activity.defn(name="repair_scene_activity")
async def mock_repair_scene(payload: dict) -> dict:
    executed_activities.append({"name": "repair_scene_activity", "payload": payload})
    return {"status": "unrepairable"}


@activity.defn(name="ensure_review_ready_activity")
async def mock_ensure_review(payload: dict) -> dict:
    executed_activities.append({"name": "ensure_review_ready_activity", "payload": payload})
    return {
        "status": "ready",
        "data": {
            "review_ref": "rev-1",
            "section_ref": "sec-1",
            "candidate_ref": "cand-1",
            "candidate_version": 1,
        },
    }


ALL_ACTIVITIES = [
    mock_publish_started,
    mock_publish_event,
    mock_extract,
    mock_ingest,
    mock_map,
    mock_prepare,
    mock_generate,
    mock_qualify,
    mock_resolve,
    mock_auth_list,
    mock_auth_claim,
    mock_auth_exec,
    mock_qualify_scene,
    mock_repair_scene,
    mock_ensure_review,
]


async def test_n19_legacy_history_replays_cleanly_without_direct_divergence():
    """Verify that a legacy execution history replays without non-determinism under updated CaseWorkflow."""
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-replay-queue"
        case_id = "00000000-0000-0000-0000-000000000080"
        wf_id = f"v3-case-{case_id}"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=ALL_ACTIVITIES,
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": case_id,
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    # No pipeline_version specified => defaults to legacy_staged_v1
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id=wf_id,
                task_queue=task_queue,
            )

            # Signal source_activation_v1
            await asyncio.sleep(0.3)
            await handle.signal(
                "source_activation_v1",
                {"case_id": case_id, "activation_id": "act-1"},
            )

            # Signal mapping_activation_v1
            await asyncio.sleep(0.3)
            await handle.signal(
                "mapping_activation_v1",
                {"case_id": case_id, "activation_id": "map-act-1", "binding_ref": "binding-1"},
            )

            # Signal lesson_ready_v1
            await asyncio.sleep(0.3)
            await handle.signal(
                "lesson_ready_v1",
                {
                    "schema_version": 1,
                    "case_id": case_id,
                    "completion_ref": "comp-1",
                    "work_ordinal": 1,
                    "map_ref": "map-1",
                    "cursor_version": 2,
                },
            )

            # Signal review_decision_v1
            await asyncio.sleep(0.5)
            await handle.signal(
                "review_decision_v1",
                {
                    "schema_version": 1,
                    "case_id": case_id,
                    "section_ref": "sec-1",
                    "review_ref": "rev-1",
                    "candidate_ref": "cand-1",
                    "candidate_version": 1,
                    "decision_ref": "dec-1",
                    "outcome": "approve",
                },
            )

            await asyncio.wait_for(handle.result(), timeout=30.0)

            # Verify that extraction was called in legacy mode
            act_names = [a["name"] for a in executed_activities]
            assert "extract_case_source" in act_names

            # Fetch history and replay it
            history = await handle.fetch_history()
            replay_result = await Replayer(workflows=[CaseWorkflow]).replay_workflow(history)
            assert replay_result.replay_failure is None, f"Replay failure: {replay_result.replay_failure}"


async def test_n19_saved_legacy_history_fixture_replays_without_nondeterminism():
    """Verify that a saved, sanitized legacy history fixture replays cleanly without dev-server execution."""
    fixture_path = os.path.join(
        os.path.dirname(__file__),
        "fixtures",
        "legacy_histories",
        "legacy_staged_v1_case.json",
    )
    assert os.path.exists(fixture_path), f"Missing history fixture: {fixture_path}"

    with open(fixture_path, "r", encoding="utf-8") as f:
        history_json = f.read()

    history = WorkflowHistory.from_json("v3-case-00000000-0000-0000-0000-000000000080", history_json)
    replay_result = await Replayer(workflows=[CaseWorkflow]).replay_workflow(history)
    assert replay_result.replay_failure is None, f"Replay failure on saved fixture: {replay_result.replay_failure}"

