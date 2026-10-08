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
claimed_prompts: List[str] = []


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
    return {"status": "ready", "cursor_phase": "awaiting_010", "section_count": 2}


@activity.defn(name="generate_prompts")
async def mock_generate_prompts(payload: dict) -> dict:
    executed_activities.append("generate_prompts")
    return {"status": "ready", "work_ordinal": payload.get("work_ordinal", 1)}


@activity.defn(name="qualify_prompts")
async def mock_qualify_prompts(payload: dict) -> dict:
    executed_activities.append("qualify_prompts")
    passing_refs = payload.get("passing_prompt_refs", [])
    return {
        "status": "completed",
        "qualified_count": len(passing_refs),
        "blocked_count": 0,
        "passing_prompt_refs": passing_refs,
    }


@activity.defn(name="resolve_qualified_prompt")
async def mock_resolve_qualified_prompt(payload: dict) -> dict:
    executed_activities.append(f"resolve_qualified_prompt:{payload['prompt_version_ref']}")
    return {
        "status": "resolved",
        "case_id": payload["case_id"],
        "prompt_version_ref": payload["prompt_version_ref"],
    }


@activity.defn(name="authoring_list_eligible_prompts")
async def mock_authoring_list_eligible(payload: dict) -> List[dict]:
    executed_activities.append("authoring_list_eligible_prompts")
    return [
        {"prompt_version_ref": "pv-1", "section_ref": "sec-1", "work_ordinal": 1, "ordinal_in_work": 1},
        {"prompt_version_ref": "pv-2", "section_ref": "sec-2", "work_ordinal": 1, "ordinal_in_work": 2},
    ]


@activity.defn(name="authoring_claim_request")
async def mock_authoring_claim_request(payload: dict) -> dict:
    pv_ref = payload["prompt_version_ref"]
    claimed_prompts.append(pv_ref)
    executed_activities.append(f"authoring_claim_request:{pv_ref}")
    req_id = f"req-{pv_ref}"
    return {
        "request_ref": req_id,
        "section_ref": f"sec-{pv_ref}",
        "state": "pending",
        "settings_version_id": 1,
    }


@activity.defn(name="authoring_execute_attempts")
async def mock_authoring_execute_attempts(payload: dict) -> dict:
    req_ref = payload["request_ref"]
    executed_activities.append(f"authoring_execute_attempts:{req_ref}")
    return {
        "status": "candidate_ready",
        "candidate_ref": f"cand-{req_ref}",
        "safe_error_code": None,
    }


@activity.defn(name="qualify_scene_activity")
async def mock_qualify_scene_activity(payload: dict) -> dict:
    cand_ref = payload["candidate_ref"]
    executed_activities.append(f"qualify_scene_activity:{cand_ref}")
    return {
        "status": "qualified",
        "candidate_ref": cand_ref,
        "check_ref": f"chk-{cand_ref}",
        "qualification_ref": f"qual-{cand_ref}",
    }


@activity.defn(name="repair_scene_activity")
async def mock_repair_scene_activity(payload: dict) -> dict:
    return {"status": "candidate_ready", "candidate_ref": "cand-rep-1"}


@activity.defn(name="ensure_review_ready_activity")
async def mock_ensure_review_ready_activity(payload: dict) -> dict:
    cand_ref = payload["candidate_ref"]
    executed_activities.append(f"ensure_review_ready_activity:{cand_ref}")
    return {
        "status": "ready",
        "review_ref": f"rev-{cand_ref}",
        "section_ref": "sec-1",
        "candidate_ref": cand_ref,
        "candidate_version": 1,
    }


@pytest.mark.asyncio
async def test_workflow_claims_passing_sections_in_order_and_emits_scene_milestones():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()
    claimed_prompts.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-authoring-wf-queue-1"
        case_id = "00000000-0000-0000-0000-000000000070"
        src_act_id = "00000000-0000-0000-0000-000000000071"
        map_act_id = "00000000-0000-0000-0000-000000000072"
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
            mock_authoring_list_eligible,
            mock_authoring_claim_request,
            mock_authoring_execute_attempts,
            mock_qualify_scene_activity,
            mock_repair_scene_activity,
            mock_ensure_review_ready_activity,
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

            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": map_act_id,
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-001",
                "passing_prompt_refs": ["pv-1", "pv-2"],
            })

            await asyncio.sleep(1.5)

            # Assert 011 authoring activities executed in ordinal order
            assert "authoring_list_eligible_prompts" in executed_activities
            assert claimed_prompts == ["pv-1", "pv-2"]
            assert "authoring_execute_attempts:req-pv-1" in executed_activities
            assert "authoring_execute_attempts:req-pv-2" in executed_activities

            # Assert exact milestone events
            assert "scene:request:req-pv-1:started" in published_events
            assert "scene:request:req-pv-1:candidate" in published_events
            assert "scene:request:req-pv-2:started" in published_events
            assert "scene:request:req-pv-2:candidate" in published_events

            # Verify NO scene-pass, review-ready or teacher-review event is emitted
            for ev in published_events:
                assert "review" not in ev.lower()
                assert "scene_pass" not in ev.lower()
                assert "approved" not in ev.lower()


@activity.defn(name="authoring_execute_attempts")
async def mock_authoring_execute_attempts_blocked(payload: dict) -> dict:
    req_ref = payload["request_ref"]
    executed_activities.append(f"authoring_execute_attempts:{req_ref}")
    return {
        "status": "blocked",
        "candidate_ref": None,
        "safe_error_code": "authoring_provider_unavailable",
    }


@pytest.mark.asyncio
async def test_workflow_handles_all_blocked_authoring_and_fails_terminally():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()
    claimed_prompts.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-authoring-wf-queue-2"
        case_id = "00000000-0000-0000-0000-000000000080"
        src_act_id = "00000000-0000-0000-0000-000000000081"
        map_act_id = "00000000-0000-0000-0000-000000000082"
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
            mock_authoring_list_eligible,
            mock_authoring_claim_request,
            mock_authoring_execute_attempts_blocked,
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

            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": map_act_id,
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-001",
                "passing_prompt_refs": ["pv-1", "pv-2"],
            })

            await asyncio.sleep(1.5)

            # Both requests were started
            assert "scene:request:req-pv-1:started" in published_events
            assert "scene:request:req-pv-2:started" in published_events

            # No candidate was emitted
            assert "scene:request:req-pv-1:candidate" not in published_events
            assert "scene:request:req-pv-2:candidate" not in published_events

            # Terminal workflow failure was emitted
            assert "scene:authoring:failed" in published_events

