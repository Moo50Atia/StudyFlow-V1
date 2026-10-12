"""Resume this one eight-PDF NotebookLM experiment from its private checkpoint.

This is a test harness, not the Laravel/Temporal application pipeline. It never
uploads sources or starts a new notebook. A turn with uncertain delivery must be
found in provider history before another Continue can be sent.
"""

import asyncio
import hashlib
import json
import os
import re
import tempfile
from datetime import datetime, timezone
from pathlib import Path

from v3_engine.notebooklm.bundle_parser import parse_combined_bundle
from v3_engine.notebooklm.credentials import get_storage_state_path
from v3_engine.notebooklm.sdk_provider import SdkSessionProvider


ROOT = Path(__file__).resolve().parent
CHECKPOINT = ROOT / "checkpoint.json"
HANDOFFS = ROOT / "lesson_handoffs"
W_LABEL = re.compile(r"^W([0-9]+)\b")
MARKER = re.compile(r"✅\s*Lesson\s*\[?([0-9]+)\]?\s*Completed")
THIRD_HEADER = re.compile(r"^Lec\s*\d+\s*\(\s*Sec\s*\d+\s*\)\s*:", re.IGNORECASE)


def now():
    return datetime.now(timezone.utc).isoformat()


def write_json(path, value):
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(value, stream, ensure_ascii=False, indent=2)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(tmp, 0o600)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def save(data):
    data["updated_at"] = now()
    write_json(CHECKPOINT, data)


def accept_turn(data, ordinal, answer, turn_number=None):
    node = next(
        item for item in data["divide"]["nodes"] if item["work_ordinal"] == ordinal
    )
    entry = {
        "lesson_ordinal": ordinal,
        "mapped_title": node["title"],
        "question": "Start with First lesson" if ordinal == 1 else "Continue",
        "raw_answer": answer,
        "conversation_id": data["conversation_id"],
        "turn_number": turn_number,
        "captured_at": now(),
    }
    data["lessons"].append(entry)
    data["status"] = "lesson_received"
    save(data)  # Save provider output before any parsing or validation.
    parse_and_export(data, entry)


def close_unterminated_third_quotes(raw):
    """Repair display-only missing final quotes; keep the provider raw text intact."""
    lines = raw.splitlines()
    try:
        third_start = lines.index("=== THIRD ===") + 1
        third_end = lines.index("=== END ===")
    except ValueError:
        return raw, 0
    headers = [
        index for index in range(third_start, third_end)
        if THIRD_HEADER.match(lines[index].strip())
    ]
    repaired = 0
    for position, header in enumerate(headers):
        next_header = headers[position + 1] if position + 1 < len(headers) else third_end
        last = next_header - 1
        while last > header and not lines[last].strip():
            last -= 1
        first_text = lines[header].split(":", 1)[1].strip()
        if not first_text:
            first_text = next((line.strip() for line in lines[header + 1:next_header] if line.strip()), "")
        if first_text.startswith('"') and not lines[last].rstrip().endswith('"'):
            lines[last] = lines[last].rstrip() + '"'
            repaired += 1
    return "\n".join(lines), repaired


