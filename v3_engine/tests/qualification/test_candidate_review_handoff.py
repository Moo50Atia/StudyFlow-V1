import asyncio
import os
from typing import Any, Dict, List, Optional
import pytest

from temporalio import activity
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Replayer, Worker

from v3_engine.workflows.case_workflow import CaseWorkflow

pytestmark = [pytest.mark.asyncio, pytest.mark.temporal]


class HandoffTracker:
    def __init__(self):
        self.call_order: List[str] = []
        self.published_events: List[str] = []
        self.reviews_ready: List[str] = []
        self.repairs_claimed: List[Dict[str, Any]] = []

    def reset(self):
        self.call_order.clear()
        self.published_events.clear()
        self.reviews_ready.clear()
        self.repairs_claimed.clear()


tracker = HandoffTracker()


@pytest.fixture(autouse=True)
def reset_tracker():
    tracker.reset()


# Mocks for activities preceding qualification
@activity.defn(name="publish_workflow_started")
async def mock_publish_started(payload: dict) -> dict:
    tracker.call_order.append("publish_workflow_started")
    return {"data": {"event_id": "ev-start-1", "case_id": payload["case_id"], "state_version": 2}}


@activity.defn(name="publish_case_event")
async def mock_publish_event(payload: dict) -> dict:
    key = payload["event_key"]
    tracker.call_order.append(f"publish_event:{key}")
    tracker.published_events.append(key)
    return {
        "data": {
            "event_id": f"ev-{key}",
            "case_id": payload["case_id"],
            "state_version": payload["expected_state_version"] + 1,
        }
    }


@activity.defn(name="extract_case_source")
async def mock_extract_case_source(payload: dict) -> dict:
    return {"status": "ready", "extracted_source_ref": "00000000-0000-0000-0000-000000000100"}


@activity.defn(name="ingest_case_source")
async def mock_ingest_case_source(payload: dict) -> dict:
    return {"status": "bound", "binding_ref": "00000000-0000-0000-0000-000000000200"}


@activity.defn(name="map_case")
async def mock_map_case(payload: dict) -> dict:
    return {"status": "ready", "map_ref": "00000000-0000-0000-0000-000000000300", "work_count": 1}


@activity.defn(name="prepare_lesson")
async def mock_prepare_lesson(payload: dict) -> dict:
    return {"status": "ready", "cursor_phase": "awaiting_010", "section_count": 1}


@activity.defn(name="generate_prompts")
async def mock_generate_prompts(payload: dict) -> dict:
    return {"status": "ready", "work_ordinal": payload.get("work_ordinal", 1)}


@activity.defn(name="qualify_prompts")
async def mock_qualify_prompts(payload: dict) -> dict:
    passing_refs = payload.get("passing_prompt_refs", ["pv-1"])
    return {
        "status": "completed",
        "qualified_count": len(passing_refs),
        "blocked_count": 0,
        "passing_prompt_refs": passing_refs,
    }


@activity.defn(name="resolve_qualified_prompt")
async def mock_resolve_qualified_prompt(payload: dict) -> dict:
    return {
        "status": "resolved",
        "case_id": payload["case_id"],
        "prompt_version_ref": payload["prompt_version_ref"],
    }


@activity.defn(name="authoring_list_eligible_prompts")
async def mock_authoring_list_eligible(payload: dict) -> List[dict]:
    return [
        {"prompt_version_ref": "pv-1", "section_ref": "sec-1", "work_ordinal": 1, "ordinal_in_work": 1},
    ]


@activity.defn(name="authoring_claim_request")
async def mock_authoring_claim_request(payload: dict) -> dict:
    pv_ref = payload["prompt_version_ref"]
    return {
        "request_ref": f"req-{pv_ref}",
        "section_ref": f"sec-{pv_ref}",
        "state": "pending",
        "settings_version_id": 1,
    }


@activity.defn(name="authoring_execute_attempts")
async def mock_authoring_execute_attempts(payload: dict) -> dict:
    req_ref = payload["request_ref"]
    return {
        "status": "candidate_ready",
        "candidate_ref": f"cand-{req_ref}",
        "safe_error_code": None,
    }


