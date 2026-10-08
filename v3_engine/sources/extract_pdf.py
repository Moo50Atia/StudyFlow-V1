"""V3 Engine PDF Extraction (FR-002, FR-003 / K04-K06).

Strict local extraction using pdfplumber for text geometry and
Poppler pdftoppm + Tesseract pytesseract for OCR fallback.
Enforces canonical page wrapper, two-column ordering, table extraction,
and visual/structural/size gap detection gates.
"""

import hashlib
import os
import re
import shutil
import subprocess
import tempfile
import unicodedata
from collections import defaultdict
from typing import List, Optional, Tuple

import numpy as np
import pdfplumber
from PIL import Image
import pytesseract

from v3_engine.sources.contracts import ExtractionResult, PageReport

EXTRACTOR_VERSION = "0.1.0"
MAX_CANONICAL_BYTES = 1048576  # 1 MiB

def _escape_page_marker_lines(text: str) -> str:
    """Escape lines matching ^\\[/?PAGE [0-9]+/[0-9]+\\]$ with a leading backslash."""
    lines = text.splitlines()
    escaped = []
    pattern = re.compile(r"^\[/?PAGE [0-9]+/[0-9]+\]$")
    for line in lines:
        if pattern.match(line):
            escaped.append("\\" + line)
        else:
            escaped.append(line)
    return "\n".join(escaped)

def _group_words_into_lines(words: List[dict], y_tolerance: float = 3.0) -> List[str]:
    """Group words with similar top coordinates into lines, sorted left to right."""
    if not words:
        return []
    
    # Sort words primarily by top coordinate, then by x0
    sorted_words = sorted(words, key=lambda w: (w["top"], w["x0"]))
    lines = []
    current_line = [sorted_words[0]]
    current_top = sorted_words[0]["top"]

    for w in sorted_words[1:]:
        if abs(w["top"] - current_top) <= y_tolerance:
            current_line.append(w)
        else:
            # Sort words in current line left to right
            current_line.sort(key=lambda item: item["x0"])
            lines.append(" ".join(item["text"] for item in current_line))
            current_line = [w]
            current_top = w["top"]

    if current_line:
        current_line.sort(key=lambda item: item["x0"])
        lines.append(" ".join(item["text"] for item in current_line))

    return lines

