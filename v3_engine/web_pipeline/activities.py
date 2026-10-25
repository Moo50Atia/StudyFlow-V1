"""Temporal Activities for Web Pipeline per-PDF processing and prompt export (T029, Spec 019)."""

from __future__ import annotations

import hashlib
import json
import logging
import os
import stat
import tempfile
import asyncio
from pathlib import Path
from typing import Any
from temporalio import activity

from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.notebooklm.terminal_service import (
    NotebookTerminalService,
    atomic_json,
    bootstrap_subject_credentials,
    sha256_file,
)
from v3_engine.notebooklm.ports import AuthenticationRequiredError, ProviderError
from v3_engine.notebooklm.protocol import COMBINED_RESPONSE_FORMAT_TEXT
from v3_engine.notebooklm.protocol import DirectLessonProtocol
from v3_engine.notebooklm.laravel_client import HttpLaravelNotebookClient, LaravelClientError
from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
from v3_engine.web_pipeline.application_client import ApplicationClient, ApplicationPipelineError
from v3_engine.web_pipeline.artifact_refs import is_valid_uuid_ref
from v3_engine.web_pipeline.contracts import sanitize_activity_result
from v3_engine.web_pipeline.settings import get_settings

logger = logging.getLogger("v3_engine.web_pipeline.activities")
_terminal_service: NotebookTerminalService | None = None
_application_client: ApplicationClient | None = None


async def _run_with_activity_heartbeat(processor, args: dict[str, Any]) -> dict[str, Any]:
    async def heartbeat_while_processing() -> None:
        while True:
            await asyncio.sleep(30)
            activity.heartbeat({"phase": "notebooklm_processing"})

    heartbeat_task = asyncio.create_task(heartbeat_while_processing())
    try:
        return await processor(args)
    finally:
        heartbeat_task.cancel()
        try:
            await heartbeat_task
        except asyncio.CancelledError:
            pass


def get_terminal_service() -> NotebookTerminalService:
    global _terminal_service
    if _terminal_service is None:
        _terminal_service = NotebookTerminalService()
    return _terminal_service


def set_terminal_service(service: NotebookTerminalService | None) -> None:
    global _terminal_service
    _terminal_service = service


def get_application_client() -> ApplicationClient:
    global _application_client
    if _application_client is None:
        _application_client = ApplicationClient()
    return _application_client


def set_application_client(client: ApplicationClient | None) -> None:
    """Inject an application client in narrowly scoped adapter tests."""
    global _application_client
    _application_client = client


def _run_dir(run_id: str) -> Path:
    if not is_valid_uuid_ref(run_id):
        raise ValueError("run_reference_invalid")
    root = get_settings().terminal_root.resolve()
    run_dir = root / "runs" / run_id.lower()
    if run_dir.is_symlink() or not run_dir.resolve().is_relative_to(root):
        raise ValueError("run_root_invalid")
    return run_dir


def _web_private_dir(run_id: str) -> Path:
    path = _run_dir(run_id) / "private" / "web-pipeline"
    path.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(path, stat.S_IRWXU)
    if path.is_symlink() or not path.resolve().is_relative_to(_run_dir(run_id).resolve()):
        raise ValueError("private_root_invalid")
    return path


def _context_path(run_id: str) -> Path:
    return _web_private_dir(run_id) / "context.json"


def _read_context(run_id: str) -> dict[str, Any]:
    path = _context_path(run_id)
    if path.is_symlink() or not path.is_file():
        raise FileNotFoundError("run_context_missing")
    payload = json.loads(path.read_text(encoding="utf-8"))
    if payload.get("run_id") != run_id:
        raise ValueError("run_context_conflict")
    return payload


def _save_context(run_id: str, context: dict[str, Any]) -> None:
    if context.get("run_id") != run_id:
        raise ValueError("run_context_conflict")
    atomic_json(_context_path(run_id), context)


def _store_private_bytes(run_id: str, name: str, content: bytes) -> Path:
    if not name or any(ch not in "0123456789abcdef-" for ch in name.lower()):
        raise ValueError("artifact_reference_invalid")
    root = _web_private_dir(run_id) / "inputs"
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    target = root / f"{name.lower()}.bin"
    if target.is_symlink():
        raise ValueError("artifact_path_invalid")
    fd, temporary = tempfile.mkstemp(prefix=".staging-", dir=root)
    try:
        os.fchmod(fd, stat.S_IRUSR | stat.S_IWUSR)
        with os.fdopen(fd, "wb") as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, target)
    except Exception:
        try:
            os.unlink(temporary)
        except OSError:
            pass
        raise
    return target


def _credential_path(run_id: str) -> Path | None:
    # Test transports do not need a copied browser session. Production uses a
    # per-run 0600 copy so concurrent runs cannot mutate the operator profile.
    if _terminal_service is not None:
        return None
    from v3_engine.notebooklm.credentials import get_storage_state_path

    private = _web_private_dir(run_id)
    sessions = private / "sessions"
    target = sessions / run_id / "storage_state.json"
    if target.is_file() and not target.is_symlink():
        return target
    master = get_storage_state_path()
    if not master.is_file() or master.is_symlink():
        raise AuthenticationRequiredError()
    return bootstrap_subject_credentials(master, run_id, session_base_dir=sessions)


