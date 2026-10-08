import os
import unittest
from pathlib import Path

from v3_engine.lessons.coverage_parser import parse_coverage_response, CoverageParserError, PageCoverageItem

FIXTURES_DIR = Path(__file__).parent / "fixtures"

def read_fixture(filename: str) -> str:
    with open(FIXTURES_DIR / filename, "r", encoding="utf-8") as f:
        return f.read()

class TestCoverageParser(unittest.TestCase):
    def test_valid_coverage_response(self):
        text = read_fixture("coverage_valid.txt")
        items = parse_coverage_response(text, page_count=3, work_count=3)

        self.assertEqual(len(items), 3)
        self.assertEqual(items[0].page, 1)
        self.assertEqual(items[0].work_ordinals, [1])
        self.assertEqual(items[1].page, 2)
        self.assertEqual(items[1].work_ordinals, [1, 2])
        self.assertEqual(items[2].page, 3)
        self.assertEqual(items[2].work_ordinals, [2, 3])

    def test_coverage_gap_rejected(self):
        text = read_fixture("coverage_gap.txt")
        with self.assertRaises(CoverageParserError):
            parse_coverage_response(text, page_count=3, work_count=2)

    def test_coverage_missing_page_rejected(self):
        text = read_fixture("coverage_missing_page.txt")
        with self.assertRaises(CoverageParserError):
            parse_coverage_response(text, page_count=3, work_count=2)

    def test_coverage_unmapped_work_rejected(self):
        text = read_fixture("coverage_unmapped_work.txt")
        with self.assertRaises(CoverageParserError):
            parse_coverage_response(text, page_count=3, work_count=2)

    def test_empty_coverage_rejected(self):
        with self.assertRaises(CoverageParserError):
            parse_coverage_response("", page_count=3, work_count=2)

if __name__ == "__main__":
    unittest.main()
