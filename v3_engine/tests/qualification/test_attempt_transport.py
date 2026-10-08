"""
Tests for R12: Separating Laravel attempt transport from external TypeSafe evaluator.
Covers B09, B10; 012 JEV transport.
"""

import hashlib
import json
import pytest
import httpx
from unittest.mock import patch

from v3_engine.contracts import compute_hmac_signature
from v3_engine.qualification.client import (
    SceneJevAttemptClient,
    get_jev_attempt_client,
)
from v3_engine.qualification.jev import (
    SceneJevClient,
    get_scene_jev_client,
    SceneJevTechnicalError,
    SceneJevWireValidationError,
    SCENE_QUESTIONS_PATH,
)


def _scene_jev_response(model: str, score_value: float = 92.0, noul_value: float = 0.95) -> dict:
    return {
        "model": model,
        "answers": {
            "choice": {
                "type": "choice",
                "choice": "pass",
                "confidence": 0.96,
                "probabilities": {"pass": 0.96, "revise": 0.03, "block": 0.01},
            },
            "score": {
                "type": "score",
                "score": score_value,
                "confidence": 0.94,
                "legend": {str(i): f"level {i}" for i in range(101)},
                "probabilities": {str(i): (1.0 if i == int(score_value) else 0.0) for i in range(101)},
            },
            "noul": {"type": "noul", "noul": noul_value},
        },
        "usage": {"input_tokens": 100, "output_tokens": 10},
    }


# ---------------------------------------------------------------------------
# 1. Credential and Client Factory Separation Tests
# ---------------------------------------------------------------------------

def test_credential_separation_and_factories(monkeypatch):
    """
    Assert Laravel attempt client uses callback HMAC and internal origin;
    external TypeSafe client uses typesafe api key, base url, and dedicated model.
    Neither client reads GEMINI_API_KEY.
    """
    # Test Laravel attempt client factory
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", "test-callback-secret-456")
    monkeypatch.setenv("V3_LARAVEL_CALLBACK_URL", "http://internal-laravel:8000/api/internal/v1/case-events")
    monkeypatch.delenv("TYPESAFE_API_KEY", raising=False)
    monkeypatch.delenv("GEMINI_API_KEY", raising=False)

    laravel_client = get_jev_attempt_client()
    assert isinstance(laravel_client, SceneJevAttemptClient)
    assert laravel_client.base_url == "http://internal-laravel:8000"
    assert laravel_client.hmac_key == "test-callback-secret-456"

    # Missing callback key raises error
    monkeypatch.delenv("V3_CALLBACK_HMAC_KEY", raising=False)
    with pytest.raises(RuntimeError, match="V3_CALLBACK_HMAC_KEY is not configured"):
        get_jev_attempt_client()

    # Test TypeSafe evaluator factory
    monkeypatch.setenv("TYPESAFE_API_KEY", "ts-key-abc-123")
    monkeypatch.setenv("TYPESAFE_BASE_URL", "https://custom.typesafe.ai")
    monkeypatch.setenv("JEV_MODEL", "jev-latest")
    monkeypatch.delenv("V3_CALLBACK_HMAC_KEY", raising=False)

    typesafe_client = get_scene_jev_client()
    assert isinstance(typesafe_client, SceneJevClient)
    assert typesafe_client.api_key == "ts-key-abc-123"
    assert typesafe_client.base_url == "https://custom.typesafe.ai"
    assert typesafe_client.model == "jev-latest"

    # Missing TypeSafe key or JEV model raises error
    monkeypatch.delenv("TYPESAFE_API_KEY", raising=False)
    with pytest.raises(RuntimeError, match="TYPESAFE_API_KEY is not configured"):
        get_scene_jev_client()

    monkeypatch.setenv("TYPESAFE_API_KEY", "ts-key-abc-123")
    monkeypatch.delenv("JEV_MODEL", raising=False)
    with pytest.raises(RuntimeError, match="JEV_MODEL is not configured"):
        get_scene_jev_client()


def test_reject_unimplemented_call_typesafe_jev_import():
    """
    Reject an invented unimplemented function import as the transport.
    v3_engine.prompts.providers must not be relied upon for scene JEV.
    """
    import v3_engine.qualification.activity as act
    assert not hasattr(act, "call_typesafe_jev")
    # Also verify activity module does not have GEMINI_API_KEY fallback
    import inspect
    src = inspect.getsource(act)
    assert "call_typesafe_jev" not in src
    assert "GEMINI_API_KEY" not in src