# Qualification Activities under test
@activity.defn(name="qualify_scene_activity")
async def mock_qualify_scene_activity(payload: dict) -> dict:
    cand_ref = payload["candidate_ref"]
    tracker.call_order.append(f"qualify_scene_activity:{cand_ref}")
    # Default behavior: direct qualification
    return {
        "status": "qualified",
        "candidate_ref": cand_ref,
        "check_ref": f"chk-{cand_ref}",
        "qualification_ref": f"qual-{cand_ref}",
    }


@activity.defn(name="repair_scene_activity")
async def mock_repair_scene_activity(payload: dict) -> dict:
    failed_ref = payload["failed_candidate_ref"]
    ordinal = payload["expected_ordinal"]
    tracker.call_order.append(f"repair_scene_activity:{failed_ref}:ord-{ordinal}")
    tracker.repairs_claimed.append(payload)
    return {
        "status": "candidate_ready",
        "candidate_ref": f"{failed_ref}-repaired-{ordinal}",
        "repair_ref": f"rep-{failed_ref}-{ordinal}",
        "ordinal": ordinal,
    }


@activity.defn(name="ensure_review_ready_activity")
async def mock_ensure_review_ready_activity(payload: dict) -> dict:
    cand_ref = payload["candidate_ref"]
    tracker.call_order.append(f"ensure_review_ready_activity:{cand_ref}")
    tracker.reviews_ready.append(cand_ref)
    return {
        "status": "ready",
        "data": {
            "review_ref": f"rev-{cand_ref}",
            "section_ref": "sec-1",
            "candidate_ref": cand_ref,
            "candidate_version": 1,
        },
    }


@pytest.mark.temporal
async def test_checks_pass_jev_pass_review_ready_ordering():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip("Temporal CLI not found")

    async with await WorkflowEnvironment.start_local(dev_server_existing_path=temporal_bin, ui=False) as env:
        task_queue = "test-handoff-queue-1"
        case_id = "00000000-0000-0000-0000-000000000081"
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

        async with Worker(env.client, task_queue=task_queue, workflows=[CaseWorkflow], activities=activities):
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
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": "act-1"})
            await asyncio.sleep(0.4)
            await handle.signal("mapping_activation_v1", {"schema_version": 1, "case_id": case_id, "binding_ref": "b-1", "activation_id": "map-1"})
            await asyncio.sleep(0.5)
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "map-1",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-1",
                "passing_prompt_refs": ["pv-1"],
            })

            # Wait for workflow progress
            await asyncio.sleep(2.0)

            # Assert exact sequence: candidate-ready -> qualify_scene -> milestone passed -> ensure_review_ready
            cand_ref = "cand-req-pv-1"
            assert f"qualify_scene_activity:{cand_ref}" in tracker.call_order
            assert f"publish_event:scene:qualification:{cand_ref}:passed" in tracker.call_order
            assert f"ensure_review_ready_activity:{cand_ref}" in tracker.call_order

            qual_idx = tracker.call_order.index(f"qualify_scene_activity:{cand_ref}")
            pass_idx = tracker.call_order.index(f"publish_event:scene:qualification:{cand_ref}:passed")
            review_idx = tracker.call_order.index(f"ensure_review_ready_activity:{cand_ref}")

            assert qual_idx < pass_idx < review_idx
            assert cand_ref in tracker.reviews_ready

            # The exact review identity must be registered and remain durable
            # until a matching committed decision is consumed. A successful
            # activity return or workflow completion is not consumption proof.
            review_ref = f"rev-{cand_ref}"
            decision_ref = "decision-for-review-handoff"
            assert await handle.query(CaseWorkflow.query_decision_consumed, decision_ref) is False
            await handle.signal("review_decision_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "section_ref": "sec-1",
                "review_ref": review_ref,
                "decision_ref": "stale-review-decision",
                "candidate_ref": "stale-candidate",
                "candidate_version": 1,
                "outcome": "approve",
            })
            assert await handle.query(CaseWorkflow.query_decision_consumed, "stale-review-decision") is False
            await handle.signal("review_decision_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "section_ref": "sec-1",
                "review_ref": review_ref,
                "decision_ref": decision_ref,
                "candidate_ref": cand_ref,
                "candidate_version": 1,
                "outcome": "approve",
            })
            assert await handle.query(CaseWorkflow.query_decision_consumed, decision_ref) is True
            await handle.result()
            replay_result = await Replayer(workflows=[CaseWorkflow]).replay_workflow(
                await handle.fetch_history()
            )
            assert replay_result.replay_failure is None


