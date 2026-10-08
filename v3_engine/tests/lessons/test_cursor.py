"""Tests for LessonCursor in Spec 009."""
import unittest
from pathlib import Path

from v3_engine.lessons.cursor import CursorPhase, LessonCursorManager
from v3_engine.lessons.first_parser import FirstParserError

FIXTURES_DIR = Path(__file__).parent / "fixtures"

class TestLessonCursor(unittest.TestCase):
    def setUp(self):
        with open(FIXTURES_DIR / "first_valid.txt", "r", encoding="utf-8") as f:
            self.valid_answer = f.read()

        self.manager = LessonCursorManager()

    def test_can_continue_only_when_ready(self):
        self.assertTrue(self.manager.can_continue(phase=CursorPhase.READY_TO_CONTINUE, work_ordinal=1, work_count=2))
        self.assertFalse(self.manager.can_continue(phase=CursorPhase.AWAITING_010, work_ordinal=1, work_count=2))
        self.assertFalse(self.manager.can_continue(phase=CursorPhase.COMPLETE, work_ordinal=2, work_count=2))
        self.assertFalse(self.manager.can_continue(phase=CursorPhase.READY_TO_CONTINUE, work_ordinal=2, work_count=2))

    def test_build_continue_query_payload(self):
        payload = self.manager.build_continue_query(
            next_work_ordinal=2,
            conversation_id="conv-final-w1",
        )
        self.assertEqual(payload["query_text"], "Continue")
        self.assertEqual(payload["conversation_in"], "conv-final-w1")
        self.assertEqual(payload["ordinal"], 2)

    def test_parse_continue_reply_success(self):
        # Suppose W2 answer
        w2_answer = self.valid_answer.replace("W1", "W2")
        parsed = self.manager.parse_continue_reply(raw_text=w2_answer, expected_next_ordinal=2)
        self.assertEqual(parsed.work_ordinal, 2)
        self.assertEqual(len(parsed.sections), 2)

    def test_parse_continue_reply_mismatched_ordinal_raises(self):
        with self.assertRaises(FirstParserError):
            # Attempt to parse W1 answer when expecting W2
            self.manager.parse_continue_reply(raw_text=self.valid_answer, expected_next_ordinal=2)

if __name__ == "__main__":
    unittest.main()
