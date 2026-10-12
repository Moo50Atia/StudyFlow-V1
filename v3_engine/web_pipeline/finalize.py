"""Export scene prompts and finalize web run outputs without external provider calls."""

from __future__ import annotations

import json
from typing import Any, Dict, List


def collect_run_prompt_manifest(
    run_id: str,
    pdf_archives: List[Dict[str, Any]],
) -> Dict[str, Any]:
    """Collect all prompts from completed PDF archives into a canonical run export manifest."""
    all_prompts: List[Dict[str, Any]] = []
    has_failed = False

    for arch in pdf_archives:
        if arch.get("state") != "completed":
            has_failed = True
            continue

        ordinal = arch.get("pdf_ordinal", 1)
        filename = arch.get("filename", f"PDF_{ordinal}.pdf")

        for s in arch.get("scenes", []):
            all_prompts.append({
                "pdf_ordinal": ordinal,
                "filename": filename,
                "scene_ordinal": s.get("scene_ordinal", 1),
                "section_title": s.get("section_title", f"Scene {s.get('scene_ordinal', 1)}"),
                "scene_type": s.get("scene_type", "Interactive Visualization"),
                "prompt_body": s.get("prompt_body", ""),
            })

    status = "partial" if has_failed else "completed"

    return {
        "schema_version": 1,
        "run_id": run_id,
        "status": status,
        "total_prompts": len(all_prompts),
        "prompts": all_prompts,
    }


def format_prompt_export_json(manifest: Dict[str, Any]) -> str:
    """Format manifest into canonical pretty-printed JSON."""
    return json.dumps(manifest, indent=2, ensure_ascii=False)


def format_prompt_export_text(manifest: Dict[str, Any]) -> str:
    """Format manifest into structured human-readable text for manual prompt execution."""
    lines: List[str] = [
        f"=== V3 SCENE PROMPT EXPORT ===",
        f"Run: {manifest.get('run_id')}",
        f"Total Prompts: {manifest.get('total_prompts')}",
        f"Status: {manifest.get('status')}",
        "",
    ]

    for idx, p in enumerate(manifest.get("prompts", []), start=1):
        lines.extend([
            f"--- [Prompt {idx}] {p.get('section_title')} ---",
            f"PDF: {p.get('filename')} (Ordinal: {p.get('pdf_ordinal')})",
            f"Scene Type: {p.get('scene_type')}",
            f"Description / Specification:",
            p.get("prompt_body", "").strip(),
            "",
        ])

    return "\n".join(lines)
