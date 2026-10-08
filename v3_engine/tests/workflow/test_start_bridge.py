import json
import os
import time
import uuid
import pytest
from httpx import ASGITransport, AsyncClient
from temporalio.testing import WorkflowEnvironment

from v3_engine.bridge import app
from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings

pytestmark = [pytest.mark.temporal, pytest.mark.asyncio]


CLI_PATH = "/home/moahmmed/.local/bin/temporal"

@pytest.fixture
async def temporal_env():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=CLI_PATH,
        ui=False,
    ) as env:
        app.state.temporal_client = env.client
        yield env
        app.state.temporal_client = None

def sign_request(method: str, path: str, body: dict, secret: str = None) -> dict:
    if secret is None:
        secret = os.getenv("V3_BRIDGE_HMAC_KEY", "dev-bridge-hmac-key")
    if method.upper() == "GET":
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

@pytest.mark.asyncio
async def test_start_workflow_and_idempotent_duplicate(temporal_env):
    case_id = str(uuid.uuid4())
    workflow_id = f"v3-case-{case_id}"
    req_body = {
        "schema_version": 1,
        "case_id": case_id,
        "settings_version_id": 1,
        "settings_checksum": "a" * 64,
        "workflow_id": workflow_id,
        "retry_policy": {
            "technical_retries": 3,
            "retry_delay_seconds": 5,
        },
    }

    path = "/internal/v1/workflows/start"
    headers = sign_request("POST", path, req_body)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(path, json=req_body, headers=headers)
        assert resp.status_code == 200, resp.text
        data = resp.json()["data"]
        assert data["workflow_id"] == workflow_id
        assert data["temporal_status"] == "open"
        run_id = data["run_id"]
        assert run_id

        # Calling start again with same identity returns same run_id (idempotent)
        headers2 = sign_request("POST", path, req_body)
        resp2 = await client.post(path, json=req_body, headers=headers2)
        assert resp2.status_code == 200, resp2.text
        data2 = resp2.json()["data"]
        assert data2["run_id"] == run_id
        assert data2["workflow_id"] == workflow_id

@pytest.mark.asyncio
async def test_start_workflow_conflict_on_memo_mismatch(temporal_env):
    case_id = str(uuid.uuid4())
    workflow_id = f"v3-case-{case_id}"
    req_body1 = {
        "schema_version": 1,
        "case_id": case_id,
        "settings_version_id": 1,
        "settings_checksum": "a" * 64,
        "workflow_id": workflow_id,
        "retry_policy": {
            "technical_retries": 3,
            "retry_delay_seconds": 5,
        },
    }

    path = "/internal/v1/workflows/start"
    headers1 = sign_request("POST", path, req_body1)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp1 = await client.post(path, json=req_body1, headers=headers1)
        assert resp1.status_code == 200

        # Attempt start on same workflow ID but with different settings_checksum in memo
        req_body2 = dict(req_body1)
        req_body2["settings_checksum"] = "b" * 64

        headers2 = sign_request("POST", path, req_body2)
        resp2 = await client.post(path, json=req_body2, headers=headers2)
        assert resp2.status_code == 409
        assert resp2.json()["error"]["code"] == "workflow_conflict"

@pytest.mark.asyncio
async def test_describe_workflow_endpoint(temporal_env):
    case_id = str(uuid.uuid4())
    workflow_id = f"v3-case-{case_id}"
    req_body = {
        "schema_version": 1,
        "case_id": case_id,
        "settings_version_id": 1,
        "settings_checksum": "c" * 64,
        "workflow_id": workflow_id,
        "retry_policy": {
            "technical_retries": 1,
            "retry_delay_seconds": 2,
        },
    }

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        # Start workflow first
        start_path = "/internal/v1/workflows/start"
        headers_start = sign_request("POST", start_path, req_body)
        start_resp = await client.post(start_path, json=req_body, headers=headers_start)
        assert start_resp.status_code == 200
        run_id = start_resp.json()["data"]["run_id"]

        # Describe existing workflow
        desc_path = f"/internal/v1/workflows/{workflow_id}"
        headers_desc = sign_request("GET", desc_path, {})
        desc_resp = await client.get(desc_path, headers=headers_desc)
        assert desc_resp.status_code == 200
        desc_data = desc_resp.json()["data"]
        assert desc_data["found"] is True
        assert desc_data["workflow_id"] == workflow_id
        assert desc_data["run_id"] == run_id
        assert desc_data["case_id"] == case_id
        assert desc_data["settings_version_id"] == 1
        assert desc_data["settings_checksum"] == "c" * 64
        assert desc_data["temporal_status"] == "open"

        # Describe non-existent workflow
        not_found_path = f"/internal/v1/workflows/v3-case-{uuid.uuid4()}"
        headers_nf = sign_request("GET", not_found_path, {})
        nf_resp = await client.get(not_found_path, headers=headers_nf)
        assert nf_resp.status_code == 200
        nf_data = nf_resp.json()["data"]
        assert nf_data["found"] is False

@pytest.mark.asyncio
async def test_bridge_rejects_unauthenticated_request(temporal_env):
    path = "/internal/v1/workflows/start"
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(path, json={})
        assert resp.status_code == 401
        assert resp.json()["error"]["code"] == "worker_unauthenticated"
