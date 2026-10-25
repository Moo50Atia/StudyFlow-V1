"""Test strict protocol parsing, bounds, hashes and duplicate key rejection."""

import hashlib
import pytest
from v3_engine.local_worker.protocol import (
    LocalWorkerProtocolError,
    strict_json_decode,
    validate_authoring_result,
    validate_input_manifest,
    validate_scene_result,
    validate_static_three_file_package,
    validate_runtime_settings,
)


def test_strict_json_decode_duplicate_keys():
    json_text = '{"a": 1, "b": 2, "a": 3}'
    with pytest.raises(LocalWorkerProtocolError, match="Duplicate key 'a' detected"):
        strict_json_decode(json_text)


def test_strict_json_decode_clean_payload():
    json_text = '{"a": 1, "nested": {"b": 2, "c": [3, 4]}}'
    data = strict_json_decode(json_text)
    assert data["a"] == 1
    assert data["nested"]["b"] == 2
    assert data["nested"]["c"] == [3, 4]


def test_strict_json_decode_bounds_exceeded():
    json_text = '{"data": "' + ("x" * 500) + '"}'
    with pytest.raises(LocalWorkerProtocolError, match="exceeds maximum allowed size"):
        strict_json_decode(json_text, max_size=200)


def test_strict_json_decode_invalid_utf8():
    bad_bytes = b'{"bad": "\xc3\x28"}'
    with pytest.raises(LocalWorkerProtocolError, match="invalid UTF-8"):
        strict_json_decode(bad_bytes)


def test_runtime_settings_match_manifest_and_reject_secret_fields():
    manifest = {
        "settings_snapshot_id": "11111111-1111-1111-1111-111111111111",
        "settings_sha256": "a" * 64,
        "execution_mode": "local_worker_v1",
        "output_scope": "prompts_only",
    }
    snapshot = {
        "schema_version": 1,
        "settings_version_id": manifest["settings_snapshot_id"],
        "settings_sha256": manifest["settings_sha256"],
        "execution_mode": manifest["execution_mode"],
        "output_scope": manifest["output_scope"],
        "captured_settings": {"limits": {}, "qualification": {}, "providers": {}, "templates": {}},
    }
    validate_runtime_settings(snapshot, manifest)
    snapshot["captured_settings"]["providers"]["coding_order"] = [
        {"id": "gemini_api", "model": "gemini-2.5-pro", "credential_ref": "GEMINI_API_KEY"},
        {"id": "deepseek_api", "model": "deepseek-coder", "credential_ref": "DEEPSEEK_API_KEY"},
    ]
    validate_runtime_settings(snapshot, manifest)
    snapshot["captured_settings"]["providers"]["coding_order"][0]["api_key"] = "forbidden"
    with pytest.raises(LocalWorkerProtocolError, match="Invalid providers.coding_order"):
        validate_runtime_settings(snapshot, manifest)
    snapshot["captured_settings"]["providers"].pop("coding_order")
    snapshot["captured_settings"]["providers"]["prompt"] = {"api_key": "must-not-be-serialized"}
    with pytest.raises(LocalWorkerProtocolError, match="Invalid providers.prompt settings"):
        validate_runtime_settings(snapshot, manifest)


def test_static_three_file_package_valid():
    pkg = {
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<h1>Hello</h1>"},
            {"path": "styles.css", "content": "body { color: red; }"},
            {"path": "script.js", "content": "console.log(1);"},
        ],
    }
    validate_static_three_file_package(pkg)


def test_static_three_file_package_rejects_null_byte():
    pkg = {
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<h1>Hello\0world</h1>"},
            {"path": "styles.css", "content": "body { color: red; }"},
            {"path": "script.js", "content": "console.log(1);"},
        ],
    }
    with pytest.raises(LocalWorkerProtocolError, match="forbidden null bytes"):
        validate_static_three_file_package(pkg)


def test_static_three_file_package_rejects_unsupported_version():
    pkg = {
        "schema_version": 2,
        "files": [
            {"path": "index.html", "content": "<h1>Hello</h1>"},
            {"path": "styles.css", "content": "body { color: red; }"},
            {"path": "script.js", "content": "console.log(1);"},
        ],
    }
    with pytest.raises(LocalWorkerProtocolError, match="Unsupported package schema_version '2'"):
        validate_static_three_file_package(pkg)


