import unittest
from typing import Dict, Any, List

from v3_engine.prompts.canonical import (
    EXECUTION_PROMPT,
    SECOND_PROMPT,
    THIRD_PROMPT,
    CANONICAL_DIGESTS,
    verify_canonical_digests,
)
from v3_engine.prompts.parser import (
    parse_execution_response,
    parse_continue_response,
    parse_second_blocks,
    parse_third_blocks,
    PromptParserError,
    ALLOWED_SCENE_TYPES,
)


class TestCanonicalPrompts(unittest.TestCase):
    def test_canonical_digests(self):
        self.assertTrue(verify_canonical_digests())
        self.assertEqual(
            CANONICAL_DIGESTS["execution"],
            "5ab2448f34c9b2b90199347772d10d7d217ba9fd468dffc28e3169bd0a7fdce9",
        )
        self.assertEqual(
            CANONICAL_DIGESTS["second"],
            "a8776affc821a1b913a4685b309dc0304afc03c7389fcfe44b366b6f5683f203",
        )
        self.assertEqual(
            CANONICAL_DIGESTS["third"],
            "07798d744b973ec11248897d2efe0e769ab481c4a21cce7562696dd131217955",
        )


class TestGenerationParser(unittest.TestCase):
    def test_p01_execution_ready_response(self):
        """P01: Execution answer is trimmed 'System Ready'."""
        res = parse_execution_response("System Ready\n", work_ordinal=1, expected_sections=[{"ordinal_in_work": 1}])
        self.assertEqual(res["shape"], "ready")
        self.assertIsNone(res.get("second_blocks"))
        self.assertIsNone(res.get("third_blocks"))

    def test_p01_execution_combined_response(self):
        """P01: Execution answer contains complete combined First, Second, and Third."""
        combined_text = (
            "Lecture: W1 | Kinematics\n\n"
            "Section 1:\n"
            "- Section Identity: Displacement\n"
            "- Core Understanding: Vectors\n"
            "- Visual-First Explanation: Path\n"
            "- Visual Scene Script: Script\n"
            "- Dynamic View Blueprint: Blueprint\n"
            "- Visual Learning Outcome: Outcome\n"
            "- Concept Check: Check\n"
            "- Conceptual Summary: Summary\n"
            "- • شرح للجزء بالعامية المصرية: عامي\n"
            "- القوانين (إن وُجدت): v=d/t\n"
            "- إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم: مثال\n\n"
            "Lecture: W1 | Kinematics\n\n"
            "Lec1 (Sec1):\n"
            "\"Dynamic View Placeholder\"\n\n"
            "Lecture: W1 | Kinematics\n\n"
            "Lec1 (Sec1):\n"
            "\"Process: The arrow extends forward representing displacement.\"\n"
        )
        res = parse_execution_response(
            combined_text,
            work_ordinal=1,
            expected_sections=[{"ordinal_in_work": 1, "section_identity": "Displacement"}],
        )
        self.assertEqual(res["shape"], "combined")
        self.assertIsNotNone(res["second_blocks"])
        self.assertIsNotNone(res["third_blocks"])
        self.assertEqual(len(res["third_blocks"]), 1)
        self.assertEqual(res["third_blocks"][0]["scene_type"], "Process")

    def test_p02_continue_combined_response(self):
        """P02: Continue answer is combined (First + Second + Third)."""
        continue_text = (
            "Lecture: W2 | Forces\n\n"
            "Section 1:\n"
            "- Section Identity: Newton First Law\n"
            "- Core Understanding: Inertia\n"
            "- Visual-First Explanation: Glider\n"
            "- Visual Scene Script: Script\n"
            "- Dynamic View Blueprint: Blueprint\n"
            "- Visual Learning Outcome: Outcome\n"
            "- Concept Check: Check\n"
            "- Conceptual Summary: Summary\n"
            "- • شرح للجزء بالعامية المصرية: عامي\n"
            "- القوانين (إن وُجدت): F=ma\n"
            "- إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم: مثال\n\n"
            "Lecture: W2 | Forces\n\n"
            "Lec2 (Sec1):\n"
            "\"Dynamic View Placeholder\"\n\n"
            "Lecture: W2 | Forces\n\n"
            "Lec2 (Sec1):\n"
            "\"Comparison: Compare friction surface vs frictionless surface.\"\n"
        )
        res = parse_continue_response(
            continue_text,
            work_ordinal=2,
            expected_sections=[{"ordinal_in_work": 1, "section_identity": "Newton First Law"}],
        )
        self.assertEqual(res["shape"], "combined")
        self.assertEqual(len(res["second_blocks"]), 1)
        self.assertEqual(len(res["third_blocks"]), 1)
        self.assertEqual(res["third_blocks"][0]["scene_type"], "Comparison")

    def test_p02_continue_first_only_response(self):
        """P02: Continue answer contains only First section."""
        continue_first_only = (
            "Lecture: W2 | Forces\n\n"
            "Section 1:\n"
            "- Section Identity: Newton First Law\n"
            "- Core Understanding: Inertia\n"
            "- Visual-First Explanation: Glider\n"
            "- Visual Scene Script: Script\n"
            "- Dynamic View Blueprint: Blueprint\n"
            "- Visual Learning Outcome: Outcome\n"
            "- Concept Check: Check\n"
            "- Conceptual Summary: Summary\n"
            "- • شرح للجزء بالعامية المصرية: عامي\n"
            "- القوانين (إن وُجدت): F=ma\n"
            "- إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم: مثال\n"
        )
        res = parse_continue_response(
            continue_first_only,
            work_ordinal=2,
            expected_sections=[{"ordinal_in_work": 1, "section_identity": "Newton First Law"}],
        )
        self.assertEqual(res["shape"], "first_only")

    def test_p03_partial_or_malformed_blocks(self):
        """P03: Malformed scene type or partial output raises PromptParserError."""
        bad_scene_type = (
            "Lecture: W1 | Kinematics\n\n"
            "Lec1 (Sec1):\n"
            "\"InvalidType: This is an invalid scene prompt.\"\n"
        )
        with self.assertRaises(PromptParserError):
            parse_third_blocks(bad_scene_type, work_ordinal=1, expected_section_ordinals=[1])

        # Missing section block
        missing_section = (
            "Lecture: W1 | Kinematics\n\n"
            "Lec1 (Sec1):\n"
            "\"Process: Prompt 1.\"\n"
        )
        with self.assertRaises(PromptParserError):
            parse_third_blocks(missing_section, work_ordinal=1, expected_section_ordinals=[1, 2])

        # Allowed scene types
        for st in ALLOWED_SCENE_TYPES:
            valid_text = f"Lecture: W1 | Test\n\nLec1 (Sec1):\n\"{st}: Description here.\""
            blocks = parse_third_blocks(valid_text, work_ordinal=1, expected_section_ordinals=[1])
            self.assertEqual(blocks[0]["scene_type"], st)


if __name__ == "__main__":
    unittest.main()
