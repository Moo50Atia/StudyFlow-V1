import json
import time
import uuid
import pytest
from httpx import ASGITransport, AsyncClient
from temporalio.testing import WorkflowEnvironment

from v3_engine.bridge import app
from v3_engine.contracts import compute_hmac_signature
from v3_engine.admin.control import AdminControlTracker

pytestmark = [pytest.mark.temporal, pytest.mark.asyncio]


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

def test_admin_control_tracker_hold_resume_and_version_ordering():
    tracker = AdminControlTracker()
    assert tracker.current_mode == "normal"
    assert tracker.current_version == 0

    cmd1 = str(uuid.uuid4())
    payload_hold = {
        "schema_version": 1,
        "case_id": "case-1",
        "command_ref": cmd1,
        "control_version": 1,
        "action": "hold",
    }

    # Apply hold
    applied = tracker.receive_signal(payload_hold)
    assert applied is True
    assert tracker.current_mode == "held"
    assert tracker.current_version == 1

    # Exact duplicate signal ignored
    assert tracker.receive_signal(payload_hold) is False

    # Lower control version ignored
    stale_payload = {
        "schema_version": 1,
        "case_id": "case-1",
        "command_ref": str(uuid.uuid4()),
        "control_version": 0,
        "action": "resume",
    }
    assert tracker.receive_signal(stale_payload) is False
    assert tracker.current_mode == "held"

    # Valid resume with higher version
    cmd2 = str(uuid.uuid4())
    payload_resume = {
        "schema_version": 1,
        "case_id": "case-1",
        "command_ref": cmd2,
        "control_version": 2,
        "action": "resume",
    }
    assert tracker.receive_signal(payload_resume) is True
    assert tracker.current_mode == "normal"
    assert tracker.current_version == 2

    # Query command status
    q1 = tracker.query_command(cmd1)
    assert q1 is not None
    assert q1["state"] == "applied"
    assert q1["control_version"] == 1

    q_unknown = tracker.query_command("unknown-cmd")
    assert q_unknown is None

def test_admin_control_tracker_cancellation():
    tracker = AdminControlTracker()

    cmd_cancel = str(uuid.uuid4())
    payload_cancel = {
        "schema_version": 1,
        "case_id": "case-1",
        "command_ref": cmd_cancel,
        "control_version": 1,
        "action": "cancel",
    }

    assert tracker.receive_signal(payload_cancel) is True
    assert tracker.current_mode == "cancelled"
    assert tracker.is_cancelled() is True

    # Subsequent hold with same or lower version ignored
    payload_hold = {
        "schema_version": 1,
        "case_id": "case-1",
        "command_ref": str(uuid.uuid4()),
        "control_version": 1,
        "action": "hold",
    }
    assert tracker.receive_signal(payload_hold) is False
    assert tracker.current_mode == "cancelled"

@pytest.fixture
async def temporal_env():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=CLI_PATH,
        ui=False,
    ) as env:
        app.state.temporal_client = env.client
        yield env
        app.state.temporal_client = None

@pytest.mark.asyncio
async def test_bridge_admin_control_endpoints_require_hmac():
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        # Unsigned POST
        post_path = "/internal/v1/workflows/test-wf/admin-controls"
        resp_post = await client.post(post_path, json={})
        assert resp_post.status_code == 401

        # Unsigned GET
        get_path = "/internal/v1/workflows/test-wf/admin-controls/cmd-1"
        resp_get = await client.get(get_path)
        assert resp_get.status_code == 401