def _lesson_protocol(run_id: str) -> DirectLessonProtocol:
    service = get_terminal_service()
    provider = service.session_provider
    credentials = _credential_path(run_id)
    if _terminal_service is None and credentials is not None:
        provider = SdkSessionProvider(storage_state_path=credentials)
    return DirectLessonProtocol(
        provider=provider,
        laravel_client=HttpLaravelNotebookClient(),
        profile_ref="mo50atiapro",
    )


def _remote_operation_path(run_id: str, operation_id: str) -> Path:
    folder = _web_private_dir(run_id) / "remote-operations"
    folder.mkdir(parents=True, exist_ok=True, mode=0o700)
    name = hashlib.sha256(operation_id.encode("utf-8")).hexdigest() + ".json"
    path = folder / name
    if path.is_symlink() or not path.resolve().is_relative_to(folder.resolve()):
        raise ValueError("operation_path_invalid")
    return path


def _load_or_begin_remote_operation(run_id: str, operation_id: str, request_sha256: str) -> tuple[Path, dict[str, Any]]:
    path = _remote_operation_path(run_id, operation_id)
    if path.exists():
        saved = json.loads(path.read_text(encoding="utf-8"))
        if saved.get("operation_id") != operation_id or saved.get("request_sha256") != request_sha256:
            raise ValueError("operation_conflict")
        return path, saved
    record = {
        "schema_version": 1,
        "operation_id": operation_id,
        "request_sha256": request_sha256,
        "state": "dispatching",
    }
    atomic_json(path, record)
    return path, record


def _finish_remote_operation(path: Path, record: dict[str, Any], result_ref: str | None) -> dict[str, Any]:
    saved = dict(record)
    saved.update({"state": "completed", "result_ref": result_ref})
    atomic_json(path, saved)
    return saved


def _expected_source(context: dict[str, Any], item_id: str | None) -> tuple[str, Path, str, str]:
    if item_id is None:
        source_id = context.get("framework_source_id")
        path = context.get("framework_path")
        title = "steps.txt"
        mime = "text/plain"
    else:
        item = context.get("items", {}).get(item_id)
        source_id = item.get("source_id") if item else None
        path = item.get("input_path") if item else None
        if item and item.get("upload_title"):
            title = str(item["upload_title"])
        elif item and item.get("case_id") and item.get("input_sha256"):
            title = f"v3-pdf-{item['case_id']}-{str(item['input_sha256'])[:16]}"
        else:
            title = f"web-item-{item_id}"
        mime = "application/pdf"
    if not source_id and path:
        return "", Path(path), title, mime
    if not source_id or not path:
        raise FileNotFoundError("source_context_missing")
    return str(source_id), Path(path), title, mime


@activity.defn(name="ensure_web_notebook_activity")
async def ensure_web_notebook_activity(args: dict[str, Any]) -> dict[str, Any]:
    return await _run_with_activity_heartbeat(_ensure_web_notebook_activity_impl, args)


async def _ensure_web_notebook_activity_impl(args: dict[str, Any]) -> dict[str, Any]:
    """Create one NotebookLM notebook with a persistent send fence and private receipt."""
    run_id = str(args.get("run_id", ""))
    if not is_valid_uuid_ref(run_id):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        context = _read_context(run_id)
        if context.get("notebook_id"):
            return sanitize_activity_result({"outcome": "completed", "safe_code": "ok"})
        title = f"V3 Web Run {run_id}"
        op_id = f"web-notebook-create:{run_id}"
        digest = hashlib.sha256(title.encode("utf-8")).hexdigest()
        path, record = _load_or_begin_remote_operation(run_id, op_id, digest)
        service = get_terminal_service()
        credentials = _credential_path(run_id)
        if record.get("state") == "completed":
            notebook_id = record.get("result_ref")
        else:
            if record.get("state") == "dispatching" and path.exists() and record.get("attempted_at"):
                reconciled = await service.reconcile("create", title=title, storage_path=credentials)
                if reconciled.get("outcome") != "complete":
                    return sanitize_activity_result({"outcome": "uncertain", "safe_code": "delivery_uncertain"})
                notebook_id = reconciled.get("notebook_id")
            else:
                record["attempted_at"] = True
                atomic_json(path, record)
                created = await service.create_notebook(title, storage_path=credentials)
                notebook_id = created.id
            if not notebook_id:
                return sanitize_activity_result({"outcome": "uncertain", "safe_code": "delivery_uncertain"})
            _finish_remote_operation(path, record, str(notebook_id))
        context["notebook_id"] = str(notebook_id)
        _save_context(run_id, context)
        await get_application_client().record_run_notebook(run_id, str(notebook_id))
        return sanitize_activity_result({"outcome": "completed", "safe_code": "ok"})
    except AuthenticationRequiredError:
        return sanitize_activity_result({"outcome": "blocked", "safe_code": "authentication_required"})
    except ProviderError as exc:
        logger.warning("Web NotebookLM operation create_notebook failed (%s)", exc.code)
        safe_code = exc.code if exc.code in {"provider_rate_limit", "provider_unavailable"} else "provider_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": safe_code})
    except Exception:
        logger.exception("Web notebook creation failed")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "unknown"})


