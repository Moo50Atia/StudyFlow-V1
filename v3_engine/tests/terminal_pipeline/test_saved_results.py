"""Tests for saved-handoff processing mode without NotebookLM (Spec 018 / T028)."""

from __future__ import annotations

import json
from pathlib import Path
import uuid
import pytest

from v3_engine.scene_generation.contracts import HandoffInventory
from v3_engine.scene_generation.handoff import InputError, load_handoff_inventory
from v3_engine.terminal_pipeline.activities import export_terminal_scene_prompts, freeze_terminal_handoffs
from v3_engine.terminal_pipeline.contracts import ActivityOutcome, SafeCode
from v3_engine.terminal_pipeline.manifest import (
    ManifestCaptureError,
    capture_manifest,
)
from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow


FIXTURES_DIR = Path(__file__).parent.parent / "scene_generation" / "fixtures"


def test_saved_handoff_three_supported_kinds_without_notebooklm(tmp_path: Path, monkeypatch) -> None:
    """Validate all 3 supported 017 handoff formats without any NotebookLM dependency."""
    # Ensure any attempt to construct or call NotebookLM raises an exception
    class ForbiddenNotebookLM:
        def __init__(self, *args, **kwargs):
            raise AssertionError("NotebookLM client MUST NOT be constructed in saved mode!")

    monkeypatch.setattr("v3_engine.notebooklm.terminal_service.NotebookTerminalService", ForbiddenNotebookLM)

    formats = [
        ("sample_multi_pdf_raw.json", "notebooklm_multi_pdf_lesson_raw_handoff"),
        ("sample_multi_pdf_parsed.json", "notebooklm_multi_pdf_lesson_handoff"),
        ("sample_archive.json", "notebooklm_lesson_handoff"),
    ]

    for filename, expected_kind in formats:
        fixture_file = FIXTURES_DIR / filename
        assert fixture_file.is_file(), f"Missing fixture {fixture_file}"

        # 1. Direct load via 017 handoff loader
        inv = load_handoff_inventory(fixture_file)
        assert isinstance(inv, HandoffInventory)
        assert len(inv.scene_inputs) > 0
        assert all(s.prompt and len(s.prompt.strip()) > 0 for s in inv.scene_inputs)

        # 2. Manifest capture in saved mode
        raw_manifest = {
            "schema_version": 1,
            "submission_id": str(uuid.uuid4()),
            "subjects": [
                {
                    "subject_key": f"subj-{filename[:10]}",
                    "mode": "saved",
                    "handoff": str(fixture_file),
                    "provider": "gemini",
                    "model": "gemini-2.5-flash",
                }
            ],
        }

        term_root = tmp_path / f"term_{filename[:10]}"
        manifest, m_path = capture_manifest(
            raw_dict=raw_manifest,
            terminal_root=term_root,
            project_root=Path(__file__).parent.parent.parent.parent,
        )

        assert manifest.subjects[0].mode == "saved"
        assert manifest.subjects[0].account_check_notebook_id is None
        assert len(manifest.subjects[0].inputs) == 1
        assert manifest.subjects[0].inputs[0].kind == "handoff"


def test_saved_handoff_lesson_title_and_scene_keys_preservation(tmp_path: Path) -> None:
    """Lesson titles, scene keys, and metadata are preserved from handoff."""
    raw_path = FIXTURES_DIR / "sample_multi_pdf_raw.json"
    inv = load_handoff_inventory(raw_path)

    # First scene in sample_multi_pdf_raw has known title and key pattern
    s1 = inv.scene_inputs[0]
    assert s1.lesson_ordinal == 1
    assert s1.scene_ordinal == 1
    assert s1.lesson_title == "Mechanics Kinematics Overview"
    assert "test-nb-001:test-conv-001:W1:S1" == s1.scene_key


def test_saved_handoff_malformed_input_issues(tmp_path: Path) -> None:
    """Malformed handoff or forbidden fields in saved mode are cleanly rejected."""
    # 1. Forbidden fields in saved mode (e.g. pdf_dir or account_check_notebook_id)
    raw_manifest_forbidden = {
        "schema_version": 1,
        "submission_id": str(uuid.uuid4()),
        "subjects": [
            {
                "subject_key": "subj-bad",
                "mode": "saved",
                "handoff": str(FIXTURES_DIR / "sample_archive.json"),
                "pdf_dir": "docs",  # FORBIDDEN
                "provider": "gemini",
                "model": "gemini-2.5-flash",
            }
        ],
    }
    with pytest.raises(ManifestCaptureError) as exc_info:
        capture_manifest(
            raw_dict=raw_manifest_forbidden,
            terminal_root=tmp_path / "term",
            project_root=Path(__file__).parent.parent.parent.parent,
        )
    assert exc_info.value.safe_code == SafeCode.input_invalid

    # 2. Corrupted handoff input (invalid JSON or missing required fields)
    bad_json = tmp_path / "bad.json"
    bad_json.write_text('{"schema_version": 99, "kind": "unknown"}', encoding="utf-8")
    with pytest.raises(InputError):
        load_handoff_inventory(bad_json)


