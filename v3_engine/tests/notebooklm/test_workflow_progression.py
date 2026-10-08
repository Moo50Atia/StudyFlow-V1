"""Tests for direct NotebookLM pipeline multi-work progression and recovery per Spec 016 (N20)."""
import asyncio
import os
from typing import Any, Dict, List
import pytest

from temporalio import activity
from temporalio.client import WorkflowExecutionStatus
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Replayer, Worker

from v3_engine.workflows.case_workflow import CaseWorkflow

pytestmark = [pytest.mark.temporal, pytest.mark.asyncio]

executed_activities: List[Dict[str, Any]] = []
published_events: List[str] = []


@activity.defn(name="publish_workflow_started")
async def mock_publish_started(payload: dict) -> dict:
    executed_activities.append({"name": "publish_workflow_started", "payload": payload})
    return {"data": {"event_id": "ev-start", "case_id": payload["case_id"], "state_version": 2}}


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
async def mock_extract(payload: dict) -> dict:
    executed_activities.append({"name": "extract_case_source", "payload": payload})
    return {"status": "ready", "extracted_source_ref": "ext-src-1"}


@activity.defn(name="ingest_case_source")
async def mock_ingest(payload: dict) -> dict:
    executed_activities.append({"name": "ingest_case_source", "payload": payload})
    return {"status": "bound", "binding_ref": "binding-direct-1"}


@activity.defn(name="map_case")
async def mock_map(payload: dict) -> dict:
    executed_activities.append({"name": "map_case", "payload": payload})
    return {"status": "ready", "map_ref": "map-direct-1", "work_count": 3}


@activity.defn(name="prepare_lesson")
async def mock_prepare(payload: dict) -> dict:
    executed_activities.append({"name": "prepare_lesson", "payload": payload})
    return {"status": "ready", "cursor_phase": "awaiting_010", "section_count": 1}


@activity.defn(name="generate_prompts")
async def mock_generate(payload: dict) -> dict:
    executed_activities.append({"name": "generate_prompts", "payload": payload})
    return {"status": "completed", "work_ordinal": payload.get("work_ordinal", 1)}


@activity.defn(name="process_notebook_lesson")
async def mock_process_notebook_lesson(payload: dict) -> dict:
    executed_activities.append({"name": "process_notebook_lesson", "payload": payload})
    ordinal = payload.get("work_ordinal", 1)
    return {
        "status": "completed",
        "bundle_ref": f"bundle-{ordinal}",
        "completion_ref": f"comp-{ordinal}",
        "work_ordinal": ordinal,
        "cursor_version": ordinal + 2,
        "original_prompt_refs": [f"prompt-v1-work-{ordinal}"],
    }


@activity.defn(name="qualify_prompts")
async def mock_qualify(payload: dict) -> dict:
    executed_activities.append({"name": "qualify_prompts", "payload": payload})
    refs = payload.get("passing_prompt_refs") or [f"prompt-v1-work-{payload.get('work_ordinal', 1)}"]
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
    return [
        {
            "prompt_version_ref": f"prompt-v1-work-{payload.get('work_ordinal', 1)}",
            "section_ref": f"sec-{payload.get('work_ordinal', 1)}",
        }
    ]


@activity.defn(name="authoring_claim_request")
async def mock_auth_claim(payload: dict) -> dict:
    executed_activities.append({"name": "authoring_claim_request", "payload": payload})
    return {"status": "claimed", "request_ref": f"auth-req-{payload.get('work_ordinal', 1)}"}


@activity.defn(name="authoring_execute_attempts")
async def mock_auth_exec(payload: dict) -> dict:
    executed_activities.append({"name": "authoring_execute_attempts", "payload": payload})
    ordinal = payload.get("work_ordinal", 1)
    return {"status": "candidate_ready", "candidate_ref": f"cand-{ordinal}", "candidate_version": 1}


@activity.defn(name="qualify_scene_activity")
async def mock_qualify_scene(payload: dict) -> dict:
    executed_activities.append({"name": "qualify_scene_activity", "payload": payload})
    cand_ref = payload.get("candidate_ref", "cand-1")
    return {"status": "qualified", "candidate_ref": cand_ref}