@activity.defn(name="upload_web_source_activity")
async def upload_web_source_activity(args: dict[str, Any]) -> dict[str, Any]:
    return await _run_with_activity_heartbeat(_upload_web_source_activity_impl, args)


async def _upload_web_source_activity_impl(args: dict[str, Any]) -> dict[str, Any]:
    """Upload one already verified PDF or the approved framework; provider IDs remain private."""
    run_id = str(args.get("run_id", ""))
    item_id = args.get("item_id")
    if not is_valid_uuid_ref(run_id) or (item_id is not None and not is_valid_uuid_ref(str(item_id))):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        context = _read_context(run_id)
        notebook_id = context.get("notebook_id")
        if not notebook_id:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
        source_id, local_path, title, mime = _expected_source(context, str(item_id) if item_id else None)
        if source_id:
            return sanitize_activity_result({"outcome": "completed", "safe_code": "ok"})
        if not local_path.is_file() or local_path.is_symlink():
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
        body_sha = sha256_file(local_path)
        op_label = f"web-source-upload:{run_id}:{item_id or context['framework_version_id']}"
        request_sha = hashlib.sha256(json.dumps({
            "notebook_id": notebook_id, "title": title, "mime_type": mime, "sha256": body_sha,
        }, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        path, record = _load_or_begin_remote_operation(run_id, op_label, request_sha)
        service = get_terminal_service()
        credentials = _credential_path(run_id)
        if record.get("state") == "completed":
            source_id = record.get("result_ref")
        elif record.get("attempted_at"):
            reconciled = await service.reconcile(
                "upload", notebook_id=notebook_id, title=title, storage_path=credentials,
            )
            if reconciled.get("outcome") != "complete":
                return sanitize_activity_result({"outcome": "uncertain", "safe_code": "delivery_uncertain"})
            source_id = reconciled.get("source_id")
            _finish_remote_operation(path, record, str(source_id) if source_id else None)
        else:
            record["attempted_at"] = True
            atomic_json(path, record)
            uploaded = await service.upload_source(
                notebook_id=notebook_id,
                local_path=local_path,
                mime_type=mime,
                title=title,
                storage_path=credentials,
            )
            source_id = uploaded.source_id
            _finish_remote_operation(path, record, str(source_id))
        if not source_id:
            return sanitize_activity_result({"outcome": "uncertain", "safe_code": "delivery_uncertain"})
        if item_id:
            context["items"][str(item_id)]["source_id"] = str(source_id)
            source_receipt = await get_application_client().record_run_item_source(
                run_id,
                str(item_id),
                str(source_id),
                str(context["items"][str(item_id)]["input_sha256"]),
            )
            if source_receipt.get("outcome") != "completed":
                return sanitize_activity_result({"outcome": "failed", "safe_code": source_receipt.get("safe_code", "source_error")})
        else:
            context["framework_source_id"] = str(source_id)
            source_receipt = await get_application_client().record_run_notebook(
                run_id,
                str(notebook_id),
                framework_source_id=str(source_id),
                framework_sha256=str(context["framework_sha256"]),
            )
            if source_receipt.get("outcome") != "completed":
                return sanitize_activity_result({"outcome": "failed", "safe_code": source_receipt.get("safe_code", "source_error")})
        _save_context(run_id, context)
        return sanitize_activity_result({"outcome": "completed", "safe_code": "ok"})
    except AuthenticationRequiredError:
        return sanitize_activity_result({"outcome": "blocked", "safe_code": "authentication_required"})
    except ProviderError as exc:
        logger.warning("Web NotebookLM operation upload_source failed (%s)", exc.code)
        safe_code = {
            "source_upload_invalid": "pdf_invalid",
            "source_processing_failed": "source_error",
        }.get(exc.code, exc.code if exc.code in {"provider_rate_limit", "provider_unavailable"} else "provider_unavailable")
        return sanitize_activity_result({"outcome": "failed", "safe_code": safe_code})
    except Exception:
        logger.exception("Web source upload failed")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "unknown"})


@activity.defn(name="configure_web_notebook_activity")
async def configure_web_notebook_activity(args: dict[str, Any]) -> dict[str, Any]:
    return await _run_with_activity_heartbeat(_configure_web_notebook_activity_impl, args)


