from __future__ import annotations

import asyncio
import hashlib
import json
import time
from dataclasses import dataclass
from pathlib import Path
import re
from typing import Any, Dict, List, Optional

from v3_engine.notebooklm.ports import (
    AuthenticationRequiredError,
    ProviderError,
    SessionProvider,
)
from v3_engine.notebooklm.laravel_client import HttpLaravelNotebookClient
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT

COMBINED_RESPONSE_FORMAT_TEXT = (
    "RESPONSE FORMAT combined-envelope-v1\n"
    "The uploaded educational PDF supplies facts. steps.txt supplies the First, Second and Third framework. Preserve that framework unchanged.\n"
    "For the dividing request, return only its structural map.\n"
    "For the execution acknowledgement, return only System Ready.\n"
    "For Start with First lesson and each literal Continue, return all three output artifacts for exactly the current mapped work item, without reading ahead. X is its one-based depth-first work ordinal; a Mini Chapter without Lessons counts as one work item.\n"
    "Use these exact standalone delimiters in this order:\n"
    "=== FIRST ===\n"
    "The complete First output, including Lecture and every Section with its eleven fields.\n"
    "=== SECOND ===\n"
    "The complete Second output, including Lecture and each LecX (SecY) placeholder and Prepared Perspective Map.\n"
    "=== THIRD ===\n"
    "The Third artifact must begin immediately with its Lecture: header, followed only by one LecX (SecY) entry for each section.\n"
    "For every entry, put the opening double quote immediately after the LecX (SecY): header, then enclose the ENTIRE multi-line final prompt in that single pair of double quotes. Do not close the quote until the final line of the prompt. Do not put explanatory text before the quote, after the closing quote, or between prompts.\n"
    "=== END ===\n"
    "Finish with exactly: ✅ Lesson [X] Completed. Awaiting 'Continue' for the next lesson.\n"
    "Do not include these delimiter lines inside an artifact. Return no other text outside the three artifacts and final completion marker."
)
COMBINED_RESPONSE_FORMAT_SHA256 = "d1c517a59d71d2e79d511b1ab2f945d6eb65f3dcffc24e802823a617c2d95580"


def compute_source_effect_digest(
    case_id: str,
    operation: str,
    notebook_title: Optional[str] = None,
    notebook_id: Optional[str] = None,
    role: Optional[str] = None,
    title: Optional[str] = None,
    artifact_sha256: Optional[str] = None,
    artifact_bytes: Optional[int] = None,
    response_format_sha256: Optional[str] = None,
) -> str:
    payload = {
        "protocol": "notebooklm_combined_v1",
        "case_id": case_id,
        "operation": operation,
        "notebook_title": notebook_title,
        "notebook_id": notebook_id,
        "role": role,
        "title": title,
        "artifact_sha256": artifact_sha256,
        "artifact_bytes": artifact_bytes,
        "response_format_sha256": response_format_sha256,
    }
    encoded = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


