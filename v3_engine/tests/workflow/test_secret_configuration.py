"""
Tests for explicit secret configuration and fail-closed behavior for Spec 007 / R02.
Covers D01: fail closed without authentication secrets.
"""

import time
import pytest
from httpx import ASGITransport, AsyncClient

from v3_engine.contracts import compute_hmac_signature
from v3_engine.bridge import app
from v3_engine.settings import EngineSettings, get_settings


def test_engine_settings_defaults_do_not_contain_published_default_secrets(monkeypatch):
    monkeypatch.delenv("V3_BRIDGE_HMAC_KEY", raising=False)
    monkeypatch.delenv("V3_CALLBACK_HMAC_KEY", raising=False)

    settings = get_settings()
    assert settings.bridge_hmac_key == ""
    assert settings.callback_hmac_key == ""


@pytest.mark.asyncio
async def test_absent_bridge_secret_fails_closed_with_401(monkeypatch):
    monkeypatch.delenv("V3_BRIDGE_HMAC_KEY", raising=False)

    path = "/internal/v1/health"
    now_ts = int(time.time())
    # Sign with former published default key
    sig = compute_hmac_signature("GET", path, now_ts, b"", "dev-bridge-hmac-key")
    headers = {
        "X-V3-Timestamp": str(now_ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
    }

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get(path, headers=headers)
        assert resp.status_code == 401
        assert resp.json()["error"]["code"] == "worker_unauthenticated"


@pytest.mark.asyncio
async def test_callback_secret_cannot_authenticate_bridge_direction(monkeypatch):
    bridge_secret = "configured-bridge-secret-999"
    callback_secret = "configured-callback-secret-888"

    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", bridge_secret)
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", callback_secret)

    path = "/internal/v1/health"
    now_ts = int(time.time())
    # Sign with callback secret instead of bridge secret
    sig = compute_hmac_signature("GET", path, now_ts, b"", callback_secret)
    headers = {
        "X-V3-Timestamp": str(now_ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
    }

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get(path, headers=headers)
        assert resp.status_code == 401
        assert resp.json()["error"]["code"] == "worker_unauthenticated"
