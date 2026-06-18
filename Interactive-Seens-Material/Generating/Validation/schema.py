"""
Validation Schema — Pydantic models for validation.json.

Includes both Coverage analysis and Visualization Readiness scoring.
"""

from pydantic import BaseModel, Field
from datetime import datetime, timezone


class ValidationResult(BaseModel):
    """Complete validation result for a material."""
    material: str = Field(..., description="Material name")
    validated_at: str = Field(
        default_factory=lambda: datetime.now(timezone.utc).isoformat()
    )

    # Coverage metrics
    coverage_percentage: float = Field(
        0.0, description="Overall content coverage (0-100)"
    )
    total_concepts_in_source: int = Field(
        0, description="Total concepts identified in source text"
    )
    total_concepts_covered: int = Field(
        0, description="Concepts present in generated sections"
    )

    # Semantic Validation Fallback
    semantic_validation_triggered: bool = Field(
        False, description="Whether LLM semantic fallback was triggered due to low regex coverage"
    )
    semantic_coverage_score: float = Field(
        0.0, description="LLM-evaluated semantic coverage score (0-100)"
    )

    # Missing items
    missing_concepts: list[str] = Field(default_factory=list)
    missing_definitions: list[str] = Field(default_factory=list)
    missing_formulas: list[str] = Field(default_factory=list)
    missing_diagrams: list[str] = Field(default_factory=list)
    missing_examples: list[str] = Field(default_factory=list)

    # Visualization Readiness
    visualization_readiness: float = Field(
        0.0,
        description="Visualization readiness score (0-100). "
                     "Estimates how well sections can be converted to Dynamic Views."
    )
    visualization_details: dict = Field(
        default_factory=dict,
        description="Per-section visualization readiness breakdown"
    )

    # Warnings and recommendations
    warnings: list[str] = Field(default_factory=list)
    recommendations: list[str] = Field(default_factory=list)

    # Per-section validation
    section_validations: list[dict] = Field(
        default_factory=list,
        description="Validation details per section"
    )