@activity.defn(name="repair_scene_activity")
async def mock_repair_scene(payload: dict) -> dict:
    executed_activities.append({"name": "repair_scene_activity", "payload": payload})
    return {"status": "unrepairable"}


@activity.defn(name="ensure_review_ready_activity")
async def mock_ensure_review(payload: dict) -> dict:
    executed_activities.append({"name": "ensure_review_ready_activity", "payload": payload})
    cand_ref = payload.get("candidate_ref", "cand-1")
    ord_num = cand_ref.split("-")[-1] if "-" in cand_ref else "1"
    return {
        "status": "ready",
        "data": {
            "review_ref": f"rev-sec-{ord_num}",
            "section_ref": f"sec-{ord_num}",
            "candidate_ref": cand_ref,
            "candidate_version": 1,
        },
    }


@activity.defn(name="reconcile_notebook_query")
async def mock_reconcile_query(payload: dict) -> dict:
    executed_activities.append({"name": "reconcile_notebook_query", "payload": payload})
    return {"status": "recorded", "query_ref": payload.get("query_ref", "query-reconciled-1")}


DIRECT_ACTIVITIES = [
    mock_publish_started,
    mock_publish_event,
    mock_extract,
    mock_ingest,
    mock_map,
    mock_prepare,
    mock_generate,
    mock_process_notebook_lesson,
    mock_reconcile_query,
    mock_qualify,
    mock_resolve,
    mock_auth_list,
    mock_auth_claim,
    mock_auth_exec,
    mock_qualify_scene,
    mock_repair_scene,
    mock_ensure_review,
]


async def test_n20_direct_workflow_advances_three_work_items_via_process_notebook_lesson():
    """Verify that direct workflow processes 3 work items via process_notebook_lesson, skipping extraction."""
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-direct-progression-queue"
        case_id = "00000000-0000-0000-0000-000000000090"
        wf_id = f"v3-case-{case_id}"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=DIRECT_ACTIVITIES,
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": case_id,
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "pipeline_version": "notebooklm_combined_v1",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id=wf_id,
                task_queue=task_queue,
            )

            # Signal source_activation_v1
            await asyncio.sleep(0.3)
            await handle.signal(
                "source_activation_v1",
                {"case_id": case_id, "activation_id": "act-direct-1"},
            )

            # Signal mapping_activation_v1
            await asyncio.sleep(0.3)
            await handle.signal(
                "mapping_activation_v1",
                {"case_id": case_id, "activation_id": "map-act-direct-1", "binding_ref": "binding-direct-1"},
            )

            # For each of the 3 work items, deliver lesson_ready_v1 signal and review decision
            for ord_num in range(1, 4):
                await asyncio.sleep(0.5)
                await handle.signal(
                    "lesson_ready_v1",
                    {
                        "schema_version": 1,
                        "case_id": case_id,
                        "completion_ref": f"comp-{ord_num}",
                        "work_ordinal": ord_num,
                        "map_ref": "map-direct-1",
                        "cursor_version": ord_num + 2,
                    },
                )
                await asyncio.sleep(0.5)
                await handle.signal(
                    "review_decision_v1",
                    {
                        "schema_version": 1,
                        "case_id": case_id,
                        "section_ref": f"sec-{ord_num}",
                        "review_ref": f"rev-sec-{ord_num}",
                        "candidate_ref": f"cand-{ord_num}",
                        "candidate_version": 1,
                        "decision_ref": f"dec-{ord_num}",
                        "outcome": "approve",
                    },
                )

            await asyncio.wait_for(handle.result(), timeout=30.0)

            # 1. Extraction was NOT called
            act_names = [a["name"] for a in executed_activities]
            assert "extract_case_source" not in act_names, "Direct workflow must skip extraction"

            # 2. process_notebook_lesson was called for ordinals 1, 2, 3
            lesson_ordinals = [
                a["payload"]["work_ordinal"]
                for a in executed_activities
                if a["name"] == "process_notebook_lesson"
            ]
            assert lesson_ordinals == [1, 2, 3], f"Expected ordinals [1, 2, 3], got {lesson_ordinals}"

            # 3. generate_prompts was NOT called (replaced by combined lesson output)
            assert "generate_prompts" not in act_names

            # 4. History replayed cleanly
            history = await handle.fetch_history()
            replay_result = await Replayer(workflows=[CaseWorkflow]).replay_workflow(history)
            assert replay_result.replay_failure is None, f"Replay failure: {replay_result.replay_failure}"


