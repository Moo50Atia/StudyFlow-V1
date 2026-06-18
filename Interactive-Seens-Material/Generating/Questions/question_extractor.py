"""
Question Extractor — Detects questions, exercises, and assignments in educational materials.

Identifies question content from PDFs (especially files starting with "question-")
and extracts structured question data for mapping to educational sections.
"""

import json
import logging
import re
from pathlib import Path
from typing import Optional

logger = logging.getLogger(__name__)


QUESTION_SYSTEM_PROMPT = """You are an educational question extraction engine.
You identify questions, exercises, assignments, and exam items in educational text.
You classify each by thinking type and extract core concepts.
Output ONLY valid JSON."""


class QuestionExtractor:
    """
    Extracts questions from educational materials.

    Detects: Questions, Exercises, Assignments, Quizzes, Exam Reviews.

    Usage:
        extractor = QuestionExtractor(ai_client)
        questions = extractor.extract(text, source_pdf="question-Assignment-2.pdf")
    """

    def __init__(self, ai_client):
        self.ai_client = ai_client

    def extract(
        self,
        text: str,
        source_pdf: str = "",
        route: str = "General",
        domain: str = "",
    ) -> list[dict]:
        """
        Extract questions from text.

        Args:
            text: The extracted text containing questions.
            source_pdf: Name of the source PDF file.
            route: The material route.
            domain: The specific domain.

        Returns:
            List of question dictionaries.
        """
        logger.info(f"Extracting questions from: {source_pdf or 'text input'}")

        # Check if this is explicitly a question file
        is_question_file = self._is_question_file(source_pdf)

        prompt = self._build_extraction_prompt(
            text, source_pdf, route, domain, is_question_file
        )

        try:
            data = self.ai_client.generate_json(prompt, QUESTION_SYSTEM_PROMPT)
            questions = data.get("questions", [])
            logger.info(f"  Extracted {len(questions)} questions")
            return questions
        except Exception as e:
            logger.error(f"  Question extraction failed: {e}")
            return []

    def _is_question_file(self, filename: str) -> bool:
        """Check if the file is explicitly a question/exercise file."""
        lower = filename.lower()
        indicators = [
            "question", "exercise", "assignment", "quiz", "exam",
            "homework", "problem", "worksheet", "practice", "test",
        ]
        return any(ind in lower for ind in indicators)

    def _build_extraction_prompt(
        self,
        text: str,
        source_pdf: str,
        route: str,
        domain: str,
        is_question_file: bool,
    ) -> str:
        """Build the prompt for question extraction."""
        context = "This is a dedicated question/exercise file." if is_question_file else \
                  "This is educational content that may contain embedded questions or exercises."

        return f"""Analyze the following {route} educational text ({domain}) and extract ALL questions,
exercises, assignments, and exam items.

SOURCE: {source_pdf}
CONTEXT: {context}

For each question found, extract:
1. question_text: The full question text
2. question_type: One of [multiple_choice, short_answer, essay, calculation,
   design, proof, diagram, true_false, matching, fill_blank]
3. core_idea: The main concept being tested (e.g., "Shift Register", "Integration by Parts")
4. required_components: List of concepts/components needed to answer
5. thinking_type: One of [Recall, Apply, Analyze, Design, Evaluate, Create]
6. difficulty: One of [easy, medium, hard]
7. page: Page number where the question appears (if detectable, else 0)

TEXT TO ANALYZE:
{text[:10000]}

OUTPUT — Valid JSON:
{{
    "questions": [
        {{
            "question_id": "Q1",
            "question_text": "...",
            "question_type": "calculation",
            "source_pdf": "{source_pdf}",
            "page": 0,
            "core_idea": "...",
            "required_components": ["...", "..."],
            "thinking_type": "Apply",
            "difficulty": "medium"
        }}
    ]
}}"""

    def save_questions(self, questions: list[dict], output_dir: str):
        """Save extracted questions to JSON."""
        output_path = Path(output_dir) / "questions.json"
        data = {
            "total_questions": len(questions),
            "questions": questions,
        }
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        logger.info(f"Saved {len(questions)} questions: {output_path}")
