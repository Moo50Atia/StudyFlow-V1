import pytest
import json
from unittest.mock import AsyncMock, patch
import httpx

from v3_engine.qualification.jev import (
    SceneJevClient,
    validate_scene_jev_response,
    compute_scene_jev_request_digest,
    SceneJevWireValidationError,
    SceneJevTechnicalError
)

def test_s09_valid_typed_response_validation():
    valid_wire = {
        "model": "jev-latest",
        "answers": {
            "choice": {
                "type": "choice",
                "choice": "pass",
                "confidence": 0.95,
                "probabilities": {"pass": 0.95, "revise": 0.04, "block": 0.01},
            },
            "score": {
                "type": "score",
                "score": 85.0,
                "confidence": 0.92,
                "legend": {str(i): f"level {i}" for i in range(101)},
                "probabilities": {str(i): (1.0 if i == 85 else 0.0) for i in range(101)},
            },
            "noul": {
                "type": "noul",
                "noul": 0.91,
            }
        }
    }

    choice, score, noul = validate_scene_jev_response(valid_wire)
    assert choice["choice"] == "pass"
    assert choice["confidence"] == 0.95
    assert score["score"] == 85.0
    assert score["confidence"] == 0.92
    assert noul["noul"] == 0.91
    assert noul["noul"] >= 0.5

def test_s11_malformed_or_missing_keys_raises_validation_error():
    # Missing noul
    wire_missing = {
        "model": "jev-latest",
        "answers": {
            "choice": {"type": "choice", "choice": "pass", "confidence": 0.95, "probabilities": {"pass": 1.0, "revise": 0.0, "block": 0.0}},
            "score": {"type": "score", "score": 85.0, "confidence": 0.90, "legend": {str(i): f"level {i}" for i in range(101)}, "probabilities": {str(i): 0.0 for i in range(101)}}
        }
    }
    with pytest.raises(SceneJevWireValidationError):
        validate_scene_jev_response(wire_missing)

    # Score out of bounds
    wire_out_of_bounds = {
        "model": "jev-latest",
        "answers": {
            "choice": {"type": "choice", "choice": "pass", "confidence": 0.95, "probabilities": {"pass": 1.0, "revise": 0.0, "block": 0.0}},
            "score": {"type": "score", "score": 150.0, "confidence": 0.90, "legend": {str(i): f"level {i}" for i in range(101)}, "probabilities": {str(i): 0.0 for i in range(101)}},
            "noul": {"type": "noul", "noul": 0.90}
        }
    }
    with pytest.raises(SceneJevWireValidationError):
        validate_scene_jev_response(wire_out_of_bounds)

    # NaN / Inf confidence
    wire_nan = {
        "model": "jev-latest",
        "answers": {
            "choice": {"type": "choice", "choice": "pass", "confidence": float("nan"), "probabilities": {"pass": 1.0, "revise": 0.0, "block": 0.0}},
            "score": {"type": "score", "score": 85.0, "confidence": 0.90, "legend": {str(i): f"level {i}" for i in range(101)}, "probabilities": {str(i): 0.0 for i in range(101)}},
            "noul": {"type": "noul", "noul": 0.90}
        }
    }
    with pytest.raises(SceneJevWireValidationError):
        validate_scene_jev_response(wire_nan)

    # The obsolete answer/value/probability shape is not accepted as an official response.
    wire_legacy = {
        "model": "jev-latest",
        "answers": {
            "choice": {"answer": "pass", "confidence": 0.95},
            "score": {"value": 85.0, "confidence": 0.90},
            "noul": {"value": True, "probability": 0.90},
        },
    }
    with pytest.raises(SceneJevWireValidationError):
        validate_scene_jev_response(wire_legacy)

@pytest.mark.asyncio
async def test_s11_oversize_evidence_fails_closed():
    client = SceneJevClient(api_key="test-key")
    huge_evidence = {"big": "X" * (6 * 1024 * 1024 + 10)}
    with pytest.raises(SceneJevTechnicalError) as exc_info:
        await client.evaluate_scene(huge_evidence)
    assert exc_info.value.error_code == "evidence_limit"

@pytest.mark.asyncio
async def test_s11_http_timeout_and_5xx_raise_technical_error():
    client = SceneJevClient(api_key="test-key")
    evidence = {"schema_version": 1, "small": "test"}

    # Mock timeout
    mock_client = AsyncMock(spec=httpx.AsyncClient)
    mock_client.post.side_effect = httpx.ReadTimeout("Timeout")
    with pytest.raises(SceneJevTechnicalError) as exc_info:
        await client.evaluate_scene(evidence, client=mock_client)
    assert exc_info.value.error_code == "timeout"

    # Mock 500 error
    mock_resp = AsyncMock(spec=httpx.Response)
    mock_resp.status_code = 500
    mock_resp.text = "Internal Server Error"
    mock_client.post.side_effect = None
    mock_client.post.return_value = mock_resp
    with pytest.raises(SceneJevTechnicalError) as exc_info500:
        await client.evaluate_scene(evidence, client=mock_client)
    assert exc_info500.value.error_code == "provider_unavailable"
