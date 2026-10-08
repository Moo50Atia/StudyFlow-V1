from datetime import timedelta
from typing import Any, Dict, Optional
from temporalio import workflow
from temporalio.common import RetryPolicy


def _direct_milestone_error(code: str | None) -> str:
    if code in {"authentication_required", "provider_unavailable", "provider_uncertain",
                "source_upload_failed", "notebook_create_failed", "source_not_found",
                "chat_ask_failed", "chat_history_failed"}:
        return "provider_unavailable"
    if code in {"validation", "protocol_validation", "source_conflict",
                "source_processing_failed", "conversation_conflict", "lesson_conflict",
                "lesson_unavailable"}:
        return "validation"
    return "internal"

with workflow.unsafe.imports_passed_through():
    from v3_engine.activities.publish_milestone import (
        publish_workflow_started,
        publish_case_event,
    )
    from v3_engine.activities.extract_case_source import extract_case_source
    from v3_engine.activities.ingest_case_source import ingest_case_source
    from v3_engine.activities.map_case import map_case
    from v3_engine.activities.prepare_lesson import prepare_lesson
    from v3_engine.activities.process_notebook_lesson import process_notebook_lesson
    from v3_engine.activities.reconcile_notebook_query import reconcile_notebook_query
    from v3_engine.prompts.activities import (
        generate_prompts,
        qualify_prompts,
        resolve_qualified_prompt,
    )
    from v3_engine.authoring.activities import (
        authoring_list_eligible_prompts,
        authoring_claim_request,
        authoring_execute_attempts,
    )
    from v3_engine.qualification.activity import (
        qualify_scene_activity,
        repair_scene_activity,
        ensure_review_ready_activity,
    )
    from v3_engine.admin.control import AdminControlTracker