async def _configure_web_notebook_activity_impl(args: dict[str, Any]) -> dict[str, Any]:
    """Apply the working pipeline response format exactly once, reconciling lost acknowledgments."""
    run_id = str(args.get("run_id", ""))
    if not is_valid_uuid_ref(run_id):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        context = _read_context(run_id)
        notebook_id = context.get("notebook_id")
        if not notebook_id:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
        digest = hashlib.sha256(COMBINED_RESPONSE_FORMAT_TEXT.encode("utf-8")).hexdigest()
        op_id = f"web-notebook-format:{run_id}"
        path, record = _load_or_begin_remote_operation(run_id, op_id, digest)
        service = get_terminal_service()
        credentials = _credential_path(run_id)
        if record.get("state") == "completed":
            return sanitize_activity_result({"outcome": "completed", "safe_code": "ok"})
        if record.get("attempted_at"):
            reconciled = await service.reconcile(
                "format", notebook_id=notebook_id, format_text=COMBINED_RESPONSE_FORMAT_TEXT,
                storage_path=credentials,
            )
            if reconciled.get("outcome") != "complete":
                return sanitize_activity_result({"outcome": "uncertain", "safe_code": "configuration_drift"})
        else:
            record["attempted_at"] = True
            atomic_json(path, record)
            await service.configure_chat(notebook_id, COMBINED_RESPONSE_FORMAT_TEXT, storage_path=credentials)
        _finish_remote_operation(path, record, None)
        receipt = await get_application_client().record_run_notebook(
            run_id,
            str(notebook_id),
            framework_source_id=str(context.get("framework_source_id")) if context.get("framework_source_id") else None,
            framework_sha256=str(context.get("framework_sha256")) if context.get("framework_sha256") else None,
            configured=True,
        )
        if receipt.get("outcome") != "completed":
            return sanitize_activity_result({"outcome": "failed", "safe_code": receipt.get("safe_code", "configuration_drift")})
        return sanitize_activity_result({"outcome": "completed", "safe_code": "ok"})
    except AuthenticationRequiredError:
        return sanitize_activity_result({"outcome": "blocked", "safe_code": "authentication_required"})
    except ProviderError as exc:
        logger.warning("Web NotebookLM operation configure_notebook failed (%s)", exc.code)
        safe_code = exc.code if exc.code in {"provider_rate_limit", "provider_unavailable"} else "provider_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": safe_code})
    except Exception:
        logger.exception("Web notebook configuration failed")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "unknown"})


@activity.defn(name="check_web_sources_activity")
async def check_web_sources_activity(args: dict[str, Any]) -> dict[str, Any]:
    return await _run_with_activity_heartbeat(_check_web_sources_activity_impl, args)


async def _check_web_sources_activity_impl(args: dict[str, Any]) -> dict[str, Any]:
    """Read provider source status; this activity is safe to repeat."""
    run_id = str(args.get("run_id", ""))
    item_id = args.get("item_id")
    if not is_valid_uuid_ref(run_id):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        context = _read_context(run_id)
        desired = [context.get("framework_source_id")]
        if item_id:
            item = context.get("items", {}).get(str(item_id))
            desired = [item.get("source_id") if item else None, context.get("framework_source_id")]
        if not desired or any(not source_id for source_id in desired):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
        receipts = await get_terminal_service().poll_sources(
            context["notebook_id"], storage_path=_credential_path(run_id),
        )
        by_id = {receipt.source_id: receipt for receipt in receipts}
        selected = [by_id.get(source_id) for source_id in desired]
        if any(receipt is None or receipt.status == "error" for receipt in selected):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "source_error"})
        ready = sum(receipt.status == "ready" for receipt in selected if receipt is not None)
        total = len(selected)
        return sanitize_activity_result({
            "outcome": "completed" if ready == total else "pending",
            "safe_code": "ok",
            "counts": {"ready": ready, "total": total},
        })
    except AuthenticationRequiredError:
        return sanitize_activity_result({"outcome": "blocked", "safe_code": "authentication_required"})
    except ProviderError as exc:
        logger.warning("Web NotebookLM operation check_sources failed (%s)", exc.code)
        safe_code = exc.code if exc.code in {"provider_rate_limit", "provider_unavailable"} else "provider_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": safe_code})
    except Exception:
        logger.exception("Web source readiness check failed")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "unknown"})