async def test_n17_workflow_recovers_uncertain_lesson_via_reconcile_activity_and_query_consumed():
    """Verify that an uncertain lesson triggers reconcile_notebook_query and resumes once without repeat turns."""
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()

    call_counts: Dict[int, int] = {}

    @activity.defn(name="process_notebook_lesson")
    async def mock_process_lesson_with_recovery(payload: dict) -> dict:
        executed_activities.append({"name": "process_notebook_lesson", "payload": payload})
        ord_num = payload.get("work_ordinal", 1)
        call_counts[ord_num] = call_counts.get(ord_num, 0) + 1
        if ord_num == 1 and call_counts[ord_num] == 1:
            return {
                "status": "uncertain",
                "kind": "lesson_start",
                "work_ordinal": 1,
                "query_ref": "query-uncertain-w1",
            }
        return {
            "status": "completed",
            "bundle_ref": f"bundle-{ord_num}",
            "completion_ref": f"comp-{ord_num}",
            "work_ordinal": ord_num,
            "cursor_version": ord_num + 2,
            "original_prompt_refs": [f"prompt-v1-work-{ord_num}"],
        }

    @activity.defn(name="map_case")
    async def mock_single_map(payload: dict) -> dict:
        executed_activities.append({"name": "map_case", "payload": payload})
        return {"status": "ready", "map_ref": "map-direct-1", "work_count": 1}

    recovery_activities = [
        mock_publish_started,
        mock_publish_event,
        mock_extract,
        mock_ingest,
        mock_single_map,
        mock_prepare,
        mock_generate,
        mock_process_lesson_with_recovery,
        mock_reconcile_query,
        mock_qualify,
        mock_resolve,
        mock_auth_list,
        mock_auth_claim,
        mock_auth_exec,
        mock_qualify_scene,
        mock_repair_scene,
        mock_ensure_review,
    ]

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-direct-recovery-queue"
        case_id = "00000000-0000-0000-0000-000000000091"
        wf_id = f"v3-case-{case_id}"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=recovery_activities,
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": case_id,
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "pipeline_version": "notebooklm_combined_v1",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id=wf_id,
                task_queue=task_queue,
            )

            await asyncio.sleep(0.3)
            await handle.signal(
                "source_activation_v1",
                {"case_id": case_id, "activation_id": "act-direct-1"},
            )

            await asyncio.sleep(0.3)
            await handle.signal(
                "mapping_activation_v1",
                {"case_id": case_id, "activation_id": "map-act-direct-1", "binding_ref": "binding-direct-1"},
            )

            await asyncio.sleep(0.5)
            await handle.signal(
                "notebook_reconciled_v1",
                {
                    "schema_version": 1,
                    "case_id": case_id,
                    "query_ref": "query-uncertain-w1",
                    "work_ordinal": 1,
                    "cursor_version": 3,
                    "completion_ref": "comp-1",
                },
            )

            consumed = await handle.query("consumed_query", "query-uncertain-w1")
            assert consumed is True, "consumed_query query must return True after notebook_reconciled_v1"

            await asyncio.sleep(0.5)
            await handle.signal(
                "review_decision_v1",
                {
                    "schema_version": 1,
                    "case_id": case_id,
                    "section_ref": "sec-1",
                    "review_ref": "rev-sec-1",
                    "candidate_ref": "cand-1",
                    "candidate_version": 1,
                    "decision_ref": "dec-1",
                    "outcome": "approve",
                },
            )

            await asyncio.wait_for(handle.result(), timeout=30.0)

            reconcile_calls = [a for a in executed_activities if a["name"] == "reconcile_notebook_query"]
            assert len(reconcile_calls) == 1
            assert reconcile_calls[0]["payload"]["kind"] == "lesson_start"
            assert reconcile_calls[0]["payload"]["work_ordinal"] == 1

            history = await handle.fetch_history()
            replay_result = await Replayer(workflows=[CaseWorkflow]).replay_workflow(history)
            assert replay_result.replay_failure is None, f"Replay failure: {replay_result.replay_failure}"


