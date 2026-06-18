"""
Route Detector — AI-based domain and route classification.

Replaces simple keyword counting with AI classification for accurate
domain detection across any educational material.
"""

import json
import logging
from pathlib import Path
from typing import Optional

from Generating.config import (
    DEFAULT_ROUTE,
    ROUTE_CONFIDENCE_THRESHOLD,
    SUPPORTED_ROUTES,
    TEMPLATES_PATH,
)

logger = logging.getLogger(__name__)


class RouteDetectionResult:
    """Result of route detection."""

    def __init__(
        self,
        domain: str = "",
        route: str = "General",
        confidence: float = 0.0,
        fallback_used: bool = False,
        keyword_scores: Optional[dict] = None,
    ):
        self.domain = domain
        self.route = route
        self.confidence = confidence
        self.fallback_used = fallback_used
        self.keyword_scores = keyword_scores or {}

    def to_dict(self) -> dict:
        return {
            "domain": self.domain,
            "route": self.route,
            "confidence": round(self.confidence, 2),
            "fallback_used": self.fallback_used,
            "keyword_scores": self.keyword_scores,
        }


class RouteDetector:
    """
    Detects the educational domain and route of a document.

    Uses a two-pass approach:
        1. Quick keyword-based pre-classification (no API cost)
        2. AI-based classification for confirmation or ambiguous cases

    Usage:
        detector = RouteDetector()
        result = detector.detect(extracted_text)
        print(result.route, result.confidence)
    """

    def __init__(self):
        self._keywords = self._load_keywords()

    def _load_keywords(self) -> dict:
        """Load route keywords from templates."""
        keywords_path = TEMPLATES_PATH / "route_keywords.json"
        if keywords_path.exists():
            with open(keywords_path, "r", encoding="utf-8") as f:
                return json.load(f)

        # Default keywords if file doesn't exist yet
        return {
            "Medical": {
                "threshold": 3,
                "keywords": [
                    "pathophysiology", "diagnosis", "treatment", "clinical",
                    "patient", "symptom", "disease", "drug", "dose", "therapy",
                    "cardiac", "ecg", "pharmacology", "anatomy", "physiology",
                    "syndrome", "infection", "inflammation", "surgery",
                    "mechanism", "receptor", "enzyme", "blood", "artery",
                    "vein", "heart", "lung", "liver", "kidney",
                ]
            },
            "Engineering": {
                "threshold": 3,
                "keywords": [
                    "circuit", "signal", "frequency", "voltage", "current",
                    "transistor", "amplifier", "filter", "modulation",
                    "flip-flop", "register", "counter", "fsm", "boolean",
                    "logic gate", "semiconductor", "impedance", "bandwidth",
                    "oscillator", "microcontroller",
                ]
            },
            "Computer Science": {
                "threshold": 3,
                "keywords": [
                    "algorithm", "data structure", "complexity", "recursion",
                    "sorting", "graph", "tree", "hash", "database",
                    "operating system", "compiler", "network", "protocol",
                    "encryption", "object-oriented", "machine learning",
                    "artificial intelligence", "neural network", "api",
                ]
            },
            "Mathematics": {
                "threshold": 3,
                "keywords": [
                    "theorem", "proof", "lemma", "corollary", "integral",
                    "derivative", "equation", "matrix", "vector", "eigenvalue",
                    "topology", "algebra", "calculus", "differential",
                    "convergence", "series", "function", "set theory",
                    "probability", "statistics",
                ]
            },
            "Physics": {
                "threshold": 3,
                "keywords": [
                    "force", "energy", "momentum", "velocity", "acceleration",
                    "electromagnetic", "quantum", "thermodynamics", "entropy",
                    "wave", "particle", "relativity", "gravity", "field",
                    "potential", "kinetic", "optics", "nuclear", "photon",
                ]
            },
        }

    def detect(
        self,
        text: str,
        use_ai: bool = True,
        ai_client=None,
        sample_size: int = 5000,
    ) -> RouteDetectionResult:
        """
        Detect the educational domain and route of a document.

        Args:
            text: The extracted text (or a representative sample).
            use_ai: Whether to use AI for classification (requires ai_client).
            ai_client: An AIClient instance for AI-based classification.
            sample_size: Number of characters to sample for classification.

        Returns:
            RouteDetectionResult with domain, route, and confidence.
        """
        # Take a representative sample (beginning + middle + end)
        sample = self._get_sample(text, sample_size)

        # Pass 1: Keyword-based classification (fast, free)
        keyword_result = self._keyword_classify(sample)
        logger.info(
            f"Keyword classification: {keyword_result.route} "
            f"(confidence: {keyword_result.confidence:.2f})"
        )

        # If confidence is high enough from keywords alone, skip AI
        if keyword_result.confidence >= 0.85 or not use_ai or ai_client is None:
            if keyword_result.confidence < ROUTE_CONFIDENCE_THRESHOLD:
                keyword_result.route = DEFAULT_ROUTE
                keyword_result.fallback_used = True
            return keyword_result

        # Pass 2: AI-based classification for confirmation
        ai_result = self._ai_classify(sample, ai_client)

        # Merge results — prefer AI if it's confident
        if ai_result.confidence >= ROUTE_CONFIDENCE_THRESHOLD:
            logger.info(
                f"AI classification: {ai_result.route} "
                f"(confidence: {ai_result.confidence:.2f})"
            )
            ai_result.keyword_scores = keyword_result.keyword_scores
            return ai_result

        # AI not confident enough — use keyword result or fallback
        if keyword_result.confidence >= ROUTE_CONFIDENCE_THRESHOLD:
            return keyword_result

        # Both uncertain — fallback to General
        return RouteDetectionResult(
            domain="General Education",
            route=DEFAULT_ROUTE,
            confidence=max(keyword_result.confidence, ai_result.confidence),
            fallback_used=True,
            keyword_scores=keyword_result.keyword_scores,
        )

    def _get_sample(self, text: str, sample_size: int) -> str:
        """Get a representative sample from the text."""
        if len(text) <= sample_size:
            return text

        third = sample_size // 3
        beginning = text[:third]
        middle_start = len(text) // 2 - third // 2
        middle = text[middle_start:middle_start + third]
        end = text[-third:]

        return f"{beginning}\n\n---\n\n{middle}\n\n---\n\n{end}"

    def _keyword_classify(self, text: str) -> RouteDetectionResult:
        """Classify using keyword matching."""
        text_lower = text.lower()
        scores = {}

        for route, config in self._keywords.items():
            threshold = config.get("threshold", 3)
            keywords = config.get("keywords", [])
            hits = sum(1 for kw in keywords if kw.lower() in text_lower)
            score = hits / max(len(keywords), 1)
            scores[route] = {
                "hits": hits,
                "total_keywords": len(keywords),
                "score": round(score, 3),
            }

        # Find the best match
        best_route = max(scores, key=lambda r: scores[r]["score"])
        best_score = scores[best_route]["score"]

        # Convert score to confidence (0-1)
        # Score > 0.3 is quite confident for keyword matching
        confidence = min(best_score / 0.4, 1.0)

        return RouteDetectionResult(
            domain=best_route,
            route=best_route,
            confidence=confidence,
            keyword_scores=scores,
        )

    def _ai_classify(self, text: str, ai_client) -> RouteDetectionResult:
        """Classify using AI."""
        routes_str = ", ".join(SUPPORTED_ROUTES)

        prompt = f"""Analyze the following educational text sample and classify it.

Determine:
1. The specific academic domain (e.g., "Digital Logic", "Cardiology", "Linear Algebra")
2. The broad route category from: {routes_str}
3. Your confidence level (0.0 to 1.0)

TEXT SAMPLE:
{text[:3000]}

Respond with ONLY valid JSON:
{{
    "domain": "...",
    "route": "...",
    "confidence": 0.XX
}}"""

        try:
            data = ai_client.generate_json(prompt)
            route = data.get("route", DEFAULT_ROUTE)

            # Validate route is in supported list
            if route not in SUPPORTED_ROUTES:
                route = DEFAULT_ROUTE

            return RouteDetectionResult(
                domain=data.get("domain", ""),
                route=route,
                confidence=float(data.get("confidence", 0.5)),
            )
        except Exception as e:
            logger.warning(f"AI route detection failed: {e}")
            return RouteDetectionResult(confidence=0.0)

    def save_result(self, result: RouteDetectionResult, output_dir: str):
        """Save route detection result to JSON."""
        output_path = Path(output_dir) / "route_detection.json"
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(result.to_dict(), f, indent=2, ensure_ascii=False)
        logger.info(f"Saved route detection: {output_path}")
