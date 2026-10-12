"""Private JSON handoff for parsed NotebookLM lessons and scene prompts.

The Laravel direct-pipeline path remains database-backed. This archive is a
temporary local handoff for provider-only runs and downstream experiments.
"""
from __future__ import annotations

from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import tempfile
from typing import Any, Mapping

from v3_engine.notebooklm.bundle_parser import ParsedCombinedBundle


class LessonArchiveError(Exception):
    """Raised when a local NotebookLM handoff cannot be written safely."""


def default_handoff_dir() -> Path:
    configured = os.environ.get("V3_NOTEBOOKLM_HANDOFF_DIR")
    if configured:
        return Path(configured).expanduser()
    repo_root = Path(__file__).resolve().parents[2]
    return repo_root / "storage" / "app" / "private" / "v3" / "notebooklm-handoffs"


def archive_lesson_bundle(
    *,
    notebook_id: str,
    conversation_id: str,
    source_ids: Mapping[str, str],
    lesson_ordinal: int,
    lesson_title: str,
    expected_work_count: int,
    bundle: ParsedCombinedBundle | None = None,
    raw_response: str | None = None,
    parse_status: str | None = None,
    output_dir: Path | None = None,
) -> Path:
    """Atomically archive one provider response, then enrich it if parsing works.

    Each scene remains attached to its lesson and section identity. Repeating
    the exact same response is idempotent; changed content for the same lesson
    in the same NotebookLM conversation is rejected rather than overwriting it.
    A raw-only record is a valid checkpoint, so strict parsing cannot discard a
    response or prevent the caller from requesting the next mapped lesson.
    """
    if not _SAFE_ID.fullmatch(notebook_id) or not _SAFE_ID.fullmatch(conversation_id):
        raise LessonArchiveError("Notebook and conversation IDs must be safe filename identifiers.")
    if lesson_ordinal < 1 or expected_work_count < lesson_ordinal:
        raise LessonArchiveError("Lesson ordinal is outside the mapped work-item range.")
    title = lesson_title.strip()
    if not title:
        raise LessonArchiveError("Mapped lesson title is required.")
    required_sources = {"original_pdf", "framework_steps"}
    if set(source_ids) != required_sources or any(not source_ids[k].strip() for k in required_sources):
        raise LessonArchiveError("Source IDs must identify the original PDF and framework steps.")

    scenes: list[dict[str, Any]] = []
    if bundle is None and raw_response is None:
        raise LessonArchiveError("A raw provider response or parsed lesson bundle is required.")
    if bundle is not None:
        first_sections = bundle.first.sections
        second_sections = bundle.second.sections
        third_sections = bundle.third.sections
        counts = {len(first_sections), len(second_sections), len(third_sections)}
        if len(counts) != 1 or not first_sections:
            raise LessonArchiveError("First, Second, and Third section counts must match and be nonempty.")

        for first, second, third in zip(first_sections, second_sections, third_sections, strict=True):
            if first.ordinal != second.ordinal or first.ordinal != third.ordinal:
                raise LessonArchiveError("First, Second, and Third section ordinals must match.")
            if len(first.fields) != 11:
                raise LessonArchiveError(f"Section {first.ordinal} must preserve all eleven First-step fields.")
            section_title = first.fields.get("section_identity", "").strip()
            if not section_title:
                raise LessonArchiveError(f"Section {first.ordinal} is missing its First-step section identity.")
            if not third.prompt_body.strip():
                raise LessonArchiveError(f"Section {first.ordinal} has an empty generated scene prompt.")
            scenes.append({
                "scene_ordinal": third.ordinal,
                "section_title": section_title,
                "scene_type": third.scene_type,
                "generated_prompt": third.prompt_body,
                "first_output": {
                    "section_ordinal": first.ordinal,
                    "fields": dict(first.fields),
                },
                "second_output": {
                    "section_ordinal": second.ordinal,
                    "placeholder": second.placeholder,
                    "perspective_map": dict(second.perspective_map),
                },
            })

    output_dir = (output_dir or default_handoff_dir()).expanduser()
    if output_dir.is_symlink():
        raise LessonArchiveError("Refusing a symbolic-link handoff directory.")
    output_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    if output_dir.is_symlink():
        raise LessonArchiveError("Refusing a symbolic-link handoff directory.")
    os.chmod(output_dir, 0o700)

    filename = f"notebook-{notebook_id}--conversation-{conversation_id}.json"
    target = output_dir / filename
    if target.is_symlink():
        raise LessonArchiveError("Refusing a symbolic-link handoff file.")
    lock_path = output_dir / f".{filename}.lock"
    if lock_path.is_symlink():
        raise LessonArchiveError("Refusing a symbolic-link handoff lock.")

    lesson_key = f"{notebook_id}:{conversation_id}:W{lesson_ordinal}"
    digest_payload = json.dumps({
        "lecture_title": bundle.lecture_title,
        "raw_first": bundle.raw_first,
        "raw_second": bundle.raw_second,
        "raw_third": bundle.raw_third,
    }, ensure_ascii=False, sort_keys=True, separators=(",", ":")) if bundle is not None else None
    bundle_sha256 = hashlib.sha256(digest_payload.encode("utf-8")).hexdigest() if digest_payload else None
    response_sha256 = hashlib.sha256(raw_response.encode("utf-8")).hexdigest() if raw_response is not None else bundle_sha256
    now = datetime.now(timezone.utc).isoformat(timespec="seconds")

    lock_fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        os.fchmod(lock_fd, 0o600)
        fcntl.flock(lock_fd, fcntl.LOCK_EX)
        if target.exists():
            if target.is_symlink():
                raise LessonArchiveError("Refusing a symbolic-link handoff file.")
            try:
                document = json.loads(target.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError) as exc:
                raise LessonArchiveError("Existing handoff JSON is unreadable; it was not overwritten.") from exc
            if (
                not isinstance(document, dict)
                or document.get("schema_version") != 1
                or document.get("notebook_id") != notebook_id
                or document.get("conversation_id") != conversation_id
                or document.get("sources") != dict(source_ids)
                or document.get("expected_work_count") != expected_work_count
                or not isinstance(document.get("lessons"), list)
            ):
                raise LessonArchiveError("Existing handoff does not match this notebook run.")
        else:
            document = {
                "schema_version": 1,
                "kind": "notebooklm_lesson_handoff",
                "provider": "notebooklm-py",
                "notebook_id": notebook_id,
                "conversation_id": conversation_id,
                "sources": dict(source_ids),
                "expected_work_count": expected_work_count,
                "created_at": now,
                "lessons": [],
            }

        lesson = {
            "lesson_ref": lesson_key,
            "lesson_ordinal": lesson_ordinal,
            "lesson_title": title,
            "notebooklm_title": bundle.lecture_title if bundle is not None else None,
            "terminal_marker_ordinal": bundle.terminal_marker_ordinal if bundle is not None else None,
            "response_sha256": response_sha256,
            "bundle_sha256": bundle_sha256,
            "parse_status": parse_status or ("parsed" if bundle is not None else "raw_only"),
            "captured_at": now,
            "scenes": scenes,
        }
        if raw_response is not None:
            lesson["raw_response"] = raw_response
        existing = next((item for item in document["lessons"] if item.get("lesson_ref") == lesson_key), None)
        if existing is not None:
            existing_response_sha = existing.get("response_sha256")
            same_response = existing_response_sha == response_sha256 if existing_response_sha else (
                bundle_sha256 is not None and existing.get("bundle_sha256") == bundle_sha256
            )
            if not same_response:
                raise LessonArchiveError("This lesson already has different content in the same conversation.")
            # The live caller first commits the untouched answer, then enriches
            # the same record with parsed fields. Never replace its raw answer.
            if bundle is None or existing.get("bundle_sha256") is not None:
                return target
            lesson["captured_at"] = existing.get("captured_at", now)
            for index, item in enumerate(document["lessons"]):
                if item.get("lesson_ref") == lesson_key:
                    document["lessons"][index] = lesson
                    break
        else:
            document["lessons"].append(lesson)

        document["lessons"].sort(key=lambda item: item["lesson_ordinal"])
        document["status"] = (
            "complete"
            if [item["lesson_ordinal"] for item in document["lessons"]]
            == list(range(1, expected_work_count + 1))
            else "partial"
        )
        document["updated_at"] = now

        fd, temp_name = tempfile.mkstemp(prefix=f".{filename}.", suffix=".tmp", dir=output_dir)
        temp_path = Path(temp_name)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                json.dump(document, handle, ensure_ascii=False, indent=2)
                handle.write("\n")
                handle.flush()
                os.fsync(handle.fileno())
            os.chmod(temp_path, 0o600)
            os.replace(temp_path, target)
            dir_fd = os.open(output_dir, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
            try:
                os.fsync(dir_fd)
            finally:
                os.close(dir_fd)
        finally:
            temp_path.unlink(missing_ok=True)
        return target
    finally:
        fcntl.flock(lock_fd, fcntl.LOCK_UN)
        os.close(lock_fd)


_SAFE_ID = re.compile(r"^[A-Za-z0-9_-]{1,128}$")