# ---------------------------------------------------------------------------
# 2. Signed Laravel Attempt Client Transport Tests
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_laravel_attempt_client_claim_attempt():
    hmac_key = "secret-hmac-123"
    captured_request = {}

    def handler(request: httpx.Request):
        captured_request["method"] = request.method
        captured_request["url"] = str(request.url)
        captured_request["headers"] = dict(request.headers)
        captured_request["body"] = json.loads(request.content.decode("utf-8"))
        return httpx.Response(
            201,
            json={
                "data": {
                    "attempt_ref": "attempt-uuid-001",
                    "state": "claimed",
                    "disposition": "send_now",
                    "request_sha256": "req-sha-123",
                    "qualification_ref": None,
                }
            },
        )

    transport = httpx.MockTransport(handler)
    client = SceneJevAttemptClient(
        base_url="http://laravel.test",
        hmac_key=hmac_key,
        transport=transport,
    )

    res = await client.claim_attempt(
        case_id="case-100",
        candidate_ref="cand-200",
        check_ref="check-300",
        attempt_no=1,
    )

    assert res["attempt_ref"] == "attempt-uuid-001"
    assert res["state"] == "claimed"
    assert captured_request["method"] == "POST"
    assert captured_request["url"] == "http://laravel.test/api/internal/v1/cases/case-100/scene-jev-attempts"
    assert captured_request["headers"]["x-v3-contract-version"] == "1"
    assert "x-v3-signature" in captured_request["headers"]
    assert captured_request["body"] == {
        "schema_version": 1,
        "candidate_ref": "cand-200",
        "check_ref": "check-300",
        "attempt_no": 1,
    }


@pytest.mark.asyncio
async def test_laravel_attempt_client_get_context():
    hmac_key = "secret-hmac-123"
    captured_request = {}

    def handler(request: httpx.Request):
        captured_request["method"] = request.method
        captured_request["url"] = str(request.url)
        captured_request["headers"] = dict(request.headers)
        return httpx.Response(
            200,
            json={
                "data": {
                    "attempt_ref": "attempt-uuid-001",
                    "model": "jev-latest",
                    "question_sha256": "q-sha-456",
                    "evidence": {"schema_version": 1, "test": "data"},
                }
            },
        )

    transport = httpx.MockTransport(handler)
    client = SceneJevAttemptClient(
        base_url="http://laravel.test",
        hmac_key=hmac_key,
        transport=transport,
    )

    context = await client.get_context(case_id="case-100", attempt_ref="attempt-uuid-001")
    assert context["attempt_ref"] == "attempt-uuid-001"
    assert context["model"] == "jev-latest"
    assert context["evidence"] == {"schema_version": 1, "test": "data"}
    assert captured_request["method"] == "GET"
    assert captured_request["url"] == "http://laravel.test/api/internal/v1/cases/case-100/scene-jev-attempts/attempt-uuid-001/context"
    assert "x-v3-signature" in captured_request["headers"]


@pytest.mark.asyncio
async def test_laravel_attempt_client_record_result():
    hmac_key = "secret-hmac-123"
    captured_request = {}

    def handler(request: httpx.Request):
        captured_request["method"] = request.method
        captured_request["url"] = str(request.url)
        captured_request["body"] = json.loads(request.content.decode("utf-8"))
        return httpx.Response(
            201,
            json={
                "data": {
                    "attempt_ref": "attempt-uuid-001",
                    "state": "recorded",
                    "qualification_ref": "qual-uuid-999",
                    "decision": "pass",
                    "failed_checks": [],
                }
            },
        )

    transport = httpx.MockTransport(handler)
    client = SceneJevAttemptClient(
        base_url="http://laravel.test",
        hmac_key=hmac_key,
        transport=transport,
    )

    raw_answer = json.dumps(_scene_jev_response("jev-latest"), separators=(",", ":"))
    answer_sha = hashlib.sha256(raw_answer.encode("utf-8")).hexdigest()

    res = await client.record_result(
        case_id="case-100",
        attempt_ref="attempt-uuid-001",
        raw_answer_json=raw_answer,
        answer_sha256=answer_sha,
        returned_model="jev-latest",
    )

    assert res["state"] == "recorded"
    assert res["decision"] == "pass"
    assert captured_request["method"] == "POST"
    assert captured_request["url"] == "http://laravel.test/api/internal/v1/cases/case-100/scene-jev-attempts/attempt-uuid-001/result"
    assert captured_request["body"] == {
        "schema_version": 1,
        "raw_answer_json": raw_answer,
        "answer_sha256": answer_sha,
        "returned_model": "jev-latest",
    }


@pytest.mark.asyncio
async def test_laravel_attempt_client_error_handling():
    hmac_key = "secret-hmac-123"

    def handler(request: httpx.Request):
        return httpx.Response(409, text='{"error":{"code":"scene_conflict","message":"conflict"}}')

    transport = httpx.MockTransport(handler)
    client = SceneJevAttemptClient(
        base_url="http://laravel.test",
        hmac_key=hmac_key,
        transport=transport,
    )

    with pytest.raises(RuntimeError, match="returned 409"):
        await client.claim_attempt("c1", "cand1", "chk1", 1)


