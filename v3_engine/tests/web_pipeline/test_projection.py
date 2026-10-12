"""Projection tests for missing-aware First lesson model and Third scene prompts (T013)."""

import pytest
from v3_engine.web_pipeline.contracts import compute_canonical_digest


ELEVEN_FIELDS = [
    "lesson_title",
    "core_concept",
    "learning_objectives",
    "misconceptions",
    "governing_formulas",
    "numerical_example",
    "visual_representation",
    "interactive_controls",
    "variables_and_ranges",
    "laws_if_any",
    "real_world_application",
]


def test_eleven_field_completeness_and_structure():
    """FR-007, FR-008: Eleven First fields must be preserved and recognized."""
    from v3_engine.web_pipeline.projection import project_first_fields

    raw_input = {k: f"content_{k}" for k in ELEVEN_FIELDS}
    result = project_first_fields(raw_input)

    assert result["is_complete"] is True
    assert result["missing_fields"] == []
    for f in ELEVEN_FIELDS:
        assert f in result["fields"]
        assert result["fields"][f] == f"content_{f}"


def test_null_vs_empty_field_distinction():
    """FR-007, A04: Null field is identified as missing; empty string is supplied value."""
    from v3_engine.web_pipeline.projection import project_first_fields

    input_with_null = {k: f"content_{k}" for k in ELEVEN_FIELDS}
    input_with_null["misconceptions"] = None
    input_with_null["laws_if_any"] = ""  # empty string

    res = project_first_fields(input_with_null)
    assert res["is_complete"] is False
    assert "misconceptions" in res["missing_fields"]
    assert "laws_if_any" not in res["missing_fields"]
    assert res["fields"]["misconceptions"] is None
    assert res["fields"]["laws_if_any"] == ""


def test_arbitrary_scene_types_and_multiple_scenes_preserved():
    """FR-008, FR-026: Scene types may be arbitrary custom strings; prompts remain verbatim."""
    from v3_engine.web_pipeline.projection import project_scene_prompts

    raw_scenes = [
        {
            "scene_ordinal": 1,
            "scene_title": "Curvature Dynamics",
            "scene_type": "Osculating Circle 3D Physics Simulator",
            "generated_prompt": "Exact raw prompt text for scene 1 with specific tokens.\nPreserve unmodified.",
        },
        {
            "scene_ordinal": 2,
            "scene_title": "Normal Acceleration Plot",
            "scene_type": "Arbitrary Custom Canvas 2D",
            "generated_prompt": "Exact raw prompt text for scene 2.\nDo not rewrite or truncate.",
        },
    ]

    projected = project_scene_prompts(raw_scenes)
    assert len(projected) == 2
    assert projected[0]["scene_type"] == "Osculating Circle 3D Physics Simulator"
    assert projected[1]["scene_type"] == "Arbitrary Custom Canvas 2D"
    assert projected[0]["prompt_body"] == raw_scenes[0]["generated_prompt"]
    assert projected[1]["prompt_body"] == raw_scenes[1]["generated_prompt"]
