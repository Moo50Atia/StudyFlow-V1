"""Protocol schemas, strict serialization, and validation for local-worker.v1."""

from __future__ import annotations

import hashlib
import json
import re
from typing import Any, Dict, List, Mapping

SCHEMA_VERSION = 1
UUID_REGEX = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$", re.IGNORECASE)
SHA256_REGEX = re.compile(r"^[0-9a-f]{64}$")

DEFAULT_MAX_SIZE = 262144  # 256 KiB
MANIFEST_MAX_SIZE = 2097152  # 2 MiB


class LocalWorkerProtocolError(ValueError):
    """Exception for schema, bounds, encoding, or lineage errors."""

    def __init__(self, message: str, code: str = "contract_invalid", status_code: int = 422):
        super().__init__(message)
        self.code = code
        self.status_code = status_code


def _detect_duplicate_pairs(pairs: List[tuple[str, Any]]) -> Dict[str, Any]:
    obj: Dict[str, Any] = {}
    for key, val in pairs:
        if key in obj:
            raise LocalWorkerProtocolError(
                f"Duplicate key '{key}' detected in JSON object",
                code="duplicate_key",
                status_code=422,
            )
        obj[key] = val
    return obj


def strict_json_decode(text: str | bytes, max_size: int = DEFAULT_MAX_SIZE) -> Any:
    """Decode JSON with strict UTF-8 checking, size bounding, and duplicate key rejection."""
    if isinstance(text, bytes):
        raw_bytes = text
        if len(raw_bytes) > max_size:
            raise LocalWorkerProtocolError(
                f"Payload exceeds maximum allowed size of {max_size} bytes",
                code="bounds_exceeded",
                status_code=413,
            )
        try:
            str_text = raw_bytes.decode("utf-8")
        except UnicodeDecodeError as e:
            raise LocalWorkerProtocolError(
                f"Payload contains invalid UTF-8: {e}",
                code="invalid_utf8",
                status_code=422,
            ) from e
    else:
        str_text = text
        raw_bytes = str_text.encode("utf-8")
        if len(raw_bytes) > max_size:
            raise LocalWorkerProtocolError(
                f"Payload exceeds maximum allowed size of {max_size} bytes",
                code="bounds_exceeded",
                status_code=413,
            )

    try:
        return json.loads(str_text, object_pairs_hook=_detect_duplicate_pairs)
    except json.JSONDecodeError as e:
        raise LocalWorkerProtocolError(
            f"Malformed JSON payload: {e}",
            code="malformed_json",
            status_code=422,
        ) from e


def assert_uuid(val: Any, field_name: str) -> None:
    if not isinstance(val, str) or not UUID_REGEX.match(val):
        raise LocalWorkerProtocolError(
            f"Field '{field_name}' must be a valid UUID",
            code="invalid_uuid",
            status_code=422,
        )


def assert_sha256(val: Any, field_name: str) -> None:
    if not isinstance(val, str) or not SHA256_REGEX.match(val):
        raise LocalWorkerProtocolError(
            f"Field '{field_name}' must be a 64-character lowercase hexadecimal SHA-256 digest",
            code="invalid_sha256",
            status_code=422,
        )


def assert_exact_keys(
    payload: Mapping[str, Any],
    required_keys: List[str],
    optional_keys: List[str] | None = None,
    context: str = "payload",
) -> None:
    allowed = set(required_keys) | set(optional_keys or [])
    for req in required_keys:
        if req not in payload:
            raise LocalWorkerProtocolError(
                f"Missing required field '{req}' in {context}",
                code="missing_required_field",
                status_code=422,
            )
    for key in payload:
        if key not in allowed:
            raise LocalWorkerProtocolError(
                f"Unknown field '{key}' is not permitted in {context}",
                code="unknown_field",
                status_code=422,
            )


