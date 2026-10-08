"""Parser for NotebookLM coverage check query output per Spec 009."""
import re
from dataclasses import dataclass
from typing import List, Dict, Set, Any

@dataclass
class PageCoverageItem:
    page: int
    work_ordinals: List[int]

class CoverageParserError(Exception):
    def __init__(self, message: str, code: str = "validation"):
        super().__init__(message)
        self.code = code

PAGE_LINE_RE = re.compile(r"^PAGE\s+([0-9]+)\s*:\s*(.+)$", re.IGNORECASE)
W_TOKEN_RE = re.compile(r"^W([0-9]+)$", re.IGNORECASE)

def parse_coverage_response(raw_text: str, page_count: int, work_count: int) -> List[PageCoverageItem]:
    """Parse raw coverage check response ensuring all pages 1..N and all work ordinals are covered."""
    if not raw_text or not raw_text.strip():
        raise CoverageParserError("Coverage response is empty", code="validation")

    lines = raw_text.strip().splitlines()
    page_map: Dict[int, List[int]] = {}
    seen_work_ordinals: Set[int] = set()

    for raw_line in lines:
        line = raw_line.strip()
        if not line:
            continue

        match = PAGE_LINE_RE.match(line)
        if not match:
            raise CoverageParserError(f"Invalid coverage line format: '{line}'", code="validation")

        page_str, assignments_str = match.groups()
        page_num = int(page_str)
        assignments_str = assignments_str.strip()

        if assignments_str.upper() == "GAP":
            raise CoverageParserError(f"Educational content GAP on page {page_num}", code="validation")

        if page_num in page_map:
            raise CoverageParserError(f"Duplicate page {page_num} in coverage response", code="validation")

        if page_num < 1 or page_num > page_count:
            raise CoverageParserError(f"Page {page_num} out of bounds (1..{page_count})", code="validation")

        work_tokens = [t.strip() for t in assignments_str.split(",") if t.strip()]
        if not work_tokens:
            raise CoverageParserError(f"No work items assigned to page {page_num}", code="validation")

        page_work_ords: Set[int] = set()
        for tok in work_tokens:
            w_match = W_TOKEN_RE.match(tok)
            if not w_match:
                raise CoverageParserError(f"Invalid work token '{tok}' on page {page_num}", code="validation")
            w_ord = int(w_match.group(1))
            if w_ord < 1 or w_ord > work_count:
                raise CoverageParserError(f"Unknown work ordinal W{w_ord} on page {page_num} (max W{work_count})", code="validation")
            page_work_ords.add(w_ord)
            seen_work_ordinals.add(w_ord)

        page_map[page_num] = sorted(page_work_ords)

    # Validate that every page 1..N is present exactly once
    if len(page_map) != page_count:
        missing_pages = set(range(1, page_count + 1)) - set(page_map.keys())
        raise CoverageParserError(f"Coverage incomplete: missing pages {sorted(missing_pages)}", code="validation")

    # Validate that every work item W1..Wn has at least one page assigned
    if len(seen_work_ordinals) != work_count:
        missing_works = set(range(1, work_count + 1)) - seen_work_ordinals
        raise CoverageParserError(f"Work items unassigned in coverage: {sorted(missing_works)}", code="validation")

    return [
        PageCoverageItem(page=p, work_ordinals=page_map[p])
        for p in sorted(page_map.keys())
    ]