class DirectSourceProtocol:
    """Manages the fixed source upload sequence for direct PDF NotebookLM cases."""

    def __init__(
        self,
        provider: SessionProvider,
        laravel_client: HttpLaravelNotebookClient,
        temp_dir: Path,
        profile_ref: str = "v3-local",
        poll_interval: float = 10.0,
        max_poll_seconds: float = 300.0,
    ):
        self.provider = provider
        self.laravel = laravel_client
        self.temp_dir = temp_dir
        self.profile_ref = profile_ref
        self.poll_interval = poll_interval
        self.max_poll_seconds = max_poll_seconds

    async def ingest(
        self,
        case_id: str,
        activation_id: str,
        binding_ref: str,
        pdf_title: str,
        pdf_sha256: str,
        steps_sha256: str,
    ) -> dict[str, Any]:
        self.temp_dir.mkdir(parents=True, exist_ok=True)
        pdf_path = self.temp_dir / f"pdf_{case_id}_{pdf_sha256[:16]}.pdf"
        steps_path = self.temp_dir / f"steps_{case_id}_{steps_sha256[:16]}.txt"

        async with self.provider.open(self.profile_ref) as session:
            # 1. Download and verify files locally if not already present
            cached_pdf_sha = None
            if pdf_path.is_file():
                cached_pdf_hasher = hashlib.sha256()
                with pdf_path.open("rb") as cached_pdf:
                    for chunk in iter(lambda: cached_pdf.read(1024 * 1024), b""):
                        cached_pdf_hasher.update(chunk)
                cached_pdf_sha = cached_pdf_hasher.hexdigest()
            if cached_pdf_sha != pdf_sha256:
                await self.laravel.stream_pdf(activation_id, pdf_path)
            if not steps_path.is_file() or hashlib.sha256(steps_path.read_bytes()).hexdigest() != steps_sha256:
                steps_content, reported_sha = await self.laravel.get_steps(activation_id)
                if reported_sha != steps_sha256:
                    raise ProviderError("source_integrity_failure", "Captured framework digest differs from the manifest")
                steps_path.write_bytes(steps_content)

            # Verify actual bytes
            pdf_hasher = hashlib.sha256()
            with pdf_path.open("rb") as pdf_stream:
                for chunk in iter(lambda: pdf_stream.read(1024 * 1024), b""):
                    pdf_hasher.update(chunk)
            if pdf_hasher.hexdigest() != pdf_sha256:
                raise ProviderError("source_integrity_failure", "Downloaded PDF SHA-256 does not match expectation")

            actual_steps_bytes = steps_path.read_bytes()
            if hashlib.sha256(actual_steps_bytes).hexdigest() != steps_sha256:
                raise ProviderError("source_integrity_failure", "Downloaded steps SHA-256 does not match expectation")

            notebook_title = f"v3-case-{case_id}"

            # 2. Notebook create effect
            create_digest = compute_source_effect_digest(
                case_id=case_id,
                operation="notebook_create",
                notebook_title=notebook_title,
            )
            create_claim = await self.laravel.claim_source_effect(case_id, "notebook_create", activation_id, create_digest)
            disposition = create_claim.get("disposition")

            if disposition == "send_now":
                nb_ref = await session.files.create_notebook(notebook_title)
                notebook_id = nb_ref.id
                await self.laravel.record_source_effect_result(
                    create_claim["effect_id"],
                    {"state": "confirmed", "provider_ref": notebook_id, "proof_title": notebook_title},
                )
            elif disposition in {"already_confirmed", "confirmed"}:
                notebook_id = create_claim.get("provider_ref")
                if not notebook_id:
                    raise ProviderError("provider_uncertain", "Notebook create confirmed without provider_ref")
            else:
                notebook_id = create_claim.get("provider_ref")
                if not notebook_id:
                    raise ProviderError("provider_uncertain", "Notebook create has no recorded provider ID")

            inventory = await session.files.get_notebook(notebook_id)
            if inventory.id != notebook_id or inventory.title != notebook_title:
                raise ProviderError("source_conflict", "Notebook identity differs from the captured case")

            # 3. PDF upload effect
            pdf_digest = compute_source_effect_digest(
                case_id=case_id,
                operation="notebook_upload_pdf",
                notebook_id=notebook_id,
                role="educational_pdf",
                title=pdf_title,
                artifact_sha256=pdf_sha256,
                artifact_bytes=pdf_path.stat().st_size,
            )
            pdf_claim = await self.laravel.claim_source_effect(case_id, "notebook_upload_pdf", activation_id, pdf_digest)
            pdf_disp = pdf_claim.get("disposition")

            if pdf_disp == "send_now":
                receipt = await session.files.upload_file(notebook_id, pdf_path, "application/pdf", pdf_title)
                pdf_source_id = receipt.source_id
                await self.laravel.record_source_effect_result(
                    pdf_claim["effect_id"],
                    {
                        "state": "registered",
                        "provider_ref": pdf_source_id,
                        "proof_title": pdf_title,
                        "proof_sha256": pdf_sha256,
                    },
                )
            elif pdf_disp in {"already_confirmed", "confirmed"}:
                pdf_source_id = pdf_claim.get("provider_ref")
            else:
                pdf_source_id = pdf_claim.get("provider_ref")
                if not pdf_source_id:
                    raise ProviderError("provider_uncertain", "PDF upload uncertain without known source ID")

            # 4. Steps upload effect
            steps_digest = compute_source_effect_digest(
                case_id=case_id,
                operation="notebook_upload_steps",
                notebook_id=notebook_id,
                role="framework_steps",
                title="steps.txt",
                artifact_sha256=steps_sha256,
                artifact_bytes=len(actual_steps_bytes),
            )
            steps_claim = await self.laravel.claim_source_effect(case_id, "notebook_upload_steps", activation_id, steps_digest)
            steps_disp = steps_claim.get("disposition")

            if steps_disp == "send_now":
                receipt = await session.files.upload_file(notebook_id, steps_path, "text/plain", "steps.txt")
                steps_source_id = receipt.source_id
                await self.laravel.record_source_effect_result(
                    steps_claim["effect_id"],
                    {
                        "state": "registered",
                        "provider_ref": steps_source_id,
                        "proof_title": "steps.txt",
                        "proof_sha256": steps_sha256,
                    },
                )
            elif steps_disp in {"already_confirmed", "confirmed"}:
                steps_source_id = steps_claim.get("provider_ref")
            else:
                steps_source_id = steps_claim.get("provider_ref")
                if not steps_source_id:
                    raise ProviderError("provider_uncertain", "Steps upload uncertain without known source ID")

            # 5. Poll readiness of both sources without re-uploading
            for src_id, expected_title, expected_mime, expected_sha, claim in (
                (pdf_source_id, pdf_title, "application/pdf", pdf_sha256, pdf_claim),
                (steps_source_id, "steps.txt", "text/plain", steps_sha256, steps_claim),
            ):
                deadline = time.monotonic() + self.max_poll_seconds
                while True:
                    receipt = await session.files.get_source(notebook_id, src_id)
                    if receipt.source_id != src_id or receipt.title != expected_title or (receipt.mime_type or "").split(";")[0] != expected_mime:
                        raise ProviderError("source_conflict", "Provider source identity differs from the captured manifest")
                    if receipt.status == "ready":
                        if claim.get("disposition") not in {"confirmed", "already_confirmed"}:
                            await self.laravel.record_source_effect_result(
                                claim["effect_id"],
                                {"state": "confirmed", "provider_ref": src_id,
                                 "proof_title": expected_title, "proof_sha256": expected_sha},
                            )
                        break
                    elif receipt.status == "error":
                        await self.laravel.record_source_effect_result(
                            claim["effect_id"],
                            {"state": "blocked", "error_code": "source_processing_failed"},
                        )
                        return {"status": "blocked", "error_code": "source_processing_failed"}
                    elif receipt.status != "processing":
                        raise ProviderError("source_conflict", "Provider source has an unknown processing state")
                    if time.monotonic() >= deadline:
                        return {"status": "processing", "binding_ref": binding_ref}
                    await asyncio.sleep(min(self.poll_interval, max(0, deadline - time.monotonic())))

            expected_sources = {pdf_source_id, steps_source_id}
            inventory_deadline = time.monotonic() + self.max_poll_seconds
            while True:
                inventory = await session.files.get_notebook(notebook_id)
                observed_sources = set(inventory.source_ids)
                if inventory.id != notebook_id or inventory.title != notebook_title:
                    raise ProviderError("source_conflict", "Notebook identity changed during source processing")
                if observed_sources == expected_sources:
                    break
                if observed_sources - expected_sources:
                    raise ProviderError("source_conflict", "Notebook contains an unexpected source")
                if time.monotonic() >= inventory_deadline:
                    return {"status": "processing", "binding_ref": binding_ref}
                await asyncio.sleep(min(self.poll_interval, max(0, inventory_deadline - time.monotonic())))

            # 6. Configure response format
            cfg_digest = compute_source_effect_digest(
                case_id=case_id,
                operation="chat_configure",
                notebook_id=notebook_id,
                response_format_sha256=COMBINED_RESPONSE_FORMAT_SHA256,
            )
            cfg_claim = await self.laravel.claim_source_effect(case_id, "chat_configure", activation_id, cfg_digest)
            cfg_disp = cfg_claim.get("disposition")

            if cfg_disp == "send_now":
                await session.conversation.configure_format(notebook_id, COMBINED_RESPONSE_FORMAT_TEXT)
            settings = await session.conversation.get_format(notebook_id)
            if settings.custom_prompt != COMBINED_RESPONSE_FORMAT_TEXT:
                raise ProviderError("protocol_validation", "Chat response format readback verification failed")
            if cfg_disp == "send_now":
                await self.laravel.record_source_effect_result(
                    cfg_claim["effect_id"],
                    {"state": "confirmed", "provider_ref": notebook_id},
                )

            return {
                "status": "bound",
                "binding_ref": binding_ref,
            }


