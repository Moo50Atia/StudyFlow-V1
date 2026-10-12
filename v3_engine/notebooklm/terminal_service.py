"""Terminal service for multi-PDF NotebookLM execution steps and credential isolation (Spec 018)."""

from __future__ import annotations

import hashlib
import json
import logging
import os
import re
import shutil
import stat
import tempfile
from pathlib import Path
from typing import Any, Sequence

from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.notebooklm.bundle_parser import parse_combined_bundle
from v3_engine.notebooklm.ports import (
    AuthenticationRequiredError,
    ChatFormatSettings,
    NotebookRef,
    ProviderError,
    SessionProvider,
    SourceReceipt,
    TurnAnswer,
)
from v3_engine.notebooklm.protocol import (
    COMBINED_RESPONSE_FORMAT_TEXT,
    parse_direct_divide_map,
)
try:
    from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
except ImportError:
    SdkSessionProvider = None

logger = logging.getLogger("v3_engine.notebooklm.terminal")

THIRD_HEADER = re.compile(r"^Lec\s*\d+\s*\(\s*Sec\s*\d+\s*\)\s*:", re.I)
LOOSE_THIRD_HEADER = re.compile(
    r"^Lec\s*\d+\s*\(\s*Sec\s*(\d+)\s*\)\s*:\s*(.*)$", re.I | re.M
)
W_LABEL = re.compile(r"^W(\d+)\b")
MARKER = re.compile(r"✅\s*Lesson\s*\[?(\d+)\]?\s*Completed")


def atomic_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(data, stream, ensure_ascii=False, indent=2)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name, 0o600)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def normalize_map(raw: str) -> str:
    lines = []
    for line in raw.splitlines():
        stripped = line.strip()
        if not stripped or stripped == "---":
            continue
        if stripped.startswith("#"):
            stripped = re.sub(r"^#+\s*", "", stripped)
            if not stripped.lower().startswith(("chapter ", "mini chapter ", "lesson ")):
                continue
            lines.append(stripped)
        else:
            lines.append(line)
    return "\n".join(lines)


def loose_work_nodes(raw: str) -> list[dict]:
    """Derive a Continue count from a noncanonical map without judging its quality."""
    nodes = []
    for line in raw.splitlines():
        match = re.search(
            r"\bLesson(?:\s+[0-9]+(?:\.[0-9]+)*)?\s*(?::|=>|-)\s*(.+)$",
            line.strip(),
            re.I,
        )
        if match:
            title = match.group(1).strip().strip("* ")
            if title:
                nodes.append({"kind": "lesson", "title": title, "work_ordinal": len(nodes) + 1})
    if nodes:
        return nodes
    for line in raw.splitlines():
        match = re.search(
            r"\bMini\s+Chapter(?:\s+[0-9]+(?:\.[0-9]+)*)?\s*(?::|=>|-)\s*(.+)$",
            line.strip(),
            re.I,
        )
        if match:
            title = match.group(1).strip().strip("* ")
            if title:
                nodes.append({"kind": "mini_chapter", "title": title, "work_ordinal": len(nodes) + 1})
    return nodes


def raw_artifacts(answer: str) -> dict[str, str | None]:
    """Keep every byte in raw_answer; slices here are best-effort conveniences."""
    markers = [
        (name, answer.find(marker), len(marker))
        for name, marker in (
            ("first", "=== FIRST ==="),
            ("second", "=== SECOND ==="),
            ("third", "=== THIRD ==="),
            ("end", "=== END ==="),
        )
    ]
    result: dict[str, str | None] = {"first": None, "second": None, "third": None}
    if all(position >= 0 for _, position, _ in markers) and all(
        markers[i][1] < markers[i + 1][1] for i in range(3)
    ):
        for i in range(3):
            result[markers[i][0]] = answer[
                markers[i][1] + markers[i][2] : markers[i + 1][1]
            ].strip()
    return result