def validate_input_manifest(manifest: Mapping[str, Any]) -> None:
    assert_exact_keys(
        manifest,
        [
            "schema_version",
            "kind",
            "task_id",
            "job_id",
            "run_id",
            "task_kind",
            "execution_mode",
            "output_scope",
            "protocol_id",
            "protocol_version",
            "settings_snapshot_id",
            "settings_sha256",
            "runtime_settings_artifact_id",
            "runtime_settings_sha256",
            "preparation",
            "items",
            "instruction_artifacts",
            "artifacts",
            "required_outputs",
            "resource_budget",
            "capabilities",
        ],
        optional_keys=["required_scenes"],
        context="input_manifest",
    )

    if manifest.get("schema_version") != SCHEMA_VERSION:
        raise LocalWorkerProtocolError(
            f"Unsupported schema_version '{manifest.get('schema_version')}'; expected {SCHEMA_VERSION}",
            code="unsupported_schema_version",
            status_code=422,
        )

    if manifest.get("kind") != "local_worker_input_manifest":
        raise LocalWorkerProtocolError("Invalid manifest kind", code="invalid_kind", status_code=422)

    assert_uuid(manifest["task_id"], "task_id")
    assert_uuid(manifest["job_id"], "job_id")
    assert_uuid(manifest["run_id"], "run_id")

    if manifest["task_kind"] not in ("author_prompts", "return_scenes"):
        raise LocalWorkerProtocolError(f"Invalid task_kind '{manifest['task_kind']}'", code="invalid_task_kind")

    if manifest["execution_mode"] != "local_worker_v1":
        raise LocalWorkerProtocolError("Invalid execution_mode", code="invalid_execution_mode")

    if manifest["output_scope"] not in ("prompts_only", "prompts_and_scenes"):
        raise LocalWorkerProtocolError("Invalid output_scope", code="invalid_output_scope")

    assert_sha256(manifest["settings_sha256"], "settings_sha256")
    assert_uuid(manifest["runtime_settings_artifact_id"], "runtime_settings_artifact_id")
    assert_sha256(manifest["runtime_settings_sha256"], "runtime_settings_sha256")

    items = manifest.get("items")
    if not isinstance(items, list) or not items:
        raise LocalWorkerProtocolError("Manifest 'items' must be a non-empty list", code="invalid_items")

    for idx, item in enumerate(items):
        assert_exact_keys(
            item,
            [
                "item_id",
                "original_artifact_id",
                "original_sha256",
                "original_byte_count",
                "original_page_count",
                "upload_ordinal",
                "source_ordinal",
                "part_ordinal",
                "original_page_start",
                "original_page_end",
                "prepared_artifact_id",
                "prepared_sha256",
                "prepared_byte_count",
                "prepared_page_count",
            ],
            context=f"items[{idx}]",
        )
        assert_uuid(item["item_id"], f"items[{idx}].item_id")
        assert_uuid(item["original_artifact_id"], f"items[{idx}].original_artifact_id")
        assert_sha256(item["original_sha256"], f"items[{idx}].original_sha256")
        assert_uuid(item["prepared_artifact_id"], f"items[{idx}].prepared_artifact_id")
        assert_sha256(item["prepared_sha256"], f"items[{idx}].prepared_sha256")
        positive_integer_fields = (
            "original_byte_count",
            "original_page_count",
            "upload_ordinal",
            "source_ordinal",
            "part_ordinal",
            "original_page_start",
            "original_page_end",
            "prepared_byte_count",
            "prepared_page_count",
        )
        for field in positive_integer_fields:
            value = item[field]
            if isinstance(value, bool) or not isinstance(value, int) or value < 1:
                raise LocalWorkerProtocolError(f"{field} must be a positive integer", code="invalid_numeric_bounds")
        if item["upload_ordinal"] != idx + 1:
            raise LocalWorkerProtocolError("upload_ordinal must match manifest order", code="invalid_numeric_bounds")
        if item["original_page_end"] > item["original_page_count"]:
            raise LocalWorkerProtocolError("Page range exceeds original page count", code="invalid_page_range")
        if item["prepared_page_count"] != item["original_page_end"] - item["original_page_start"] + 1:
            raise LocalWorkerProtocolError("Invalid page range", code="invalid_page_range")

    artifacts = manifest.get("artifacts")
    if not isinstance(artifacts, list):
        raise LocalWorkerProtocolError("artifacts must be a list", code="invalid_artifacts")
    artifact_by_id: dict[str, Mapping[str, Any]] = {}
    logical_names: set[str] = set()
    allowed_purposes = {"runtime_settings", "framework_body", "instruction_bundle", "original_pdf", "prepared_pdf", "source_pdf", "preparation_inventory"}
    for index, artifact in enumerate(artifacts):
        assert_exact_keys(
            artifact,
            ["artifact_id", "purpose", "size_bytes", "digest_sha256", "media_type", "logical_name"],
            optional_keys=["sha256"],
            context=f"artifacts[{index}]",
        )
        assert_uuid(artifact["artifact_id"], f"artifacts[{index}].artifact_id")
        assert_sha256(artifact["digest_sha256"], f"artifacts[{index}].digest_sha256")
        size = artifact["size_bytes"]
        if isinstance(size, bool) or not isinstance(size, int) or size < 1:
            raise LocalWorkerProtocolError("Artifact size_bytes must be a positive integer", code="invalid_artifacts")
        name = artifact["logical_name"]
        if (
            not isinstance(name, str) or not name or name in {".", ".."}
            or "/" in name or "\\" in name or "\x00" in name
        ):
            raise LocalWorkerProtocolError("Artifact logical_name must be a safe filename", code="invalid_artifacts")
        if name in logical_names or artifact["artifact_id"] in artifact_by_id:
            raise LocalWorkerProtocolError("Artifact identities and names must be unique", code="invalid_artifacts")
        if artifact["purpose"] not in allowed_purposes or not isinstance(artifact["media_type"], str):
            raise LocalWorkerProtocolError("Artifact purpose or media_type is invalid", code="invalid_artifacts")
        if "sha256" in artifact and artifact["sha256"] != artifact["digest_sha256"]:
            raise LocalWorkerProtocolError("Artifact digest aliases disagree", code="invalid_artifacts")
        artifact_by_id[artifact["artifact_id"]] = artifact
        logical_names.add(name)

    if manifest["task_kind"] == "author_prompts":
        runtime = artifact_by_id.get(manifest["runtime_settings_artifact_id"])
        if not runtime or runtime["purpose"] != "runtime_settings" or runtime["digest_sha256"] != manifest["runtime_settings_sha256"]:
            raise LocalWorkerProtocolError("Runtime settings artifact is not bound by artifacts", code="settings_snapshot_mismatch")
    instructions = manifest.get("instruction_artifacts")
    if not isinstance(instructions, list):
        raise LocalWorkerProtocolError("instruction_artifacts must be a list", code="invalid_instructions")
    names: set[str] = set()
    for index, instruction in enumerate(instructions):
        assert_exact_keys(instruction, ["name", "version", "artifact_id", "sha256", "size_bytes"], context=f"instruction_artifacts[{index}]")
        if not isinstance(instruction["name"], str) or not instruction["name"] or instruction["name"] in names:
            raise LocalWorkerProtocolError("Instruction names must be unique non-empty strings", code="invalid_instructions")
        assert_uuid(instruction["artifact_id"], f"instruction_artifacts[{index}].artifact_id")
        assert_sha256(instruction["sha256"], f"instruction_artifacts[{index}].sha256")
        artifact = artifact_by_id.get(instruction["artifact_id"])
        expected_purpose = {
            "framework": "framework_body",
            "notebooklm_protocol": "instruction_bundle",
            "scene_system_template": "instruction_bundle",
        }.get(instruction["name"])
        if (
            not artifact or expected_purpose is None or artifact["purpose"] != expected_purpose
            or artifact["digest_sha256"] != instruction["sha256"]
            or artifact["size_bytes"] != instruction["size_bytes"]
        ):
            raise LocalWorkerProtocolError("Instruction artifact does not match artifact inventory", code="invalid_instructions")
        names.add(instruction["name"])
    if manifest["task_kind"] == "author_prompts" and manifest["protocol_id"] == "notebooklm_v1" and names != {"framework", "notebooklm_protocol", "scene_system_template"}:
        raise LocalWorkerProtocolError("Authoring manifest must seal framework, NotebookLM protocol, and scene template instructions", code="invalid_instructions")


