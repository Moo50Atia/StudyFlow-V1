"""Tests for the temporary per-notebook JSON handoff to scene generation."""
from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
import json
from pathlib import Path

import pytest

from v3_engine.lessons.first_parser import ParsedFirstResponse, ParsedSection
from v3_engine.notebooklm.bundle_parser import (
    ParsedCombinedBundle,
    ParsedSecondArtifact,
    ParsedSecondSection,
    ParsedThirdArtifact,
    ParsedThirdSection,
)
from v3_engine.notebooklm.lesson_archive import LessonArchiveError, archive_lesson_bundle


def make_bundle() -> ParsedCombinedBundle:
    names = [
        "section_identity", "core_understanding", "visual_first_explanation",
        "visual_scene_script", "dynamic_view_blueprint", "visual_learning_outcome",
        "concept_check", "conceptual_summary", "colloquial_egyptian_explanation",
        "laws_if_any", "real_life_example",
    ]
    sections = []
    for ordinal, title in ((1, "Parallel Lines"), (2, "Perpendicular Lines")):
        fields = {name: f"{name} content for {title}" for name in names}
        fields["section_identity"] = title
        sections.append(ParsedSection(ordinal=ordinal, fields=fields))
    return ParsedCombinedBundle(
        work_ordinal=1,
        terminal_marker_ordinal=1,
        lecture_title="Lesson 1: Relationships Between Lines",
        first=ParsedFirstResponse(1, "Lesson 1: Relationships Between Lines", sections),
        second=ParsedSecondArtifact(
            "Lesson 1: Relationships Between Lines",
            [
                ParsedSecondSection(1, "Compare parallel slopes", {"scene_type": "Comparison"}),
                ParsedSecondSection(2, "Explore perpendicular slopes", {"scene_type": "Process"}),
            ],
        ),
        third=ParsedThirdArtifact(
            "Lesson 1: Relationships Between Lines",
            [
                ParsedThirdSection(1, "Comparison", "Comparison: Scene prompt for parallel lines."),
                ParsedThirdSection(2, "Process", "Process: Scene prompt for perpendicular lines."),
            ],
        ),
        raw_first="first raw",
        raw_second="second raw",
        raw_third="third raw",
    )


def archive(tmp_path: Path, bundle: ParsedCombinedBundle) -> Path:
    return archive_lesson_bundle(
        notebook_id="notebook-123",
        conversation_id="conversation-456",
        source_ids={"original_pdf": "pdf-source", "framework_steps": "steps-source"},
        lesson_ordinal=1,
        lesson_title="Lesson 1: Relationships Between Lines",
        expected_work_count=1,
        bundle=bundle,
        output_dir=tmp_path,
    )


def test_archives_each_scene_prompt_under_its_lesson_and_section(tmp_path):
    path = archive(tmp_path, make_bundle())

    document = json.loads(path.read_text(encoding="utf-8"))
    lesson = document["lessons"][0]
    assert document["status"] == "complete"
    assert lesson["lesson_ref"] == "notebook-123:conversation-456:W1"
    assert lesson["lesson_title"] == "Lesson 1: Relationships Between Lines"
    assert len(lesson["scenes"]) == 2
    assert [scene["section_title"] for scene in lesson["scenes"]] == [
        "Parallel Lines", "Perpendicular Lines"
    ]
    assert [scene["generated_prompt"] for scene in lesson["scenes"]] == [
        "Comparison: Scene prompt for parallel lines.",
        "Process: Scene prompt for perpendicular lines.",
    ]
    assert len(lesson["scenes"][0]["first_output"]["fields"]) == 11
    assert lesson["scenes"][1]["second_output"]["perspective_map"]["scene_type"] == "Process"
    assert path.stat().st_mode & 0o777 == 0o600
    assert tmp_path.stat().st_mode & 0o777 == 0o700


