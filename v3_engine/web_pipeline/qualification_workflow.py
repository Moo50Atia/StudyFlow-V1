"""Durable workflow for qualifying a locally authored scene candidate."""

from __future__ import annotations

from datetime import timedelta
from typing import Any
from temporalio import workflow

with workflow.unsafe.imports_passed_through():
    from temporalio.common import RetryPolicy
    from v3_engine.web_pipeline.contracts import sanitize_activity_result


@workflow.defn(name="SceneQualificationWorkflow")
class SceneQualificationWorkflow:
    """Run the existing isolated browser checker from a durable, replayable workflow."""

    @workflow.run
    async def run(self, payload: dict[str, Any]) -> dict[str, Any]:
        result = await workflow.execute_activity(
            "qualify_authoring_candidate_activity",
            {
                "run_id": str(payload.get("run_id", "")),
                "case_id": str(payload.get("case_id", "")),
                "candidate_ref": str(payload.get("candidate_ref", "")),
            },
            task_queue="v3-terminal-build",
            start_to_close_timeout=timedelta(minutes=5),
            # Keep accepted candidates durably held while private storage or Chromium
            # is unavailable. The activity reports content failures as results; only
            # infrastructure failures raise retryable ApplicationErrors.
            retry_policy=RetryPolicy(
                initial_interval=timedelta(seconds=1),
                backoff_coefficient=2.0,
                maximum_interval=timedelta(minutes=5),
                maximum_attempts=0,
            ),
        )
        return sanitize_activity_result(result)
