"""
Shared Bridge and Callback Signing Vector Tests for Spec 007 / R01.
Verifies cross-language signing consistency between Laravel and Python.
"""

import time
import pytest
from httpx import ASGITransport, AsyncClient

from v3_engine.contracts import compute_hmac_signature, verify_hmac_signature
from v3_engine.bridge import app


def test_vector_1_empty_body_get():
    method = "GET"
    path = "/internal/v1/health"
    timestamp = 1700000000
    secret = "test-secret-key-12345"
    raw_body = b""

    sig = compute_hmac_signature(method, path, timestamp, raw_body, secret)
    assert sig == "6f611383ab8f82e063acfc362db301204d9e9f79f903fa3220ecbbebe78cefdb"

    # Verify helper accepts exact vector
    assert verify_hmac_signature(
        method, path, str(timestamp), sig, raw_body, secret, now=timestamp
    ) is True


def test_vector_2_utf8_arabic_post_with_query_canonicalization():
    method = "POST"
    # Query string must be stripped before canonical signing
    raw_path = "/api/internal/v1/case-events?ignored_query=1"
    clean_path = "/" + raw_path.lstrip("/").split("?")[0]
    timestamp = 1700000000
    secret = "test-secret-key-12345"
    raw_body = '{"message":"مرحبا بالعالم","count":42}'.encode("utf-8")

    sig = compute_hmac_signature(method, clean_path, timestamp, raw_body, secret)
    assert sig == "6a7db59da799b8fece525612e54d393fe32ce82659cec52fd4c67dde811177d6"

    # Verify helper accepts exact vector
    assert verify_hmac_signature(
        method, clean_path, str(timestamp), sig, raw_body, secret, now=timestamp
    ) is True


def test_tampered_body_rejection():
    method = "POST"
    path = "/internal/v1/workflows/start"
    timestamp = 1700000000
    secret = "test-secret-key-12345"
    raw_body = b'{"case_id":"1111"}'
    tampered_body = b'{"case_id":"2222"}'

    sig = compute_hmac_signature(method, path, timestamp, raw_body, secret)

    assert verify_hmac_signature(
        method, path, str(timestamp), sig, tampered_body, secret, now=timestamp
    ) is False


def test_expired_timestamp_rejection():
    method = "GET"
    path = "/internal/v1/health"
    secret = "test-secret-key-12345"
    raw_body = b""
    old_ts = 1700000000
    current_ts = old_ts + 301  # Exceeds 300 second tolerance

    sig = compute_hmac_signature(method, path, old_ts, raw_body, secret)

    assert verify_hmac_signature(
        method, path, str(old_ts), sig, raw_body, secret, now=current_ts
    ) is False


@pytest.mark.asyncio
async def test_bridge_middleware_accepts_valid_signature_and_rejects_tampering(monkeypatch):
    secret = "configured-test-bridge-key"
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", secret)
    now_ts = int(time.time())
    path = "/internal/v1/health"
    raw_body = b""

    sig = compute_hmac_signature("GET", path, now_ts, raw_body, secret)
    headers = {
        "X-V3-Timestamp": str(now_ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
    }

    class MockServiceClient:
        async def check_health(self):
            pass

    class MockClient:
        service_client = MockServiceClient()

    app.state.temporal_client = MockClient()

    try:
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            # Valid signature succeeds
            resp = await client.get(path, headers=headers)
            assert resp.status_code == 200

            # Tampered signature fails 401
            bad_headers = dict(headers)
            bad_headers["X-V3-Signature"] = "0" * 64
            resp_bad = await client.get(path, headers=bad_headers)
            assert resp_bad.status_code == 401
            assert resp_bad.json()["error"]["code"] == "worker_unauthenticated"
    finally:
        app.state.temporal_client = None

