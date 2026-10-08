"""Read-only NotebookLM history reconciliation for an already claimed direct turn."""
from __future__ import annotations

import hashlib
from typing import Any

from temporalio import activity

from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.notebooklm.laravel_client import HttpLaravelNotebookClient, LaravelClientError
from v3_engine.notebooklm.protocol import recover_turn_from_history
from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
from v3_engine.notebooklm.credentials import CredentialsValidationError
from v3_engine.notebooklm.ports import ProviderError


@activity.defn(name="reconcile_notebook_query")
async def reconcile_notebook_query(payload: dict[str, Any]) -> dict[str, Any]:
    case_id = payload["case_id"]
    kind = payload["kind"]
    ordinal = int(payload["work_ordinal"])
    binding_ref = payload["binding_ref"]
    client = HttpLaravelNotebookClient()
    query = await client.find_lesson_query(case_id, kind, ordinal)
    if not query or query["binding_ref"] != binding_ref:
        return {"status": "blocked", "error_code": "lesson_unavailable"}
    if query["state"] == "recorded":
        return {"status": "recorded", "query_ref": query["query_ref"]}
    if query["state"] == "blocked":
        return {"status": "blocked", "error_code": "protocol_validation"}
    conversation_id = query.get("conversation_in")
    if not conversation_id:
        # A lost Divide conversation ID cannot be recovered by notebook title.
        return {"status": "blocked", "error_code": "provider_uncertain"}

    binding = await client.get_binding_by_ref(case_id, binding_ref)
    question = (
        EXECUTION_PROMPT if kind == "execution" else
        "Start with First lesson" if kind == "lesson_start" else "Continue"
    )
    try:
        async with SdkSessionProvider().open() as session:
            pairs = await session.conversation.history(binding["notebook_id"], conversation_id, limit=1000)
    except (ProviderError, CredentialsValidationError) as exc:
        return {"status": "blocked", "error_code": exc.code}
    recovered = recover_turn_from_history(
        [{"question": q, "answer": a} for q, a in pairs],
        query["history_length_before"], question, query["history_anchor_sha256"],
    )
    if recovered["status"] != "recovered":
        return {"status": "uncertain", "query_ref": query["query_ref"]}
    answer = recovered["answer"]
    try:
        if kind == "execution":
            await client.record_execution_result(query["query_ref"], answer, conversation_id)
        else:
            await client.record_combined_lesson_result(query["query_ref"], {
                "schema_version": 1,
                "expected_cursor_version": query["expected_cursor_version"],
                "answer": answer,
                "answer_sha256": hashlib.sha256(answer.encode("utf-8")).hexdigest(),
                "conversation_out": conversation_id,
            })
    except LaravelClientError as exc:
        if exc.status_code == 422:
            return {"status": "blocked", "error_code": "protocol_validation"}
        raise
    return {"status": "recorded", "query_ref": query["query_ref"]}
