import base64
import json
from typing import Any, Optional
from fastapi import FastAPI, Header, HTTPException, Request, Response, status
from fastapi.responses import JSONResponse
from temporalio.client import (
    Client,
    WorkflowExecutionStatus,
    WorkflowFailureError,
)
from temporalio.common import WorkflowIDReusePolicy
from temporalio.exceptions import WorkflowAlreadyStartedError

from v3_engine.contracts import (
    WorkflowDescribeResponse,
    WorkflowDescribeResponseData,
    WorkflowStartRequest,
    WorkflowStartResponse,
    WorkflowStartResponseData,
    verify_hmac_signature,
)
from v3_engine.client import get_temporal_client
from v3_engine.review.preview_gateway import PreviewGateway
from v3_engine.settings import get_settings
from v3_engine.workflows.case_workflow import CaseWorkflow
from v3_engine.web_pipeline.artifact_refs import is_valid_uuid_ref

app = FastAPI(title="V3 Engine Bridge")


@app.get("/health")
async def health_check():
    return {"status": "ok", "service": "v3_bridge"}

def status_to_str(st: Optional[WorkflowExecutionStatus]) -> str:
    if st == WorkflowExecutionStatus.RUNNING:
        return "open"
    elif st == WorkflowExecutionStatus.COMPLETED:
        return "completed"
    elif st == WorkflowExecutionStatus.FAILED:
        return "failed"
    elif st == WorkflowExecutionStatus.CANCELED:
        return "cancelled"
    elif st == WorkflowExecutionStatus.TERMINATED:
        return "cancelled"
    elif st == WorkflowExecutionStatus.TIMED_OUT:
        return "failed"
    return "open"

async def verify_request_signature(request: Request, raw_body: bytes) -> Optional[JSONResponse]:
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

@app.post("/internal/v1/workflows/start", response_model=WorkflowStartResponse)
async def start_workflow_endpoint(request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        body_json = json.loads(raw_body.decode("utf-8"))
        req = WorkflowStartRequest.model_validate(body_json)
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": str(e)}},
        )

    expected_workflow_id = f"v3-case-{req.case_id}"
    if req.workflow_id != expected_workflow_id:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Mismatched workflow_id"}},
        )

    settings = get_settings()
    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": "Temporal connection failed"}},
        )

    memo = {
        "case_id": req.case_id,
        "settings_version_id": req.settings_version_id,
        "settings_checksum": req.settings_checksum,
        "schema_version": 1,
    }

    try:
        handle = await client.start_workflow(
            CaseWorkflow.run,
            req.model_dump(),
            id=req.workflow_id,
            task_queue=settings.task_queue,
            id_reuse_policy=WorkflowIDReusePolicy.REJECT_DUPLICATE,
            memo=memo,
        )
        return WorkflowStartResponse(
            data=WorkflowStartResponseData(
                workflow_id=req.workflow_id,
                run_id=handle.result_run_id or "",
                temporal_status="open",
            )
        )
    except WorkflowAlreadyStartedError:
        # Existing workflow with same ID. Describe and compare memo
        try:
            handle = client.get_workflow_handle(req.workflow_id)
            desc = await handle.describe()
            existing_memo = await desc.memo() or {}

            # Verify memo matches
            if (
                existing_memo.get("case_id") != req.case_id
                or existing_memo.get("settings_version_id") != req.settings_version_id
                or existing_memo.get("settings_checksum") != req.settings_checksum
            ):
                return JSONResponse(
                    status_code=status.HTTP_409_CONFLICT,
                    content={"error": {"code": "workflow_conflict", "message": "Workflow memo conflict"}},
                )

            temporal_status = status_to_str(desc.status)
            return WorkflowStartResponse(
                data=WorkflowStartResponseData(
                    workflow_id=req.workflow_id,
                    run_id=desc.run_id,
                    temporal_status=temporal_status,
                )
            )
        except Exception as e:
            return JSONResponse(
                status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
                content={"error": {"code": "workflow_unavailable", "message": str(e)}},
            )
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": str(e)}},
        )

