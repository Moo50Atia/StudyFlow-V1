"""
Structure Schema — Pydantic models for structure.json.

Every entity maintains source references (page_start, page_end) back to
the original PDF for traceability, validation, and future UI features.
"""

from pydantic import BaseModel, Field
from typing import Optional


class Lesson(BaseModel):
    """A single lesson — the atomic educational unit."""
    id: str = Field(..., description="Unique lesson ID, e.g., 'L1', 'L2'")
    title: str = Field(..., description="Lesson title")
    view_name: str = Field(
        "",
        description="Auto-generated descriptive view name, e.g., 'BloodPressure_FlowSim'"
    )
    page_start: int = Field(..., description="First page of this lesson in the source PDF")
    page_end: int = Field(..., description="Last page of this lesson in the source PDF")
    visual_assets: list[str] = Field(
        default_factory=list,
        description="Paths to extracted visual assets relevant to this lesson"
    )
    content_ref: dict = Field(
        default_factory=dict,
        description="Additional content references (line ranges, section headers, etc.)"
    )


class MiniChapter(BaseModel):
    """A sub-chapter grouping related lessons."""
    id: str = Field(..., description="Mini chapter ID, e.g., 'mc1.1'")
    title: str = Field(..., description="Mini chapter title")
    lessons: list[Lesson] = Field(default_factory=list)


class Chapter(BaseModel):
    """A top-level chapter grouping mini chapters."""
    id: str = Field(..., description="Chapter ID, e.g., 'ch1'")
    title: str = Field(..., description="Chapter title")
    mini_chapters: list[MiniChapter] = Field(default_factory=list)


class MaterialStructure(BaseModel):
    """
    Complete structural hierarchy for an educational material.

    This is the output of Stage 6 (Structure Extraction) and the
    primary input to subsequent stages. Replaces all hardcoded
    chapter definitions from the legacy scripts.
    """
    material: str = Field(..., description="Name of the educational material")
    source_file: str = Field(..., description="Source PDF filename")
    route: str = Field(..., description="Detected route: Medical, Engineering, etc.")
    domain: str = Field("", description="Specific domain, e.g., 'Digital Logic'")
    total_chapters: int = Field(0, description="Total number of chapters")
    total_mini_chapters: int = Field(0, description="Total mini chapters across all chapters")
    total_lessons: int = Field(0, description="Total lessons across all mini chapters")
    chapters: list[Chapter] = Field(default_factory=list)

    def count_totals(self):
        """Recompute total counts from the chapter tree."""
        self.total_chapters = len(self.chapters)
        self.total_mini_chapters = sum(
            len(ch.mini_chapters) for ch in self.chapters
        )
        self.total_lessons = sum(
            len(mc.lessons)
            for ch in self.chapters
            for mc in ch.mini_chapters
        )

    def get_all_lessons(self) -> list[Lesson]:
        """Flatten the hierarchy and return all lessons in order."""
        lessons = []
        for chapter in self.chapters:
            for mini_chapter in chapter.mini_chapters:
                for lesson in mini_chapter.lessons:
                    lessons.append(lesson)
        return lessons


class MaterialConfig(BaseModel):
    """
    Material-specific configuration — config-over-code approach.

    Instead of generating Python parser scripts per material, we generate
    a JSON configuration that the universal pipeline uses.
    """
    material_name: str = Field(..., description="Material identifier")
    language: str = Field("English", description="Primary language of the material")
    route: str = Field("General", description="Processing route")
    domain: str = Field("", description="Specific academic domain")
    chapter_pattern: str = Field(
        "Chapter",
        description="Pattern used for chapter headings in the source"
    )
    mini_chapter_pattern: str = Field(
        "",
        description="Pattern used for sub-chapter headings (empty = auto-detect)"
    )
    lesson_pattern: str = Field(
        "",
        description="Pattern used for lesson headings (empty = auto-detect)"
    )
    has_toc: bool = Field(True, description="Whether the source has a table of contents")
    toc_page_range: Optional[list[int]] = Field(
        None,
        description="Page range for table of contents [start, end]"
    )
    special_handling: dict = Field(
        default_factory=dict,
        description="Material-specific processing hints for the AI"
    )
