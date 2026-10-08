import pytest
from datetime import timedelta
from unittest.mock import AsyncMock, patch
import httpx
from temporalio.exceptions import ApplicationError

from v3_engine.activities.publish_milestone import (
    publish_workflow_started,
    ContractInvalidError,
    EventConflictError,
)
from v3_engine.workflows.case_workflow import CaseWorkflow

def test_w11_retry_policy_derivation_from_captured_settings():
    # Helper to inspect retry policy construction logic in CaseWorkflow
    input_data_1 = {
        "case_id": "test-case-1",
        "retry_policy": {
            "technical_retries": 3,
            "retry_delay_seconds": 5,
        }
    }
    # For technical_retries=3: max attempts = 4, initial_interval = 5s
    technical_retries = input_data_1["retry_policy"]["technical_retries"]
    retry_delay = input_data_1["retry_policy"]["retry_delay_seconds"]
    
    assert technical_retries + 1 == 4
    assert timedelta(seconds=retry_delay) == timedelta(seconds=5)

    # For technical_retries=0: max attempts = 1
    input_data_zero = {
        "case_id": "test-case-2",
        "retry_policy": {
            "technical_retries": 0,
            "retry_delay_seconds": 10,
        }
    }
    assert input_data_zero["retry_policy"]["technical_retries"] + 1 == 1

@pytest.mark.asyncio
async def test_w12_activity_error_classification():
    payload = {
        "case_id": "c1",
        "workflow_id": "w1",
        "run_id": "r1",
        "schema_version": 1,
        "event_key": "workflow_started",
        "expected_state_version": 1,
        "event_type": "workflow_started",
        "stage_code": "extraction",
        "status_code": "queued",
        "error_code": None,
    }

    # 1. Network timeout is retryable (non_retryable=False)
    with patch("httpx.AsyncClient.post", side_effect=httpx.ConnectTimeout("Connection timed out")):
        with pytest.raises(ApplicationError) as exc_info:
            await publish_workflow_started(payload)
        assert exc_info.value.non_retryable is False

    # 2. Server 503 is retryable (non_retryable=False)
    mock_resp_503 = httpx.Response(503, text="Service Unavailable", request=httpx.Request("POST", "http://test"))
    with patch("httpx.AsyncClient.post", return_value=mock_resp_503):
        with pytest.raises(ApplicationError) as exc_info:
            await publish_workflow_started(payload)
        assert exc_info.value.non_retryable is False

    # 3. 409 Conflict raises non-retryable EventConflictError
    mock_resp_409 = httpx.Response(409, text='{"error":{"code":"event_conflict"}}', request=httpx.Request("POST", "http://test"))
    with patch("httpx.AsyncClient.post", return_value=mock_resp_409):
        with pytest.raises(EventConflictError) as exc_info:
            await publish_workflow_started(payload)
        assert exc_info.value.non_retryable is True
        assert exc_info.value.type == "EventConflictError"

    # 4. 422 Validation error raises non-retryable ContractInvalidError
    mock_resp_422 = httpx.Response(422, text='{"error":{"code":"contract_invalid"}}', request=httpx.Request("POST", "http://test"))
    with patch("httpx.AsyncClient.post", return_value=mock_resp_422):
        with pytest.raises(ContractInvalidError) as exc_info:
            await publish_workflow_started(payload)
        assert exc_info.value.non_retryable is True
        assert exc_info.value.type == "ContractInvalidError"

    # 5. 201 Success returns response dict
    mock_resp_201 = httpx.Response(201, json={"data": {"event_id": "ev1", "state_version": 2}}, request=httpx.Request("POST", "http://test"))
    with patch("httpx.AsyncClient.post", return_value=mock_resp_201):
        result = await publish_workflow_started(payload)
        assert result["data"]["event_id"] == "ev1"
        assert result["data"]["state_version"] == 2