@app.get("/internal/v1/workflows/{workflow_id}", response_model=WorkflowDescribeResponse)
async def describe_workflow_endpoint(workflow_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": "Temporal connection failed"}},
        )

    try:
        handle = client.get_workflow_handle(workflow_id)
        desc = await handle.describe()
        existing_memo = await desc.memo() or {}
        temporal_status = status_to_str(desc.status)
        return WorkflowDescribeResponse(
            data=WorkflowDescribeResponseData(
                found=True,
                workflow_id=workflow_id,
                run_id=desc.run_id,
                temporal_status=temporal_status,
                case_id=existing_memo.get("case_id"),
                settings_version_id=existing_memo.get("settings_version_id"),
                settings_checksum=existing_memo.get("settings_checksum"),
            )
        )
    except Exception as e:
        # Check if error is not found
        err_msg = str(e).lower()
        if "not found" in err_msg or "cannot find" in err_msg:
            return WorkflowDescribeResponse(
                data=WorkflowDescribeResponseData(
                    found=False,
                    workflow_id=workflow_id,
                )
            )
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": str(e)}},
        )

@app.post("/internal/v1/workflows/{workflow_id}/activate-source")
async def activate_source_endpoint(workflow_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        data = json.loads(raw_body)
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Malformed JSON"}},
        )

    if data.get("schema_version") != 1 or not data.get("case_id") or not data.get("activation_id"):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Invalid request fields"}},
        )

    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": "Temporal connection failed"}},
        )

    try:
        handle = client.get_workflow_handle(workflow_id)
        desc = await handle.describe()

        if desc.status != WorkflowExecutionStatus.RUNNING:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "source_activation_conflict", "message": f"Workflow in status {desc.status}"}},
            )

        existing_memo = await desc.memo() or {}
        memo_case_id = existing_memo.get("case_id")
        if memo_case_id and memo_case_id != data["case_id"]:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "source_activation_conflict", "message": "Case ID mismatch"}},
            )

        await handle.signal("source_activation_v1", data)

        return JSONResponse(
            status_code=status.HTTP_202_ACCEPTED,
            content={"data": {"workflow_id": workflow_id, "activation_id": data["activation_id"], "accepted": True}},
        )
    except Exception as e:
        err_msg = str(e).lower()
        if "not found" in err_msg or "cannot find" in err_msg:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "source_activation_conflict", "message": "Workflow not found"}},
            )
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": str(e)}},
        )

@app.post("/internal/v1/workflows/{workflow_id}/activate-map")
async def activate_map_endpoint(workflow_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        data = json.loads(raw_body)
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Malformed JSON"}},
        )

    if (
        data.get("schema_version") != 1
        or not data.get("case_id")
        or not data.get("binding_ref")
        or not data.get("activation_id")
    ):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Invalid request fields"}},
        )

    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": "Temporal connection failed"}},
        )

    try:
        handle = client.get_workflow_handle(workflow_id)
        desc = await handle.describe()

        if desc.status != WorkflowExecutionStatus.RUNNING:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "mapping_activation_conflict", "message": f"Workflow in status {desc.status}"}},
            )

        existing_memo = await desc.memo() or {}
        memo_case_id = existing_memo.get("case_id")
        if memo_case_id and memo_case_id != data["case_id"]:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "mapping_activation_conflict", "message": "Case ID mismatch"}},
            )

        await handle.signal("mapping_activation_v1", data)

        return JSONResponse(
            status_code=status.HTTP_202_ACCEPTED,
            content={"data": {"workflow_id": workflow_id, "activation_id": data["activation_id"], "accepted": True}},
        )
    except Exception as e:
        err_msg = str(e).lower()
        if "not found" in err_msg or "cannot find" in err_msg:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "mapping_activation_conflict", "message": "Workflow not found"}},
            )
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": str(e)}},
        )

@app.post("/internal/v1/workflows/{workflow_id}/lesson-ready")
async def lesson_ready_endpoint(workflow_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        data = json.loads(raw_body)
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Malformed JSON"}},
        )

    if (
        data.get("schema_version") != 1
        or not data.get("case_id")
        or not data.get("map_ref")
        or "work_ordinal" not in data
        or "cursor_version" not in data
        or not data.get("completion_ref")
    ):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Invalid request fields"}},
        )

    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": "Temporal connection failed"}},
        )

    try:
        handle = client.get_workflow_handle(workflow_id)
        desc = await handle.describe()

        if desc.status != WorkflowExecutionStatus.RUNNING:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "lesson_conflict", "message": f"Workflow in status {desc.status}"}},
            )

        existing_memo = await desc.memo() or {}
        memo_case_id = existing_memo.get("case_id")
        if memo_case_id and memo_case_id != data["case_id"]:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "lesson_conflict", "message": "Case ID mismatch"}},
            )

        await handle.signal("lesson_ready_v1", data)

        return JSONResponse(
            status_code=status.HTTP_202_ACCEPTED,
            content={"data": {"workflow_id": workflow_id, "completion_ref": data["completion_ref"], "accepted": True}},
        )
    except Exception as e:
        err_msg = str(e).lower()
        if "not found" in err_msg or "cannot find" in err_msg:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "lesson_conflict", "message": "Workflow not found"}},
            )
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": str(e)}},
        )

