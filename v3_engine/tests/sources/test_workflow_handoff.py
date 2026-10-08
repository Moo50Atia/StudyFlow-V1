import pytest
import asyncio
import time
from datetime import timedelta
from typing import Any, Dict, List
from temporalio import activity, workflow
from temporalio.client import WorkflowExecutionStatus
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Worker
from fastapi.testclient import TestClient

from v3_engine.workflows.case_workflow import CaseWorkflow
from v3_engine.activities.publish_milestone import publish_workflow_started
from v3_engine.bridge import app as bridge_app
from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings

executed_activities: List[str] = []
published_events: List[str] = []

@activity.defn(name="publish_workflow_started")
async def fixture_publish_started(payload: dict) -> dict:
    executed_activities.append("publish_workflow_started")
    return {"data": {"event_id": "ev-start-1", "case_id": payload["case_id"], "state_version": 2}}

@activity.defn(name="publish_case_event")
async def fixture_publish_event(payload: dict) -> dict:
    executed_activities.append(f"publish_event:{payload['event_key']}")
    published_events.append(payload["event_key"])
    return {"data": {"event_id": f"ev-{payload['event_key']}", "case_id": payload["case_id"], "state_version": payload["expected_state_version"] + 1}}

@activity.defn(name="extract_case_source")
async def fixture_extract_case_source(payload: dict) -> dict:
    executed_activities.append("extract_case_source")
    return {"status": "ready", "extracted_source_ref": "00000000-0000-0000-0000-000000000100", "page_count": 2}

@activity.defn(name="ingest_case_source")
async def fixture_ingest_case_source(payload: dict) -> dict:
    executed_activities.append("ingest_case_source")
    return {"status": "bound", "binding_ref": "00000000-0000-0000-0000-000000000200"}

@pytest.mark.temporal
@pytest.mark.asyncio
async def test_k01_k18_workflow_receives_signal_and_executes_source_pipeline():
    executed_activities.clear()
    published_events.clear()

    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "test-handoff-queue"
        case_id = "00000000-0000-0000-0000-000000000010"
        activation_id = "00000000-0000-0000-0000-000000000020"
        wf_id = f"v3-case-{case_id}"

        activities = [
            fixture_publish_started,
            fixture_publish_event,
            fixture_extract_case_source,
            fixture_ingest_case_source,
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

            # Wait for workflow to park at extraction wait
            await asyncio.sleep(0.5)
            assert "publish_workflow_started" in executed_activities

            # Send signal
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": activation_id})
            # Duplicate signal must be no-op
            await handle.signal("source_activation_v1", {"case_id": case_id, "activation_id": activation_id})
            # Foreign signal must be ignored
            await handle.signal("source_activation_v1", {"case_id": "other-case", "activation_id": "other-act"})

            # Wait for execution of extraction and sourcing
            await asyncio.sleep(1.0)

            assert "extract_case_source" in executed_activities
            assert "ingest_case_source" in executed_activities
            assert published_events == [
                "source:extraction:started",
                "source:extraction:completed",
                "source:sourcing:started",
                "source:sourcing:completed",
            ]

            desc = await handle.describe()
            assert desc.status == WorkflowExecutionStatus.RUNNING

def test_bridge_activate_source_endpoint():
    client = TestClient(bridge_app)
    settings = get_settings()

    path = "/internal/v1/workflows/v3-case-test-123/activate-source"
    body = {"schema_version": 1, "case_id": "00000000-0000-0000-0000-000000000010", "activation_id": "00000000-0000-0000-0000-000000000020"}
    body_bytes = b'{"schema_version":1,"case_id":"00000000-0000-0000-0000-000000000010","activation_id":"00000000-0000-0000-0000-000000000020"}'
    ts = int(time.time())
    sig = compute_hmac_signature("POST", path, ts, body_bytes, settings.bridge_hmac_key)

    headers = {
        "Content-Type": "application/json",
        "X-V3-Timestamp": str(ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
    }

    # Bridge should have the endpoint
    resp = client.post(path, content=body_bytes, headers=headers)
    # Against unextended bridge, this is 404
    assert resp.status_code != 404