def validate_captured_protocol_bundle(bundle: Mapping[str, Any], manifest: Mapping[str, Any]) -> None:
    """Validate the exact immutable authoring prompts included in a task manifest."""
    assert_exact_keys(bundle, [
        "schema_version", "protocol_id", "protocol_version", "divide_prompt", "execution_prompt",
        "lesson_start_prompt", "lesson_continue_prompt", "combined_response_format", "framework_sections",
    ], context="captured_protocol_bundle")
    if bundle.get("schema_version") != 1 or bundle.get("protocol_id") != manifest.get("protocol_id") or bundle.get("protocol_version") != manifest.get("protocol_version"):
        raise LocalWorkerProtocolError("Captured protocol identity does not match the manifest", code="protocol_snapshot_mismatch")
    for field in ("divide_prompt", "execution_prompt", "lesson_start_prompt", "lesson_continue_prompt", "combined_response_format"):
        if not isinstance(bundle.get(field), str) or not bundle[field].strip():
            raise LocalWorkerProtocolError(f"Captured protocol field {field} must be non-empty text", code="invalid_instructions")
    sections = bundle.get("framework_sections")
    if not isinstance(sections, dict) or set(sections) != {"first", "second", "third"} or any(
        not isinstance(text, str) or not text.strip() for text in sections.values()
    ):
        raise LocalWorkerProtocolError("Captured framework sections are invalid", code="invalid_instructions")


