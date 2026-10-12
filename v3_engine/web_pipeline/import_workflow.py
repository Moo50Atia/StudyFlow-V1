"""Temporal workflow for verified historical archive import (Spec 019)."""

from __future__ import annotations

from datetime import timedelta
from typing import Any

from temporalio import workflow

with workflow.unsafe.imports_passed_through():
    from temporalio.common import RetryPolicy
from v3_engine.web_pipeline.contracts import sanitize_activity_result
from v3_engine.web_pipeline.artifact_refs import is_valid_uuid_ref


@workflow.defn(name="ApplicationImportWorkflow")
class ApplicationImportWorkflow:
    """Delegate archive verification and transactional materialization to Laravel."""

    _workflow_definition_name = "ApplicationImportWorkflow"

    @workflow.run
    async def run(self, payload: dict[str, Any]) -> dict[str, Any]:
        if (
            not isinstance(payload, dict)
            or payload.get("workflow_kind") != "web_import_v1"
            or not is_valid_uuid_ref(str(payload.get("run_id", "")))
            or not is_valid_uuid_ref(str(payload.get("manifest_ref", "")))
        ):
            return {"outcome": "failed", "safe_code": "contract_invalid"}

        result = await workflow.execute_activity(
            "execute_terminal_import_activity",
            {
                "run_id": str(payload["run_id"]),
                "manifest_ref": str(payload["manifest_ref"]),
            },
            task_queue="v3-terminal-control",
            start_to_close_timeout=timedelta(minutes=5),
            retry_policy=RetryPolicy(maximum_attempts=1),
        )
        return sanitize_activity_result(result)


async def execute_local_import(payload: dict[str, Any]) -> dict[str, Any]:
    """Do not pretend an import completed without the signed Laravel boundary."""
    if (
        not isinstance(payload, dict)
        or payload.get("workflow_kind") != "web_import_v1"
        or not is_valid_uuid_ref(str(payload.get("run_id", "")))
        or not is_valid_uuid_ref(str(payload.get("manifest_ref", "")))
    ):
        return {"outcome": "failed", "safe_code": "contract_invalid"}
    return {
        "outcome": "blocked",
        "safe_code": "storage_unavailable",
        "run_id": payload.get("run_id"),
    }
