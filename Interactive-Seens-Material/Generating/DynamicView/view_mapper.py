"""
View Mapper — Maps educational sections to Dynamic View specifications.

Implements the "Second Step" from the route templates.
Determines scene types, visual objects, animations, and interactions.
"""

import json
import logging
from pathlib import Path

from Generating.DynamicView.prompts import DV_PREPARE_SYSTEM_PROMPT, get_view_mapping_prompt

logger = logging.getLogger(__name__)


class ViewMapper:
    """
    Maps educational sections to Dynamic View specifications.

    Usage:
        mapper = ViewMapper(ai_client)
        mapping = mapper.map_sections(sections_data, route="Medical")
    """

    def __init__(self, ai_client):
        self.ai_client = ai_client

    def map_sections(
        self,
        sections_data: dict,
        route: str = "General",
        validation_data: dict = None,
    ) -> dict:
        """
        Create Dynamic View mappings for all sections.

        Args:
            sections_data: The sections data (from sections.json).
            route: The material route.
            validation_data: Optional validation results to skip low-readiness sections.

        Returns:
            Dynamic View mapping data.
        """
        logger.info("Mapping sections to Dynamic Views...")

        all_mappings = []

        for lesson in sections_data.get("lessons", []):
            lesson_id = lesson.get("lesson_id", "")
            lesson_title = lesson.get("lesson_title", "")
            sections = lesson.get("sections", [])

            if not sections:
                continue

            # Check visualization readiness if validation data available
            if validation_data:
                sections = self._filter_by_readiness(
                    sections, validation_data
                )

            # Generate mappings for this lesson's sections
            try:
                prompt = get_view_mapping_prompt(lesson_title, sections, route)
                data = self.ai_client.generate_json(
                    prompt, DV_PREPARE_SYSTEM_PROMPT
                )

                mappings = data.get("mappings", [])
                for m in mappings:
                    m["lesson_id"] = lesson_id
                    m["lesson_title"] = lesson_title
                all_mappings.extend(mappings)

                logger.info(
                    f"  {lesson_id}: {len(mappings)} view mappings created"
                )

            except Exception as e:
                logger.error(f"  {lesson_id}: View mapping failed: {e}")

        result = {
            "material": sections_data.get("material", ""),
            "route": route,
            "total_mappings": len(all_mappings),
            "mappings": all_mappings,
        }

        logger.info(f"View mapping complete: {len(all_mappings)} total mappings")
        return result

    def _filter_by_readiness(
        self, sections: list[dict], validation_data: dict
    ) -> list[dict]:
        """Filter out sections with low visualization readiness."""
        viz_details = validation_data.get("visualization_details", {})
        filtered = []

        for section in sections:
            sec_id = section.get("section_id", "")
            detail = viz_details.get(sec_id, {})

            if detail.get("recommend_dynamic_view", True):
                filtered.append(section)
            else:
                logger.info(
                    f"    Skipping {sec_id}: low visualization readiness "
                    f"({detail.get('readiness_score', 0)}%)"
                )

        return filtered

    def save_mapping(self, mapping_data: dict, output_dir: str):
        """Save view mapping to JSON."""
        output_path = Path(output_dir) / "dynamic_view_mapping.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(mapping_data, f, indent=2, ensure_ascii=False)
        logger.info(f"Saved view mapping: {output_path}")
