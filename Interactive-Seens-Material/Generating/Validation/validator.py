"""
Validator — Content coverage analysis and visualization readiness scoring.

Compares original extracted content against generated educational structure
to detect coverage gaps and estimate how well each section can be visualized.
"""

import json
import logging
import re
from pathlib import Path
from typing import Optional

from Generating.Validation.schema import ValidationResult
from Generating.config import VALIDATION_MIN_COVERAGE, VISUALIZATION_READINESS_THRESHOLD

logger = logging.getLogger(__name__)


class Validator:
    """
    Validates generated content against source material.

    Two-axis validation:
        1. Coverage — Are all concepts from the source represented?
        2. Visualization Readiness — Can sections be effectively visualized?

    Usage:
        validator = Validator(ai_client)
        result = validator.validate(source_text, sections_data, structure)
    """

    def __init__(self, ai_client=None):
        """
        Args:
            ai_client: Optional AIClient for AI-assisted validation.
                       If None, uses heuristic validation only.
        """
        self.ai_client = ai_client

    def validate(
        self,
        source_text: str,
        sections_data: dict,
        structure_data: Optional[dict] = None,
        route: str = "General",
    ) -> ValidationResult:
        """
        Run full validation pipeline.

        Args:
            source_text: The original extracted text.
            sections_data: The generated sections (from sections.json).
            structure_data: The structure (from structure.json).
            route: The material route.

        Returns:
            ValidationResult with coverage and visualization readiness scores.
        """
        logger.info("Running validation...")

        result = ValidationResult(
            material=sections_data.get("material", "Unknown"),
        )

        # 1. Extract key terms from source
        source_terms = self._extract_key_terms(source_text)
        result.total_concepts_in_source = len(source_terms)

        # 2. Extract terms from generated sections
        generated_text = self._flatten_sections_text(sections_data)
        generated_terms = self._extract_key_terms(generated_text)

        # 3. Compute coverage
        covered = source_terms & generated_terms
        result.total_concepts_covered = len(covered)

        if source_terms:
            result.coverage_percentage = round(
                len(covered) / len(source_terms) * 100, 1
            )

        # 4. Find missing items by category
        missing = source_terms - generated_terms
        result.missing_concepts = sorted(list(missing))[:50]  # Cap at 50
        result.missing_definitions = self._find_missing_definitions(source_text, generated_text)
        result.missing_formulas = self._find_missing_formulas(source_text, generated_text)

        # 5. Compute visualization readiness
        viz_score, viz_details = self._compute_visualization_readiness(
            sections_data, route
        )
        result.visualization_readiness = viz_score
        result.visualization_details = viz_details

        # 6. Semantic Validation Fallback and Warnings
        if result.coverage_percentage < VALIDATION_MIN_COVERAGE and self.ai_client:
            logger.info(f"Regex coverage low ({result.coverage_percentage}%). Triggering semantic validation...")
            result.semantic_validation_triggered = True
            semantic_score = self._run_semantic_validation(source_text, generated_text)
            result.semantic_coverage_score = semantic_score
            
            # If semantic score passes, we suppress the coverage warning
            if semantic_score >= VALIDATION_MIN_COVERAGE:
                logger.info(f"Semantic validation passed ({semantic_score}%).")
            else:
                result.warnings.append(
                    f"Semantic coverage ({semantic_score}%) is below threshold "
                    f"({VALIDATION_MIN_COVERAGE}%)"
                )
        elif result.coverage_percentage < VALIDATION_MIN_COVERAGE:
            result.warnings.append(
                f"Coverage ({result.coverage_percentage}%) is below threshold "
                f"({VALIDATION_MIN_COVERAGE}%)"
            )
            
        if result.visualization_readiness < VISUALIZATION_READINESS_THRESHOLD:
            result.warnings.append(
                f"Visualization readiness ({result.visualization_readiness}%) is below "
                f"threshold ({VISUALIZATION_READINESS_THRESHOLD}%)"
            )

        # 7. Per-section validation
        result.section_validations = self._validate_sections(sections_data)

        # 8. Recommendations
        if result.missing_formulas:
            result.recommendations.append(
                "Review missing formulas — they may need manual verification"
            )
        if result.visualization_readiness < 70:
            result.recommendations.append(
                "Some sections may not be ideal for Dynamic View generation"
            )

        logger.info(
            f"Validation complete: "
            f"Coverage={result.coverage_percentage}%, "
            f"Viz Readiness={result.visualization_readiness}%"
        )

        return result

    def _run_semantic_validation(self, source: str, generated: str) -> float:
        """Run an LLM-based semantic coverage check."""
        prompt = f"""Evaluate how well the GENERATED TEXT covers the core educational concepts from the SOURCE TEXT.
Ignore differences in synonyms, phrasing, or exact wording. Focus on semantic conceptual coverage.

SOURCE TEXT:
{source[:10000]}

GENERATED TEXT:
{generated[:10000]}

Return ONLY a JSON object with a single field 'score' containing a float between 0.0 and 100.0 representing the coverage percentage.
"""
        try:
            response = self.ai_client.generate_json(prompt, "You are an expert semantic evaluator. Output ONLY valid JSON.")
            return float(response.get("score", 0.0))
        except Exception as e:
            logger.error(f"Semantic validation failed: {e}")
            return 0.0

    def _extract_key_terms(self, text: str) -> set:
        """Extract key terms from text using heuristic NLP."""
        # Extract capitalized phrases (likely proper terms)
        terms = set()

        # Multi-word capitalized phrases
        for match in re.finditer(r'\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\b', text):
            term = match.group(1).strip()
            if len(term) > 5:
                terms.add(term.lower())

        # Technical terms (words with specific patterns)
        for match in re.finditer(r'\b([A-Z]{2,}[a-z]*(?:\s*[-/]\s*[A-Z]{2,}[a-z]*)*)\b', text):
            term = match.group(1).strip()
            if len(term) > 2:
                terms.add(term.lower())

        # Terms following "is defined as", "refers to", etc.
        definition_patterns = [
            r'(\w[\w\s]{3,30})\s+is defined as',
            r'(\w[\w\s]{3,30})\s+refers to',
            r'definition of\s+(\w[\w\s]{3,30})',
        ]
        for pattern in definition_patterns:
            for match in re.finditer(pattern, text, re.IGNORECASE):
                terms.add(match.group(1).strip().lower())

        return terms

    def _flatten_sections_text(self, sections_data: dict) -> str:
        """Flatten all section content into a single text string."""
        parts = []
        for lesson in sections_data.get("lessons", []):
            for section in lesson.get("sections", []):
                parts.append(section.get("title", ""))
                parts.append(section.get("core_concept", ""))
                content = section.get("content", {})
                if isinstance(content, dict):
                    for value in content.values():
                        if isinstance(value, str):
                            parts.append(value)
        return " ".join(parts)

    def _find_missing_definitions(
        self, source: str, generated: str
    ) -> list[str]:
        """Find definitions in source that are missing from generated."""
        missing = []
        # Look for definition patterns in source
        for match in re.finditer(
            r'(?:definition|defined as|refers to)[:\s]+([^.]{10,100})\.',
            source, re.IGNORECASE
        ):
            definition = match.group(1).strip()
            # Check if the core term appears in generated
            core_words = [w for w in definition.split() if len(w) > 4]
            if core_words:
                key_word = core_words[0].lower()
                if key_word not in generated.lower():
                    missing.append(definition[:80])
        return missing[:20]

    def _find_missing_formulas(
        self, source: str, generated: str
    ) -> list[str]:
        """Find formulas in source that are missing from generated."""
        missing = []
        # Look for mathematical expressions
        formula_pattern = re.compile(
            r'[A-Za-z]\s*[=<>≤≥]\s*[^,\n]{3,50}'
        )
        for match in formula_pattern.finditer(source):
            formula = match.group().strip()
            # Check if formula appears in generated (exact or close)
            if formula not in generated:
                missing.append(formula[:60])
        return missing[:20]

    def _compute_visualization_readiness(
        self, sections_data: dict, route: str
    ) -> tuple[float, dict]:
        """
        Compute visualization readiness score.

        Estimates how well each section can be converted to a Dynamic View.
        """
        scores = []
        details = {}

        for lesson in sections_data.get("lessons", []):
            for section in lesson.get("sections", []):
                sec_id = section.get("section_id", "unknown")
                score = self._score_section_visualizability(section, route)
                scores.append(score)
                details[sec_id] = {
                    "title": section.get("title", ""),
                    "readiness_score": score,
                    "recommend_dynamic_view": score >= VISUALIZATION_READINESS_THRESHOLD,
                }

        avg_score = sum(scores) / max(len(scores), 1)
        return round(avg_score, 1), details

    def _score_section_visualizability(
        self, section: dict, route: str
    ) -> float:
        """Score a single section's visualization readiness (0-100)."""
        score = 50.0  # Base score

        content = section.get("content", {})
        if not isinstance(content, dict):
            return score

        # Has visual explanation → +15
        visual = content.get("visual_explanation", "") or content.get("visual_first_explanation", "")
        if visual and len(visual) > 50:
            score += 15

        # Has scene script → +15
        scene = content.get("scene_script", "") or content.get("visual_scene_script", "")
        if scene and len(scene) > 50:
            score += 15

        # Has dynamic view blueprint → +10
        blueprint = content.get("dynamic_view_blueprint", "")
        if blueprint and len(blueprint) > 30:
            score += 10

        # Has core concept (clear topic) → +5
        if section.get("core_concept", ""):
            score += 5

        # Has visual metaphor → +5
        if section.get("visual_metaphor", ""):
            score += 5

        # Route-specific bonuses
        if route == "Medical":
            if content.get("diagnostic_rules", ""):
                score += 5  # Medical visuals are rich
        elif route == "Engineering":
            if content.get("formulas", ""):
                score += 5  # Engineering diagrams + formulas

        return min(score, 100.0)

    def _validate_sections(self, sections_data: dict) -> list[dict]:
        """Validate individual sections for completeness."""
        validations = []

        for lesson in sections_data.get("lessons", []):
            for section in lesson.get("sections", []):
                issues = []

                if not section.get("title"):
                    issues.append("Missing title")
                if not section.get("core_concept"):
                    issues.append("Missing core concept")
                if not section.get("source_pages"):
                    issues.append("Missing source page references")

                content = section.get("content", {})
                if isinstance(content, dict):
                    if not content.get("arabic_explanation"):
                        issues.append("Missing Arabic explanation")
                    if not content.get("learning_outcome") and not content.get("clinical_learning_outcome"):
                        issues.append("Missing learning outcome")

                validations.append({
                    "section_id": section.get("section_id", ""),
                    "lesson_id": lesson.get("lesson_id", ""),
                    "valid": len(issues) == 0,
                    "issues": issues,
                })

        return validations

    def save_validation(self, result: ValidationResult, output_dir: str):
        """Save validation result to JSON."""
        output_path = Path(output_dir) / "validation.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(result.model_dump(), f, indent=2, ensure_ascii=False)
        logger.info(f"Saved validation: {output_path}")