def _extract_text_layer(page: pdfplumber.page.Page) -> Tuple[Optional[str], Optional[str]]:
    """Extract text from text layer handling two-column layout, tables, and visual gap gate.
    
    Returns (extracted_text, gap_code).
    """
    page_width = float(page.width)
    page_height = float(page.height)
    page_area = page_width * page_height

    words = page.extract_words()
    if not words:
        return None, None  # No words; candidate for OCR fallback

    # Check for replacement glyph
    for w in words:
        if "\ufffd" in w["text"]:
            return None, "unreadable"

    # Check if page has at least one Unicode letter or number
    has_alnum = any(any(c.isalnum() for c in w["text"]) for w in words)
    if not has_alnum:
        return None, None  # Candidate for OCR fallback

    # 1. On text-layer page: Check for nontext image or vector regions >= 5% of page area
    for img in page.images:
        w = float(img.get("width", img.get("x1", 0) - img.get("x0", 0)))
        h = float(img.get("height", img.get("bottom", 0) - img.get("top", 0)))
        area = w * h
        if area >= 0.05 * page_area:
            return None, "visual_unrepresented"

    # 2. Extract tables
    tables = []
    table_bboxes = []
    try:
        found_tables = page.find_tables()
        for t in found_tables:
            table_bboxes.append(t.bbox)
            extracted_table = t.extract()
            if extracted_table:
                # Format row-major
                table_lines = []
                for row in extracted_table:
                    row_cells = [str(cell).strip() for cell in row if cell is not None]
                    if row_cells:
                        table_lines.append("\t".join(row_cells))
                if table_lines:
                    tables.append((t.bbox[1], "\n".join(table_lines)))
    except Exception:
        return None, "structure_unrepresented"

    # Filter out words that fall inside any table bbox to avoid duplicates
    def is_inside_table(w):
        wx_mid = (w["x0"] + w["x1"]) / 2
        wy_mid = (w["top"] + w["bottom"]) / 2
        for bbox in table_bboxes:
            if bbox[0] <= wx_mid <= bbox[2] and bbox[1] <= wy_mid <= bbox[3]:
                return True
        return False

    non_table_words = [w for w in words if not is_inside_table(w)]

    # 3. Detect two-column layout
    # A two-column page has words partitioned into left and right columns
    mid = page_width / 2
    left_words = [w for w in non_table_words if w["x1"] <= mid + 15]
    right_words = [w for w in non_table_words if w["x0"] >= mid - 15]

    is_two_col = False
    if len(left_words) >= 2 and len(right_words) >= 2:
        col_word_count = len(left_words) + len(right_words)
        if col_word_count >= 0.7 * len(non_table_words):
            # Check vertical overlap between left and right columns
            left_min_top = min(w["top"] for w in left_words)
            left_max_top = max(w["top"] for w in left_words)
            right_min_top = min(w["top"] for w in right_words)
            right_max_top = max(w["top"] for w in right_words)
            if not (left_max_top < right_min_top or right_max_top < left_min_top):
                is_two_col = True

    sections = []
    if is_two_col:
        # Separate top header (words above column start spanning or centered)
        col_start_y = min(min(w["top"] for w in left_words), min(w["top"] for w in right_words))
        header_words = [w for w in non_table_words if w["bottom"] <= col_start_y + 5 and w not in left_words and w not in right_words]
        
        if header_words:
            header_lines = _group_words_into_lines(header_words)
            if header_lines:
                sections.append((min(w["top"] for w in header_words), "\n".join(header_lines)))

        left_lines = _group_words_into_lines(left_words)
        if left_lines:
            sections.append((min(w["top"] for w in left_words), "\n".join(left_lines)))

        right_lines = _group_words_into_lines(right_words)
        if right_lines:
            sections.append((min(w["top"] for w in right_words), "\n".join(right_lines)))
    else:
        # Single column / top-to-bottom
        lines = _group_words_into_lines(non_table_words)
        if lines:
            sections.append((min(w["top"] for w in non_table_words), "\n".join(lines)))

    # Add tables into sections
    for table_top, table_text in tables:
        sections.append((table_top, table_text))

    # Sort sections top-to-bottom
    sections.sort(key=lambda s: s[0])
    combined_text = "\n".join(s[1] for s in sections).strip()

    if not combined_text or not any(c.isalnum() for c in combined_text):
        return None, None

    return combined_text, None

def _check_ocr_pixel_gate(img: Image.Image, accepted_boxes: List[Tuple[int, int, int, int]]) -> Tuple[bool, str]:
    """Check OCR residual foreground pixel gate.
    
    Rendered at 200 dpi grayscale. Mark pixels < 240 as foreground.
    Exclude accepted OCR word boxes expanded by 5 pixels.
    If residual foreground >= 0.2% of pixels or connected component >= 1% of page area, return (True, 'visual_unrepresented').
    """
    img_gray = img.convert("L")
    arr = np.array(img_gray)
    H, W = arr.shape
    total_pixels = H * W

    # Foreground pixels are below grayscale 240
    mask = (arr < 240)

    # Exclude accepted OCR word boxes expanded by 5 rendered pixels
    for left, top, w, h in accepted_boxes:
        x0 = max(0, left - 5)
        y0 = max(0, top - 5)
        x1 = min(W, left + w + 5)
        y1 = min(H, top + h + 5)
        mask[y0:y1, x0:x1] = False

    residual_count = np.count_nonzero(mask)
    if residual_count / total_pixels >= 0.002:
        return True, "visual_unrepresented"

    if residual_count > 0:
        # Check connected components
        visited = np.zeros_like(mask, dtype=bool)
        coords = np.argwhere(mask)
        threshold_box_area = 0.01 * total_pixels
        for y0, x0 in coords:
            if visited[y0, x0]:
                continue
            min_y, max_y = y0, y0
            min_x, max_x = x0, x0
            queue = [(y0, x0)]
            visited[y0, x0] = True
            while queue:
                cy, cx = queue.pop()
                if cy < min_y: min_y = cy
                if cy > max_y: max_y = cy
                if cx < min_x: min_x = cx
                if cx > max_x: max_x = cx
                for dy, dx in [(-1, 0), (1, 0), (0, -1), (0, 1)]:
                    ny, nx = cy + dy, cx + dx
                    if 0 <= ny < H and 0 <= nx < W and mask[ny, nx] and not visited[ny, nx]:
                        visited[ny, nx] = True
                        queue.append((ny, nx))
            box_area = (max_y - min_y + 1) * (max_x - min_x + 1)
            if box_area >= threshold_box_area:
                return True, "visual_unrepresented"

    return False, ""

