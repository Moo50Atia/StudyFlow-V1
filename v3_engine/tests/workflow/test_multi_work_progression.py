import asyncio
import os
from typing import Any, Dict, List
import pytest

from temporalio import activity
from temporalio.client import WorkflowExecutionStatus
from temporalio.exceptions import ApplicationError
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Replayer, Worker

from v3_engine.workflows.case_workflow import CaseWorkflow

pytestmark = [pytest.mark.temporal, pytest.mark.asyncio]


executed_activities: List[Dict[str, Any]] = []
published_events: List[str] = []
prepare_lesson_attempts: Dict[int, int] = {}


@activity.defn(name="publish_workflow_started")
async def mock_publish_started(payload: dict) -> dict:
    executed_activities.append({"name": "publish_workflow_started", "payload": payload})
    return {"data": {"event_id": "ev-start-1", "case_id": payload["case_id"], "state_version": 2}}


@activity.defn(name="publish_case_event")
async def mock_publish_event(payload: dict) -> dict:
    executed_activities.append({"name": f"publish_event:{payload['event_key']}", "payload": payload})
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
    executed_activities.append({"name": "extract_case_source", "payload": payload})
    return {"status": "ready", "extracted_source_ref": "00000000-0000-0000-0000-000000000100"}


@activity.defn(name="ingest_case_source")
async def mock_ingest_case_source(payload: dict) -> dict:
    executed_activities.append({"name": "ingest_case_source", "payload": payload})
    return {"status": "bound", "binding_ref": "00000000-0000-0000-0000-000000000200"}


@activity.defn(name="map_case")
async def mock_map_case_three_items(payload: dict) -> dict:
    executed_activities.append({"name": "map_case", "payload": payload})
    return {
        "status": "ready",
        "map_ref": "00000000-0000-0000-0000-000000000300",
        "work_count": 3,
    }


@activity.defn(name="prepare_lesson")
async def mock_prepare_lesson_multi(payload: dict) -> dict:
    work_ordinal = payload.get("work_ordinal", 1)
    prepare_lesson_attempts[work_ordinal] = prepare_lesson_attempts.get(work_ordinal, 0) + 1
    executed_activities.append({"name": "prepare_lesson", "payload": payload})
    return {
        "status": "ready",
        "cursor_phase": "awaiting_010",
        "section_count": 2,
        "work_ordinal": work_ordinal,
    }


@activity.defn(name="generate_prompts")
async def mock_generate_prompts_multi(payload: dict) -> dict:
    work_ordinal = payload.get("work_ordinal", 1)
    executed_activities.append({"name": "generate_prompts", "payload": payload})
    return {
        "status": "completed",
        "work_ordinal": work_ordinal,
        "original_prompt_refs": [
            f"prompt-{work_ordinal}-sec-1",
            f"prompt-{work_ordinal}-sec-2",
        ],
    }


@activity.defn(name="qualify_prompts")
async def mock_qualify_prompts_multi(payload: dict) -> dict:
    work_ordinal = payload.get("work_ordinal", 1)
    executed_activities.append({"name": "qualify_prompts", "payload": payload})
    prompt_refs = payload.get("prompt_version_refs") or []

    # In work item 1, simulate a mixed outcome: section 1 passes, section 2 is blocked!
    if work_ordinal == 1:
        return {
            "status": "completed",
            "qualified_count": 1,
            "blocked_count": 1,
            "passing_prompt_refs": [prompt_refs[0]] if prompt_refs else ["prompt-1-sec-1"],
        }

    # For other work items, all pass
    return {
        "status": "completed",
        "qualified_count": len(prompt_refs),
        "blocked_count": 0,
        "passing_prompt_refs": prompt_refs,
    }


@activity.defn(name="resolve_qualified_prompt")
async def mock_resolve_qualified_prompt_multi(payload: dict) -> dict:
    executed_activities.append({"name": f"resolve_qualified_prompt:{payload['prompt_version_ref']}", "payload": payload})
    return {
        "status": "resolved",
        "case_id": payload["case_id"],
        "prompt_version_ref": payload["prompt_version_ref"],
    }


@activity.defn(name="authoring_list_eligible_prompts")
async def mock_authoring_list_eligible_prompts(payload: dict) -> list:
    executed_activities.append({"name": "authoring_list_eligible_prompts", "payload": payload})
    return []


@activity.defn(name="authoring_claim_request")
async def mock_authoring_claim_request(payload: dict) -> dict:
    executed_activities.append({"name": "authoring_claim_request", "payload": payload})
    return {"request_ref": "req-1"}


@activity.defn(name="authoring_execute_attempts")
async def mock_authoring_execute_attempts(payload: dict) -> dict:
    executed_activities.append({"name": "authoring_execute_attempts", "payload": payload})
    return {"status": "candidate_ready", "candidate_ref": "cand-1"}


