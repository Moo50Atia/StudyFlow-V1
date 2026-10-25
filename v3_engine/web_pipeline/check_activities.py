"""Temporal activities for running and recording browser qualification checks (Spec 019 / T043)."""

from __future__ import annotations

import hashlib
import json
import logging
from pathlib import Path
import tempfile
from typing import Any
from temporalio import activity
from temporalio.exceptions import ApplicationError
import httpx

from v3_engine.qualification.browser_checks import run_browser_checks
from v3_engine.web_pipeline.contracts import sanitize_activity_result
from v3_engine.web_pipeline.application_client import ApplicationClient, ApplicationPipelineError
from v3_engine.web_pipeline.artifact_refs import is_valid_uuid_ref

logger = logging.getLogger("v3_engine.web_pipeline.check_activities")


def _raise_retryable_qualification_error(message: str) -> None:
    """Keep the Temporal workflow open when infrastructure, not the scene, failed."""
    raise ApplicationError(message, type="qualification_temporarily_unavailable", non_retryable=False)


@activity.defn(name="qualify_authoring_candidate_activity")
async def qualify_authoring_candidate_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Fetch a committed candidate privately, run Chromium checks, and persist the proof in Laravel."""
    run_id = str(args.get("run_id", ""))
    case_id = str(args.get("case_id", ""))
    candidate_ref = str(args.get("candidate_ref", ""))
    if not all(is_valid_uuid_ref(value) for value in (run_id, case_id, candidate_ref)):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "receipt_invalid", "verdict": "fail"})

    client = ApplicationClient(timeout=60.0)
    try:
        envelope = await client.get_scene_candidate(case_id, candidate_ref)
        candidate = envelope.get("data")
        if isinstance(candidate, dict) and "data" in candidate and isinstance(candidate["data"], dict):
            candidate = candidate["data"]
        if not isinstance(candidate, dict):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "receipt_invalid", "verdict": "fail"})
        manifest = candidate.get("manifest")
        files = candidate.get("files")
        manifest_sha256 = candidate.get("manifest_sha256")
        profile = candidate.get("package_profile", "static_three_file_v1")
        if not isinstance(manifest, dict) or not isinstance(files, list):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "manifest_invalid", "verdict": "fail"})

        manifest_files = manifest.get("files")
        if not isinstance(manifest_files, list):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "manifest_invalid", "verdict": "fail"})
        expected = {entry.get("path"): entry for entry in manifest_files if isinstance(entry, dict)}
        allowed = {"index.html", "styles.css", "script.js"}
        if profile == "manual_scene_files_v1":
            valid_paths = 1 <= len(expected) <= 3 and set(expected).issubset(allowed) and "index.html" in expected
        else:
            valid_paths = profile == "static_three_file_v1" and set(expected) == allowed
        if (not valid_paths or len(expected) != len(manifest_files)
                or len(files) != len(expected) or len(set(expected)) != len(expected)):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "manifest_invalid", "verdict": "fail"})

        with tempfile.TemporaryDirectory(prefix=f"v3-scene-{candidate_ref[:8]}-") as temp_dir:
            candidate_dir = Path(temp_dir)
            for file_doc in files:
                if not isinstance(file_doc, dict):
                    return sanitize_activity_result({"outcome": "failed", "safe_code": "manifest_invalid", "verdict": "fail"})
                rel_path = file_doc.get("path")
                content = file_doc.get("content")
                meta = expected.get(rel_path)
                if not isinstance(rel_path, str) or not isinstance(content, str) or not meta:
                    return sanitize_activity_result({"outcome": "failed", "safe_code": "manifest_invalid", "verdict": "fail"})
                body = content.encode("utf-8")
                if len(body) != meta.get("bytes") or hashlib.sha256(body).hexdigest() != meta.get("sha256"):
                    return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_corrupt", "verdict": "fail"})
                (candidate_dir / rel_path).write_bytes(body)

            claim = await client.claim_scene_check(case_id, candidate_ref, 1)
            check_claim = claim.get("data", {})
            attempt_ref = check_claim.get("attempt_ref")
            disposition = check_claim.get("disposition")
            if not is_valid_uuid_ref(str(attempt_ref or "")):
                return sanitize_activity_result({"outcome": "failed", "safe_code": "receipt_invalid", "verdict": "fail"})
            if disposition == "recorded":
                return sanitize_activity_result({
                    "outcome": "completed", "safe_code": "ok", "verdict": check_claim.get("verdict", "fail"),
                    "result_ref": check_claim.get("check_ref"), "candidate_ref": candidate_ref,
                })

            # The callback provides exact bytes so signed manifests are never re-encoded.
            raw_manifest = candidate.get("manifest_raw_base64")
            if raw_manifest:
                import base64
                manifest_bytes = base64.b64decode(raw_manifest, validate=True)
                if hashlib.sha256(manifest_bytes).hexdigest() != manifest_sha256 or json.loads(manifest_bytes) != manifest:
                    return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_corrupt", "verdict": "fail"})
                (candidate_dir / "manifest.json").write_bytes(manifest_bytes)

            raw_result = await run_browser_checks(
                candidate_dir=str(candidate_dir),
                candidate_ref=candidate_ref,
                manifest=manifest,
                manifest_sha256=manifest_sha256,
            )
            screenshot_b64 = raw_result.pop("screenshot_png_base64", "")
            screenshot_sha256 = str(raw_result.get("screenshot_sha256", ""))
            if not screenshot_b64 or not screenshot_sha256:
                return sanitize_activity_result({"outcome": "failed", "safe_code": "browser_check_error", "verdict": "fail"})
            report_json = json.dumps(raw_result, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
            report_sha256 = hashlib.sha256(report_json.encode("utf-8")).hexdigest()
            persisted = await client.record_scene_check(case_id, str(attempt_ref), {
                "schema_version": 1,
                "report_json": report_json,
                "report_sha256": report_sha256,
                "screenshot_png_base64": screenshot_b64,
                "screenshot_sha256": screenshot_sha256,
            })
            recorded = persisted.get("data", {})
            verdict = recorded.get("state")
            if verdict not in {"pass", "fail"}:
                return sanitize_activity_result({"outcome": "failed", "safe_code": "receipt_invalid", "verdict": "fail"})
            return sanitize_activity_result({
                "outcome": "completed", "safe_code": "ok", "verdict": verdict,
                "result_ref": recorded.get("check_ref"), "candidate_ref": candidate_ref,
                "checks": raw_result.get("checks", []),
                "report_sha256": report_sha256,
            })
    except ApplicationPipelineError as exc:
        if exc.status_code in {408, 425, 429} or exc.status_code >= 500:
            # Every operation is replay-safe: candidate fetch is read-only, claim uses a
            # stable attempt number, and result recording has exact-byte idempotency.
            _raise_retryable_qualification_error("Qualification service is temporarily unavailable.")
        if exc.code == "authoring_unavailable":
            msg = str(exc.message or "")
            if "missing" in msg:
                safe_code = "artifact_missing"
            elif "mismatch" in msg or "corrupt" in msg:
                safe_code = "artifact_corrupt"
            else:
                safe_code = "storage_unavailable"
        elif exc.code in {"artifact_missing", "artifact_corrupt", "contract_invalid", "scene_unavailable"}:
            safe_code = exc.code
        else:
            safe_code = "storage_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": safe_code, "verdict": "fail"})
    except httpx.TransportError:
        _raise_retryable_qualification_error("Qualification service could not be reached.")
    except Exception:
        # Content defects are returned as seven-check reports; unexpected exceptions
        # here indicate checker or infrastructure failure and must not settle a
        # candidate as a validation failure.
        logger.exception("Web authoring candidate qualification infrastructure failed")
        _raise_retryable_qualification_error("Qualification checker is temporarily unavailable.")


@activity.defn(name="execute_browser_checks_activity")
async def execute_browser_checks_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Execute real headless Chromium browser checks against candidate directory and manifest."""
    candidate_dir = args.get("candidate_dir", "")
    candidate_ref = args.get("candidate_ref", "")
    manifest = args.get("manifest", {})
    manifest_sha256 = args.get("manifest_sha256")

    if not candidate_dir or not Path(candidate_dir).exists():
        return sanitize_activity_result({
            "outcome": "failed",
            "safe_code": "candidate_dir_missing",
            "verdict": "fail",
        })

    if not manifest or "files" not in manifest:
        return sanitize_activity_result({
            "outcome": "failed",
            "safe_code": "manifest_invalid",
            "verdict": "fail",
        })

    try:
        raw_result = await run_browser_checks(
            candidate_dir=candidate_dir,
            candidate_ref=candidate_ref,
            manifest=manifest,
            manifest_sha256=manifest_sha256,
        )

        checks = raw_result.get("checks", [])
        verdict = "pass" if all(c.get("status") == "pass" for c in checks) else "fail"

        report_json = json.dumps(raw_result, sort_keys=True)
        report_sha = hashlib.sha256(report_json.encode("utf-8")).hexdigest()

        result = {
            "outcome": "completed",
            "safe_code": "ok",
            "verdict": verdict,
            "checks": checks,
            "report_json": report_json,
            "report_sha256": report_sha,
            "screenshot_sha256": raw_result.get("screenshot_sha256", ""),
            "screenshot_png_base64": raw_result.get("screenshot_png_base64", ""),
            "started_at": raw_result.get("started_at"),
            "finished_at": raw_result.get("finished_at"),
        }
        return sanitize_activity_result(result)
    except Exception as exc:
        logger.exception("Browser checks execution failed: %s", exc)
        return sanitize_activity_result({
            "outcome": "failed",
            "safe_code": "browser_check_error",
            "verdict": "fail",
        })