async def test_n23_connected_three_work_progression_with_registered_activities(monkeypatch):
    """Verify that CaseWorkflow advances 3 works using registered production process_notebook_lesson activity with transport doubles."""
    from contextlib import asynccontextmanager
    from types import SimpleNamespace
    from unittest.mock import AsyncMock
    from v3_engine.activities.process_notebook_lesson import process_notebook_lesson
    from v3_engine.notebooklm.sdk_provider import SdkProviderSession

    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    fixture_dir = os.path.join(
        os.path.dirname(__file__),
        "../../..",
        "tests",
        "Fixtures",
        "V3",
        "NotebookLM",
    )
    with open(os.path.join(fixture_dir, "valid_combined_lesson_1.txt"), "r", encoding="utf-8") as f:
        text1 = f.read()
    with open(os.path.join(fixture_dir, "valid_combined_lesson_2.txt"), "r", encoding="utf-8") as f:
        text2 = f.read()
    with open(os.path.join(fixture_dir, "valid_combined_lesson_3.txt"), "r", encoding="utf-8") as f:
        text3 = f.read()

    lesson_texts = {1: text1, 2: text2, 3: text3}
    sent_questions: List[str] = []
    from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT

    conversation_history: List[tuple[str, str]] = [
        (DIVIDE_PROMPT, "map-outline"),
        (EXECUTION_PROMPT, "System Ready"),
    ]

    class TransportDoubleLaravel:
        def __init__(self):
            self.claims: List[tuple] = []
            self.commits: List[tuple] = []

        async def get_lesson_context(self, case_id: str, ordinal: int):
            return {
                "cursor_version": ordinal * 2,
                "conversation_ref": "convo-conn-1",
                "work_node_ref": f"node-{ordinal}",
                "bundle_ref": None,
                "completion_ref": None,
            }

        async def get_binding_by_ref(self, case_id: str, binding_ref: str):
            return {
                "notebook_id": "nb-conn-1",
                "framework_sha256": "96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec",
                "manifest": [
                    {"role": "educational_pdf", "provider_source_id": "pdf-src-1"},
                    {"role": "framework_steps", "provider_source_id": "steps-src-1"},
                ],
            }

        async def find_lesson_query(self, case_id: str, kind: str, ordinal: int):
            return None

        async def claim_lesson_query(self, case_id: str, kind: str, ordinal: int, body: dict):
            self.claims.append((kind, ordinal))
            return {
                "query_ref": f"query-ord-{ordinal}",
                "disposition": "send_now",
                "claimed_cursor_version": body["expected_cursor_version"],
            }

        async def record_combined_lesson_result(self, query_ref: str, payload: dict):
            self.commits.append((query_ref, payload["expected_cursor_version"]))
            return {
                "bundle_ref": f"bundle-conn-{query_ref}",
                "completion_ref": f"comp-conn-{query_ref}",
                "cursor_version": payload["expected_cursor_version"] + 1,
                "section_refs": [f"sec-{query_ref}"],
                "original_prompt_refs": [f"prompt-{query_ref}"],
            }

    fake_laravel = TransportDoubleLaravel()

    class TransportDoubleProvider:
        @asynccontextmanager
        async def open(self, profile_ref=None):
            sdk = AsyncMock()
            turn_counter = 0

            async def ask(nb, question, *, source_ids, conversation_id=None):
                nonlocal turn_counter
                turn_counter += 1
                sent_questions.append(question)
                ans = lesson_texts.get(turn_counter, text1)
                conversation_history.append((question, ans))
                return SimpleNamespace(
                    answer=ans,
                    text=ans,
                    conversation_id="convo-conn-1",
                    turn_number=turn_counter,
                )

            sdk.chat.ask.side_effect = ask
            sdk.chat.get_history.return_value = conversation_history
            yield SdkProviderSession(sdk)

    monkeypatch.setattr(
        "v3_engine.activities.process_notebook_lesson.HttpLaravelNotebookClient",
        lambda: fake_laravel,
    )
    monkeypatch.setattr(
        "v3_engine.activities.process_notebook_lesson.SdkSessionProvider",
        TransportDoubleProvider,
    )

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-registered-progression-queue"
        case_id = "00000000-0000-0000-0000-000000000099"
        wf_id = f"v3-case-{case_id}"

        # Use actual registered process_notebook_lesson activity
        registered_activities = [
            mock_publish_started,
            mock_publish_event,
            mock_extract,
            mock_ingest,
            mock_map,
            mock_prepare,
            mock_generate,
            process_notebook_lesson,  # REAL registered activity!
            mock_reconcile_query,
            mock_qualify,
            mock_resolve,
            mock_auth_list,
            mock_auth_claim,
            mock_auth_exec,
            mock_qualify_scene,
            mock_repair_scene,
            mock_ensure_review,
        ]

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=registered_activities,
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": case_id,
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "pipeline_version": "notebooklm_combined_v1",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id=wf_id,
                task_queue=task_queue,
            )

            await asyncio.sleep(0.3)
            await handle.signal(
                "source_activation_v1",
                {"case_id": case_id, "activation_id": "act-reg-1"},
            )

            await asyncio.sleep(0.3)
            await handle.signal(
                "mapping_activation_v1",
                {"case_id": case_id, "activation_id": "map-reg-1", "binding_ref": "b-reg-1"},
            )

            # Signal 3 work items
            for ord_num in range(1, 4):
                await asyncio.sleep(0.5)
                await handle.signal(
                    "lesson_ready_v1",
                    {
                        "schema_version": 1,
                        "case_id": case_id,
                        "completion_ref": f"comp-conn-query-ord-{ord_num}",
                        "work_ordinal": ord_num,
                        "map_ref": "map-direct-1",
                        "cursor_version": ord_num * 2 + 1,
                    },
                )
                await asyncio.sleep(0.5)
                await handle.signal(
                    "review_decision_v1",
                    {
                        "schema_version": 1,
                        "case_id": case_id,
                        "section_ref": f"sec-{ord_num}",
                        "review_ref": f"rev-sec-{ord_num}",
                        "candidate_ref": f"cand-{ord_num}",
                        "candidate_version": 1,
                        "decision_ref": f"dec-{ord_num}",
                        "outcome": "approve",
                    },
                )

            await asyncio.wait_for(handle.result(), timeout=30.0)

            # Verify registered process_notebook_lesson called for all 3 works
            assert fake_laravel.claims == [
                ("lesson_start", 1),
                ("lesson_continue", 2),
                ("lesson_continue", 3),
            ]
            assert sent_questions == [
                "Start with First lesson",
                "Continue",
                "Continue",
            ]
            assert len(fake_laravel.commits) == 3

            # Verify history replays cleanly with zero non-determinism
            history = await handle.fetch_history()
            replay_result = await Replayer(workflows=[CaseWorkflow]).replay_workflow(history)
            assert replay_result.replay_failure is None


