"""Tests for NotebookLM direct protocol combined bundle parser (Spec 016).

Covers FR-009, FR-010 / N12, N15.
"""
from pathlib import Path
import pytest

from v3_engine.notebooklm.bundle_parser import (
    BundleParserError,
    ParsedCombinedBundle,
    parse_combined_bundle,
)

FIXTURES_DIR = Path(__file__).resolve().parent.parent.parent.parent / "tests" / "Fixtures" / "V3" / "NotebookLM"


@pytest.mark.parametrize("section_prefix", ["Lec1", "LecW1"])
def test_n12_parse_valid_combined_envelope(section_prefix):
    """N12: Parse valid combined envelope preserving all eleven First fields, Arabic, LaTeX, 13 Second fields, and Third prompts."""
    raw_text = (FIXTURES_DIR / "valid_combined_lesson_1.txt").read_text(encoding="utf-8")
    raw_text = raw_text.replace("Lec1 (Sec", section_prefix + " (Sec")

    bundle: ParsedCombinedBundle = parse_combined_bundle(raw_text, expected_work_ordinal=1)

    assert bundle.work_ordinal == 1
    assert bundle.terminal_marker_ordinal == 1
    assert bundle.lecture_title == "W1 | The Number Line and Real Numbers"

    # First artifact checks
    assert len(bundle.first.sections) == 2
    sec1 = bundle.first.sections[0]
    assert sec1.ordinal == 1
    assert sec1.fields["section_identity"] == "Real Number Representation"
    assert "خط الأعداد ده خط مستمر ملوش نهاية" in sec1.fields["colloquial_egyptian_explanation"]
    assert "\\forall x \\in \\mathbb{R}" in sec1.fields["laws_if_any"]
    assert "مسطرة القياس الهندسية" in sec1.fields["real_life_example"]
    assert len(sec1.fields) == 11

    sec2 = bundle.first.sections[1]
    assert sec2.ordinal == 2
    assert sec2.fields["section_identity"] == "Order and Absolute Value"
    assert "|a - b|" in sec2.fields["laws_if_any"]

    # Second artifact checks
    assert len(bundle.second.sections) == 2
    sec1_prep = bundle.second.sections[0]
    assert sec1_prep.ordinal == 1
    assert sec1_prep.placeholder == "Interactive number line exploring real number continuum and density."
    assert sec1_prep.perspective_map["scene_type"] == "Comparison"
    assert sec1_prep.perspective_map["primary_learning_objective"] == "Understand one-to-one correspondence between real numbers and points on a line."
    assert len(sec1_prep.perspective_map) == 13

    # Third artifact checks
    assert len(bundle.third.sections) == 2
    sec1_third = bundle.third.sections[0]
    assert sec1_third.ordinal == 1
    assert sec1_third.scene_type == "Comparison"
    assert sec1_third.prompt_body.startswith("Comparison:")
    assert "'Zoom in between 1 and 2'" in sec1_third.prompt_body
    assert len(sec1_third.prompt_body.encode("utf-8")) <= 65536

    sec2_third = bundle.third.sections[1]
    assert sec2_third.ordinal == 2
    assert sec2_third.scene_type == "Process"
    assert sec2_third.prompt_body.startswith("Process:")


def test_third_parser_accepts_prompt_inline_with_section_header():
    """NotebookLM may place the full quoted Third prompt after the Lec/Sec label."""
    raw_text = (FIXTURES_DIR / "valid_combined_lesson_1.txt").read_text(encoding="utf-8")
    prefix, first_and_rest = raw_text.split("=== FIRST ===", 1)
    first, second_and_rest = first_and_rest.split("=== SECOND ===", 1)
    second, third_and_rest = second_and_rest.split("=== THIRD ===", 1)
    third, tail = third_and_rest.split("=== END ===", 1)
    third = third.replace('Lec1 (Sec1):\n"', 'Lec1 (Sec1): "', 1)
    raw_text = (
        prefix + "=== FIRST ===" + first + "=== SECOND ===" + second
        + "=== THIRD ===" + third + "=== END ===" + tail
    )

    bundle = parse_combined_bundle(raw_text, expected_work_ordinal=1)

    assert len(bundle.third.sections) == 2
    assert bundle.third.sections[0].scene_type == "Comparison"
    assert bundle.third.sections[0].prompt_body.startswith("Comparison:")


def test_live_style_section_headers_allow_provider_appended_titles():
    raw_text = (FIXTURES_DIR / "valid_combined_lesson_1.txt").read_text(encoding="utf-8")
    raw_text = raw_text.replace(
        'Lec1 (Sec1):\n"Interactive number line exploring real number continuum and density."',
        'Lec1 (Sec1): "Interactive number line exploring real number continuum and density."',
        1,
    )

    bundle = parse_combined_bundle(raw_text, expected_work_ordinal=1)

    assert [section.ordinal for section in bundle.second.sections] == [1, 2]
    assert [section.ordinal for section in bundle.third.sections] == [1, 2]


def test_n15_reject_final_only_reply():
    """N15: Reject final-only reply that lacks FIRST and SECOND delimiters."""
    raw_text = (FIXTURES_DIR / "combined_final_only.txt").read_text(encoding="utf-8")

    with pytest.raises(BundleParserError) as exc_info:
        parse_combined_bundle(raw_text, expected_work_ordinal=1)
    assert exc_info.value.code in {"validation", "bundle_invalid"}


