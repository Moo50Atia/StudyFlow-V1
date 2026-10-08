"""
Review Correction Routing (Spec 013).
Maps teacher feedback categories to correction execution pipelines and formats payloads.
"""

def resolve_correction_route(feedback_kind: str) -> str:
    if feedback_kind == "visual_interaction":
        return "scene_repair"
    elif feedback_kind in ("factual_source", "mixed"):
        return "prompt_requalification"
    else:
        raise ValueError(f"Unknown feedback kind: {feedback_kind}")

def build_teacher_correction_payload(
    section_ref: str,
    fields: dict,
    previous_prompt: str,
    teacher_note: str,
    previous_qualification: dict,
) -> dict:
    """
    Constructs TeacherCorrectionPayloadV1 with exact keys per Spec 013.
    """
    return {
        "section_ref": section_ref,
        "fields": fields,
        "previous_prompt": previous_prompt,
        "teacher_note": teacher_note,
        "previous_qualification": previous_qualification,
        "reason": "teacher_factual_correction",
    }
