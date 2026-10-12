"""Tests for contracts, artifact references, and safe boundaries (Spec 018 / T002)."""

import hashlib
import json
import pytest
from pathlib import Path

from v3_engine.terminal_pipeline.contracts import (
    MAX_PAYLOAD_BYTES,
    ActivityOutcome,
    ArtifactRef,
    ControlAction,
    ControlState,
    SafeActivityInput,
    SafeActivityResult,
    SafeCode,
    SubjectLifecycle,
    TerminalPhase,
    TerminalStatus,
    validate_canonical_uuid,
    validate_sha256,
)


def test_canonical_uuid_validation() -> None:
    valid_uuid = "92407f71-f6c4-4c78-9938-0cfeef937cf2"
    assert validate_canonical_uuid(valid_uuid) == valid_uuid

    with pytest.raises(ValueError, match="canonical lowercase UUID"):
        validate_canonical_uuid("92407F71-F6C4-4C78-9938-0CFEEF937CF2")  # uppercase

    with pytest.raises(ValueError, match="canonical lowercase UUID"):
        validate_canonical_uuid("invalid-uuid")

    with pytest.raises(ValueError, match="canonical lowercase UUID"):
        validate_canonical_uuid("92407f71f6c44c7899380cfeef937cf2")  # missing hyphens


def test_sha256_validation() -> None:
    valid_hash = hashlib.sha256(b"hello").hexdigest()
    assert validate_sha256(valid_hash) == valid_hash

    with pytest.raises(ValueError, match="64-character lowercase hex"):
        validate_sha256(valid_hash.upper())

    with pytest.raises(ValueError, match="64-character lowercase hex"):
        validate_sha256("abc123")


def test_artifact_ref_validation_and_root_escape(tmp_path: Path) -> None:
    valid_hash = hashlib.sha256(b"content").hexdigest()

    # Valid relative ref
    ref = ArtifactRef(path="runs/sub-1/report.json", sha256=valid_hash, bytes=7)
    assert ref.path == "runs/sub-1/report.json"

    # Absolute path rejected
    with pytest.raises(ValueError, match="relative without leading slash"):
        ArtifactRef(path="/runs/sub-1/report.json", sha256=valid_hash, bytes=7)

    # Path traversal rejected
    with pytest.raises(ValueError, match=r"must not contain '\.\.'"):
        ArtifactRef(path="../outside.json", sha256=valid_hash, bytes=7)

    with pytest.raises(ValueError, match=r"must not contain '\.\.'"):
        ArtifactRef(path="runs/../../outside.json", sha256=valid_hash, bytes=7)

    # Negative bytes rejected
    with pytest.raises(ValueError, match="non-negative"):
        ArtifactRef(path="runs/sub-1/report.json", sha256=valid_hash, bytes=-1)

    # Disk resolution and verification
    root = tmp_path / "terminal_root"
    root.mkdir()
    target_file = root / "runs" / "sub-1" / "report.json"
    target_file.parent.mkdir(parents=True)
    target_file.write_bytes(b"content")

    assert ref.verify_on_disk(root) is True

    # Modified content fails verification
    target_file.write_bytes(b"altered")
    assert ref.verify_on_disk(root) is False


def test_safe_codes_enum_completeness() -> None:
    expected_codes = {
        "ok",
        "input_invalid",
        "input_changed",
        "ownership_conflict",
        "lock_busy",
        "authentication_required",
        "account_mismatch",
        "provider_rate_limit",
        "provider_unavailable",
        "delivery_uncertain",
        "history_conflict",
        "source_error",
        "source_timeout",
        "map_unreadable",
        "storage_unavailable",
        "receipt_invalid",
        "artifact_missing",
        "provider_request",
        "source_package_invalid",
        "build_failed",
        "build_cancelled",
        "config_drift",
        "configuration_invalid",
        "configuration_drift",
        "control_conflict",
        "control_pending",
        "recovery_busy",
        "queue_full",
        "scheduler_draining",
        "worker_unavailable",
        "temporal_unavailable",
        "supervisor_recovery_required",
    }
    actual_codes = {c.value for c in SafeCode}
    assert expected_codes == actual_codes


def test_safe_activity_payload_size_boundary() -> None:
    valid_hash = hashlib.sha256(b"dummy").hexdigest()
    run_ref = ArtifactRef(path="runs/test/run.json", sha256=valid_hash, bytes=5)
    subject_uuid = "92407f71-f6c4-4c78-9938-0cfeef937cf2"

    inp = SafeActivityInput(
        subject_run_id=subject_uuid,
        run_ref=run_ref,
        schema_version=1,
        owner_epoch=1,
        ordinal=1,
    )
    inp.assert_payload_size()

    # Verify that a payload exceeding 32KiB raises ValueError
    huge_path = "a" * (MAX_PAYLOAD_BYTES + 100)
    huge_ref = ArtifactRef(path=huge_path, sha256=valid_hash, bytes=100)
    huge_inp = SafeActivityInput(
        subject_run_id=subject_uuid,
        run_ref=huge_ref,
    )
    with pytest.raises(ValueError, match="exceeds 32KiB limit"):
        huge_inp.assert_payload_size()


def test_safe_activity_result_retry_after() -> None:
    res = SafeActivityResult(
        outcome=ActivityOutcome.blocked,
        safe_code=SafeCode.provider_rate_limit,
        retry_after_seconds=42.5,
    )
    d = res.to_dict()
    assert d["outcome"] == "blocked"
    assert d["safe_code"] == "provider_rate_limit"
    assert d["retry_after_seconds"] == 42.5
    res.assert_payload_size()
