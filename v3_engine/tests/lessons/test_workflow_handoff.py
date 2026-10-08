try:
    import pytest
except ImportError:
    class _PytestShim:
        class mark:
            @staticmethod
            def asyncio(fn):
                return fn
        @staticmethod
        def skip(reason):
            print(f"SKIPPED: {reason}")
        class raises:
            def __init__(self, exc_type, match=None):
                self.exc_type = exc_type
                self.match = match
            def __enter__(self):
                return self
            def __exit__(self, exc_type, exc_val, exc_tb):
                if exc_type is None:
                    raise AssertionError(f"Expected exception {self.exc_type} was not raised")
                return issubclass(exc_type, self.exc_type)
    pytest = _PytestShim()
import asyncio
import time
import os
from datetime import timedelta
from typing import Any, Dict, List
try:
    from starlette.requests import Request
    from v3_engine.bridge import app as bridge_app
    HAS_FASTAPI = True
except ImportError:
    HAS_FASTAPI = False
    bridge_app = None
    Request = None

from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings

executed_activities: List[str] = []
published_events: List[str] = []

try:
    from temporalio import activity
    from temporalio.client import WorkflowExecutionStatus
    from temporalio.testing import WorkflowEnvironment
    from temporalio.worker import Worker
    from v3_engine.workflows.case_workflow import CaseWorkflow

    @activity.defn(name="publish_workflow_started")
    async def fixture_publish_started(payload: dict) -> dict:
        executed_activities.append("publish_workflow_started")
        return {"data": {"event_id": "ev-start-1", "case_id": payload["case_id"], "state_version": 2}}

    @activity.defn(name="publish_case_event")
    async def fixture_publish_event(payload: dict) -> dict:
        executed_activities.append(f"publish_event:{payload['event_key']}")
        published_events.append(payload["event_key"])
        return {
            "data": {
                "event_id": f"ev-{payload['event_key']}",
                "case_id": payload["case_id"],
                "state_version": payload["expected_state_version"] + 1,
            }
        }

    @activity.defn(name="extract_case_source")
    async def fixture_extract_case_source(payload: dict) -> dict:
        executed_activities.append("extract_case_source")
        return {"status": "ready", "extracted_source_ref": "00000000-0000-0000-0000-000000000100", "page_count": 2}

    @activity.defn(name="ingest_case_source")
    async def fixture_ingest_case_source(payload: dict) -> dict:
        executed_activities.append("ingest_case_source")
        return {"status": "bound", "binding_ref": "00000000-0000-0000-0000-000000000200"}

    @activity.defn(name="map_case")
    async def fixture_map_case(payload: dict) -> dict:
        executed_activities.append("map_case")
        return {"status": "ready", "map_ref": "00000000-0000-0000-0000-000000000300", "work_count": 1}

    @activity.defn(name="prepare_lesson")
    async def fixture_prepare_lesson(payload: dict) -> dict:
        executed_activities.append("prepare_lesson")
        return {"status": "ready", "cursor_phase": "awaiting_010", "section_count": 1}

    HAS_TEMPORAL = True
except ImportError:
    HAS_TEMPORAL = False


class _OfflineWorkflowHandle:
    async def describe(self):
        return _OfflineDescription()

    async def signal(self, name, payload):
        self.last_signal = (name, payload)


class _OfflineDescription:
    status = WorkflowExecutionStatus.RUNNING if 'WorkflowExecutionStatus' in globals() else None

    async def memo(self):
        return {"case_id": "00000000-0000-0000-0000-000000000010"}


class _OfflineTemporalClient:
    def get_workflow_handle(self, _workflow_id):
        return _OfflineWorkflowHandle()


def _use_offline_bridge(monkeypatch):
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", "offline-test-secret")
    monkeypatch.setattr(bridge_app.state, "temporal_client", _OfflineTemporalClient(), raising=False)


def _clear_offline_bridge():
    return None


def _request(method, path, body, headers):
    body_sent = False
    async def receive():
        nonlocal body_sent
        if body_sent:
            return {"type": "http.request", "body": b"", "more_body": False}
        body_sent = True
        return {"type": "http.request", "body": body, "more_body": False}
    scope = {
        "type": "http", "asgi": {"version": "3.0"}, "http_version": "1.1",
        "method": method, "scheme": "http", "path": path, "raw_path": path.encode(),
        "query_string": b"", "headers": [(k.lower().encode(), v.encode()) for k, v in headers.items()],
        "server": ("test", 80), "client": ("127.0.0.1", 12345),
    }
    return Request(scope, receive)


