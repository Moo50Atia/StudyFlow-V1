"""Temporal dispatch coverage for teacher-triggered scene authoring."""

from __future__ import annotations

import asyncio
import uuid
from contextlib import AsyncExitStack

import pytest
from temporalio import activity
from temporalio.worker import Worker

from v3_engine.web_pipeline.authoring_workflow import ApplicationAuthoringWorkflow


@pytest.mark.temporal
@pytest.mark.asyncio
async def test_authoring_workflow_resolves_request_authors_candidate_and_persists_checks(temporal_local_env):
    run_id = str(uuid.uuid4())
    case_id = str(uuid.uuid4())
    request_ref = str(uuid.uuid4())
    manifest_ref = str(uuid.uuid4())
    candidate_ref = str(uuid.uuid4())
    check_ref = str(uuid.uuid4())
    calls: list[str] = []

    @activity.defn(name="resolve_web_authoring_manifest_activity")
    async def resolve_job(_args):
        calls.append("resolve")
        return {
            "outcome": "completed", "safe_code": "ok", "case_id": case_id,
            "request_ref": request_ref,
        }

    @activity.defn(name="authoring_execute_attempts")
    async def author_candidate(_args):
        calls.append("author")
        return {"status": "candidate_ready", "candidate_ref": candidate_ref}

    @activity.defn(name="qualify_authoring_candidate_activity")
    async def check_candidate(_args):
        calls.append("qualify")
        return {
            "outcome": "completed", "safe_code": "ok", "candidate_ref": candidate_ref,
            "result_ref": check_ref, "verdict": "pass",
        }

    client = temporal_local_env.client
    workers = [
        Worker(
            client,
            task_queue="v3-terminal-control",
            workflows=[ApplicationAuthoringWorkflow],
            activities=[resolve_job],
        ),
        Worker(client, task_queue="v3-terminal-io", activities=[author_candidate]),
        Worker(client, task_queue="v3-terminal-build", activities=[check_candidate]),
    ]

    async with AsyncExitStack() as stack:
        for worker in workers:
            await stack.enter_async_context(worker)
        handle = await client.start_workflow(
            ApplicationAuthoringWorkflow.run,
            {
                "run_id": run_id,
                "subject_run_id": request_ref,
                "manifest_ref": manifest_ref,
            },
            id=f"test-web-authoring-{request_ref}",
            task_queue="v3-terminal-control",
        )
        result = await handle.result()
        assert result == {
            "outcome": "completed", "safe_code": "ok", "run_id": run_id,
            "case_id": case_id, "request_ref": request_ref,
            "candidate_ref": candidate_ref, "check_ref": check_ref, "verdict": "pass",
        }
        assert calls == ["resolve", "author", "qualify"]
