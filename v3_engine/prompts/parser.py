import re
from typing import Any, Dict, List, Optional

ALLOWED_SCENE_TYPES = [
    "Process",
    "Comparison",
    "Accumulation",
    "Parallel Flow",
    "Time Evolution",
]

MAX_PROMPT_BYTES = 65536


class PromptParserError(Exception):
    """Raised when canonical step output cannot be structurally parsed."""
    pass


def split_lecture_segments(text: str) -> List[str]:
    """Splits text by consecutive Lecture: headers."""
    pattern = r"(?:^|\n)(?=Lecture:\s*)"
    parts = re.split(pattern, text.strip())
    return [p.strip() for p in parts if p.strip()]


def _extract_blocks(
    text: str,
    work_ordinal: int,
    expected_section_ordinals: List[int],
    step_name: str,
) -> List[Dict[str, Any]]:
    """Extracts Lec<X> (Sec<Y>): blocks for the given step."""
    pattern = rf"Lec{work_ordinal}\s*\(Sec(\d+)\)\s*:\s*\n?\"([^\"]*)\""
    matches = re.findall(pattern, text, re.DOTALL)

    if not matches:
        pattern_fallback = rf"Lec{work_ordinal}\s*\(Sec(\d+)\)\s*:\s*\n?(.*?)(?=(?:Lec{work_ordinal}\s*\(Sec|\Z))"
        alt_matches = re.findall(pattern_fallback, text, re.DOTALL)
        if not alt_matches:
            raise PromptParserError(f"No valid {step_name} blocks found for work ordinal {work_ordinal}.")
        cleaned_matches = []
        for sec_num, content in alt_matches:
            c = content.strip().strip('"')
            cleaned_matches.append((sec_num, c))
        matches = cleaned_matches

    found_ordinals = []
    blocks = []
    for sec_str, content in matches:
        sec_num = int(sec_str)
        found_ordinals.append(sec_num)
        blocks.append({
            "ordinal": sec_num,
            "text": content.strip(),
        })

    if found_ordinals != expected_section_ordinals:
        raise PromptParserError(
            f"{step_name} sections mismatch. Expected {expected_section_ordinals}, got {found_ordinals}."
        )

    return blocks


def parse_second_blocks(
    text: str,
    work_ordinal: int,
    expected_section_ordinals: List[int],
) -> List[Dict[str, Any]]:
    """Parses Second step placeholder blocks."""
    blocks = _extract_blocks(text, work_ordinal, expected_section_ordinals, "Second")
    for b in blocks:
        if not b["text"]:
            raise PromptParserError(f"Empty placeholder in Second block Sec{b['ordinal']}.")
    return blocks


def parse_third_blocks(
    text: str,
    work_ordinal: int,
    expected_section_ordinals: List[int],
) -> List[Dict[str, Any]]:
    """Parses Third step executable prompt blocks and verifies scene type."""
    blocks = _extract_blocks(text, work_ordinal, expected_section_ordinals, "Third")

    for b in blocks:
        body = b["text"]
        if not body:
            raise PromptParserError(f"Empty prompt in Third block Sec{b['ordinal']}.")

        if len(body.encode("utf-8")) > MAX_PROMPT_BYTES:
            raise PromptParserError(f"Third prompt Sec{b['ordinal']} exceeds {MAX_PROMPT_BYTES} bytes.")

        matched_scene_type: Optional[str] = None
        for st in ALLOWED_SCENE_TYPES:
            if body.startswith(st):
                matched_scene_type = st
                break

        if not matched_scene_type:
            raise PromptParserError(
                f"Third prompt Sec{b['ordinal']} has invalid scene type. "
                f"Must start with one of {ALLOWED_SCENE_TYPES}. Prompt begins with: '{body[:30]}'."
            )

        b["scene_type"] = matched_scene_type

    return blocks


def parse_execution_response(
    text: str,
    work_ordinal: int,
    expected_sections: List[Dict[str, Any]],
) -> Dict[str, Any]:
    """Parses Execution protocol response (ready or combined)."""
    trimmed = text.strip()
    if trimmed == "System Ready" or trimmed.startswith("System Ready"):
        return {"shape": "ready"}

    expected_ordinals = [s["ordinal_in_work"] for s in expected_sections]

    # Check for combined response containing three segments
    segments = split_lecture_segments(text)
    if len(segments) >= 3:
        try:
            second_blocks = parse_second_blocks(segments[1], work_ordinal, expected_ordinals)
            third_blocks = parse_third_blocks(segments[2], work_ordinal, expected_ordinals)
            return {
                "shape": "combined",
                "second_blocks": second_blocks,
                "third_blocks": third_blocks,
            }
        except PromptParserError:
            pass

    # Fallback to single text if only Second/Third present without multiple Lecture headers
    try:
        second_blocks = parse_second_blocks(text, work_ordinal, expected_ordinals)
        third_blocks = parse_third_blocks(text, work_ordinal, expected_ordinals)
        return {
            "shape": "combined",
            "second_blocks": second_blocks,
            "third_blocks": third_blocks,
        }
    except PromptParserError:
        pass

    raise PromptParserError(f"Execution response does not match ready or combined form. Content: '{trimmed[:100]}'")


def parse_continue_response(
    text: str,
    work_ordinal: int,
    expected_sections: List[Dict[str, Any]],
) -> Dict[str, Any]:
    """Parses Continue response (combined or first_only)."""
    expected_ordinals = [s["ordinal_in_work"] for s in expected_sections]

    segments = split_lecture_segments(text)
    if len(segments) >= 3:
        try:
            second_blocks = parse_second_blocks(segments[1], work_ordinal, expected_ordinals)
            third_blocks = parse_third_blocks(segments[2], work_ordinal, expected_ordinals)
            return {
                "shape": "combined",
                "second_blocks": second_blocks,
                "third_blocks": third_blocks,
            }
        except PromptParserError:
            pass

    # Check for first-only response
    if "Section 1:" in text or "Section" in text:
        if f"Lec{work_ordinal} (Sec" not in text:
            return {"shape": "first_only"}

    raise PromptParserError(f"Continue response does not match combined or first_only form. Content: '{text[:100]}'")
