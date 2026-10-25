"""Bounded contract schemas, validation, and sanitization for V3 Web Pipeline."""

from __future__ import annotations

import hashlib
import json
import re
from typing import Any

from v3_engine.web_pipeline.artifact_refs import (
    MAX_METADATA_BYTES,
    MAX_STATIC_PACKAGE_BYTES,
    is_valid_uuid_ref,
)

SAFE_CODES = (
    "ok",
    "authentication_required",
    "account_mismatch",
    "provider_rate_limit",
    "provider_unavailable",
    "source_error",
    "map_unreadable",
    "pdf_invalid",
    "policy_violation",
    "context_incomplete",
    "delivery_uncertain",
    "history_conflict",
    "reset_uncertain",
    "storage_unavailable",
    "receipt_invalid",
    "protocol_validation",
    "artifact_missing",
    "artifact_corrupt",
    "scene_package_invalid",
    "build_failed",
    "configuration_invalid",
    "configuration_drift",
    "operation_conflict",
    "control_conflict",
    "stale_version",
    "queue_full",
    "concurrent_update",
    "cancelled",
)

ALLOWED_WORKFLOW_KINDS = ("web_subject_v1", "web_import_v1", "web_authoring_v1")

DISALLOWED_RESULT_KEYS = {
    "body", "prompt", "text", "code", "secret", "token",
    "key", "password", "exception", "traceback", "trace",
    "payload", "html", "markdown", "script", "student", "email",
}


class ContractValidationError(ValueError):
    """Contract or schema invariant violation."""


def compute_canonical_digest(data: Any) -> str:
    """
    Compute canonical SHA-256 hash.
    Distinguishes null (None) from empty string.
    """
    if isinstance(data, (dict, list)):
        serialized = json.dumps(data, sort_keys=True, separators=(",", ":")).encode("utf-8")
    elif isinstance(data, str):
        serialized = data.encode("utf-8")
    elif isinstance(data, bytes):
        serialized = data
    elif data is None:
        serialized = b""
    else:
        serialized = json.dumps(data, sort_keys=True, separators=(",", ":")).encode("utf-8")

    return hashlib.sha256(serialized).hexdigest()


def validate_static_scene_package(package: dict[str, Any]) -> None:
    """
    FR-012, FR-015: Enforce static three-file scene package constraints.
    - Exactly index.html, styles.css, script.js
    - schema_version == 1
    - Files non-empty, each <= 1 MiB, total <= 4 MiB
    - No path traversal or slashes in filenames
    """
    if not isinstance(package, dict):
        raise ContractValidationError("Static scene package must be a JSON object")

    if package.get("schema_version") != 1:
        raise ContractValidationError(
            f"Expected schema_version 1, got {package.get('schema_version')}"
        )

    files = package.get("files")
    if not isinstance(files, list) or len(files) != 3:
        raise ContractValidationError(
            f"Expected exactly 3 files in static package, got {len(files) if isinstance(files, list) else type(files)}"
        )

    expected_files = {"index.html", "styles.css", "script.js"}
    found_files = set()
    total_bytes = 0

    for file_info in files:
        if not isinstance(file_info, dict):
            raise ContractValidationError("Each file entry must be a dictionary")

        path = file_info.get("path")
        if not isinstance(path, str):
            raise ContractValidationError("File path must be a string")

        if "/" in path or "\\" in path or ".." in path:
            raise ContractValidationError(f"Path traversal or directory separators forbidden: {path!r}")

        if path not in expected_files:
            raise ContractValidationError(f"Unexpected file path {path!r} in static scene package")

        if path in found_files:
            raise ContractValidationError(f"Duplicate file path: {path!r}")
        found_files.add(path)

        content = file_info.get("content")
        if not isinstance(content, str) or len(content.strip()) == 0:
            raise ContractValidationError(f"File {path!r} content must be non-empty string")

        content_bytes = len(content.encode("utf-8"))
        if content_bytes > 1048576:  # 1 MiB
            raise ContractValidationError(
                f"File {path!r} size ({content_bytes} bytes) exceeds 1 MiB limit"
            )

        total_bytes += content_bytes

    if found_files != expected_files:
        missing = expected_files - found_files
        raise ContractValidationError(f"Missing required static package files: {missing}")

    if total_bytes > MAX_STATIC_PACKAGE_BYTES:
        raise ContractValidationError(
            f"Total package size ({total_bytes} bytes) exceeds 4 MiB limit"
        )


