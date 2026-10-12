import os
import unittest
from pathlib import Path

from v3_engine.lessons.first_parser import (
    parse_first_response,
    FirstParserError,
    ParsedFirstResponse,
    CANONICAL_FIELD_LABELS,
    LABEL_SPECS,
)

FIXTURES_DIR = Path(__file__).parent / "fixtures"

def read_fixture(filename: str) -> str:
    with open(FIXTURES_DIR / filename, "r", encoding="utf-8") as f:
        return f.read()

class TestFirstParser(unittest.TestCase):
    def test_valid_first_response(self):
        text = read_fixture("first_valid.txt")
        result = parse_first_response(text, expected_work_ordinal=1, expected_path="Chapter 1 / Lesson 1")

        self.assertEqual(result.work_ordinal, 1)
        self.assertEqual(len(result.sections), 2)
        self.assertEqual(result.sections[0].ordinal, 1)
        self.assertEqual(result.sections[1].ordinal, 2)

        # Check all 11 fields are present in section 1
        s1_fields = result.sections[0].fields
        self.assertIn("section_identity", s1_fields)
        self.assertIn("core_understanding", s1_fields)
        self.assertIn("visual_first_explanation", s1_fields)
        self.assertIn("visual_scene_script", s1_fields)
        self.assertIn("dynamic_view_blueprint", s1_fields)
        self.assertIn("visual_learning_outcome", s1_fields)
        self.assertIn("concept_check", s1_fields)
        self.assertIn("conceptual_summary", s1_fields)
        self.assertIn("colloquial_egyptian_explanation", s1_fields)
        self.assertIn("laws_if_any", s1_fields)
        self.assertIn("real_life_example", s1_fields)
        self.assertEqual(len(s1_fields), 11)

    def test_titled_section_header_preserves_all_canonical_fields(self):
        text = read_fixture("first_valid.txt").replace(
            "Section 1:", "Section 1: Equivalent Fractions and Equal Partitions", 1
        )

        result = parse_first_response(text, expected_work_ordinal=1)

        self.assertEqual(len(result.sections[0].fields), 11)
        self.assertEqual(result.sections[0].fields["section_identity"], "Vectors vs Scalars")

    def test_standalone_canonical_headings_preserve_nested_perspective_content(self):
        lines = read_fixture("first_valid.txt").splitlines()
        expanded = []
        for line in lines:
            matched = next((label for label in CANONICAL_FIELD_LABELS if line.startswith(label + ":")), None)
            if matched is None:
                expanded.append(line)
                continue
            value = line[len(matched) + 1:].strip()
            expanded.extend([matched, f"- Primary perspective: {value}"])

        result = parse_first_response("\n".join(expanded), expected_work_ordinal=1)

        self.assertEqual(len(result.sections), 2)
        self.assertEqual(len(result.sections[0].fields), 11)
        self.assertIn("- Primary perspective: Vectors vs Scalars", result.sections[0].fields["section_identity"])

    def test_numbered_canonical_field_headings_preserve_all_eleven_fields(self):
        lines = read_fixture("first_valid.txt").splitlines()
        numbered = []
        field_ordinal = 0
        for line in lines:
            if line.startswith("Section "):
                field_ordinal = 0
            matched_field = any(pattern.match(line.strip()) for _, pattern, _ in LABEL_SPECS)
            if matched_field:
                field_ordinal += 1
                numbered.append(f"{field_ordinal}. {line}")
            else:
                numbered.append(line)

        result = parse_first_response("\n".join(numbered), expected_work_ordinal=1)

        self.assertEqual(len(result.sections), 2)
        self.assertEqual(len(result.sections[0].fields), 11)
        self.assertEqual(result.sections[0].fields["section_identity"], "Vectors vs Scalars")
        self.assertEqual(
            result.sections[1].fields["real_life_example"],
            "اتنين بيشدوا صندوق بحبلين في اتجاهين مختلفين والصندوق بيتحرك في الاتجاه المحصل.",
        )

    def test_empty_laws_allowed(self):
        text = read_fixture("first_empty_laws.txt")
        result = parse_first_response(text, expected_work_ordinal=1, expected_path="Chapter 1 / Lesson 1")

        self.assertEqual(result.work_ordinal, 1)
        self.assertEqual(len(result.sections), 1)
        # laws_if_any should be empty string
        self.assertEqual(result.sections[0].fields["laws_if_any"], "")

    def test_missing_field_rejected(self):
        text = read_fixture("first_missing_field.txt")
        with self.assertRaises(FirstParserError):
            parse_first_response(text, expected_work_ordinal=1, expected_path="Chapter 1 / Lesson 1")

    def test_empty_required_field_rejected(self):
        text = read_fixture("first_empty_required_field.txt")
        with self.assertRaises(FirstParserError):
            parse_first_response(text, expected_work_ordinal=1, expected_path="Chapter 1 / Lesson 1")

    def test_wrong_work_rejected(self):
        text = read_fixture("first_wrong_work.txt")
        with self.assertRaises(FirstParserError):
            parse_first_response(text, expected_work_ordinal=1, expected_path="Chapter 1 / Lesson 1")

    def test_empty_text_rejected(self):
        with self.assertRaises(FirstParserError):
            parse_first_response("", expected_work_ordinal=1, expected_path="Chapter 1 / Lesson 1")

if __name__ == "__main__":
    unittest.main()
