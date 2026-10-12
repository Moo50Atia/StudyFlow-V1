"""FastAPI router for Web Pipeline application bridge endpoints (T025, T074, Spec 019)."""

from __future__ import annotations

import json
import os
import re
from pathlib import Path
from typing import Any
from fastapi import APIRouter, FastAPI, Request, status
from fastapi.responses import JSONResponse

from v3_engine.contracts import verify_hmac_signature
from v3_engine.settings import get_settings
from v3_engine.terminal_pipeline.settings import get_terminal_settings
from v3_engine.web_pipeline.artifact_refs import (
    ArtifactError,
    ArtifactSecurityError,
    ArtifactSizeError,
    is_valid_uuid_ref,
    load_manifest,
)
from v3_engine.web_pipeline.contracts import (
    ContractValidationError,
    validate_admission_payload,
)

router = APIRouter(prefix="/internal/v1/application-pipeline")
app = FastAPI(title="V3 Engine Web Pipeline Bridge")


@app.get("/health")
async def health_check():
    return {"status": "ok", "service": "v3_bridge"}


app.include_router(router)

# In-memory durable store for bridge receipts and submissions in test / offline mode
_submissions_store: dict[str, dict[str, Any]] = {}
_commands_store: dict[str, dict[str, Any]] = {}
_runs_status: dict[str, dict[str, Any]] = {}


def get_storage_root() -> Path:
    custom = os.getenv("V3_STORAGE_ROOT") or os.getenv("V3_PRIVATE_ROOT")
    if custom:
        return Path(custom).resolve()
    p1 = Path.cwd() / "storage/app/v3"
    if p1.exists():
        return p1.resolve()
    p2 = Path.cwd() / "storage/app/private/v3"
    if p2.exists():
        return p2.resolve()
    return (Path.cwd() / "storage/app/v3").resolve()


async def _get_temporal_client():
    try:
        from temporalio.client import Client
        terminal_settings = get_terminal_settings()
        client = await Client.connect(
            terminal_settings.temporal_address,
            namespace=terminal_settings.temporal_namespace,
        )
        return client
    except Exception:
        return None


async def _verify_auth(request: Request, raw_body: bytes) -> JSONResponse | None:
    settings = get_settings()
    ts_header = request.headers.get("X-V3-Timestamp")
    sig_header = request.headers.get("X-V3-Signature")
    contract_ver = request.headers.get("X-V3-Contract-Version")

    if contract_ver != "1":
        return JSONResponse(
            status_code=status.HTTP_401_UNAUTHORIZED,
            content={"error": {"code": "worker_unauthenticated", "message": "Invalid contract version"}},
        )

    if not settings.bridge_hmac_key or not verify_hmac_signature(
        request.method,
        request.url.path,
        ts_header,
        sig_header,
        raw_body,
        settings.bridge_hmac_key,
    ):
        return JSONResponse(
            status_code=status.HTTP_401_UNAUTHORIZED,
            content={"error": {"code": "worker_unauthenticated", "message": "Authentication failed"}},
        )
    return None