@dataclass
class ParsedLessonNode:
    kind: str  # chapter | mini_chapter | lesson
    position_path: str  # e.g. "001.002"
    parent_position_path: Optional[str]
    title: str
    ordinal_in_parent: int
    work_ordinal: Optional[int] = None


LINE_PATTERN = re.compile(
    r"^(?P<indent>\s*)(?P<tree>(?:[│|]\s*)*[├└]──\s*)?(?:=>\s*)?(?:[-*]\s+)?(?P<kind>Chapter|Mini\s+Chapter|Lesson)(?:\s+(?:[0-9]+(?:\.[0-9]+)*|[IVXLCDMivxlcdm]+))?\s*(?::|-|=>)\s*(?P<title>.+)$",
    re.IGNORECASE,
)


def parse_direct_divide_map(raw_text: str) -> List[ParsedLessonNode]:
    """Parse raw Divide response into ordered lesson nodes with single sibling counters across kinds."""
    if not raw_text or not raw_text.strip():
        raise ProviderError("protocol_validation", "Divide response is empty")

    lines = raw_text.strip().splitlines()
    nodes: List[ParsedLessonNode] = []

    current_chapter: Optional[ParsedLessonNode] = None
    current_mini: Optional[ParsedLessonNode] = None

    chapter_count = 0
    chapter_sibling_counters: Dict[str, int] = {}
    mini_sibling_counters: Dict[str, int] = {}

    chapter_children: Dict[str, List[ParsedLessonNode]] = {}
    mini_children: Dict[str, List[ParsedLessonNode]] = {}

    # Check for indentation presence across lines
    has_indentation = any(
        len(line) - len(line.lstrip(" ")) > 0 or re.search(r"[│|├└]", line)
        for line in lines
        if line.strip()
    )

    for raw_line in lines:
        line = raw_line.strip()
        if not line:
            continue

        if line.startswith("```") or line.endswith("```"):
            raise ProviderError("protocol_validation", "Code fences are prohibited in Divide output")

        match = LINE_PATTERN.match(raw_line)
        if not match:
            raise ProviderError("protocol_validation", f"Prose or invalid line format: '{line}'")

        tree_prefix = match.group("tree") or ""
        tree_depth = (
            len(re.findall(r"[│|]", tree_prefix))
            + int(bool(re.search(r"[├└]──", tree_prefix)))
        )
        indent_len = len(match.group("indent")) + tree_depth * 2
        raw_kind = match.group("kind")
        title = match.group("title").strip()

        # Strip optional Markdown bold/italic around title or label
        if title.startswith("**") and title.endswith("**"):
            title = title[2:-2].strip()

        if not title or len(title) > 255:
            raise ProviderError("protocol_validation", f"Invalid title length: '{title}'")

        kind_norm = raw_kind.lower()
        if "mini" in kind_norm:
            kind = "mini_chapter"
        elif "chapter" in kind_norm:
            kind = "chapter"
        elif "lesson" in kind_norm:
            kind = "lesson"
        else:
            raise ProviderError("protocol_validation", f"Unrecognized node kind: '{raw_kind}'")

        # NotebookLM's Unicode tree output can repeat continuation bars for
        # earlier siblings. Those bars increase the visual prefix depth even
        # when the node remains a Lesson under the current Mini Chapter. The
        # protocol's semantic hierarchy has only Chapter -> Mini Chapter ->
        # Lesson, so normalize deep tree-only lesson prefixes to that leaf
        # level. Keep plain whitespace indentation strict below.
        if kind == "lesson" and indent_len not in (0, 2, 4):
            # The live NotebookLM map may prefix a lesson with an arrow and
            # nonstandard spaces (for example "   => Lesson 1"). The map's
            # hierarchy is still unambiguous from the lesson label; treat
            # these provider-specific leaf indents as the deepest supported
            # lesson level so one formatting quirk cannot stop the Continue
            # sequence for every remaining work item.
            indent_len = 4

        # Validate hierarchy and indentation
        if has_indentation:
            if kind == "chapter" and indent_len != 0:
                raise ProviderError("protocol_validation", f"Ambiguous indentation: Chapter has {indent_len} spaces")
            elif kind in ("mini_chapter", "lesson") and indent_len not in (0, 2, 4):
                raise ProviderError("protocol_validation", f"Ambiguous indentation: {indent_len} spaces for line '{line}'")
            if indent_len == 2:
                # Sibling under chapter, resets mini
                if kind == "lesson":
                    current_mini = None

        if kind == "chapter":
            if current_chapter is not None and not chapter_children.get(current_chapter.position_path):
                raise ProviderError("protocol_validation", f"Chapter '{current_chapter.title}' has no children")

            chapter_count += 1
            pos_path = f"{chapter_count:03d}"
            node = ParsedLessonNode(
                kind="chapter",
                position_path=pos_path,
                parent_position_path=None,
                title=title,
                ordinal_in_parent=chapter_count,
            )
            nodes.append(node)
            current_chapter = node
            current_mini = None
            chapter_sibling_counters[pos_path] = 0
            chapter_children[pos_path] = []

        elif kind == "mini_chapter":
            if current_chapter is None:
                raise ProviderError("protocol_validation", f"Orphan Mini Chapter '{title}' without parent Chapter")

            chapter_sibling_counters[current_chapter.position_path] += 1
            sib_ordinal = chapter_sibling_counters[current_chapter.position_path]
            pos_path = f"{current_chapter.position_path}.{sib_ordinal:03d}"

            node = ParsedLessonNode(
                kind="mini_chapter",
                position_path=pos_path,
                parent_position_path=current_chapter.position_path,
                title=title,
                ordinal_in_parent=sib_ordinal,
            )
            nodes.append(node)
            current_mini = node
            mini_sibling_counters[pos_path] = 0
            chapter_children[current_chapter.position_path].append(node)
            mini_children[pos_path] = []

        elif kind == "lesson":
            if current_chapter is None:
                raise ProviderError("protocol_validation", f"Orphan Lesson '{title}' without parent Chapter")

            if current_mini is not None:
                mini_sibling_counters[current_mini.position_path] += 1
                sib_ordinal = mini_sibling_counters[current_mini.position_path]
                pos_path = f"{current_mini.position_path}.{sib_ordinal:03d}"
                node = ParsedLessonNode(
                    kind="lesson",
                    position_path=pos_path,
                    parent_position_path=current_mini.position_path,
                    title=title,
                    ordinal_in_parent=sib_ordinal,
                )
                mini_children[current_mini.position_path].append(node)
            else:
                chapter_sibling_counters[current_chapter.position_path] += 1
                sib_ordinal = chapter_sibling_counters[current_chapter.position_path]
                pos_path = f"{current_chapter.position_path}.{sib_ordinal:03d}"
                node = ParsedLessonNode(
                    kind="lesson",
                    position_path=pos_path,
                    parent_position_path=current_chapter.position_path,
                    title=title,
                    ordinal_in_parent=sib_ordinal,
                )
                chapter_children[current_chapter.position_path].append(node)

            nodes.append(node)

    if not nodes:
        raise ProviderError("protocol_validation", "No structural nodes found in Divide response")

    if current_chapter is not None and not chapter_children.get(current_chapter.position_path):
        raise ProviderError("protocol_validation", f"Chapter '{current_chapter.title}' has no children")

    # Depth-first work ordinal assignment (W1..Wn)
    current_work_ordinal = 1
    for node in nodes:
        if node.kind == "lesson":
            node.work_ordinal = current_work_ordinal
            current_work_ordinal += 1
        elif node.kind == "mini_chapter":
            if not mini_children.get(node.position_path):
                # Promoted childless Mini Chapter!
                node.work_ordinal = current_work_ordinal
                current_work_ordinal += 1

    if current_work_ordinal == 1:
        raise ProviderError("protocol_validation", "Divide map contains no work items")

    return nodes


