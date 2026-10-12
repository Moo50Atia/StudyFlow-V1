"""Tests for submission manifest capture, canonical digests, and input validation (Spec 018 / T002)."""

import hashlib
import json
import pytest
from pathlib import Path

from v3_engine.terminal_pipeline.manifest import (
    ManifestCaptureError,
    SubmissionManifest,
    canonical_json_dumps,
    capture_manifest,
    compute_digest,
)
from v3_engine.terminal_pipeline.contracts import SafeCode


@pytest.fixture
def sample_workspace(tmp_path: Path) -> tuple[Path, Path]:
    project_root = tmp_path / "project"
    project_root.mkdir()
    terminal_root = tmp_path / "terminal_root"
    terminal_root.mkdir()

    # Create dummy PDFs
    pdf_dir = project_root / "test_pdfs"
    pdf_dir.mkdir()
    (pdf_dir / "01_intro.pdf").write_bytes(b"%PDF-1.4 intro content")
    (pdf_dir / "02_chapter.pdf").write_bytes(b"%PDF-1.4 chapter content")

    # Create dummy framework
    (project_root / "steps.txt").write_text("Framework instructions", encoding="utf-8")

    return project_root, terminal_root


def test_canonical_json_dumps_determinism() -> None:
    data1 = {"b": 2, "a": 1, "c": [3, 4]}
    data2 = {"a": 1, "c": [3, 4], "b": 2}
    assert canonical_json_dumps(data1) == '{"a":1,"b":2,"c":[3,4]}'
    assert canonical_json_dumps(data1) == canonical_json_dumps(data2)


def test_manifest_capture_new_mode(sample_workspace: tuple[Path, Path]) -> None:
    proj_root, term_root = sample_workspace
    sub_id = "92407f71-f6c4-4c78-9938-0cfeef937cf2"

    raw_manifest = {
        "schema_version": 1,
        "submission_id": sub_id,
        "subjects": [
            {
                "subject_key": "mechanics",
                "mode": "new",
                "title": "Mechanics",
                "pdf_dir": "test_pdfs",
                "steps": "steps.txt",
                "account_check_notebook_id": "85b76e80-ea8a-4f86-9311-185634fcd2ed",
                "provider": "gemini",
                "model": "gemini-2.5-flash",
                "timeout_seconds": 300,
            }
        ],
    }

    manifest, path = capture_manifest(raw_manifest, term_root, proj_root)
    assert path.is_file()
    assert manifest.submission_id == sub_id
    assert manifest.schema_version == 1
    assert manifest.verify_digest() is True
    assert len(manifest.subjects) == 1

    subject = manifest.subjects[0]
    assert subject.subject_key == "mechanics"
    assert subject.mode == "new"
    assert subject.provider == "gemini"
    assert subject.model == "gemini-2.5-flash"
    assert subject.account_alias == "mo50atiapro"
    assert subject.account_check_notebook_id == "85b76e80-ea8a-4f86-9311-185634fcd2ed"
    assert subject.scene_generation_mode == "generate"

    # Inputs should include 2 PDFs (sorted Unicode order) and 1 steps file
    assert len(subject.inputs) == 3
    assert subject.inputs[0].kind == "pdf"
    assert "01_intro.pdf" in subject.inputs[0].original_path
    assert subject.inputs[1].kind == "pdf"
    assert "02_chapter.pdf" in subject.inputs[1].original_path
    assert subject.inputs[2].kind == "steps"

    # Verify byte snapshots exist and match on disk
    for inp in subject.inputs:
        assert inp.snapshot_ref.verify_on_disk(term_root) is True


def test_manifest_captures_prompt_export_mode_and_rejects_mode_change_on_reuse(
    sample_workspace: tuple[Path, Path], monkeypatch
) -> None:
    proj_root, term_root = sample_workspace
    raw = {
        "schema_version": 1, "submission_id": "0b803e50-0acd-45ef-9af2-7344a798f950",
        "subjects": [{"subject_key": "mechanics", "mode": "new", "pdf_dir": "test_pdfs",
                      "steps": "steps.txt", "account_check_notebook_id": "proof",
                      "provider": "gemini", "model": "gemini-2.5-flash"}],
    }
    monkeypatch.setenv("V3_GEMINI_THINKING_BUDGET", "12000")
    monkeypatch.setenv("V3_GEMINI_MAX_OUTPUT_TOKENS", "48000")
    captured, _ = capture_manifest(raw, term_root, proj_root, scene_generation_mode="export_prompts")
    assert captured.subjects[0].scene_generation_mode == "export_prompts"
    assert captured.subjects[0].gemini_thinking_budget == 12000
    assert captured.subjects[0].gemini_max_output_tokens == 48000
    assert SubmissionManifest.from_dict(captured.to_dict()).subjects[0].scene_generation_mode == "export_prompts"

    with pytest.raises(ManifestCaptureError, match="changed subject configuration"):
        capture_manifest(raw, term_root, proj_root, scene_generation_mode="generate")


def test_manifest_rejects_invalid_scene_generation_mode(sample_workspace: tuple[Path, Path]) -> None:
    proj_root, term_root = sample_workspace
    raw = {
        "schema_version": 1, "subjects": [{"subject_key": "mechanics", "mode": "new",
            "pdf_dir": "test_pdfs", "steps": "steps.txt", "account_check_notebook_id": "proof",
            "provider": "gemini", "model": "gemini-2.5-flash"}],
    }
    with pytest.raises(ManifestCaptureError, match="scene_generation_mode"):
        capture_manifest(raw, term_root, proj_root, scene_generation_mode="skip_everything")


