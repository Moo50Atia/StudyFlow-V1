"""Tests for LessonProcessor in Spec 009."""
import unittest
from unittest.mock import AsyncMock, MagicMock
from pathlib import Path

from v3_engine.lessons.lesson_processor import LessonProcessor
from v3_engine.lessons.first_parser import FirstParserError

FIXTURES_DIR = Path(__file__).parent / "fixtures"

class TestLessonProcessor(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.adapter = MagicMock()
        self.adapter.query_once_by_binding = AsyncMock()

        self.laravel_client = MagicMock()
        self.laravel_client.get_activation_context = AsyncMock()
        self.laravel_client.get_lesson_context = AsyncMock(return_value={"data": {"work_path": "Work Item 1"}})
        self.laravel_client.claim_query = AsyncMock()
        self.laravel_client.post_query_result = AsyncMock()

        self.processor = LessonProcessor(
            adapter=self.adapter,
            laravel_client=self.laravel_client,
        )

        with open(FIXTURES_DIR / "first_valid.txt", "r", encoding="utf-8") as f:
            self.valid_first_answer = f.read()

    async def test_prepare_first_lesson_success(self):
        self.laravel_client.get_activation_context.return_value = {
            "data": {
                "case_id": "case-1",
                "binding_ref": "bind-1",
                "map_state": "ready",
                "conversation_ref": "conv-coverage-out",
            }
        }
        self.laravel_client.get_lesson_context.return_value = {
            "data": {"work_path": "Chapter 1 / Kinematics / Motion", "phase": "first_pending", "section_refs": []}
        }
        self.laravel_client.claim_query.return_value = {
            "data": {
                "query_ref": "q-first-1",
                "disposition": "send_now",
            }
        }
        self.adapter.query_once_by_binding.return_value = {
            "answer": self.valid_first_answer,
            "conversation_id": "conv-first-out",
        }
        self.laravel_client.post_query_result.return_value = {
            "data": {
                "query_ref": "q-first-1",
                "map_ref": "map-1",
                "cursor_version": 2,
                "state": "recorded",
            }
        }

        res = await self.processor.prepare_first_lesson(
            case_id="case-1",
            activation_id="act-1",
            binding_ref="bind-1",
            work_ordinal=1,
            work_path="Kinematics / Motion",
        )

        self.assertEqual(res["status"], "ready")
        self.assertEqual(res["cursor_phase"], "awaiting_010")
        self.assertEqual(res["cursor_version"], 2)
        self.assertEqual(res["section_count"], 2)

        # Verify envelope in claim call
        claim_call = self.laravel_client.claim_query.call_args[1]
        self.assertEqual(claim_call["kind"], "first")
        self.assertEqual(claim_call["ordinal"], 1)
        self.assertIn("TARGET WORK ITEM v1\nW1 | Kinematics / Motion", claim_call["query_text"])
        self.assertEqual(claim_call["conversation_in"], "conv-coverage-out")
        from v3_engine.lessons.canonical_prompts import FIRST_PROMPT
        from v3_engine.lessons.first_parser import CANONICAL_FIELD_LABELS
        sent_query = claim_call["query_text"]
        self.assertIn("OUTPUT TRANSPORT FORMAT v1", sent_query)
        self.assertIn("Do not use Markdown headings, bold labels, horizontal rules, or code fences.", sent_query)
        self.assertIn("Section 1:\n", sent_query)
        for label in CANONICAL_FIELD_LABELS:
            self.assertIn(label + ":\n", sent_query)
        self.assertTrue(sent_query.endswith("CANONICAL FIRST BODY\n" + FIRST_PROMPT))
        self.adapter.query_once_by_binding.assert_awaited_once_with(
            case_id="case-1",
            binding_ref="bind-1",
            query_text=claim_call["query_text"],
            conversation_id="conv-coverage-out",
        )

        # Verify parsed payload posted
        post_call = self.laravel_client.post_query_result.call_args[1]
        self.assertEqual(post_call["query_ref"], "q-first-1")
        self.assertEqual(post_call["conversation_out"], "conv-first-out")
        self.assertEqual(len(post_call["parsed"]["sections"]), 2)

    async def test_prepare_first_lesson_recorded_disposition(self):
        self.laravel_client.get_activation_context.return_value = {
            "data": {
                "case_id": "case-1",
                "binding_ref": "bind-1",
                "map_state": "ready",
                "conversation_ref": "conv-1",
            }
        }
        self.laravel_client.get_lesson_context.return_value = {
            "data": {"work_path": "Chapter 1 / Lesson 1", "phase": "awaiting_010", "section_refs": ["sec-1", "sec-2"], "cursor_version": 2}
        }
        self.laravel_client.claim_query.return_value = {
            "data": {
                "query_ref": "q-first-1",
                "disposition": "recorded",
            }
        }

        res = await self.processor.prepare_first_lesson(
            case_id="case-1",
            activation_id="act-1",
            binding_ref="bind-1",
            work_ordinal=1,
        )

        self.assertEqual(res["status"], "ready")
        self.assertEqual(res["cursor_phase"], "awaiting_010")
        self.assertEqual(res["section_count"], 2)
        self.adapter.query_once_by_binding.assert_not_called()

    async def test_prepare_first_lesson_missing_conversation_id_blocks(self):
        self.laravel_client.get_activation_context.return_value = {
            "data": {"case_id": "case-1", "binding_ref": "bind-1", "map_state": "ready"}
        }
        self.laravel_client.get_lesson_context.return_value = {"data": {"work_path": "Chapter 1 / Lesson 1"}}
        self.laravel_client.claim_query.return_value = {
            "data": {"query_ref": "q-1", "disposition": "send_now"}
        }
        self.adapter.query_once_by_binding.return_value = {
            "answer": self.valid_first_answer,
            "conversation_id": None,  # Missing
        }

        res = await self.processor.prepare_first_lesson(
            case_id="case-1",
            activation_id="act-1",
            binding_ref="bind-1",
            work_ordinal=1,
        )

        self.assertEqual(res["status"], "uncertain")
        self.assertEqual(res["error_code"], "lesson_query_uncertain")

    async def test_prepare_first_lesson_malformed_response_blocks(self):
        self.laravel_client.get_activation_context.return_value = {
            "data": {"case_id": "case-1", "binding_ref": "bind-1", "map_state": "ready"}
        }
        self.laravel_client.get_lesson_context.return_value = {"data": {"work_path": "Chapter 1 / Lesson 1"}}
        self.laravel_client.claim_query.return_value = {
            "data": {"query_ref": "q-1", "disposition": "send_now"}
        }
        self.adapter.query_once_by_binding.return_value = {
            "answer": "Not a valid lecture output",
            "conversation_id": "conv-bad",
        }

        res = await self.processor.prepare_first_lesson(
            case_id="case-1",
            activation_id="act-1",
            binding_ref="bind-1",
            work_ordinal=1,
        )

        self.assertEqual(res["status"], "blocked")
        self.assertEqual(res["error_code"], "validation")

    async def test_prepare_first_lesson_provider_error_blocks(self):
        self.laravel_client.get_activation_context.return_value = {
            "data": {"case_id": "case-1", "binding_ref": "bind-1", "map_state": "ready"}
        }
        self.laravel_client.get_lesson_context.return_value = {"data": {"work_path": "Chapter 1 / Lesson 1"}}
        self.laravel_client.claim_query.return_value = {
            "data": {"query_ref": "q-1", "disposition": "send_now"}
        }
        self.adapter.query_once_by_binding.side_effect = RuntimeError("MCP connection timed out")

        res = await self.processor.prepare_first_lesson(
            case_id="case-1",
            activation_id="act-1",
            binding_ref="bind-1",
            work_ordinal=1,
        )

        self.assertEqual(res["status"], "uncertain")
        self.assertEqual(res["error_code"], "lesson_query_uncertain")

    async def test_prepare_continuation_lesson_success(self):
        self.laravel_client.get_lesson_context.return_value = {
            "data": {
                "work_path": "Chapter 1 / Kinematics / Motion Part 2",
                "phase": "ready_to_continue",
                "conversation_ref": "conv-w1-out",
                "cursor_version": 2,
            }
        }
        self.laravel_client.claim_query.return_value = {
            "data": {
                "query_ref": "q-continue-2",
                "disposition": "send_now",
            }
        }
        w2_answer = self.valid_first_answer.replace("W1", "W2")
        self.adapter.query_once_by_binding.return_value = {
            "answer": w2_answer,
            "conversation_id": "conv-continue-2-out",
        }
        self.laravel_client.post_query_result.return_value = {
            "data": {
                "query_ref": "q-continue-2",
                "map_ref": "map-1",
                "cursor_version": 3,
                "state": "recorded",
            }
        }

        res = await self.processor.prepare_continuation_lesson(
            case_id="case-1",
            activation_id="act-1",
            binding_ref="bind-1",
            work_ordinal=2,
        )

        self.assertEqual(res["status"], "ready")
        self.assertEqual(res["cursor_phase"], "awaiting_010")
        self.assertEqual(res["cursor_version"], 3)
        self.assertEqual(res["section_count"], 2)

        # Verify claim call parameters for Continue query
        claim_call = self.laravel_client.claim_query.call_args[1]
        self.assertEqual(claim_call["kind"], "continue")
        self.assertEqual(claim_call["ordinal"], 2)
        self.assertEqual(claim_call["query_text"], "Continue")
        self.assertEqual(claim_call["conversation_in"], "conv-w1-out")

    async def test_prepare_continuation_lesson_reconciles_on_post_result_timeout(self):
        self.laravel_client.get_lesson_context.side_effect = [
            # Initial call before send
            {
                "data": {
                    "work_path": "Chapter 1 / Kinematics / Motion Part 2",
                    "phase": "ready_to_continue",
                    "conversation_ref": "conv-w1-out",
                    "cursor_version": 2,
                }
            },
            # Reconciled call after timeout
            {
                "data": {
                    "work_path": "Chapter 1 / Kinematics / Motion Part 2",
                    "phase": "awaiting_010",
                    "conversation_ref": "conv-continue-2-out",
                    "cursor_version": 3,
                    "section_refs": ["sec-1", "sec-2"],
                }
            },
        ]
        self.laravel_client.claim_query.return_value = {
            "data": {
                "query_ref": "q-continue-2",
                "disposition": "send_now",
            }
        }
        w2_answer = self.valid_first_answer.replace("W1", "W2")
        self.adapter.query_once_by_binding.return_value = {
            "answer": w2_answer,
            "conversation_id": "conv-continue-2-out",
        }
        self.laravel_client.post_query_result.side_effect = TimeoutError("HTTP ack lost after database commit")

        res = await self.processor.prepare_continuation_lesson(
            case_id="case-1",
            activation_id="act-1",
            binding_ref="bind-1",
            work_ordinal=2,
        )

        self.assertEqual(res["status"], "ready")
        self.assertEqual(res["cursor_phase"], "awaiting_010")
        self.assertEqual(res["cursor_version"], 3)
        self.assertEqual(res["section_count"], 2)

    async def test_prepare_continuation_lesson_recorded_disposition(self):
        self.laravel_client.get_lesson_context.return_value = {
            "data": {
                "work_path": "Chapter 1 / Kinematics / Motion Part 2",
                "phase": "awaiting_010",
                "conversation_ref": "conv-continue-2-out",
                "cursor_version": 3,
                "section_refs": ["sec-1", "sec-2"],
            }
        }
        self.laravel_client.claim_query.return_value = {
            "data": {
                "query_ref": "q-continue-2",
                "disposition": "recorded",
            }
        }

        res = await self.processor.prepare_continuation_lesson(
            case_id="case-1",
            activation_id="act-1",
            binding_ref="bind-1",
            work_ordinal=2,
        )

        self.assertEqual(res["status"], "ready")
        self.assertEqual(res["cursor_phase"], "awaiting_010")
        self.assertEqual(res["cursor_version"], 3)
        self.assertEqual(res["section_count"], 2)
        self.adapter.query_once_by_binding.assert_not_called()


if __name__ == "__main__":
    unittest.main()
