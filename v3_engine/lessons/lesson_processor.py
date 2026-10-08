"""Lesson processor coordinating First prompt execution and parsing for Spec 009."""
from dataclasses import asdict
from typing import Dict, Any, Optional

from v3_engine.lessons.canonical_prompts import FIRST_PROMPT
from v3_engine.lessons.first_parser import parse_first_response, FirstParserError, CANONICAL_FIELD_LABELS


class LessonProcessor:
    def __init__(self, adapter: Any, laravel_client: Any):
        self.adapter = adapter
        self.laravel_client = laravel_client

    async def prepare_first_lesson(
        self,
        case_id: str,
        activation_id: str,
        binding_ref: str,
        work_ordinal: int = 1,
        work_path: Optional[str] = None,
    ) -> Dict[str, Any]:
        """Execute First prompt turn for target work ordinal, parse 11 fields per Section, and post result."""
        # 1. Fetch activation context
        context_resp = await self.laravel_client.get_activation_context(activation_id)
        context = context_resp.get("data", {})
        if context.get("case_id") != case_id or context.get("binding_ref") != binding_ref:
            return {"status": "blocked", "error_code": "internal"}
        if context.get("map_state") != "ready":
            return {"status": "blocked", "error_code": "internal"}
        conversation_ref = context.get("conversation_ref")

        lesson_ctx_resp = await self.laravel_client.get_lesson_context(case_id, work_ordinal)
        lesson_ctx = lesson_ctx_resp.get("data", {})
        work_path = work_path or lesson_ctx.get("work_path")
        if not isinstance(work_path, str) or not work_path.strip():
            return {"status": "blocked", "error_code": "internal"}

        # 3. Build canonical First query with transport envelope
        prefix = (
            f"TARGET WORK ITEM v1\n"
            f"W{work_ordinal} | {work_path}\n"
            f"Treat only this mapped work item as the single Lecture. In your output write exactly Lecture: W{work_ordinal} | {work_path}. Do not include any other work item.\n"
            "OUTPUT TRANSPORT FORMAT v1\n"
            "Use plain text. Do not use Markdown headings, bold labels, horizontal rules, or code fences.\n"
            "Each Section header must be exactly Section N: on its own line, with no title after the colon.\n"
            "Within each Section use these eleven labels in this exact order, each ending with a colon. Put its value on following lines.\n"
            "Section 1:\n"
            + "".join(label + ":\n" for label in CANONICAL_FIELD_LABELS)
            + "Repeat the structure for subsequent Sections with contiguous ordinals. Include no formatting preamble.\n"
            f"CANONICAL FIRST BODY\n"
        )
        query_text = f"{prefix}{FIRST_PROMPT}"

        # 4. Claim query from Laravel
        claim_resp = await self.laravel_client.claim_query(
            case_id=case_id,
            kind="first",
            ordinal=work_ordinal,
            activation_id=activation_id,
            binding_ref=binding_ref,
            query_text=query_text,
            conversation_in=conversation_ref,
        )
        claim_data = claim_resp.get("data", {})
        query_ref = claim_data.get("query_ref")
        disposition = claim_data.get("disposition")

        if disposition == "recorded":
            if lesson_ctx.get("phase") != "awaiting_010" or not lesson_ctx.get("section_refs"):
                return {"status": "blocked", "error_code": "internal"}
            return {
                "status": "ready",
                "cursor_phase": "awaiting_010",
                "cursor_version": lesson_ctx.get("cursor_version"),
                "section_count": len(lesson_ctx["section_refs"]),
                "query_ref": query_ref,
            }

        if disposition != "send_now":
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        try:
            mcp_resp = await self.adapter.query_once_by_binding(
                case_id=case_id,
                binding_ref=binding_ref,
                query_text=query_text,
                conversation_id=conversation_ref,
            )
        except Exception:
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        answer = mcp_resp.get("answer", "")
        conv_out = mcp_resp.get("conversation_id")
        if not conv_out:
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        try:
            parsed_first = parse_first_response(answer, expected_work_ordinal=work_ordinal)
        except FirstParserError:
            return {"status": "blocked", "error_code": "validation", "query_ref": query_ref}

        parsed_payload = {
            "work_ordinal": parsed_first.work_ordinal,
            "lecture_header": parsed_first.lecture_header,
            "sections": [
                {"ordinal": section.ordinal, "fields": section.fields}
                for section in parsed_first.sections
            ],
            "completion_marker": parsed_first.completion_marker,
        }

        try:
            result_resp = await self.laravel_client.post_query_result(
                query_ref=query_ref,
                answer=answer,
                conversation_out=conv_out,
                parsed=parsed_payload,
            )
        except Exception:
            refreshed = await self.laravel_client.get_lesson_context(case_id, work_ordinal)
            ref_data = refreshed.get("data", {})
            if ref_data.get("phase") == "awaiting_010" and ref_data.get("section_refs"):
                return {
                    "status": "ready",
                    "cursor_phase": "awaiting_010",
                    "cursor_version": ref_data["cursor_version"],
                    "section_count": len(ref_data["section_refs"]),
                    "query_ref": query_ref,
                }
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        result_data = result_resp.get("data", {})
        if result_data.get("state") != "recorded" or not result_data.get("cursor_version"):
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
        return {
            "status": "ready",
            "cursor_phase": "awaiting_010",
            "cursor_version": result_data["cursor_version"],
            "section_count": len(parsed_first.sections),
            "query_ref": query_ref,
        }

    async def prepare_continuation_lesson(
        self,
        case_id: str,
        activation_id: str,
        binding_ref: str,
        work_ordinal: int,
        work_path: Optional[str] = None,
    ) -> Dict[str, Any]:
        """Execute literal Continue prompt turn for target work ordinal > 1, parse 11 fields, and post result."""
        lesson_ctx_resp = await self.laravel_client.get_lesson_context(case_id, work_ordinal)
        lesson_ctx = lesson_ctx_resp.get("data", {})
        conversation_ref = lesson_ctx.get("conversation_ref")

        if lesson_ctx.get("phase") == "awaiting_010" and lesson_ctx.get("section_refs"):
            return {
                "status": "ready",
                "cursor_phase": "awaiting_010",
                "cursor_version": lesson_ctx.get("cursor_version"),
                "section_count": len(lesson_ctx["section_refs"]),
                "query_ref": lesson_ctx.get("query_ref") or "reconciled",
            }

        query_text = "Continue"

        claim_resp = await self.laravel_client.claim_query(
            case_id=case_id,
            kind="continue",
            ordinal=work_ordinal,
            activation_id=activation_id,
            binding_ref=binding_ref,
            query_text=query_text,
            conversation_in=conversation_ref,
        )
        claim_data = claim_resp.get("data", {})
        query_ref = claim_data.get("query_ref")
        disposition = claim_data.get("disposition")

        if disposition == "recorded":
            refreshed = await self.laravel_client.get_lesson_context(case_id, work_ordinal)
            ref_data = refreshed.get("data", {})
            if ref_data.get("phase") == "awaiting_010" and ref_data.get("section_refs"):
                return {
                    "status": "ready",
                    "cursor_phase": "awaiting_010",
                    "cursor_version": ref_data["cursor_version"],
                    "section_count": len(ref_data["section_refs"]),
                    "query_ref": query_ref,
                }
            return {"status": "blocked", "error_code": "internal"}

        if disposition != "send_now":
            refreshed = await self.laravel_client.get_lesson_context(case_id, work_ordinal)
            ref_data = refreshed.get("data", {})
            if ref_data.get("phase") == "awaiting_010" and ref_data.get("section_refs"):
                return {
                    "status": "ready",
                    "cursor_phase": "awaiting_010",
                    "cursor_version": ref_data["cursor_version"],
                    "section_count": len(ref_data["section_refs"]),
                    "query_ref": query_ref,
                }
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        try:
            mcp_resp = await self.adapter.query_once_by_binding(
                case_id=case_id,
                binding_ref=binding_ref,
                query_text=query_text,
                conversation_id=conversation_ref,
            )
        except Exception:
            refreshed = await self.laravel_client.get_lesson_context(case_id, work_ordinal)
            ref_data = refreshed.get("data", {})
            if ref_data.get("phase") == "awaiting_010" and ref_data.get("section_refs"):
                return {
                    "status": "ready",
                    "cursor_phase": "awaiting_010",
                    "cursor_version": ref_data["cursor_version"],
                    "section_count": len(ref_data["section_refs"]),
                    "query_ref": query_ref,
                }
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        answer = mcp_resp.get("answer", "")
        conv_out = mcp_resp.get("conversation_id")
        if not conv_out or not isinstance(answer, str):
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        try:
            parsed_first = parse_first_response(answer, expected_work_ordinal=work_ordinal)
        except FirstParserError:
            return {"status": "blocked", "error_code": "validation", "query_ref": query_ref}

        parsed_payload = {
            "work_ordinal": parsed_first.work_ordinal,
            "lecture_header": parsed_first.lecture_header,
            "sections": [
                {"ordinal": section.ordinal, "fields": section.fields}
                for section in parsed_first.sections
            ],
            "completion_marker": parsed_first.completion_marker,
        }

        try:
            result_resp = await self.laravel_client.post_query_result(
                query_ref=query_ref,
                answer=answer,
                conversation_out=conv_out,
                parsed=parsed_payload,
            )
        except Exception:
            refreshed = await self.laravel_client.get_lesson_context(case_id, work_ordinal)
            ref_data = refreshed.get("data", {})
            if ref_data.get("phase") == "awaiting_010" and ref_data.get("section_refs"):
                return {
                    "status": "ready",
                    "cursor_phase": "awaiting_010",
                    "cursor_version": ref_data["cursor_version"],
                    "section_count": len(ref_data["section_refs"]),
                    "query_ref": query_ref,
                }
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        result_data = result_resp.get("data", {})
        if result_data.get("state") != "recorded" or not result_data.get("cursor_version"):
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
        return {
            "status": "ready",
            "cursor_phase": "awaiting_010",
            "cursor_version": result_data["cursor_version"],
            "section_count": len(parsed_first.sections),
            "query_ref": query_ref,
        }

    async def prepare_lesson(
        self,
        case_id: str,
        activation_id: str,
        binding_ref: str,
        work_ordinal: int = 1,
        work_path: Optional[str] = None,
    ) -> Dict[str, Any]:
        """Dispatch to prepare_first_lesson or prepare_continuation_lesson based on ordinal."""
        if work_ordinal == 1:
            return await self.prepare_first_lesson(
                case_id=case_id,
                activation_id=activation_id,
                binding_ref=binding_ref,
                work_ordinal=1,
                work_path=work_path,
            )
        return await self.prepare_continuation_lesson(
            case_id=case_id,
            activation_id=activation_id,
            binding_ref=binding_ref,
            work_ordinal=work_ordinal,
            work_path=work_path,
        )