@router.post("/submissions")
async def submit_admission_endpoint(request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await _verify_auth(request, raw_body)
    if auth_err:
        return auth_err

    if len(raw_body) > 32768:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Encoded payload exceeds 32 KiB ceiling"}},
        )

    try:
        body_json = json.loads(raw_body.decode("utf-8"))
        validate_admission_payload(body_json)
    except ContractValidationError as e:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": str(e)}},
        )
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": str(e)}},
        )

    sub_id = body_json["submission_id"]
    sub_digest = body_json["submission_digest"]
    run_id = body_json["run_id"]
    manifest_ref = body_json["manifest_ref"]

    # Privately resolve manifest from disk
    storage_root = get_storage_root()
    try:
        manifest_data = load_manifest(storage_root, manifest_ref)
    except FileNotFoundError:
        return JSONResponse(
            status_code=status.HTTP_404_NOT_FOUND,
            content={"error": {"code": "artifact_missing", "message": f"Manifest {manifest_ref} not found"}},
        )
    except (ArtifactError, ArtifactSecurityError, ArtifactSizeError) as e:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": str(e)}},
        )

    # Temporal history and activity receipts are the authority for admission.
    # Do not acknowledge a process-local cache entry when Temporal is absent.
    client = await _get_temporal_client()
    if client is None:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "storage_unavailable", "message": "The shared workflow service is unavailable"}},
        )

    from temporalio.client import WithStartWorkflowOperation, WorkflowUpdateStage
    from temporalio.common import WorkflowIDConflictPolicy
    from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow
    terminal_settings = get_terminal_settings()

    # Only opaque references and safe workflow enums enter Temporal history.
    # Workers resolve the immutable manifest privately after the child starts.
    subject = {
        "subject_run_id": body_json.get("subject_id", run_id),
        "subject_key": body_json.get("subject_id", run_id),
        "run_id": run_id,
        "run_ref": f"runs/{run_id}",
        "manifest_ref": manifest_ref,
        "workflow_kind": body_json["workflow_kind"],
    }
    update_payload = {
        "submission_id": sub_id,
        "submission_digest": sub_digest,
        "manifest_ref": manifest_ref,
        "workflow_kind": body_json["workflow_kind"],
        "subjects": [subject],
    }
    try:
        update = await client.start_update_with_start_workflow(
            TerminalSupervisorWorkflow.submit_v1,
            update_payload,
            start_workflow_operation=WithStartWorkflowOperation(
                TerminalSupervisorWorkflow.run,
                {},
                id="v3-terminal-supervisor",
                task_queue=terminal_settings.control_queue,
                id_conflict_policy=WorkflowIDConflictPolicy.USE_EXISTING,
            ),
            id=f"submit-{sub_id}",
            wait_for_stage=WorkflowUpdateStage.ACCEPTED,
        )
        receipt = await update.result()
    except Exception:
        # The update may have been accepted before a transport failure. The
        # stable update ID makes a retry/reconciliation safe; never invent 202.
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "delivery_uncertain", "message": "Admission acknowledgment is uncertain; retry with the same submission ID"}},
        )

    if not isinstance(receipt, dict) or receipt.get("outcome") != "accepted":
        safe_code = receipt.get("safe_code", "operation_conflict") if isinstance(receipt, dict) else "operation_conflict"
        status_code = status.HTTP_409_CONFLICT if safe_code in ("queue_full", "control_conflict") else status.HTTP_422_UNPROCESSABLE_ENTITY
        return JSONResponse(
            status_code=status_code,
            content={"error": {"code": safe_code, "message": "The shared workflow supervisor rejected admission"}},
        )

    return JSONResponse(status_code=status.HTTP_202_ACCEPTED, content=receipt)


