"""Missing-aware lesson projection and canonical context hashing (Spec 019)."""

from __future__ import annotations

from typing import Any

from v3_engine.web_pipeline.contracts import compute_canonical_digest

ELEVEN_FIRST_FIELDS = [
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


def project_first_fields(raw_fields: dict[str, Any]) -> dict[str, Any]:
    """
    FR-007, FR-008: Project eleven First fields, distinguishing NULL (missing) from empty string.
    """
    fields: dict[str, Any] = {}
    missing_fields: list[str] = []

    for key in ELEVEN_FIRST_FIELDS:
        if key in raw_fields:
            val = raw_fields[key]
            if val is None:
                fields[key] = None
                missing_fields.append(key)
            else:
                fields[key] = str(val)
        else:
            fields[key] = None
            missing_fields.append(key)

    context_digest = compute_canonical_digest(fields)

    return {
        "fields": fields,
        "missing_fields": missing_fields,
        "is_complete": len(missing_fields) == 0,
        "context_digest": context_digest,
    }


def project_scene_prompts(raw_scenes: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """
    FR-008, FR-026: Project scene prompts, preserving arbitrary scene types and verbatim prompt text.
    """
    projected: list[dict[str, Any]] = []

    for item in raw_scenes:
        ordinal = item.get("scene_ordinal") or item.get("ordinal", 1)
        title = item.get("scene_title") or item.get("title", f"Scene {ordinal}")
        scene_type = item.get("scene_type", "Interactive Visualization")
        prompt_body = item.get("generated_prompt") or item.get("prompt_text") or item.get("prompt_body", "")

        projected.append({
            "scene_ordinal": int(ordinal),
            "scene_title": str(title),
            "scene_type": str(scene_type),
            "prompt_body": str(prompt_body),
            "prompt_sha256": compute_canonical_digest(prompt_body),
        })

    # Sort deterministically by ordinal
    projected.sort(key=lambda s: s["scene_ordinal"])
    return projected
