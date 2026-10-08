import pytest
import json
from v3_engine.review.routing import resolve_correction_route, build_teacher_correction_payload

def test_visual_interaction_routes_to_scene_repair():
    route_kind = resolve_correction_route("visual_interaction")
    assert route_kind == "scene_repair"

def test_factual_source_routes_to_prompt_requalification():
    route_kind = resolve_correction_route("factual_source")
    assert route_kind == "prompt_requalification"

def test_mixed_routes_to_prompt_requalification():
    route_kind = resolve_correction_route("mixed")
    assert route_kind == "prompt_requalification"

def test_unknown_kind_raises_error():
    with pytest.raises(ValueError):
        resolve_correction_route("unknown_category")

def test_build_teacher_correction_payload_exact_keys_and_values():
    section_ref = "00000000-0000-0000-0000-000000000001"
    fields = {
        "section_identity": "Section 1",
        "core_understanding": "Core concept",
        "visual_first_explanation": "Visual explanation",
        "visual_scene_script": "Scene script",
        "dynamic_view_blueprint": "Blueprint",
        "visual_learning_outcome": "Outcome",
        "concept_check": "Check",
        "conceptual_summary": "Summary",
        "colloquial_egyptian_explanation": "Egyptian",
        "laws_if_any": "None",
        "real_life_example": "Example",
    }
    previous_prompt = "Process: Old prompt"
    teacher_note = "Please clarify the formula."
    prev_qual = {
        "choice": "pass",
        "choice_confidence": 0.95,
        "score": 90.0,
        "score_confidence": 0.95,
        "noul_probability": 0.95,
    }

    payload = build_teacher_correction_payload(
        section_ref=section_ref,
        fields=fields,
        previous_prompt=previous_prompt,
        teacher_note=teacher_note,
        previous_qualification=prev_qual,
    )

    expected_keys = {
        "section_ref",
        "fields",
        "previous_prompt",
        "teacher_note",
        "previous_qualification",
        "reason",
    }
    assert set(payload.keys()) == expected_keys
    assert payload["reason"] == "teacher_factual_correction"
    assert payload["section_ref"] == section_ref
    assert payload["teacher_note"] == teacher_note
    assert len(payload["fields"]) == 11

    # Verify JSON serializability
    encoded = json.dumps(payload)
    assert isinstance(encoded, str)
