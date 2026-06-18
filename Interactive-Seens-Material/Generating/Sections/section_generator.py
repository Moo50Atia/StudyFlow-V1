"""
Section Generator — Generates educational section content per lesson.

Takes structure.json and extracted text, produces sections.json with
rich pedagogical content for each lesson's sections.
"""

import json
import logging
from pathlib import Path
from typing import Optional

from Generating.Sections.prompts import SECTION_SYSTEM_PROMPT, get_section_generation_prompt
from Generating.Structure.schema import MaterialStructure

logger = logging.getLogger(__name__)


class SectionGenerator:
    """
    Generates educational section content for each lesson.

    Usage:
        generator = SectionGenerator(ai_client)
        sections = generator.generate(structure, text, route="Medical")
    """

    def __init__(self, ai_client):
        self.ai_client = ai_client

    def generate(
        self,
        structure: MaterialStructure,
        text: str,
        route: str = "General",
        domain: str = "",
        kg_data: dict = None,
        questions_data: dict = None,
    ) -> dict:
        """
        Generate educational sections for all lessons.

        Args:
            structure: The material structure (from structure.json).
            text: The full extracted text.
            route: The material route.
            domain: The specific domain.

        Returns:
            Dict containing all lessons with their generated sections.
        """
        logger.info(
            f"Generating sections for {structure.total_lessons} lessons "
            f"(route: {route})"
        )

        all_lessons = []
        total_sections = 0

        for chapter in structure.chapters:
            for mini_chapter in chapter.mini_chapters:
                for lesson in mini_chapter.lessons:
                    logger.info(f"  Processing {lesson.id}: {lesson.title}")

                    # Extract the lesson's text from the full document
                    lesson_text = self._extract_lesson_text(
                        text, lesson.page_start, lesson.page_end,
                        structure.total_lessons
                    )

                    if not lesson_text.strip():
                        logger.warning(
                            f"  {lesson.id}: No text found for pages "
                            f"{lesson.page_start}-{lesson.page_end}"
                        )
                        continue

                    # Generate sections for this lesson
                    try:
                        prompt = get_section_generation_prompt(
                            lesson.title, lesson_text, route, domain, kg_data, questions_data
                        )
                        data = self.ai_client.generate_json(
                            prompt, SECTION_SYSTEM_PROMPT
                        )

                        # Ensure lesson_id is set
                        data["lesson_id"] = lesson.id
                        data["lesson_title"] = lesson.title

                        sections = data.get("sections", [])
                        total_sections += len(sections)
                        all_lessons.append(data)

                        logger.info(
                            f"    ✓ Generated {len(sections)} sections"
                        )

                    except Exception as e:
                        logger.error(f"    ✗ Failed: {e}")
                        all_lessons.append({
                            "lesson_id": lesson.id,
                            "lesson_title": lesson.title,
                            "sections": [],
                            "error": str(e),
                        })

        result = {
            "material": structure.material,
            "route": route,
            "domain": domain,
            "total_lessons": len(all_lessons),
            "total_sections": total_sections,
            "lessons": all_lessons,
        }

        logger.info(
            f"Section generation complete: {len(all_lessons)} lessons, "
            f"{total_sections} sections"
        )

        return result

    def generate_single_lesson(
        self,
        lesson_id: str,
        lesson_title: str,
        lesson_text: str,
        route: str = "General",
        domain: str = "",
        kg_data: dict = None,
        questions_data: dict = None,
    ) -> dict:
        """
        Generate sections for a single lesson.

        Useful for regenerating or processing individual lessons.
        """
        prompt = get_section_generation_prompt(
            lesson_title, lesson_text, route, domain, kg_data, questions_data
        )
        data = self.ai_client.generate_json(prompt, SECTION_SYSTEM_PROMPT)
        data["lesson_id"] = lesson_id
        data["lesson_title"] = lesson_title
        return data

    def _extract_lesson_text(
        self,
        full_text: str,
        page_start: int,
        page_end: int,
        total_lessons: int,
    ) -> str:
        """
        Extract the text portion for a specific lesson.

        Uses page references to calculate approximate character offsets.
        """
        if page_start <= 0 or page_end <= 0:
            return ""

        # Estimate the total pages from the text
        # Use double-newline as a rough page separator
        pages = full_text.split("\n\n")
        total_pages = max(len(pages), page_end)

        # Calculate character offsets proportionally
        chars_per_page = len(full_text) / max(total_pages, 1)
        start_char = int((page_start - 1) * chars_per_page)
        end_char = int(page_end * chars_per_page)

        # Clamp to text bounds
        start_char = max(0, min(start_char, len(full_text)))
        end_char = max(start_char, min(end_char, len(full_text)))

        return full_text[start_char:end_char]

    def save_sections(self, sections_data: dict, output_dir: str):
        """Save sections to JSON."""
        output_path = Path(output_dir) / "sections.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(sections_data, f, indent=2, ensure_ascii=False)
        logger.info(f"Saved sections: {output_path}")

    def load_sections(self, output_dir: str) -> dict:
        """Load sections from JSON."""
        sections_path = Path(output_dir) / "sections.json"
        with open(sections_path, "r", encoding="utf-8") as f:
            return json.load(f)
