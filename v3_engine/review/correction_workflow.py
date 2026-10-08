"""Correction workflow for Spec 013 (Later Teacher Corrections)."""
from datetime import timedelta
from typing import Any, Dict, Optional
from temporalio import workflow
from temporalio.common import RetryPolicy

with workflow.unsafe.imports_passed_through():
    from v3_engine.review.activity import (
        claim_teacher_scene_repair_activity,
        claim_teacher_prompt_correction_activity,
        get_teacher_prompt_correction_context_activity,
        record_teacher_prompt_correction_result_activity,
        record_teacher_prompt_correction_uncertain_activity,
        revise_teacher_prompt_correction_activity,
        execute_teacher_scene_repair_activity,
    )
    from v3_engine.admin.control import AdminControlTracker
    from v3_engine.prompts.activities import qualify_prompts
    from v3_engine.authoring.activities import authoring_list_eligible_prompts, authoring_claim_request, authoring_execute_attempts
    from v3_engine.qualification.activity import qualify_scene_activity, repair_scene_activity, ensure_review_ready_activity


@workflow.defn
class CorrectionWorkflow:
    """
    Dedicated workflow for executing teacher correction routes.
    Runs under the fixed ID: v3-review-correction-{correction_uuid}.
    Never restarts a completed case workflow.
    """

    def __init__(self) -> None:
        self.admin_control = AdminControlTracker()
        self.review_expectations: Dict[str, Dict[str, Any]] = {}
        self.consumed_decisions: Dict[str, Dict[str, Any]] = {}
        self.pending_review_signals: Dict[str, Dict[str, Any]] = {}

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
        expected = self.review_expectations.get(review_ref)
        if expected is None:
            self.pending_review_signals.setdefault(decision_ref, payload)
            return
        self._consume_review_decision(expected, payload)

    @workflow.query(name="query_decision_consumed")
    def query_decision_consumed(self, decision_ref: str) -> bool:
        return decision_ref in self.consumed_decisions

    def _consume_review_decision(self, expected: Dict[str, Any], payload: Dict[str, Any]) -> None:
        fields = ("case_id", "section_ref", "review_ref", "candidate_ref", "candidate_version")
        if any(payload.get(key) != expected.get(key) for key in fields):
            return
        decision_ref = payload.get("decision_ref")
        if not isinstance(decision_ref, str) or payload.get("outcome") not in ("approve", "request_changes"):
            return
        if decision_ref in self.consumed_decisions:
            return
        self.consumed_decisions[decision_ref] = {
            "review_ref": expected["review_ref"],
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
        for decision_ref, signal in list(self.pending_review_signals.items()):
            if signal.get("review_ref") == review_ref:
                self._consume_review_decision(expected, signal)
                self.pending_review_signals.pop(decision_ref, None)

    @workflow.run
    async def run(self, input_data: Dict[str, Any]) -> Dict[str, Any]:
        case_id = input_data["case_id"]
        section_ref = input_data["section_ref"]
        correction_ref = input_data["correction_ref"]
        route_ref = input_data["route_ref"]
        feedback_kind = input_data.get("feedback_kind", "visual_interaction")
        repair_ordinal = 0

        retry_policy = RetryPolicy(
            initial_interval=timedelta(seconds=2),
            backoff_coefficient=2.0,
            maximum_interval=timedelta(seconds=60),
            maximum_attempts=3,
            non_retryable_error_types=["ApplicationError"],
        )

        if feedback_kind == "visual_interaction":
            repair_res = await workflow.execute_activity(
                "claim_teacher_scene_repair",
                {
                    "case_id": case_id,
                    "route_ref": route_ref,
                },
                start_to_close_timeout=timedelta(seconds=30),
                retry_policy=retry_policy,
            )
            if repair_res.get("status_code") == 409 or not repair_res.get("authoring_request_ref"):
                return {"status": "blocked", "correction_ref": correction_ref, "safe_error_code": "repair_unavailable"}
            result = await workflow.execute_activity(
                execute_teacher_scene_repair_activity,
                {"case_id": case_id, "repair_ref": repair_res["repair_ref"],
                 "authoring_request_ref": repair_res["authoring_request_ref"], "ordinal": repair_res["ordinal"]},
                start_to_close_timeout=timedelta(seconds=300), retry_policy=retry_policy,
            )
            if result.get("status") != "candidate_ready":
                return {"status": result.get("status", "blocked"), "correction_ref": correction_ref,
                        "safe_error_code": result.get("safe_error_code", "repair_failed")}
            candidate_ref = result["candidate_ref"]
            repair_ordinal = int(repair_res.get("ordinal", 0))
        else:
            claim_res = await workflow.execute_activity(
                "claim_teacher_prompt_correction",
                {
                    "case_id": case_id,
                    "route_ref": route_ref,
                },
                start_to_close_timeout=timedelta(seconds=30),
                retry_policy=retry_policy,
            )
            effect_ref = claim_res.get("effect_ref")
            if not effect_ref or claim_res.get("disposition") != "send_now":
                return {"status": "uncertain", "correction_ref": correction_ref, "safe_error_code": "prompt_correction_uncertain"}
            prompt_res = await workflow.execute_activity(
                revise_teacher_prompt_correction_activity,
                {"case_id": case_id, "effect_ref": effect_ref},
                start_to_close_timeout=timedelta(seconds=300), retry_policy=retry_policy,
            )
            if prompt_res.get("status") != "recorded":
                return {"status": prompt_res.get("status", "uncertain"), "correction_ref": correction_ref,
                        "safe_error_code": prompt_res.get("error_code", "prompt_revision_uncertain")}
            prompt_ref = prompt_res.get("prompt_version_ref")
            qual_res = await workflow.execute_activity(
                qualify_prompts,
                {"case_id": case_id, "work_ordinal": int(prompt_res.get("work_ordinal", 1)),
                 "prompt_version_refs": [prompt_ref], "passing_prompt_refs": [prompt_ref]},
                start_to_close_timeout=timedelta(seconds=600), retry_policy=retry_policy,
            )
            if qual_res.get("status") != "completed" or prompt_ref not in qual_res.get("passing_prompt_refs", []):
                return {"status": qual_res.get("status", "blocked"), "correction_ref": correction_ref,
                        "safe_error_code": qual_res.get("error_code", "prompt_qualification_failed")}
            eligible = await workflow.execute_activity(
                authoring_list_eligible_prompts, {"case_id": case_id},
                start_to_close_timeout=timedelta(seconds=30), retry_policy=retry_policy,
            )
            info = next((item for item in eligible if item.get("prompt_version_ref") == prompt_ref), None)
            if not info:
                return {"status": "blocked", "correction_ref": correction_ref, "safe_error_code": "prompt_not_eligible"}
            claim = await workflow.execute_activity(
                authoring_claim_request, {"case_id": case_id, "prompt_version_ref": prompt_ref},
                start_to_close_timeout=timedelta(seconds=30), retry_policy=retry_policy,
            )
            authored = await workflow.execute_activity(
                authoring_execute_attempts, {"case_id": case_id, "request_ref": claim["request_ref"]},
                start_to_close_timeout=timedelta(seconds=300), retry_policy=retry_policy,
            )
            if authored.get("status") != "candidate_ready":
                return {"status": authored.get("status", "blocked"), "correction_ref": correction_ref,
                        "safe_error_code": authored.get("safe_error_code", "authoring_failed")}
            candidate_ref = authored["candidate_ref"]

        # Both correction routes converge on the ordinary 012 checks/JEV gate
        # and create a fresh teacher review only after a persisted pass.
        while True:
            qual_scene = await workflow.execute_activity(
                qualify_scene_activity, {"case_id": case_id, "candidate_ref": candidate_ref},
                start_to_close_timeout=timedelta(seconds=300), retry_policy=retry_policy,
            )
            scene_status = qual_scene.get("status")
            if scene_status == "qualified":
                break
            if scene_status in ("check_failed", "repair_pending"):
                repair_ordinal += 1
                successor = await workflow.execute_activity(
                    repair_scene_activity,
                    {"case_id": case_id, "failed_candidate_ref": candidate_ref, "expected_ordinal": repair_ordinal},
                    start_to_close_timeout=timedelta(seconds=300), retry_policy=retry_policy,
                )
                if successor.get("status") == "candidate_ready":
                    candidate_ref = successor["candidate_ref"]
                    continue
                return {"status": successor.get("status", "blocked"), "correction_ref": correction_ref,
                        "candidate_ref": candidate_ref,
                        "safe_error_code": successor.get("safe_error_code", "repair_failed")}
            return {"status": scene_status or "blocked", "correction_ref": correction_ref,
                    "candidate_ref": candidate_ref, "safe_error_code": qual_scene.get("safe_error_code", "scene_qualification_failed")}
        review = await workflow.execute_activity(
            ensure_review_ready_activity, {
                "case_id": case_id,
                "section_ref": section_ref,
                "candidate_ref": candidate_ref,
            },
            start_to_close_timeout=timedelta(seconds=30), retry_policy=retry_policy,
        )
        if review.get("status") not in ("created", "existing", "ready"):
            return {"status": "uncertain", "correction_ref": correction_ref, "candidate_ref": candidate_ref,
                    "safe_error_code": "review_handoff_unavailable"}
        review_data = review.get("data") or {}
        self.case_id = case_id
        self._register_review(review_data)
        review_ref = review_data.get("review_ref")
        if not review_ref:
            return {"status": "uncertain", "correction_ref": correction_ref, "candidate_ref": candidate_ref,
                    "safe_error_code": "review_handoff_unavailable"}
        await workflow.wait_condition(
            lambda: any(
                item.get("review_ref") == review_ref
                for item in self.consumed_decisions.values()
            )
        )
        return {"status": "review_ready", "correction_ref": correction_ref,
                "case_id": case_id, "candidate_ref": candidate_ref,
                "review_ref": review_ref}