def test_scene_result_requires_explicit_numeric_operator_provenance():
    result = {
        "schema_version": 1,
        "kind": "local_worker_scene_result",
        "result_id": "11111111-1111-1111-1111-111111111111",
        "task_id": "22222222-2222-2222-2222-222222222222",
        "job_id": "33333333-3333-3333-3333-333333333333",
        "run_id": "44444444-4444-4444-4444-444444444444",
        "assignment_id": "55555555-5555-5555-5555-555555555555",
        "assignment_epoch": 1,
        "control_version": 1,
        "input_manifest_sha256": "a" * 64,
        "submissions": [{
            "submission_id": "66666666-6666-6666-6666-666666666666",
            "scene_id": "77777777-7777-7777-7777-777777777777",
            "prompt_version_id": "88888888-8888-8888-8888-888888888888",
            "prompt_sha256": "b" * 64,
            "package_artifact_id": "99999999-9999-9999-9999-999999999999",
            "package_sha256": "c" * 64,
            "provenance": {
                "mode": "manual_web",
                "operator_id": 42,
                "device_id": "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
                "provider": None,
                "model": None,
                "effort": None,
                "measurement_status": "not_recorded",
            },
        }],
    }
    validate_scene_result(result)

    duplicated_scene = dict(result)
    duplicated_scene["submissions"] = [
        dict(result["submissions"][0]),
        {**result["submissions"][0], "submission_id": "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb"},
    ]
    with pytest.raises(LocalWorkerProtocolError, match="submitted only once"):
        validate_scene_result(duplicated_scene)

    duplicated_id = dict(result)
    duplicated_id["submissions"] = [
        dict(result["submissions"][0]),
        {**result["submissions"][0], "scene_id": "cccccccc-cccc-cccc-cccc-cccccccccccc"},
    ]
    with pytest.raises(LocalWorkerProtocolError, match="submission IDs must be unique"):
        validate_scene_result(duplicated_id)

    result["submissions"][0]["provenance"]["operator_id"] = "operator-42"
    with pytest.raises(LocalWorkerProtocolError, match="positive user ID"):
        validate_scene_result(result)


def test_authoring_result_prompt_sha256_mismatch():
    prompt_text = "Physics mechanics lesson."
    result = {
        "schema_version": 1,
        "kind": "local_worker_authoring_result",
        "result_id": "11111111-1111-1111-1111-111111111111",
        "task_id": "22222222-2222-2222-2222-222222222222",
        "job_id": "33333333-3333-3333-3333-333333333333",
        "run_id": "44444444-4444-4444-4444-444444444444",
        "assignment_id": "55555555-5555-5555-5555-555555555555",
        "assignment_epoch": 1,
        "input_manifest_sha256": "a" * 64,
        "protocol_version": "notebooklm_v1",
        "settings_sha256": "b" * 64,
        "preparation_sha256": "c" * 64,
        "artifact_references": [],
        "lessons": [
            {
                "item_id": "66666666-6666-6666-6666-666666666666",
                "lesson_ordinal": 0,
                "lesson_title": "Lesson 0",
                "raw_response_artifact_id": "77777777-7777-7777-7777-777777777777",
                "raw_response_sha256": "d" * 64,
                "scenes": [
                    {
                        "scene_ordinal": 0,
                        "scene_title": "Scene 1",
                        "prompt_text": prompt_text,
                        "prompt_sha256": "e" * 64,  # wrong hash!
                        "generation_context": "Ctx",
                    }
                ],
            }
        ],
        "archive_inventory": {
            "archive_artifact_id": "88888888-8888-8888-8888-888888888888",
            "archive_sha256": "f" * 64,
            "item_count": 1,
        },
        "metadata": {"measured_usage": {"measurement_status": "not_recorded"}},
    }
    with pytest.raises(LocalWorkerProtocolError, match="prompt_sha256 mismatch"):
        validate_authoring_result(result)


def test_authoring_result_non_contiguous_ordinals():
    prompt1 = "Scene 1"
    prompt2 = "Scene 2"
    result = {
        "schema_version": 1,
        "kind": "local_worker_authoring_result",
        "result_id": "11111111-1111-1111-1111-111111111111",
        "task_id": "22222222-2222-2222-2222-222222222222",
        "job_id": "33333333-3333-3333-3333-333333333333",
        "run_id": "44444444-4444-4444-4444-444444444444",
        "assignment_id": "55555555-5555-5555-5555-555555555555",
        "assignment_epoch": 1,
        "input_manifest_sha256": "a" * 64,
        "protocol_version": "notebooklm_v1",
        "settings_sha256": "b" * 64,
        "preparation_sha256": "c" * 64,
        "artifact_references": [],
        "lessons": [
            {
                "item_id": "66666666-6666-6666-6666-666666666666",
                "lesson_ordinal": 0,
                "lesson_title": "Lesson 0",
                "raw_response_artifact_id": "77777777-7777-7777-7777-777777777777",
                "raw_response_sha256": "d" * 64,
                "scenes": [
                    {
                        "scene_ordinal": 0,
                        "scene_title": "Scene 1",
                        "prompt_text": prompt1,
                        "prompt_sha256": hashlib.sha256(prompt1.encode("utf-8")).hexdigest(),
                        "generation_context": "Ctx",
                    }
                ],
            },
            {
                "item_id": "66666666-6666-6666-6666-666666666666",
                "lesson_ordinal": 2,  # skipping 1!
                "lesson_title": "Lesson 2",
                "raw_response_artifact_id": "77777777-7777-7777-7777-777777777777",
                "raw_response_sha256": "d" * 64,
                "scenes": [
                    {
                        "scene_ordinal": 0,
                        "scene_title": "Scene 2",
                        "prompt_text": prompt2,
                        "prompt_sha256": hashlib.sha256(prompt2.encode("utf-8")).hexdigest(),
                        "generation_context": "Ctx",
                    }
                ],
            },
        ],
        "archive_inventory": {
            "archive_artifact_id": "88888888-8888-8888-8888-888888888888",
            "archive_sha256": "f" * 64,
            "item_count": 1,
        },
        "metadata": {"measured_usage": {"measurement_status": "not_recorded"}},
    }
    with pytest.raises(LocalWorkerProtocolError, match="Non-contiguous lesson ordinals"):
        validate_authoring_result(result)