@pytest.mark.asyncio
async def test_multi_work_progression_advances_through_all_mapped_items():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()
    prepare_lesson_attempts.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-multi-work-progression-queue"
        case_id = "00000000-0000-0000-0000-000000000070"
        src_act_id = "00000000-0000-0000-0000-000000000071"
        map_act_id = "00000000-0000-0000-0000-000000000072"
        wf_id = f"v3-case-{case_id}"

        activities = [
            mock_publish_started,
            mock_publish_event,
            mock_extract_case_source,
            mock_ingest_case_source,
            mock_map_case_three_items,
            mock_prepare_lesson_multi,
            mock_generate_prompts_multi,
            mock_qualify_prompts_multi,
            mock_resolve_qualified_prompt_multi,
            mock_authoring_list_eligible_prompts,
            mock_authoring_claim_request,
            mock_authoring_execute_attempts,
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
            # Step 1: source activation
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": src_act_id})
            await asyncio.sleep(0.5)

            # Step 2: mapping activation
            await handle.signal("mapping_activation_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "binding_ref": "00000000-0000-0000-0000-000000000200",
                "activation_id": map_act_id,
            })
            await asyncio.sleep(0.8)

            # Out-of-order signal: send work item 2 receipt before work item 1 receipt!
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 2,
                "cursor_version": 2,
                "completion_ref": "comp-prompt-w2",
                "third_output_refs": ["prompt-2-sec-1", "prompt-2-sec-2"],
            })

            # Send cross-case signal: must be rejected / ignored
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": "different-case-id-0000-000000000099",
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-cross-case",
            })

            # Send invalid schema signal: must be rejected / ignored
            await handle.signal("lesson_ready_v1", {
                "schema_version": 99,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-bad-schema",
            })

            # Now send valid work item 1 receipt
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-w1",
                "third_output_refs": ["prompt-1-sec-1", "prompt-1-sec-2"],
            })

            # Send duplicate work item 1 receipt: must be ignored idempotently
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-w1",
                "third_output_refs": ["prompt-1-sec-1", "prompt-1-sec-2"],
            })

            await asyncio.sleep(1.0)

            # Send work item 3 receipt
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 3,
                "cursor_version": 3,
                "completion_ref": "comp-prompt-w3",
                "third_output_refs": ["prompt-3-sec-1", "prompt-3-sec-2"],
            })

            await asyncio.sleep(1.5)

            # Assert all 3 work items were processed under their own identity:
            prepare_ordinals = [
                act["payload"]["work_ordinal"]
                for act in executed_activities
                if act["name"] == "prepare_lesson"
            ]
            assert prepare_ordinals == [1, 2, 3], f"Expected prepare_lesson for [1, 2, 3], got {prepare_ordinals}"

            generate_ordinals = [
                act["payload"]["work_ordinal"]
                for act in executed_activities
                if act["name"] == "generate_prompts"
            ]
            assert generate_ordinals == [1, 2, 3], f"Expected generate_prompts for [1, 2, 3], got {generate_ordinals}"

            qualify_ordinals = [
                act["payload"]["work_ordinal"]
                for act in executed_activities
                if act["name"] == "qualify_prompts"
            ]
            assert qualify_ordinals == [1, 2, 3], f"Expected qualify_prompts for [1, 2, 3], got {qualify_ordinals}"

            # Final item sends NO extra Continue (no prepare_lesson with work_ordinal=4)
            assert 4 not in prepare_ordinals

            # Milestone events published for each work item
            for ordinal in (1, 2, 3):
                assert f"prompt:work:{ordinal}:started" in published_events
                assert f"prompt:work:{ordinal}:originals" in published_events

            # Blocked section in work item 1 did not prevent passing section resolution:
            assert "resolve_qualified_prompt:prompt-1-sec-1" in [act["name"] for act in executed_activities]
            # And blocked section was NOT resolved:
            assert "resolve_qualified_prompt:prompt-1-sec-2" not in [act["name"] for act in executed_activities]

            # And work items 2 and 3 sections were resolved:
            assert "resolve_qualified_prompt:prompt-2-sec-1" in [act["name"] for act in executed_activities]
            assert "resolve_qualified_prompt:prompt-3-sec-1" in [act["name"] for act in executed_activities]

            # Replay the persisted production-workflow history, including all
            # three lesson receipts and the blocked sibling in item 1. Direct
            # method tests cannot detect Temporal command-sequence drift.
            await handle.result()
            history = await handle.fetch_history()
            replay_result = await Replayer(workflows=[CaseWorkflow]).replay_workflow(history)
            assert replay_result.replay_failure is None