def parse_and_export(data, entry):
    ordinal = entry["lesson_ordinal"]
    answer = entry["raw_answer"]
    marker = MARKER.search(answer)
    entry["terminal_marker_ordinal"] = int(marker.group(1)) if marker else None
    try:
        try:
            bundle = parse_combined_bundle(answer, ordinal)
        except Exception as original_error:
            if "prompt must be enclosed in quotes" not in str(original_error):
                raise
            normalized, count = close_unterminated_third_quotes(answer)
            if count == 0:
                raise
            bundle = parse_combined_bundle(normalized, ordinal)
            entry["parse_normalization"] = f"closed {count} unterminated Third prompt quote(s)"
        label = W_LABEL.match(bundle.lecture_title)
        if not label or int(label.group(1)) != ordinal:
            raise ValueError(f"Response title is not W{ordinal}: {bundle.lecture_title[:100]}")
        if not (
            len(bundle.first.sections)
            == len(bundle.second.sections)
            == len(bundle.third.sections)
        ):
            raise ValueError("First/Second/Third section counts differ")
        scenes = []
        for first, second, third in zip(
            bundle.first.sections, bundle.second.sections, bundle.third.sections
        ):
            if first.ordinal != second.ordinal or first.ordinal != third.ordinal:
                raise ValueError("First/Second/Third section ordinals differ")
            if len(first.fields) != 11:
                raise ValueError("First section does not contain all eleven fields")
            scenes.append(
                {
                    "scene_ordinal": first.ordinal,
                    "section_title": first.fields["section_identity"],
                    "scene_type": third.scene_type,
                    "generated_prompt": third.prompt_body,
                    "first_output": first.fields,
                    "second_output": {
                        "placeholder": second.placeholder,
                        "perspective_map": second.perspective_map,
                    },
                }
            )
        handoff = {
            "schema_version": 1,
            "kind": "notebooklm_multi_pdf_lesson_handoff",
            "notebook_id": data["notebook_id"],
            "conversation_id": data["conversation_id"],
            "source_ids": [item["source_id"] for item in data["pdf_sources"]],
            "framework_source_id": data["framework_source"]["source_id"],
            "lesson_ordinal": ordinal,
            "mapped_title": entry["mapped_title"],
            "notebooklm_title": bundle.lecture_title,
            "terminal_marker_ordinal": entry["terminal_marker_ordinal"],
            "raw_answer_sha256": hashlib.sha256(answer.encode("utf-8")).hexdigest(),
            "parse_normalization": entry.get("parse_normalization"),
            "captured_at": entry["captured_at"],
            "scenes": scenes,
        }
        handoff_path = HANDOFFS / f"lesson-{ordinal:03d}.json"
        write_json(handoff_path, handoff)
        entry["handoff_path"] = str(handoff_path.relative_to(ROOT))
        entry["parsed"] = {
            "lecture_title": bundle.lecture_title,
            "section_count": len(bundle.first.sections),
            "scene_count": len(scenes),
            "scene_types": [scene["scene_type"] for scene in scenes],
        }
        entry.pop("parse_error", None)
        data["status"] = "lesson_accepted"
        save(data)
        print(
            "lesson_saved",
            ordinal,
            "scenes",
            len(scenes),
            "marker",
            entry["terminal_marker_ordinal"],
            flush=True,
        )
    except Exception as exc:
        entry["parse_error"] = f"{type(exc).__name__}: {str(exc)[:500]}"
        data["status"] = "lesson_parse_error"
        save(data)
        raise


async def main():
    data = json.loads(CHECKPOINT.read_text(encoding="utf-8"))
    total = data["divide"]["work_count"]
    assert total == 29 and len(data["pdf_sources"]) == 8
    for entry in data["lessons"]:
        if not entry.get("handoff_path"):
            parse_and_export(data, entry)

    provider = SdkSessionProvider(
        base_url="https://notebook.google.com",
        storage_state_path=get_storage_state_path(),
    )
    async with provider.open("mo50atiapro") as session:
        if data["status"] in {"lesson_pending", "lesson_uncertain"}:
            ordinal = data["pending_lesson_ordinal"]
            history = await session.conversation.history(
                data["notebook_id"], data["conversation_id"], limit=100
            )
            expected = 2 + len(data["lessons"])
            if len(history) == expected + 1 and history[-1][0] == data["pending_question"]:
                accept_turn(data, ordinal, history[-1][1])
            else:
                raise RuntimeError(
                    "Last delivery remains uncertain. Inspect provider history before retrying."
                )
        if data["status"] not in {"lesson_accepted", "lesson_marker_mismatch"}:
            raise RuntimeError(f"Checkpoint status {data['status']} cannot safely continue")

        ids = [item["source_id"] for item in data["pdf_sources"]]
        ids.append(data["framework_source"]["source_id"])
        for ordinal in range(len(data["lessons"]) + 1, total + 1):
            history = await session.conversation.history(
                data["notebook_id"], data["conversation_id"], limit=100
            )
            if len(history) != 2 + len(data["lessons"]):
                raise RuntimeError("History length changed; refusing duplicate Continue")
            previous = data["lessons"][-1]
            if history[-1] != (previous["question"] if "question" in previous else (
                "Start with First lesson" if previous["lesson_ordinal"] == 1 else "Continue"
            ), previous["raw_answer"]):
                raise RuntimeError("Provider history differs from saved previous turn")
            data["status"] = "lesson_pending"
            data["pending_lesson_ordinal"] = ordinal
            data["pending_question"] = "Continue"
            save(data)
            print("lesson_sent", ordinal, flush=True)
            try:
                response = await session.conversation.ask_once(
                    data["notebook_id"], "Continue", ids, data["conversation_id"]
                )
            except Exception as exc:
                data["status"] = "lesson_uncertain"
                data["last_error"] = f"{type(exc).__name__}: {str(exc)[:500]}"
                save(data)
                raise
            if response.conversation_id != data["conversation_id"]:
                data["status"] = "conversation_conflict"
                data["last_error"] = "Provider returned another conversation ID"
                save(data)
                raise RuntimeError(data["last_error"])
            accept_turn(data, ordinal, response.text, response.turn_number)
        data["status"] = "complete"
        save(data)
        print("all_lessons_saved", len(data["lessons"]), flush=True)


if __name__ == "__main__":
    asyncio.run(main())
