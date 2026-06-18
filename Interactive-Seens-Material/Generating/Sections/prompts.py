"""
Section Prompts — Prompt templates for educational section generation.

Implements the "First Step" from General.txt and Medical.txt programmatically.
Route-specific prompts are loaded from Templates/*.json and augmented here.
"""

import json
import logging
from pathlib import Path

from Generating.config import TEMPLATES_PATH

logger = logging.getLogger(__name__)


def _load_template(route: str) -> dict:
    """Load a route-specific step template."""
    filename = f"{route.lower()}_steps.json"
    template_path = TEMPLATES_PATH / filename

    if template_path.exists():
        with open(template_path, "r", encoding="utf-8") as f:
            return json.load(f)

    # Return empty dict if template doesn't exist yet
    return {}


SECTION_SYSTEM_PROMPT = """You are an educational content architect specializing in
deep conceptual understanding and student-friendly learning.
Your task is NOT summarization. Your task is to decompose and restructure
educational content into rich, visualization-ready sections.
Output ONLY valid JSON."""


def get_section_generation_prompt(
    lesson_title: str,
    lesson_text: str,
    route: str = "General",
    domain: str = "",
    kg_data: dict = None,
    questions_data: dict = None,
) -> str:
    """
    Generate the prompt for creating educational sections from a lesson.

    This implements the "First Step" from the route templates.

    Args:
        lesson_title: Title of the lesson.
        lesson_text: The raw text content of the lesson.
        route: The material route (Medical, Engineering, etc.).
        domain: The specific domain.

    Returns:
        Complete prompt string for the AI.
    """
    # Route-specific section fields
    if route == "Medical":
        section_fields = """For each Section, generate EXACTLY:
- section_identity: What this section covers
- core_pathological_mechanism: The underlying medical mechanism
- visual_first_explanation: Focus on hemodynamics and tissue-level changes
- visual_scene_script: Step-by-step visual narrative
- dynamic_view_blueprint: Interactive visualization specification
- clinical_learning_outcome: What the student should understand
- concept_check: A quick self-check question
- conceptual_summary: Brief recap
- arabic_explanation: شرح للجزء بالعامية المصرية (لتبسيط الميكانيكية الطبية المعقدة)
- diagnostic_rules: Any ECG criteria, drug mechanisms, lab values (exact copy, never paraphrase)
- clinical_scenario: إسقاط على حالة مريض في الطوارئ أو العيادة لتثبيت الفهم"""
    else:
        section_fields = """For each Section, generate EXACTLY:
- section_identity: What this section covers
- core_understanding: The key concept to grasp
- visual_first_explanation: How to visualize this concept
- visual_scene_script: Step-by-step visual narrative
- dynamic_view_blueprint: Interactive visualization specification
- visual_learning_outcome: What the student should understand visually
- concept_check: A quick self-check question
- conceptual_summary: Brief recap
- arabic_explanation: شرح للجزء بالعامية المصرية
- formulas: القوانين (إن وُجدت)
- real_life_example: إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم"""

    backward_design = ""
    if kg_data or questions_data:
        backward_design += "\nBACKWARD DESIGN REQUIREMENTS:\n"
        if kg_data:
            backward_design += "- Knowledge Graph: Ensure explanations bridge prerequisite concepts mentioned in the graph.\n"
        if questions_data:
            backward_design += "- Assessment Alignment: Directly address the core ideas from the extracted questions to prepare students for exams.\n"
            
    kg_context = ""
    if kg_data:
        kg_context = f"\nKNOWLEDGE GRAPH CONTEXT:\n{json.dumps(kg_data, indent=2)[:5000]}\n"

    questions_context = ""
    if questions_data:
        questions_context = f"\nEXTRACTED QUESTIONS CONTEXT:\n{json.dumps(questions_data, indent=2)[:5000]}\n"

    return f"""You will process ONE educational lesson from a {route} ({domain}) material.

LESSON: {lesson_title}

TASK:
1. Identify all implicit and explicit Sections in this lesson.
2. Infer logical Sections if headings are missing.
3. Treat each Section as an independent learning unit.

{section_fields}

{backward_design}
RULES:
- This is a SINGLE Lesson, not a full course.
- Do NOT add exam-style questions.
- Do NOT reference Dynamic View engines explicitly.
- Focus on clarity, intuition, and visualization readiness.
- Keep explanations student-oriented and simple.
- If a medical/scientific value (dose, ECG criteria, lab value) appears → copy it EXACTLY.
- Each section must include source_pages: the list of page numbers from the source PDF.

OUTPUT FORMAT — Valid JSON:
{{
    "lesson_id": "...",
    "lesson_title": "{lesson_title}",
    "sections": [
        {{
            "section_id": "S1",
            "title": "Section Title",
            "source_pages": [44, 45],
            "core_concept": "...",
            "visual_metaphor": "...",
            "key_misconception": "...",
            "content": {{
                "section_identity": "...",
                "core_understanding": "...",
                "visual_explanation": "...",
                "scene_script": "...",
                "dynamic_view_blueprint": "...",
                "learning_outcome": "...",
                "concept_check": "...",
                "summary": "...",
                "arabic_explanation": "...",
                "formulas": "...",
                "real_life_example": "..."
            }}
        }}
    ]
}}
{kg_context}{questions_context}
LESSON TEXT:
{lesson_text[:12000]}"""
