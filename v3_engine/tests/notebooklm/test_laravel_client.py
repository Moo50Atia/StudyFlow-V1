from __future__ import annotations

import hashlib
import json
from pathlib import Path
import pytest
import httpx

from v3_engine.contracts import verify_hmac_signature
from v3_engine.notebooklm.laravel_client import HttpLaravelNotebookClient, LaravelClientError


@pytest.fixture
def hmac_key():
    return "test-callback-hmac-key-for-direct-protocol"


@pytest.mark.asyncio
async def test_client_signs_exact_sent_bytes(hmac_key, monkeypatch, tmp_path):
    captured_requests = []

    def mock_handler(request: httpx.Request) -> httpx.Response:
        captured_requests.append(request)
        # Verify HMAC
        ts = int(request.headers["x-v3-timestamp"])
        sig = request.headers["x-v3-signature"]
        body = request.read()
        is_valid = verify_hmac_signature(
            request.method,
            request.url.path,
            str(ts),
            sig,
            body,
            hmac_key,
        )
        assert is_valid, "HMAC signature verification failed on mock server"
        return httpx.Response(200, json={"data": {"status": "ok", "effect_ref": "eff-123"}})

    transport = httpx.MockTransport(mock_handler)
    orig_client = httpx.AsyncClient
    monkeypatch.setattr(httpx, "AsyncClient", lambda **kw: orig_client(transport=transport, **kw))

    client = HttpLaravelNotebookClient(
        base_url="http://127.0.0.1:8000",
        hmac_key=hmac_key,
    )

    claim_res = await client.claim_source_effect(
        case_id="case-1",
        operation="notebook_create",
        activation_id="act-1",
        request_digest="digest-abc",
    )
    assert claim_res["effect_ref"] == "eff-123"
    assert len(captured_requests) == 1
    req = captured_requests[0]
    assert req.headers["x-v3-contract-version"] == "1"
    assert req.headers["content-type"] == "application/json"


@pytest.mark.asyncio
async def test_get_steps_returns_content_and_sha256(hmac_key, monkeypatch):
    steps_bytes = b"Sample steps framework content"
    expected_hash = hashlib.sha256(steps_bytes).hexdigest()

    def mock_handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/api/internal/v1/source-activations/act-steps-1/steps"
        return httpx.Response(
            200,
            content=steps_bytes,
            headers={"X-V3-Framework-SHA256": expected_hash},
        )

    transport = httpx.MockTransport(mock_handler)
    orig_client = httpx.AsyncClient
    monkeypatch.setattr(httpx, "AsyncClient", lambda **kw: orig_client(transport=transport, **kw))

    client = HttpLaravelNotebookClient(base_url="http://127.0.0.1:8000", hmac_key=hmac_key)
    content, sha256 = await client.get_steps("act-steps-1")
    assert content == steps_bytes
    assert sha256 == expected_hash


@pytest.mark.asyncio
async def test_stream_pdf_writes_securely(hmac_key, monkeypatch, tmp_path):
    pdf_bytes = b"%PDF-1.4 test stream content \x00\x01\x02\x03"
    expected_hash = hashlib.sha256(pdf_bytes).hexdigest()

    def mock_handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/api/internal/v1/source-activations/act-pdf-1/pdf"
        return httpx.Response(200, content=pdf_bytes)

    transport = httpx.MockTransport(mock_handler)
    orig_client = httpx.AsyncClient
    monkeypatch.setattr(httpx, "AsyncClient", lambda **kw: orig_client(transport=transport, **kw))

    client = HttpLaravelNotebookClient(base_url="http://127.0.0.1:8000", hmac_key=hmac_key)
    dest_path = tmp_path / "case_pdf.pdf"
    result = await client.stream_pdf("act-pdf-1", dest_path)

    assert dest_path.is_file()
    assert dest_path.read_bytes() == pdf_bytes
    assert result["sha256"] == expected_hash
    assert result["byte_count"] == len(pdf_bytes)
    assert dest_path.stat().st_mode & 0o777 == 0o600


@pytest.mark.asyncio
async def test_client_error_parsing(hmac_key, monkeypatch):
    def mock_handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            409,
            json={"error": {"code": "protocol_conflict", "message": "Expected cursor version mismatch"}},
        )

    transport = httpx.MockTransport(mock_handler)
    orig_client = httpx.AsyncClient
    monkeypatch.setattr(httpx, "AsyncClient", lambda **kw: orig_client(transport=transport, **kw))

    client = HttpLaravelNotebookClient(base_url="http://127.0.0.1:8000", hmac_key=hmac_key)
    with pytest.raises(LaravelClientError) as exc_info:
        await client.claim_lesson_query("case-1", "lesson_continue", 2, {"schema_version": 1})

    assert exc_info.value.status_code == 409
    assert exc_info.value.code == "protocol_conflict"
    assert "Expected cursor version mismatch" in exc_info.value.message