@activity.defn(name="resolve_application_run_activity")
async def resolve_application_run_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Resolve Laravel's captured manifest and expose only bounded opaque refs to Temporal."""
    run_id = str(args.get("run_id", ""))
    manifest_ref = str(args.get("manifest_ref", ""))
    if not is_valid_uuid_ref(run_id) or not is_valid_uuid_ref(manifest_ref):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        manifest = await get_application_client().get_manifest(manifest_ref)
        items = manifest.get("items")
        prepared = manifest.get("protocol") == "notebooklm_per_pdf_web_v3"
        limit = 640 if prepared else 20
        if manifest.get("run_id") != run_id or not isinstance(items, list) or not 1 <= len(items) <= limit:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "contract_invalid"})
        if prepared:
            from v3_engine.web_pipeline.pdf_preparation import verify_web_manifest_preparation
            from v3_engine.web_pipeline.bridge import get_storage_root
            try:
                await asyncio.to_thread(verify_web_manifest_preparation, get_storage_root(), manifest)
            except Exception as exc:
                logger.warning("Captured PDF preparation verification failed (%s)", type(exc).__name__)
                return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_corrupt"})
        item_refs = []
        ordinals = set()
        for item in items:
            item_id = str(item.get("item_id", ""))
            ordinal = item.get("pdf_ordinal")
            if (not is_valid_uuid_ref(item_id) or not isinstance(ordinal, int)
                    or isinstance(ordinal, bool) or ordinal < 1 or ordinal in ordinals):
                return sanitize_activity_result({"outcome": "failed", "safe_code": "contract_invalid"})
            ordinals.add(ordinal)
            item_refs.append({"item_id": item_id, "pdf_ordinal": ordinal})
        item_refs.sort(key=lambda entry: entry["pdf_ordinal"])
        if [entry["pdf_ordinal"] for entry in item_refs] != list(range(1, len(item_refs) + 1)):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "contract_invalid"})
        framework_id = str(manifest.get("framework_version_id", ""))
        if not is_valid_uuid_ref(framework_id):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})

        context = {
            "schema_version": 1,
            "run_id": run_id,
            "manifest_ref": manifest_ref,
            "mode": manifest.get("mode", "export_manual"),
            "qualification_mode": manifest.get("qualification_mode"),
            "framework_version_id": framework_id,
            "framework_source_id": None,
            "notebook_id": None,
            "conversation_ids": {},
            "maps": {},
            "items": {str(row["item_id"]): {**row, "source_id": None, "input_path": None} for row in item_refs},
            "manifest": manifest,
        }
        if prepared:
            for entry in items:
                context["items"][entry["item_id"]].update({
                    "upload_title": entry["original_filename"], "pdf_lineage": entry["pdf_lineage"],
                })
        if _context_path(run_id).exists():
            previous = _read_context(run_id)
            if previous.get("manifest_ref") != manifest_ref or previous.get("manifest") != manifest:
                return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_corrupt"})
            if set(previous.get("items", {})) != set(context["items"]):
                return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_corrupt"})
            # Admission replay must retain committed progress and provider receipts.
            context = previous
        _save_context(run_id, context)
        return {
            "outcome": "completed",
            "safe_code": "ok",
            "counts": {"total_pdfs": len(item_refs)},
            "result_ref": manifest_ref,
            "mode": context["mode"],
            "framework_version_id": framework_id,
            "items": item_refs,
        }
    except ApplicationPipelineError as exc:
        code = exc.code if exc.code in {"artifact_missing", "contract_invalid", "worker_unauthenticated"} else "storage_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": code})
    except Exception:
        logger.exception("Application manifest resolution failed")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "storage_unavailable"})


@activity.defn(name="prepare_application_item_activity")
async def prepare_application_item_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Accept one upload in Laravel and stage its verified bytes inside the private worker root."""
    run_id = str(args.get("run_id", ""))
    item_id = str(args.get("item_id", ""))
    if not is_valid_uuid_ref(run_id) or not is_valid_uuid_ref(item_id):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        context = _read_context(run_id)
        item = context["items"].get(item_id)
        if not item:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
        client = get_application_client()
        receipt = await client.prepare_run_item(run_id, item_id)
        if receipt.get("outcome") != "completed" or receipt.get("item_id") != item_id:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "source_error"})
        input_ref = str(receipt.get("input_ref", ""))
        expected_sha = str(receipt.get("input_sha256", "")).lower()
        expected_bytes = receipt.get("input_bytes")
        if (not is_valid_uuid_ref(input_ref) or len(expected_sha) != 64
                or not isinstance(expected_bytes, int) or expected_bytes < 1):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "receipt_invalid"})
        content = await client.get_accepted_input(item_id)
        if len(content) != expected_bytes or hashlib.sha256(content).hexdigest() != expected_sha:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_corrupt"})
        target = _store_private_bytes(run_id, item_id, content)
        item.update({
            "input_ref": input_ref,
            "input_sha256": expected_sha,
            "input_bytes": expected_bytes,
            "page_count": int(receipt.get("page_count", 0)),
            "case_id": receipt.get("case_id"),
            "binding_ref": receipt.get("binding_ref"),
            "input_path": str(target),
        })
        _save_context(run_id, context)
        return sanitize_activity_result({
            "outcome": "completed",
            "safe_code": "ok",
            "counts": {"input_bytes": expected_bytes, "page_count": item["page_count"]},
            "result_ref": input_ref,
        })
    except AuthenticationRequiredError:
        return sanitize_activity_result({"outcome": "blocked", "safe_code": "authentication_required"})
    except ApplicationPipelineError as exc:
        print(f"\n[APP-PIPELINE-ERROR in prepare]: {exc.status_code} {exc.code} {exc.message}\n")
        code = exc.code if exc.code in {"artifact_missing", "artifact_corrupt", "contract_invalid"} else "storage_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": code})
    except Exception as exc:
        print(f"\n[EXCEPTION in prepare]: {type(exc)} {exc}\n")
        logger.exception("Web input preparation failed")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "storage_unavailable"})


@activity.defn(name="prepare_application_framework_activity")
async def prepare_application_framework_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Fetch and retain the exact approved framework bytes for the captured run."""
    run_id = str(args.get("run_id", ""))
    if not is_valid_uuid_ref(run_id):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        context = _read_context(run_id)
        framework_id = context["framework_version_id"]
        content = await get_application_client().get_framework_body(framework_id)
        if not content or len(content) > 8 * 1024 * 1024:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_corrupt"})
        target = _store_private_bytes(run_id, framework_id, content)
        context["framework_path"] = str(target)
        context["framework_sha256"] = hashlib.sha256(content).hexdigest()
        context["framework_bytes"] = len(content)
        _save_context(run_id, context)
        return sanitize_activity_result({
            "outcome": "completed",
            "safe_code": "ok",
            "counts": {"framework_bytes": len(content)},
            "result_ref": framework_id,
        })
    except ApplicationPipelineError as exc:
        code = exc.code if exc.code in {"artifact_missing", "artifact_corrupt", "contract_invalid"} else "storage_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": code})
    except Exception:
        logger.exception("Framework preparation failed")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "storage_unavailable"})


