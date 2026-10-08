"""Live gate test for Spec 014 (T017 / O24).

Marked with @pytest.mark.live and gated by V3_ADMIN_LIVE=1.
Executes real Temporal workflow and bridge with hold, resume, and cancel signals.
"""
import json
import os
import time
import uuid
import pytest
from httpx import ASGITransport, AsyncClient
from temporalio.client import Client
from temporalio.worker import Worker
from temporalio.testing import WorkflowEnvironment

from v3_engine.bridge import app
from v3_engine.contracts import compute_hmac_signature
from v3_engine.admin.control import AdminControlTracker
from v3_engine.workflows.case_workflow import CaseWorkflow

CLI_PATH = "/home/moahmmed/.local/bin/temporal"


def sign_request(method: str, path: str, body: dict = None, secret: str = "dev-bridge-hmac-key") -> dict:
    if method.upper() == "GET" or body is None:
        raw_body = b""
    else:
        raw_body = json.dumps(body, separators=(",", ":")).encode("utf-8")
    now_ts = int(time.time())
    sig = compute_hmac_signature(method, path, now_ts, raw_body, secret)
    return {
        "X-V3-Timestamp": str(now_ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
        "Content-Type": "application/json",
    }


@pytest.mark.live
@pytest.mark.temporal
@pytest.mark.asyncio
async def test_live_temporal_hold_resume_cancel():
    if os.getenv("V3_ADMIN_LIVE") != "1":
        pytest.skip("V3_ADMIN_LIVE not set to 1; skipping opt-in live gate.")

    temporal_address = os.getenv("TEMPORAL_ADDRESS", None)

    async def run_with_env(client: Client):
        task_queue = f"live-admin-queue-{uuid.uuid4()}"
        case_id = str(uuid.uuid4())
        workflow_id = f"v3-case-{case_id}"

        # Initialize bridge state
        app.state.temporal_client = client

        # 1. Health check probe
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as http_client:
            headers = sign_request("GET", "/internal/v1/health")
            health_resp = await http_client.get("/internal/v1/health", headers=headers)
            assert health_resp.status_code == 200
            assert health_resp.json()["data"]["reachable"] is True

            # 2. Start worker
            async with Worker(
                client,
                task_queue=task_queue,
                workflows=[CaseWorkflow],
            ):
                # Start workflow
                handle = await client.start_workflow(
                    CaseWorkflow.run,
                    args=[{"case_id": case_id}],
                    id=workflow_id,
                    task_queue=task_queue,
                )
                assert handle.id == workflow_id

                # 3. Post hold command via signed bridge endpoint
                cmd_hold = str(uuid.uuid4())
                hold_payload = {
                    "schema_version": 1,
                    "case_id": case_id,
                    "command_ref": cmd_hold,
                    "control_version": 1,
                    "action": "hold",
                }
                hold_path = f"/internal/v1/workflows/{workflow_id}/admin-controls"
                hold_headers = sign_request("POST", hold_path, hold_payload)
                resp_hold = await http_client.post(hold_path, headers=hold_headers, json=hold_payload)
                assert resp_hold.status_code == 202

                # Query control status via bridge
                query_path = f"/internal/v1/workflows/{workflow_id}/admin-controls/{cmd_hold}"
                q_headers = sign_request("GET", query_path)
                resp_q = await http_client.get(query_path, headers=q_headers)
                assert resp_q.status_code == 200
                assert resp_q.json()["data"]["state"] in ["applied", "pending"]

                # 4. Post resume command via signed bridge endpoint
                cmd_resume = str(uuid.uuid4())
                resume_payload = {
                    "schema_version": 1,
                    "case_id": case_id,
                    "command_ref": cmd_resume,
                    "control_version": 2,
                    "action": "resume",
                }
                resume_headers = sign_request("POST", hold_path, resume_payload)
                resp_resume = await http_client.post(hold_path, headers=resume_headers, json=resume_payload)
                assert resp_resume.status_code == 202

                # 5. Post cancel command via signed bridge endpoint
                cmd_cancel = str(uuid.uuid4())
                cancel_payload = {
                    "schema_version": 1,
                    "case_id": case_id,
                    "command_ref": cmd_cancel,
                    "control_version": 3,
                    "action": "cancel",
                }
                cancel_headers = sign_request("POST", hold_path, cancel_payload)
                resp_cancel = await http_client.post(hold_path, headers=cancel_headers, json=cancel_payload)
                assert resp_cancel.status_code == 202

    if temporal_address:
        client = await Client.connect(temporal_address)
        await run_with_env(client)
    elif os.path.exists(CLI_PATH):
        async with await WorkflowEnvironment.start_local(
            dev_server_existing_path=CLI_PATH,
            ui=False,
        ) as env:
            await run_with_env(env.client)
    else:
        pytest.skip(f"Temporal CLI not found at {CLI_PATH} and TEMPORAL_ADDRESS not configured.")