def validate_runtime_settings(snapshot: Mapping[str, Any], manifest: Mapping[str, Any]) -> None:
    """Validate the allowlisted settings file and its binding to an input manifest."""
    assert_exact_keys(
        snapshot,
        ["schema_version", "settings_version_id", "settings_sha256", "execution_mode", "output_scope", "captured_settings"],
        context="runtime_settings",
    )
    if snapshot.get("schema_version") != 1:
        raise LocalWorkerProtocolError("Unsupported runtime settings schema", code="unsupported_schema_version")
    expected_values = {
        "settings_version_id": manifest.get("settings_snapshot_id"),
        "settings_sha256": manifest.get("settings_sha256"),
        "execution_mode": manifest.get("execution_mode"),
        "output_scope": manifest.get("output_scope"),
    }
    for field, expected in expected_values.items():
        if snapshot.get(field) != expected:
            raise LocalWorkerProtocolError(f"Runtime settings {field} does not match the manifest", code="settings_snapshot_mismatch")

    captured = snapshot.get("captured_settings")
    if not isinstance(captured, dict):
        raise LocalWorkerProtocolError("captured_settings must be an object", code="invalid_settings")
    assert_exact_keys(captured, ["limits", "qualification", "providers", "templates"], context="captured_settings")
    group_keys = {
        "limits": {"max_pdf_bytes", "max_pdf_pages", "max_ocr_pages", "technical_retries", "retry_delay_seconds", "prompt_revisions", "scene_repairs", "coding_attempts", "review_dormancy_days"},
        "qualification": {"prompt", "scene"},
        "providers": {"prompt", "feedback", "coding_order"},
        "templates": {"feedback", "scene"},
    }
    nested_keys = {
        "qualification": {"min_score", "min_confidence"},
        "providers": {"id", "model", "credential_ref"},
        "templates": {"id", "checksum"},
    }
    for group, allowed in group_keys.items():
        value = captured[group]
        if not isinstance(value, dict) or set(value) - allowed:
            raise LocalWorkerProtocolError(f"Invalid portable {group} settings", code="invalid_settings")
        for key, nested in value.items():
            if group == "providers" and key == "coding_order":
                if not isinstance(nested, list):
                    raise LocalWorkerProtocolError("Invalid providers.coding_order", code="invalid_settings")
                for item in nested:
                    # Published settings use ordered provider descriptors;
                    # older captures can still contain provider identifiers.
                    if isinstance(item, str):
                        continue
                    if (not isinstance(item, dict) or set(item) - nested_keys["providers"]
                            or any(not isinstance(field, str) for field in item.values())):
                        raise LocalWorkerProtocolError("Invalid providers.coding_order", code="invalid_settings")
                continue
            if group == "limits":
                if isinstance(nested, bool) or not isinstance(nested, int) or nested < 0:
                    raise LocalWorkerProtocolError("Invalid numeric runtime limit", code="invalid_settings")
                continue
            if not isinstance(nested, dict) or set(nested) - nested_keys[group]:
                raise LocalWorkerProtocolError(f"Invalid {group}.{key} settings", code="invalid_settings")
            if group == "templates" and "checksum" in nested:
                assert_sha256(nested["checksum"], f"templates.{key}.checksum")
            if group == "providers" and any(not isinstance(item, str) for item in nested.values()):
                raise LocalWorkerProtocolError(f"Invalid {group}.{key} settings", code="invalid_settings")