@app.post("/internal/v1/workflows/{workflow_id}/notebook-reconciled")
async def notebook_reconciled_endpoint(workflow_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        data = json.loads(raw_body)
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Malformed JSON"}},
        )

    if (
        data.get("schema_version") != 1
        or not data.get("case_id")
        or not data.get("query_ref")
        or "work_ordinal" not in data
        or "cursor_version" not in data
        or not data.get("completion_ref")
    ):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Invalid request fields"}},
        )

    try:
        client = await get_temporal_client()
        handle = client.get_workflow_handle(workflow_id)
        desc = await handle.describe()

        if desc.status != WorkflowExecutionStatus.RUNNING:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "lesson_conflict", "message": f"Workflow in status {desc.status}"}},
            )

        await handle.signal("notebook_reconciled_v1", data)

        return JSONResponse(
            status_code=status.HTTP_202_ACCEPTED,
            content={"data": {"workflow_id": workflow_id, "completion_ref": data["completion_ref"], "accepted": True}},
        )
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": str(e)}},
        )

@app.get("/internal/v1/workflows/{workflow_id}/consumed-query")
async def consumed_query_endpoint(workflow_id: str, query_ref: str, request: Request) -> Any:
    auth_err = await verify_request_signature(request, b"")
    if auth_err:
        return auth_err

    try:
        client = await get_temporal_client()
        handle = client.get_workflow_handle(workflow_id)
        consumed = await handle.query("consumed_query", query_ref)
        return JSONResponse(
            status_code=status.HTTP_200_OK,
            content={"data": {"workflow_id": workflow_id, "query_ref": query_ref, "consumed": consumed}},
        )
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": str(e)}},
        )

@app.post("/internal/v1/review-preview/sessions")
async def start_review_preview_session_endpoint(request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    if len(raw_body) > 2 * 1024 * 1024:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Payload exceeds 2 MiB"}},
            headers={"Cache-Control": "private, no-store"},
        )

    try:
        data = json.loads(raw_body.decode("utf-8"))
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": str(e)}},
            headers={"Cache-Control": "private, no-store"},
        )

    if (
        data.get("schema_version") != 1
        or not data.get("viewer_ref")
        or not data.get("candidate_ref")
        or not data.get("manifest_sha256")
        or not isinstance(data.get("files"), list)
    ):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Missing or invalid fields"}},
            headers={"Cache-Control": "private, no-store"},
        )

    gateway = PreviewGateway.get_instance()
    res = gateway.start_session(
        viewer_ref=data["viewer_ref"],
        candidate_ref=data["candidate_ref"],
        manifest_sha256=data["manifest_sha256"],
        files=data["files"],
    )

    if not res.get("success", False):
        err = res.get("error", "review_unavailable")
        msg = res.get("message", "Preview session start failed")
        st_code = (
            status.HTTP_409_CONFLICT
            if err == "review_conflict"
            else status.HTTP_503_SERVICE_UNAVAILABLE
        )
        return JSONResponse(
            status_code=st_code,
            content={"error": {"code": err, "message": msg}},
            headers={"Cache-Control": "private, no-store"},
        )

    is_replay = res.get("is_replay", False)
    st_code = status.HTTP_200_OK if is_replay else status.HTTP_201_CREATED
    return JSONResponse(
        status_code=st_code,
        content={
            "data": {
                "viewer_ref": res["viewer_ref"],
                "frame_no": 0,
                "frame": res.get("frame", {}),
            }
        },
        headers={"Cache-Control": "private, no-store"},
    )

