import pytest
import asyncio
from datetime import timedelta
from temporalio import activity
from temporalio.client import WorkflowExecutionStatus
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Worker

from v3_engine.workflows.case_workflow import CaseWorkflow
from v3_engine.activities.publish_milestone import ContractInvalidError

pytestmark = [pytest.mark.temporal, pytest.mark.asyncio]


@activity.defn(name="publish_workflow_started")
async def mock_publish_workflow_started_success(payload: dict) -> dict:
    return {
        "data": {
            "event_id": "ev-success-1",
            "case_id": payload["case_id"],
            "state_version": 2,
        }
    }

@activity.defn(name="publish_workflow_started")
async def mock_publish_workflow_started_non_retryable(payload: dict) -> dict:
    raise ContractInvalidError("Contract schema invalid")

@pytest.mark.asyncio
async def test_w17_case_workflow_publishes_start_and_parks_at_extraction_handoff():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "test-case-queue-1"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=[mock_publish_workflow_started_success],
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": "00000000-0000-0000-0000-000000000001",
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id="test-case-wf-1",
                task_queue=task_queue,
            )

            # Wait a moment for activity to finish and workflow to enter wait_condition
            await asyncio.sleep(0.5)

            desc = await handle.describe()
            # Workflow must remain running (status == RUNNING) truthfully parked
            assert desc.status == WorkflowExecutionStatus.RUNNING

@pytest.mark.asyncio
async def test_w12_case_workflow_fails_on_non_retryable_contract_error():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "test-case-queue-2"

        async with Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=[mock_publish_workflow_started_non_retryable],
        ):
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": "00000000-0000-0000-0000-000000000002",
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id="test-case-wf-2",
                task_queue=task_queue,
            )

            # Workflow must fail because of non-retryable error
            with pytest.raises(Exception):
                await handle.result()

            desc = await handle.describe()
            assert desc.status == WorkflowExecutionStatus.FAILED

@pytest.mark.asyncio
async def test_w12_workflow_state_persists_across_worker_restart():
    async with await WorkflowEnvironment.start_local(
        dev_server_existing_path="/home/moahmmed/.local/bin/temporal",
        ui=False,
    ) as env:
        task_queue = "test-case-queue-3"

        # Worker 1 starts and processes activity
        worker1 = Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=[mock_publish_workflow_started_success],
        )

        async with worker1:
            handle = await env.client.start_workflow(
                CaseWorkflow.run,
                {
                    "case_id": "00000000-0000-0000-0000-000000000003",
                    "settings_version_id": 1,
                    "settings_checksum": "abcdef",
                    "retry_policy": {"technical_retries": 1, "retry_delay_seconds": 1},
                },
                id="test-case-wf-3",
                task_queue=task_queue,
            )
            await asyncio.sleep(0.5)

        # Worker 1 is stopped now. Describe should show it's still running
        desc = await handle.describe()
        assert desc.status == WorkflowExecutionStatus.RUNNING

        # Start Worker 2
        worker2 = Worker(
            env.client,
            task_queue=task_queue,
            workflows=[CaseWorkflow],
            activities=[mock_publish_workflow_started_success],
        )

        async with worker2:
            await asyncio.sleep(0.5)
            desc2 = await handle.describe()
            assert desc2.status == WorkflowExecutionStatus.RUNNING