def loose_scene_prompts(third: str | None) -> list[dict]:
    if not third:
        return []
    headers = list(LOOSE_THIRD_HEADER.finditer(third))
    scenes = []
    for index, header in enumerate(headers):
        stop = headers[index + 1].start() if index + 1 < len(headers) else len(third)
        prompt = (header.group(2) + "\n" + third[header.end() : stop]).strip()
        if prompt.startswith(('"', "'")):
            quote = prompt[0]
            prompt = prompt[1:]
            if prompt.endswith(quote):
                prompt = prompt[:-1]
        prompt = prompt.strip()
        first_line = next((line.strip() for line in prompt.splitlines() if line.strip()), "")
        scene_type = re.sub(r"^Scene\s+Type\s*[:.]\s*", "", first_line, flags=re.I)
        scenes.append({
            "scene_ordinal": int(header.group(1)),
            "scene_type": scene_type.split(":", 1)[0].strip(),
            "generated_prompt": prompt,
        })
    return scenes


def normalize_third_quotes(raw: str) -> tuple[str, int]:
    lines = raw.splitlines()
    try:
        start = lines.index("=== THIRD ===") + 1
        end = lines.index("=== END ===")
    except ValueError:
        return raw, 0
    headers = [index for index in range(start, end) if THIRD_HEADER.match(lines[index].strip())]
    changed = 0
    for position, header in enumerate(headers):
        following = headers[position + 1] if position + 1 < len(headers) else end
        last = following - 1
        while last > header and not lines[last].strip():
            last -= 1
        first = lines[header].split(":", 1)[1].strip()
        if not first:
            first_line = next(
                (index for index in range(header + 1, following) if lines[index].strip()),
                None,
            )
            first = lines[first_line].strip() if first_line is not None else ""
        else:
            first_line = header
        if first and not first.startswith('"') and first_line is not None:
            if first_line == header:
                prefix, body = lines[header].split(":", 1)
                lines[header] = prefix + ': "' + body.strip()
            else:
                lines[first_line] = '"' + lines[first_line].lstrip()
            changed += 1
        if first and not lines[last].rstrip().endswith('"'):
            lines[last] = lines[last].rstrip() + '"'
            changed += 1
    return "\n".join(lines), changed


def bootstrap_subject_credentials(
    master_storage_path: Path,
    subject_run_id: str,
    session_base_dir: Path | None = None,
) -> Path:
    """Isolate credentials for a subject into a dedicated 0700/0600 storage directory and disable browser refresh."""
    os.environ.pop("NOTEBOOKLM_REFRESH_CMD", None)

    if session_base_dir is not None:
        base_dir = session_base_dir
    else:
        base_dir = Path.home() / ".notebooklm" / "v3-terminal-sessions"

    subject_dir = base_dir / subject_run_id
    subject_dir.mkdir(parents=True, exist_ok=True)
    os.chmod(subject_dir, stat.S_IRWXU)  # 0700

    target_file = subject_dir / "storage_state.json"
    shutil.copy2(master_storage_path, target_file)
    os.chmod(target_file, stat.S_IRUSR | stat.S_IWUSR)  # 0600

    return target_file