@pytest.mark.temporal
@pytest.mark.asyncio
async def test_l01_l13_workflow_receives_map_signal_and_executes_mapping_pipeline():
    if not HAS_TEMPORAL:
        pytest.skip("GATE BLOCKED: Temporal Python SDK not installed in environment")

    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.exists(temporal_bin):
        pytest.skip(f"GATE BLOCKED: Temporal CLI not found at {temporal_bin}")

    executed_activities.clear()
    published_events.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        ui=False,
    ) as env:
        task_queue = "test-map-handoff-queue"
        case_id = "00000000-0000-0000-0000-000000000010"
        src_act_id = "00000000-0000-0000-0000-000000000020"
        map_act_id = "00000000-0000-0000-0000-000000000030"
        binding_ref = "00000000-0000-0000-0000-000000000200"
        wf_id = f"v3-case-{case_id}"

        activities = [
            fixture_publish_started,
            fixture_publish_event,
            fixture_extract_case_source,
            fixture_ingest_case_source,
            fixture_map_case,
            fixture_prepare_lesson,
        ]

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=activities,
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": case_id,
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id=wf_id,
                task_queue=task_queue,
            )

            # Signal source activation to advance through 008 stages
            await asyncio.sleep(0.5)
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": src_act_id})
            await asyncio.sleep(1.0)

            # Now workflow should be waiting at 009 mapping handoff
            assert "source:sourcing:completed" in published_events

            # Send mapping signal
            map_payload = {
                "schema_version": 1,
                "case_id": case_id,
                "binding_ref": binding_ref,
                "activation_id": map_act_id,
            }
            await handle.signal("mapping_activation_v1", map_payload)

            # Duplicate signal must be no-op
            await handle.signal("mapping_activation_v1", map_payload)

            # Foreign signal must be ignored
            await handle.signal("mapping_activation_v1", {
                "schema_version": 1,
                "case_id": "different-case",
                "binding_ref": binding_ref,
                "activation_id": "other-act",
            })

            await asyncio.sleep(1.0)

            assert "map_case" in executed_activities
            assert "prepare_lesson" in executed_activities
            assert "map:started" in published_events
            assert "map:completed" in published_events

            desc = await handle.describe()
            assert desc.status == WorkflowExecutionStatus.RUNNING


@pytest.mark.asyncio
async def test_bridge_activate_map_endpoint_validation(monkeypatch):
    if not HAS_FASTAPI:
        pytest.skip("GATE BLOCKED: FastAPI not installed in environment")
    _use_offline_bridge(monkeypatch)
    settings = get_settings()

    path = "/internal/v1/workflows/v3-case-test-123/activate-map"
    body_bytes = b'{"schema_version":1,"case_id":"00000000-0000-0000-0000-000000000010","binding_ref":"00000000-0000-0000-0000-000000000200","activation_id":"00000000-0000-0000-0000-000000000030"}'
    ts = int(time.time())
    sig = compute_hmac_signature("POST", path, ts, body_bytes, settings.bridge_hmac_key)

    headers = {
        "Content-Type": "application/json",
        "X-V3-Timestamp": str(ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
    }

    from v3_engine.bridge import activate_map_endpoint
    resp = await activate_map_endpoint("v3-case-test-123", _request("POST", path, body_bytes, headers))
    # The endpoint exists and responds (not 404)
    assert resp.status_code != 404

    # Invalid contract version
    bad_headers = dict(headers)
    bad_headers["X-V3-Contract-Version"] = "99"
    resp_bad_ver = await activate_map_endpoint("v3-case-test-123", _request("POST", path, body_bytes, bad_headers))
    assert resp_bad_ver.status_code == 401

    # Missing fields
    bad_body = b'{"schema_version":1,"case_id":"00000000-0000-0000-0000-000000000010"}'
    sig_bad = compute_hmac_signature("POST", path, ts, bad_body, settings.bridge_hmac_key)
    headers_bad = dict(headers)
    headers_bad["X-V3-Signature"] = sig_bad
    resp_bad = await activate_map_endpoint("v3-case-test-123", _request("POST", path, bad_body, headers_bad))
    assert resp_bad.status_code == 422
    _clear_offline_bridge()


@pytest.mark.asyncio
async def test_bridge_lesson_ready_endpoint_validation(monkeypatch):
    if not HAS_FASTAPI:
        pytest.skip("GATE BLOCKED: FastAPI not installed in environment")
    _use_offline_bridge(monkeypatch)
    settings = get_settings()

    path = "/internal/v1/workflows/v3-case-test-123/lesson-ready"
    body_bytes = b'{"schema_version":1,"case_id":"00000000-0000-0000-0000-000000000010","map_ref":"00000000-0000-0000-0000-000000000300","work_ordinal":1,"cursor_version":1,"completion_ref":"comp-1"}'
    ts = int(time.time())
    sig = compute_hmac_signature("POST", path, ts, body_bytes, settings.bridge_hmac_key)

    headers = {
        "Content-Type": "application/json",
        "X-V3-Timestamp": str(ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
    }

    from v3_engine.bridge import lesson_ready_endpoint
    resp = await lesson_ready_endpoint("v3-case-test-123", _request("POST", path, body_bytes, headers))
    assert resp.status_code != 404
    _clear_offline_bridge()
