"""Tests for handoff loading, normalization, and validation (Spec 017)."""

from __future__ import annotations

import json
from pathlib import Path
import pytest

from v3_engine.scene_generation.contracts import HandoffInventory, SceneInput
from v3_engine.scene_generation.handoff import InputError, load_handoff_inventory


def test_load_multi_pdf_raw_handoff(fixtures_dir: Path) -> None:
    path = fixtures_dir / "sample_multi_pdf_raw.json"
    inv = load_handoff_inventory(path)

    assert inv.notebook_id == "test-nb-001"
    assert inv.conversation_id == "test-conv-001"
    assert len(inv.scene_inputs) == 4
    assert len(inv.input_issues) == 0

    s1 = inv.scene_inputs[0]
    assert s1.scene_key == "test-nb-001:test-conv-001:W1:S1"
    assert s1.lesson_ordinal == 1
    assert s1.scene_ordinal == 1
    assert s1.lesson_title == "Mechanics Kinematics Overview"
    # Section title falls back to Scene 1 because section_title was absent in raw handoff
    assert s1.section_title == "Scene 1"
    assert s1.scene_type == "Comparison | Objects"
    assert s1.prompt is not None and "Visual split screen" in s1.prompt
    assert s1.prompt_sha256 is not None
    assert s1.input_error is None
    assert s1.source_index == 0
    assert s1.source_json_pointer == "/scenes/0"


def test_load_multi_pdf_parsed_handoff(fixtures_dir: Path) -> None:
    path = fixtures_dir / "sample_multi_pdf_parsed.json"
    inv = load_handoff_inventory(path)

    assert inv.notebook_id == "test-nb-001"
    assert inv.conversation_id == "test-conv-001"
    assert len(inv.scene_inputs) == 2

    s1 = inv.scene_inputs[0]
    assert s1.scene_key == "test-nb-001:test-conv-001:W2:S1"
    assert s1.lesson_ordinal == 2
    assert s1.scene_ordinal == 1
    assert s1.lesson_title == "Newton Laws of Motion"
    assert s1.section_title == "First Law Inertia"
    assert s1.scene_type == "Static Anatomy"


def test_load_archive_handoff(fixtures_dir: Path) -> None:
    path = fixtures_dir / "sample_archive.json"
    inv = load_handoff_inventory(path)

    assert inv.notebook_id == "test-nb-002"
    assert inv.conversation_id == "test-conv-002"
    assert inv.expected_work_count == 2
    # Lesson 1 has 2 scenes, Lesson 2 has 1 scene -> 3 total
    assert len(inv.scene_inputs) == 3
    assert [s.scene_key for s in inv.scene_inputs] == [
        "test-nb-002:test-conv-002:W1:S1",
        "test-nb-002:test-conv-002:W1:S2",
        "test-nb-002:test-conv-002:W2:S1",
    ]
    assert inv.scene_inputs[0].source_json_pointer == "/lessons/0/scenes/0"
    assert inv.scene_inputs[2].source_json_pointer == "/lessons/1/scenes/0"


def test_duplicate_archive_lesson_identity_is_rejected_before_scene_collection(tmp_path: Path, fixtures_dir: Path) -> None:
    archive = json.loads((fixtures_dir / "sample_archive.json").read_text())
    duplicate = json.loads(json.dumps(archive["lessons"][0]))
    duplicate["scenes"][0]["scene_ordinal"] = 99
    archive["lessons"].append(duplicate)
    path = tmp_path / "duplicate.json"
    path.write_text(json.dumps(archive), encoding="utf-8")
    with pytest.raises(InputError, match="Duplicate lesson ordinal"):
        load_handoff_inventory(path)


