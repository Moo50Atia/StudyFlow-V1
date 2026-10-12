"""Parser for First prompt output containing eleven canonical fields per Section."""
import re
from dataclasses import dataclass
from typing import List, Dict, Optional, Any, Tuple

CANONICAL_FIELD_LABELS = [
    "Section Identity",
    "Core Understanding",
    "Visual-First Explanation",
    "Visual Scene Script",
    "Dynamic View Blueprint",
    "Visual Learning Outcome",
    "Concept Check",
    "Conceptual Summary",
    "• شرح للجزء بالعامية المصرية",
    "القوانين (إن وُجدت)",
    "إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم",
]

# Mapping from canonical label regex pattern to internal field name
LABEL_SPECS: List[Tuple[str, re.Pattern, bool]] = [
    ("section_identity", re.compile(r"^Section\s+Identity\s*:?\s*(.*)$", re.IGNORECASE), False),
    ("core_understanding", re.compile(r"^Core\s+Understanding\s*:?\s*(.*)$", re.IGNORECASE), False),
    ("visual_first_explanation", re.compile(r"^Visual-First\s+Explanation\s*:?\s*(.*)$", re.IGNORECASE), False),
    ("visual_scene_script", re.compile(r"^Visual\s+Scene\s+Script\s*:?\s*(.*)$", re.IGNORECASE), False),
    ("dynamic_view_blueprint", re.compile(r"^Dynamic\s+View\s+Blueprint\s*:?\s*(.*)$", re.IGNORECASE), False),
    ("visual_learning_outcome", re.compile(r"^Visual\s+Learning\s+Outcome\s*:?\s*(.*)$", re.IGNORECASE), False),
    ("concept_check", re.compile(r"^Concept\s+Check\s*:?\s*(.*)$", re.IGNORECASE), False),
    ("conceptual_summary", re.compile(r"^Conceptual\s+Summary\s*:?\s*(.*)$", re.IGNORECASE), False),
    ("colloquial_egyptian_explanation", re.compile(r"^(?:[•\-*]\s*)?شرح\s+للجزء\s+بالعامية\s+المصرية\s*:?\s*(.*)$"), False),
    ("laws_if_any", re.compile(r"^القوانين\s*\((?:إن|ان)\s*(?:وُجدت|وجدت)\)\s*:?\s*(.*)$"), True),  # True: can be empty
    ("real_life_example", re.compile(r"^(?:إسقاط|اسقاط)\s+على\s+مثال\s+أو\s+موقف\s+من\s+الحياة\s+اليومية\s+لتثبيت\s+الفهم\s*:?\s*(.*)$"), False),
]

@dataclass
class ParsedSection:
    ordinal: int
    fields: Dict[str, str]

@dataclass
class ParsedFirstResponse:
    work_ordinal: int
    lecture_header: str
    sections: List[ParsedSection]
    completion_marker: Optional[int] = None

class FirstParserError(Exception):
    def __init__(self, message: str, code: str = "validation"):
        super().__init__(message)
        self.code = code

LECTURE_RE = re.compile(r"^Lecture\s*:\s*W([0-9]+)\s*\|\s*(.+)$", re.IGNORECASE)
SECTION_RE = re.compile(r"^Section\s+([0-9]+)\s*:\s*(?:.+)?$", re.IGNORECASE)
COMPLETION_MARKER_RE = re.compile(r"✅\s*Lesson\s*\[?([0-9]+)\]?\s*Completed", re.IGNORECASE)

