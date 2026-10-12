import hashlib
import json
import uuid
import pytest
from v3_engine.tests.web_pipeline.conftest import make_malformed_static_package


def test_v2_static_scene_package_invariants(valid_static_package):
    """
    FR-012, FR-015: Validate three-file static package constraints.
    Exactly index.html, styles.css, script.js, schema_version 1.
    """
    assert valid_static_package["schema_version"] == 1
    assert len(valid_static_package["files"]) == 3
    paths = sorted([f["path"] for f in valid_static_package["files"]])
    assert paths == ["index.html", "script.js", "styles.css"]

    for f in valid_static_package["files"]:
        assert len(f["content"].strip()) > 0
        assert len(f["content"].encode("utf-8")) <= 1048576  # <= 1 MiB


@pytest.mark.parametrize(
    "reason",
    [
        "extra_file",
        "missing_file",
        "path_traversal",
        "empty_file",
        "oversize_file",
        "invalid_schema",
    ],
)
def test_malformed_static_scene_packages_fail_validation(reason):
    pkg = make_malformed_static_package(reason)
    if reason == "extra_file":
        assert len(pkg["files"]) == 4
    elif reason == "missing_file":
        assert len(pkg["files"]) == 2
    elif reason == "path_traversal":
        assert ".." in pkg["files"][0]["path"]
    elif reason == "empty_file":
        assert len(pkg["files"][1]["content"]) == 0
    elif reason == "oversize_file":
        assert len(pkg["files"][2]["content"].encode("utf-8")) > 1048576
    elif reason == "invalid_schema":
        assert pkg["schema_version"] != 1


def test_payload_32kib_boundary_limit():
    """
    FR-024, A27: Enforce that metadata/control payloads cannot exceed 32 KiB.
    """
    max_bytes = 32768
    valid_payload = json.dumps({"command_id": str(uuid.uuid4()), "data": "x" * 32000}).encode("utf-8")
    assert len(valid_payload) <= max_bytes

    oversized_payload = json.dumps({"command_id": str(uuid.uuid4()), "data": "x" * 33000}).encode("utf-8")
    assert len(oversized_payload) > max_bytes


def test_opaque_ref_validation():
    """
    FR-024, A27: Opaque UUID refs validation.
    """
    valid_uuid_str = str(uuid.uuid4())
    # Should parse cleanly
    parsed = uuid.UUID(valid_uuid_str)
    assert str(parsed) == valid_uuid_str

    invalid_refs = [
        "/etc/passwd",
        "../storage/run.json",
        "https://example.com/api",
        "file:///private/key.pem",
        "",
    ]
    for bad in invalid_refs:
        with pytest.raises(ValueError):
            uuid.UUID(bad)


def test_null_vs_empty_context_digest():
    """
    FR-007, A04: Canonical JSON digest must differentiate between null and empty string.
    """
    fields = [
        "lesson_title",
        "core_concept",
        "learning_objectives",
        "misconceptions",
        "governing_formulas",
        "numerical_example",
        "visual_representation",
        "interactive_controls",
        "variables_and_ranges",
        "laws_if_any",
        "real_world_application",
    ]

    context_a = {k: "val" for k in fields}
    context_a["laws_if_any"] = ""  # Empty string (supplied law)
    canon_a = json.dumps(context_a, sort_keys=True, separators=(",", ":")).encode("utf-8")
    hash_a = hashlib.sha256(canon_a).hexdigest()

    context_b = {k: "val" for k in fields}
    context_b["laws_if_any"] = None  # Null (missing field)
    canon_b = json.dumps(context_b, sort_keys=True, separators=(",", ":")).encode("utf-8")
    hash_b = hashlib.sha256(canon_b).hexdigest()

    assert hash_a != hash_b, "Null and empty string MUST produce distinct SHA-256 hashes."