@pytest.mark.parametrize("bad_ordinals", [["2", 1], [None], [True]])
def test_malformed_lesson_ordinals_return_input_error_before_sorting(tmp_path: Path, bad_ordinals: list[object]) -> None:
    doc = {"schema_version": 1, "kind": "notebooklm_lesson_handoff", "notebook_id": "n", "conversation_id": "c", "lessons": [
        {"lesson_ordinal": ordinal, "scenes": []} for ordinal in bad_ordinals
    ]}
    path = tmp_path / "bad.json"
    path.write_text(json.dumps(doc), encoding="utf-8")
    with pytest.raises(InputError):
        load_handoff_inventory(path)


def test_load_directory_sorted(tmp_path: Path, fixtures_dir: Path) -> None:
    # Copy parsed into lesson-002.json and raw into lesson-001.json
    raw_data = json.loads((fixtures_dir / "sample_multi_pdf_raw.json").read_text())
    parsed_data = json.loads((fixtures_dir / "sample_multi_pdf_parsed.json").read_text())

    (tmp_path / "lesson-002.json").write_text(json.dumps(parsed_data))
    (tmp_path / "lesson-001.json").write_text(json.dumps(raw_data))

    inv = load_handoff_inventory(tmp_path)
    assert len(inv.input_snapshots) == 2
    assert inv.input_snapshots[0].relative_name == "lesson-001.json"
    assert inv.input_snapshots[1].relative_name == "lesson-002.json"
    # Scenes ordered W1:S1..S4 then W2:S1..S2
    ordinals = [(s.lesson_ordinal, s.scene_ordinal) for s in inv.scene_inputs]
    assert ordinals == [(1, 1), (1, 2), (1, 3), (1, 4), (2, 1), (2, 2)]


def test_empty_scenes_records_issue(tmp_path: Path, fixtures_dir: Path) -> None:
    raw_data = json.loads((fixtures_dir / "sample_multi_pdf_raw.json").read_text())
    empty_data = json.loads((fixtures_dir / "sample_empty_scenes.json").read_text())
    (tmp_path / "lesson-001.json").write_text(json.dumps(raw_data))
    (tmp_path / "lesson-003.json").write_text(json.dumps(empty_data))

    inv = load_handoff_inventory(tmp_path)
    assert len(inv.scene_inputs) == 4
    empty_issues = [issue for issue in inv.input_issues if issue.code == "input_lesson_empty"]
    assert len(empty_issues) == 1
    assert empty_issues[0].lesson_ordinal == 3


def test_defective_prompt_is_item_failure_and_leaves_other_scenes(fixtures_dir: Path) -> None:
    path = fixtures_dir / "sample_missing_prompt.json"
    inv = load_handoff_inventory(path)
    assert len(inv.scene_inputs) == 2

    valid_scene = inv.scene_inputs[0]
    assert valid_scene.prompt is not None
    assert valid_scene.input_error is None

    bad_scene = inv.scene_inputs[1]
    assert bad_scene.prompt is None
    assert bad_scene.prompt_sha256 is None
    assert bad_scene.input_error == "input_prompt_missing"


def test_zero_usable_prompts_raises_input_error(tmp_path: Path) -> None:
    bad_doc = {
        "schema_version": 1,
        "kind": "notebooklm_multi_pdf_lesson_handoff",
        "notebook_id": "nb-1",
        "conversation_id": "conv-1",
        "lesson_ordinal": 1,
        "mapped_title": "Only Bad Prompts",
        "scenes": [
            {"scene_ordinal": 1, "generated_prompt": ""},
            {"scene_ordinal": 2, "generated_prompt": "   "},
        ],
    }
    f = tmp_path / "lesson-001.json"
    f.write_text(json.dumps(bad_doc))
    with pytest.raises(InputError, match="No usable prompts"):
        load_handoff_inventory(f)