def compute_lesson_query_digest(
    case_id: str,
    binding_ref: str,
    kind: str,
    work_ordinal: int,
    expected_cursor_version: Optional[int],
    conversation_in: Optional[str],
    source_roles: list[str],
    framework_sha256: str,
    response_format_sha256: str,
    question_sha256: str,
) -> str:
    payload = {
        "protocol": "notebooklm_combined_v1",
        "case_id": case_id,
        "binding_ref": binding_ref,
        "kind": kind,
        "work_ordinal": work_ordinal,
        "expected_cursor_version": expected_cursor_version,
        "conversation_in": conversation_in,
        "source_roles": source_roles,
        "framework_sha256": framework_sha256,
        "response_format_sha256": response_format_sha256,
        "question_sha256": question_sha256,
    }
    encoded = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


class DirectLessonProtocol:
    """Orchestrates the fixed Divide -> Execution -> Start -> Continue lesson protocol."""

    def __init__(
        self,
        provider: SessionProvider,
        laravel_client: HttpLaravelNotebookClient,
        profile_ref: str = "v3-local",
    ):
        self.provider = provider
        self.laravel = laravel_client
        self.profile_ref = profile_ref

    async def _claim(
        self, session: Any, case_id: str, binding_ref: str, kind: str,
        work_ordinal: int, question: str, conversation_id: Optional[str],
        expected_cursor_version: Optional[int],
    ) -> dict[str, Any]:
        existing = await self.laravel.find_lesson_query(case_id, kind, work_ordinal)
        if existing:
            if existing["binding_ref"] != binding_ref or existing["conversation_in"] != conversation_id:
                raise ProviderError("conversation_conflict", "Existing query identity differs from the captured conversation")
            if existing.get("state") == "recorded":
                return {**existing, "disposition": "already_recorded"}
            if existing.get("state") not in {"sending", "uncertain"}:
                return {**existing, "disposition": "reconcile_only"}

            # A remote send may have completed immediately before a worker
            # restart. Reconcile from the exact expected NotebookLM history
            # position; never blindly resend a claimed chat operation.
            binding = await self.laravel.get_binding_by_ref(case_id, binding_ref)
            history = []
            if conversation_id:
                history = await session.conversation.history(binding["notebook_id"], conversation_id, limit=1000)
            base_length = {
                "divide": 0,
                "execution": 1,
                "lesson_start": 2,
                "lesson_continue": work_ordinal + 1,
            }[kind]
            prefix = {
                "divide": [],
                "execution": [DIVIDE_PROMPT],
                "lesson_start": [DIVIDE_PROMPT, EXECUTION_PROMPT],
                "lesson_continue": [DIVIDE_PROMPT, EXECUTION_PROMPT]
                    + ["Start with First lesson"]
                    + ["Continue"] * max(0, work_ordinal - 2),
            }[kind]
            if len(history) == base_length and [entry[0] for entry in history] == prefix:
                return {**existing, "disposition": "uncertain"}
            if len(history) != base_length + 1 or [entry[0] for entry in history[:base_length]] != prefix:
                raise ProviderError("history_conflict", "Notebook history differs from the claimed operation position")
            if history[-1][0] != question:
                raise ProviderError("history_conflict", "Notebook history does not contain the claimed question")
            recovered_answer = history[-1][1]
            query_ref = existing["query_ref"]
            if kind == "divide":
                result = await self.laravel.record_divide_result(query_ref, recovered_answer, str(conversation_id))
                nodes = parse_direct_divide_map(recovered_answer)
                recovered = {"map_ref": result.get("map_ref"), "work_count": sum(n.work_ordinal is not None for n in nodes)}
            elif kind == "execution":
                result = await self.laravel.record_execution_result(query_ref, recovered_answer, str(conversation_id))
                recovered = {"cursor_version": result.get("cursor_version")}
            else:
                result = await self.laravel.record_combined_lesson_result(
                    query_ref,
                    {
                        "schema_version": 1,
                        "expected_cursor_version": existing.get("expected_cursor_version"),
                        "answer": recovered_answer,
                        "answer_sha256": hashlib.sha256(recovered_answer.encode("utf-8")).hexdigest(),
                        "conversation_out": conversation_id,
                    },
                )
                recovered = result
            return {**existing, **recovered, "disposition": "already_recorded"}
        binding = await self.laravel.get_binding_by_ref(case_id, binding_ref)
        roles = ["educational_pdf"] if kind == "divide" else ["educational_pdf", "framework_steps"]
        history: list[tuple[str, str]] = []
        if conversation_id:
            history = await session.conversation.history(binding["notebook_id"], conversation_id, limit=1000)
            expected_length = 1 if kind == "execution" else work_ordinal + 1
            expected_previous = (
                DIVIDE_PROMPT if kind == "execution" else
                EXECUTION_PROMPT if kind == "lesson_start" else
                "Start with First lesson" if work_ordinal == 2 else "Continue"
            )
            if len(history) != expected_length or history[-1][0] != expected_previous:
                raise ProviderError("conversation_conflict", "Notebook conversation history does not match the expected turn")
        anchor = compute_history_anchor(*history[-1]) if history else None
        payload = {
            "schema_version": 1,
            "binding_ref": binding_ref,
            "expected_cursor_version": expected_cursor_version,
            "conversation_in": conversation_id,
            "query_text": question,
            "history_length_before": len(history),
            "history_anchor_sha256": anchor,
        }
        payload["request_sha256"] = compute_query_request_digest(
            case_id, binding_ref, kind, work_ordinal, expected_cursor_version,
            conversation_id, roles, binding["framework_sha256"],
            COMBINED_RESPONSE_FORMAT_SHA256,
            hashlib.sha256(question.encode("utf-8")).hexdigest(),
        )
        return await self.laravel.claim_lesson_query(case_id, kind, work_ordinal, payload)

    async def _ask_claimed(
        self, session: Any, claim: dict[str, Any], notebook_id: str,
        question: str, source_ids: list[str], conversation_id: Optional[str],
    ) -> Optional[Any]:
        if claim.get("disposition") != "send_now":
            return None
        try:
            return await session.conversation.ask_once(
                notebook_id=notebook_id, question=question,
                source_ids=source_ids, conversation_id=conversation_id,
            )
        except AuthenticationRequiredError:
            # Authentication failure is a known precondition failure, not an
            # uncertain provider send. Preserve the typed safe outcome.
            raise
        except ProviderError:
            try:
                await self.laravel.record_lesson_query_uncertain(
                    claim["query_ref"], {"schema_version": 1, "error_code": "provider_uncertain"},
                )
            except Exception:
                pass
            raise
        except Exception:
            try:
                await self.laravel.record_lesson_query_uncertain(
                    claim["query_ref"], {"schema_version": 1, "error_code": "provider_uncertain"},
                )
            except Exception:
                pass
            return None

    async def divide(
        self,
        case_id: str,
        binding_ref: str,
        notebook_id: str,
        pdf_source_id: str,
        expected_cursor_version: Optional[int] = None,
    ) -> dict[str, Any]:
        async with self.provider.open(self.profile_ref) as session:
            claim = await self._claim(session, case_id, binding_ref, "divide", 0, DIVIDE_PROMPT, None, expected_cursor_version)
            if claim.get("disposition") == "already_recorded":
                return {
                    "status": "recorded", "conversation_id": claim.get("conversation_out"),
                    "work_count": int(claim.get("work_count") or 0), "map_ref": claim.get("map_ref"),
                }
            if claim.get("disposition") != "send_now":
                return {"status": "uncertain", "query_ref": claim.get("query_ref"), "kind": "divide"}
            answer = await self._ask_claimed(session, claim, notebook_id, DIVIDE_PROMPT, [pdf_source_id], None)
            if answer is None:
                return {"status": "uncertain", "query_ref": claim["query_ref"], "kind": "divide"}
            if not answer.conversation_id:
                raise ProviderError("conversation_conflict", "Divide response missing server conversation ID")

            result = await self.laravel.record_divide_result(
                query_ref=claim["query_ref"],
                answer=answer.text,
                conversation_out=answer.conversation_id,
            )
            nodes = parse_direct_divide_map(answer.text)
            work_nodes = [n for n in nodes if n.work_ordinal is not None]

            return {
                "status": "recorded",
                "conversation_id": answer.conversation_id,
                "work_count": len(work_nodes),
                "map_ref": result.get("map_ref"),
            }

    async def execution(
        self,
        case_id: str,
        binding_ref: str,
        notebook_id: str,
        pdf_source_id: str,
        steps_source_id: str,
        conversation_id: str,
        expected_cursor_version: Optional[int] = None,
    ) -> dict[str, Any]:
        async with self.provider.open(self.profile_ref) as session:
            claim = await self._claim(session, case_id, binding_ref, "execution", 0, EXECUTION_PROMPT, conversation_id, expected_cursor_version)
            if claim.get("disposition") == "already_recorded":
                return {"status": "acknowledged", "conversation_id": conversation_id, "cursor_version": claim.get("cursor_version")}
            if claim.get("disposition") != "send_now":
                return {"status": "uncertain", "query_ref": claim.get("query_ref"), "kind": "execution"}
            answer = await self._ask_claimed(session, claim, notebook_id, EXECUTION_PROMPT, [pdf_source_id, steps_source_id], conversation_id)
            if answer is None:
                return {"status": "uncertain", "query_ref": claim["query_ref"], "kind": "execution"}
            if answer.conversation_id != conversation_id:
                raise ProviderError(
                    "conversation_conflict",
                    f"Execution returned changed conversation ID: expected {conversation_id}, got {answer.conversation_id}",
                )

            result = await self.laravel.record_execution_result(
                query_ref=claim["query_ref"],
                answer=answer.text,
                conversation_out=answer.conversation_id,
            )
            if answer.text.strip() != "System Ready":
                raise ProviderError("protocol_validation", "Execution acknowledgement was invalid")

            return {
                "status": "acknowledged",
                "conversation_id": answer.conversation_id,
                "cursor_version": result.get("cursor_version"),
            }

    async def process_lesson(
        self,
        case_id: str,
        binding_ref: str,
        notebook_id: str,
        pdf_source_id: str,
        steps_source_id: str,
        conversation_id: str,
        work_ordinal: int,
        expected_cursor_version: Optional[int] = None,
    ) -> dict[str, Any]:
        question = "Start with First lesson" if work_ordinal == 1 else "Continue"
        async with self.provider.open(self.profile_ref) as session:
            kind = "lesson_start" if work_ordinal == 1 else "lesson_continue"
            claim = await self._claim(session, case_id, binding_ref, kind, work_ordinal, question, conversation_id, expected_cursor_version)
            if claim.get("disposition") == "already_recorded":
                return {"status": "completed", **claim}
            if claim.get("disposition") != "send_now":
                return {"status": "uncertain", "query_ref": claim.get("query_ref"), "kind": kind}
            answer = await self._ask_claimed(session, claim, notebook_id, question, [pdf_source_id, steps_source_id], conversation_id)
            if answer is None:
                return {"status": "uncertain", "query_ref": claim["query_ref"], "kind": kind}
            if answer.conversation_id != conversation_id:
                raise ProviderError(
                    "conversation_conflict",
                    f"Lesson {work_ordinal} returned changed conversation ID: expected {conversation_id}, got {answer.conversation_id}",
                )

            result = await self.laravel.record_combined_lesson_result(
                query_ref=claim["query_ref"],
                payload={
                    "schema_version": 1,
                    "expected_cursor_version": claim["claimed_cursor_version"],
                    "answer": answer.text,
                    "answer_sha256": hashlib.sha256(answer.text.encode("utf-8")).hexdigest(),
                    "conversation_out": answer.conversation_id,
                },
            )

            return {"status": "completed", **result}