@activity.defn(name="execute_divide_turn")
async def execute_divide_turn(args: dict[str, Any]) -> dict[str, Any]:
    return await _run_with_activity_heartbeat(_execute_divide_turn_impl, args)


async def _execute_divide_turn_impl(args: dict[str, Any]) -> dict[str, Any]:
    """Run and commit the canonical PDF-only Divide operation for one web item."""
    run_id = str(args.get("run_id", ""))
    item_id = str(args.get("item_id", ""))
    if not is_valid_uuid_ref(run_id) or not is_valid_uuid_ref(item_id):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        context = _read_context(run_id)
        item = context.get("items", {}).get(item_id)
        if not item:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
        if not item.get("case_id") or not item.get("binding_ref") or not item.get("source_id"):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "source_error"})
        if int(args.get("pdf_ordinal", item.get("pdf_ordinal", 0))) != int(item.get("pdf_ordinal", 0)):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "contract_invalid"})
        if item.get("divide_map_ref") and item.get("work_count"):
            return sanitize_activity_result({
                "outcome": "completed", "safe_code": "ok", "result_ref": item["divide_map_ref"],
                "counts": {"work_count": int(item["work_count"])},
            })

        result = await _lesson_protocol(run_id).divide(
            case_id=str(item["case_id"]),
            binding_ref=str(item["binding_ref"]),
            notebook_id=str(context["notebook_id"]),
            pdf_source_id=str(item["source_id"]),
        )
        if result.get("status") != "recorded" or not is_valid_uuid_ref(str(result.get("map_ref", ""))):
            return sanitize_activity_result({"outcome": "uncertain", "safe_code": "delivery_uncertain"})
        item["divide_map_ref"] = result["map_ref"]
        item["divide_conversation_id"] = result["conversation_id"]
        item["work_count"] = int(result["work_count"])
        item["completed_work_ordinals"] = []
        _save_context(run_id, context)
        return sanitize_activity_result({
            "outcome": "completed", "safe_code": "ok", "result_ref": result["map_ref"],
            "counts": {"work_count": int(result["work_count"])},
        })
    except AuthenticationRequiredError:
        return sanitize_activity_result({"outcome": "blocked", "safe_code": "authentication_required"})
    except ProviderError as e:
        safe_code = getattr(e, "code", "provider_unavailable")
        if safe_code == "protocol_validation":
            safe_code = "map_unreadable"
        elif safe_code not in ("provider_rate_limit", "provider_unavailable"):
            safe_code = "provider_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": safe_code})
    except (ApplicationPipelineError, LaravelClientError) as exc:
        code = "protocol_validation" if exc.code in {"protocol_validation", "lesson_invalid"} else (exc.code if exc.code in {"artifact_missing", "contract_invalid", "lesson_conflict"} else "storage_unavailable")
        return sanitize_activity_result({"outcome": "failed", "safe_code": code})
    except FileNotFoundError:
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    except Exception:
        logger.exception("Divide turn failed for a web item")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "unknown"})


@activity.defn(name="execute_lesson_turns")
async def execute_lesson_turns(args: dict[str, Any]) -> dict[str, Any]:
    """Keep long NotebookLM work alive and retry safely after worker loss."""
    return await _run_with_activity_heartbeat(_execute_lesson_turns_impl, args)