def test_duplicate_scene_key_raises_input_error(tmp_path: Path) -> None:
    dup_doc = {
        "schema_version": 1,
        "kind": "notebooklm_multi_pdf_lesson_handoff",
        "notebook_id": "nb-1",
        "conversation_id": "conv-1",
        "lesson_ordinal": 1,
        "mapped_title": "Duplicate Scene",
        "scenes": [
            {"scene_ordinal": 1, "generated_prompt": "Prompt 1"},
            {"scene_ordinal": 1, "generated_prompt": "Prompt 2"},
        ],
    }
    f = tmp_path / "lesson-001.json"
    f.write_text(json.dumps(dup_doc))
    with pytest.raises(InputError, match="Duplicate scene identity"):
        load_handoff_inventory(f)


def test_conflicting_notebook_id_raises_input_error(tmp_path: Path, fixtures_dir: Path) -> None:
    # Put two files with different notebook_ids in the same folder
    doc1 = json.loads((fixtures_dir / "sample_multi_pdf_raw.json").read_text())
    doc2 = json.loads((fixtures_dir / "sample_multi_pdf_raw.json").read_text())
    doc2["notebook_id"] = "different-notebook-id"
    doc2["lesson_ordinal"] = 2

    (tmp_path / "lesson-001.json").write_text(json.dumps(doc1))
    (tmp_path / "lesson-002.json").write_text(json.dumps(doc2))

    with pytest.raises(InputError, match="notebook_id"):
        load_handoff_inventory(tmp_path)


def test_expected_work_count_gap_records_issue(tmp_path: Path) -> None:
    # Lessons 1 and 3 provided, expected_work_count = 3 -> lesson 2 is missing
    doc1 = {
        "schema_version": 1,
        "kind": "notebooklm_multi_pdf_lesson_handoff",
        "notebook_id": "nb-1",
        "conversation_id": "conv-1",
        "lesson_ordinal": 1,
        "expected_work_count": 3,
        "scenes": [{"scene_ordinal": 1, "generated_prompt": "Scene 1.1"}],
    }
    doc3 = {
        "schema_version": 1,
        "kind": "notebooklm_multi_pdf_lesson_handoff",
        "notebook_id": "nb-1",
        "conversation_id": "conv-1",
        "lesson_ordinal": 3,
        "expected_work_count": 3,
        "scenes": [{"scene_ordinal": 1, "generated_prompt": "Scene 3.1"}],
    }
    (tmp_path / "lesson-001.json").write_text(json.dumps(doc1))
    (tmp_path / "lesson-003.json").write_text(json.dumps(doc3))

    inv = load_handoff_inventory(tmp_path)
    assert len(inv.input_issues) == 1
    assert inv.input_issues[0].code == "input_lesson_missing"
    assert inv.input_issues[0].lesson_ordinal == 2


def test_contradictory_expected_work_count_raises_input_error(tmp_path: Path) -> None:
    doc1 = {
        "schema_version": 1,
        "kind": "notebooklm_multi_pdf_lesson_handoff",
        "notebook_id": "nb-1",
        "conversation_id": "conv-1",
        "lesson_ordinal": 1,
        "expected_work_count": 3,
        "scenes": [{"scene_ordinal": 1, "generated_prompt": "Scene 1.1"}],
    }
    doc2 = {
        "schema_version": 1,
        "kind": "notebooklm_multi_pdf_lesson_handoff",
        "notebook_id": "nb-1",
        "conversation_id": "conv-1",
        "lesson_ordinal": 2,
        "expected_work_count": 4,  # Contradicts 3!
        "scenes": [{"scene_ordinal": 1, "generated_prompt": "Scene 2.1"}],
    }
    (tmp_path / "lesson-001.json").write_text(json.dumps(doc1))
    (tmp_path / "lesson-002.json").write_text(json.dumps(doc2))

    with pytest.raises(InputError, match="expected_work_count"):
        load_handoff_inventory(tmp_path)


def test_symlink_input_rejected(tmp_path: Path, fixtures_dir: Path) -> None:
    target = fixtures_dir / "sample_multi_pdf_raw.json"
    link = tmp_path / "lesson-001.json"
    link.symlink_to(target)

    with pytest.raises(InputError, match="[Ss]ymbolic-link"):
        load_handoff_inventory(link)