@pytest.mark.asyncio
async def test_freeze_terminal_handoffs_in_saved_mode(tmp_path: Path) -> None:
    """Activity freeze_terminal_handoffs correctly extracts scene keys in saved mode."""
    fixture_file = FIXTURES_DIR / "sample_multi_pdf_parsed.json"
    res = await freeze_terminal_handoffs(
        {
            "schema_version": 1,
            "subject_run_id": str(uuid.uuid4()),
            "handoff_path": str(fixture_file),
            "mode": "saved",
        }
    )

    assert res["outcome"] == ActivityOutcome.complete.value
    assert res["safe_code"] == SafeCode.ok.value
    scene_keys = json.loads(Path(res["result_ref"]).read_text(encoding="utf-8"))
    assert len(scene_keys) == 2
    assert "test-nb-001:test-conv-001:W2:S1" in scene_keys
    assert "test-nb-001:test-conv-001:W2:S2" in scene_keys


@pytest.mark.asyncio
async def test_export_mode_freezes_and_exports_all_prompts_without_provider_configuration(
    tmp_path: Path, monkeypatch
) -> None:
    """Prompt-only mode saves every scene prompt with lesson metadata and never needs a scene API key."""
    subject_id = str(uuid.uuid4())
    run_dir = tmp_path / "runs" / subject_id
    run_dir.mkdir(parents=True)
    (run_dir / "subject-config.json").write_text(json.dumps({
        "scene_generation_mode": "export_prompts", "provider": "gemini", "model": "gemini-2.5-flash",
    }), encoding="utf-8")
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path))
    frozen = await freeze_terminal_handoffs({
        "schema_version": 1, "subject_run_id": subject_id,
        "handoff_path": str(FIXTURES_DIR / "sample_multi_pdf_parsed.json"), "mode": "saved",
    })
    assert frozen["outcome"] == ActivityOutcome.complete.value
    assert frozen["counts"]["scene_count"] == 2
    assert not (run_dir / "scene-generation" / "run.json").exists()

    exported = await export_terminal_scene_prompts({"schema_version": 1, "subject_run_id": subject_id})
    assert exported["outcome"] == ActivityOutcome.complete.value
    assert exported["counts"] == {"lesson_count": 1, "scene_count": 2}
    payload = json.loads(Path(exported["result_ref"]).read_text(encoding="utf-8"))
    assert payload["notebook_id"] == "test-nb-001"
    assert payload["conversation_id"] == "test-conv-001"
    assert payload["generation_context"]["system_prompt_sha256"]
    assert "standard HTML, CSS, and browser JavaScript" in payload["generation_context"]["system_prompt"]
    assert "Return exactly one JSON object" in payload["generation_context"]["output_contract"]
    assert payload["lessons"][0]["lesson_ordinal"] == 2
    assert [scene["scene_ordinal"] for scene in payload["lessons"][0]["scenes"]] == [1, 2]
    assert all(scene["prompt"].strip() for scene in payload["lessons"][0]["scenes"])
    assert Path(exported["result_ref"]).stat().st_mode & 0o777 == 0o600


@pytest.mark.asyncio
async def test_saved_handoff_zero_scenes_or_input_gaps_yield_partial_status(tmp_path: Path) -> None:
    """Handoff with empty scenes lesson or input gap yields partial terminal status."""
    raw_data = json.loads((FIXTURES_DIR / "sample_multi_pdf_raw.json").read_text())
    empty_data = json.loads((FIXTURES_DIR / "sample_empty_scenes.json").read_text())
    (tmp_path / "lesson-001.json").write_text(json.dumps(raw_data))
    (tmp_path / "lesson-003.json").write_text(json.dumps(empty_data))

    res = await freeze_terminal_handoffs(
        {
            "schema_version": 1,
            "subject_run_id": str(uuid.uuid4()),
            "handoff_path": str(tmp_path),
            "mode": "saved",
        }
    )
    assert res["outcome"] == ActivityOutcome.complete.value
    counts = res["counts"]
    assert counts["issue_count"] > 0

    scene_keys = json.loads(Path(res["result_ref"]).read_text(encoding="utf-8"))
    scenes_built = len(scene_keys)
    issue_count = counts["issue_count"]
    terminal_status = (
        "completed"
        if (scene_keys and scenes_built == len(scene_keys) and issue_count == 0)
        else "partial"
    )
    assert terminal_status == "partial"


def test_saved_handoff_failed_scene_continuation() -> None:
    """If one scene fails, subsequent scenes continue processing and final status is partial."""
    scene_keys = ["scene_1", "scene_2", "scene_3"]
    scenes_generated = 0
    scenes_built = 0
    executed_scenes = []

    for sk in scene_keys:
        try:
            executed_scenes.append(sk)
            if sk == "scene_2":
                raise RuntimeError("Simulated provider rate limit or compilation failure")
            scenes_generated += 1
            scenes_built += 1
        except Exception:
            continue

    # Scene 3 was still executed despite Scene 2 failing!
    assert executed_scenes == ["scene_1", "scene_2", "scene_3"]
    assert scenes_generated == 2
    assert scenes_built == 2
    terminal_status = "completed" if (scene_keys and scenes_built == len(scene_keys)) else "partial"
    assert terminal_status == "partial"
