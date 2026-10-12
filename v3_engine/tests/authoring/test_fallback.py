"""Unit tests for authoring attempt fallback policy, credential check, timeouts and budget limits."""
import pytest
from unittest.mock import AsyncMock, patch, MagicMock

from v3_engine.authoring.activity import AuthoringRunner, AuthoringOutcome
from v3_engine.authoring.package import ScenePackageV1, ScenePackageFile


@pytest.fixture
def base_context():
    return {
        "request_ref": "11111111-1111-1111-1111-111111111111",
        "section_ref": "22222222-2222-2222-2222-222222222222",
        "prompt_version_ref": "33333333-3333-3333-3333-333333333333",
        "qualification_ref": "44444444-4444-4444-4444-444444444444",
        "prompt": "Process: Wave particles oscillating in place.",
        "body_sha256": "abcdef1234567890",
        "settings_version_id": 1,
        "settings_checksum": "checksum123",
        "template_id": "threejs-scene-v1",
        "template_sha256": "template123",
        "template_text": "System: return ScenePackageV1",
        "coding_order": [
            {"id": "gemini_api", "model": "gemini-2.5-flash", "credential_ref": "gemini_api_key"},
            {"id": "glm_api", "model": "glm-4-flash", "credential_ref": "z_ai_api_key"},
            {"id": "deepseek_api", "model": "deepseek-chat", "credential_ref": "deepseek_api_key"},
        ],
        "coding_attempts": 3,
    }


@pytest.mark.asyncio
async def test_missing_credential_consumes_slot_without_http_and_advances(base_context):
    """A10: missing credential produces known_failure with 0 HTTP calls and advances to next provider."""
    client = MagicMock()
    client.get_context = AsyncMock(return_value=base_context)
    client.reserve_attempt = AsyncMock(side_effect=[
        {
            "attempt_ref": "att-1",
            "ordinal": 1,
            "provider": {"id": "gemini_api", "model": "gemini-2.5-flash", "credential_ref": "gemini_api_key"},
            "state": "send_reserved",
        },
        {
            "attempt_ref": "att-2",
            "ordinal": 2,
            "provider": {"id": "glm_api", "model": "glm-4-flash", "credential_ref": "z_ai_api_key"},
            "state": "send_reserved",
        },
    ])
    client.record_result = AsyncMock(side_effect=[
        {"attempt_ref": "att-1", "state": "known_failure", "candidate_ref": None},
        {"attempt_ref": "att-2", "state": "candidate_ready", "candidate_ref": "cand-2"},
    ])

    # Env has z_ai_api_key, but NOT gemini_api_key
    env_keys = {"z_ai_api_key": "secret-glm"}

    valid_json = '{"schema_version":1,"files":[{"path":"index.html","content":"<!DOCTYPE html><html><body>Test</body></html>"}]}'

    with patch("v3_engine.authoring.activity.call_gemini_authoring", new_callable=AsyncMock) as mock_gemini, \
         patch("v3_engine.authoring.activity.call_glm_authoring", new_callable=AsyncMock) as mock_glm:

        mock_glm.return_value = {
            "text": valid_json,
            "returned_model": "glm-4-flash",
            "provider_response_id": "glm-resp-1",
            "duration_ms": 200,
        }

        runner = AuthoringRunner(client=client, api_keys=env_keys)
        outcome = await runner.run("case-1", "req-1")

        # Gemini must NEVER have been called over HTTP
        mock_gemini.assert_not_called()
        # GLM must have been called
        mock_glm.assert_called_once()

        # Slot 1 result recorded with 0 duration and provider_unavailable
        first_result_call = client.record_result.call_args_list[0]
        assert first_result_call[1]["payload"]["outcome"] == "known_failure"
        assert first_result_call[1]["payload"]["failure_code"] == "provider_unavailable"
        assert first_result_call[1]["payload"]["duration_ms"] == 0

        # Overall outcome is candidate_ready from slot 2
        assert outcome.status == "candidate_ready"
        assert outcome.candidate_ref == "cand-2"


