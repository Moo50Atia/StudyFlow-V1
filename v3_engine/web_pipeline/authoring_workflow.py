"""Temporal workflow for teacher-requested scene authoring (Spec 019)."""

from __future__ import annotations

from datetime import timedelta
from typing import Any
from temporalio import workflow

with workflow.unsafe.imports_passed_through():
    from temporalio.common import RetryPolicy
    from v3_engine.authoring.activities import authoring_execute_attempts


@workflow.defn(name="ApplicationAuthoringWorkflow")
class ApplicationAuthoringWorkflow:
    @workflow.run
    async def run(self, subject: dict[str, Any]) -> dict[str, Any]:
        run_id = str(subject.get("run_id", ""))
        manifest_ref = str(subject.get("manifest_ref", ""))
        resolved = await workflow.execute_activity(
            "resolve_web_authoring_manifest_activity",
            {"run_id": run_id, "manifest_ref": manifest_ref},
            task_queue="v3-terminal-control",
            start_to_close_timeout=timedelta(seconds=60),
            retry_policy=RetryPolicy(maximum_attempts=3),
        )
        if resolved.get("outcome") != "completed":
            return resolved

        authored = await workflow.execute_activity(
            authoring_execute_attempts,
            {"case_id": resolved["case_id"], "request_ref": resolved["request_ref"]},
            task_queue="v3-terminal-io",
            start_to_close_timeout=timedelta(minutes=10),
            retry_policy=RetryPolicy(maximum_attempts=1),
        )
        status = authored.get("status")
        if status == "candidate_ready" and authored.get("candidate_ref"):
            checked = await workflow.execute_activity(
                "qualify_authoring_candidate_activity",
                {
                    "run_id": run_id,
                    "case_id": resolved["case_id"],
                    "candidate_ref": authored["candidate_ref"],
                },
                task_queue="v3-terminal-build",
                start_to_close_timeout=timedelta(minutes=3),
                retry_policy=RetryPolicy(maximum_attempts=1),
            )
            if checked.get("outcome") != "completed":
                return {
                    "outcome": checked.get("outcome", "failed"),
                    "safe_code": checked.get("safe_code", "browser_check_error"),
                    "run_id": run_id,
                    "case_id": resolved["case_id"],
                    "request_ref": resolved["request_ref"],
                    "candidate_ref": authored["candidate_ref"],
                }
            return {
                "outcome": "completed",
                "safe_code": "ok",
                "run_id": run_id,
                "case_id": resolved["case_id"],
                "request_ref": resolved["request_ref"],
                "candidate_ref": authored.get("candidate_ref"),
                "check_ref": checked.get("result_ref"),
                "verdict": checked.get("verdict"),
            }
        safe_code = authored.get("safe_error_code")
        if safe_code not in {"authoring_provider_unavailable", "authoring_attempt_uncertain"}:
            safe_code = "provider_unavailable"
        return {
            "outcome": "uncertain" if status == "uncertain" else "failed",
            "safe_code": safe_code,
            "run_id": run_id,
            "case_id": resolved["case_id"],
            "request_ref": resolved["request_ref"],
        }