@pytest.mark.asyncio
async def test_multi_work_progression_with_worker_restart():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-worker-restart-queue"
        case_id = "00000000-0000-0000-0000-000000000080"
        src_act_id = "00000000-0000-0000-0000-000000000081"
        map_act_id = "00000000-0000-0000-0000-000000000082"
        wf_id = f"v3-case-{case_id}"

        activities = [
            mock_publish_started,
            mock_publish_event,
            mock_extract_case_source,
            mock_ingest_case_source,
            mock_map_case_three_items,
            mock_prepare_lesson_multi,
            mock_generate_prompts_multi,
            mock_qualify_prompts_multi,
            mock_resolve_qualified_prompt_multi,
            mock_authoring_list_eligible_prompts,
            mock_authoring_claim_request,
            mock_authoring_execute_attempts,
        ]

        # Start first worker
        worker1 = Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=activities,
        )
        async with worker1:
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
                "binding_ref": "00000000-0000-0000-0000-000000000200",
                "activation_id": map_act_id,
            })
            await asyncio.sleep(0.8)

            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-w1",
                "third_output_refs": ["prompt-1-sec-1", "prompt-1-sec-2"],
            })
            await asyncio.sleep(0.8)

        # Worker 1 has stopped here!
        # Now start Worker 2 on the same task queue
        worker2 = Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=activities,
        )
        async with worker2:
            # Send signals for work 2 and 3
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 2,
                "cursor_version": 2,
                "completion_ref": "comp-prompt-w2",
                "third_output_refs": ["prompt-2-sec-1", "prompt-2-sec-2"],
            })
            await asyncio.sleep(0.8)

            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 3,
                "cursor_version": 3,
                "completion_ref": "comp-prompt-w3",
                "third_output_refs": ["prompt-3-sec-1", "prompt-3-sec-2"],
            })
            await asyncio.sleep(1.2)

            prepare_ordinals = [
                act["payload"]["work_ordinal"]
                for act in executed_activities
                if act["name"] == "prepare_lesson"
            ]
            assert prepare_ordinals == [1, 2, 3]


@activity.defn(name="prepare_lesson")
async def mock_prepare_lesson_with_uncertain_recovery(payload: dict) -> dict:
    work_ordinal = payload.get("work_ordinal", 1)
    attempts = prepare_lesson_attempts.get(work_ordinal, 0) + 1
    prepare_lesson_attempts[work_ordinal] = attempts
    executed_activities.append({"name": "prepare_lesson", "payload": payload, "attempt": attempts})

    # For work ordinal 2, attempt 1 returns uncertain; attempt 2 reconciles and succeeds!
    if work_ordinal == 2 and attempts == 1:
        raise ApplicationError("Continue query uncertain: network timeout after dispatch", non_retryable=False)

    return {
        "status": "ready",
        "cursor_phase": "awaiting_010",
        "section_count": 2,
        "work_ordinal": work_ordinal,
    }


@pytest.mark.asyncio
async def test_uncertain_continue_recovery():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()
    prepare_lesson_attempts.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-uncertain-continue-queue"
        case_id = "00000000-0000-0000-0000-000000000090"
        src_act_id = "00000000-0000-0000-0000-000000000091"
        map_act_id = "00000000-0000-0000-0000-000000000092"
        wf_id = f"v3-case-{case_id}"

        activities = [
            mock_publish_started,
            mock_publish_event,
            mock_extract_case_source,
            mock_ingest_case_source,
            mock_map_case_three_items,
            mock_prepare_lesson_with_uncertain_recovery,
            mock_generate_prompts_multi,
            mock_qualify_prompts_multi,
            mock_resolve_qualified_prompt_multi,
            mock_authoring_list_eligible_prompts,
            mock_authoring_claim_request,
            mock_authoring_execute_attempts,
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
                    "retry_policy": {"technical_retries": 3, "retry_delay_seconds": 1},
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
                "binding_ref": "00000000-0000-0000-0000-000000000200",
                "activation_id": map_act_id,
            })
            await asyncio.sleep(0.8)

            # Signal work item 1
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-prompt-w1",
                "third_output_refs": ["prompt-1-sec-1", "prompt-1-sec-2"],
            })
            await asyncio.sleep(2.0)

            # Signal work item 2
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 2,
                "cursor_version": 2,
                "completion_ref": "comp-prompt-w2",
                "third_output_refs": ["prompt-2-sec-1", "prompt-2-sec-2"],
            })
            await asyncio.sleep(1.0)

            # Signal work item 3
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "00000000-0000-0000-0000-000000000300",
                "work_ordinal": 3,
                "cursor_version": 3,
                "completion_ref": "comp-prompt-w3",
                "third_output_refs": ["prompt-3-sec-1", "prompt-3-sec-2"],
            })
            await asyncio.sleep(1.5)

            # Verify work_ordinal 2 had 2 attempts (first failed with uncertain, second succeeded)
            assert prepare_lesson_attempts.get(2) == 2
            # All 3 items were prepared
            prepare_ordinals = [
                act["payload"]["work_ordinal"]
                for act in executed_activities
                if act["name"] == "prepare_lesson"
            ]
            assert 1 in prepare_ordinals
            assert 2 in prepare_ordinals
            assert 3 in prepare_ordinals

            await handle.result()
            replay_result = await Replayer(workflows=[CaseWorkflow]).replay_workflow(
                await handle.fetch_history()
            )
            assert replay_result.replay_failure is None