def _ocr_page(pdf_path: str, page_num: int) -> Tuple[Optional[str], Optional[float], Optional[str], Optional[str]]:
    """Perform OCR on a single page using pdftoppm and pytesseract.
    
    Returns (text, ocr_confidence, gap_code, error_code).
    """
    temp_dir = tempfile.mkdtemp(prefix="v3_ocr_")
    try:
        prefix = os.path.join(temp_dir, f"page_{page_num}")
        cmd = [
            "pdftoppm", "-png", "-r", "200", "-gray",
            "-f", str(page_num), "-l", str(page_num),
            pdf_path, prefix
        ]
        proc = subprocess.run(cmd, capture_output=True)
        if proc.returncode != 0:
            return None, None, "unreadable", "validation"

        rendered_files = [os.path.join(temp_dir, f) for f in os.listdir(temp_dir) if f.endswith(".png")]
        if not rendered_files:
            return None, None, "unreadable", "validation"
        
        rendered_image_path = rendered_files[0]
        img = Image.open(rendered_image_path)

        # Check available tesseract languages
        try:
            installed_langs = pytesseract.get_languages()
            lang = "eng+ara" if ("eng" in installed_langs and "ara" in installed_langs) else "eng"
        except Exception:
            return None, None, "unreadable", "provider_unavailable"

        try:
            data = pytesseract.image_to_data(img, lang=lang, output_type=pytesseract.Output.DICT)
        except Exception:
            return None, None, "unreadable", "provider_unavailable"

        tokens = []
        confs = []
        accepted_boxes = []

        n_boxes = len(data["text"])
        for i in range(n_boxes):
            word_text = data["text"][i].strip()
            conf_val = float(data["conf"][i])
            if conf_val != -1 and word_text:
                if "\ufffd" in word_text:
                    return None, None, "unreadable", "validation"
                tokens.append((i, word_text, conf_val))
                confs.append(conf_val)
                accepted_boxes.append((
                    data["left"][i],
                    data["top"][i],
                    data["width"][i],
                    data["height"][i],
                ))

        if not tokens:
            return None, None, "unreadable", "validation"

        all_text = " ".join(t[1] for t in tokens)
        if not any(c.isalnum() for c in all_text):
            return None, None, "unreadable", "validation"

        unrounded_conf = sum(confs) / len(confs)
        if unrounded_conf < 70.0:
            return None, round(unrounded_conf, 1), "unreadable", "validation"

        rounded_conf = round(unrounded_conf, 1)

        # Pixel gate check
        is_visual_gap, gap_reason = _check_ocr_pixel_gate(img, accepted_boxes)
        if is_visual_gap:
            return None, rounded_conf, "visual_unrepresented", "validation"

        # Reconstruct text into lines
        lines_dict = defaultdict(list)
        for i, word_text, _ in tokens:
            line_key = (data["block_num"][i], data["par_num"][i], data["line_num"][i])
            lines_dict[line_key].append((data["left"][i], word_text))

        sorted_line_keys = sorted(lines_dict.keys())
        lines = []
        for key in sorted_line_keys:
            words_in_line = sorted(lines_dict[key], key=lambda x: x[0])
            lines.append(" ".join(w[1] for w in words_in_line))

        final_ocr_text = "\n".join(lines).strip()
        return final_ocr_text, rounded_conf, None, None

    finally:
        shutil.rmtree(temp_dir, ignore_errors=True)

