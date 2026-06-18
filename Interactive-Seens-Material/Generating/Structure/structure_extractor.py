"""
Structure Extractor — AI-powered educational hierarchy extraction.

Replaces ALL hardcoded structure logic from:
  - generate_final_structure.py (14 manual chapters for CCS + 36 for To The Point)
  - parse_to_the_point.py (duplicated 36-chapter dictionary)
  - inspect_chapters.py (hardcoded TOC parsing)

Uses a two-pass strategy:
  1. Heuristic pre-parse for TOC detection
  2. AI-based structure extraction per chunk
  3. Multi-chunk merging for large documents
"""

import json
import logging
from pathlib import Path
from typing import Optional

from Generating.Structure.schema import (
    Chapter,
    Lesson,
    MaterialConfig,
    MaterialStructure,
    MiniChapter,
)
from Generating.Structure.prompts import (
    SYSTEM_PROMPT,
    get_material_config_prompt,
    get_structure_extraction_prompt,
    get_structure_merge_prompt,
)

logger = logging.getLogger(__name__)


class StructureExtractor:
    """
    Extracts educational hierarchy from text using AI.

    Handles documents of any size through chunking. Produces
    structure.json and material_config.json.

    Usage:
        extractor = StructureExtractor(ai_client)
        structure = extractor.extract(
            text="...",
            chunks=[...],
            route="Medical",
            material_name="Cardiology_Lecture"
        )
    """

    def __init__(self, ai_client):
        """
        Args:
            ai_client: An AIClient instance for making LLM calls.
        """
        self.ai_client = ai_client

    def extract(
        self,
        text: str,
        chunk_texts: Optional[list[str]] = None,
        route: str = "General",
        domain: str = "",
        material_name: str = "Unknown",
        source_file: str = "unknown.pdf",
        output_dir: Optional[str] = None,
    ) -> MaterialStructure:
        """
        Extract the structural hierarchy from educational text.

        If chunk_texts is provided, processes each chunk separately
        and merges the results. Otherwise, processes the full text.

        Args:
            text: The full extracted text.
            chunk_texts: List of text chunks (from ChunkManager). If None,
                         the full text is sent as a single chunk.
            route: The detected route.
            domain: The specific domain.
            material_name: Name of the material.
            source_file: Source PDF filename.

        Returns:
            MaterialStructure with the complete hierarchy.
        """
        if chunk_texts and len(chunk_texts) > 1:
            structure = self._extract_multi_chunk(
                chunk_texts, route, domain, material_name, source_file
            )
        else:
            single_text = chunk_texts[0] if chunk_texts else text
            structure = self._extract_single(
                single_text, route, domain, material_name, source_file
            )

        structure.count_totals()
        
        # Link visual assets if metadata is available
        if output_dir:
            meta_path = Path(output_dir) / "extraction_metadata.json"
            if meta_path.exists():
                try:
                    with open(meta_path, "r", encoding="utf-8") as f:
                        meta = json.load(f)
                        
                    page_visuals = {}
                    for p in meta.get("pages", []):
                        assets = p.get("visual_assets", [])
                        if assets:
                            page_visuals[p.get("page", 0)] = assets
                            
                    for lesson in structure.get_all_lessons():
                        assets = []
                        for page_num in range(lesson.page_start, lesson.page_end + 1):
                            assets.extend(page_visuals.get(page_num, []))
                        lesson.visual_assets = assets
                except Exception as e:
                    logger.warning(f"Failed to map visual assets to structure: {e}")

        logger.info(
            f"Structure extracted: {structure.total_chapters} chapters, "
            f"{structure.total_mini_chapters} mini chapters, "
            f"{structure.total_lessons} lessons"
        )

        return structure

    def _extract_single(
        self,
        text: str,
        route: str,
        domain: str,
        material_name: str,
        source_file: str,
    ) -> MaterialStructure:
        """Extract structure from a single text block."""
        prompt = get_structure_extraction_prompt(text, route, domain)

        logger.info("Extracting structure from text...")
        data = self.ai_client.generate_json(prompt, SYSTEM_PROMPT)

        return self._parse_structure_response(
            data, material_name, source_file, route, domain
        )

    def _extract_multi_chunk(
        self,
        chunk_texts: list[str],
        route: str,
        domain: str,
        material_name: str,
        source_file: str,
    ) -> MaterialStructure:
        """Extract structure from multiple chunks and merge."""
        logger.info(f"Extracting structure from {len(chunk_texts)} chunks...")

        chunk_structures = []
        for i, chunk_text in enumerate(chunk_texts):
            chunk_info = f"Chunk {i + 1} of {len(chunk_texts)}"
            logger.info(f"  Processing {chunk_info}...")

            prompt = get_structure_extraction_prompt(
                chunk_text, route, domain, chunk_info
            )
            data = self.ai_client.generate_json(prompt, SYSTEM_PROMPT)
            chunk_structures.append(data)

        # Merge chunk structures
        if len(chunk_structures) == 1:
            merged = chunk_structures[0]
        else:
            logger.info("  Merging chunk structures...")
            merge_prompt = get_structure_merge_prompt(
                chunk_structures, len(chunk_texts)
            )
            merged = self.ai_client.generate_json(merge_prompt, SYSTEM_PROMPT)

        return self._parse_structure_response(
            merged, material_name, source_file, route, domain
        )

    def _parse_structure_response(
        self,
        data: dict,
        material_name: str,
        source_file: str,
        route: str,
        domain: str,
    ) -> MaterialStructure:
        """Parse AI response into MaterialStructure."""
        chapters = []

        for ch_data in data.get("chapters", []):
            mini_chapters = []
            for mc_data in ch_data.get("mini_chapters", []):
                lessons = []
                for les_data in mc_data.get("lessons", []):
                    lessons.append(Lesson(
                        id=les_data.get("id", ""),
                        title=les_data.get("title", ""),
                        view_name=les_data.get("view_name", ""),
                        page_start=les_data.get("page_start", 0),
                        page_end=les_data.get("page_end", 0),
                        content_ref=les_data.get("content_ref", {}),
                    ))

                # If mini chapter has no lessons, treat it as a lesson
                if not lessons:
                    lessons.append(Lesson(
                        id=mc_data.get("id", "").replace("mc", "L"),
                        title=mc_data.get("title", ""),
                        view_name="",
                        page_start=0,
                        page_end=0,
                    ))

                mini_chapters.append(MiniChapter(
                    id=mc_data.get("id", ""),
                    title=mc_data.get("title", ""),
                    lessons=lessons,
                ))

            chapters.append(Chapter(
                id=ch_data.get("id", ""),
                title=ch_data.get("title", ""),
                mini_chapters=mini_chapters,
            ))

        return MaterialStructure(
            material=material_name,
            source_file=source_file,
            route=route,
            domain=domain,
            chapters=chapters,
        )

    def generate_material_config(
        self,
        text: str,
        route: str,
        domain: str,
        material_name: str,
    ) -> MaterialConfig:
        """
        Generate a material_config.json for a new material.

        This is the config-over-code replacement for generated parser scripts.
        """
        prompt = get_material_config_prompt(text, route, domain)
        data = self.ai_client.generate_json(prompt, SYSTEM_PROMPT)

        return MaterialConfig(
            material_name=material_name,
            language=data.get("language", "English"),
            route=route,
            domain=domain,
            chapter_pattern=data.get("chapter_pattern", "Chapter"),
            mini_chapter_pattern=data.get("mini_chapter_pattern", ""),
            lesson_pattern=data.get("lesson_pattern", ""),
            has_toc=data.get("has_toc", True),
            toc_page_range=data.get("toc_page_range"),
            special_handling=data.get("special_handling", {}),
        )

    def save_structure(self, structure: MaterialStructure, output_dir: str):
        """Save structure to JSON."""
        output_path = Path(output_dir) / "structure.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(structure.model_dump(), f, indent=2, ensure_ascii=False)
        logger.info(f"Saved structure: {output_path}")

    def save_material_config(self, config: MaterialConfig, output_dir: str):
        """Save material config to JSON."""
        output_path = Path(output_dir) / "material_config.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(config.model_dump(), f, indent=2, ensure_ascii=False)
        logger.info(f"Saved material config: {output_path}")

    def load_structure(self, output_dir: str) -> MaterialStructure:
        """Load structure from JSON."""
        structure_path = Path(output_dir) / "structure.json"
        with open(structure_path, "r", encoding="utf-8") as f:
            data = json.load(f)
        return MaterialStructure.model_validate(data)
