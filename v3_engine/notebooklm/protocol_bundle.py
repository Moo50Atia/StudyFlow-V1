"""Load the versioned, shared NotebookLM authoring protocol bundle."""

from __future__ import annotations

import json
from functools import lru_cache
from pathlib import Path
from typing import Any


@lru_cache(maxsize=1)
def load_local_worker_protocol() -> dict[str, Any]:
    path = Path(__file__).resolve().parent / "protocols" / "local_worker_v1.json"
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError("Versioned local-worker NotebookLM protocol bundle is unavailable or invalid") from exc
    required = {
        "schema_version", "protocol_id", "protocol_version", "divide_prompt", "execution_prompt",
        "lesson_start_prompt", "lesson_continue_prompt", "combined_response_format", "framework_sections",
    }
    if not isinstance(value, dict) or set(value) != required:
        raise RuntimeError("Versioned local-worker NotebookLM protocol bundle has an invalid schema")
    if value["schema_version"] != 1 or value["protocol_id"] != "notebooklm_v1" or value["protocol_version"] != "notebooklm_per_pdf_web_v1":
        raise RuntimeError("Versioned local-worker NotebookLM protocol bundle has unsupported identity")
    if any(not isinstance(value[key], str) or not value[key].strip() for key in (
        "divide_prompt", "execution_prompt", "lesson_start_prompt", "lesson_continue_prompt", "combined_response_format",
    )):
        raise RuntimeError("Versioned local-worker NotebookLM protocol contains empty prompt text")
    sections = value["framework_sections"]
    if not isinstance(sections, dict) or set(sections) != {"first", "second", "third"} or any(
        not isinstance(text, str) or not text.strip() for text in sections.values()
    ):
        raise RuntimeError("Versioned local-worker framework sections have an invalid schema")
    return value