@pytest.mark.asyncio
async def test_provider_tuple_contract_creates_candidate_and_preserves_response_metadata(base_context):
    client = MagicMock()
    client.get_context = AsyncMock(return_value={
        **base_context,
        "coding_order": [{"id": "gemini_api", "model": "gemini-2.5-pro", "credential_ref": "gemini_api_key", "thinking_budget": 12000}],
        "coding_attempts": 1,
    })
    client.reserve_attempt = AsyncMock(return_value={
        "attempt_ref": "attempt-1",
        "provider": {"id": "gemini_api", "model": "gemini-2.5-pro", "credential_ref": "gemini_api_key", "thinking_budget": 12000},
    })
    client.record_result = AsyncMock(return_value={"candidate_ref": "candidate-1"})
    package_text = '{"schema_version":1,"files":[{"path":"index.html","content":"<!doctype html><html><body>Scene</body></html>"}]}'
    provider_json = '{"modelVersion":"gemini-2.5-pro","responseId":"response-1"}'

    with patch("v3_engine.authoring.activity.call_gemini_authoring", new_callable=AsyncMock) as call_provider:
        call_provider.return_value = (package_text, "gemini-2.5-pro", "response-1", provider_json, {"input_tokens": 11})
        outcome = await AuthoringRunner(client, {"gemini_api_key": "fixture-key"}).run("case-1", "request-1")

    assert outcome == AuthoringOutcome(status="candidate_ready", candidate_ref="candidate-1")
    call_provider.assert_awaited_once_with(
        "gemini-2.5-pro", "fixture-key", base_context["template_text"], base_context["prompt"],
        thinking_budget=12000,
    )
    result = client.record_result.await_args.kwargs["payload"]
    assert result["outcome"] == "candidate"
    assert result["provider_response_id"] == "response-1"
    assert result["provider_response_json"] == provider_json


@pytest.mark.asyncio
async def test_known_refusal_advances_to_next_distinct_provider(base_context):
    """A10: 401/403/429 refusal produces known_failure and advances to next provider."""
    client = MagicMock()
    client.get_context = AsyncMock(return_value=base_context)
    client.reserve_attempt = AsyncMock(side_effect=[
        {
            "attempt_ref": "att-1",
            "ordinal": 1,
            "provider": {"id": "gemini_api", "model": "gemini-2.5-flash", "credential_ref": "gemini_api_key"},
            "state": "send_reserved",
        },
        {
            "attempt_ref": "att-2",
            "ordinal": 2,
            "provider": {"id": "glm_api", "model": "glm-4-flash", "credential_ref": "z_ai_api_key"},
            "state": "send_reserved",
        },
    ])
    client.record_result = AsyncMock(side_effect=[
        {"attempt_ref": "att-1", "state": "known_failure", "candidate_ref": None},
        {"attempt_ref": "att-2", "state": "candidate_ready", "candidate_ref": "cand-2"},
    ])

    env_keys = {"gemini_api_key": "key1", "z_ai_api_key": "key2"}
    valid_json = '{"schema_version":1,"files":[{"path":"index.html","content":"<!DOCTYPE html><html><body>Test</body></html>"}]}'

    with patch("v3_engine.authoring.activity.call_gemini_authoring", new_callable=AsyncMock) as mock_gemini, \
         patch("v3_engine.authoring.activity.call_glm_authoring", new_callable=AsyncMock) as mock_glm:

        # Provider 1 returns 429 rate limit refusal
        mock_gemini.side_effect = RuntimeError("Provider returned 429: Too Many Requests")
        mock_glm.return_value = {
            "text": valid_json,
            "returned_model": "glm-4-flash",
            "provider_response_id": "glm-resp-1",
            "duration_ms": 200,
        }

        runner = AuthoringRunner(client=client, api_keys=env_keys)
        outcome = await runner.run("case-1", "req-1")

        assert client.record_result.call_count == 2
        assert client.record_result.call_args_list[0][1]["payload"]["outcome"] == "known_failure"
        assert outcome.status == "candidate_ready"


@pytest.mark.asyncio
async def test_structurally_invalid_package_records_validation_failure_and_advances(base_context):
    """A10: structurally invalid response records known_failure/validation and advances."""
    client = MagicMock()
    client.get_context = AsyncMock(return_value=base_context)
    client.reserve_attempt = AsyncMock(side_effect=[
        {
            "attempt_ref": "att-1",
            "ordinal": 1,
            "provider": {"id": "gemini_api", "model": "gemini-2.5-flash", "credential_ref": "gemini_api_key"},
            "state": "send_reserved",
        },
        {
            "attempt_ref": "att-2",
            "ordinal": 2,
            "provider": {"id": "glm_api", "model": "glm-4-flash", "credential_ref": "z_ai_api_key"},
            "state": "send_reserved",
        },
    ])
    client.record_result = AsyncMock(side_effect=[
        {"attempt_ref": "att-1", "state": "known_failure", "candidate_ref": None},
        {"attempt_ref": "att-2", "state": "candidate_ready", "candidate_ref": "cand-2"},
    ])

    env_keys = {"gemini_api_key": "key1", "z_ai_api_key": "key2"}
    invalid_json = '{"schema_version":1,"files":[{"path":"script.js","content":"console.log(1)"}]}' # Missing index.html
    valid_json = '{"schema_version":1,"files":[{"path":"index.html","content":"<!DOCTYPE html><html><body>Test</body></html>"}]}'

    with patch("v3_engine.authoring.activity.call_gemini_authoring", new_callable=AsyncMock) as mock_gemini, \
         patch("v3_engine.authoring.activity.call_glm_authoring", new_callable=AsyncMock) as mock_glm:

        mock_gemini.return_value = {
            "text": invalid_json,
            "returned_model": "gemini-2.5-flash",
            "provider_response_id": "gem-resp-1",
            "duration_ms": 300,
        }
        mock_glm.return_value = {
            "text": valid_json,
            "returned_model": "glm-4-flash",
            "provider_response_id": "glm-resp-2",
            "duration_ms": 250,
        }

        runner = AuthoringRunner(client=client, api_keys=env_keys)
        outcome = await runner.run("case-1", "req-1")

        first_result = client.record_result.call_args_list[0][1]["payload"]
        assert first_result["outcome"] == "known_failure"
        assert first_result["failure_code"] == "validation"
        assert outcome.status == "candidate_ready"