def test_manifest_capture_reuses_immutable_subject_ids_on_identical_resubmission(sample_workspace: tuple[Path, Path]) -> None:
    proj_root, term_root = sample_workspace
    raw = {
        "schema_version": 1,
        "submission_id": "bd191f64-3dba-4568-9e5a-c7ca8ed8294b",
        "subjects": [{
            "subject_key": "same", "mode": "new", "pdf_dir": "test_pdfs", "steps": "steps.txt",
            "account_check_notebook_id": "proof-notebook", "provider": "gemini", "model": "gemini-2.5-flash",
        }],
    }
    first, first_path = capture_manifest(raw, term_root, proj_root)
    original_bytes = first_path.read_bytes()
    second, second_path = capture_manifest(raw, term_root, proj_root)
    assert second_path == first_path
    assert second.subjects[0].subject_run_id == first.subjects[0].subject_run_id
    assert second.manifest_sha256 == first.manifest_sha256
    assert second_path.read_bytes() == original_bytes


def test_manifest_capture_rejects_changed_source_on_same_submission_id(sample_workspace: tuple[Path, Path]) -> None:
    proj_root, term_root = sample_workspace
    raw = {
        "schema_version": 1,
        "submission_id": "eb8f3e88-8a9a-48f2-9c4b-a08ca5698092",
        "subjects": [{
            "subject_key": "same", "mode": "new", "pdf_dir": "test_pdfs", "steps": "steps.txt",
            "account_check_notebook_id": "proof-notebook", "provider": "gemini", "model": "gemini-2.5-flash",
        }],
    }
    capture_manifest(raw, term_root, proj_root)
    (proj_root / "test_pdfs" / "01_intro.pdf").write_bytes(b"changed")
    with pytest.raises(ManifestCaptureError, match="Captured PDF bytes changed"):
        capture_manifest(raw, term_root, proj_root)


def test_manifest_duplicate_pdf_hash_rejected(sample_workspace: tuple[Path, Path]) -> None:
    proj_root, term_root = sample_workspace

    # Write identical content to two PDFs
    dup_dir = proj_root / "dup_pdfs"
    dup_dir.mkdir()
    (dup_dir / "a.pdf").write_bytes(b"%PDF-1.4 identical")
    (dup_dir / "b.pdf").write_bytes(b"%PDF-1.4 identical")

    raw = {
        "schema_version": 1,
        "subjects": [
            {
                "subject_key": "dup_test",
                "mode": "new",
                "pdf_dir": "dup_pdfs",
                "account_check_notebook_id": "85b76e80-ea8a-4f86-9311-185634fcd2ed",
                "provider": "gemini",
                "model": "gemini-2.5-flash",
            }
        ],
    }

    with pytest.raises(ManifestCaptureError, match="Duplicate PDF content hash"):
        capture_manifest(raw, term_root, proj_root)


def test_manifest_capture_saved_mode(sample_workspace: tuple[Path, Path]) -> None:
    proj_root, term_root = sample_workspace

    # Create dummy handoff file
    handoff_file = proj_root / "lesson-0001.json"
    handoff_data = {
        "schema_version": 1,
        "export_kind": "notebooklm_multi_pdf_lesson_raw_handoff",
        "lesson_ordinal": 1,
        "scenes": [{"scene_ordinal": 1, "prompt": "Draw a vector"}],
    }
    handoff_file.write_text(json.dumps(handoff_data), encoding="utf-8")

    raw = {
        "schema_version": 1,
        "subjects": [
            {
                "subject_key": "saved_subject",
                "mode": "saved",
                "handoff": "lesson-0001.json",
                "provider": "qwen",
                "model": "qwen-plus",
            }
        ],
    }

    manifest, path = capture_manifest(raw, term_root, proj_root)
    assert manifest.verify_digest() is True
    subject = manifest.subjects[0]
    assert subject.mode == "saved"
    assert subject.handoff_ref is not None
    assert subject.handoff_ref.verify_on_disk(term_root) is True


def test_manifest_saved_mode_forbids_new_mode_fields(sample_workspace: tuple[Path, Path]) -> None:
    proj_root, term_root = sample_workspace

    raw = {
        "schema_version": 1,
        "subjects": [
            {
                "subject_key": "invalid_saved",
                "mode": "saved",
                "handoff": "lesson-0001.json",
                "account_check_notebook_id": "85b76e80-ea8a-4f86-9311-185634fcd2ed",  # forbidden
                "provider": "gemini",
                "model": "gemini-2.5-flash",
            }
        ],
    }
    with pytest.raises(ManifestCaptureError, match="forbidden in saved mode"):
        capture_manifest(raw, term_root, proj_root)


def test_manifest_drift_detection(sample_workspace: tuple[Path, Path]) -> None:
    proj_root, term_root = sample_workspace

    raw = {
        "schema_version": 1,
        "subjects": [
            {
                "subject_key": "drift_test",
                "mode": "new",
                "pdf_dir": "test_pdfs",
                "account_check_notebook_id": "85b76e80-ea8a-4f86-9311-185634fcd2ed",
                "provider": "gemini",
                "model": "gemini-2.5-flash",
            }
        ],
    }

    manifest, _ = capture_manifest(raw, term_root, proj_root)
    snapshot_pdf = manifest.subjects[0].inputs[0].snapshot_ref

    # Verify initial snapshot passes
    assert snapshot_pdf.verify_on_disk(term_root) is True

    # Mutate the snapshot file on disk to simulate drift/corruption
    target_disk_file = snapshot_pdf.resolve(term_root)
    target_disk_file.write_bytes(b"%PDF-1.4 modified bytes causing drift")

    # verify_on_disk must detect the corruption
    assert snapshot_pdf.verify_on_disk(term_root) is False
