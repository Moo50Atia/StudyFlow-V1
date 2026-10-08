import os
import unittest
from pathlib import Path

from v3_engine.lessons.map_parser import parse_divide_response, MapParserError, ParsedLessonNode

FIXTURES_DIR = Path(__file__).parent / "fixtures"

def read_fixture(filename: str) -> str:
    with open(FIXTURES_DIR / filename, "r", encoding="utf-8") as f:
        return f.read()

class TestMapParser(unittest.TestCase):
    def test_structured_divide_response(self):
        text = read_fixture("divide_structured.txt")
        nodes = parse_divide_response(text)

        self.assertEqual(len(nodes), 6)
        # Verify node kinds
        kinds = [n.kind for n in nodes]
        self.assertEqual(kinds, ["chapter", "mini_chapter", "lesson", "lesson", "chapter", "lesson"])

        # Verify position paths
        paths = [n.position_path for n in nodes]
        self.assertEqual(paths, ["001", "001.001", "001.001.001", "001.001.002", "002", "002.001"])

        # Verify parent paths
        parents = [n.parent_position_path for n in nodes]
        self.assertEqual(parents, [None, "001", "001.001", "001.001", None, "002"])

        # Verify work ordinals: lessons are work items (W1, W2, W3)
        work_ordinals = [n.work_ordinal for n in nodes if n.work_ordinal is not None]
        self.assertEqual(work_ordinals, [1, 2, 3])

    def test_heading_poor_divide_response(self):
        text = read_fixture("divide_heading_poor.txt")
        nodes = parse_divide_response(text)

        self.assertGreaterEqual(len(nodes), 4)
        work_nodes = [n for n in nodes if n.work_ordinal is not None]
        self.assertEqual(len(work_nodes), 2)
        self.assertEqual([n.work_ordinal for n in work_nodes], [1, 2])

    def test_duplicate_titles_remain_distinct(self):
        text = read_fixture("divide_duplicate_titles.txt")
        nodes = parse_divide_response(text)

        overview_nodes = [n for n in nodes if n.title == "Overview"]
        self.assertEqual(len(overview_nodes), 2)
        # Position paths and parent paths must be distinct
        self.assertNotEqual(overview_nodes[0].position_path, overview_nodes[1].position_path)
        self.assertNotEqual(overview_nodes[0].parent_position_path, overview_nodes[1].parent_position_path)
        self.assertEqual(overview_nodes[0].work_ordinal, 1)
        self.assertEqual(overview_nodes[1].work_ordinal, 2)

    def test_empty_mini_promoted_to_work_item(self):
        text = read_fixture("divide_empty_mini.txt")
        nodes = parse_divide_response(text)

        minis = [n for n in nodes if n.kind == "mini_chapter"]
        self.assertEqual(len(minis), 2)
        # Mini chapters with no lesson children must be promoted to work items
        self.assertEqual(minis[0].work_ordinal, 1)
        self.assertEqual(minis[1].work_ordinal, 2)

    def test_invalid_prose_rejected(self):
        text = read_fixture("divide_invalid_prose.txt")
        with self.assertRaises(MapParserError):
            parse_divide_response(text)

    def test_orphan_lesson_rejected(self):
        text = read_fixture("divide_invalid_orphan.txt")
        with self.assertRaises(MapParserError):
            parse_divide_response(text)

    def test_empty_text_rejected(self):
        with self.assertRaises(MapParserError):
            parse_divide_response("")

if __name__ == "__main__":
    unittest.main()
