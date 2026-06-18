"""
Question Classifier — Classifies and enriches extracted questions.

Takes raw extracted questions and adds classification metadata:
topic mapping, difficulty calibration, and prerequisite analysis.
"""

import json
import logging
from pathlib import Path

logger = logging.getLogger(__name__)


class QuestionClassifier:
    """
    Classifies extracted questions with additional metadata.

    Usage:
        classifier = QuestionClassifier(ai_client)
        classified = classifier.classify(questions, structure)
    """

    def __init__(self, ai_client):
        self.ai_client = ai_client

    def classify(
        self,
        questions: list[dict],
        structure_chapters: list[dict] = None,
    ) -> list[dict]:
        """
        Enrich questions with classification metadata.

        Args:
            questions: List of extracted question dicts.
            structure_chapters: Chapter structure for topic mapping.

        Returns:
            Enriched question list with additional classification fields.
        """
        if not questions:
            return []

        logger.info(f"Classifying {len(questions)} questions...")

        # Build topic list from structure for mapping
        topics = []
        if structure_chapters:
            for ch in structure_chapters:
                topics.append(ch.get("title", ""))
                for mc in ch.get("mini_chapters", []):
                    topics.append(mc.get("title", ""))
                    for les in mc.get("lessons", []):
                        topics.append(les.get("title", ""))

        classified = []
        for q in questions:
            enriched = dict(q)

            # Auto-classify based on question text patterns
            q_text = q.get("question_text", "").lower()

            # Detect if question involves design/building
            if any(kw in q_text for kw in ["design", "build", "construct", "implement", "create"]):
                enriched.setdefault("thinking_type", "Design")

            # Detect if question involves analysis
            elif any(kw in q_text for kw in ["analyze", "compare", "contrast", "evaluate", "explain why"]):
                enriched.setdefault("thinking_type", "Analyze")

            # Detect if question involves calculation
            elif any(kw in q_text for kw in ["calculate", "compute", "find the value", "determine", "solve"]):
                enriched.setdefault("thinking_type", "Apply")

            # Detect if question is recall
            elif any(kw in q_text for kw in ["define", "list", "name", "state", "what is"]):
                enriched.setdefault("thinking_type", "Recall")

            # Map to closest topic if structure is available
            if topics and not enriched.get("mapped_topic"):
                core_idea = enriched.get("core_idea", "")
                best_match = self._find_closest_topic(core_idea, topics)
                if best_match:
                    enriched["mapped_topic"] = best_match

            classified.append(enriched)

        logger.info(f"  Classification complete for {len(classified)} questions")
        return classified

    def _find_closest_topic(self, core_idea: str, topics: list[str]) -> str:
        """Find the closest matching topic for a question's core idea."""
        if not core_idea:
            return ""

        try:
            from rapidfuzz import fuzz
            best_score = 0
            best_topic = ""
            for topic in topics:
                score = fuzz.partial_ratio(core_idea.lower(), topic.lower())
                if score > best_score and score > 60:
                    best_score = score
                    best_topic = topic
            return best_topic
        except ImportError:
            # Simple fallback: substring matching
            core_lower = core_idea.lower()
            for topic in topics:
                if core_lower in topic.lower() or topic.lower() in core_lower:
                    return topic
            return ""