async def _execute_lesson_turns_impl(args: dict[str, Any]) -> dict[str, Any]:
    """Run Execution, Start and literal Continue turns; Laravel commits each bundle."""
    run_id = str(args.get("run_id", ""))
    item_id = str(args.get("item_id", ""))
    if not is_valid_uuid_ref(run_id) or not is_valid_uuid_ref(item_id):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        context = _read_context(run_id)
        item = context.get("items", {}).get(item_id)
        work_count = int(item.get("work_count", 0)) if item else 0
        conversation_id = item.get("divide_conversation_id") if item else None
        if (not item or work_count < 1 or not conversation_id or not item.get("case_id")
                or not item.get("binding_ref") or not item.get("source_id")
                or not context.get("framework_source_id")):
            return sanitize_activity_result({"outcome": "failed", "safe_code": "context_incomplete"})

        completed = set(int(value) for value in item.get("completed_work_ordinals", []))
        if len(completed) == work_count:
            return sanitize_activity_result({
                "outcome": "completed", "safe_code": "ok",
                "counts": {"lessons_saved": work_count, "prompts_saved": int(item.get("saved_prompt_count", 0))},
            })

        protocol = _lesson_protocol(run_id)
        cursor_version = item.get("cursor_version")
        if not isinstance(cursor_version, int):
            execution = await protocol.execution(
                case_id=str(item["case_id"]), binding_ref=str(item["binding_ref"]),
                notebook_id=str(context["notebook_id"]), pdf_source_id=str(item["source_id"]),
                steps_source_id=str(context["framework_source_id"]), conversation_id=str(conversation_id),
                expected_cursor_version=1,
            )
            if execution.get("status") != "acknowledged" or not isinstance(execution.get("cursor_version"), int):
                return sanitize_activity_result({"outcome": "uncertain", "safe_code": "delivery_uncertain"})
            cursor_version = int(execution["cursor_version"])
            item["cursor_version"] = cursor_version
            _save_context(run_id, context)
        prompts_saved = int(item.get("saved_prompt_count", 0))
        for lesson_ordinal in range(1, work_count + 1):
            if lesson_ordinal in completed:
                continue
            result = await protocol.process_lesson(
                case_id=str(item["case_id"]), binding_ref=str(item["binding_ref"]),
                notebook_id=str(context["notebook_id"]), pdf_source_id=str(item["source_id"]),
                steps_source_id=str(context["framework_source_id"]), conversation_id=str(conversation_id),
                work_ordinal=lesson_ordinal, expected_cursor_version=cursor_version,
            )
            if result.get("status") != "completed":
                return sanitize_activity_result({"outcome": "uncertain", "safe_code": "delivery_uncertain"})
            prompt_refs = result.get("original_prompt_refs")
            if not isinstance(prompt_refs, list) or not prompt_refs:
                return sanitize_activity_result({"outcome": "failed", "safe_code": "context_incomplete"})
            prompts_saved += len(prompt_refs)
            cursor_version = int(result.get("cursor_version", 0))
            if cursor_version <= 0:
                return sanitize_activity_result({"outcome": "failed", "safe_code": "receipt_invalid"})
            completed.add(lesson_ordinal)
            item["completed_work_ordinals"] = sorted(completed)
            item["saved_prompt_count"] = prompts_saved
            item["cursor_version"] = cursor_version
            _save_context(run_id, context)

        return sanitize_activity_result({
            "outcome": "completed", "safe_code": "ok",
            "counts": {"lessons_saved": len(completed), "prompts_saved": prompts_saved},
        })
    except AuthenticationRequiredError:
        return sanitize_activity_result({"outcome": "blocked", "safe_code": "authentication_required"})
    except ProviderError as e:
        safe_code = getattr(e, "code", "provider_unavailable")
        if safe_code not in ("provider_rate_limit", "provider_unavailable"):
            safe_code = "provider_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": safe_code})
    except (ApplicationPipelineError, LaravelClientError) as exc:
        code = exc.code if exc.code in {"artifact_missing", "contract_invalid", "protocol_validation", "lesson_conflict"} else "storage_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": code})
    except FileNotFoundError:
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    except Exception:
        logger.exception("Lesson turns failed for a web item")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "unknown"})


@activity.defn(name="reset_conversation_activity")
async def reset_conversation_activity(args: dict[str, Any]) -> dict[str, Any]:
    return await _run_with_activity_heartbeat(_reset_conversation_activity_impl, args)