def extract_pdf_source(pdf_bytes: bytes, max_ocr_pages: int = 20) -> ExtractionResult:
    """Extract canonical text or detect gaps from PDF bytes according to Spec 008 contracts."""
    if not pdf_bytes:
        return ExtractionResult(
            status="blocked",
            error_code="validation",
            extractor_version=EXTRACTOR_VERSION,
        )

    temp_fd, temp_pdf_path = tempfile.mkstemp(prefix="v3_extract_", suffix=".pdf")
    os.close(temp_fd)

    try:
        # Write mode-0600 temporary PDF
        with open(temp_pdf_path, "wb") as f:
            f.write(pdf_bytes)
        os.chmod(temp_pdf_path, 0o600)

        try:
            pdf = pdfplumber.open(temp_pdf_path)
        except Exception:
            return ExtractionResult(
                status="blocked",
                error_code="internal",
                extractor_version=EXTRACTOR_VERSION,
            )

        with pdf:
            page_count = len(pdf.pages)
            if page_count == 0:
                return ExtractionResult(
                    status="blocked",
                    error_code="validation",
                    extractor_version=EXTRACTOR_VERSION,
                )

            ocr_page_count = 0
            page_reports: List[PageReport] = []
            canonical_pages: List[str] = []
            has_blocked = False
            top_error_code: Optional[str] = None

            for idx, page in enumerate(pdf.pages):
                page_num = idx + 1
                text, gap_code = _extract_text_layer(page)

                if gap_code:
                    has_blocked = True
                    top_error_code = top_error_code or "validation"
                    page_reports.append(PageReport(
                        page=page_num,
                        method="text",
                        chars=0,
                        gap_code=gap_code,
                    ))
                    continue

                if text is not None:
                    # Text layer succeeded
                    page_reports.append(PageReport(
                        page=page_num,
                        method="text",
                        chars=len(text),
                        gap_code=None,
                    ))
                    canonical_pages.append(text)
                    continue

                # No usable text layer -> candidate for OCR fallback
                if ocr_page_count >= max_ocr_pages:
                    # OCR budget exceeded / 0
                    has_blocked = True
                    top_error_code = top_error_code or "validation"
                    page_reports.append(PageReport(
                        page=page_num,
                        method="none",
                        chars=0,
                        gap_code="unreadable",
                    ))
                    continue

                # Run OCR
                ocr_page_count += 1
                ocr_text, ocr_conf, ocr_gap, err_code = _ocr_page(temp_pdf_path, page_num)
                if ocr_gap:
                    has_blocked = True
                    top_error_code = top_error_code or err_code or "validation"
                    page_reports.append(PageReport(
                        page=page_num,
                        method="ocr",
                        chars=0,
                        ocr_confidence=ocr_conf,
                        gap_code=ocr_gap,
                    ))
                    continue

                # OCR succeeded
                page_reports.append(PageReport(
                    page=page_num,
                    method="ocr",
                    chars=len(ocr_text),
                    ocr_confidence=ocr_conf,
                    gap_code=None,
                ))
                canonical_pages.append(ocr_text)

            if has_blocked:
                return ExtractionResult(
                    status="blocked",
                    page_count=page_count,
                    ocr_page_count=ocr_page_count,
                    page_report=page_reports,
                    error_code=top_error_code or "validation",
                    extractor_version=EXTRACTOR_VERSION,
                )

            # Build canonical wrapped text
            wrapped_blocks = []
            for i, p_text in enumerate(canonical_pages):
                p_num = i + 1
                # NFC normalization
                p_text_norm = unicodedata.normalize("NFC", p_text)
                # LF line endings
                p_text_lf = p_text_norm.replace("\r\n", "\n").replace("\r", "\n")
                # Escape ^\\[/?PAGE [0-9]+/[0-9]+\\]$
                escaped_page = _escape_page_marker_lines(p_text_lf)
                wrapped_blocks.append(f"[PAGE {p_num}/{page_count}]\n{escaped_page}\n[/PAGE {p_num}/{page_count}]\n")

            canonical_text = "".join(wrapped_blocks)
            canonical_bytes = canonical_text.encode("utf-8")

            # Check 1 MiB limit
            if len(canonical_bytes) > MAX_CANONICAL_BYTES:
                for rep in page_reports:
                    rep.gap_code = "size_limit"
                return ExtractionResult(
                    status="blocked",
                    page_count=page_count,
                    ocr_page_count=ocr_page_count,
                    page_report=page_reports,
                    error_code="validation",
                    extractor_version=EXTRACTOR_VERSION,
                )

            text_sha256 = hashlib.sha256(canonical_bytes).hexdigest()

            return ExtractionResult(
                status="ready",
                text=canonical_text,
                text_sha256=text_sha256,
                page_count=page_count,
                ocr_page_count=ocr_page_count,
                page_report=page_reports,
                error_code=None,
                extractor_version=EXTRACTOR_VERSION,
            )

    finally:
        if os.path.exists(temp_pdf_path):
            try:
                os.unlink(temp_pdf_path)
            except OSError:
                pass
