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


def test_n12_parse_valid_combined_envelope():
    """N12: Parse valid combined envelope preserving all eleven First fields, Arabic, LaTeX, 13 Second fields, and Third prompts."""
    raw_text = (FIXTURES_DIR / "valid_combined_lesson_1.txt").read_text(encoding="utf-8")

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


def test_n15_reject_invalid_third_type():
    """N15: Reject Third prompt starting with 'Scene Type: Comparison' (not permitted in v1)."""
    raw_text = (FIXTURES_DIR / "combined_invalid_third_type.txt").read_text(encoding="utf-8")

    with pytest.raises(BundleParserError) as exc_info:
        parse_combined_bundle(raw_text, expected_work_ordinal=1)
    assert exc_info.value.code in {"validation", "bundle_invalid"}


def test_n15_reject_mismatched_marker_ordinal():
    """N15: Reject reply when completion marker work ordinal does not match expected ordinal."""
    raw_text = (FIXTURES_DIR / "combined_mismatched_marker.txt").read_text(encoding="utf-8")

    with pytest.raises(BundleParserError) as exc_info:
        parse_combined_bundle(raw_text, expected_work_ordinal=1)
    assert exc_info.value.code in {"validation", "bundle_invalid"}


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