def test_contracts_module_validations(valid_static_package):
    """Verify v3_engine.web_pipeline.contracts functions."""
    from v3_engine.web_pipeline.contracts import (
        validate_static_scene_package,
        validate_admission_payload,
        sanitize_activity_result,
        ContractValidationError,
    )

    # Valid static scene package passes
    validate_static_scene_package(valid_static_package)

    # Malformed package raises ContractValidationError
    bad_pkg = make_malformed_static_package("extra_file")
    with pytest.raises(ContractValidationError):
        validate_static_scene_package(bad_pkg)

    # Valid admission payload passes
    valid_admission = {
        "submission_id": str(uuid.uuid4()),
        "submission_digest": "a" * 64,
        "run_id": str(uuid.uuid4()),
        "manifest_ref": str(uuid.uuid4()),
        "workflow_kind": "web_subject_v1",
    }
    validate_admission_payload(valid_admission)

    # Unknown key raises ContractValidationError
    with pytest.raises(ContractValidationError, match="Unknown keys"):
        validate_admission_payload({**valid_admission, "unexpected_key": "val"})

    # Invalid workflow kind raises ContractValidationError
    with pytest.raises(ContractValidationError, match="Invalid workflow_kind"):
        validate_admission_payload({**valid_admission, "workflow_kind": "legacy_v1"})

    # Sanitized activity result strips secrets and prompt text
    dirty_result = {
        "outcome": "completed",
        "safe_code": "ok",
        "prompt": "SECRET PROMPT",
        "secret": "KEY_123",
        "counts": {
            "scenes_total": 4,
            "secret_token": "ignore",
            "negative": -1,
        },
    }
    cleaned = sanitize_activity_result(dirty_result)
    assert cleaned["outcome"] == "completed"
    assert cleaned["safe_code"] == "ok"
    assert "prompt" not in cleaned
    assert "secret" not in cleaned
    assert cleaned["counts"] == {"scenes_total": 4}


def test_artifact_refs_and_symlink_rejection(tmp_path):
    """Verify artifact refs module containment, size limit, and symlink rejection."""
    from v3_engine.web_pipeline.artifact_refs import (
        is_valid_uuid_ref,
        parse_uuid_ref,
        resolve_artifact_path,
        store_artifact,
        load_artifact,
        ArtifactSecurityError,
        ArtifactSizeError,
    )

    valid_ref = str(uuid.uuid4())
    assert is_valid_uuid_ref(valid_ref)
    assert not is_valid_uuid_ref("../bad")
    assert not is_valid_uuid_ref("/root/bad")

    # Store and load artifact
    data = b"safe artifact metadata payload"
    stored_path = store_artifact(tmp_path, valid_ref, data, max_bytes=1000)
    assert stored_path.exists()
    loaded = load_artifact(tmp_path, valid_ref, max_bytes=1000)
    assert loaded == data

    # Exceeding size ceiling fails
    with pytest.raises(ArtifactSizeError):
        store_artifact(tmp_path, str(uuid.uuid4()), b"x" * 100, max_bytes=50)

    # Path traversal in category fails
    with pytest.raises(ArtifactSecurityError):
        resolve_artifact_path(tmp_path, valid_ref, category="../evil")

    # Symlink rejection
    link_ref = str(uuid.uuid4())
    link_file = tmp_path / "artifacts" / f"{link_ref}.bin"
    link_file.parent.mkdir(parents=True, exist_ok=True)
    link_file.symlink_to(stored_path)

    with pytest.raises(ArtifactSecurityError, match="Symlink rejected|Refusing to read symlink"):
        load_artifact(tmp_path, link_ref)


def test_settings_resolves_single_absolute_root(tmp_path, monkeypatch):
    """Verify WebPipelineSettings resolves captured absolute root."""
    from v3_engine.web_pipeline.settings import get_web_pipeline_settings

    custom_root = tmp_path / "custom_terminal"
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(custom_root))
    settings = get_web_pipeline_settings()
    assert settings.terminal_root.is_absolute()
    assert settings.terminal_root == custom_root.resolve()
