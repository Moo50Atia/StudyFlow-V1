"""Tests for SceneArtifactStore, locking, and atomic persistence (Spec 017)."""

from __future__ import annotations

import json
import hashlib
from pathlib import Path
import pytest

from v3_engine.scene_generation.artifact_store import (
    LockBusyError,
    SceneArtifactStore,
)
from v3_engine.scene_generation.config import ConfigurationError, load_provider_config
from v3_engine.scene_generation.contracts import (
    HandoffInventory,
    InputSnapshot,
    SceneInput,
)


def create_sample_inventory() -> HandoffInventory:
    s1 = SceneInput(
        scene_key="test-nb:test-conv:W1:S1",
        notebook_id="test-nb",
        conversation_id="test-conv",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Lesson 1",
        section_title="Scene 1",
        scene_type="Comparison",
        prompt="Prompt 1 text",
        prompt_sha256="abc123sha",
        input_error=None,
        source_index=0,
        source_json_pointer="/scenes/0",
    )
    snap = InputSnapshot(
        source_index=0,
        original_path="/tmp/fake/lesson-001.json",
        relative_name="lesson-001.json",
        sha256=hashlib.sha256(b"{}").hexdigest(),
        bytes=2,
        snapshot_path="snapshot/inputs/0001.json",
    )
    return HandoffInventory(
        notebook_id="test-nb",
        conversation_id="test-conv",
        scene_inputs=[s1],
        input_issues=[],
        input_snapshots=[snap],
        expected_work_count=1,
    )


def test_artifact_store_flock_mutual_exclusion(tmp_path: Path) -> None:
    store1 = SceneArtifactStore(tmp_path)
    store1.acquire_lock()

    store2 = SceneArtifactStore(tmp_path)
    with pytest.raises(LockBusyError):
        store2.acquire_lock()

    store1.close()
    # Now store2 can acquire
    store2.acquire_lock()
    store2.close()


def test_initialize_run_creates_expected_artifacts(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()

    inv = create_sample_inventory()
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    template_text = "System template instructions"
    template_sha = "8845fbb5723dea4f52cf4f3acd25b39edf9305cfa0516759137c2bf0a79d0a0c"

    store.initialize_run(
        inventory=inv,
        config=cfg,
        template_text=template_text,
        template_sha=template_sha,
        input_raw_bytes=[b"{}"],
    )

    run_file = tmp_path / "run.json"
    ckpt_file = tmp_path / "checkpoint.json"
    tmpl_snap = tmp_path / "snapshot" / "template.txt"
    input_snap = tmp_path / "snapshot" / "inputs" / "0001.json"

    assert run_file.exists()
    assert ckpt_file.exists()
    assert tmpl_snap.exists()
    assert input_snap.exists()
    assert tmpl_snap.read_text(encoding="utf-8") == template_text
    assert input_snap.read_bytes() == b"{}"

    run_data = json.loads(run_file.read_text(encoding="utf-8"))
    assert run_data["schema_version"] == 1
    assert run_data["kind"] == "local_scene_generation_run"
    assert run_data["notebook_id"] == "test-nb"
    assert run_data["qualification"]["mode"] == "owner_waived_terminal_demo"
    assert run_data["qualification"]["jev_called"] is False

    ckpt_data = json.loads(ckpt_file.read_text(encoding="utf-8"))
    assert ckpt_data["status"] == "partial"
    assert len(ckpt_data["items"]) == 1
    assert ckpt_data["items"][0]["scene_key"] == "test-nb:test-conv:W1:S1"
    assert ckpt_data["items"][0]["status"] == "queued"
    assert ckpt_data["counts"]["queued"] == 1

    store.close()


def test_raw_receipt_persistence(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inv = create_sample_inventory()
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    store.initialize_run(inv, cfg, "tmpl", "8845", [b"{}"])

    scene = inv.scene_inputs[0]
    attempt = store.start_attempt(scene)
    assert attempt.ordinal == 1

    body_bytes = b"{\"response\": \"ok\"}"
    receipt_ref = store.save_raw_receipt(
        scene=scene,
        attempt_ordinal=1,
        body_bytes=body_bytes,
        http_status=200,
        complete=True,
        truncated=False,
    )

    assert "response-receipt.json" in receipt_ref
    receipt_path = tmp_path / receipt_ref
    assert receipt_path.exists()
    receipt_doc = json.loads(receipt_path.read_text(encoding="utf-8"))
    assert receipt_doc["http_status"] == 200
    assert receipt_doc["complete"] is True
    assert receipt_doc["body_bytes"] == len(body_bytes)

    body_path = receipt_path.parent / receipt_doc["body_path"]
    assert body_path.exists()
    assert body_path.read_bytes() == body_bytes

    store.close()


def test_run_identity_mismatch_rejected(tmp_path: Path) -> None:
    store1 = SceneArtifactStore(tmp_path)
    store1.acquire_lock()
    inv = create_sample_inventory()
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    store1.initialize_run(inv, cfg, "tmpl", "8845", [b"{}"])
    store1.close()

    # Try re-initializing with different model
    store2 = SceneArtifactStore(tmp_path)
    store2.acquire_lock()
    cfg2 = load_provider_config("gemini", model="gemini-2.5-pro", env={"GEMINI_API_KEY": "key"})
    with pytest.raises(ConfigurationError, match="Run identity mismatch"):
        store2.initialize_run(inv, cfg2, "tmpl", "8845", [b"{}"])
    store2.close()


def test_build_reservations_are_monotonic_across_failed_and_orphaned_builds(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inv = create_sample_inventory()
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "key"})
    store.initialize_run(inv, cfg, "tmpl", "8845", [b"{}"])
    scene = inv.scene_inputs[0]
    attempt = store.start_attempt(scene)

    first = store.reserve_build(scene, attempt.ordinal)
    assert first == 1
    store.finish_build(scene, attempt.ordinal, first, "failed")
    (store.get_attempt_dir(scene, attempt.ordinal) / "builds" / "build-0002").mkdir(parents=True)
    second = store.reserve_build(scene, attempt.ordinal)
    assert second == 3
    builds = store.load_checkpoint()["items"][0]["attempts"][0]["builds"]
    assert [b["ordinal"] for b in builds] == [1, 3]
    assert builds[0]["status"] == "failed"
    assert builds[1]["status"] == "reserved"
    store.close()