async def test_n20_downstream_candidate_repair_and_teacher_decision_consumption():
    """Verify downstream candidate failure -> repair -> qualification and review decision consumption per T027/T028."""
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()

    qual_attempts: Dict[str, int] = {}

    @activity.defn(name="qualify_scene_activity")
    async def mock_qualify_with_repair(payload: dict) -> dict:
        executed_activities.append({"name": "qualify_scene_activity", "payload": payload})
        cand_ref = payload.get("candidate_ref", "cand-1")
        qual_attempts[cand_ref] = qual_attempts.get(cand_ref, 0) + 1
        if cand_ref == "cand-1":
            return {"status": "check_failed", "candidate_ref": cand_ref}
        return {"status": "qualified", "candidate_ref": cand_ref}

    @activity.defn(name="repair_scene_activity")
    async def mock_repair_scene_success(payload: dict) -> dict:
        executed_activities.append({"name": "repair_scene_activity", "payload": payload})
        failed_ref = payload.get("failed_candidate_ref", "cand-1")
        return {
            "status": "candidate_ready",
            "candidate_ref": f"{failed_ref}-v2",
            "candidate_version": 2,
        }

    @activity.defn(name="ensure_review_ready_activity")
    async def mock_ensure_review_custom(payload: dict) -> dict:
        executed_activities.append({"name": "ensure_review_ready_activity", "payload": payload})
        cand_ref = payload.get("candidate_ref", "cand-1")
        ord_num = cand_ref.split("-")[1] if "-" in cand_ref else "1"
        return {
            "status": "ready",
            "data": {
                "review_ref": f"rev-sec-{ord_num}",
                "section_ref": f"sec-{ord_num}",
                "candidate_ref": cand_ref,
                "candidate_version": 2 if "-v2" in cand_ref else 1,
            },
        }

    activities_with_repair = [
        mock_publish_started,
        mock_publish_event,
        mock_extract,
        mock_ingest,
        mock_map,
        mock_prepare,
        mock_generate,
        mock_process_notebook_lesson,
        mock_reconcile_query,
        mock_qualify,
        mock_resolve,
        mock_auth_list,
        mock_auth_claim,
        mock_auth_exec,
        mock_qualify_with_repair,
        mock_repair_scene_success,
        mock_ensure_review_custom,
    ]

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-downstream-repair-queue"
        case_id = "00000000-0000-0000-0000-000000000098"
        wf_id = f"v3-case-{case_id}"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=activities_with_repair,
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": case_id,
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "pipeline_version": "notebooklm_combined_v1",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id=wf_id,
                task_queue=task_queue,
            )

            await asyncio.sleep(0.3)
            await handle.signal(
                "source_activation_v1",
                {"case_id": case_id, "activation_id": "act-repair-1"},
            )

            await asyncio.sleep(0.3)
            await handle.signal(
                "mapping_activation_v1",
                {"case_id": case_id, "activation_id": "map-repair-1", "binding_ref": "b-repair-1"},
            )

            for ord_num in range(1, 4):
                await asyncio.sleep(0.5)
                await handle.signal(
                    "lesson_ready_v1",
                    {
                        "schema_version": 1,
                        "case_id": case_id,
                        "completion_ref": f"comp-{ord_num}",
                        "work_ordinal": ord_num,
                        "map_ref": "map-direct-1",
                        "cursor_version": ord_num + 2,
                    },
                )
                await asyncio.sleep(0.5)
                cand_ref = "cand-1-v2" if ord_num == 1 else f"cand-{ord_num}"
                cand_ver = 2 if ord_num == 1 else 1
                decision_ref = f"dec-{ord_num}"
                await handle.signal(
                    "review_decision_v1",
                    {
                        "schema_version": 1,
                        "case_id": case_id,
                        "section_ref": f"sec-{ord_num}",
                        "review_ref": f"rev-sec-{ord_num}",
                        "candidate_ref": cand_ref,
                        "candidate_version": cand_ver,
                        "decision_ref": decision_ref,
                        "outcome": "request_changes" if ord_num == 1 else "approve",
                    },
                )

                consumed = await handle.query("query_decision_consumed", decision_ref)
                assert consumed is True, f"Workflow must confirm consumption of exact decision {decision_ref}"

                if ord_num == 1:
                    successor_decision_ref = "dec-1-successor"
                    await handle.signal(
                        "review_decision_v1",
                        {
                            "schema_version": 1,
                            "case_id": case_id,
                            "section_ref": "sec-1",
                            "review_ref": "rev-sec-1-successor",
                            "candidate_ref": "cand-1-v3",
                            "candidate_version": 3,
                            "decision_ref": successor_decision_ref,
                            "outcome": "approve",
                        },
                    )
                    assert await handle.query("query_decision_consumed", successor_decision_ref) is True

            await asyncio.wait_for(handle.result(), timeout=30.0)

            repair_calls = [a for a in executed_activities if a["name"] == "repair_scene_activity"]
            assert len(repair_calls) == 1
            assert repair_calls[0]["payload"]["failed_candidate_ref"] == "cand-1"

            qual_cands = [a["payload"]["candidate_ref"] for a in executed_activities if a["name"] == "qualify_scene_activity"]
            assert "cand-1" in qual_cands
            assert "cand-1-v2" in qual_cands

            for ord_num in range(1, 4):
                assert await handle.query("query_decision_consumed", f"dec-{ord_num}") is True
            assert await handle.query("query_decision_consumed", "dec-nonexistent") is False

            history = await handle.fetch_history()
            replay_result = await Replayer(workflows=[CaseWorkflow]).replay_workflow(history)
            assert replay_result.replay_failure is None


