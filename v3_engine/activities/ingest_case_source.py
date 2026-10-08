from __future__ import annotations

from pathlib import Path
from typing import Any, Dict
from temporalio import activity

from v3_engine.sources.notebooklm_adapter import KnowledgeSourceAdapter
from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
from v3_engine.notebooklm.protocol import DirectSourceProtocol
from v3_engine.notebooklm.laravel_client import HttpLaravelNotebookClient


@activity.defn(name="ingest_case_source")
async def ingest_case_source(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Activity for ingesting case source via KnowledgeSourceAdapter or DirectSourceProtocol."""
    case_id = payload["case_id"]
    activation_id = payload["activation_id"]
    protocol = payload.get("protocol", "legacy_staged_v1")

    if protocol == "notebooklm_combined_v1":
        from v3_engine.notebooklm.credentials import CredentialsValidationError
        from v3_engine.notebooklm.ports import ProviderError
        laravel_client = HttpLaravelNotebookClient()
        binding_data = await laravel_client.get_binding(case_id, activation_id)
        provider = SdkSessionProvider()
        temp_dir = Path("/tmp/v3_engine_sources") / case_id
        direct = DirectSourceProtocol(provider=provider, laravel_client=laravel_client, temp_dir=temp_dir)
        pdf_file = next(f for f in binding_data["manifest"] if f["role"] == "educational_pdf")
        steps_file = next(f for f in binding_data["manifest"] if f["role"] == "framework_steps")
        try:
            return await direct.ingest(
                case_id=case_id,
                activation_id=activation_id,
                binding_ref=binding_data["binding_ref"],
                pdf_title=pdf_file["title"],
                pdf_sha256=pdf_file["artifact_sha256"],
                steps_sha256=steps_file["artifact_sha256"],
            )
        except (ProviderError, CredentialsValidationError) as exc:
            return {"status": "blocked", "error_code": exc.code}

    extracted_source_ref = payload["extracted_source_ref"]
    adapter = KnowledgeSourceAdapter()
    return await adapter.ingest(
        case_id=case_id,
        extracted_source_ref=extracted_source_ref,
        activation_id=activation_id,
    )