def validate_authoring_result(result: Mapping[str, Any]) -> None:
    assert_exact_keys(
        result,
        [
            "schema_version",
            "kind",
            "result_id",
            "task_id",
            "job_id",
            "run_id",
            "assignment_id",
            "assignment_epoch",
            "input_manifest_sha256",
            "protocol_version",
            "settings_sha256",
            "preparation_sha256",
            "artifact_references",
            "lessons",
            "archive_inventory",
            "metadata",
        ],
        context="authoring_result",
    )

    if result.get("schema_version") != SCHEMA_VERSION:
        raise LocalWorkerProtocolError("Unsupported schema_version in authoring_result", code="unsupported_schema_version")

    if result.get("kind") != "local_worker_authoring_result":
        raise LocalWorkerProtocolError("Invalid result kind", code="invalid_kind")

    assert_uuid(result["result_id"], "result_id")
    assert_uuid(result["task_id"], "task_id")
    assert_uuid(result["job_id"], "job_id")
    assert_uuid(result["run_id"], "run_id")
    assert_uuid(result["assignment_id"], "assignment_id")

    if not isinstance(result["assignment_epoch"], int) or result["assignment_epoch"] < 1:
        raise LocalWorkerProtocolError("assignment_epoch must be integer >= 1", code="invalid_numeric_bounds")

    assert_sha256(result["input_manifest_sha256"], "input_manifest_sha256")
    assert_sha256(result["settings_sha256"], "settings_sha256")
    assert_sha256(result["preparation_sha256"], "preparation_sha256")

    lessons = result.get("lessons")
    if not isinstance(lessons, list) or not lessons:
        raise LocalWorkerProtocolError("Result 'lessons' must be a non-empty list", code="invalid_lessons")

    seen_tuples = set()
    lessons_by_item: Dict[str, List[int]] = {}

    for l_idx, lesson in enumerate(lessons):
        assert_exact_keys(
            lesson,
            [
                "item_id",
                "lesson_ordinal",
                "lesson_title",
                "raw_response_artifact_id",
                "raw_response_sha256",
                "scenes",
            ],
            context=f"lessons[{l_idx}]",
        )
        assert_uuid(lesson["item_id"], f"lessons[{l_idx}].item_id")
        assert_uuid(lesson["raw_response_artifact_id"], f"lessons[{l_idx}].raw_response_artifact_id")
        assert_sha256(lesson["raw_response_sha256"], f"lessons[{l_idx}].raw_response_sha256")

        if not isinstance(lesson["lesson_ordinal"], int) or lesson["lesson_ordinal"] < 0:
            raise LocalWorkerProtocolError("lesson_ordinal must be integer >= 0", code="invalid_numeric_bounds")

        scenes = lesson.get("scenes")
        if not isinstance(scenes, list) or not scenes:
            raise LocalWorkerProtocolError("Lesson must contain non-empty scenes list", code="empty_scenes")

        item_id = lesson["item_id"]
        lessons_by_item.setdefault(item_id, []).append(lesson["lesson_ordinal"])

        for s_idx, scene in enumerate(scenes):
            assert_exact_keys(
                scene,
                [
                    "scene_ordinal",
                    "scene_title",
                    "prompt_text",
                    "prompt_sha256",
                    "generation_context",
                ],
                context=f"lessons[{l_idx}].scenes[{s_idx}]",
            )
            if not isinstance(scene["scene_ordinal"], int) or scene["scene_ordinal"] < 0:
                raise LocalWorkerProtocolError("scene_ordinal must be integer >= 0", code="invalid_numeric_bounds")

            prompt_text = str(scene.get("prompt_text", ""))
            if not prompt_text.strip():
                raise LocalWorkerProtocolError("prompt_text must not be empty or whitespace only", code="empty_prompt")

            assert_sha256(scene["prompt_sha256"], f"lessons[{l_idx}].scenes[{s_idx}].prompt_sha256")
            computed = hashlib.sha256(prompt_text.encode("utf-8")).hexdigest()
            if computed != scene["prompt_sha256"]:
                raise LocalWorkerProtocolError(
                    f"prompt_sha256 mismatch: declared {scene['prompt_sha256']}, computed {computed}",
                    code="digest_mismatch",
                )

            tup = (item_id, lesson["lesson_ordinal"], scene["scene_ordinal"])
            if tup in seen_tuples:
                raise LocalWorkerProtocolError(f"Duplicate (item_id, lesson_ordinal, scene_ordinal) tuple: {tup}", code="duplicate_tuple")
            seen_tuples.add(tup)

    for item_id, ords in lessons_by_item.items():
        ords.sort()
        for expected_idx, actual_ord in enumerate(ords):
            if actual_ord != expected_idx:
                raise LocalWorkerProtocolError(
                    f"Non-contiguous lesson ordinals for item {item_id}",
                    code="non_contiguous_ordinals",
                )