def validate_admission_payload(payload: dict[str, Any]) -> None:
    """
    Validate admission schema 1:
    submission_id UUID, submission_digest SHA, run_id UUID, manifest_ref UUID, workflow_kind enum.
    Enforces unknown-key rejection and 32 KiB payload ceiling.
    """
    if not isinstance(payload, dict):
        raise ContractValidationError("Admission payload must be a JSON dictionary")

    raw_bytes = json.dumps(payload).encode("utf-8")
    if len(raw_bytes) > MAX_METADATA_BYTES:
        raise ContractValidationError(
            f"Payload size ({len(raw_bytes)} bytes) exceeds {MAX_METADATA_BYTES} ceiling"
        )

    allowed_keys = {
        "submission_id",
        "submission_digest",
        "run_id",
        "manifest_ref",
        "workflow_kind",
        "subject_id",
        "retry",
    }
    unknown_keys = set(payload.keys()) - allowed_keys
    if unknown_keys:
        raise ContractValidationError(f"Unknown keys in admission payload: {unknown_keys}")

    for required_uuid in ("submission_id", "run_id", "manifest_ref"):
        val = payload.get(required_uuid)
        if not val or not is_valid_uuid_ref(str(val)):
            raise ContractValidationError(f"Invalid UUID for {required_uuid}: {val!r}")

    if "subject_id" in payload and not is_valid_uuid_ref(str(payload.get("subject_id", ""))):
        raise ContractValidationError("Invalid UUID for subject_id")

    if "retry" in payload and not isinstance(payload.get("retry"), bool):
        raise ContractValidationError("retry must be a boolean")

    digest = payload.get("submission_digest")
    if not isinstance(digest, str) or not re.match(r"^[0-9a-f]{64}$", digest.lower()):
        raise ContractValidationError(f"Invalid SHA-256 submission_digest: {digest!r}")

    kind = payload.get("workflow_kind")
    if kind not in ALLOWED_WORKFLOW_KINDS:
        raise ContractValidationError(
            f"Invalid workflow_kind {kind!r}; must be one of {ALLOWED_WORKFLOW_KINDS}"
        )


def sanitize_activity_result(result: dict[str, Any]) -> dict[str, Any]:
    """
    Sanitize activity result:
    - Strips raw code, prompt, secret, and exception strings
    - Guarantees valid outcome and safe_code
    - Validates counts as bounded non-negative integers
    """
    if not isinstance(result, dict):
        return {
            "outcome": "failed",
            "safe_code": "receipt_invalid",
        }

    outcome = str(result.get("outcome", "failed")).lower()
    allowed_outcomes = {"completed", "pending", "failed", "blocked", "uncertain", "cancelled"}
    if outcome not in allowed_outcomes:
        outcome = "failed"

    raw_safe_code = str(result.get("safe_code", "ok")).lower()
    safe_code = raw_safe_code if raw_safe_code in SAFE_CODES else "unknown"

    clean_counts = None
    counts = result.get("counts")
    if isinstance(counts, dict):
        clean_counts = {}
        for k, v in counts.items():
            if str(k).lower() in DISALLOWED_RESULT_KEYS:
                continue
            if isinstance(v, int) and v >= 0:
                clean_counts[str(k)] = v

    sanitized: dict[str, Any] = {
        "outcome": outcome,
        "safe_code": safe_code,
    }

    receipt_ref = result.get("receipt_ref")
    if receipt_ref and is_valid_uuid_ref(str(receipt_ref)):
        sanitized["receipt_ref"] = str(receipt_ref).lower()

    result_ref = result.get("result_ref")
    if result_ref and is_valid_uuid_ref(str(result_ref)):
        sanitized["result_ref"] = str(result_ref).lower()

    if clean_counts:
        sanitized["counts"] = clean_counts

    if result.get("verdict") in ("pass", "fail"):
        sanitized["verdict"] = result["verdict"]

    if isinstance(result.get("checks"), list):
        sanitized["checks"] = result["checks"]

    if isinstance(result.get("report_sha256"), str):
        sanitized["report_sha256"] = result["report_sha256"]

    if isinstance(result.get("lessons_saved"), int):
        sanitized["lessons_saved"] = result["lessons_saved"]

    if isinstance(result.get("prompts_saved"), int):
        sanitized["prompts_saved"] = result["prompts_saved"]

    if result.get("mode") in {"export_manual", "api_generate", "selected_api"}:
        sanitized["mode"] = result["mode"]

    if result.get("state") in {"completed", "partial", "duplicate"}:
        sanitized["state"] = result["state"]

    import_ref = result.get("import_id")
    if import_ref and is_valid_uuid_ref(str(import_ref)):
        sanitized["import_id"] = str(import_ref).lower()

    framework_ref = result.get("framework_version_id")
    if framework_ref and is_valid_uuid_ref(str(framework_ref)):
        sanitized["framework_version_id"] = str(framework_ref).lower()

    for ref_key in ("case_id", "request_ref", "section_ref", "candidate_ref"):
        ref_value = result.get(ref_key)
        if ref_value and is_valid_uuid_ref(str(ref_value)):
            sanitized[ref_key] = str(ref_value).lower()

    items = result.get("items")
    if isinstance(items, list) and len(items) <= 640:
        safe_items = []
        for item in items:
            if not isinstance(item, dict):
                safe_items = []
                break
            item_ref = item.get("item_id")
            ordinal = item.get("pdf_ordinal")
            if (not item_ref or not is_valid_uuid_ref(str(item_ref))
                    or not isinstance(ordinal, int) or isinstance(ordinal, bool) or ordinal < 1):
                safe_items = []
                break
            safe_items.append({"item_id": str(item_ref).lower(), "pdf_ordinal": ordinal})
        if safe_items or not items:
            sanitized["items"] = safe_items

    return sanitized