@pytest.mark.temporal
async def test_check_failure_triggers_repair_and_requalifies_successor():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip("Temporal CLI not found")

    qual_call_count = 0

    @activity.defn(name="qualify_scene_activity")
    async def mock_qualify_scene_with_repair(payload: dict) -> dict:
        nonlocal qual_call_count
        qual_call_count += 1
        cand_ref = payload["candidate_ref"]
        tracker.call_order.append(f"qualify_scene_activity:{cand_ref}")

        if qual_call_count == 1:
            # First candidate fails checks
            return {
                "status": "check_failed",
                "candidate_ref": cand_ref,
                "check_ref": f"chk-{cand_ref}",
                "qualification_ref": None,
            }
        else:
            # Repaired successor candidate passes
            return {
                "status": "qualified",
                "candidate_ref": cand_ref,
                "check_ref": f"chk-{cand_ref}",
                "qualification_ref": f"qual-{cand_ref}",
            }

    async with await WorkflowEnvironment.start_local(dev_server_existing_path=temporal_bin, ui=False) as env:
        task_queue = "test-handoff-queue-2"
        case_id = "00000000-0000-0000-0000-000000000082"
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
            mock_qualify_scene_with_repair,
            mock_repair_scene_activity,
            mock_ensure_review_ready_activity,
        ]

        async with Worker(env.client, task_queue=task_queue, workflows=[CaseWorkflow], activities=activities):
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
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": "act-1"})
            await asyncio.sleep(0.4)
            await handle.signal("mapping_activation_v1", {"schema_version": 1, "case_id": case_id, "binding_ref": "b-1", "activation_id": "map-1"})
            await asyncio.sleep(0.5)
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "map-1",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-1",
                "passing_prompt_refs": ["pv-1"],
            })

            await asyncio.sleep(2.5)

            first_cand = "cand-req-pv-1"
            successor_cand = f"{first_cand}-repaired-1"

            # Predecessor was qualified (failed checks)
            assert f"qualify_scene_activity:{first_cand}" in tracker.call_order
            # Repair was claimed
            assert f"repair_scene_activity:{first_cand}:ord-1" in tracker.call_order
            # Successor candidate was qualified
            assert f"qualify_scene_activity:{successor_cand}" in tracker.call_order
            # Passed milestone was published for successor, NOT predecessor
            assert f"publish_event:scene:qualification:{successor_cand}:passed" in tracker.call_order
            assert f"publish_event:scene:qualification:{first_cand}:passed" not in tracker.call_order
            # Only successor reached review ready
            assert successor_cand in tracker.reviews_ready
            assert first_cand not in tracker.reviews_ready


@pytest.mark.temporal
async def test_exhausted_repair_budget_blocks_section():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip("Temporal CLI not found")

    @activity.defn(name="qualify_scene_activity")
    async def mock_qualify_fail(payload: dict) -> dict:
        cand_ref = payload["candidate_ref"]
        tracker.call_order.append(f"qualify_scene_activity:{cand_ref}")
        return {
            "status": "check_failed",
            "candidate_ref": cand_ref,
            "check_ref": f"chk-{cand_ref}",
            "qualification_ref": None,
        }

    @activity.defn(name="repair_scene_activity")
    async def mock_repair_exhausted(payload: dict) -> dict:
        failed_ref = payload["failed_candidate_ref"]
        tracker.call_order.append(f"repair_scene_activity:{failed_ref}:ord-1")
        # Budget exhausted!
        return {
            "status": "blocked",
            "safe_error_code": "repair_budget_exhausted",
            "error": "Repair budget exhausted.",
        }

    async with await WorkflowEnvironment.start_local(dev_server_existing_path=temporal_bin, ui=False) as env:
        task_queue = "test-handoff-queue-3"
        case_id = "00000000-0000-0000-0000-000000000083"
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
            mock_qualify_fail,
            mock_repair_exhausted,
            mock_ensure_review_ready_activity,
        ]

        async with Worker(env.client, task_queue=task_queue, workflows=[CaseWorkflow], activities=activities):
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
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": "act-1"})
            await asyncio.sleep(0.4)
            await handle.signal("mapping_activation_v1", {"schema_version": 1, "case_id": case_id, "binding_ref": "b-1", "activation_id": "map-1"})
            await asyncio.sleep(0.5)
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "map-1",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-1",
                "passing_prompt_refs": ["pv-1"],
            })

            await asyncio.sleep(2.5)

            cand_ref = "cand-req-pv-1"
            assert f"qualify_scene_activity:{cand_ref}" in tracker.call_order
            assert f"repair_scene_activity:{cand_ref}:ord-1" in tracker.call_order
            # Review ready is NEVER called
            assert len(tracker.reviews_ready) == 0
            # Since all sections blocked, emits scene:qualification:all_blocked
            assert "scene:qualification:all_blocked" in tracker.published_events


