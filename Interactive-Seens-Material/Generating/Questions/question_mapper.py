"""
Question Mapper — Maps questions to educational sections.

Creates question_mapping.json linking each question to relevant sections,
enabling future Dynamic View support for question-based visualizations.
"""

import json
import logging
from pathlib import Path

logger = logging.getLogger(__name__)


class QuestionMapper:
    """
    Maps extracted questions to educational sections.

    Usage:
        mapper = QuestionMapper(ai_client)
        mapping = mapper.map_questions(questions, sections)
    """

    def __init__(self, ai_client=None):
        """
        Args:
            ai_client: Optional AIClient for AI-assisted mapping.
                       If None, uses heuristic matching only.
        """
        self.ai_client = ai_client

    def map_questions(
        self,
        questions: list[dict],
        sections: list[dict],
    ) -> dict:
        """
        Map questions to sections.

        Args:
            questions: List of classified question dicts.
            sections: List of section dicts from sections.json.

        Returns:
            Mapping dict with section_id → related question IDs.
        """
        if not questions or not sections:
            return {"mappings": []}

        logger.info(f"Mapping {len(questions)} questions to {len(sections)} sections...")

        # Build a flat list of all sections with their concepts
        section_concepts = {}
        for lesson in sections:
            for section in lesson.get("sections", []):
                sec_id = section.get("section_id", "")
                if sec_id:
                    section_concepts[sec_id] = {
                        "title": section.get("title", ""),
                        "core_concept": section.get("core_concept", ""),
                        "lesson_id": lesson.get("lesson_id", ""),
                    }

        # Map each question to matching sections
        mappings = {}
        for q in questions:
            q_id = q.get("question_id", "")
            core_idea = q.get("core_idea", "")
            required_components = q.get("required_components", [])

            # Find matching sections
            matching_sections = self._find_matching_sections(
                core_idea, required_components, section_concepts
            )

            for sec_id in matching_sections:
                if sec_id not in mappings:
                    mappings[sec_id] = {
                        "section_id": sec_id,
                        "section_title": section_concepts[sec_id]["title"],
                        "lesson_id": section_concepts[sec_id]["lesson_id"],
                        "related_questions": [],
                    }
                if q_id not in mappings[sec_id]["related_questions"]:
                    mappings[sec_id]["related_questions"].append(q_id)

        mapping_list = list(mappings.values())
        logger.info(
            f"  Mapped questions to {len(mapping_list)} sections"
        )

        return {
            "total_sections_with_questions": len(mapping_list),
            "total_questions_mapped": sum(
                len(m["related_questions"]) for m in mapping_list
            ),
            "mappings": mapping_list,
        }

    def _find_matching_sections(
        self,
        core_idea: str,
        required_components: list[str],
        section_concepts: dict,
    ) -> list[str]:
        """Find sections that match a question's concepts."""
        matching = []

        try:
            from rapidfuzz import fuzz

            search_terms = [core_idea] + required_components

            for sec_id, sec_info in section_concepts.items():
                sec_text = f"{sec_info['title']} {sec_info['core_concept']}".lower()

                # Check if any search term matches the section
                for term in search_terms:
                    if not term:
                        continue
                    score = fuzz.partial_ratio(term.lower(), sec_text)
                    if score > 70:
                        matching.append(sec_id)
                        break

        except ImportError:
            # Fallback: simple substring matching
            for sec_id, sec_info in section_concepts.items():
                sec_text = f"{sec_info['title']} {sec_info['core_concept']}".lower()
                if core_idea.lower() in sec_text:
                    matching.append(sec_id)

        return matching

    def save_mapping(self, mapping: dict, output_dir: str):
        """Save question mapping to JSON."""
        output_path = Path(output_dir) / "question_mapping.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(mapping, f, indent=2, ensure_ascii=False)
        logger.info(f"Saved question mapping: {output_path}")