class NotebookTerminalService:
    def __init__(self, session_provider: SessionProvider | None = None):
        self.session_provider = session_provider or SdkSessionProvider()

    async def verify_session(
        self,
        proof_notebook_id: str,
        storage_path: Path | None = None,
    ) -> bool:
        async with self.session_provider.open(storage_path=storage_path) as session:
            role = await session.files.verify_owner(proof_notebook_id)
            if role != "owner":
                raise AuthenticationRequiredError(
                    f"Owner verification failed for approved account mo50atiapro (role={role!r})",
                    raw_detail=f"proof_notebook={proof_notebook_id}, role={role}",
                )
            return True

    async def create_notebook(
        self,
        title: str,
        storage_path: Path | None = None,
    ) -> NotebookRef:
        async with self.session_provider.open(storage_path=storage_path) as session:
            return await session.files.create_notebook(title)

    async def upload_source(
        self,
        notebook_id: str,
        local_path: Path,
        mime_type: str,
        title: str,
        storage_path: Path | None = None,
    ) -> SourceReceipt:
        async with self.session_provider.open(storage_path=storage_path) as session:
            return await session.files.upload_file(
                notebook_id=notebook_id,
                local_path=local_path,
                mime_type=mime_type,
                title=title,
            )

    async def poll_sources(
        self,
        notebook_id: str,
        storage_path: Path | None = None,
    ) -> list[SourceReceipt]:
        async with self.session_provider.open(storage_path=storage_path) as session:
            return await session.files.list_sources(notebook_id)

    async def configure_chat(
        self,
        notebook_id: str,
        format_text: str,
        storage_path: Path | None = None,
    ) -> None:
        async with self.session_provider.open(storage_path=storage_path) as session:
            await session.conversation.configure_format(notebook_id, format_text)

    async def send_turn(
        self,
        notebook_id: str,
        question: str,
        source_ids: Sequence[str],
        conversation_id: str | None = None,
        storage_path: Path | None = None,
        fresh_after_conversation_id: str | None = None,
    ) -> TurnAnswer:
        async with self.session_provider.open(storage_path=storage_path) as session:
            return await session.conversation.ask_once(
                notebook_id=notebook_id,
                question=question,
                source_ids=source_ids,
                conversation_id=conversation_id,
                **({"fresh_after_conversation_id": fresh_after_conversation_id}
                   if fresh_after_conversation_id is not None else {}),
            )

    async def get_history(
        self,
        notebook_id: str,
        conversation_id: str,
        storage_path: Path | None = None,
    ) -> list[tuple[str, str]]:
        async with self.session_provider.open(storage_path=storage_path) as session:
            return await session.conversation.history(notebook_id, conversation_id)

    async def get_current_conversation_id(
        self,
        notebook_id: str,
        storage_path: Path | None = None,
    ) -> str | None:
        async with self.session_provider.open(storage_path=storage_path) as session:
            return await session.conversation.current_conversation_id(notebook_id)

    async def reset_conversation(
        self, notebook_id: str, conversation_id: str, storage_path: Path | None = None,
    ) -> None:
        async with self.session_provider.open(storage_path=storage_path) as session:
            await session.conversation.reset_conversation(notebook_id, conversation_id)

    def capture_turn(
        self,
        turn_kind: str,
        raw_answer: str,
        capture_raw: bool = True,
        lesson_ordinal: int | None = None,
        mapped_title: str | None = None,
        output_dir: Path | None = None,
        notebook_id: str | None = None,
        conversation_id: str | None = None,
        pdf_source_ids: list[str] | None = None,
        framework_source_id: str | None = None,
        pdf_metadata: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        """Parse map or best-effort export saved receipt; never asks a model."""
        if turn_kind == "divide":
            normalized = normalize_map(raw_answer)
            parse_warning = None
            if capture_raw:
                try:
                    nodes = parse_direct_divide_map(normalized)
                    dict_nodes = [vars(n) for n in nodes]
                    mode = "strict"
                except Exception as exc:
                    dict_nodes = loose_work_nodes(raw_answer)
                    mode = "loose"
                    parse_warning = f"{type(exc).__name__}: {str(exc)[:300]}"
            else:
                nodes = parse_direct_divide_map(normalized)
                dict_nodes = [vars(n) for n in nodes]
                mode = "strict"

            work_count = sum(node.get("work_ordinal") is not None for node in dict_nodes)
            if work_count < 1:
                raise RuntimeError(
                    "Divide response contains no identifiable work items; Continue count is unknown"
                )

            res = {
                "question": DIVIDE_PROMPT,
                "raw_answer": raw_answer,
                "nodes": dict_nodes,
                "work_count": work_count,
                "map_parse_mode": mode,
                "status": "divide_parsed",
            }
            if parse_warning:
                res["map_parse_warning"] = parse_warning
            return res

        elif turn_kind == "execution":
            is_ready = raw_answer.strip() == "System Ready"
            res = {
                "question": EXECUTION_PROMPT,
                "raw_answer": raw_answer,
                "status": "execution_ready",
            }
            if not is_ready:
                if capture_raw:
                    res["output_warning"] = "Execution acknowledgement was not exactly System Ready"
                else:
                    raise RuntimeError("Execution response was not exactly System Ready")
            return res

        elif turn_kind == "lesson":
            if lesson_ordinal is None:
                raise ValueError("lesson_ordinal is required for lesson turn capture")
            mapped_title_str = mapped_title or f"Lesson {lesson_ordinal}"

            if capture_raw:
                artifacts = raw_artifacts(raw_answer)
                scenes = loose_scene_prompts(artifacts["third"])
                marker = MARKER.search(raw_answer)
                marker_ordinal = int(marker.group(1)) if marker else None
                output_warnings = [
                    w
                    for w in (
                        (
                            "Combined artifact delimiters missing or out of order"
                            if any(v is None for v in artifacts.values())
                            else None
                        ),
                        (
                            "No Lec/Sec scene headers extracted from Third artifact"
                            if not scenes
                            else None
                        ),
                    )
                    if w
                ]
                handoff = {
                    "schema_version": 1,
                    "kind": "notebooklm_multi_pdf_lesson_raw_handoff",
                    "notebook_id": notebook_id,
                    "conversation_id": conversation_id,
                    "pdf_source_ids": pdf_source_ids or [],
                    "framework_source_id": framework_source_id,
                    "lesson_ordinal": lesson_ordinal,
                    "mapped_title": mapped_title_str,
                    "terminal_marker_ordinal": marker_ordinal,
                    "raw_answer_sha256": hashlib.sha256(raw_answer.encode("utf-8")).hexdigest(),
                    "raw_answer": raw_answer,
                    "raw_first": artifacts["first"],
                    "raw_second": artifacts["second"],
                    "raw_third": artifacts["third"],
                    "scenes": scenes,
                    "output_warnings": output_warnings,
                }
                normalization = None
            else:
                try:
                    bundle = parse_combined_bundle(raw_answer, lesson_ordinal)
                    normalization = None
                except Exception as exc:
                    if not any(
                        p in str(exc)
                        for p in ("prompt must be enclosed in quotes", "prompt must begin with quote")
                    ):
                        raise
                    normalized, count = normalize_third_quotes(raw_answer)
                    if count == 0:
                        raise
                    bundle = parse_combined_bundle(normalized, lesson_ordinal)
                    normalization = f"repaired {count} Third prompt quote boundary/boundaries for parsing"

                label = W_LABEL.match(bundle.lecture_title)
                if not label or int(label.group(1)) != lesson_ordinal:
                    raise RuntimeError(f"Lesson title does not identify W{lesson_ordinal}")
                if not (len(bundle.first.sections) == len(bundle.second.sections) == len(bundle.third.sections)):
                    raise RuntimeError("First/Second/Third section counts differ")

                scenes = []
                for first, second, third in zip(
                    bundle.first.sections, bundle.second.sections, bundle.third.sections
                ):
                    if (
                        first.ordinal != second.ordinal
                        or first.ordinal != third.ordinal
                        or len(first.fields) != 11
                    ):
                        raise RuntimeError("Section ordinal or eleven-field coverage differs")
                    scenes.append({
                        "scene_ordinal": first.ordinal,
                        "section_title": first.fields["section_identity"],
                        "scene_type": third.scene_type,
                        "generated_prompt": third.prompt_body,
                        "first_output": first.fields,
                        "second_output": {
                            "placeholder": second.placeholder,
                            "perspective_map": second.perspective_map,
                        },
                    })
                marker = MARKER.search(raw_answer)
                marker_ordinal = int(marker.group(1)) if marker else None
                output_warnings = []
                handoff = {
                    "schema_version": 1,
                    "kind": "notebooklm_multi_pdf_lesson_handoff",
                    "notebook_id": notebook_id,
                    "conversation_id": conversation_id,
                    "pdf_source_ids": pdf_source_ids or [],
                    "framework_source_id": framework_source_id,
                    "lesson_ordinal": lesson_ordinal,
                    "mapped_title": mapped_title_str,
                    "notebooklm_title": bundle.lecture_title,
                    "terminal_marker_ordinal": marker_ordinal,
                    "raw_answer_sha256": hashlib.sha256(raw_answer.encode("utf-8")).hexdigest(),
                    "parse_normalization": normalization,
                    "scenes": scenes,
                }

            handoff_path = None
            if pdf_metadata:
                handoff["pdf"] = pdf_metadata
            if output_dir is not None:
                target = output_dir / f"lesson-{lesson_ordinal:03d}.json"
                atomic_json(target, handoff)
                handoff_path = str(target)

            return {
                "lesson_ordinal": lesson_ordinal,
                "mapped_title": mapped_title_str,
                "handoff_path": handoff_path,
                "scene_count": len(scenes),
                "terminal_marker_ordinal": marker_ordinal,
                "output_warnings": output_warnings,
                "status": "lesson_saved",
                "raw_answer_sha256": handoff["raw_answer_sha256"],
                "handoff_data": handoff,
            }

        raise ValueError(f"Unknown turn_kind: {turn_kind}")

    def freeze_handoffs(self, handoff_dir: Path, expected_lesson_count: int) -> dict[str, Any]:
        """Validate every mapped ordinal captured, snapshot handoffs and initialize 017 scene inventory/config."""
        handoffs: list[str] = []
        for ordinal in range(1, expected_lesson_count + 1):
            file_path = handoff_dir / f"lesson-{ordinal:03d}.json"
            if not file_path.is_file():
                raise RuntimeError(
                    f"Missing handoff file for lesson ordinal {ordinal}: {file_path.name}"
                )
            handoffs.append(str(file_path.resolve()))

        return {
            "status": "frozen",
            "lesson_count": expected_lesson_count,
            "handoffs": handoffs,
        }

    async def reconcile(
        self,
        operation_type: str,
        notebook_id: str | None = None,
        title: str | None = None,
        format_text: str | None = None,
        storage_path: Path | None = None,
    ) -> dict[str, Any]:
        """Read retained receipt, inventory or settings; no mutation or resend."""
        async with self.session_provider.open(storage_path=storage_path) as session:
            if operation_type == "create":
                notebooks = await session.files.list_notebooks()
                matches = [nb for nb in notebooks if nb.title == title]
                if len(matches) == 1:
                    return {"outcome": "complete", "notebook_id": matches[0].id}
                elif len(matches) > 1:
                    return {"outcome": "blocked", "error": "multiple_matching_notebooks"}
                return {"outcome": "uncertain"}

            elif operation_type == "format":
                if not notebook_id:
                    raise ValueError("notebook_id required for format reconciliation")
                settings = await session.conversation.get_format(notebook_id)
                if settings.custom_prompt == format_text:
                    return {"outcome": "complete"}
                return {"outcome": "uncertain"}

            elif operation_type == "upload":
                if not notebook_id:
                    raise ValueError("notebook_id required for upload reconciliation")
                sources = await session.files.list_sources(notebook_id)
                matches = [s for s in sources if s.title == title]
                if len(matches) == 1:
                    return {"outcome": "complete", "source_id": matches[0].source_id}
                elif len(matches) > 1:
                    return {"outcome": "blocked", "error": "multiple_matching_sources"}
                return {"outcome": "uncertain"}

            return {"outcome": "uncertain"}

    async def reconcile_chat_turn(
        self,
        notebook_id: str,
        conversation_id: str,
        expected_question: str,
        expected_turn_index: int,
        expected_history_prefix: list[str] | None = None,
        storage_path: Path | None = None,
    ) -> dict[str, Any]:
        """Read conversation history and match expected question at expected index with full-prefix anchoring."""
        async with self.session_provider.open(storage_path=storage_path) as session:
            history = await session.conversation.history(notebook_id, conversation_id, limit=1000)
            if len(history) < expected_turn_index:
                return {"outcome": "blocked", "error": "history_truncated"}

            if expected_history_prefix is not None:
                if len(history) < len(expected_history_prefix):
                    return {"outcome": "blocked", "error": "history_truncated"}
                for idx, exp_q in enumerate(expected_history_prefix):
                    actual_q, _ = history[idx]
                    if actual_q != exp_q:
                        return {"outcome": "blocked", "error": "history_prefix_mismatch"}

            if len(history) > expected_turn_index:
                actual_q, actual_a = history[expected_turn_index]
                if actual_q == expected_question:
                    return {"outcome": "complete", "raw_answer": actual_a, "turn_index": expected_turn_index}
                else:
                    return {"outcome": "blocked", "error": "history_conflict"}
            return {"outcome": "uncertain"}

    def assert_safe_to_resend_chat(self, outcome: str, accept_duplicate_risk: bool = False) -> None:
        """Enforce strict invariant: uncertain chat turns cannot be blind-resent in the same conversation."""
        if outcome == "uncertain":
            raise RuntimeError(
                "Uncertain NotebookLM chat cannot be resent in the same conversation; reconcile or start a new run"
            )

    def load_existing_receipt(self, receipt_file: Path) -> dict[str, Any] | None:
        """Load and return existing receipt if file exists, else None."""
        if receipt_file.is_file():
            try:
                return json.loads(receipt_file.read_text(encoding="utf-8"))
            except Exception as exc:
                logger.warning("Could not read receipt file %s: %s", receipt_file, exc)
                return None
        return None
