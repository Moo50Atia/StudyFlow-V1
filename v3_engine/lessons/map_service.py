"""Map execution using 008 source-scoped queries and 009 persisted claims."""

from dataclasses import asdict
from typing import Any, Dict, Optional

from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT
from v3_engine.lessons.map_parser import MapParserError, parse_divide_response
from v3_engine.lessons.coverage_parser import CoverageParserError, parse_coverage_response


class MapService:
    def __init__(self, adapter: Any, laravel_client: Any):
        self.adapter = adapter
        self.laravel_client = laravel_client

    async def _context(self, case_id: str, activation_id: str, binding_ref: str) -> Dict[str, Any]:
        response = await self.laravel_client.get_activation_context(activation_id)
        context = response.get("data", {})
        if context.get("case_id") != case_id or context.get("binding_ref") != binding_ref:
            raise ValueError("Map activation identity does not match its bound source.")
        page_count = context.get("page_count")
        if not isinstance(page_count, int) or isinstance(page_count, bool) or page_count < 1:
            raise ValueError("Verified extracted page count is unavailable.")
        return context

    @staticmethod
    def _ready(context: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        if context.get("map_state") != "ready":
            return None
        map_ref = context.get("map_ref")
        work_count = context.get("work_count")
        if not map_ref or not isinstance(work_count, int) or work_count < 1:
            raise ValueError("Ready lesson map is missing its committed identity or work count.")
        return {"status": "ready", "map_ref": map_ref, "work_count": work_count}

    async def _source_query(
        self,
        case_id: str,
        binding_ref: str,
        query_text: str,
        conversation_id: Optional[str],
    ) -> Dict[str, Any]:
        return await self.adapter.query_once_by_binding(
            case_id=case_id,
            binding_ref=binding_ref,
            query_text=query_text,
            conversation_id=conversation_id,
        )

    async def execute_mapping(self, case_id: str, activation_id: str, binding_ref: str) -> Dict[str, Any]:
        context = await self._context(case_id, activation_id, binding_ref)
        ready = self._ready(context)
        if ready:
            return ready

        map_ref = context.get("map_ref")
        work_items = context.get("work_items", [])
        divide_conversation = context.get("divide_conversation_ref")

        # A persisted pending map proves Divide was committed. Reuse its nodes and
        # conversation; never re-send a stateful Divide after an uncertain receipt.
        if not map_ref:
            claim_response = await self.laravel_client.claim_query(
                case_id=case_id,
                kind="divide",
                ordinal=0,
                activation_id=activation_id,
                binding_ref=binding_ref,
                query_text=DIVIDE_PROMPT,
                conversation_in=None,
            )
            claim = claim_response.get("data", {})
            query_ref = claim.get("query_ref")
            disposition = claim.get("disposition")
            if disposition != "send_now":
                refreshed = await self._context(case_id, activation_id, binding_ref)
                ready = self._ready(refreshed)
                if ready:
                    return ready
                if refreshed.get("map_ref"):
                    context = refreshed
                    map_ref = context.get("map_ref")
                    work_items = context.get("work_items", [])
                    divide_conversation = context.get("divide_conversation_ref")
                else:
                    return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
            else:
                try:
                    response = await self._source_query(case_id, binding_ref, DIVIDE_PROMPT, None)
                except Exception:
                    return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
                answer = response.get("answer")
                divide_conversation = response.get("conversation_id")
                if not isinstance(answer, str) or not isinstance(divide_conversation, str) or not divide_conversation.strip():
                    return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
                try:
                    nodes = parse_divide_response(answer)
                except MapParserError:
                    return {"status": "blocked", "error_code": "validation"}
                parsed_nodes = [asdict(node) for node in nodes]
                node_by_path = {node["position_path"]: node for node in parsed_nodes}

                def full_path(node: Dict[str, Any]) -> str:
                    titles = [node["title"]]
                    parent_path = node.get("parent_position_path")
                    while parent_path:
                        parent = node_by_path.get(parent_path)
                        if parent is None:
                            raise ValueError("Map parent path is missing.")
                        titles.append(parent["title"])
                        parent_path = parent.get("parent_position_path")
                    return " / ".join(reversed(titles))

                try:
                    local_work_items = [
                        {
                            "work_ordinal": node["work_ordinal"],
                            "work_path": full_path(node),
                        }
                        for node in parsed_nodes
                        if node.get("work_ordinal") is not None
                    ]
                    result = await self.laravel_client.post_query_result(
                        query_ref=query_ref,
                        answer=answer,
                        conversation_out=divide_conversation,
                        parsed={"nodes": parsed_nodes},
                    )
                except Exception:
                    # Laravel may have committed the map before the response was
                    # lost. Reconcile from its durable projection; never resend
                    # a stateful Divide turn after an uncertain receipt.
                    try:
                        refreshed = await self._context(case_id, activation_id, binding_ref)
                    except Exception:
                        return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
                    ready = self._ready(refreshed)
                    if ready:
                        return ready
                    if not refreshed.get("map_ref"):
                        return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
                    context = refreshed
                    map_ref = context.get("map_ref")
                    work_items = context.get("work_items", [])
                    divide_conversation = context.get("divide_conversation_ref")
                else:
                    result_data = result.get("data", {})
                    if result_data.get("state") != "recorded" or not result_data.get("map_ref"):
                        return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
                    map_ref = result_data["map_ref"]
                    work_items = local_work_items

        if not isinstance(work_items, list) or not work_items:
            return {"status": "blocked", "error_code": "internal"}
        work_items = sorted(work_items, key=lambda item: item.get("work_ordinal", 0))
        ordinals = [item.get("work_ordinal") for item in work_items]
        if ordinals != list(range(1, len(work_items) + 1)):
            return {"status": "blocked", "error_code": "internal"}
        if any(not isinstance(item.get("work_path"), str) or not item["work_path"].strip() for item in work_items):
            return {"status": "blocked", "error_code": "internal"}

        coverage_lines = [
            "COVERAGE CHECK v1",
            "For every literal [PAGE i/N] source marker, assign the educational content on that page to one or more listed work items. Return exactly one line PAGE i: Wn[,Wn...] for each i=1..N; use PAGE i: GAP when no assignment is grounded. Output no other text.",
            "WORK ITEMS",
        ]
        coverage_lines.extend(
            f"W{item['work_ordinal']} | {item['work_path']}" for item in work_items
        )
        coverage_query = "\n".join(coverage_lines) + "\n"
        if len(coverage_query.encode("utf-8")) > 16384:
            return {"status": "blocked", "error_code": "validation"}

        claim_response = await self.laravel_client.claim_query(
            case_id=case_id,
            kind="coverage",
            ordinal=0,
            activation_id=activation_id,
            binding_ref=binding_ref,
            query_text=coverage_query,
            conversation_in=divide_conversation,
        )
        claim = claim_response.get("data", {})
        query_ref = claim.get("query_ref")
        disposition = claim.get("disposition")
        if disposition != "send_now":
            refreshed = await self._context(case_id, activation_id, binding_ref)
            ready = self._ready(refreshed)
            if ready:
                return ready
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        try:
            response = await self._source_query(case_id, binding_ref, coverage_query, divide_conversation)
        except Exception:
            # The commit may have succeeded even when its response was lost.
            # Only the authoritative ready projection proves completion.
            try:
                refreshed = await self._context(case_id, activation_id, binding_ref)
            except Exception:
                return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
            ready = self._ready(refreshed)
            if ready:
                return ready
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
        answer = response.get("answer")
        conversation_out = response.get("conversation_id")
        if not isinstance(answer, str) or not isinstance(conversation_out, str) or not conversation_out.strip():
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
        try:
            pages = parse_coverage_response(answer, context["page_count"], len(work_items))
        except CoverageParserError:
            return {"status": "blocked", "error_code": "validation"}

        try:
            result = await self.laravel_client.post_query_result(
                query_ref=query_ref,
                answer=answer,
                conversation_out=conversation_out,
                parsed={"pages": [asdict(page) for page in pages]},
            )
        except Exception:
            # The ledger result and ready map may commit before the HTTP
            # acknowledgement is lost. Reconcile only through persisted context.
            try:
                refreshed = await self._context(case_id, activation_id, binding_ref)
            except Exception:
                return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
            ready = self._ready(refreshed)
            if ready:
                return ready
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}

        result_data = result.get("data", {})
        if result_data.get("state") != "recorded" or not result_data.get("map_ref"):
            return {"status": "uncertain", "error_code": "lesson_query_uncertain", "query_ref": query_ref}
        return {"status": "ready", "map_ref": result_data["map_ref"], "work_count": len(work_items)}
