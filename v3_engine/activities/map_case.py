"""Activity: map_case per Spec 009."""
from temporalio import activity
from typing import Dict, Any

@activity.defn(name="map_case")
async def map_case(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Execute Divide prompt, parse map, check coverage, and accept map in Laravel."""
    from v3_engine.lessons.map_service import MapService
    from v3_engine.lessons.client import HttpLaravelLessonClient
    from v3_engine.sources.notebooklm_adapter import KnowledgeSourceAdapter

    case_id = payload.get("case_id")
    activation_id = payload.get("activation_id")
    binding_ref = payload.get("binding_ref")
    if not all(isinstance(value, str) and value for value in (case_id, activation_id, binding_ref)):
        return {"status": "blocked", "error_code": "validation"}

    if payload.get("protocol") == "notebooklm_combined_v1":
        from v3_engine.notebooklm.laravel_client import HttpLaravelNotebookClient, LaravelClientError
        from v3_engine.notebooklm.ports import ProviderError
        from v3_engine.notebooklm.credentials import CredentialsValidationError
        from v3_engine.notebooklm.protocol import DirectLessonProtocol
        from v3_engine.notebooklm.sdk_provider import SdkSessionProvider

        laravel = HttpLaravelNotebookClient()
        binding = await laravel.get_binding_by_ref(case_id, binding_ref)
        manifest = {item["role"]: item for item in binding["manifest"]}
        context = await laravel.get_map_context(activation_id)
        direct = DirectLessonProtocol(SdkSessionProvider(), laravel)

        if not context.get("map_ref"):
            try:
                divide = await direct.divide(
                    case_id, binding_ref, binding["notebook_id"],
                    manifest["educational_pdf"]["provider_source_id"],
                )
            except LaravelClientError as exc:
                if exc.status_code == 422:
                    return {"status": "blocked", "error_code": "protocol_validation"}
                raise
            except (ProviderError, CredentialsValidationError) as exc:
                return {"status": "blocked", "error_code": exc.code}
            if divide["status"] == "uncertain":
                return divide
            context = await laravel.get_map_context(activation_id)

        if context.get("map_state") != "ready" or not context.get("divide_conversation_ref"):
            return {"status": "blocked", "error_code": "protocol_validation"}

        first_context = await laravel.get_lesson_context(case_id, 1)
        if first_context.get("phase") == "start_pending":
            try:
                execution = await direct.execution(
                    case_id, binding_ref, binding["notebook_id"],
                    manifest["educational_pdf"]["provider_source_id"],
                    manifest["framework_steps"]["provider_source_id"],
                    context["divide_conversation_ref"],
                    expected_cursor_version=first_context["cursor_version"],
                )
            except LaravelClientError as exc:
                if exc.status_code == 422:
                    return {"status": "blocked", "error_code": "protocol_validation"}
                raise
            except (ProviderError, CredentialsValidationError) as exc:
                return {"status": "blocked", "error_code": exc.code}
            if execution["status"] == "uncertain":
                return execution
        return {
            "status": "ready",
            "map_ref": context["map_ref"],
            "work_count": context["work_count"],
        }

    service = MapService(KnowledgeSourceAdapter(), HttpLaravelLessonClient())
    return await service.execute_mapping(case_id, activation_id, binding_ref)
