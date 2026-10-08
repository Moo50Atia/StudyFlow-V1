"""Tests for V3 Engine PDF Extraction (FR-002, FR-003 / K04-K06)."""
import hashlib
import os
import pytest
from pathlib import Path

from v3_engine.sources.contracts import ExtractionResult, PageReport
from v3_engine.sources.extract_pdf import extract_pdf_source

FIXTURES_DIR = Path(__file__).parent / "fixtures"

def read_fixture(name: str) -> bytes:
    path = FIXTURES_DIR / name
    assert path.exists(), f"Fixture {name} not found at {path}"
    return path.read_bytes()

def test_k04_simple_text_extraction_and_canonical_wrapper():
    """K04: Standard multi-page text document extracts canonical text with strict page wrappers."""
    pdf_bytes = read_fixture("simple_text.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=5)

    assert result.status == "ready"
    assert result.error_code is None
    assert result.page_count == 2
    assert result.ocr_page_count == 0
    assert len(result.page_report) == 2

    # Check page report
    p1 = result.page_report[0]
    assert p1.page == 1
    assert p1.method == "text"
    assert p1.chars > 0
    assert p1.gap_code is None
    assert p1.ocr_confidence is None

    p2 = result.page_report[1]
    assert p2.page == 2
    assert p2.method == "text"
    assert p2.chars > 0
    assert p2.gap_code is None

    # Check canonical text formatting
    text = result.text
    assert text is not None
    assert text.startswith("[PAGE 1/2]\n")
    assert "\n[/PAGE 1/2]\n[PAGE 2/2]\n" in text
    assert text.endswith("\n[/PAGE 2/2]\n")
    assert "Introduction to Science" in text
    assert "Energy cannot be created or destroyed" in text
    assert "Forces and Motion" in text
    assert "Every action has an equal and opposite reaction" in text

    # Strict LF and NFC and sha256
    assert "\r" not in text
    expected_hash = hashlib.sha256(text.encode("utf-8")).hexdigest()
    assert result.text_sha256 == expected_hash

def test_k04_marker_shaped_source_line_escaping():
    """K04: Lines matching ^\\[/?PAGE [0-9]+/[0-9]+\\]$ are escaped with leading backslash."""
    pdf_bytes = read_fixture("marker_escaping.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=5)

    assert result.status == "ready"
    text = result.text
    assert text is not None

    # Line should be escaped
    assert "\\[PAGE 1/2]" in text
    assert "\\[/PAGE 1/2]" in text

    # Canonical markers themselves are present
    assert text.startswith("[PAGE 1/1]\n")
    assert text.endswith("\n[/PAGE 1/1]\n")

def test_k04_two_column_reading_order():
    """K04: Detected two-column page is read left column then right column."""
    pdf_bytes = read_fixture("two_column.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=5)

    assert result.status == "ready"
    text = result.text
    assert text is not None

    # Top heading comes first
    idx_heading = text.find("Two Column Layout")
    idx_left1 = text.find("Left column line one")
    idx_left3 = text.find("Left column line three")
    idx_right1 = text.find("Right column line one")
    idx_right3 = text.find("Right column line three")

    assert idx_heading < idx_left1
    assert idx_left1 < idx_left3
    assert idx_left3 < idx_right1
    assert idx_right1 < idx_right3

def test_k04_table_row_major_and_no_duplicate_words():
    """K04: Tables are extracted row-major and cells do not duplicate word boxes."""
    pdf_bytes = read_fixture("table_page.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=5)

    assert result.status == "ready"
    text = result.text
    assert text is not None

    assert "Experiment Measurements" in text
    assert "Element" in text
    assert "Hydrogen" in text
    assert "Helium" in text

    # Verify no duplication of words
    assert text.count("Hydrogen") == 1
    assert text.count("Helium") == 1
    assert text.count("1.008") == 1

def test_k06_text_layer_visual_gap_blocks():
    """K06: Text-layer page with nontext image/vector area >= 5% of page blocks with visual_unrepresented."""
    pdf_bytes = read_fixture("visual_gap_text.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=5)

    assert result.status == "blocked"
    assert result.error_code == "validation"
    assert result.text is None
    assert result.text_sha256 is None
    assert len(result.page_report) == 1
    assert result.page_report[0].gap_code == "visual_unrepresented"

def test_k05_ocr_within_budget_succeeds():
    """K05: Scanned page without text layer uses OCR within max_ocr_pages budget."""
    pdf_bytes = read_fixture("clean_scanned.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=5)

    assert result.status == "ready"
    assert result.error_code is None
    assert result.page_count == 1
    assert result.ocr_page_count == 1
    assert len(result.page_report) == 1

    p = result.page_report[0]
    assert p.method == "ocr"
    assert p.gap_code is None
    assert p.ocr_confidence is not None
    assert p.ocr_confidence >= 70.0

    text = result.text
    assert text is not None
    assert text.startswith("[PAGE 1/1]\n")
    assert "Optical Character Recognition" in text or "Testing Document" in text

def test_k05_zero_ocr_budget_blocks():
    """K05: Scanned page blocks when max_ocr_pages budget is 0 or exceeded."""
    pdf_bytes = read_fixture("clean_scanned.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=0)

    assert result.status == "blocked"
    assert result.error_code == "validation"
    assert result.text is None
    assert result.page_report[0].gap_code == "unreadable"

def test_k05_unreadable_scan_blocks():
    """K05: Noise / low-confidence scan blocks with unreadable."""
    pdf_bytes = read_fixture("unreadable_scanned.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=5)

    assert result.status == "blocked"
    assert result.error_code == "validation"
    assert result.text is None
    assert result.page_report[0].gap_code == "unreadable"

def test_k06_scanned_residual_diagram_blocks():
    """K06: Scanned page with residual ink / geometric diagram blocks with visual_unrepresented."""
    pdf_bytes = read_fixture("scanned_diagram.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=5)

    assert result.status == "blocked"
    assert result.error_code == "validation"
    assert result.text is None
    assert result.page_report[0].gap_code == "visual_unrepresented"

def test_k06_oversize_text_blocks():
    """K06: Canonical text exceeding 1 MiB blocks with size_limit."""
    pdf_bytes = read_fixture("oversize_text.pdf")
    result = extract_pdf_source(pdf_bytes, max_ocr_pages=5)

    assert result.status == "blocked"
    assert result.error_code == "validation"
    assert result.text is None
    assert any(p.gap_code == "size_limit" for p in result.page_report)