# ---------------------------------------------------------------------------
# 3. External TypeSafe Evaluator Transport Tests
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_typesafe_evaluator_request_and_response():
    """
    Assert external evaluation uses TypeSafe authentication, questions from canonical JSON,
    and returns validated typed structure.
    """
    captured_request = {}

    def handler(request: httpx.Request):
        captured_request["method"] = request.method
        captured_request["url"] = str(request.url)
        captured_request["headers"] = dict(request.headers)
        captured_request["body"] = json.loads(request.content.decode("utf-8"))
        return httpx.Response(
            200,
            json=_scene_jev_response("jev-latest"),
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as mock_http_client:
        evaluator = SceneJevClient(
            api_key="ts-secret-key-789",
            base_url="https://api.typesafe.ai",
            model="jev-latest",
        )

        evidence = {"schema_version": 1, "observations": {"dom_text": "Sample text"}}
        result = await evaluator.evaluate_scene(evidence=evidence, client=mock_http_client)

        assert result["model"] == "jev-latest"
        assert "answers" in result
        assert result["answers"]["choice"]["choice"] == "pass"
        assert result["answers"]["score"]["score"] == 92.0
        assert result["answers"]["noul"]["noul"] == 0.95

        # Verify wire details
        assert captured_request["method"] == "POST"
        assert captured_request["url"] == "https://api.typesafe.ai/v1/systemone"
        assert captured_request["headers"]["authorization"] == "Bearer ts-secret-key-789"
        assert "x-v3-signature" not in captured_request["headers"]  # Not sent to external API!
        assert captured_request["body"]["model"] == "jev-latest"
        assert captured_request["body"]["state"] == evidence
        assert "choice" in captured_request["body"]["questions"]


# ---------------------------------------------------------------------------
# 4. Connected Wire Sequence Test (Claim -> Context -> Evaluate -> Record)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_full_jev_wire_sequence():
    """
    Test that the two distinct clients execute the full qualification sequence
    without either client implementing the other's methods.
    """
    case_id = "case-uuid-10"
    candidate_ref = "cand-uuid-20"
    check_ref = "check-uuid-30"

    # Laravel client
    def laravel_handler(req: httpx.Request):
        url = str(req.url)
        if url.endswith("/scene-jev-attempts"):
            return httpx.Response(201, json={"data": {"attempt_ref": "att-40", "state": "claimed"}})
        elif url.endswith("/context"):
            return httpx.Response(
                200,
                json={
                    "data": {
                        "attempt_ref": "att-40",
                        "model": "jev-latest",
                        "evidence": {"dom_text": "Hello world"},
                    }
                },
            )
        elif url.endswith("/result"):
            return httpx.Response(
                201,
                json={
                    "data": {
                        "attempt_ref": "att-40",
                        "state": "recorded",
                        "decision": "pass",
                        "qualification_ref": "qual-50",
                    }
                },
            )
        return httpx.Response(404)

    # TypeSafe evaluator
    def typesafe_handler(req: httpx.Request):
        return httpx.Response(
            200,
            json={
                **_scene_jev_response("jev-latest", score_value=90.0, noul_value=0.92),
            },
        )

    laravel_client = SceneJevAttemptClient(
        base_url="http://laravel.internal",
        hmac_key="key",
        transport=httpx.MockTransport(laravel_handler),
    )
    typesafe_evaluator = SceneJevClient(
        api_key="ts-key",
        base_url="https://api.typesafe.ai",
        model="jev-latest",
    )

    # 1. Claim attempt in Laravel
    claim_res = await laravel_client.claim_attempt(case_id, candidate_ref, check_ref, 1)
    attempt_ref = claim_res["attempt_ref"]
    assert attempt_ref == "att-40"

    # 2. Fetch context from Laravel
    context = await laravel_client.get_context(case_id, attempt_ref)
    model = context["model"]
    evidence = context["evidence"]
    assert model == "jev-latest"

    # 3. Evaluate with TypeSafe
    async with httpx.AsyncClient(transport=httpx.MockTransport(typesafe_handler)) as ts_http:
        eval_result = await typesafe_evaluator.evaluate_scene(evidence, client=ts_http, model=model)

    returned_model = eval_result.get("model", model)
    raw_answer_json = json.dumps(eval_result, separators=(",", ":"))
    answer_sha = hashlib.sha256(raw_answer_json.encode("utf-8")).hexdigest()

    # 4. Record result back in Laravel
    record_res = await laravel_client.record_result(
        case_id=case_id,
        attempt_ref=attempt_ref,
        raw_answer_json=raw_answer_json,
        answer_sha256=answer_sha,
        returned_model=returned_model,
    )

    assert record_res["state"] == "recorded"
    assert record_res["decision"] == "pass"
    assert record_res["qualification_ref"] == "qual-50"