async def _reset_conversation_activity_impl(args: dict[str, Any]) -> dict[str, Any]:
    """Persist a PDF archive receipt, reset NotebookLM, and verify empty history."""
    run_id = str(args.get("run_id", ""))
    ordinal = int(args.get("pdf_ordinal", 1))
    if not is_valid_uuid_ref(run_id):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        context = _read_context(run_id)
        item = next((v for v in context.get("items", {}).values() if int(v.get("pdf_ordinal", 0)) == ordinal), None)
        if not item:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
        conv_id = item.get("divide_conversation_id")
        notebook_id = context.get("notebook_id")
        if not conv_id or not notebook_id:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "reset_uncertain"})
        if item.get("work_count") and len(item.get("completed_work_ordinals", [])) != int(item["work_count"]):
            # A map-only failure may be safely archived after its map query
            # settles, but an incomplete lesson sequence is never called saved.
            if item.get("saved_prompt_count") or item.get("completed_work_ordinals"):
                return sanitize_activity_result({"outcome": "failed", "safe_code": "context_incomplete"})

        archive_dir = _web_private_dir(run_id) / "archives"
        archive_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        archive_path = archive_dir / f"item-{ordinal:03d}.json"
        archive = {
            "schema_version": 1,
            "run_id": run_id,
            "item_id": item["item_id"],
            "pdf_ordinal": ordinal,
            "case_id": item.get("case_id"),
            "map_ref": item.get("divide_map_ref"),
            "work_count": int(item.get("work_count", 0)),
            "lessons_saved": len(item.get("completed_work_ordinals", [])),
            "prompts_saved": int(item.get("saved_prompt_count", 0)),
            "source_sha256": item.get("input_sha256"),
            "conversation_sha256": hashlib.sha256(str(conv_id).encode()).hexdigest(),
            **({"pdf_lineage": item["pdf_lineage"]} if item.get("pdf_lineage") else {}),
        }
        atomic_json(archive_path, archive)
        archive_digest = hashlib.sha256(archive_path.read_bytes()).hexdigest()
        if item.get("reset_verified"):
            if item.get("archive_ref") != archive_digest or not is_valid_uuid_ref(str(item.get("application_archive_ref", ""))):
                return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_corrupt"})
            return sanitize_activity_result({"outcome": "completed", "safe_code": "ok",
                "result_ref": item.get("divide_map_ref"),
                "counts": {"lessons_saved": archive["lessons_saved"], "prompts_saved": archive["prompts_saved"]}})
        if len(item.get("completed_work_ordinals", [])) == int(item.get("work_count", 0)) and int(item.get("work_count", 0)) > 0:
            saved = await get_application_client().record_run_item_archive(str(item["item_id"]), {
                "run_id": run_id,
                "work_count": int(item["work_count"]),
                "lessons_saved": len(item.get("completed_work_ordinals", [])),
                "prompts_saved": int(item.get("saved_prompt_count", 0)),
                "source_sha256": str(item["input_sha256"]),
                "archive_sha256": archive_digest,
            })
            if saved.get("outcome") != "completed" or not is_valid_uuid_ref(str(saved.get("archive_ref", ""))):
                return sanitize_activity_result({"outcome": "failed", "safe_code": saved.get("safe_code", "receipt_invalid")})
            item["application_archive_ref"] = saved["archive_ref"]
        service = get_terminal_service()
        credentials = _credential_path(run_id)
        await service.reset_conversation(notebook_id=str(notebook_id), conversation_id=str(conv_id), storage_path=credentials)
        history = await service.get_history(notebook_id=str(notebook_id), conversation_id=str(conv_id), storage_path=credentials)
        if history:
            return sanitize_activity_result({"outcome": "failed", "safe_code": "reset_uncertain"})
        if item.get("application_archive_ref"):
            reset_receipt = await get_application_client().mark_run_item_reset_verified(str(item["item_id"]), {
                "run_id": run_id,
                "archive_ref": str(item["application_archive_ref"]),
                "reset_history_sha256": hashlib.sha256(json.dumps(history, separators=(",", ":")).encode()).hexdigest(),
            })
            if reset_receipt.get("outcome") != "completed":
                return sanitize_activity_result({"outcome": "failed", "safe_code": reset_receipt.get("safe_code", "reset_uncertain")})
        item["archive_ref"] = archive_digest
        item["reset_verified"] = True
        _save_context(run_id, context)
        return sanitize_activity_result({
            "outcome": "completed", "safe_code": "ok",
            "result_ref": item.get("divide_map_ref"),
            "counts": {"lessons_saved": archive["lessons_saved"], "prompts_saved": archive["prompts_saved"]},
        })
    except AuthenticationRequiredError:
        return sanitize_activity_result({"outcome": "blocked", "safe_code": "authentication_required"})
    except ProviderError as exc:
        code = exc.code if exc.code in {"provider_rate_limit", "provider_unavailable"} else "reset_uncertain"
        return sanitize_activity_result({"outcome": "failed", "safe_code": code})
    except Exception:
        logger.exception("NotebookLM conversation reset was not verified")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "reset_uncertain"})


@activity.defn(name="export_prompts_activity")
async def export_prompts_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Export the exact prompt rows currently committed in Laravel."""
    run_id = str(args.get("run_id", ""))
    if not is_valid_uuid_ref(run_id):
        return sanitize_activity_result({"outcome": "failed", "safe_code": "artifact_missing"})
    try:
        result = await get_application_client().export_run_prompts(run_id)
        artifact_ref = result.get("artifact_ref")
        counts = result.get("counts") or {}
        prompt_count = counts.get("prompts_saved")
        if (result.get("outcome") != "completed" or not is_valid_uuid_ref(str(artifact_ref or ""))
                or not isinstance(prompt_count, int) or prompt_count < 1):
            return sanitize_activity_result({"outcome": "failed", "safe_code": result.get("safe_code", "context_incomplete")})
        return sanitize_activity_result({
            "outcome": "completed", "safe_code": "ok", "result_ref": artifact_ref,
            "counts": {"prompts_saved": prompt_count},
        })
    except ApplicationPipelineError as exc:
        code = exc.code if exc.code in {"artifact_missing", "context_incomplete", "contract_invalid"} else "storage_unavailable"
        return sanitize_activity_result({"outcome": "failed", "safe_code": code})
    except Exception:
        logger.exception("Persisted prompt export failed")
        return sanitize_activity_result({"outcome": "failed", "safe_code": "storage_unavailable"})