@app.post("/internal/v1/review-preview/sessions/{viewer_ref}/actions")
async def apply_review_preview_action_endpoint(viewer_ref: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    if len(raw_body) > 1024:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Payload exceeds 1024 bytes"}},
            headers={"Cache-Control": "private, no-store"},
        )

    try:
        data = json.loads(raw_body.decode("utf-8"))
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": str(e)}},
            headers={"Cache-Control": "private, no-store"},
        )

    if data.get("schema_version") != 1 or "expected_frame_no" not in data or not data.get("kind"):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Invalid action fields"}},
            headers={"Cache-Control": "private, no-store"},
        )

    gateway = PreviewGateway.get_instance()
    res = gateway.apply_action(viewer_ref, data)

    if not res.get("success", False):
        err = res.get("error", "review_unavailable")
        msg = res.get("message", "Preview action failed")
        if err == "review_conflict":
            st_code = status.HTTP_409_CONFLICT
        elif err in ("resource_unavailable", "session_closed"):
            st_code = status.HTTP_404_NOT_FOUND
        else:
            st_code = status.HTTP_503_SERVICE_UNAVAILABLE
        return JSONResponse(
            status_code=st_code,
            content={"error": {"code": err, "message": msg}},
            headers={"Cache-Control": "private, no-store"},
        )

    return JSONResponse(
        status_code=status.HTTP_200_OK,
        content={
            "data": {
                "viewer_ref": res["viewer_ref"],
                "frame_no": res["frame_no"],
                "frame": res.get("frame", {}),
            }
        },
        headers={"Cache-Control": "private, no-store"},
    )

@app.get("/internal/v1/review-preview/sessions/{viewer_ref}/frames/{frame_no}")
async def get_review_preview_frame_endpoint(viewer_ref: str, frame_no: int, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    gateway = PreviewGateway.get_instance()
    frame = gateway.get_frame(viewer_ref, frame_no)
    if not frame:
        return JSONResponse(
            status_code=status.HTTP_404_NOT_FOUND,
            content={"error": {"code": "resource_unavailable", "message": "Frame not found"}},
            headers={"Cache-Control": "private, no-store"},
        )

    png_b64 = frame.get("png_base64", "")
    try:
        png_bytes = base64.b64decode(png_b64)
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            content={"error": {"code": "preview_corrupt", "message": "Could not decode frame PNG"}},
            headers={"Cache-Control": "private, no-store"},
        )

    return Response(
        content=png_bytes,
        media_type="image/png",
        headers={"Cache-Control": "private, no-store"},
    )

