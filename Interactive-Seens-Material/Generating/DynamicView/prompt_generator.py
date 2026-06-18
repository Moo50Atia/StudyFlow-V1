"""
Dynamic View Prompt Generator — Creates final executable DV prompts.

Implements the "Third Step" from the route templates.
Generates prompts for both Concept and Question Dynamic Views.
Uses caching to avoid regenerating identical prompts.
"""

import json
import logging
from pathlib import Path

from Generating.DynamicView.cache import DynamicViewCache
from Generating.DynamicView.prompts import (
    DV_GENERATE_SYSTEM_PROMPT,
    get_prompt_generation_prompt,
)

logger = logging.getLogger(__name__)


class PromptGenerator:
    """
    Generates final Dynamic View prompts for each mapped section.

    Supports both Concept Prompts and Question Prompts.
    Uses content-hash caching to reduce API costs.

    Usage:
        generator = PromptGenerator(ai_client, output_dir)
        prompts = generator.generate(mapping_data, sections_data, route="Medical")
    """

    def __init__(self, ai_client, output_dir: str):
        self.ai_client = ai_client
        self.output_dir = output_dir
        self.cache = DynamicViewCache(output_dir)

    def generate(
        self,
        mapping_data: dict,
        sections_data: dict,
        route: str = "General",
        question_mapping: dict = None,
        questions: list[dict] = None,
    ) -> dict:
        """
        Generate Dynamic View prompts for all mapped sections.

        Args:
            mapping_data: View mapping data (from dynamic_view_mapping.json).
            sections_data: Sections data (from sections.json).
            route: The material route.
            question_mapping: Optional question-to-section mapping.
            questions: Optional list of questions for question prompts.

        Returns:
            Dynamic View prompts data.
        """
        logger.info("Generating Dynamic View prompts...")

        # Build section content lookup
        section_lookup = self._build_section_lookup(sections_data)

        # Build question lookup if available
        question_lookup = {}
        if questions:
            for q in questions:
                question_lookup[q.get("question_id", "")] = q

        # Build section-to-questions mapping
        section_questions = {}
        if question_mapping:
            for m in question_mapping.get("mappings", []):
                sec_id = m.get("section_id", "")
                section_questions[sec_id] = m.get("related_questions", [])

        all_prompts = []

        for mapping in mapping_data.get("mappings", []):
            section_id = mapping.get("section_id", "")
            lesson_title = mapping.get("lesson_title", "")
            section_content = section_lookup.get(section_id, {})

            # Generate Concept Prompt
            concept_prompt = self._generate_single_prompt(
                lesson_title, mapping, section_content, route
            )
            if concept_prompt:
                all_prompts.append(concept_prompt)

            # Generate Question Prompts if questions are mapped to this section
            if section_id in section_questions:
                for q_id in section_questions[section_id]:
                    q_data = question_lookup.get(q_id)
                    if q_data:
                        q_prompt = self._generate_single_prompt(
                            lesson_title, mapping, section_content, route,
                            is_question=True, question_data=q_data,
                        )
                        if q_prompt:
                            all_prompts.append(q_prompt)

        # Save cache
        self.cache.save()

        result = {
            "material": mapping_data.get("material", ""),
            "route": route,
            "total_prompts": len(all_prompts),
            "concept_prompts": sum(
                1 for p in all_prompts if p.get("prompt_type") == "concept_dynamic_view"
            ),
            "question_prompts": sum(
                1 for p in all_prompts if p.get("prompt_type") == "question_dynamic_view"
            ),
            "cache_stats": self.cache.stats,
            "prompts": all_prompts,
        }

        logger.info(
            f"Prompt generation complete: {len(all_prompts)} prompts "
            f"(cache: {self.cache.stats})"
        )

        return result

    def _generate_single_prompt(
        self,
        lesson_title: str,
        mapping: dict,
        section_content: dict,
        route: str,
        is_question: bool = False,
        question_data: dict = None,
    ) -> dict | None:
        """Generate a single Dynamic View prompt (concept or question)."""
        # Build cache key from content
        cache_key = json.dumps({
            "section": section_content.get("title", ""),
            "core_concept": section_content.get("core_concept", ""),
            "is_question": is_question,
            "question_id": question_data.get("question_id", "") if question_data else "",
        }, sort_keys=True)

        # Check cache
        cached = self.cache.get(cache_key)
        if cached:
            return cached

        # Generate prompt via AI
        try:
            prompt = get_prompt_generation_prompt(
                lesson_title, mapping, section_content, route,
                is_question_prompt=is_question, question_data=question_data,
            )
            data = self.ai_client.generate_json(prompt, DV_GENERATE_SYSTEM_PROMPT)

            # Add metadata
            data["lesson_id"] = mapping.get("lesson_id", "")
            data["lesson_title"] = lesson_title

            # Cache the result
            self.cache.set(cache_key, data)

            view_name = data.get("view_name", "unknown")
            prompt_type = "Q" if is_question else "C"
            logger.info(f"    [{prompt_type}] Generated: {view_name}")

            return data

        except Exception as e:
            logger.error(f"    Prompt generation failed: {e}")
            return None

    def _build_section_lookup(self, sections_data: dict) -> dict:
        """Build a section_id → section_content lookup dict."""
        lookup = {}
        for lesson in sections_data.get("lessons", []):
            for section in lesson.get("sections", []):
                sec_id = section.get("section_id", "")
                if sec_id:
                    lookup[sec_id] = section
        return lookup

    def save_prompts(self, prompts_data: dict, output_dir: str = None):
        """Save prompts to JSON."""
        out_dir = output_dir or self.output_dir
        output_path = Path(out_dir) / "dynamic_view_prompts.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(prompts_data, f, indent=2, ensure_ascii=False)
        logger.info(f"Saved DV prompts: {output_path}")