def parse_first_response(raw_text: str, expected_work_ordinal: int, expected_path: Optional[str] = None) -> ParsedFirstResponse:
    """Parse raw First response enforcing eleven canonical fields and target work item identity."""
    if not raw_text or not raw_text.strip():
        raise FirstParserError("First response is empty", code="validation")

    # Normalize line endings
    normalized = raw_text.replace("\r\n", "\n").replace("\r", "\n")
    lines = normalized.split("\n")

    lecture_header: Optional[str] = None
    parsed_work_ord: Optional[int] = None

    sections: List[ParsedSection] = []
    current_section_ordinal: Optional[int] = None
    current_fields: Dict[str, str] = {}
    current_label_idx: int = -1
    completion_marker: Optional[int] = None

    # Step 1: Find lecture header
    line_idx = 0
    while line_idx < len(lines):
        line = lines[line_idx].strip()
        line_idx += 1
        if not line:
            continue

        match = LECTURE_RE.match(line)
        if match:
            parsed_work_ord = int(match.group(1))
            lecture_header = line
            break
        else:
            raise FirstParserError(f"First response must begin with Lecture header, got '{line}'", code="validation")

    if parsed_work_ord != expected_work_ordinal:
        raise FirstParserError(
            f"Work ordinal mismatch: expected W{expected_work_ordinal}, got W{parsed_work_ord}",
            code="validation",
        )

    def finalize_current_section():
        nonlocal current_section_ordinal, current_fields, current_label_idx
        if current_section_ordinal is not None:
            # Check all 11 fields were seen
            if len(current_fields) != len(LABEL_SPECS):
                missing = [name for name, _, _ in LABEL_SPECS if name not in current_fields]
                raise FirstParserError(
                    f"Section {current_section_ordinal} is missing fields: {missing}",
                    code="validation",
                )

            # Validate non-empty for required fields
            for name, _, can_be_empty in LABEL_SPECS:
                val = current_fields[name]
                if not can_be_empty and not val.strip():
                    raise FirstParserError(
                        f"Section {current_section_ordinal} field '{name}' cannot be empty",
                        code="validation",
                    )
                # Store normalized text
                current_fields[name] = val.strip() if not can_be_empty else val.strip()

            sections.append(ParsedSection(ordinal=current_section_ordinal, fields=dict(current_fields)))
            current_fields.clear()
            current_label_idx = -1

    while line_idx < len(lines):
        raw_line = lines[line_idx]
        line = raw_line.strip()
        line_idx += 1

        if not line:
            # Empty lines in multi-line field are preserved as newline
            if current_label_idx >= 0:
                current_field_name = LABEL_SPECS[current_label_idx][0]
                current_fields[current_field_name] += "\n"
            continue

        # Check for completion marker at end
        comp_match = COMPLETION_MARKER_RE.search(line)
        if comp_match:
            completion_marker = int(comp_match.group(1))
            continue

        # Check for new Section header
        sec_match = SECTION_RE.match(line)
        if sec_match:
            finalize_current_section()
            sec_num = int(sec_match.group(1))
            expected_sec_num = len(sections) + 1
            if sec_num != expected_sec_num:
                raise FirstParserError(
                    f"Non-contiguous section numbering: expected Section {expected_sec_num}, got Section {sec_num}",
                    code="validation",
                )
            current_section_ordinal = sec_num
            continue

        if current_section_ordinal is None:
            raise FirstParserError(f"Unexpected content before Section 1: '{line}'", code="validation")

        # Some NotebookLM replies number each canonical field heading (for example,
        # "1. Section Identity"). Treat that prefix as presentation only when the
        # remainder is itself a known field label; numbered prose inside a field
        # remains part of that field's value.
        label_line = line
        numbered_heading = re.match(r"^\d+[.)]\s+(.+)$", line)
        if numbered_heading:
            candidate = numbered_heading.group(1)
            if any(pattern.match(candidate) for _, pattern, _ in LABEL_SPECS):
                label_line = candidate

        # Check if line starts the next expected label in canonical order
        next_label_idx = current_label_idx + 1
        if next_label_idx < len(LABEL_SPECS):
            field_name, pattern, _ = LABEL_SPECS[next_label_idx]
            match = pattern.match(label_line)
            if match:
                current_label_idx = next_label_idx
                initial_val = match.group(1)
                current_fields[field_name] = initial_val
                continue

        # Check if line matches ANY other label out of order
        for idx, (f_name, pat, _) in enumerate(LABEL_SPECS):
            if idx != current_label_idx and pat.match(label_line):
                raise FirstParserError(
                    f"Label '{f_name}' out of canonical order or repeated in Section {current_section_ordinal}",
                    code="validation",
                )

        # Continuation of current multiline field
        if current_label_idx >= 0:
            current_field_name = LABEL_SPECS[current_label_idx][0]
            if current_fields[current_field_name]:
                current_fields[current_field_name] += "\n" + line
            else:
                current_fields[current_field_name] = line
        else:
            raise FirstParserError(f"Unrecognized line in Section {current_section_ordinal}: '{line}'", code="validation")

    finalize_current_section()

    if not sections:
        raise FirstParserError("No valid sections found in First response", code="validation")

    return ParsedFirstResponse(
        work_ordinal=parsed_work_ord,
        lecture_header=lecture_header,
        sections=sections,
        completion_marker=completion_marker,
    )
