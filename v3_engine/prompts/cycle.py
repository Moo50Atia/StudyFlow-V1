import hashlib
import json
import time
from typing import Any, Dict, List, Optional
import httpx

from v3_engine.contracts import compute_hmac_signature
from v3_engine.prompts.canonical import (
    EXECUTION_PROMPT,
    SECOND_PROMPT,
    THIRD_PROMPT,
    CANONICAL_DIGESTS,
)
from v3_engine.prompts.parser import (
    parse_execution_response,
    parse_continue_response,
    parse_second_blocks,
    parse_third_blocks,
    PromptParserError,
)


class PromptCycleRunner:
    def __init__(self, base_url: str, secret_key: str, mcp_adapter: Any = None):
        self.base_url = base_url.rstrip("/")
        self.secret_key = secret_key
        self.mcp_adapter = mcp_adapter

    async def _source_query(
        self,
        case_id: str,
        binding_ref: str,
        query_text: str,
        conversation_id: Optional[str],
    ) -> Dict[str, str]:
        if self.mcp_adapter is None:
            raise RuntimeError("NotebookLM source adapter is not configured.")
        result = await self.mcp_adapter.query_once_by_binding(
            case_id=case_id,
            binding_ref=binding_ref,
            query_text=query_text,
            conversation_id=conversation_id,
        )
        conversation_out = result.get("conversation_id")
        if not isinstance(result.get("answer"), str) or not isinstance(conversation_out, str) or not conversation_out.strip():
            raise RuntimeError("NotebookLM returned an invalid prompt turn response.")
        return {"answer": result["answer"], "conversation_id": conversation_out}

    async def _complete_work_item(
        self,
        client: httpx.AsyncClient,
        case_id: str,
        work_item_ref: str,
    ) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/complete"
        return await self._api_call(client, "POST", path, {"schema_version": 1})

    def _sign_headers(self, method: str, path: str, body_bytes: bytes) -> Dict[str, str]:
        ts = int(time.time())
        sig = compute_hmac_signature(method, path, ts, body_bytes, self.secret_key)
        return {
            "X-V3-Timestamp": str(ts),
            "X-V3-Signature": sig,
            "X-V3-Contract-Version": "1",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    async def _api_call(self, client: httpx.AsyncClient, method: str, path: str, payload: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        raw_body = json.dumps(payload, separators=(",", ":")).encode("utf-8") if payload is not None else b""
        headers = self._sign_headers(method, path, raw_body)
        url = f"{self.base_url}{path}"

        if method.upper() == "GET":
            resp = await client.get(url, headers=headers, timeout=30.0)
        else:
            resp = await client.post(url, content=raw_body, headers=headers, timeout=30.0)

        if resp.status_code >= 400:
            raise RuntimeError(f"API call {method} {path} returned {resp.status_code}: {resp.text}")

        return resp.json().get("data", {})

    async def run_cycle(self, case_id: str, work_ordinal: int, binding_ref: str) -> Dict[str, Any]:
        async with httpx.AsyncClient() as client:
            # 1. Fetch prompt context
            context_path = f"/api/internal/v1/cases/{case_id}/prompt-context/{work_ordinal}"
            context = await self._api_call(client, "GET", context_path)

            map_ref = context["map_ref"]
            work_node_ref = context["work_node_ref"]
            section_refs = context["section_refs"]
            cursor_version = context["cursor_version"]
            conversation_ref = context["conversation_ref"]
            sections = context["sections"]

            # 2. Claim work item
            claim_work_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_ordinal}/claim"
            claim_work_payload = {
                "schema_version": 1,
                "map_ref": map_ref,
                "work_node_ref": work_node_ref,
                "section_refs": section_refs,
                "expected_cursor_version": cursor_version,
            }
            work_res = await self._api_call(client, "POST", claim_work_path, claim_work_payload)
            work_item_ref = work_res["work_item_ref"]

            expected_section_ordinals = [s["ordinal_in_work"] for s in sections]
            section_ref_map = {s["ordinal_in_work"]: s["section_ref"] for s in sections}

            if work_ordinal == 1:
                # Execution turn
                claim_exec_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/execution/claim"
                claim_exec_payload = {
                    "schema_version": 1,
                    "origin": "sent",
                    "conversation_in": conversation_ref,
                    "canonical_sha256": CANONICAL_DIGESTS["execution"],
                    "origin_query_ref": None,
                    "origin_turn_ref": None,
                }
                exec_claim = await self._api_call(client, "POST", claim_exec_path, claim_exec_payload)
                exec_turn_ref = exec_claim["turn_ref"]

                if exec_claim["disposition"] == "send_now":
                    # Query NotebookLM
                    exec_ans_data = await self._source_query(case_id, binding_ref, EXECUTION_PROMPT, conversation_ref)
                    exec_ans = exec_ans_data["answer"]
                    conv_after_exec = exec_ans_data.get("conversation_id", conversation_ref)

                    exec_parsed = parse_execution_response(exec_ans, work_ordinal, sections)
                    exec_shape = exec_parsed["shape"]

                    res_exec_path = f"/api/internal/v1/cases/{case_id}/prompt-turns/{exec_turn_ref}/result"
                    res_exec_payload = {
                        "schema_version": 1,
                        "answer": exec_ans,
                        "conversation_out": conv_after_exec,
                        "execution_shape": exec_shape,
                        "blocks": [],
                        "answer_sha256": hashlib.sha256(exec_ans.encode("utf-8")).hexdigest(),
                    }
                    await self._api_call(client, "POST", res_exec_path, res_exec_payload)

                    if exec_shape == "ready":
                        # Send Second
                        claim_sec_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/second/claim"
                        claim_sec_payload = {
                            "schema_version": 1,
                            "origin": "sent",
                            "conversation_in": conv_after_exec,
                            "canonical_sha256": CANONICAL_DIGESTS["second"],
                            "origin_query_ref": None,
                            "origin_turn_ref": None,
                        }
                        sec_claim = await self._api_call(client, "POST", claim_sec_path, claim_sec_payload)
                        sec_turn_ref = sec_claim["turn_ref"]
                        if sec_claim.get("disposition") != "send_now":
                            return {"status": "in_progress", "work_item_ref": work_item_ref, "turn_ref": sec_turn_ref}

                        sec_ans_data = await self._source_query(case_id, binding_ref, SECOND_PROMPT, conv_after_exec)
                        sec_ans = sec_ans_data["answer"]
                        conv_after_sec = sec_ans_data.get("conversation_id", conv_after_exec)

                        sec_blocks = parse_second_blocks(sec_ans, work_ordinal, expected_section_ordinals)
                        sec_blocks_with_refs = [
                            {"section_ref": section_ref_map[b["ordinal"]], "ordinal": b["ordinal"], "text": b["text"]}
                            for b in sec_blocks
                        ]

                        res_sec_path = f"/api/internal/v1/cases/{case_id}/prompt-turns/{sec_turn_ref}/result"
                        res_sec_payload = {
                            "schema_version": 1,
                            "answer": sec_ans,
                            "conversation_out": conv_after_sec,
                            "execution_shape": None,
                            "blocks": sec_blocks_with_refs,
                            "answer_sha256": hashlib.sha256(sec_ans.encode("utf-8")).hexdigest(),
                        }
                        await self._api_call(client, "POST", res_sec_path, res_sec_payload)

                        # Send Third
                        claim_third_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/third/claim"
                        claim_third_payload = {
                            "schema_version": 1,
                            "origin": "sent",
                            "conversation_in": conv_after_sec,
                            "canonical_sha256": CANONICAL_DIGESTS["third"],
                            "origin_query_ref": None,
                            "origin_turn_ref": None,
                        }
                        third_claim = await self._api_call(client, "POST", claim_third_path, claim_third_payload)
                        third_turn_ref = third_claim["turn_ref"]
                        if third_claim.get("disposition") != "send_now":
                            return {"status": "in_progress", "work_item_ref": work_item_ref, "turn_ref": third_turn_ref}

                        third_ans_data = await self._source_query(case_id, binding_ref, THIRD_PROMPT, conv_after_sec)
                        third_ans = third_ans_data["answer"]
                        conv_after_third = third_ans_data.get("conversation_id", conv_after_sec)

                        third_blocks = parse_third_blocks(third_ans, work_ordinal, expected_section_ordinals)
                        third_blocks_with_refs = [
                            {"section_ref": section_ref_map[b["ordinal"]], "ordinal": b["ordinal"], "text": b["text"]}
                            for b in third_blocks
                        ]

                        res_third_path = f"/api/internal/v1/cases/{case_id}/prompt-turns/{third_turn_ref}/result"
                        res_third_payload = {
                            "schema_version": 1,
                            "answer": third_ans,
                            "conversation_out": conv_after_third,
                            "execution_shape": None,
                            "blocks": third_blocks_with_refs,
                            "answer_sha256": hashlib.sha256(third_ans.encode("utf-8")).hexdigest(),
                        }
                        third_res = await self._api_call(client, "POST", res_third_path, res_third_payload)
                        completion = await self._complete_work_item(client, case_id, work_item_ref)

                        return {
                            "status": "completed",
                            "work_item_ref": work_item_ref,
                            "original_prompt_refs": third_res.get("original_prompt_refs", []),
                            "completion_ref": completion.get("completion_ref"),
                            "cursor_version": completion.get("cursor_version"),
                        }

                    elif exec_shape == "combined":
                        # Record Second and Third as execution_reuse
                        sec_blocks = exec_parsed["second_blocks"]
                        sec_blocks_with_refs = [
                            {"section_ref": section_ref_map[b["ordinal"]], "ordinal": b["ordinal"], "text": b["text"]}
                            for b in sec_blocks
                        ]
                        claim_sec_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/second/claim"
                        sec_claim = await self._api_call(client, "POST", claim_sec_path, {
                            "schema_version": 1,
                            "origin": "execution_reuse",
                            "conversation_in": conv_after_exec,
                            "canonical_sha256": CANONICAL_DIGESTS["second"],
                            "origin_query_ref": None,
                            "origin_turn_ref": exec_turn_ref,
                        })
                        await self._api_call(client, "POST", f"/api/internal/v1/cases/{case_id}/prompt-turns/{sec_claim['turn_ref']}/result", {
                            "schema_version": 1,
                            "answer": exec_ans,
                            "conversation_out": conv_after_exec,
                            "execution_shape": None,
                            "blocks": sec_blocks_with_refs,
                            "answer_sha256": hashlib.sha256(exec_ans.encode("utf-8")).hexdigest(),
                        })

                        third_blocks = exec_parsed["third_blocks"]
                        third_blocks_with_refs = [
                            {"section_ref": section_ref_map[b["ordinal"]], "ordinal": b["ordinal"], "text": b["text"]}
                            for b in third_blocks
                        ]
                        claim_third_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/third/claim"
                        third_claim = await self._api_call(client, "POST", claim_third_path, {
                            "schema_version": 1,
                            "origin": "execution_reuse",
                            "conversation_in": conv_after_exec,
                            "canonical_sha256": CANONICAL_DIGESTS["third"],
                            "origin_query_ref": None,
                            "origin_turn_ref": exec_turn_ref,
                        })
                        third_res = await self._api_call(client, "POST", f"/api/internal/v1/cases/{case_id}/prompt-turns/{third_claim['turn_ref']}/result", {
                            "schema_version": 1,
                            "answer": exec_ans,
                            "conversation_out": conv_after_exec,
                            "execution_shape": None,
                            "blocks": third_blocks_with_refs,
                            "answer_sha256": hashlib.sha256(exec_ans.encode("utf-8")).hexdigest(),
                        })
                        completion = await self._complete_work_item(client, case_id, work_item_ref)

                        return {
                            "status": "completed",
                            "work_item_ref": work_item_ref,
                            "original_prompt_refs": third_res.get("original_prompt_refs", []),
                            "completion_ref": completion.get("completion_ref"),
                            "cursor_version": completion.get("cursor_version"),
                        }

            else:
                continue_origin = context.get("continue_origin")
                reused = False
                if continue_origin and continue_origin.get("answer"):
                    try:
                        sec_blocks = parse_second_blocks(continue_origin["answer"], work_ordinal, expected_section_ordinals)
                        third_blocks = parse_third_blocks(continue_origin["answer"], work_ordinal, expected_section_ordinals)
                        reused = True
                    except Exception:
                        reused = False

                if reused:
                    sec_blocks_with_refs = [
                        {"section_ref": section_ref_map[b["ordinal"]], "ordinal": b["ordinal"], "text": b["text"]}
                        for b in sec_blocks
                    ]
                    claim_sec_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/second/claim"
                    sec_claim = await self._api_call(client, "POST", claim_sec_path, {
                        "schema_version": 1,
                        "origin": "continue_reuse",
                        "conversation_in": conversation_ref,
                        "canonical_sha256": CANONICAL_DIGESTS["second"],
                        "origin_query_ref": continue_origin.get("query_ref"),
                        "origin_turn_ref": None,
                    })
                    await self._api_call(client, "POST", f"/api/internal/v1/cases/{case_id}/prompt-turns/{sec_claim['turn_ref']}/result", {
                        "schema_version": 1,
                        "answer": continue_origin["answer"],
                        "conversation_out": continue_origin.get("conversation_out", conversation_ref),
                        "execution_shape": None,
                        "blocks": sec_blocks_with_refs,
                        "answer_sha256": continue_origin.get("answer_sha256") or hashlib.sha256(continue_origin["answer"].encode("utf-8")).hexdigest(),
                    })

                    third_blocks_with_refs = [
                        {"section_ref": section_ref_map[b["ordinal"]], "ordinal": b["ordinal"], "text": b["text"]}
                        for b in third_blocks
                    ]
                    claim_third_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/third/claim"
                    third_claim = await self._api_call(client, "POST", claim_third_path, {
                        "schema_version": 1,
                        "origin": "continue_reuse",
                        "conversation_in": continue_origin.get("conversation_out", conversation_ref),
                        "canonical_sha256": CANONICAL_DIGESTS["third"],
                        "origin_query_ref": continue_origin.get("query_ref"),
                        "origin_turn_ref": None,
                    })
                    third_res = await self._api_call(client, "POST", f"/api/internal/v1/cases/{case_id}/prompt-turns/{third_claim['turn_ref']}/result", {
                        "schema_version": 1,
                        "answer": continue_origin["answer"],
                        "conversation_out": continue_origin.get("conversation_out", conversation_ref),
                        "execution_shape": None,
                        "blocks": third_blocks_with_refs,
                        "answer_sha256": continue_origin.get("answer_sha256") or hashlib.sha256(continue_origin["answer"].encode("utf-8")).hexdigest(),
                    })
                    completion = await self._complete_work_item(client, case_id, work_item_ref)
                    return {
                        "status": "completed",
                        "work_item_ref": work_item_ref,
                        "original_prompt_refs": third_res.get("original_prompt_refs", []),
                        "completion_ref": completion.get("completion_ref"),
                        "cursor_version": completion.get("cursor_version"),
                    }
                else:
                    claim_sec_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/second/claim"
                    claim_sec_payload = {
                        "schema_version": 1,
                        "origin": "sent",
                        "conversation_in": conversation_ref,
                        "canonical_sha256": CANONICAL_DIGESTS["second"],
                        "origin_query_ref": None,
                        "origin_turn_ref": None,
                    }
                    sec_claim = await self._api_call(client, "POST", claim_sec_path, claim_sec_payload)
                    sec_turn_ref = sec_claim["turn_ref"]
                    if sec_claim.get("disposition") != "send_now":
                        return {"status": "in_progress", "work_item_ref": work_item_ref, "turn_ref": sec_turn_ref}

                    sec_ans_data = await self._source_query(case_id, binding_ref, SECOND_PROMPT, conversation_ref)
                    sec_ans = sec_ans_data["answer"]
                    conv_after_sec = sec_ans_data.get("conversation_id", conversation_ref)

                    sec_blocks = parse_second_blocks(sec_ans, work_ordinal, expected_section_ordinals)
                    sec_blocks_with_refs = [
                        {"section_ref": section_ref_map[b["ordinal"]], "ordinal": b["ordinal"], "text": b["text"]}
                        for b in sec_blocks
                    ]

                    res_sec_path = f"/api/internal/v1/cases/{case_id}/prompt-turns/{sec_turn_ref}/result"
                    res_sec_payload = {
                        "schema_version": 1,
                        "answer": sec_ans,
                        "conversation_out": conv_after_sec,
                        "execution_shape": None,
                        "blocks": sec_blocks_with_refs,
                        "answer_sha256": hashlib.sha256(sec_ans.encode("utf-8")).hexdigest(),
                    }
                    await self._api_call(client, "POST", res_sec_path, res_sec_payload)

                    claim_third_path = f"/api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/third/claim"
                    claim_third_payload = {
                        "schema_version": 1,
                        "origin": "sent",
                        "conversation_in": conv_after_sec,
                        "canonical_sha256": CANONICAL_DIGESTS["third"],
                        "origin_query_ref": None,
                        "origin_turn_ref": None,
                    }
                    third_claim = await self._api_call(client, "POST", claim_third_path, claim_third_payload)
                    third_turn_ref = third_claim["turn_ref"]
                    if third_claim.get("disposition") != "send_now":
                        return {"status": "in_progress", "work_item_ref": work_item_ref, "turn_ref": third_turn_ref}

                    third_ans_data = await self._source_query(case_id, binding_ref, THIRD_PROMPT, conv_after_sec)
                    third_ans = third_ans_data["answer"]
                    conv_after_third = third_ans_data.get("conversation_id", conv_after_sec)

                    third_blocks = parse_third_blocks(third_ans, work_ordinal, expected_section_ordinals)
                    third_blocks_with_refs = [
                        {"section_ref": section_ref_map[b["ordinal"]], "ordinal": b["ordinal"], "text": b["text"]}
                        for b in third_blocks
                    ]

                    res_third_path = f"/api/internal/v1/cases/{case_id}/prompt-turns/{third_turn_ref}/result"
                    res_third_payload = {
                        "schema_version": 1,
                        "answer": third_ans,
                        "conversation_out": conv_after_third,
                        "execution_shape": None,
                        "blocks": third_blocks_with_refs,
                        "answer_sha256": hashlib.sha256(third_ans.encode("utf-8")).hexdigest(),
                    }
                    third_res = await self._api_call(client, "POST", res_third_path, res_third_payload)
                    completion = await self._complete_work_item(client, case_id, work_item_ref)

                    return {
                        "status": "completed",
                        "work_item_ref": work_item_ref,
                        "original_prompt_refs": third_res.get("original_prompt_refs", []),
                        "completion_ref": completion.get("completion_ref"),
                        "cursor_version": completion.get("cursor_version"),
                    }

            return {
                "status": "in_progress",
                "work_item_ref": work_item_ref,
            }