@app.delete("/internal/v1/review-preview/sessions/{viewer_ref}")
async def close_review_preview_session_endpoint(viewer_ref: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    gateway = PreviewGateway.get_instance()
    gateway.close_session(viewer_ref)
    return Response(status_code=status.HTTP_204_NO_CONTENT, headers={"Cache-Control": "private, no-store"})

async def _review_temporal_client(workflow_id: str) -> Client:
    if hasattr(app.state, "temporal_client") and app.state.temporal_client:
        return app.state.temporal_client
    if workflow_id.startswith("v3-web-review-"):
        from v3_engine.terminal_pipeline.settings import get_terminal_settings
        settings = get_terminal_settings()
        return await Client.connect(settings.temporal_address, namespace=settings.temporal_namespace)
    return await get_temporal_client()


@app.post("/internal/v1/web-reviews/{review_ref}/start")
async def start_web_review_endpoint(review_ref: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err
    try:
        expected = json.loads(raw_body)
    except Exception:
        expected = None
    if (not isinstance(expected, dict) or expected.get("schema_version") != 1
        or expected.get("review_ref") != review_ref
        or any(not is_valid_uuid_ref(expected.get(key)) for key in
               ("case_id", "section_ref", "review_ref", "candidate_ref"))
        or not isinstance(expected.get("candidate_version"), int)
        or isinstance(expected.get("candidate_version"), bool)
        or expected["candidate_version"] < 1):
        return JSONResponse(status_code=422, content={"error": {"code": "contract_invalid", "message": "Invalid review identity"}})

    workflow_id = f"v3-web-review-{review_ref}"
    try:
        from v3_engine.web_pipeline.review_workflow import WebReviewWorkflow
        client = await _review_temporal_client(workflow_id)
        await client.start_workflow(
            WebReviewWorkflow.run, expected, id=workflow_id,
            task_queue="v3-terminal-control",
            id_reuse_policy=WorkflowIDReusePolicy.REJECT_DUPLICATE,
        )
        return JSONResponse(status_code=202, content={"data": {"workflow_id": workflow_id, "review_ref": review_ref}})
    except WorkflowAlreadyStartedError:
        try:
            recorded = await client.get_workflow_handle(workflow_id).query("review_identity_v1")
            if recorded == expected:
                return JSONResponse(status_code=200, content={"data": {"workflow_id": workflow_id, "review_ref": review_ref}})
        except Exception:
            pass
        return JSONResponse(status_code=409, content={"error": {"code": "review_conflict", "message": "Review workflow identity differs"}})
    except Exception:
        return JSONResponse(status_code=503, content={"error": {"code": "workflow_unavailable", "message": "Review workflow could not be started"}})


@app.post("/internal/v1/workflows/{workflow_id}/review-decisions")
async def review_decision_endpoint(workflow_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        data = json.loads(raw_body)
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Malformed JSON"}},
            headers={"Cache-Control": "private, no-store"},
        )

    if (
        data.get("schema_version") != 1
        or not data.get("case_id")
        or not data.get("section_ref")
        or not data.get("review_ref")
        or not data.get("decision_ref")
        or not data.get("candidate_ref")
        or "candidate_version" not in data
        or data.get("outcome") not in ("approve", "request_changes")
    ):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Invalid request fields"}},
            headers={"Cache-Control": "private, no-store"},
        )

    try:
        client = await _review_temporal_client(workflow_id)
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": "Temporal connection failed"}},
            headers={"Cache-Control": "private, no-store"},
        )

    try:
        handle = client.get_workflow_handle(workflow_id)
        desc = await handle.describe()

        if desc.status != WorkflowExecutionStatus.RUNNING:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "decision_conflict", "message": f"Workflow in status {desc.status}"}},
                headers={"Cache-Control": "private, no-store"},
            )

        existing_memo = await desc.memo() or {}
        memo_case_id = existing_memo.get("case_id")
        if memo_case_id and memo_case_id != data["case_id"]:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "decision_conflict", "message": "Case ID mismatch"}},
                headers={"Cache-Control": "private, no-store"},
            )

        await handle.signal("review_decision_v1", data)

        return JSONResponse(
            status_code=status.HTTP_202_ACCEPTED,
            content={"data": {"workflow_id": workflow_id, "decision_ref": data["decision_ref"], "accepted": True}},
            headers={"Cache-Control": "private, no-store"},
        )
    except Exception as e:
        err_msg = str(e).lower()
        if "not found" in err_msg or "cannot find" in err_msg:
            return JSONResponse(
                status_code=status.HTTP_409_CONFLICT,
                content={"error": {"code": "decision_conflict", "message": "Workflow not found"}},
                headers={"Cache-Control": "private, no-store"},
            )
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": str(e)}},
            headers={"Cache-Control": "private, no-store"},
        )

@app.get("/internal/v1/workflows/{workflow_id}/review-decisions/{decision_ref}")
async def query_review_decision_endpoint(workflow_id: str, decision_ref: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        client = await _review_temporal_client(workflow_id)
    except Exception as e:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": "Temporal connection failed"}},
            headers={"Cache-Control": "private, no-store"},
        )

    try:
        handle = client.get_workflow_handle(workflow_id)
        desc = await handle.describe()

        consumed = False
        try:
            consumed = await handle.query("query_decision_consumed", decision_ref)
        except Exception as e:
            # A closed execution is not proof that this particular decision
            # was consumed. Keep the outbox retryable when the exact ledger
            # query is unavailable.
            return JSONResponse(
                status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
                content={"error": {"code": "decision_proof_unavailable", "message": "Decision consumption could not be verified"}},
                headers={"Cache-Control": "private, no-store"},
            )

        return JSONResponse(
            status_code=status.HTTP_200_OK,
            content={"data": {"decision_ref": decision_ref, "consumed": bool(consumed)}},
            headers={"Cache-Control": "private, no-store"},
        )
    except Exception as e:
        err_msg = str(e).lower()
        if "not found" in err_msg or "cannot find" in err_msg:
            return JSONResponse(
                status_code=status.HTTP_404_NOT_FOUND,
                content={"error": {"code": "resource_unavailable", "message": "Workflow not found"}},
                headers={"Cache-Control": "private, no-store"},
            )
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": str(e)}},
            headers={"Cache-Control": "private, no-store"},
        )