def test_n15_reject_missing_delimiter():
    """N15: Reject combined reply missing SECOND delimiter."""
    raw_text = (FIXTURES_DIR / "combined_missing_delimiter.txt").read_text(encoding="utf-8")

    with pytest.raises(BundleParserError) as exc_info:
        parse_combined_bundle(raw_text, expected_work_ordinal=1)
    assert exc_info.value.code in {"validation", "bundle_invalid"}


def test_n15_accepts_provider_scene_type_field_prefix():
    """NotebookLM's scene type label is metadata; the prefix is not a whitelist failure."""
    raw_text = (FIXTURES_DIR / "combined_invalid_third_type.txt").read_text(encoding="utf-8")

    bundle = parse_combined_bundle(raw_text, expected_work_ordinal=1)
    assert bundle.third.sections[0].scene_type == "Comparison"


def test_n15_accepts_unlisted_notebooklm_scene_type():
    raw_text = (FIXTURES_DIR / "valid_combined_lesson_1.txt").read_text(encoding="utf-8")
    raw_text = raw_text.replace('"Comparison: Real Number Line', '"Decomposition: Real Number Line', 1)

    bundle = parse_combined_bundle(raw_text, expected_work_ordinal=1)

    assert bundle.third.sections[0].scene_type == "Decomposition"


def test_n15_completion_marker_number_is_advisory_to_the_claimed_work_ordinal():
    """The cursor and map identify work; NotebookLM's displayed lesson number is not authority."""
    raw_text = (FIXTURES_DIR / "combined_mismatched_marker.txt").read_text(encoding="utf-8")

    bundle = parse_combined_bundle(raw_text, expected_work_ordinal=1)

    assert bundle.work_ordinal == 1
    assert bundle.terminal_marker_ordinal == 2


def test_n15_completion_marker_still_requires_positive_number():
    raw_text = (FIXTURES_DIR / "valid_combined_lesson_1.txt").read_text(encoding="utf-8")
    raw_text = raw_text.replace("Lesson [1] Completed.", "Lesson [0] Completed.")

    with pytest.raises(BundleParserError, match="positive display number"):
        parse_combined_bundle(raw_text, expected_work_ordinal=1)


def test_unnumbered_completion_requires_identical_claimed_work_headers():
    raw = (FIXTURES_DIR / "valid_combined_lesson_1.txt").read_text().replace("Lesson [1] Completed.", "Lesson  Completed.")
    assert parse_combined_bundle(raw, 1).terminal_marker_ordinal == 1
    wrong = raw.replace("=== SECOND ===\nLecture: W1", "=== SECOND ===\nLecture: W2")
    with pytest.raises(BundleParserError):
        parse_combined_bundle(wrong, 1)


def test_inner_quoted_questions_do_not_truncate_multiline_scene_prompts():
    raw = (FIXTURES_DIR / "valid_combined_lesson_1.txt").read_text()
    raw = raw.replace('Learning Target: Contrast discrete integer points with dense real number continuum.',
        'Learning Target: Contrast discrete integer points with dense real number continuum.\nLearner question: "Which point moves?"\nPreserve the rest of the scene after this question.')
    parsed = parse_combined_bundle(raw, 1)
    assert len(parsed.third.sections) == 2
    assert 'Learner question: "Which point moves?"' in parsed.third.sections[0].prompt_body
    assert 'Preserve the rest of the scene after this question.' in parsed.third.sections[0].prompt_body
    assert 'Acceptance Requirements:' in parsed.third.sections[0].prompt_body


def test_n15_reject_missing_first_fields():
    """N15: Reject First artifact missing one of the 11 canonical fields."""
    raw_text = (FIXTURES_DIR / "combined_missing_first_fields.txt").read_text(encoding="utf-8")

    with pytest.raises(BundleParserError) as exc_info:
        parse_combined_bundle(raw_text, expected_work_ordinal=1)
    assert exc_info.value.code in {"validation", "bundle_invalid"}


def test_n15_reject_section_count_mismatch():
    """N15: Reject reply when section count does not match between First, Second, and Third."""
    raw_text = (FIXTURES_DIR / "combined_mismatched_section_count.txt").read_text(encoding="utf-8")

    with pytest.raises(BundleParserError) as exc_info:
        parse_combined_bundle(raw_text, expected_work_ordinal=1)
    assert exc_info.value.code in {"validation", "bundle_invalid"}


def test_n15_reject_oversized_reply():
    """N15: Reject reply exceeding 1 MiB."""
    oversized = "A" * (1024 * 1024 + 1)
    with pytest.raises(BundleParserError) as exc_info:
        parse_combined_bundle(oversized, expected_work_ordinal=1)
    assert exc_info.value.code in {"validation", "bundle_invalid"}


def test_n15_reject_empty_reply():
    """N15: Reject empty reply."""
    with pytest.raises(BundleParserError) as exc_info:
        parse_combined_bundle("", expected_work_ordinal=1)
    assert exc_info.value.code in {"validation", "bundle_invalid"}
