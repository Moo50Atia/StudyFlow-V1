"""Parser for NotebookLM structural map (Divide prompt output) per Spec 009."""
import re
from dataclasses import dataclass
from typing import List, Optional, Dict, Any, Set

@dataclass
class ParsedLessonNode:
    kind: str  # chapter | mini_chapter | lesson
    position_path: str  # e.g. "001.002"
    parent_position_path: Optional[str]
    title: str
    ordinal_in_parent: int
    work_ordinal: Optional[int] = None

class MapParserError(Exception):
    def __init__(self, message: str, code: str = "validation"):
        super().__init__(message)
        self.code = code

LINE_PATTERN = re.compile(
    r"^\s*(?:[-*]\s+)?(Chapter|Mini\s+Chapter|Lesson)(?:\s+(?:[0-9]+(?:\.[0-9]+)*|[IVXLCDMivxlcdm]+))?\s*(?::|-|=>)\s*(.+)$",
    re.IGNORECASE,
)

def parse_divide_response(raw_text: str) -> List[ParsedLessonNode]:
    """Parse raw structural Divide response into ordered lesson nodes."""
    if not raw_text or not raw_text.strip():
        raise MapParserError("Divide response is empty", code="validation")

    lines = raw_text.strip().splitlines()
    nodes: List[ParsedLessonNode] = []

    current_chapter: Optional[ParsedLessonNode] = None
    current_mini: Optional[ParsedLessonNode] = None

    chapter_count = 0
    mini_count_in_chapter = 0
    lesson_count_in_mini = 0
    lesson_count_in_chapter = 0

    chapter_children: Dict[str, List[ParsedLessonNode]] = {}
    mini_children: Dict[str, List[ParsedLessonNode]] = {}

    for raw_line in lines:
        line = raw_line.strip()
        if not line:
            continue

        if line.startswith("```") or line.endswith("```"):
            raise MapParserError("Code fences are prohibited in Divide output", code="validation")

        match = LINE_PATTERN.match(line)
        if not match:
            raise MapParserError(f"Prose or invalid line format: '{line}'", code="validation")

        raw_kind, title = match.groups()
        title = title.strip()
        if not title or len(title) > 255:
            raise MapParserError(f"Invalid title length: '{title}'", code="validation")

        kind_norm = raw_kind.lower()
        if "mini" in kind_norm:
            kind = "mini_chapter"
        elif "chapter" in kind_norm:
            kind = "chapter"
        elif "lesson" in kind_norm:
            kind = "lesson"
        else:
            raise MapParserError(f"Unrecognized node kind: '{raw_kind}'", code="validation")

        if kind == "chapter":
            # Check if previous chapter had children
            if current_chapter is not None and not chapter_children.get(current_chapter.position_path):
                raise MapParserError(f"Chapter '{current_chapter.title}' has no children", code="validation")

            chapter_count += 1
            pos_path = f"{chapter_count:03d}"
            node = ParsedLessonNode(
                kind="chapter",
                position_path=pos_path,
                parent_position_path=None,
                title=title,
                ordinal_in_parent=chapter_count,
            )
            nodes.append(node)
            current_chapter = node
            current_mini = None
            mini_count_in_chapter = 0
            lesson_count_in_chapter = 0
            chapter_children[pos_path] = []

        elif kind == "mini_chapter":
            if current_chapter is None:
                raise MapParserError(f"Orphan Mini Chapter '{title}' without parent Chapter", code="validation")

            mini_count_in_chapter += 1
            pos_path = f"{current_chapter.position_path}.{mini_count_in_chapter:03d}"
            node = ParsedLessonNode(
                kind="mini_chapter",
                position_path=pos_path,
                parent_position_path=current_chapter.position_path,
                title=title,
                ordinal_in_parent=mini_count_in_chapter,
            )
            nodes.append(node)
            current_mini = node
            lesson_count_in_mini = 0
            chapter_children[current_chapter.position_path].append(node)
            mini_children[pos_path] = []

        elif kind == "lesson":
            if current_chapter is None:
                raise MapParserError(f"Orphan Lesson '{title}' without parent Chapter", code="validation")

            if current_mini is not None:
                lesson_count_in_mini += 1
                pos_path = f"{current_mini.position_path}.{lesson_count_in_mini:03d}"
                node = ParsedLessonNode(
                    kind="lesson",
                    position_path=pos_path,
                    parent_position_path=current_mini.position_path,
                    title=title,
                    ordinal_in_parent=lesson_count_in_mini,
                )
                mini_children[current_mini.position_path].append(node)
            else:
                lesson_count_in_chapter += 1
                pos_path = f"{current_chapter.position_path}.{lesson_count_in_chapter:03d}"
                node = ParsedLessonNode(
                    kind="lesson",
                    position_path=pos_path,
                    parent_position_path=current_chapter.position_path,
                    title=title,
                    ordinal_in_parent=lesson_count_in_chapter,
                )
                chapter_children[current_chapter.position_path].append(node)

            nodes.append(node)

    if not nodes:
        raise MapParserError("No structural nodes found in Divide response", code="validation")

    # Check that last chapter had children
    if current_chapter is not None and not chapter_children.get(current_chapter.position_path):
        raise MapParserError(f"Chapter '{current_chapter.title}' has no children", code="validation")

    # Assign work ordinals W1..Wn in depth-first order
    current_work_ordinal = 1
    for node in nodes:
        if node.kind == "lesson":
            node.work_ordinal = current_work_ordinal
            current_work_ordinal += 1
        elif node.kind == "mini_chapter":
            # If mini chapter has no lesson children, promote to work item
            if not mini_children.get(node.position_path):
                node.work_ordinal = current_work_ordinal
                current_work_ordinal += 1

    if current_work_ordinal == 1:
        raise MapParserError("Map contains no work items (lessons or promoted mini chapters)", code="validation")

    return nodes
