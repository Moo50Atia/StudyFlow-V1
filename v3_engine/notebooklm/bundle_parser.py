"""Parser for NotebookLM direct protocol combined lesson envelope (Spec 016).

Parses combined envelope containing === FIRST ===, === SECOND ===, === THIRD ===, and === END ===
into typed First, Second, and Third artifacts, preserving all educational fields, Arabic, LaTeX,
preparation perspective map fields, and scene types.
"""
from __future__ import annotations

from dataclasses import dataclass, field
import re
from typing import Any, Dict, List, Optional, Tuple

from v3_engine.lessons.first_parser import (
    CANONICAL_FIELD_LABELS,
    LABEL_SPECS,
    ParsedFirstResponse,
    ParsedSection as ParsedFirstSection,
    parse_first_response,
)

# 13 required fields in Second Prepared Perspective Map
PREPARED_PERSPECTIVE_MAP_FIELDS = [
    ("primary_learning_objective", re.compile(r"^(?:[•\-*]\s*)?Primary\s+learning\s+objective\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("scene_type", re.compile(r"^(?:[•\-*]\s*)?Scene\s+Type\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("baseline_objects_and_state", re.compile(r"^(?:[•\-*]\s*)?Baseline\s+objects\s+and\s+state\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("time_perspective", re.compile(r"^(?:[•\-*]\s*)?Time\s+Perspective\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("custom_perspectives", re.compile(r"^(?:[•\-*]\s*)?Custom\s+Perspectives\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("governing_behavior", re.compile(r"^(?:[•\-*]\s*)?Governing\s+Behavior\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("learner_investigations", re.compile(r"^(?:[•\-*]\s*)?Learner\s+Investigations\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("controls", re.compile(r"^(?:[•\-*]\s*)?Controls\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("linked_views", re.compile(r"^(?:[•\-*]\s*)?Linked\s+Views\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("visual_learning_goal", re.compile(r"^(?:[•\-*]\s*)?Visual\s+Learning\s+Goal\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("coverage", re.compile(r"^(?:[•\-*]\s*)?Coverage\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("acceptance_checks", re.compile(r"^(?:[•\-*]\s*)?Acceptance\s+Checks\s*[:.]\s*(.*)$", re.IGNORECASE)),
    ("evidence_gaps", re.compile(r"^(?:[•\-*]\s*)?Evidence\s+Gaps\s*[:.]\s*(.*)$", re.IGNORECASE)),
]

MAX_REPLY_BYTES = 1024 * 1024  # 1 MiB
MAX_THIRD_PROMPT_BYTES = 65536


class BundleParserError(Exception):
    def __init__(self, message: str, code: str = "validation"):
        super().__init__(message)
        self.code = code


@dataclass
class ParsedSecondSection:
    ordinal: int
    placeholder: str
    perspective_map: Dict[str, str]


@dataclass
class ParsedSecondArtifact:
    lecture_header: str
    sections: List[ParsedSecondSection]


@dataclass
class ParsedThirdSection:
    ordinal: int
    scene_type: str
    prompt_body: str


@dataclass
class ParsedThirdArtifact:
    lecture_header: str
    sections: List[ParsedThirdSection]


@dataclass
class ParsedCombinedBundle:
    work_ordinal: int
    terminal_marker_ordinal: int
    lecture_title: str
    first: ParsedFirstResponse
    second: ParsedSecondArtifact
    third: ParsedThirdArtifact
    raw_first: str
    raw_second: str
    raw_third: str


LEC_SEC_HEADER_RE = re.compile(r"^Lec\s*W?\s*([0-9]+)\s*\(\s*Sec\s*([0-9]+)\s*\)\s*:\s*(.*)$", re.IGNORECASE)
LECTURE_HEADER_RE = re.compile(r"^Lecture\s*:\s*(.+)$", re.IGNORECASE)
TERMINAL_MARKER_RE = re.compile(r"✅\s*Lesson\s*(?:\[?([0-9]+)\]?\s*)?Completed\.\s*Awaiting\s*['\"]Continue['\"]\s*for\s*the\s*next\s*lesson\.\s*$", re.IGNORECASE)


def parse_combined_bundle(raw_text: str, expected_work_ordinal: int) -> ParsedCombinedBundle:
    if not raw_text or not raw_text.strip():
        raise BundleParserError("Combined response is empty", code="validation")

    raw_bytes_len = len(raw_text.encode("utf-8"))
    if raw_bytes_len > MAX_REPLY_BYTES:
        raise BundleParserError(f"Combined response exceeds 1 MiB limit ({raw_bytes_len} bytes)", code="validation")

    normalized = raw_text.replace("\r\n", "\n").replace("\r", "\n")

    # Split on standalone delimiter lines
    d_first = "=== FIRST ==="
    d_second = "=== SECOND ==="
    d_third = "=== THIRD ==="
    d_end = "=== END ==="

    pos_first = normalized.find(d_first)
    pos_second = normalized.find(d_second)
    pos_third = normalized.find(d_third)
    pos_end = normalized.find(d_end)

    if pos_first == -1 or pos_second == -1 or pos_third == -1 or pos_end == -1:
        raise BundleParserError("Missing required envelope delimiter(s)", code="validation")

    if not (pos_first < pos_second < pos_third < pos_end):
        raise BundleParserError("Envelope delimiters out of required order", code="validation")

    # Check for duplicate delimiters
    if (
        normalized.count(d_first) != 1
        or normalized.count(d_second) != 1
        or normalized.count(d_third) != 1
        or normalized.count(d_end) != 1
    ):
        raise BundleParserError("Duplicate delimiter line detected", code="validation")

    # Extract slices
    first_part = normalized[pos_first + len(d_first):pos_second].strip()
    second_part = normalized[pos_second + len(d_second):pos_third].strip()
    third_part = normalized[pos_third + len(d_third):pos_end].strip()
    after_end = normalized[pos_end + len(d_end):].strip()

    if not first_part or not second_part or not third_part:
        raise BundleParserError("Envelope contains empty artifact(s)", code="validation")

    # Parse terminal marker
    marker_match = TERMINAL_MARKER_RE.match(after_end)
    if not marker_match:
        raise BundleParserError(f"Invalid or missing terminal completion marker: '{after_end}'", code="validation")

    marker_omitted_number = marker_match.group(1) is None
    terminal_ordinal = expected_work_ordinal if marker_omitted_number else int(marker_match.group(1))
    if terminal_ordinal < 1:
        raise BundleParserError("Terminal completion marker must contain a positive display number", code="validation")

    # 1. Parse First artifact using canonical first parser
    first_parsed = _parse_first_artifact(first_part, expected_work_ordinal)

    # Extract lecture title from First artifact
    lecture_title = first_parsed.lecture_header
    if lecture_title.lower().startswith("lecture:"):
        lecture_title = lecture_title[len("lecture:"):].strip()

    # 2. Parse Second artifact
    second_parsed = _parse_second_artifact(second_part, expected_work_ordinal, expected_section_count=len(first_parsed.sections))

    # 3. Parse Third artifact
    third_parsed = _parse_third_artifact(third_part, expected_work_ordinal, expected_section_count=len(first_parsed.sections))
    if marker_omitted_number and (
        not re.match(rf"^W{expected_work_ordinal}\s*\|\s*\S", lecture_title, re.IGNORECASE)
        or second_parsed.lecture_header != lecture_title
        or third_parsed.lecture_header != lecture_title
    ):
        raise BundleParserError("Unnumbered completion requires three identical headers for the claimed work item", code="validation")

    return ParsedCombinedBundle(
        work_ordinal=expected_work_ordinal,
        terminal_marker_ordinal=terminal_ordinal,
        lecture_title=lecture_title,
        first=first_parsed,
        second=second_parsed,
        third=third_parsed,
        raw_first=first_part,
        raw_second=second_part,
        raw_third=third_part,
    )


def _parse_first_artifact(text: str, expected_work_ordinal: int) -> ParsedFirstResponse:
    # If the text doesn't have completion marker, parse_first_response handles it as optional
    # First response in combined envelope might have "Lecture: W1 | Title" or "Lecture: Title"
    lines = text.strip().split("\n")
    first_non_empty = ""
    for line in lines:
        if line.strip():
            first_non_empty = line.strip()
            break

    # If header doesn't have "W{ordinal} |", normalize for first_parser compatibility
    match = LECTURE_HEADER_RE.match(first_non_empty)
    if not match:
        raise BundleParserError(f"First artifact must begin with Lecture header, got: '{first_non_empty}'", code="validation")

    # Check if header contains work ordinal
    header_val = match.group(1).strip()
    if not re.match(r"^W[0-9]+\s*\|", header_val, re.IGNORECASE):
        # Prefix with expected ordinal for standard first_parser
        normalized_text = re.sub(
            r"^Lecture\s*:\s*(.+)$",
            f"Lecture: W{expected_work_ordinal} | \\1",
            text.strip(),
            flags=re.IGNORECASE | re.MULTILINE,
            count=1,
        )
    else:
        normalized_text = text.strip()

    try:
        return parse_first_response(normalized_text, expected_work_ordinal)
    except Exception as e:
        raise BundleParserError(str(e), code="validation") from e


def _parse_second_artifact(text: str, expected_work_ordinal: int, expected_section_count: int) -> ParsedSecondArtifact:
    lines = text.strip().split("\n")
    lecture_header = ""
    line_idx = 0

    while line_idx < len(lines):
        line = lines[line_idx].strip()
        line_idx += 1
        if not line:
            continue
        match = LECTURE_HEADER_RE.match(line)
        if match:
            lecture_header = match.group(1).strip()
            break
        else:
            raise BundleParserError(f"Second artifact must begin with Lecture header, got '{line}'", code="validation")

    sections: List[ParsedSecondSection] = []
    current_sec_ordinal: Optional[int] = None
    current_placeholder: Optional[str] = None
    current_fields: Dict[str, str] = {}
    in_perspective_map = False
    current_field_idx = -1

    def finalize_second_section():
        nonlocal current_sec_ordinal, current_placeholder, current_fields, in_perspective_map, current_field_idx
        if current_sec_ordinal is not None:
            if not current_placeholder:
                raise BundleParserError(f"Second section {current_sec_ordinal} missing placeholder", code="validation")
            if len(current_fields) != len(PREPARED_PERSPECTIVE_MAP_FIELDS):
                missing = [name for name, _ in PREPARED_PERSPECTIVE_MAP_FIELDS if name not in current_fields]
                raise BundleParserError(
                    f"Second section {current_sec_ordinal} missing Prepared Perspective Map field(s): {missing}",
                    code="validation",
                )
            for f_name, _ in PREPARED_PERSPECTIVE_MAP_FIELDS:
                val = current_fields.get(f_name, "")
                if not val.strip():
                    raise BundleParserError(
                        f"Second section {current_sec_ordinal} field '{f_name}' cannot be empty",
                        code="validation",
                    )
            sections.append(ParsedSecondSection(
                ordinal=current_sec_ordinal,
                placeholder=current_placeholder,
                perspective_map=dict(current_fields),
            ))
            current_fields.clear()
            current_placeholder = None
            in_perspective_map = False
            current_field_idx = -1

    while line_idx < len(lines):
        line = lines[line_idx].strip()
        line_idx += 1
        if not line:
            continue

        header_match = LEC_SEC_HEADER_RE.match(line)
        if header_match:
            finalize_second_section()
            lec_num = int(header_match.group(1))
            sec_num = int(header_match.group(2))
            if sec_num != len(sections) + 1:
                raise BundleParserError(
                    f"Second section numbering non-contiguous: expected Sec{len(sections) + 1}, got Sec{sec_num}",
                    code="validation",
                )
            current_sec_ordinal = sec_num
            header_placeholder = (header_match.group(3) or "").strip()
            if header_placeholder:
                if (header_placeholder.startswith('"') and header_placeholder.endswith('"')) or (
                    header_placeholder.startswith("'") and header_placeholder.endswith("'")
                ):
                    current_placeholder = header_placeholder[1:-1].strip()
                else:
                    raise BundleParserError(
                        f"Second section {sec_num} inline placeholder must be quoted",
                        code="validation",
                    )
            continue

        if current_sec_ordinal is None:
            raise BundleParserError(f"Unexpected content before LecW (SecY) header: '{line}'", code="validation")

        if line.lower().startswith("prepared perspective map"):
            if current_placeholder is None:
                raise BundleParserError(f"Missing quoted placeholder before Prepared Perspective Map in section {current_sec_ordinal}", code="validation")
            in_perspective_map = True
            continue

        if not in_perspective_map:
            if current_placeholder is None and (
                (line.startswith('"') and line.endswith('"'))
                or (line.startswith("'") and line.endswith("'"))
            ):
                current_placeholder = line[1:-1].strip()
                continue
            raise BundleParserError(
                f"Unexpected content before Prepared Perspective Map in section {current_sec_ordinal}",
                code="validation",
            )

        if in_perspective_map:
            # Check if line matches any field
            matched_field = False
            for idx, (f_name, pat) in enumerate(PREPARED_PERSPECTIVE_MAP_FIELDS):
                m = pat.match(line)
                if m:
                    current_field_idx = idx
                    current_fields[f_name] = m.group(1).strip()
                    matched_field = True
                    break
            if matched_field:
                continue

            # Continuation of previous field
            if current_field_idx >= 0:
                f_name = PREPARED_PERSPECTIVE_MAP_FIELDS[current_field_idx][0]
                current_fields[f_name] += "\n" + line
                continue

            raise BundleParserError(f"Unrecognized line in Prepared Perspective Map for section {current_sec_ordinal}: '{line}'", code="validation")

    finalize_second_section()

    if len(sections) != expected_section_count:
        raise BundleParserError(
            f"Section count mismatch: First had {expected_section_count} sections, Second had {len(sections)}",
            code="validation",
        )

    return ParsedSecondArtifact(
        lecture_header=lecture_header,
        sections=sections,
    )


def _parse_third_artifact(text: str, expected_work_ordinal: int, expected_section_count: int) -> ParsedThirdArtifact:
    lines = text.strip().split("\n")
    lecture_header = ""
    line_idx = 0

    while line_idx < len(lines):
        line = lines[line_idx].strip()
        line_idx += 1
        if not line:
            continue
        match = LECTURE_HEADER_RE.match(line)
        if match:
            lecture_header = match.group(1).strip()
            break
        else:
            raise BundleParserError(f"Third artifact must begin with Lecture header, got '{line}'", code="validation")

    sections: List[ParsedThirdSection] = []
    current_sec_ordinal: Optional[int] = None
    prompt_lines: List[str] = []
    in_prompt = False

    def finalize_third_section():
        nonlocal current_sec_ordinal, prompt_lines, in_prompt
        if current_sec_ordinal is not None:
            if not prompt_lines:
                raise BundleParserError(f"Third section {current_sec_ordinal} has empty prompt", code="validation")

            raw_prompt = "\n".join(prompt_lines).strip()
            # Must start with quote and end with quote
            if not (raw_prompt.startswith('"') and raw_prompt.endswith('"')):
                raise BundleParserError(f"Third section {current_sec_ordinal} prompt must be enclosed in quotes", code="validation")

            prompt_body = raw_prompt[1:-1].strip()
            prompt_bytes_len = len(prompt_body.encode("utf-8"))
            if prompt_bytes_len > MAX_THIRD_PROMPT_BYTES:
                raise BundleParserError(
                    f"Third section {current_sec_ordinal} prompt exceeds {MAX_THIRD_PROMPT_BYTES} bytes ({prompt_bytes_len} bytes)",
                    code="validation",
                )

            # The provider owns the scene taxonomy. Preserve any nonempty label
            # returned by NotebookLM instead of imposing an application whitelist.
            first_prompt_line = prompt_body.split("\n", 1)[0].strip()
            label_line = re.sub(r"^[•*\-]\s*", "", first_prompt_line).strip()
            scene_type_match = re.match(r"^Scene\s+Type\s*[:.]\s*(.+)$", label_line, re.IGNORECASE)
            if scene_type_match:
                detected_type = scene_type_match.group(1).strip()
            else:
                detected_type = label_line.split(":", 1)[0].strip()
            if not detected_type:
                raise BundleParserError(
                    f"Third section {current_sec_ordinal} prompt must provide a nonempty scene type label",
                    code="validation",
                )

            sections.append(ParsedThirdSection(
                ordinal=current_sec_ordinal,
                scene_type=detected_type,
                prompt_body=prompt_body,
            ))
            current_sec_ordinal = None
            prompt_lines = []
            in_prompt = False

    while line_idx < len(lines):
        line = lines[line_idx]
        stripped = line.strip()
        line_idx += 1

        if not stripped and not in_prompt:
            continue

        header_match = LEC_SEC_HEADER_RE.match(stripped)
        if header_match:
            finalize_third_section()
            sec_num = int(header_match.group(2))
            if sec_num != len(sections) + 1:
                raise BundleParserError(
                    f"Third section numbering non-contiguous: expected Sec{len(sections) + 1}, got Sec{sec_num}",
                    code="validation",
                )
            current_sec_ordinal = sec_num
            in_prompt = False
            # NotebookLM may place the quoted prompt on the same line as the
            # Lec/Sec label. The label's trailing capture is the prompt body;
            # ignoring it makes an otherwise complete section look empty.
            inline_prompt = header_match.group(3).strip()
            if inline_prompt:
                if not inline_prompt.startswith('"'):
                    raise BundleParserError(
                        f"Third section {current_sec_ordinal} prompt must begin with quote",
                        code="validation",
                    )
                prompt_lines.append(inline_prompt)
                in_prompt = True
            continue

        if current_sec_ordinal is None:
            raise BundleParserError(f"Unexpected content before LecW (SecY) header: '{stripped}'", code="validation")

        if not in_prompt:
            if stripped.startswith('"'):
                in_prompt = True
                prompt_lines.append(stripped)
            else:
                raise BundleParserError(f"Third section {current_sec_ordinal} prompt must begin with quote, got '{stripped}'", code="validation")
        else:
            prompt_lines.append(line)

    finalize_third_section()

    if len(sections) != expected_section_count:
        raise BundleParserError(
            f"Section count mismatch: First had {expected_section_count} sections, Third had {len(sections)}",
            code="validation",
        )

    return ParsedThirdArtifact(
        lecture_header=lecture_header,
        sections=sections,
    )
