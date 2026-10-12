"""Handoff loader, normalizer, and validator for terminal scene generation (Spec 017)."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
from typing import Any, Optional

from v3_engine.scene_generation.contracts import (
    HandoffInventory,
    InputIssue,
    InputSnapshot,
    SceneInput,
)

MAX_INPUT_FILE_BYTES = 32 * 1024 * 1024  # 32 MiB
SUPPORTED_KINDS = {
    "notebooklm_multi_pdf_lesson_raw_handoff",
    "notebooklm_multi_pdf_lesson_handoff",
    "notebooklm_lesson_handoff",
}


class InputError(Exception):
    """Raised when handoff input files or structures are invalid (exit code 2)."""


def _resolve_lesson_title(lesson_dict: dict[str, Any], lesson_ordinal: int) -> str:
    for candidate in [
        lesson_dict.get("mapped_title"),
        lesson_dict.get("lesson_title"),
        lesson_dict.get("notebooklm_title"),
    ]:
        if isinstance(candidate, str) and candidate.strip():
            return candidate.strip()
    return f"Lesson {lesson_ordinal}"


def _resolve_section_title(scene_dict: dict[str, Any], scene_ordinal: int) -> str:
    candidate = scene_dict.get("section_title")
    if isinstance(candidate, str) and candidate.strip():
        return candidate.strip()
    return f"Scene {scene_ordinal}"


def _read_json_file(file_path: Path) -> tuple[dict[str, Any], bytes, str]:
    if file_path.is_symlink():
        raise InputError(f"Symbolic-link input file rejected: {file_path}")
    size = file_path.stat().st_size
    if size > MAX_INPUT_FILE_BYTES:
        raise InputError(f"Input file exceeds 32 MiB cap ({size} bytes): {file_path}")

    raw_bytes = file_path.read_bytes()
    sha256 = hashlib.sha256(raw_bytes).hexdigest()
    try:
        data = json.loads(raw_bytes.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise InputError(f"Input file is not valid UTF-8 JSON: {file_path}") from exc

    if not isinstance(data, dict):
        raise InputError(f"Input JSON root must be an object: {file_path}")
    return data, raw_bytes, sha256


def load_handoff_inventory(input_path: Path) -> HandoffInventory:
    """Load and normalize handoff JSON file or directory into HandoffInventory."""
    path = Path(input_path)
    if not path.exists():
        raise InputError(f"Input path does not exist: {path}")
    if path.is_symlink():
        raise InputError(f"Symbolic-link input path rejected: {path}")

    # Determine files to load
    files_to_load: list[Path] = []
    if path.is_dir():
        for child in path.iterdir():
            if child.is_file() and child.name.startswith("lesson-") and child.name.endswith(".json"):
                if child.is_symlink():
                    raise InputError(f"Symbolic-link input file rejected: {child}")
                files_to_load.append(child)
        if not files_to_load:
            raise InputError(f"No direct lesson-*.json files found in input directory: {path}")
    else:
        files_to_load.append(path)

    # First pass: parse JSON and build temporary inventory to sort files
    raw_files: list[tuple[Path, dict[str, Any], bytes, str]] = []
    for f in files_to_load:
        data, b, sha = _read_json_file(f)
        raw_files.append((f, data, b, sha))

    # Helper to get the primary lesson ordinal for sorting files
    def file_sort_key(item: tuple[Path, dict[str, Any], bytes, str]) -> tuple[int, str]:
        d = item[1]
        if d.get("kind") == "notebooklm_lesson_handoff":
            lessons = d.get("lessons", [])
            if not isinstance(lessons, list):
                raise InputError(f"lessons must be a list in {item[0]}")
            ordinals = []
            for lesson in lessons:
                if not isinstance(lesson, dict):
                    raise InputError(f"Lesson entry must be an object in {item[0]}")
                value = lesson.get("lesson_ordinal")
                if isinstance(value, bool) or not isinstance(value, int) or value < 1:
                    raise InputError(f"Missing or invalid lesson_ordinal {value!r} in {item[0]}")
                ordinals.append(value)
            if len(ordinals) != len(set(ordinals)):
                raise InputError(f"Duplicate lesson ordinal in archive {item[0]}")
            first_ord = ordinals[0] if ordinals else 0
            return (first_ord, item[0].name)
        value = d.get("lesson_ordinal", 0)
        if isinstance(value, bool) or not isinstance(value, int) or value < 1:
            raise InputError(f"Missing or invalid lesson_ordinal {value!r} in {item[0]}")
        return (value, item[0].name)

    raw_files.sort(key=file_sort_key)

    common_notebook_id: Optional[str] = None
    common_conversation_id: Optional[str] = None
    expected_work_count: Optional[int] = None
    input_snapshots: list[InputSnapshot] = []
    scene_inputs: list[SceneInput] = []
    input_issues: list[InputIssue] = []
    observed_lesson_ordinals: set[int] = set()
    lesson_identity_sources: dict[int, Path] = {}
    observed_scene_keys: set[str] = set()

    for source_idx, (fpath, doc, raw_bytes, sha) in enumerate(raw_files):
        # Validate schema_version
        if doc.get("schema_version") != 1:
            raise InputError(f"Unsupported schema_version {doc.get('schema_version')} in {fpath}; expected 1")

        kind = doc.get("kind")
        if kind not in SUPPORTED_KINDS:
            raise InputError(f"Unsupported handoff kind '{kind}' in {fpath}")

        nb_id = doc.get("notebook_id")
        conv_id = doc.get("conversation_id")
        if not isinstance(nb_id, str) or not nb_id.strip():
            raise InputError(f"Missing or nonstring notebook_id in {fpath}")
        if not isinstance(conv_id, str) or not conv_id.strip():
            raise InputError(f"Missing or nonstring conversation_id in {fpath}")

        if common_notebook_id is None:
            common_notebook_id = nb_id
            common_conversation_id = conv_id
        else:
            if nb_id != common_notebook_id:
                raise InputError(f"Conflicting notebook_id '{nb_id}' vs '{common_notebook_id}' across input files")
            if conv_id != common_conversation_id:
                raise InputError(f"Conflicting conversation_id '{conv_id}' vs '{common_conversation_id}' across input files")

        # Expected work count check
        file_exp = doc.get("expected_work_count")
        if file_exp is not None:
            if isinstance(file_exp, bool) or not isinstance(file_exp, int) or file_exp < 1:
                raise InputError(f"Invalid expected_work_count {file_exp!r} in {fpath}")
            if expected_work_count is None:
                expected_work_count = file_exp
            elif expected_work_count != file_exp:
                raise InputError(
                    f"Contradictory expected_work_count ({expected_work_count} vs {file_exp}) in input files"
                )

        snapshot_path = f"snapshot/inputs/{source_idx + 1:04d}.json"
        input_snapshots.append(
            InputSnapshot(
                source_index=source_idx,
                original_path=str(fpath.resolve()),
                relative_name=fpath.name,
                sha256=sha,
                bytes=len(raw_bytes),
                snapshot_path=snapshot_path,
            )
        )

        # Extract lessons
        if kind == "notebooklm_lesson_handoff":
            lessons_list = doc.get("lessons")
            if not isinstance(lessons_list, list):
                raise InputError(f"kind 'notebooklm_lesson_handoff' requires lessons array in {fpath}")
            lessons_to_process = [(idx, l_dict) for idx, l_dict in enumerate(lessons_list)]
            is_archive = True
        else:
            lessons_to_process = [(None, doc)]
            is_archive = False

        for lesson_idx, l_dict in lessons_to_process:
            if not isinstance(l_dict, dict):
                raise InputError(f"Lesson entry must be an object in {fpath}")
            l_ord = l_dict.get("lesson_ordinal")
            if isinstance(l_ord, bool) or not isinstance(l_ord, int) or l_ord < 1:
                raise InputError(f"Missing or invalid lesson_ordinal {l_ord!r} in {fpath}")

            if l_ord in observed_lesson_ordinals and lesson_identity_sources.get(l_ord) != fpath:
                raise InputError(f"Duplicate lesson identity detected: {common_notebook_id}:{common_conversation_id}:W{l_ord}")
            observed_lesson_ordinals.add(l_ord)
            lesson_identity_sources[l_ord] = fpath
            lesson_title = _resolve_lesson_title(l_dict, l_ord)

            scenes_list = l_dict.get("scenes")
            if not isinstance(scenes_list, list):
                raise InputError(f"scenes must be a list in lesson {l_ord} in {fpath}")

            if not scenes_list:
                input_issues.append(
                    InputIssue(lesson_ordinal=l_ord, scene_ordinal=None, code="input_lesson_empty")
                )
                continue

            for s_idx, s_dict in enumerate(scenes_list):
                if not isinstance(s_dict, dict):
                    raise InputError(f"Scene entry must be an object in {fpath}")
                s_ord = s_dict.get("scene_ordinal")
                if isinstance(s_ord, bool) or not isinstance(s_ord, int) or s_ord < 1:
                    raise InputError(f"Missing or invalid scene_ordinal {s_ord!r} in lesson {l_ord}")

                scene_key = f"{common_notebook_id}:{common_conversation_id}:W{l_ord}:S{s_ord}"
                if scene_key in observed_scene_keys:
                    raise InputError(f"Duplicate scene identity detected: {scene_key}")
                observed_scene_keys.add(scene_key)

                section_title = _resolve_section_title(s_dict, s_ord)
                scene_type = s_dict.get("scene_type")
                if scene_type is not None and not isinstance(scene_type, str):
                    scene_type = str(scene_type)

                # Prompt validation
                raw_prompt = s_dict.get("generated_prompt")
                if isinstance(raw_prompt, str) and raw_prompt.strip():
                    prompt = raw_prompt  # verbatim text, do not trim!
                    prompt_sha256 = hashlib.sha256(prompt.encode("utf-8")).hexdigest()
                    input_error = None
                else:
                    prompt = None
                    prompt_sha256 = None
                    input_error = "input_prompt_missing"

                pointer = (
                    f"/lessons/{lesson_idx}/scenes/{s_idx}"
                    if is_archive
                    else f"/scenes/{s_idx}"
                )

                scene_inputs.append(
                    SceneInput(
                        scene_key=scene_key,
                        notebook_id=common_notebook_id,
                        conversation_id=common_conversation_id,
                        lesson_ordinal=l_ord,
                        scene_ordinal=s_ord,
                        lesson_title=lesson_title,
                        section_title=section_title,
                        scene_type=scene_type,
                        prompt=prompt,
                        prompt_sha256=prompt_sha256,
                        input_error=input_error,
                        source_index=source_idx,
                        source_json_pointer=pointer,
                    )
                )

    if common_notebook_id is None or common_conversation_id is None:
        raise InputError("No valid input files were processed.")

    # Sort scene_inputs by (lesson_ordinal, scene_ordinal)
    scene_inputs.sort(key=lambda s: (s.lesson_ordinal, s.scene_ordinal))

    # Check expected_work_count against observed lesson ordinals
    if expected_work_count is not None:
        max_ord = max(observed_lesson_ordinals) if observed_lesson_ordinals else 0
        if expected_work_count < max_ord:
            raise InputError(
                f"expected_work_count ({expected_work_count}) is less than highest lesson ordinal ({max_ord})"
            )
        for ord_val in range(1, expected_work_count + 1):
            if ord_val not in observed_lesson_ordinals:
                input_issues.append(
                    InputIssue(
                        lesson_ordinal=ord_val,
                        scene_ordinal=None,
                        code="input_lesson_missing",
                    )
                )

    # Sort input_issues by (lesson_ordinal, scene_ordinal or 0)
    input_issues.sort(key=lambda issue: (issue.lesson_ordinal, issue.scene_ordinal or 0))

    # Ensure there is at least one usable prompt across all scenes
    has_usable_prompt = any(s.prompt is not None for s in scene_inputs)
    if not has_usable_prompt:
        raise InputError("No usable prompts found in input.")

    return HandoffInventory(
        notebook_id=common_notebook_id,
        conversation_id=common_conversation_id,
        scene_inputs=scene_inputs,
        input_issues=input_issues,
        input_snapshots=input_snapshots,
        expected_work_count=expected_work_count,
    )
