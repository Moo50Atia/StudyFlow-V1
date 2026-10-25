"""Tests for opaque artifact references and manifest resolution (T070)."""

import json
import uuid
import pytest
from pathlib import Path

from v3_engine.web_pipeline.artifact_refs import (
    ArtifactError,
    ArtifactSecurityError,
    ArtifactSizeError,
    is_valid_uuid_ref,
    load_manifest,
    resolve_manifest_path,
    store_manifest,
)


def test_is_valid_uuid_ref():
    assert is_valid_uuid_ref(str(uuid.uuid4()))
    assert not is_valid_uuid_ref("not-a-uuid")
    assert not is_valid_uuid_ref("../traversal")
    assert not is_valid_uuid_ref("")


def test_prepared_manifest_supports_bounded_expansion_without_changing_legacy_limit(tmp_path):
    data = {"protocol": "notebooklm_per_pdf_web_v3", "items": [
        {"item_id": str(uuid.uuid4()), "pdf_ordinal": n, "pdf_lineage": {"original_filename": "x" * 200}}
        for n in range(1, 641)
    ]}
    ref = str(uuid.uuid4())
    store_manifest(tmp_path, ref, data)
    assert load_manifest(tmp_path, ref) == data
    with pytest.raises(ArtifactSizeError):
        store_manifest(tmp_path, str(uuid.uuid4()), {**data, "protocol": "notebooklm_per_pdf_web_v2"})
    with pytest.raises(ArtifactSizeError):
        store_manifest(tmp_path, str(uuid.uuid4()), {**data, "extra": "x" * 1048576})


def test_store_and_load_manifest(tmp_path: Path):
    manifest_ref = str(uuid.uuid4())
    manifest_data = {
        "run_id": str(uuid.uuid4()),
        "mode": "export_manual",
        "items": [{"item_id": str(uuid.uuid4()), "pdf_ordinal": 1}],
    }

    path = store_manifest(tmp_path, manifest_ref, manifest_data)
    assert path.exists()

    loaded = load_manifest(tmp_path, manifest_ref)
    assert loaded["run_id"] == manifest_data["run_id"]
    assert loaded["items"][0]["pdf_ordinal"] == 1


def test_load_manifest_missing_and_corrupt(tmp_path: Path):
    # Missing
    with pytest.raises(FileNotFoundError):
        load_manifest(tmp_path, str(uuid.uuid4()))

    # Corrupt
    bad_ref = str(uuid.uuid4())
    bad_path = resolve_manifest_path(tmp_path, bad_ref)
    bad_path.parent.mkdir(parents=True, exist_ok=True)
    bad_path.write_bytes(b"not-json-content")

    with pytest.raises(ArtifactError):
        load_manifest(tmp_path, bad_ref)


def test_manifest_size_and_symlink_protection(tmp_path: Path):
    # Oversize
    big_ref = str(uuid.uuid4())
    big_data = {"pad": "x" * 33000}
    with pytest.raises(ArtifactSizeError):
        store_manifest(tmp_path, big_ref, big_data)

    # Symlink protection
    sym_ref = str(uuid.uuid4())
    target_path = resolve_manifest_path(tmp_path, sym_ref)
    target_path.parent.mkdir(parents=True, exist_ok=True)
    
    real_target = tmp_path / "outside.json"
    real_target.write_text("{}", encoding="utf-8")
    target_path.symlink_to(real_target)

    with pytest.raises(ArtifactSecurityError):
        load_manifest(tmp_path, sym_ref)