def validate_scene_result(result: Mapping[str, Any]) -> None:
    assert_exact_keys(
        result,
        [
            "schema_version",
            "kind",
            "result_id",
            "task_id",
            "job_id",
            "run_id",
            "assignment_id",
            "assignment_epoch",
            "control_version",
            "input_manifest_sha256",
            "submissions",
        ],
        context="scene_result",
    )

    if result.get("schema_version") != SCHEMA_VERSION:
        raise LocalWorkerProtocolError("Unsupported schema_version in scene_result", code="unsupported_schema_version")

    if result.get("kind") != "local_worker_scene_result":
        raise LocalWorkerProtocolError("Invalid result kind", code="invalid_kind")

    assert_uuid(result["result_id"], "result_id")
    assert_uuid(result["task_id"], "task_id")
    assert_uuid(result["job_id"], "job_id")
    assert_uuid(result["run_id"], "run_id")
    assert_uuid(result["assignment_id"], "assignment_id")

    if not isinstance(result["assignment_epoch"], int) or result["assignment_epoch"] < 1:
        raise LocalWorkerProtocolError("assignment_epoch must be integer >= 1", code="invalid_numeric_bounds")
    if not isinstance(result["control_version"], int) or result["control_version"] < 1:
        raise LocalWorkerProtocolError("control_version must be integer >= 1", code="invalid_numeric_bounds")

    assert_sha256(result["input_manifest_sha256"], "input_manifest_sha256")

    submissions = result.get("submissions")
    if not isinstance(submissions, list) or not submissions:
        raise LocalWorkerProtocolError("Scene result submissions must be a non-empty list", code="empty_submissions")

    seen_submission_ids: set[str] = set()
    seen_scene_ids: set[str] = set()
    for idx, sub in enumerate(submissions):
        assert_exact_keys(
            sub,
            [
                "submission_id",
                "scene_id",
                "prompt_version_id",
                "prompt_sha256",
                "package_artifact_id",
                "package_sha256",
                "provenance",
            ],
            context=f"submissions[{idx}]",
        )
        assert_uuid(sub["submission_id"], f"submissions[{idx}].submission_id")
        assert_uuid(sub["scene_id"], f"submissions[{idx}].scene_id")
        submission_id = sub["submission_id"].lower()
        scene_id = sub["scene_id"].lower()
        if submission_id in seen_submission_ids:
            raise LocalWorkerProtocolError("Scene submission IDs must be unique within a result.", code="duplicate_submission_id")
        if scene_id in seen_scene_ids:
            raise LocalWorkerProtocolError("A scene may be submitted only once in a result batch.", code="duplicate_scene_submission")
        seen_submission_ids.add(submission_id)
        seen_scene_ids.add(scene_id)
        assert_uuid(sub["prompt_version_id"], f"submissions[{idx}].prompt_version_id")
        assert_sha256(sub["prompt_sha256"], f"submissions[{idx}].prompt_sha256")
        assert_uuid(sub["package_artifact_id"], f"submissions[{idx}].package_artifact_id")
        assert_sha256(sub["package_sha256"], f"submissions[{idx}].package_sha256")

        prov = sub["provenance"]
        assert_exact_keys(
            prov,
            [
                "mode",
                "operator_id",
                "device_id",
                "provider",
                "model",
                "effort",
                "measurement_status",
            ],
            context=f"submissions[{idx}].provenance",
        )
        if prov["mode"] not in ("manual_web", "approved_api"):
            raise LocalWorkerProtocolError(f"Invalid provenance mode '{prov['mode']}'", code="invalid_provenance_mode")
        if prov["measurement_status"] not in ("not_recorded", "measured", "estimated"):
            raise LocalWorkerProtocolError(
                f"Invalid measurement_status '{prov['measurement_status']}'",
                code="invalid_measurement_status",
            )
        if isinstance(prov["operator_id"], bool) or not isinstance(prov["operator_id"], int) or prov["operator_id"] < 1:
            raise LocalWorkerProtocolError("provenance.operator_id must be a positive user ID", code="invalid_operator_id")
        for field in ("provider", "model", "effort"):
            value = prov[field]
            if value is not None and (not isinstance(value, str) or not value.strip()):
                raise LocalWorkerProtocolError(
                    f"provenance.{field} must be a non-empty string or null",
                    code="invalid_reported_provenance",
                )
        assert_uuid(prov["device_id"], f"submissions[{idx}].provenance.device_id")


def validate_scene_package(package: Mapping[str, Any]) -> None:
    if package.get('profile') != 'manual_scene_files_v1':
        validate_static_three_file_package(package)
        return
    assert_exact_keys(package, ['schema_version', 'profile', 'files'], context='manual_scene_files_package')
    if package['schema_version'] != 1:
        raise LocalWorkerProtocolError('Unsupported scene package version', code='unsupported_schema_version')
    files = package['files']
    if not isinstance(files, list) or not 1 <= len(files) <= 3:
        raise LocalWorkerProtocolError('Manual package requires index.html and optional styles.css/script.js', code='invalid_package_files_count')
    seen = set()
    for row in files:
        assert_exact_keys(row, ['path', 'content'], context='manual_scene_file')
        if row['path'] not in {'index.html', 'styles.css', 'script.js'} or row['path'] in seen:
            raise LocalWorkerProtocolError('Invalid or duplicate scene file path', code='disallowed_package_file')
        seen.add(row['path'])
        content = row['content']
        if not isinstance(content, str) or '\0' in content:
            raise LocalWorkerProtocolError('Scene file must contain UTF-8 text without NUL', code='invalid_content')
        if len(content.encode('utf-8')) > 1048576:
            raise LocalWorkerProtocolError('Scene file exceeds transfer limit', code='file_size_exceeded', status_code=413)
        if row['path'] == 'index.html' and not content.strip():
            raise LocalWorkerProtocolError('Scene entrypoint is empty', code='empty_package_file')
    if 'index.html' not in seen:
        raise LocalWorkerProtocolError('Scene entrypoint is missing', code='missing_entrypoint')


