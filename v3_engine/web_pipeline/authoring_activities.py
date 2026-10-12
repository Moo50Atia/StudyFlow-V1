"""Activities that join teacher-triggered authoring requests to the web pipeline."""

from __future__ import annotations

from typing import Any
from temporalio import activity

from v3_engine.web_pipeline.artifact_refs import is_valid_uuid_ref
from v3_engine.web_pipeline.application_client import ApplicationClient, ApplicationPipelineError
from v3_engine.web_pipeline.contracts import sanitize_activity_result


@activity.defn(name="resolve_web_authoring_manifest_activity")
async def resolve_web_authoring_manifest_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Resolve the immutable teacher authoring request through Laravel's signed API."""
    run_id = str(args.get("run_id", ""))
    manifest_ref = str(args.get("manifest_ref", ""))
    if not is_valid_uuid_ref(run_id) or not is_valid_uuid_ref(manifest_ref):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        manifest = await ApplicationClient().get_manifest(manifest_ref)
        if (
            manifest.get("kind") != "web_authoring_v1"
            or str(manifest.get("run_id", "")) != run_id
            or not is_valid_uuid_ref(str(manifest.get("case_id", "")))
            or not is_valid_uuid_ref(str(manifest.get("request_ref", "")))
        ):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "receipt_invalid"})
        return sanitize_activity_result({
            "outcome": "completed",
            "safe_code": "ok",
            "case_id": manifest["case_id"],
            "request_ref": manifest["request_ref"],
            "section_ref": manifest.get("section_ref"),
        })
    except ApplicationPipelineError as exc:
        safe_code = exc.code if exc.code in {"artifact_missing", "contract_invalid"} else "storage_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": safe_code})
    except Exception:
        return sanitize_activity_result({"outcome": "failed", "safe_code": "storage_unavailable"})