@pytest.mark.temporal
async def test_mixed_section_outcomes():
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip("Temporal CLI not found")

    @activity.defn(name="authoring_list_eligible_prompts")
    async def mock_list_two_sections(payload: dict) -> List[dict]:
        return [
            {"prompt_version_ref": "pv-1", "section_ref": "sec-1", "work_ordinal": 1, "ordinal_in_work": 1},
            {"prompt_version_ref": "pv-2", "section_ref": "sec-2", "work_ordinal": 1, "ordinal_in_work": 2},
        ]

    @activity.defn(name="qualify_scene_activity")
    async def mock_mixed_qualify(payload: dict) -> dict:
        cand_ref = payload["candidate_ref"]
        tracker.call_order.append(f"qualify_scene_activity:{cand_ref}")
        if "pv-1" in cand_ref:
            # Section 1 candidate passes
            return {
                "status": "qualified",
                "candidate_ref": cand_ref,
                "check_ref": f"chk-{cand_ref}",
                "qualification_ref": f"qual-{cand_ref}",
            }
        else:
            # Section 2 candidate fails
            return {
                "status": "check_failed",
                "candidate_ref": cand_ref,
                "check_ref": f"chk-{cand_ref}",
                "qualification_ref": None,
            }

    @activity.defn(name="repair_scene_activity")
    async def mock_repair_sec2_exhausted(payload: dict) -> dict:
        failed_ref = payload["failed_candidate_ref"]
        tracker.call_order.append(f"repair_scene_activity:{failed_ref}:ord-1")
        return {"status": "blocked", "safe_error_code": "repair_budget_exhausted"}

    async with await WorkflowEnvironment.start_local(dev_server_existing_path=temporal_bin, ui=False) as env:
        task_queue = "test-handoff-queue-4"
        case_id = "00000000-0000-0000-0000-000000000084"
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
            mock_list_two_sections,
            mock_authoring_claim_request,
            mock_authoring_execute_attempts,
            mock_mixed_qualify,
            mock_repair_sec2_exhausted,
            mock_ensure_review_ready_activity,
        ]

        async with Worker(env.client, task_queue=task_queue, workflows=[CaseWorkflow], activities=activities):
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
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": "act-1"})
            await asyncio.sleep(0.4)
            await handle.signal("mapping_activation_v1", {"schema_version": 1, "case_id": case_id, "binding_ref": "b-1", "activation_id": "map-1"})
            await asyncio.sleep(0.5)
            await handle.signal("lesson_ready_v1", {
                "schema_version": 1,
                "case_id": case_id,
                "map_ref": "map-1",
                "work_ordinal": 1,
                "cursor_version": 1,
                "completion_ref": "comp-1",
                "passing_prompt_refs": ["pv-1", "pv-2"],
            })

            await asyncio.sleep(3.0)

            # Section 1 reached review ready
            assert "cand-req-pv-1" in tracker.reviews_ready
            assert "publish_event:scene:qualification:cand-req-pv-1:passed" in tracker.call_order

            # Section 2 failed qualification and exhausted repair
            assert "cand-req-pv-2" not in tracker.reviews_ready

            # Workflow did NOT emit all_blocked because Section 1 succeeded
            assert "scene:qualification:all_blocked" not in tracker.published_events