@pytest.mark.asyncio
async def test_timeout_halts_fallback_and_records_uncertain(base_context):
    """A12: post-send timeout records uncertain and halts fallback without advancing."""
    import asyncio

    client = MagicMock()
    client.get_context = AsyncMock(return_value=base_context)
    client.reserve_attempt = AsyncMock(return_value={
        "attempt_ref": "att-1",
        "ordinal": 1,
        "provider": {"id": "gemini_api", "model": "gemini-2.5-flash", "credential_ref": "gemini_api_key"},
        "state": "send_reserved",
    })
    client.record_result = AsyncMock(return_value={
        "attempt_ref": "att-1",
        "state": "uncertain",
        "candidate_ref": None,
    })

    env_keys = {"gemini_api_key": "key1", "z_ai_api_key": "key2"}

    with patch("v3_engine.authoring.activity.call_gemini_authoring", new_callable=AsyncMock) as mock_gemini, \
         patch("v3_engine.authoring.activity.call_glm_authoring", new_callable=AsyncMock) as mock_glm:

        mock_gemini.side_effect = asyncio.TimeoutError("Provider HTTP request timed out after 60s")

        runner = AuthoringRunner(client=client, api_keys=env_keys)
        outcome = await runner.run("case-1", "req-1")

        # GLM must NOT be called
        mock_glm.assert_not_called()

        assert client.reserve_attempt.call_count == 1
        assert client.record_result.call_count == 1
        result_call = client.record_result.call_args[1]["payload"]
        assert result_call["outcome"] == "uncertain"
        assert result_call["failure_code"] == "timeout"

        assert outcome.status == "uncertain"


@pytest.mark.asyncio
async def test_budget_cap_blocks_section(base_context):
    """A11: all known failures up to attempt cap block the section with safe error."""
    base_context["coding_attempts"] = 2  # Cap budget at 2 attempts

    client = MagicMock()
    client.get_context = AsyncMock(return_value=base_context)
    client.reserve_attempt = AsyncMock(side_effect=[
        {
            "attempt_ref": "att-1",
            "ordinal": 1,
            "provider": {"id": "gemini_api", "model": "gemini-2.5-flash", "credential_ref": "gemini_api_key"},
            "state": "send_reserved",
        },
        {
            "attempt_ref": "att-2",
            "ordinal": 2,
            "provider": {"id": "glm_api", "model": "glm-4-flash", "credential_ref": "z_ai_api_key"},
            "state": "send_reserved",
        },
    ])
    client.record_result = AsyncMock(side_effect=[
        {"attempt_ref": "att-1", "state": "known_failure", "candidate_ref": None},
        {"attempt_ref": "att-2", "state": "known_failure", "candidate_ref": None},
    ])

    env_keys = {"gemini_api_key": "k1", "z_ai_api_key": "k2"}

    with patch("v3_engine.authoring.activity.call_gemini_authoring", new_callable=AsyncMock) as mock_gemini, \
         patch("v3_engine.authoring.activity.call_glm_authoring", new_callable=AsyncMock) as mock_glm:

        mock_gemini.side_effect = RuntimeError("401 Unauthorized")
        mock_glm.side_effect = RuntimeError("429 Too Many Requests")

        runner = AuthoringRunner(client=client, api_keys=env_keys)
        outcome = await runner.run("case-1", "req-1")

        assert client.reserve_attempt.call_count == 2
        assert outcome.status == "blocked"
        assert outcome.safe_error_code == "authoring_provider_unavailable"