def validate_static_three_file_package(package: Mapping[str, Any]) -> None:
    assert_exact_keys(package, ["schema_version", "files"], context="static_three_file_package")

    if package.get("schema_version") != SCHEMA_VERSION:
        raise LocalWorkerProtocolError(
            f"Unsupported package schema_version '{package.get('schema_version')}'",
            code="unsupported_schema_version",
        )

    files = package.get("files")
    if not isinstance(files, list) or len(files) != 3:
        raise LocalWorkerProtocolError(
            "Package 'files' must contain exactly 3 files (index.html, styles.css, script.js)",
            code="invalid_package_files_count",
        )

    expected_paths = {"index.html": False, "styles.css": False, "script.js": False}
    total_bytes = 0

    for idx, f in enumerate(files):
        assert_exact_keys(f, ["path", "content"], context=f"files[{idx}]")
        path = f["path"]
        if path not in expected_paths:
            raise LocalWorkerProtocolError(
                f"Unexpected file path '{path}' in static_three_file package",
                code="disallowed_package_file",
            )
        if expected_paths[path]:
            raise LocalWorkerProtocolError(
                f"Duplicate file path '{path}' in static_three_file package",
                code="duplicate_package_file",
            )
        expected_paths[path] = True

        content = f["content"]
        if not isinstance(content, str):
            raise LocalWorkerProtocolError(f"File '{path}' content must be string", code="invalid_content")

        content_bytes = content.encode("utf-8")
        file_len = len(content_bytes)

        if file_len == 0:
            raise LocalWorkerProtocolError(f"File '{path}' must not be empty", code="empty_package_file")

        if file_len > 1048576:  # 1 MiB
            raise LocalWorkerProtocolError(
                f"File '{path}' exceeds 1 MiB limit ({file_len} bytes)",
                code="file_size_exceeded",
                status_code=413,
            )

        if "\0" in content:
            raise LocalWorkerProtocolError(
                f"File '{path}' contains forbidden null bytes",
                code="null_byte_detected",
            )

        total_bytes += file_len

    if total_bytes > 4194304:  # 4 MiB
        raise LocalWorkerProtocolError(
            f"Total package decoded content exceeds 4 MiB limit ({total_bytes} bytes)",
            code="package_size_exceeded",
            status_code=413,
        )


def validate_claim_request(claim: Mapping[str, Any]) -> None:
    assert_exact_keys(claim, [
        "schema_version",
        "claim_id",
        "protocol_version",
        "capabilities",
        "available_slots",
    ], ["task_id"], context="claim_request")

    if claim["schema_version"] != SCHEMA_VERSION:
        raise LocalWorkerProtocolError("Unsupported schema_version", code="unsupported_schema_version")
    assert_uuid(claim["claim_id"], "claim_id")
    if not isinstance(claim["protocol_version"], int) or claim["protocol_version"] < 1:
        raise LocalWorkerProtocolError("protocol_version must be integer >= 1", code="invalid_protocol_version")
    if not isinstance(claim["capabilities"], list) or not claim["capabilities"]:
        raise LocalWorkerProtocolError("capabilities must be non-empty list", code="invalid_capabilities")
    for cap in claim["capabilities"]:
        if not isinstance(cap, str) or not cap.strip():
            raise LocalWorkerProtocolError("capabilities must be non-empty strings", code="invalid_capabilities")
    if not isinstance(claim["available_slots"], int) or claim["available_slots"] < 1:
        raise LocalWorkerProtocolError("available_slots must be integer >= 1", code="invalid_numeric_bounds")
    if "task_id" in claim:
        assert_uuid(claim["task_id"], "task_id")


def validate_heartbeat_request(hb: Mapping[str, Any]) -> None:
    assert_exact_keys(hb, [
        "schema_version",
        "observed_control_version",
    ], context="heartbeat_request")
    if hb["schema_version"] != SCHEMA_VERSION:
        raise LocalWorkerProtocolError("Unsupported schema_version", code="unsupported_schema_version")
    if not isinstance(hb["observed_control_version"], int) or hb["observed_control_version"] < 1:
        raise LocalWorkerProtocolError("observed_control_version must be integer >= 1", code="invalid_numeric_bounds")


