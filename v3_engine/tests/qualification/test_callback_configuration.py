"""Scene callback clients use the worker's configured Laravel callback contract."""

import httpx
import pytest

from v3_engine.contracts import verify_hmac_signature
from v3_engine.qualification import activity
from v3_engine.qualification.repair import SceneRepairClient
from v3_engine.settings import EngineSettings


def test_qualification_factories_derive_origin_and_share_callback_key(monkeypatch):
    configured = EngineSettings(
        callback_hmac_key="one-configured-callback-secret",
        laravel_callback_url="http://laravel.local/api/internal/v1/case-events",
    )
    monkeypatch.setattr(activity, "get_settings", lambda: configured)

    check = activity.get_check_client()
    repair = activity.get_repair_client()

    assert check.base_url == "http://laravel.local"
    assert repair.base_url == "http://laravel.local"
    assert check.hmac_key == configured.callback_hmac_key
    assert repair.hmac_key == configured.callback_hmac_key


def test_qualification_factories_fail_closed_without_callback_secret(monkeypatch):
    configured = EngineSettings(
        callback_hmac_key="",
        laravel_callback_url="http://laravel.local/api/internal/v1/case-events",
    )
    monkeypatch.setattr(activity, "get_settings", lambda: configured)

    with pytest.raises(RuntimeError, match="V3_CALLBACK_HMAC_KEY"):
        activity.get_check_client()
    with pytest.raises(RuntimeError, match="V3_CALLBACK_HMAC_KEY"):
        activity.get_repair_client()


def test_callback_url_must_be_absolute_case_events_endpoint():
    with pytest.raises(ValueError, match="V3_LARAVEL_CALLBACK_URL"):
        EngineSettings(
            callback_hmac_key="secret",
            laravel_callback_url="http://laravel.local/api/internal/v1",
        ).laravel_api_origin


@pytest.mark.asyncio
async def test_check_and_repair_http_requests_use_configured_origin_and_signature(monkeypatch):
    secret = "one-configured-callback-secret"
    captured = []

    def handler(request: httpx.Request) -> httpx.Response:
        captured.append(request)
        return httpx.Response(200, json={"data": {"state": "claimed"}})

    transport = httpx.MockTransport(handler)
    original_client = httpx.AsyncClient
    monkeypatch.setattr(
        activity.httpx,
        "AsyncClient",
        lambda **kwargs: original_client(transport=transport, **kwargs),
    )
    import v3_engine.qualification.repair as repair_module

    monkeypatch.setattr(
        repair_module.httpx,
        "AsyncClient",
        lambda **kwargs: original_client(transport=transport, **kwargs),
    )

    check = activity.SceneCheckClient("http://laravel.local", secret)
    repair = SceneRepairClient("http://laravel.local", secret)
    await check._request("POST", "/api/internal/v1/cases/case-1/scene-check-attempts", {"candidate_ref": "cand-1"})
    await repair._request("POST", "/api/internal/v1/cases/case-1/scene-repairs", {"failed_candidate_ref": "cand-1"})

    assert [request.url.path for request in captured] == [
        "/api/internal/v1/cases/case-1/scene-check-attempts",
        "/api/internal/v1/cases/case-1/scene-repairs",
    ]
    for request in captured:
        assert request.url.host == "laravel.local"
        assert verify_hmac_signature(
            request.method,
            request.url.path,
            request.headers["X-V3-Timestamp"],
            request.headers["X-V3-Signature"],
            request.content,
            secret,
        )