@app.post("/internal/v1/workflows/{workflow_id}/start-correction")
async def start_correction_endpoint(workflow_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        data = json.loads(raw_body)
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Malformed JSON"}},
            headers={"Cache-Control": "private, no-store"},
        )

    if (
        data.get("schema_version") != 1
        or not data.get("case_id")
        or not data.get("section_ref")
        or not data.get("correction_ref")
        or not data.get("route_ref")
        or data.get("feedback_kind") not in ("visual_interaction", "factual_content", "mixed")
    ):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Missing required fields"}},
            headers={"Cache-Control": "private, no-store"},
        )

    expected_id = f"v3-review-correction-{data['correction_ref']}"
    if workflow_id != expected_id:
        return JSONResponse(status_code=409, content={"error": {"code": "correction_conflict", "message": "Workflow identity mismatch"}}, headers={"Cache-Control": "private, no-store"})

    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()
        from v3_engine.review.correction_workflow import CorrectionWorkflow
        from v3_engine.terminal_pipeline.settings import get_terminal_settings
        terminal_settings = get_terminal_settings()
        try:
            handle = await client.start_workflow(
                CorrectionWorkflow.run,
                {
                    "schema_version": 1,
                    "case_id": data["case_id"],
                    "section_ref": data["section_ref"],
                    "correction_ref": data["correction_ref"],
                    "route_ref": data.get("route_ref"),
                    "feedback_kind": data.get("feedback_kind", "visual_interaction"),
                },
                id=workflow_id,
                task_queue=terminal_settings.control_queue,
                id_reuse_policy=WorkflowIDReusePolicy.REJECT_DUPLICATE,
                memo={"case_id": data["case_id"], "correction_ref": data["correction_ref"], "schema_version": 1},
            )
            return JSONResponse(status_code=202, content={"data": {"workflow_id": workflow_id, "correction_ref": data["correction_ref"], "run_id": handle.result_run_id, "accepted": True}}, headers={"Cache-Control": "private, no-store"})
        except WorkflowAlreadyStartedError:
            handle = client.get_workflow_handle(workflow_id)
            desc = await handle.describe()
            memo = await desc.memo() or {}
            if memo.get("case_id") != data["case_id"] or memo.get("correction_ref") != data["correction_ref"]:
                return JSONResponse(status_code=409, content={"error": {"code": "correction_conflict", "message": "Existing workflow identity mismatch"}}, headers={"Cache-Control": "private, no-store"})
            return JSONResponse(status_code=200, content={"data": {"workflow_id": workflow_id, "correction_ref": data["correction_ref"], "status": str(desc.status)}}, headers={"Cache-Control": "private, no-store"})
    except Exception:
        return JSONResponse(status_code=503, content={"error": {"code": "workflow_unavailable", "message": "Correction workflow could not be confirmed"}}, headers={"Cache-Control": "private, no-store"})

@app.get("/internal/v1/workflows/{workflow_id}/describe-correction")
async def describe_correction_endpoint(workflow_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    status_name = "running"
    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()
        handle = client.get_workflow_handle(workflow_id)
        desc = await handle.describe()
        status_name = str(desc.status)
    except Exception:
        pass

    return JSONResponse(
        status_code=status.HTTP_200_OK,
        content={"data": {"workflow_id": workflow_id, "status": status_name}},
        headers={"Cache-Control": "private, no-store"},
    )

@app.get("/internal/v1/health")
async def health_endpoint(request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()

        import asyncio
        from datetime import datetime, timezone

        if hasattr(client, "service_client") and hasattr(client.service_client, "check_health"):
            await asyncio.wait_for(client.service_client.check_health(), timeout=2.0)

        return JSONResponse(
            status_code=status.HTTP_200_OK,
            content={"data": {"reachable": True, "checked_at": datetime.now(timezone.utc).isoformat()}},
            headers={"Cache-Control": "private, no-store"},
        )
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": "Bridge probe failed"}},
            headers={"Cache-Control": "private, no-store"},
        )


@app.post("/internal/v1/notebooklm/health")
async def notebooklm_health_endpoint(request: Request) -> Any:
    """Read-only authenticated check of the configured NotebookLM session."""
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        import asyncio
        from datetime import datetime, timezone
        from v3_engine.notebooklm.credentials import CredentialsValidationError
        from v3_engine.notebooklm.ports import AuthenticationRequiredError, ProviderError
        from v3_engine.notebooklm.sdk_provider import SdkSessionProvider

        async with asyncio.timeout(15):
            async with SdkSessionProvider().open() as session:
                # Listing is read-only and verifies that the saved Google
                # session can reach NotebookLM. No prompts or paid generation.
                await session.files.list_notebooks()
        return JSONResponse(
            status_code=status.HTTP_200_OK,
            content={"data": {"healthy": True, "state": "ready", "checked_at": datetime.now(timezone.utc).isoformat()}},
            headers={"Cache-Control": "private, no-store"},
        )
    except AuthenticationRequiredError:
        return JSONResponse(
            status_code=status.HTTP_200_OK,
            content={"data": {"healthy": False, "state": "authentication_required"}},
            headers={"Cache-Control": "private, no-store"},
        )
    except CredentialsValidationError as exc:
        if exc.code == "authentication_required":
            return JSONResponse(
                status_code=status.HTTP_200_OK,
                content={"data": {"healthy": False, "state": "authentication_required"}},
                headers={"Cache-Control": "private, no-store"},
            )
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "notebooklm_unavailable", "message": "NotebookLM health could not be verified."}},
            headers={"Cache-Control": "private, no-store"},
        )
    except (TimeoutError, ProviderError):
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "notebooklm_unavailable", "message": "NotebookLM health could not be verified."}},
            headers={"Cache-Control": "private, no-store"},
        )
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "notebooklm_unavailable", "message": "NotebookLM health could not be verified."}},
            headers={"Cache-Control": "private, no-store"},
        )