@router.post("/commands")
async def send_command_endpoint(request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await _verify_auth(request, raw_body)
    if auth_err:
        return auth_err

    if len(raw_body) > 32768:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Command payload exceeds 32 KiB ceiling"}},
        )

    try:
        body_json = json.loads(raw_body.decode("utf-8"))
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": f"Invalid JSON: {e}"}},
        )

    allowed_cmd_keys = {"command_id", "run_id", "action", "expected_version", "command_digest"}
    unknown_keys = set(body_json.keys()) - allowed_cmd_keys
    if unknown_keys:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": f"Unknown keys in command: {unknown_keys}"}},
        )

    cmd_id = body_json.get("command_id", "")
    run_id = body_json.get("run_id", "")
    action = body_json.get("action", "")
    cmd_digest = body_json.get("command_digest", "")
    expected_version = body_json.get("expected_version")

    if not is_valid_uuid_ref(str(cmd_id)) or not is_valid_uuid_ref(str(run_id)):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Invalid UUID format for command_id or run_id"}},
        )

    if (not isinstance(expected_version, int) or isinstance(expected_version, bool)
            or expected_version < 1 or not isinstance(cmd_digest, str)
            or re.fullmatch(r"[0-9a-f]{64}", cmd_digest) is None):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "expected_version and command_digest are invalid"}},
        )

    allowed_actions = {"pause", "resume", "cancel", "cancel_queued"}
    if action not in allowed_actions:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": f"Unsupported action: {action}"}},
        )

    # Check duplicate command
    if cmd_id in _commands_store:
        existing = _commands_store[cmd_id]
        if existing["digest"] == cmd_digest:
            return JSONResponse(
                status_code=status.HTTP_200_OK,
                content=existing["receipt"],
            )
        return JSONResponse(
            status_code=status.HTTP_409_CONFLICT,
            content={"error": {"code": "operation_conflict", "message": "Differing command digest"}},
        )

    # Do not acknowledge a control until its Temporal update is accepted.
    client = await _get_temporal_client()
    if client is None:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "storage_unavailable", "message": "The shared workflow service is unavailable; retry with the same command id"}},
        )

    supervisor = client.get_workflow_handle("v3-terminal-supervisor")
    try:
        admission = await supervisor.query("get_subject_admission_v1", run_id)
        if action == "cancel_queued" or (action == "cancel" and admission == "queued"):
            result = await supervisor.execute_update(
                "cancel_queued_v1",
                {"subject_run_id": run_id, "command_id": cmd_id},
                id=f"cancel-{cmd_id}",
            )
        elif action in ("pause", "resume", "cancel") and admission == "active":
            child = client.get_workflow_handle(f"v3-web-subject-{run_id}")
            result = await child.execute_update(
                "control_v1",
                {"command_id": cmd_id, "action": action, "command_digest": cmd_digest},
                id=f"control-{cmd_id}",
            )
        else:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "control_conflict", "message": "Run is not in a state that accepts this control"}},
            )
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "delivery_uncertain", "message": "Control delivery is uncertain; retry with the same command id"}},
        )

    if not isinstance(result, dict) or result.get("outcome") != "applied":
        return JSONResponse(
            status_code=status.HTTP_409_CONFLICT,
            content={"error": {"code": "control_conflict", "message": "Workflow rejected the control"}},
        )

    receipt = {"outcome": "applied", "command_id": cmd_id, "run_id": run_id, "action": action, "applied": True}
    _commands_store[cmd_id] = {
        "command_id": cmd_id,
        "run_id": run_id,
        "digest": cmd_digest,
        "version": expected_version,
        "consumed": True,
        "receipt": receipt,
    }

    return JSONResponse(
        status_code=status.HTTP_202_ACCEPTED,
        content=receipt,
    )


@router.get("/receipts/{command_id}")
async def get_receipt_endpoint(command_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await _verify_auth(request, raw_body)
    if auth_err:
        return auth_err

    record = _commands_store.get(command_id)
    if not record:
        return JSONResponse(
            status_code=status.HTTP_404_NOT_FOUND,
            content={"error": {"code": "receipt_not_found", "message": f"Command {command_id} not found"}},
        )

    return JSONResponse(
        status_code=status.HTTP_200_OK,
        content={
            "command_id": command_id,
            "consumed": record.get("consumed", True),
            "digest": record.get("digest"),
            "version": record.get("version", 1),
        },
    )


@router.get("/runs/{run_id}/status")
async def get_run_status_endpoint(run_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await _verify_auth(request, raw_body)
    if auth_err:
        return auth_err

    current_status = "unknown"
    progress: dict[str, Any] | None = None
    client = await _get_temporal_client()
    if client:
        try:
            child = client.get_workflow_handle(f"v3-web-subject-{run_id}")
            candidate = await child.query("progress_v1")
            if isinstance(candidate, dict) and isinstance(candidate.get("status"), str):
                progress = candidate
                current_status = candidate["status"]
        except Exception:
            pass
        try:
            handle = client.get_workflow_handle("v3-terminal-supervisor")
            admission_state = await handle.query("get_subject_admission_v1", run_id)
            if current_status == "unknown" and admission_state != "unknown":
                current_status = {
                    "active": "running",
                    "queued": "queued",
                    "completed": "completed",
                    "cancelled_before_start": "cancelled",
                }.get(admission_state, admission_state)
        except Exception:
            pass

    if current_status == "unknown":
        status_rec = _runs_status.get(run_id)
        if status_rec:
            current_status = status_rec.get("status", "unknown")

    payload = {"run_id": run_id, "status": current_status}
    if progress is not None:
        payload["progress"] = progress
    return JSONResponse(status_code=status.HTTP_200_OK, content=payload)
