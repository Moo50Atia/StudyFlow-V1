"""Lesson cursor lifecycle management for Spec 009."""
from enum import Enum
from typing import Optional, Dict, Any

from v3_engine.lessons.first_parser import parse_first_response, ParsedFirstResponse


class CursorPhase(str, Enum):
    FIRST_PENDING = "first_pending"
    AWAITING_010 = "awaiting_010"
    READY_TO_CONTINUE = "ready_to_continue"
    CONTINUE_PENDING = "continue_pending"
    CONTINUE_UNCERTAIN = "continue_uncertain"
    COMPLETE = "complete"
    BLOCKED = "blocked"


class LessonCursorState:
    def __init__(
        self,
        current_work_ordinal: int,
        phase: CursorPhase,
        version: int,
        conversation_id: Optional[str] = None,
    ):
        self.current_work_ordinal = current_work_ordinal
        self.phase = phase
        self.version = version
        self.conversation_id = conversation_id


class LessonCursorManager:
    """Manages Continuation queries, cursor states, and continuation parsing."""

    def can_continue(self, phase: CursorPhase, work_ordinal: int, work_count: int) -> bool:
        """Determines if the lesson cursor can perform a Continue turn."""
        return phase == CursorPhase.READY_TO_CONTINUE and work_ordinal < work_count

    def build_continue_query(self, next_work_ordinal: int, conversation_id: str) -> Dict[str, Any]:
        """Constructs query payload for literal Continue turn."""
        return {
            "query_text": "Continue",
            "conversation_in": conversation_id,
            "ordinal": next_work_ordinal,
        }

    def parse_continue_reply(self, raw_text: str, expected_next_ordinal: int) -> ParsedFirstResponse:
        """Parses continuation reply extracting First sections for next work ordinal."""
        return parse_first_response(raw_text=raw_text, expected_work_ordinal=expected_next_ordinal)
