"""
Test-first regression suite for Python source request byte serialization and signing.
Governed by R03 (B01 - Python source byte serialization).
Ensures public JSON byte serialization and exact byte signing across source HTTP requests.
"""

import json
from types import SimpleNamespace
import pytest
import httpx

from v3_engine.contracts import compute_hmac_signature, verify_hmac_signature
from v3_engine.sources import notebooklm_adapter
from v3_engine.sources.notebooklm_adapter import HttpLaravelSourceClient
from v3_engine.activities import extract_case_source as extract_activity_module


@pytest.mark.asyncio
async def test_claim_effect_sends_exact_signed_bytes_with_unicode_and_nulls(monkeypatch):
    secret = "test-callback-secret-12345"
    captured_requests = []

    def handler(request: httpx.Request) -> httpx.Response:
        captured_requests.append(request)
        return httpx.Response(200, json={"data": {"effect_id": "eff_123", "state": "pending"}})

    transport = httpx.MockTransport(handler)

    client = HttpLaravelSourceClient(
        base_url="http://test/api/internal/v1",
        hmac_key=secret,
    )

    # Test claim_effect with Unicode Arabic and nested/null payload
    case_id = "case_مرحبا_1"
    operation = "notebook_create"
    activation_id = "act_456"
    request_digest = "sha256_digest_789"

    original_client = httpx.AsyncClient
    monkeypatch.setattr(
        notebooklm_adapter.httpx,
        "AsyncClient",
        lambda **kwargs: original_client(transport=transport, **kwargs),
    )
    res = await client.claim_effect(case_id, operation, activation_id, request_digest)

    assert res["effect_id"] == "eff_123"
    assert len(captured_requests) == 1
    req = captured_requests[0]

    # Verify that request.content is bytes and NOT a tuple
    assert isinstance(req.content, bytes)
    assert not isinstance(req.content, tuple)

    # Parse content as valid json
    body_data = json.loads(req.content.decode("utf-8"))
    assert body_data["schema_version"] == 1
    assert body_data["activation_id"] == activation_id
    assert body_data["request_digest"] == request_digest

    # Verify HMAC signature against exact bytes
    ts = req.headers["X-V3-Timestamp"]
    sig = req.headers["X-V3-Signature"]
    path = f"/api/internal/v1/source-effects/{case_id}/{operation}/claim"

    assert verify_hmac_signature("POST", path, ts, sig, req.content, secret) is True

    # One byte tampering must fail signature verification
    tampered_content = req.content[:-1] + (b"X" if req.content[-1:] != b"X" else b"Y")
    assert verify_hmac_signature("POST", path, ts, sig, tampered_content, secret) is False


@pytest.mark.asyncio
async def test_record_result_sends_exact_signed_bytes_with_unicode_and_nulls(monkeypatch):
    secret = "test-callback-secret-12345"
    captured_requests = []

    def handler(request: httpx.Request) -> httpx.Response:
        captured_requests.append(request)
        return httpx.Response(200, json={"data": {"effect_id": "eff_123", "state": "confirmed"}})

    transport = httpx.MockTransport(handler)

    client = HttpLaravelSourceClient(
        base_url="http://test/api/internal/v1",
        hmac_key=secret,
    )

    original_client = httpx.AsyncClient
    monkeypatch.setattr(
        notebooklm_adapter.httpx,
        "AsyncClient",
        lambda **kwargs: original_client(transport=transport, **kwargs),
    )
    res = await client.record_result(
        effect_id="eff_123",
        state="confirmed",
        provider_ref="notebook_مرحبا_99",
        proof_title="ملخص الدرس التعليمي",
        proof_sha256="abcde" * 12 + "1234",
        error_code=None,
    )

    assert res["state"] == "confirmed"
    assert len(captured_requests) == 1
    req = captured_requests[0]

    # Content must be exact bytes, not tuple
    assert isinstance(req.content, bytes)
    assert not isinstance(req.content, tuple)

    body_data = json.loads(req.content.decode("utf-8"))
    assert body_data["error_code"] is None
    assert body_data["proof_title"] == "ملخص الدرس التعليمي"

    ts = req.headers["X-V3-Timestamp"]
    sig = req.headers["X-V3-Signature"]
    path = "/api/internal/v1/source-effects/eff_123/result"

    assert verify_hmac_signature("POST", path, ts, sig, req.content, secret) is True

    # Tampered byte fails
    tampered_content = req.content[:-1] + (b"X" if req.content[-1:] != b"X" else b"Y")
    assert verify_hmac_signature("POST", path, ts, sig, tampered_content, secret) is False


@pytest.mark.asyncio
async def test_extraction_activity_signs_the_exact_utf8_bytes_it_posts(monkeypatch):
    secret = "test-callback-secret-12345"
    captured = []
    pdf_bytes = b"%PDF-test"

    async def handler(request: httpx.Request) -> httpx.Response:
        captured.append(request)
        if request.method == "GET":
            return httpx.Response(
                200,
                content=pdf_bytes,
                headers={"X-V3-Document-SHA256": __import__("hashlib").sha256(pdf_bytes).hexdigest()},
            )
        return httpx.Response(201, json={"data": {"extracted_source_ref": "src_123"}})

    original_client = httpx.AsyncClient
    transport = httpx.MockTransport(handler)
    monkeypatch.setattr(
        extract_activity_module.httpx,
        "AsyncClient",
        lambda **kwargs: original_client(transport=transport, **kwargs),
    )
    monkeypatch.setattr(
        extract_activity_module,
        "get_settings",
        lambda: SimpleNamespace(
            callback_hmac_key=secret,
            laravel_callback_url="http://test/api/internal/v1/case-events",
        ),
    )
    monkeypatch.setattr(
        extract_activity_module,
        "extract_pdf_source",
        lambda *_args, **_kwargs: SimpleNamespace(
            status="extracted",
            text="الدرس الأول",
            text_sha256=__import__("hashlib").sha256("الدرس الأول".encode()).hexdigest(),
            page_report=[SimpleNamespace(model_dump=lambda: {"page": 1, "status": "text"})],
            extractor_version="test-v1",
            page_count=1,
            error_code=None,
        ),
    )

    result = await extract_activity_module.extract_case_source(
        {"case_id": "case_123", "activation_id": "activation_123"}
    )

    assert result["extracted_source_ref"] == "src_123"
    assert len(captured) == 2
    request = captured[1]
    assert isinstance(request.content, bytes)
    assert "الدرس الأول".encode("utf-8") in request.content
    assert verify_hmac_signature(
        "POST",
        "/api/internal/v1/source-activations/activation_123/extraction",
        request.headers["X-V3-Timestamp"],
        request.headers["X-V3-Signature"],
        request.content,
        secret,
    )
