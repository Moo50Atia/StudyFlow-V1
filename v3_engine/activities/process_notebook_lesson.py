"""Activity: process_notebook_lesson per Spec 016."""
from __future__ import annotations

from typing import Any, Dict
from temporalio import activity

from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
from v3_engine.notebooklm.protocol import DirectLessonProtocol
from v3_engine.notebooklm.laravel_client import HttpLaravelNotebookClient, LaravelClientError
from v3_engine.notebooklm.credentials import CredentialsValidationError
from v3_engine.notebooklm.ports import ProviderError


@activity.defn(name="process_notebook_lesson")
async def process_notebook_lesson(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Execute direct NotebookLM lesson turn (Start or Continue) and accept combined result in Laravel."""
    case_id = payload["case_id"]
    binding_ref = payload["binding_ref"]
    work_ordinal = payload["work_ordinal"]

    laravel_client = HttpLaravelNotebookClient()
    context = await laravel_client.get_lesson_context(case_id, work_ordinal)
    if context.get("completion_ref") and context.get("bundle_ref"):
        return {
            "status": "completed", "bundle_ref": context["bundle_ref"],
            "completion_ref": context["completion_ref"], "work_ordinal": work_ordinal,
            "cursor_version": context["completion_cursor_version"],
            "section_refs": context["section_refs"],
            "original_prompt_refs": context["original_prompt_refs"],
        }
    binding_data = await laravel_client.get_binding_by_ref(case_id, binding_ref)
    provider = SdkSessionProvider()
    direct = DirectLessonProtocol(provider=provider, laravel_client=laravel_client)

    pdf_file = next(f for f in binding_data["manifest"] if f["role"] == "educational_pdf")
    steps_file = next(f for f in binding_data["manifest"] if f["role"] == "framework_steps")

    expected_cursor_version = context["cursor_version"]
    conversation_id = context["conversation_ref"]
    if not conversation_id or context.get("work_node_ref") is None:
        return {"status": "blocked", "error_code": "protocol_validation"}

    try:
        res = await direct.process_lesson(
            case_id=case_id,
            binding_ref=binding_ref,
            notebook_id=binding_data.get("notebook_id", ""),
            pdf_source_id=pdf_file["provider_source_id"],
            steps_source_id=steps_file["provider_source_id"],
            conversation_id=conversation_id,
            work_ordinal=work_ordinal,
            expected_cursor_version=expected_cursor_version,
        )
    except LaravelClientError as exc:
        if exc.status_code == 422:
            return {"status": "blocked", "error_code": "protocol_validation"}
        raise
    except (ProviderError, CredentialsValidationError) as exc:
        return {"status": "blocked", "error_code": exc.code}

    if res.get("status") != "completed":
        return res
    return {
        "status": "completed",
        "bundle_ref": res["bundle_ref"],
        "completion_ref": res["completion_ref"],
        "work_ordinal": work_ordinal,
        "cursor_version": res["cursor_version"],
        "section_refs": res["section_refs"],
        "original_prompt_refs": res["original_prompt_refs"],
    }