@workflow.defn
class CaseWorkflow:
    def __init__(self) -> None:
        self.activation: Optional[Dict[str, Any]] = None
        self.map_activation: Optional[Dict[str, Any]] = None
        self.lesson_ready: Optional[Dict[str, Any]] = None
        self.lesson_receipts: Dict[int, Dict[str, Any]] = {}
        self.reconciled_queries: Dict[str, Dict[str, Any]] = {}
        self.case_id: Optional[str] = None
        self.review_expectations: Dict[str, Dict[str, Any]] = {}
        self.latest_review_by_section: Dict[str, str] = {}
        self.pending_review_signals: Dict[str, Dict[str, Any]] = {}
        self.consumed_decisions: Dict[str, Dict[str, Any]] = {}
        self.admin_control = AdminControlTracker()

    @workflow.signal(name="notebook_reconciled_v1")
    def receive_notebook_reconciled(self, payload: Dict[str, Any]) -> None:
        if not isinstance(payload, dict) or payload.get("schema_version") != 1:
            return
        query_ref = payload.get("query_ref")
        if not isinstance(query_ref, str) or not query_ref:
            return
        self.reconciled_queries[query_ref] = payload
        work_ordinal = payload.get("work_ordinal")
        if isinstance(work_ordinal, int) and work_ordinal >= 1:
            if work_ordinal not in self.lesson_receipts:
                self.lesson_receipts[work_ordinal] = payload

    @workflow.query(name="consumed_query")
    def query_consumed_query(self, query_ref: str) -> bool:
        return query_ref in self.reconciled_queries

    @workflow.signal(name="admin_control_v1")
    def receive_admin_control(self, payload: Dict[str, Any]) -> None:
        self.admin_control.receive_signal(payload)

    @workflow.query(name="query_admin_control")
    def query_admin_control(self, command_ref: str) -> Optional[Dict[str, Any]]:
        return self.admin_control.query_command(command_ref)

    @workflow.signal(name="review_decision_v1")
    def receive_review_decision(self, payload: Dict[str, Any]) -> None:
        if not isinstance(payload, dict) or payload.get("schema_version") != 1:
            return
        decision_ref = payload.get("decision_ref")
        review_ref = payload.get("review_ref")
        if not isinstance(decision_ref, str) or not isinstance(review_ref, str):
            return
        expectation = self.review_expectations.get(review_ref)
        if expectation is None:
            # A teacher-requested correction creates a successor review item
            # after this workflow has entered its approval wait. Accept that
            # successor only for a section whose prior exact decision was
            # request_changes; the signed Laravel outbox forwards the same
            # decision to this case workflow after the correction workflow
            # proves consumption.
            section_ref = payload.get("section_ref")
            prior_change = any(
                item.get("section_ref") == section_ref
                and item.get("outcome") == "request_changes"
                for item in self.consumed_decisions.values()
            )
            if (
                isinstance(section_ref, str)
                and payload.get("case_id") == self.case_id
                and prior_change
                and isinstance(payload.get("candidate_ref"), str)
                and isinstance(payload.get("candidate_version"), int)
                and not isinstance(payload.get("candidate_version"), bool)
            ):
                self._register_review(payload)
                expected = self.review_expectations.get(review_ref)
                if expected is not None:
                    self._consume_review_decision(expected, payload)
                return
            prior = self.pending_review_signals.get(decision_ref)
            if prior is None:
                self.pending_review_signals[decision_ref] = payload
            return
        self._consume_review_decision(expectation, payload)

    @workflow.query(name="query_decision_consumed")
    def query_decision_consumed(self, decision_ref: str) -> bool:
        return decision_ref in self.consumed_decisions

    def _consume_review_decision(self, expected: Dict[str, Any], payload: Dict[str, Any]) -> None:
        fields = ("case_id", "section_ref", "review_ref", "candidate_ref", "candidate_version")
        if any(payload.get(k) != expected.get(k) for k in fields):
            return
        decision_ref = payload.get("decision_ref")
        if not isinstance(decision_ref, str) or payload.get("outcome") not in ("approve", "request_changes"):
            return
        existing = self.consumed_decisions.get(decision_ref)
        if existing is not None:
            return
        # Consumption means the exact committed review identity passed every
        # workflow fence and was applied to the durable review gate.
        self.consumed_decisions[decision_ref] = {
            "review_ref": expected["review_ref"],
            "section_ref": expected["section_ref"],
            "candidate_ref": expected["candidate_ref"],
            "candidate_version": expected["candidate_version"],
            "outcome": payload["outcome"],
        }

    def _register_review(self, payload: Dict[str, Any]) -> None:
        review_ref = payload.get("review_ref")
        if not review_ref:
            return
        expected = {
            "case_id": self.case_id,
            "section_ref": payload.get("section_ref"),
            "review_ref": review_ref,
            "candidate_ref": payload.get("candidate_ref"),
            "candidate_version": payload.get("candidate_version"),
        }
        self.review_expectations[review_ref] = expected
        section_ref = expected.get("section_ref")
        if isinstance(section_ref, str) and section_ref:
            self.latest_review_by_section[section_ref] = review_ref
        for signal in list(self.pending_review_signals.values()):
            if signal.get("review_ref") == review_ref:
                self._consume_review_decision(expected, signal)
                self.pending_review_signals.pop(signal.get("decision_ref"), None)

    @workflow.signal(name="source_activation_v1")
    def receive_source_activation(self, payload: Dict[str, Any]) -> None:
        case_id = payload.get("case_id")
        activation_id = payload.get("activation_id")
        if not case_id or not activation_id:
            return

        if self.activation is not None:
            # Duplicate or conflict: ignore
            return

        self.activation = payload

    @workflow.signal(name="mapping_activation_v1")
    def receive_mapping_activation(self, payload: Dict[str, Any]) -> None:
        case_id = payload.get("case_id")
        activation_id = payload.get("activation_id")
        if not case_id or not activation_id:
            return

        if self.map_activation is not None:
            # Duplicate or conflict: ignore
            return

        self.map_activation = payload

    @workflow.signal(name="lesson_ready_v1")
    def receive_lesson_ready(self, payload: Dict[str, Any]) -> None:
        if not isinstance(payload, dict):
            return
        if payload.get("schema_version") != 1:
            return
        case_id = payload.get("case_id")
        completion_ref = payload.get("completion_ref")
        work_ordinal = payload.get("work_ordinal")
        if not case_id or not completion_ref:
            return
        if self.case_id and case_id != self.case_id:
            return
        if not isinstance(work_ordinal, int) or isinstance(work_ordinal, bool) or work_ordinal < 1:
            return

        if work_ordinal in self.lesson_receipts:
            if self.lesson_receipts[work_ordinal].get("completion_ref") == completion_ref:
                return
            return

        self.lesson_receipts[work_ordinal] = payload
        self.lesson_ready = payload

    @workflow.run
    async def run(self, input_data: Dict[str, Any]) -> None:
        case_id = input_data["case_id"]
        self.case_id = case_id

        run_id = workflow.info().run_id
        workflow_id = workflow.info().workflow_id
        retry_policy_data = input_data.get("retry_policy", {})
        technical_retries = retry_policy_data.get("technical_retries", 3)
        retry_delay_seconds = retry_policy_data.get("retry_delay_seconds", 5)

        activity_retry_policy = RetryPolicy(
            initial_interval=timedelta(seconds=retry_delay_seconds),
            backoff_coefficient=2.0,
            maximum_interval=timedelta(seconds=3600),
            maximum_attempts=technical_retries + 1,
            non_retryable_error_types=["ContractInvalidError", "EventConflictError"],
        )

        is_direct = (input_data.get("pipeline_version") == "notebooklm_combined_v1")

        if is_direct:
            normalize_direct_errors = workflow.patched("direct-safe-milestone-error-v1")
            started_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "workflow_started",
                "expected_state_version": 1,
                "event_type": "workflow_started",
                "stage_code": "sourcing",
                "status_code": "queued",
                "error_code": None,
            }
            started_res = await workflow.execute_activity(
                publish_workflow_started,
                started_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            current_state_version = started_res.get("data", {}).get("state_version", 2)

            await workflow.wait_condition(lambda: self.activation is not None and self.activation.get("case_id") == case_id)
            activation_id = self.activation["activation_id"]

            start_src_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "source:sourcing:started",
                "expected_state_version": current_state_version,
                "event_type": "stage_started",
                "stage_code": "sourcing",
                "status_code": "running",
                "error_code": None,
            }
            res = await workflow.execute_activity(
                publish_case_event,
                start_src_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

            while True:
                ingest_res = await workflow.execute_activity(
                    ingest_case_source,
                    {
                        "case_id": case_id,
                        "activation_id": activation_id,
                        "protocol": "notebooklm_combined_v1",
                    },
                    start_to_close_timeout=timedelta(seconds=330),
                    retry_policy=activity_retry_policy,
                )
                if ingest_res.get("status") != "processing":
                    break
                await workflow.sleep(timedelta(seconds=30))

            require_confirmed_source = workflow.patched("source-binding-confirmed-v1")
            if ingest_res.get("status") == "blocked" or (
                require_confirmed_source and ingest_res.get("status") != "bound"
            ):
                blocked_event = {
                    "case_id": case_id,
                    "workflow_id": workflow_id,
                    "run_id": run_id,
                    "schema_version": 1,
                    "event_key": "source:blocked:sourcing",
                    "expected_state_version": current_state_version,
                    "event_type": "source_blocked",
                    "stage_code": "sourcing",
                    "status_code": "blocked",
                    "error_code": (_direct_milestone_error(ingest_res.get("error_code"))
                                   if normalize_direct_errors else ingest_res.get("error_code", "provider_unavailable")),
                }
                await workflow.execute_activity(
                    publish_case_event,
                    blocked_event,
                    start_to_close_timeout=timedelta(seconds=15),
                    retry_policy=activity_retry_policy,
                )
                return

            comp_src_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "source:sourcing:completed",
                "expected_state_version": current_state_version,
                "event_type": "stage_completed",
                "stage_code": "sourcing",
                "status_code": "queued",
                "error_code": None,
            }
            res = await workflow.execute_activity(
                publish_case_event,
                comp_src_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

            await workflow.wait_condition(
                lambda: self.map_activation is not None and self.map_activation.get("case_id") == case_id
            )
            map_activation_id = self.map_activation["activation_id"]
            binding_ref = self.map_activation.get("binding_ref")

            start_map_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "map:started",
                "expected_state_version": current_state_version,
                "event_type": "stage_started",
                "stage_code": "mapping",
                "status_code": "running",
                "error_code": None,
            }
            res = await workflow.execute_activity(
                publish_case_event,
                start_map_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

            while True:
                map_res = await workflow.execute_activity(
                    map_case,
                    {
                        "case_id": case_id,
                        "activation_id": map_activation_id,
                        "binding_ref": binding_ref,
                        "protocol": "notebooklm_combined_v1",
                    },
                    start_to_close_timeout=timedelta(seconds=300),
                    retry_policy=activity_retry_policy,
                )
                if map_res.get("status") != "uncertain":
                    break
                pending_query = map_res.get("query_ref")
                if not pending_query:
                    return
                reconcile = await workflow.execute_activity(
                    reconcile_notebook_query,
                    {"case_id": case_id, "binding_ref": binding_ref,
                     "kind": map_res["kind"], "work_ordinal": 0},
                    start_to_close_timeout=timedelta(seconds=60),
                    retry_policy=activity_retry_policy,
                )
                if reconcile.get("status") == "blocked":
                    map_res = reconcile
                    break
                if reconcile.get("status") != "recorded":
                    await workflow.sleep(timedelta(seconds=30))

            if map_res.get("status") == "blocked":
                blocked_event = {
                    "case_id": case_id,
                    "workflow_id": workflow_id,
                    "run_id": run_id,
                    "schema_version": 1,
                    "event_key": "map:blocked",
                    "expected_state_version": current_state_version,
                    "event_type": "map_blocked",
                    "stage_code": "mapping",
                    "status_code": "blocked",
                    "error_code": (_direct_milestone_error(map_res.get("error_code"))
                                   if normalize_direct_errors else map_res.get("error_code", "validation")),
                }
                await workflow.execute_activity(
                    publish_case_event,
                    blocked_event,
                    start_to_close_timeout=timedelta(seconds=15),
                    retry_policy=activity_retry_policy,
                )
                return

            comp_map_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "map:completed",
                "expected_state_version": current_state_version,
                "event_type": "stage_completed",
                "stage_code": "mapping",
                "status_code": "queued",
                "error_code": None,
            }
            res = await workflow.execute_activity(
                publish_case_event,
                comp_map_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

            work_count = map_res.get("work_count", 1)
            per_work_qualification_keys = workflow.patched("direct-qual-event-keys-v1")

            for current_work_ordinal in range(1, work_count + 1):
                while True:
                    lesson_res = await workflow.execute_activity(
                        process_notebook_lesson,
                        {
                            "case_id": case_id,
                            "work_ordinal": current_work_ordinal,
                            "binding_ref": binding_ref,
                        },
                        start_to_close_timeout=timedelta(seconds=900),
                        retry_policy=activity_retry_policy,
                    )
                    if lesson_res.get("status") != "uncertain":
                        break
                    pending_query = lesson_res.get("query_ref")
                    if not pending_query:
                        return
                    reconcile = await workflow.execute_activity(
                        reconcile_notebook_query,
                        {"case_id": case_id, "binding_ref": binding_ref,
                         "kind": lesson_res["kind"], "work_ordinal": current_work_ordinal},
                        start_to_close_timeout=timedelta(seconds=60),
                        retry_policy=activity_retry_policy,
                    )
                    if reconcile.get("status") == "blocked":
                        lesson_res = reconcile
                        break
                    if reconcile.get("status") != "recorded":
                        await workflow.sleep(timedelta(seconds=30))

                if lesson_res.get("status") == "blocked":
                    blocked_event = {
                        "case_id": case_id,
                        "workflow_id": workflow_id,
                        "run_id": run_id,
                        "schema_version": 1,
                        "event_key": f"map:work:{current_work_ordinal}:blocked",
                        "expected_state_version": current_state_version,
                        "event_type": "map_blocked",
                        "stage_code": "mapping",
                        "status_code": "blocked",
                        "error_code": (_direct_milestone_error(lesson_res.get("error_code"))
                                       if normalize_direct_errors else lesson_res.get("error_code", "validation")),
                    }
                    await workflow.execute_activity(
                        publish_case_event,
                        blocked_event,
                        start_to_close_timeout=timedelta(seconds=15),
                        retry_policy=activity_retry_policy,
                    )
                    return

                await workflow.wait_condition(
                    lambda: current_work_ordinal in self.lesson_receipts
                    or any(item.get("work_ordinal") == current_work_ordinal for item in self.reconciled_queries.values())
                )
                receipt = self.lesson_receipts.get(current_work_ordinal) or next(
                    item for item in self.reconciled_queries.values() if item.get("work_ordinal") == current_work_ordinal
                )
                work_ordinal = current_work_ordinal

                start_prompt_event = {
                    "case_id": case_id,
                    "workflow_id": workflow_id,
                    "run_id": run_id,
                    "schema_version": 1,
                    "event_key": f"prompt:work:{work_ordinal}:started",
                    "expected_state_version": current_state_version,
                    "event_type": "stage_started",
                    "stage_code": "prompting",
                    "status_code": "running",
                    "error_code": None,
                }
                res = await workflow.execute_activity(
                    publish_case_event,
                    start_prompt_event,
                    start_to_close_timeout=timedelta(seconds=15),
                    retry_policy=activity_retry_policy,
                )
                current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                comp_prompt_event = {
                    "case_id": case_id,
                    "workflow_id": workflow_id,
                    "run_id": run_id,
                    "schema_version": 1,
                    "event_key": f"prompt:work:{work_ordinal}:originals",
                    "expected_state_version": current_state_version,
                    "event_type": "stage_completed",
                    "stage_code": "prompting",
                    "status_code": "queued",
                    "error_code": None,
                }
                res = await workflow.execute_activity(
                    publish_case_event,
                    comp_prompt_event,
                    start_to_close_timeout=timedelta(seconds=15),
                    retry_policy=activity_retry_policy,
                )
                current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                start_qual_event = {
                    "case_id": case_id,
                    "workflow_id": workflow_id,
                    "run_id": run_id,
                    "schema_version": 1,
                    "event_key": (f"prompt:work:{work_ordinal}:qualification:started"
                                  if per_work_qualification_keys else "prompt:qualification:started"),
                    "expected_state_version": current_state_version,
                    "event_type": "stage_started",
                    "stage_code": "prompt_qualification",
                    "status_code": "running",
                    "error_code": None,
                }
                res = await workflow.execute_activity(
                    publish_case_event,
                    start_qual_event,
                    start_to_close_timeout=timedelta(seconds=15),
                    retry_policy=activity_retry_policy,
                )
                current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                prompt_version_refs = (
                    lesson_res.get("original_prompt_refs")
                    or receipt.get("third_output_refs")
                )
                if not prompt_version_refs:
                    return
                qual_res = await workflow.execute_activity(
                    qualify_prompts,
                    {
                        "case_id": case_id,
                        "work_ordinal": work_ordinal,
                        "prompt_version_refs": prompt_version_refs,
                    },
                    start_to_close_timeout=timedelta(seconds=600),
                    retry_policy=activity_retry_policy,
                )

                if qual_res.get("status") == "uncertain":
                    await workflow.wait_condition(lambda: False)

                qualified_count = qual_res.get("qualified_count", 0)
                blocked_count = qual_res.get("blocked_count", 0)
                passing_prompt_refs = qual_res.get("passing_prompt_refs", [])

                if qualified_count > 0:
                    comp_qual_event = {
                        "case_id": case_id,
                        "workflow_id": workflow_id,
                        "run_id": run_id,
                        "schema_version": 1,
                        "event_key": (f"prompt:work:{work_ordinal}:qualification:completed"
                                      if per_work_qualification_keys else "prompt:qualification:completed"),
                        "expected_state_version": current_state_version,
                        "event_type": "stage_completed",
                        "stage_code": "prompt_qualification",
                        "status_code": "queued",
                        "error_code": None,
                    }
                    res = await workflow.execute_activity(
                        publish_case_event,
                        comp_qual_event,
                        start_to_close_timeout=timedelta(seconds=15),
                        retry_policy=activity_retry_policy,
                    )
                    current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                    for prompt_version_ref in passing_prompt_refs:
                        await workflow.execute_activity(
                            resolve_qualified_prompt,
                            {
                                "case_id": case_id,
                                "prompt_version_ref": prompt_version_ref,
                            },
                            start_to_close_timeout=timedelta(seconds=30),
                            retry_policy=activity_retry_policy,
                        )

                    eligible_prompts = await workflow.execute_activity(
                        authoring_list_eligible_prompts,
                        {"case_id": case_id, "work_ordinal": work_ordinal},
                        start_to_close_timeout=timedelta(seconds=30),
                        retry_policy=activity_retry_policy,
                    )
                    if not eligible_prompts:
                        return

                    authored_any_candidate = False
                    all_blocked = True

                    for prompt_info in eligible_prompts:
                        p_ref = prompt_info["prompt_version_ref"]
                        claim_res = await workflow.execute_activity(
                            authoring_claim_request,
                            {
                                "case_id": case_id,
                                "prompt_version_ref": p_ref,
                            },
                            start_to_close_timeout=timedelta(seconds=30),
                            retry_policy=activity_retry_policy,
                        )
                        request_ref = claim_res["request_ref"]

                        start_scene_event = {
                            "case_id": case_id,
                            "workflow_id": workflow_id,
                            "run_id": run_id,
                            "schema_version": 1,
                            "event_key": f"scene:request:{request_ref}:started",
                            "expected_state_version": current_state_version,
                            "event_type": "stage_started",
                            "stage_code": "scene_generation",
                            "status_code": "running",
                            "error_code": None,
                        }
                        res = await workflow.execute_activity(
                            publish_case_event,
                            start_scene_event,
                            start_to_close_timeout=timedelta(seconds=15),
                            retry_policy=activity_retry_policy,
                        )
                        current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                        outcome = await workflow.execute_activity(
                            authoring_execute_attempts,
                            {
                                "case_id": case_id,
                                "request_ref": request_ref,
                                "work_ordinal": work_ordinal,
                            },
                            start_to_close_timeout=timedelta(seconds=300),
                            retry_policy=activity_retry_policy,
                        )

                        if outcome.get("status") == "candidate_ready":
                            authored_any_candidate = True

                            comp_scene_event = {
                                "case_id": case_id,
                                "workflow_id": workflow_id,
                                "run_id": run_id,
                                "schema_version": 1,
                                "event_key": f"scene:request:{request_ref}:candidate",
                                "expected_state_version": current_state_version,
                                "event_type": "stage_completed",
                                "stage_code": "scene_generation",
                                "status_code": "queued",
                                "error_code": None,
                            }
                            res = await workflow.execute_activity(
                                publish_case_event,
                                comp_scene_event,
                                start_to_close_timeout=timedelta(seconds=15),
                                retry_policy=activity_retry_policy,
                            )
                            current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                            current_candidate_ref = outcome.get("candidate_ref")
                            repair_ordinal = 0

                            while current_candidate_ref:
                                qual_outcome = await workflow.execute_activity(
                                    qualify_scene_activity,
                                    {
                                        "case_id": case_id,
                                        "candidate_ref": current_candidate_ref,
                                    },
                                    start_to_close_timeout=timedelta(seconds=300),
                                    retry_policy=activity_retry_policy,
                                )
                                qual_status = qual_outcome.get("status")

                                if qual_status == "qualified":
                                    pass_qual_event = {
                                        "case_id": case_id,
                                        "workflow_id": workflow_id,
                                        "run_id": run_id,
                                        "schema_version": 1,
                                        "event_key": f"scene:qualification:{current_candidate_ref}:passed",
                                        "expected_state_version": current_state_version,
                                        "event_type": "stage_completed",
                                        "stage_code": "scene_qualification",
                                        "status_code": "queued",
                                        "error_code": None,
                                    }
                                    res = await workflow.execute_activity(
                                        publish_case_event,
                                        pass_qual_event,
                                        start_to_close_timeout=timedelta(seconds=15),
                                        retry_policy=activity_retry_policy,
                                    )
                                    current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                                    review_res = await workflow.execute_activity(
                                        ensure_review_ready_activity,
                                        {
                                            "case_id": case_id,
                                            "candidate_ref": current_candidate_ref,
                                            "section_ref": prompt_info["section_ref"],
                                        },
                                        start_to_close_timeout=timedelta(seconds=30),
                                        retry_policy=activity_retry_policy,
                                    )
                                    if review_res.get("status") in ("created", "existing", "ready"):
                                        review_data = review_res.get("data", {})
                                        if not review_data.get("review_ref") and review_res.get("review_ref"):
                                            review_data = {
                                                "review_ref": review_res["review_ref"],
                                                "section_ref": prompt_info["section_ref"],
                                                "candidate_ref": current_candidate_ref,
                                                "candidate_version": outcome.get("candidate_version"),
                                            }
                                        self._register_review(review_data)
                                        all_blocked = False
                                        break
                                    else:
                                        all_blocked = False
                                        await workflow.wait_condition(lambda: False)
                                        return

                                elif qual_status in ("repair_pending", "check_failed"):
                                    repair_ordinal += 1
                                    repair_outcome = await workflow.execute_activity(
                                        repair_scene_activity,
                                        {
                                            "case_id": case_id,
                                            "failed_candidate_ref": current_candidate_ref,
                                            "expected_ordinal": repair_ordinal,
                                        },
                                        start_to_close_timeout=timedelta(seconds=300),
                                        retry_policy=activity_retry_policy,
                                    )
                                    rep_status = repair_outcome.get("status")
                                    if rep_status == "candidate_ready":
                                        current_candidate_ref = repair_outcome.get("candidate_ref")
                                        continue
                                    elif rep_status in ("blocked", "exhausted"):
                                        break
                                    elif rep_status in ("uncertain", "held"):
                                        all_blocked = False
                                        await workflow.wait_condition(lambda: False)
                                        return
                                    else:
                                        all_blocked = False
                                        await workflow.wait_condition(lambda: False)
                                        return

                                elif qual_status == "blocked":
                                    break

                                elif qual_status in ("technical_failure", "uncertain"):
                                    all_blocked = False
                                    await workflow.wait_condition(lambda: False)
                                    return
                                else:
                                    all_blocked = False
                                    await workflow.wait_condition(lambda: False)
                                    return

                        elif outcome.get("status") == "uncertain":
                            all_blocked = False
                            await workflow.wait_condition(lambda: False)
                            return

                        elif outcome.get("status") == "blocked":
                            pass

                    if eligible_prompts and all_blocked:
                        if not authored_any_candidate:
                            fail_event = {
                                "case_id": case_id,
                                "workflow_id": workflow_id,
                                "run_id": run_id,
                                "schema_version": 1,
                                "event_key": "scene:authoring:failed",
                                "expected_state_version": current_state_version,
                                "event_type": "workflow_failed",
                                "stage_code": "scene_generation",
                                "status_code": "failed",
                                "error_code": "provider_unavailable",
                            }
                        else:
                            fail_event = {
                                "case_id": case_id,
                                "workflow_id": workflow_id,
                                "run_id": run_id,
                                "schema_version": 1,
                                "event_key": "scene:qualification:all_blocked",
                                "expected_state_version": current_state_version,
                                "event_type": "workflow_failed",
                                "stage_code": "scene_qualification",
                                "status_code": "failed",
                                "error_code": "validation",
                            }
                        await workflow.execute_activity(
                            publish_case_event,
                            fail_event,
                            start_to_close_timeout=timedelta(seconds=15),
                            retry_policy=activity_retry_policy,
                        )
                        return

                elif blocked_count > 0 and qualified_count == 0:
                    all_blocked_event = {
                        "case_id": case_id,
                        "workflow_id": workflow_id,
                        "run_id": run_id,
                        "schema_version": 1,
                        "event_key": (f"prompt:work:{work_ordinal}:qualification:all_blocked"
                                      if per_work_qualification_keys else "prompt:qualification:all_blocked"),
                        "expected_state_version": current_state_version,
                        "event_type": "workflow_failed",
                        "stage_code": "prompt_qualification",
                        "status_code": "failed",
                        "error_code": "policy_denied",
                    }
                    await workflow.execute_activity(
                        publish_case_event,
                        all_blocked_event,
                        start_to_close_timeout=timedelta(seconds=15),
                        retry_policy=activity_retry_policy,
                    )
                    return

                if self.review_expectations:
                    await workflow.wait_condition(
                        lambda: all(
                            any(item.get("review_ref") == latest_ref and item.get("outcome") == "approve"
                                for item in self.consumed_decisions.values())
                            for latest_ref in self.latest_review_by_section.values()
                        )
                    )
            return

        activity_input = {
            "case_id": case_id,
            "workflow_id": workflow_id,
            "run_id": run_id,
            "schema_version": 1,
            "event_key": "workflow_started",
            "expected_state_version": 1,
            "event_type": "workflow_started",
            "stage_code": "extraction",
            "status_code": "queued",
            "error_code": None,
        }

        started_res = await workflow.execute_activity(
            publish_workflow_started,
            activity_input,
            start_to_close_timeout=timedelta(seconds=15),
            retry_policy=activity_retry_policy,
        )

        current_state_version = started_res.get("data", {}).get("state_version", 2)

        # 007 parking wait at extraction handoff
        # Holds no activity worker or timer; 008 replaces this line with waiting for valid source_activation_v1 signal
        await workflow.wait_condition(lambda: self.activation is not None and self.activation.get("case_id") == case_id)

        activation_id = self.activation["activation_id"]

        # Milestone 1: source:extraction:started
        start_ext_event = {
            "case_id": case_id,
            "workflow_id": workflow_id,
            "run_id": run_id,
            "schema_version": 1,
            "event_key": "source:extraction:started",
            "expected_state_version": current_state_version,
            "event_type": "stage_started",
            "stage_code": "extraction",
            "status_code": "running",
            "error_code": None,
        }
        res = await workflow.execute_activity(
            publish_case_event,
            start_ext_event,
            start_to_close_timeout=timedelta(seconds=15),
            retry_policy=activity_retry_policy,
        )
        current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

        # Activity: extract_case_source
        extract_res = await workflow.execute_activity(
            extract_case_source,
            {"case_id": case_id, "activation_id": activation_id},
            start_to_close_timeout=timedelta(seconds=300),
            retry_policy=activity_retry_policy,
        )

        if extract_res.get("status") == "blocked":
            blocked_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "source:blocked:extraction",
                "expected_state_version": current_state_version,
                "event_type": "source_blocked",
                "stage_code": "extraction",
                "status_code": "blocked",
                "error_code": extract_res.get("error_code", "validation"),
            }
            await workflow.execute_activity(
                publish_case_event,
                blocked_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            return

        # Milestone 2: source:extraction:completed
        comp_ext_event = {
            "case_id": case_id,
            "workflow_id": workflow_id,
            "run_id": run_id,
            "schema_version": 1,
            "event_key": "source:extraction:completed",
            "expected_state_version": current_state_version,
            "event_type": "stage_completed",
            "stage_code": "extraction",
            "status_code": "queued",
            "error_code": None,
        }
        res = await workflow.execute_activity(
            publish_case_event,
            comp_ext_event,
            start_to_close_timeout=timedelta(seconds=15),
            retry_policy=activity_retry_policy,
        )
        current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

        # Milestone 3: source:sourcing:started
        start_src_event = {
            "case_id": case_id,
            "workflow_id": workflow_id,
            "run_id": run_id,
            "schema_version": 1,
            "event_key": "source:sourcing:started",
            "expected_state_version": current_state_version,
            "event_type": "stage_started",
            "stage_code": "sourcing",
            "status_code": "running",
            "error_code": None,
        }
        res = await workflow.execute_activity(
            publish_case_event,
            start_src_event,
            start_to_close_timeout=timedelta(seconds=15),
            retry_policy=activity_retry_policy,
        )
        current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

        extracted_source_ref = extract_res.get("extracted_source_ref")

        # Activity: ingest_case_source
        ingest_res = await workflow.execute_activity(
            ingest_case_source,
            {
                "case_id": case_id,
                "activation_id": activation_id,
                "extracted_source_ref": extracted_source_ref,
            },
            start_to_close_timeout=timedelta(seconds=300),
            retry_policy=activity_retry_policy,
        )

        # Version the changed branch so old histories can replay their prior decisions.
        require_confirmed_source = workflow.patched("source-binding-confirmed-v1")
        if ingest_res.get("status") == "blocked" or (
            require_confirmed_source and ingest_res.get("status") != "bound"
        ):
            blocked_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "source:blocked:sourcing",
                "expected_state_version": current_state_version,
                "event_type": "source_blocked",
                "stage_code": "sourcing",
                "status_code": "blocked",
                "error_code": ingest_res.get("error_code", "provider_unavailable"),
            }
            await workflow.execute_activity(
                publish_case_event,
                blocked_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            return

        # Milestone 4: source:sourcing:completed
        comp_src_event = {
            "case_id": case_id,
            "workflow_id": workflow_id,
            "run_id": run_id,
            "schema_version": 1,
            "event_key": "source:sourcing:completed",
            "expected_state_version": current_state_version,
            "event_type": "stage_completed",
            "stage_code": "sourcing",
            "status_code": "queued",
            "error_code": None,
        }
        res = await workflow.execute_activity(
            publish_case_event,
            comp_src_event,
            start_to_close_timeout=timedelta(seconds=15),
            retry_policy=activity_retry_policy,
        )
        current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

        # 009 Mapping handoff wait
        await workflow.wait_condition(
            lambda: self.map_activation is not None and self.map_activation.get("case_id") == case_id
        )

        map_activation_id = self.map_activation["activation_id"]
        binding_ref = self.map_activation.get("binding_ref")

        # Milestone 5: map:started
        start_map_event = {
            "case_id": case_id,
            "workflow_id": workflow_id,
            "run_id": run_id,
            "schema_version": 1,
            "event_key": "map:started",
            "expected_state_version": current_state_version,
            "event_type": "stage_started",
            "stage_code": "mapping",
            "status_code": "running",
            "error_code": None,
        }
        res = await workflow.execute_activity(
            publish_case_event,
            start_map_event,
            start_to_close_timeout=timedelta(seconds=15),
            retry_policy=activity_retry_policy,
        )
        current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

        # Activity: map_case
        map_res = await workflow.execute_activity(
            map_case,
            {
                "case_id": case_id,
                "activation_id": map_activation_id,
                "binding_ref": binding_ref,
            },
            start_to_close_timeout=timedelta(seconds=300),
            retry_policy=activity_retry_policy,
        )

        if map_res.get("status") == "uncertain":
            # The 009 ledger forbids replaying a stateful NotebookLM turn after
            # an ambiguous response. Keep this workflow open and the case in
            # mapping/running for explicit reconciliation.
            await workflow.wait_condition(lambda: False)

        if map_res.get("status") == "blocked":
            blocked_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "map:blocked",
                "expected_state_version": current_state_version,
                "event_type": "map_blocked",
                "stage_code": "mapping",
                "status_code": "blocked",
                "error_code": map_res.get("error_code", "validation"),
            }
            await workflow.execute_activity(
                publish_case_event,
                blocked_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            return

        # Activity: prepare_lesson (first work ordinal)
        lesson_res = await workflow.execute_activity(
            prepare_lesson,
            {
                "case_id": case_id,
                "activation_id": map_activation_id,
                "binding_ref": binding_ref,
                "work_ordinal": 1,
            },
            start_to_close_timeout=timedelta(seconds=300),
            retry_policy=activity_retry_policy,
        )

        if lesson_res.get("status") == "uncertain":
            # Preserve an unresolved First turn; never report ready or resend it.
            await workflow.wait_condition(lambda: False)

        if lesson_res.get("status") == "blocked":
            blocked_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "map:blocked",
                "expected_state_version": current_state_version,
                "event_type": "map_blocked",
                "stage_code": "mapping",
                "status_code": "blocked",
                "error_code": lesson_res.get("error_code", "validation"),
            }
            await workflow.execute_activity(
                publish_case_event,
                blocked_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            return

        # Milestone 6: map:completed
        comp_map_event = {
            "case_id": case_id,
            "workflow_id": workflow_id,
            "run_id": run_id,
            "schema_version": 1,
            "event_key": "map:completed",
            "expected_state_version": current_state_version,
            "event_type": "stage_completed",
            "stage_code": "mapping",
            "status_code": "queued",
            "error_code": None,
        }
        res = await workflow.execute_activity(
            publish_case_event,
            comp_map_event,
            start_to_close_timeout=timedelta(seconds=15),
            retry_policy=activity_retry_policy,
        )
        current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

        work_count = map_res.get("work_count", 1)

        for current_work_ordinal in range(1, work_count + 1):
            if current_work_ordinal > 1:
                # Prepare lesson for continuation (Continue query turn)
                lesson_res = await workflow.execute_activity(
                    prepare_lesson,
                    {
                        "case_id": case_id,
                        "activation_id": map_activation_id,
                        "binding_ref": binding_ref,
                        "work_ordinal": current_work_ordinal,
                    },
                    start_to_close_timeout=timedelta(seconds=300),
                    retry_policy=activity_retry_policy,
                )

                if lesson_res.get("status") == "uncertain":
                    await workflow.wait_condition(lambda: False)

                if lesson_res.get("status") == "blocked":
                    blocked_event = {
                        "case_id": case_id,
                        "workflow_id": workflow_id,
                        "run_id": run_id,
                        "schema_version": 1,
                        "event_key": f"map:work:{current_work_ordinal}:blocked",
                        "expected_state_version": current_state_version,
                        "event_type": "map_blocked",
                        "stage_code": "mapping",
                        "status_code": "blocked",
                        "error_code": lesson_res.get("error_code", "validation"),
                    }
                    await workflow.execute_activity(
                        publish_case_event,
                        blocked_event,
                        start_to_close_timeout=timedelta(seconds=15),
                        retry_policy=activity_retry_policy,
                    )
                    return

            # Generate First-derived canonical prompts and persist the Third outputs
            # before waiting for Laravel's durable lesson-completion receipt. The
            # receipt notification may arrive before this workflow reaches the wait;
            # Temporal retains the signal in workflow state.
            prompt_res = await workflow.execute_activity(
                generate_prompts,
                {
                    "case_id": case_id,
                    "work_ordinal": current_work_ordinal,
                    "binding_ref": binding_ref,
                },
                start_to_close_timeout=timedelta(seconds=900),
                retry_policy=activity_retry_policy,
            )
            if prompt_res.get("status") not in ("completed", "ready"):
                # A non-completed result is not evidence of persisted Third output.
                # Keep the case open for explicit reconciliation rather than
                # converting uncertainty into a completion event.
                await workflow.wait_condition(lambda: False)

            # Park at the 009 completion receipt for current_work_ordinal
            await workflow.wait_condition(lambda: current_work_ordinal in self.lesson_receipts)
            receipt = self.lesson_receipts[current_work_ordinal]
            work_ordinal = current_work_ordinal
            completion_ref = receipt.get("completion_ref")

            # Milestone 7: prompt:work:{ordinal}:started
            start_prompt_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": f"prompt:work:{work_ordinal}:started",
                "expected_state_version": current_state_version,
                "event_type": "stage_started",
                "stage_code": "prompting",
                "status_code": "running",
                "error_code": None,
            }
            res = await workflow.execute_activity(
                publish_case_event,
                start_prompt_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

            # Milestone 8: prompt:work:{ordinal}:originals
            comp_prompt_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": f"prompt:work:{work_ordinal}:originals",
                "expected_state_version": current_state_version,
                "event_type": "stage_completed",
                "stage_code": "prompting",
                "status_code": "queued",
                "error_code": None,
            }
            res = await workflow.execute_activity(
                publish_case_event,
                comp_prompt_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

            # Milestone 9: prompt:qualification:started
            start_qual_event = {
                "case_id": case_id,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "schema_version": 1,
                "event_key": "prompt:qualification:started",
                "expected_state_version": current_state_version,
                "event_type": "stage_started",
                "stage_code": "prompt_qualification",
                "status_code": "running",
                "error_code": None,
            }
            res = await workflow.execute_activity(
                publish_case_event,
                start_qual_event,
                start_to_close_timeout=timedelta(seconds=15),
                retry_policy=activity_retry_policy,
            )
            current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

            # Activity: qualify_prompts
            prompt_version_refs = (
                prompt_res.get("original_prompt_refs")
                or receipt.get("third_output_refs")
                or receipt.get("passing_prompt_refs")
            )
            qual_res = await workflow.execute_activity(
                qualify_prompts,
                {
                    "case_id": case_id,
                    "work_ordinal": work_ordinal,
                    "prompt_version_refs": prompt_version_refs,
                    "passing_prompt_refs": prompt_version_refs,
                },
                start_to_close_timeout=timedelta(seconds=600),
                retry_policy=activity_retry_policy,
            )

            if qual_res.get("status") == "uncertain":
                # A transport/reconciliation state is neither a pass nor an
                # educational block. Keep Temporal durable and await reviewed
                # recovery rather than advancing to authoring or all-blocked.
                await workflow.wait_condition(lambda: False)

            qualified_count = qual_res.get("qualified_count", 0)
            blocked_count = qual_res.get("blocked_count", 0)
            passing_prompt_refs = qual_res.get("passing_prompt_refs", [])

            if qualified_count > 0:
                # Milestone 10: prompt:qualification:completed
                comp_qual_event = {
                    "case_id": case_id,
                    "workflow_id": workflow_id,
                    "run_id": run_id,
                    "schema_version": 1,
                    "event_key": "prompt:qualification:completed",
                    "expected_state_version": current_state_version,
                    "event_type": "stage_completed",
                    "stage_code": "prompt_qualification",
                    "status_code": "queued",
                    "error_code": None,
                }
                res = await workflow.execute_activity(
                    publish_case_event,
                    comp_qual_event,
                    start_to_close_timeout=timedelta(seconds=15),
                    retry_policy=activity_retry_policy,
                )
                current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                # 011 Handoff boundary: verify each passing prompt via QualifiedPromptResolver
                for prompt_version_ref in passing_prompt_refs:
                    await workflow.execute_activity(
                        resolve_qualified_prompt,
                        {
                            "case_id": case_id,
                            "prompt_version_ref": prompt_version_ref,
                        },
                        start_to_close_timeout=timedelta(seconds=30),
                        retry_policy=activity_retry_policy,
                    )

                # 011 Scene Authoring: list current passing sections in 009 ordinal order and process
                eligible_prompts = await workflow.execute_activity(
                    authoring_list_eligible_prompts,
                    {"case_id": case_id},
                    start_to_close_timeout=timedelta(seconds=30),
                    retry_policy=activity_retry_policy,
                )

                authored_any_candidate = False
                all_blocked = True

                for prompt_info in eligible_prompts:
                    p_ref = prompt_info["prompt_version_ref"]
                    claim_res = await workflow.execute_activity(
                        authoring_claim_request,
                        {
                            "case_id": case_id,
                            "prompt_version_ref": p_ref,
                        },
                        start_to_close_timeout=timedelta(seconds=30),
                        retry_policy=activity_retry_policy,
                    )
                    request_ref = claim_res["request_ref"]

                    # Milestone: scene:request:{request_ref}:started
                    start_scene_event = {
                        "case_id": case_id,
                        "workflow_id": workflow_id,
                        "run_id": run_id,
                        "schema_version": 1,
                        "event_key": f"scene:request:{request_ref}:started",
                        "expected_state_version": current_state_version,
                        "event_type": "stage_started",
                        "stage_code": "scene_generation",
                        "status_code": "running",
                        "error_code": None,
                    }
                    res = await workflow.execute_activity(
                        publish_case_event,
                        start_scene_event,
                        start_to_close_timeout=timedelta(seconds=15),
                        retry_policy=activity_retry_policy,
                    )
                    current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                    # Execute authoring attempts
                    outcome = await workflow.execute_activity(
                        authoring_execute_attempts,
                        {
                            "case_id": case_id,
                            "request_ref": request_ref,
                        },
                        start_to_close_timeout=timedelta(seconds=300),
                        retry_policy=activity_retry_policy,
                    )

                    if outcome.get("status") == "candidate_ready":
                        authored_any_candidate = True

                        # Milestone: scene:request:{request_ref}:candidate
                        comp_scene_event = {
                            "case_id": case_id,
                            "workflow_id": workflow_id,
                            "run_id": run_id,
                            "schema_version": 1,
                            "event_key": f"scene:request:{request_ref}:candidate",
                            "expected_state_version": current_state_version,
                            "event_type": "stage_completed",
                            "stage_code": "scene_generation",
                            "status_code": "queued",
                            "error_code": None,
                        }
                        res = await workflow.execute_activity(
                            publish_case_event,
                            comp_scene_event,
                            start_to_close_timeout=timedelta(seconds=15),
                            retry_policy=activity_retry_policy,
                        )
                        current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                        # Candidate Qualification & Permitted Repair Loop
                        current_candidate_ref = outcome.get("candidate_ref")
                        repair_ordinal = 0

                        while current_candidate_ref:
                            qual_outcome = await workflow.execute_activity(
                                qualify_scene_activity,
                                {
                                    "case_id": case_id,
                                    "candidate_ref": current_candidate_ref,
                                },
                                start_to_close_timeout=timedelta(seconds=300),
                                retry_policy=activity_retry_policy,
                            )
                            qual_status = qual_outcome.get("status")

                            if qual_status == "qualified":
                                # Milestone: scene:qualification:{current_candidate_ref}:passed
                                pass_qual_event = {
                                    "case_id": case_id,
                                    "workflow_id": workflow_id,
                                    "run_id": run_id,
                                    "schema_version": 1,
                                    "event_key": f"scene:qualification:{current_candidate_ref}:passed",
                                    "expected_state_version": current_state_version,
                                    "event_type": "stage_completed",
                                    "stage_code": "scene_qualification",
                                    "status_code": "queued",
                                    "error_code": None,
                                }
                                res = await workflow.execute_activity(
                                    publish_case_event,
                                    pass_qual_event,
                                    start_to_close_timeout=timedelta(seconds=15),
                                    retry_policy=activity_retry_policy,
                                )
                                current_state_version = res.get("data", {}).get("state_version", current_state_version + 1)

                                # Ensure Review Ready
                                review_res = await workflow.execute_activity(
                                    ensure_review_ready_activity,
                                    {
                                        "case_id": case_id,
                                        "candidate_ref": current_candidate_ref,
                                    },
                                    start_to_close_timeout=timedelta(seconds=30),
                                    retry_policy=activity_retry_policy,
                                )
                                if review_res.get("status") in ("created", "existing", "ready"):
                                    review_data = review_res.get("data", {})
                                    self._register_review(review_data)
                                    all_blocked = False
                                    break
                                else:
                                    all_blocked = False
                                    await workflow.wait_condition(lambda: False)
                                    return

                            elif qual_status in ("repair_pending", "check_failed"):
                                repair_ordinal += 1
                                repair_outcome = await workflow.execute_activity(
                                    repair_scene_activity,
                                    {
                                        "case_id": case_id,
                                        "failed_candidate_ref": current_candidate_ref,
                                        "expected_ordinal": repair_ordinal,
                                    },
                                    start_to_close_timeout=timedelta(seconds=300),
                                    retry_policy=activity_retry_policy,
                                )
                                rep_status = repair_outcome.get("status")
                                if rep_status == "candidate_ready":
                                    # Successor candidate produced; requalify it
                                    current_candidate_ref = repair_outcome.get("candidate_ref")
                                    continue
                                elif rep_status in ("blocked", "exhausted"):
                                    break
                                elif rep_status in ("uncertain", "held"):
                                    all_blocked = False
                                    await workflow.wait_condition(lambda: False)
                                    return
                                else:
                                    all_blocked = False
                                    await workflow.wait_condition(lambda: False)
                                    return

                            elif qual_status == "blocked":
                                break

                            elif qual_status in ("technical_failure", "uncertain"):
                                all_blocked = False
                                await workflow.wait_condition(lambda: False)
                                return
                            else:
                                all_blocked = False
                                await workflow.wait_condition(lambda: False)
                                return

                    elif outcome.get("status") == "uncertain":
                        all_blocked = False
                        await workflow.wait_condition(lambda: False)
                        return

                    elif outcome.get("status") == "blocked":
                        pass

                if eligible_prompts and all_blocked:
                    if not authored_any_candidate:
                        fail_event = {
                            "case_id": case_id,
                            "workflow_id": workflow_id,
                            "run_id": run_id,
                            "schema_version": 1,
                            "event_key": "scene:authoring:failed",
                            "expected_state_version": current_state_version,
                            "event_type": "workflow_failed",
                            "stage_code": "scene_generation",
                            "status_code": "failed",
                            "error_code": "provider_unavailable",
                        }
                    else:
                        fail_event = {
                            "case_id": case_id,
                            "workflow_id": workflow_id,
                            "run_id": run_id,
                            "schema_version": 1,
                            "event_key": "scene:qualification:all_blocked",
                            "expected_state_version": current_state_version,
                            "event_type": "workflow_failed",
                            "stage_code": "scene_qualification",
                            "status_code": "failed",
                            "error_code": "validation",
                        }
                    await workflow.execute_activity(
                        publish_case_event,
                        fail_event,
                        start_to_close_timeout=timedelta(seconds=15),
                        retry_policy=activity_retry_policy,
                    )
                    return

            elif blocked_count > 0 and qualified_count == 0:
                # All blocked
                all_blocked_event = {
                    "case_id": case_id,
                    "workflow_id": workflow_id,
                    "run_id": run_id,
                    "schema_version": 1,
                    "event_key": "prompt:qualification:all_blocked",
                    "expected_state_version": current_state_version,
                    "event_type": "workflow_failed",
                    "stage_code": "prompt_qualification",
                    "status_code": "failed",
                    "error_code": "policy_denied",
                }
                await workflow.execute_activity(
                    publish_case_event,
                    all_blocked_event,
                    start_to_close_timeout=timedelta(seconds=15),
                    retry_policy=activity_retry_policy,
                )
                return

        # Keep the execution open until each qualified current review has
        # consumed one exact persisted decision. A successful signal send or
        # completed workflow is never used as a substitute for consumption.
        if self.review_expectations:
            await workflow.wait_condition(
                lambda: all(
                    any(item.get("review_ref") == review_ref for item in self.consumed_decisions.values())
                    for review_ref in self.review_expectations
                )
            )