def compute_query_request_digest(
    case_id: str, binding_ref: str, kind: str, work_ordinal: int,
    expected_cursor_version: Optional[int], conversation_in: Optional[str],
    source_roles: list[str], framework_sha256: str,
    response_format_sha256: str, question_sha256: str,
) -> str:
    payload = {
        "protocol": "notebooklm_combined_v1",
        "case_id": case_id,
        "binding_ref": binding_ref,
        "kind": kind,
        "work_ordinal": work_ordinal,
        "expected_cursor_version": expected_cursor_version,
        "conversation_in": conversation_in,
        "source_roles": source_roles,
        "framework_sha256": framework_sha256,
        "response_format_sha256": response_format_sha256,
        "question_sha256": question_sha256,
    }
    return hashlib.sha256(json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")).hexdigest()


def compute_history_anchor(question: str, answer: str) -> str:
    """Compute anchor SHA-256 over compact JSON array [previous_question, previous_answer] in UTF-8."""
    encoded = json.dumps([question, answer], separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def recover_turn_from_history(
    history_turns: list[dict[str, str]],
    history_length_before: int,
    expected_question: str,
    expected_anchor_sha256: Optional[str],
) -> dict[str, Any]:
    """Recover a single turn from conversation history using anchor and position.

    Returns:
        {"status": "recovered", "answer": str, "turn_index": int}
        or
        {"status": "uncertain"|"not_found", "reason": str}
    """
    if len(history_turns) > 1000:
        return {"status": "uncertain", "reason": "history_exceeds_limit"}

    if len(history_turns) < history_length_before:
        return {"status": "uncertain", "reason": "history_truncated"}

    if history_length_before == 0:
        if expected_anchor_sha256 is not None:
            return {"status": "uncertain", "reason": "anchor_mismatch_for_zero_length"}
    else:
        prev_turn = history_turns[history_length_before - 1]
        prev_q = prev_turn.get("question", "")
        prev_a = prev_turn.get("answer", "")
        actual_anchor = compute_history_anchor(prev_q, prev_a)
        if actual_anchor != expected_anchor_sha256:
            return {"status": "uncertain", "reason": "history_anchor_mismatch"}

    if len(history_turns) <= history_length_before:
        return {"status": "not_found", "reason": "no_successor_turn"}
    if len(history_turns) != history_length_before + 1:
        return {"status": "uncertain", "reason": "additional_turns_after_claim"}

    target_turn = history_turns[history_length_before]
    if target_turn.get("question") != expected_question:
        return {"status": "uncertain", "reason": "successor_question_mismatch"}

    answer = target_turn.get("answer", "")
    if not answer:
        return {"status": "uncertain", "reason": "empty_successor_answer"}

    return {
        "status": "recovered",
        "answer": answer,
        "turn_index": history_length_before,
    }