def validate_release_request(rel: Mapping[str, Any]) -> None:
    assert_exact_keys(rel, [
        "schema_version",
        "reason",
    ], context="release_request")
    if rel["schema_version"] != SCHEMA_VERSION:
        raise LocalWorkerProtocolError("Unsupported schema_version", code="unsupported_schema_version")
    if not isinstance(rel["reason"], str) or not rel["reason"].strip():
        raise LocalWorkerProtocolError("reason must be non-empty string", code="invalid_reason")


def validate_upload_slot_request(slot: Mapping[str, Any]) -> None:
    assert_exact_keys(slot, [
        "schema_version",
        "upload_id",
        "artifact_id",
        "purpose",
        "media_type",
        "expected_bytes",
        "expected_sha256",
    ], context="upload_slot_request")
    if slot["schema_version"] != SCHEMA_VERSION:
        raise LocalWorkerProtocolError("Unsupported schema_version", code="unsupported_schema_version")
    assert_uuid(slot["upload_id"], "upload_id")
    assert_uuid(slot["artifact_id"], "artifact_id")
    if not isinstance(slot["purpose"], str) or not slot["purpose"].strip():
        raise LocalWorkerProtocolError("purpose must be non-empty string", code="invalid_purpose")
    if not isinstance(slot["media_type"], str) or not slot["media_type"].strip():
        raise LocalWorkerProtocolError("media_type must be non-empty string", code="invalid_media_type")
    if not isinstance(slot["expected_bytes"], int) or slot["expected_bytes"] <= 0:
        raise LocalWorkerProtocolError("expected_bytes must be integer > 0", code="invalid_numeric_bounds")
    assert_sha256(slot["expected_sha256"], "expected_sha256")


def validate_effect_reserve_request(req: Mapping[str, Any]) -> None:
    assert_exact_keys(req, [
        "schema_version",
        "effect_id",
        "logical_effect_key",
        "operation_kind",
        "request_digest",
    ], ["pdf_ordinal", "lesson_ordinal", "scene_ordinal"], context="effect_reserve_request")
    if req["schema_version"] != SCHEMA_VERSION:
        raise LocalWorkerProtocolError("Unsupported schema_version", code="unsupported_schema_version")
    assert_uuid(req["effect_id"], "effect_id")
    if not isinstance(req["logical_effect_key"], str) or not req["logical_effect_key"].strip():
        raise LocalWorkerProtocolError("logical_effect_key must be non-empty string", code="invalid_key")
    if not isinstance(req["operation_kind"], str) or not req["operation_kind"].strip():
        raise LocalWorkerProtocolError("operation_kind must be non-empty string", code="invalid_operation")
    assert_sha256(req["request_digest"], "request_digest")


def validate_effect_dispatch_request(req: Mapping[str, Any]) -> None:
    assert_exact_keys(req, [
        "schema_version",
        "worker_process_id",
    ], context="effect_dispatch_request")
    if req["schema_version"] != SCHEMA_VERSION:
        raise LocalWorkerProtocolError("Unsupported schema_version", code="unsupported_schema_version")
    if not isinstance(req["worker_process_id"], str) or not req["worker_process_id"].strip():
        raise LocalWorkerProtocolError("worker_process_id must be non-empty string", code="invalid_process_id")


def validate_effect_outcome_request(req: Mapping[str, Any]) -> None:
    assert_exact_keys(req, [
        "schema_version",
        "outcome_ref",
        "status",
    ], ["evidence_artifact_id", "evidence_sha256", "metadata"], context="effect_outcome_request")
    if req["schema_version"] != SCHEMA_VERSION:
        raise LocalWorkerProtocolError("Unsupported schema_version", code="unsupported_schema_version")
    if not isinstance(req["outcome_ref"], str) or not req["outcome_ref"].strip():
        raise LocalWorkerProtocolError("outcome_ref must be non-empty string", code="invalid_ref")
    valid_statuses = {"succeeded", "failed_no_effect", "unknown", "authentication_required", "rate_limited"}
    if req["status"] not in valid_statuses:
        raise LocalWorkerProtocolError(f"status must be one of {valid_statuses}", code="invalid_status")
    if "evidence_artifact_id" in req:
        assert_uuid(req["evidence_artifact_id"], "evidence_artifact_id")
    if "evidence_sha256" in req:
        assert_sha256(req["evidence_sha256"], "evidence_sha256")