def test_archive_is_idempotent_and_rejects_changed_content_for_same_lesson(tmp_path):
    bundle = make_bundle()
    first_path = archive(tmp_path, bundle)
    second_path = archive(tmp_path, bundle)
    assert first_path == second_path
    assert len(json.loads(first_path.read_text(encoding="utf-8"))["lessons"]) == 1

    changed = replace(bundle, raw_third="different result")
    with pytest.raises(LessonArchiveError, match="different content"):
        archive(tmp_path, changed)


def test_archive_marks_handoff_partial_until_every_mapped_lesson_is_saved(tmp_path):
    bundle = make_bundle()
    archive_lesson_bundle(
        notebook_id="notebook-123",
        conversation_id="conversation-456",
        source_ids={"original_pdf": "pdf-source", "framework_steps": "steps-source"},
        lesson_ordinal=1,
        lesson_title="Lesson 1: Relationships Between Lines",
        expected_work_count=2,
        bundle=bundle,
        output_dir=tmp_path,
    )
    document = json.loads(next(tmp_path.glob("*.json")).read_text(encoding="utf-8"))
    assert document["expected_work_count"] == 2
    assert document["status"] == "partial"
    assert [lesson["lesson_ordinal"] for lesson in document["lessons"]] == [1]


def test_raw_responses_complete_the_run_even_when_structured_parsing_is_deferred(tmp_path):
    common = {
        "notebook_id": "notebook-123",
        "conversation_id": "conversation-456",
        "source_ids": {"original_pdf": "pdf-source", "framework_steps": "steps-source"},
        "expected_work_count": 2,
        "output_dir": tmp_path,
    }
    archive_lesson_bundle(
        **common,
        lesson_ordinal=1,
        lesson_title="Mapped lesson 1",
        raw_response="unparsed provider response 1",
        parse_status="raw_only",
    )
    path = archive_lesson_bundle(
        **common,
        lesson_ordinal=2,
        lesson_title="Mapped lesson 2",
        raw_response="unparsed provider response 2",
        parse_status="raw_only",
    )

    document = json.loads(path.read_text(encoding="utf-8"))
    assert document["status"] == "complete"
    assert [lesson["raw_response"] for lesson in document["lessons"]] == [
        "unparsed provider response 1", "unparsed provider response 2"
    ]
    assert [lesson["parse_status"] for lesson in document["lessons"]] == ["raw_only", "raw_only"]
    assert [lesson["scenes"] for lesson in document["lessons"]] == [[], []]


def test_raw_response_is_enriched_after_successful_parse_without_duplicate_lesson(tmp_path):
    raw_response = "complete provider response"
    archive_lesson_bundle(
        notebook_id="notebook-123",
        conversation_id="conversation-456",
        source_ids={"original_pdf": "pdf-source", "framework_steps": "steps-source"},
        lesson_ordinal=1,
        lesson_title="Lesson 1: Relationships Between Lines",
        expected_work_count=1,
        raw_response=raw_response,
        parse_status="raw_only",
        output_dir=tmp_path,
    )
    path = archive_lesson_bundle(
        notebook_id="notebook-123",
        conversation_id="conversation-456",
        source_ids={"original_pdf": "pdf-source", "framework_steps": "steps-source"},
        lesson_ordinal=1,
        lesson_title="Lesson 1: Relationships Between Lines",
        expected_work_count=1,
        bundle=make_bundle(),
        raw_response=raw_response,
        parse_status="parsed",
        output_dir=tmp_path,
    )

    lesson = json.loads(path.read_text(encoding="utf-8"))["lessons"][0]
    assert lesson["raw_response"] == raw_response
    assert lesson["parse_status"] == "parsed"
    assert len(lesson["scenes"]) == 2


def test_concurrent_identical_archives_produce_one_lesson_record(tmp_path):
    bundle = make_bundle()
    with ThreadPoolExecutor(max_workers=4) as pool:
        paths = list(pool.map(lambda _: archive(tmp_path, bundle), range(8)))

    assert len(set(paths)) == 1
    document = json.loads(paths[0].read_text(encoding="utf-8"))
    assert len(document["lessons"]) == 1
