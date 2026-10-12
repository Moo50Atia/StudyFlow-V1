"""Signed Laravel activities for historical archive import (Spec 019)."""

from __future__ import annotations

from typing import Any

from temporalio import activity

from v3_engine.web_pipeline.artifact_refs import is_valid_uuid_ref
from v3_engine.web_pipeline.application_client import ApplicationClient, ApplicationPipelineError
from v3_engine.web_pipeline.contracts import sanitize_activity_result


@activity.defn(name="execute_terminal_import_activity")
async def execute_terminal_import_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Resolve the private import manifest and invoke Laravel's real importer."""
    run_id = str(args.get("run_id", ""))
    manifest_ref = str(args.get("manifest_ref", ""))
    if not is_valid_uuid_ref(run_id) or not is_valid_uuid_ref(manifest_ref):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "receipt_invalid"})

    client = ApplicationClient(timeout=180.0)
    try:
        manifest = await client.get_manifest(manifest_ref)
        if (
            manifest.get("kind") != "web_import_v1"
            or str(manifest.get("run_id", "")) != run_id
            or str(manifest.get("source_run_id", "")) != run_id
            or not is_valid_uuid_ref(str(manifest.get("import_id", "")))
            or not isinstance(manifest.get("import_digest"), str)
            or len(manifest["import_digest"]) != 64
            or not isinstance(manifest.get("terminal_root"), str)
            or not manifest.get("terminal_root")
            or any(
                isinstance(manifest.get(key), bool)
                or not isinstance(manifest.get(key), int)
                or manifest[key] < 1
                for key in ("subject_id", "teacher_id", "actor_admin_id")
            )
        ):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "receipt_invalid"})

        result = await client.materialize_import({
            "terminal_root": manifest["terminal_root"],
            "run_id": run_id,
            "subject_id": manifest["subject_id"],
            "teacher_id": manifest["teacher_id"],
            "actor_admin_id": manifest["actor_admin_id"],
            "import_id": manifest["import_id"],
            "expected_digest": manifest["import_digest"],
        })
        state = result.get("state")
        if state not in {"completed", "partial", "duplicate"}:
            return sanitize_activity_result({
                "outcome": "failed",
                "safe_code": result.get("safe_code", "storage_unavailable"),
                "run_id": run_id,
                "import_id": manifest["import_id"],
            })
        return sanitize_activity_result({
            "outcome": "completed" if state in {"completed", "duplicate"} else "pending",
            "safe_code": "ok",
            "run_id": run_id,
            "import_id": manifest["import_id"],
            "state": state,
            "counts": result.get("counts", {}),
        })
    except ApplicationPipelineError as exc:
        safe_code = exc.code if exc.code in {
            "artifact_missing", "artifact_corrupt", "contract_invalid", "authentication_required",
            "account_mismatch", "import_conflict", "concurrent_update",
        } else "storage_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": safe_code, "run_id": run_id})
    except Exception:
        return sanitize_activity_result({"outcome": "failed", "safe_code": "storage_unavailable", "run_id": run_id})