@app.post("/internal/v1/workflows/{workflow_id}/admin-controls")
async def send_admin_control_endpoint(workflow_id: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        body = json.loads(raw_body.decode("utf-8"))
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Invalid JSON"}},
            headers={"Cache-Control": "private, no-store"},
        )

    if body.get("schema_version") != 1:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Invalid schema_version"}},
            headers={"Cache-Control": "private, no-store"},
        )

    command_ref = body.get("command_ref")
    action = body.get("action")
    control_version = body.get("control_version")

    if not command_ref or not action or not isinstance(control_version, int):
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            content={"error": {"code": "contract_invalid", "message": "Missing required fields"}},
            headers={"Cache-Control": "private, no-store"},
        )

    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()

        handle = client.get_workflow_handle(workflow_id)
        await handle.signal("admin_control_v1", body)

        return JSONResponse(
            status_code=status.HTTP_202_ACCEPTED,
            content={"data": {"command_ref": command_ref, "state": "accepted"}},
            headers={"Cache-Control": "private, no-store"},
        )
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": {"code": "workflow_unavailable", "message": "Failed to send admin control"}},
            headers={"Cache-Control": "private, no-store"},
        )

@app.get("/internal/v1/workflows/{workflow_id}/admin-controls/{command_ref}")
async def query_admin_control_endpoint(workflow_id: str, command_ref: str, request: Request) -> Any:
    raw_body = await request.body()
    auth_err = await verify_request_signature(request, raw_body)
    if auth_err:
        return auth_err

    try:
        if hasattr(app.state, "temporal_client") and app.state.temporal_client:
            client = app.state.temporal_client
        else:
            client = await get_temporal_client()

        handle = client.get_workflow_handle(workflow_id)
        desc = await handle.describe()

        if desc.status != WorkflowExecutionStatus.RUNNING:
            return JSONResponse(
                status_code=status.HTTP_200_OK,
                content={"data": {"command_ref": command_ref, "control_version": 0, "state": "closed"}},
                headers={"Cache-Control": "private, no-store"},
            )

        cmd_info = await handle.query("query_admin_control", command_ref)
        if cmd_info:
            return JSONResponse(
                status_code=status.HTTP_200_OK,
                content={"data": {
                    "command_ref": command_ref,
                    "control_version": cmd_info.get("control_version", 0),
                    "state": cmd_info.get("state", "applied"),
                }},
                headers={"Cache-Control": "private, no-store"},
            )
        else:
            return JSONResponse(
                status_code=status.HTTP_200_OK,
                content={"data": {"command_ref": command_ref, "control_version": 0, "state": "pending"}},
                headers={"Cache-Control": "private, no-store"},
            )
    except Exception:
        return JSONResponse(
            status_code=status.HTTP_200_OK,
            content={"data": {"command_ref": command_ref, "control_version": 0, "state": "closed"}},
            headers={"Cache-Control": "private, no-store"},
        )


from v3_engine.web_pipeline.bridge import router as application_pipeline_router
app.include_router(application_pipeline_router)


