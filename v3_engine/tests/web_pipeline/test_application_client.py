"""Tests for HMAC-signed ApplicationClient (T073)."""

import json
import uuid
import pytest
import httpx

from v3_engine.web_pipeline.application_client import ApplicationClient, ApplicationPipelineError


@pytest.mark.asyncio
async def test_application_client_signs_and_invokes_endpoints():
    captured_requests = []

    def mock_handler(request: httpx.Request) -> httpx.Response:
        captured_requests.append(request)
        path = request.url.path
        if "/claims" in path:
            return httpx.Response(201, json={"status": 201, "lease_token": "lease-123", "context_ref": "ctx-123"})
        if "/results" in path:
            return httpx.Response(201, json={"status": 201, "outcome": "completed", "state_version": 2})
        if "/manifests/" in path:
            return httpx.Response(200, json={"schema_version": 1, "items": []})
        return httpx.Response(404, json={"error": {"code": "not_found", "message": "Unknown path"}})

    transport = httpx.MockTransport(mock_handler)
    client = ApplicationClient(base_url="http://testserver", hmac_key="test-key")

    # Monkeypatch transport in _request
    original_request = client._request

    async def patched_request(method, path, body=None):
        clean_path = "/" + path.lstrip("/").split("?")[0]
        if body is None:
            raw_body = b""
        elif isinstance(body, bytes):
            raw_body = body
        else:
            raw_body = json.dumps(body, separators=(",", ":")).encode("utf-8")

        headers = client._sign_headers(method, clean_path, raw_body)
        url = f"{client.base_url}{clean_path}"

        async with httpx.AsyncClient(transport=transport) as hc:
            resp = await hc.request(method=method, url=url, headers=headers, content=raw_body)
        if resp.status_code >= 400:
            raise ApplicationPipelineError(resp.status_code, "err", resp.text)
        return resp

    client._request = patched_request

    # 1. Claim
    claim_resp = await client.claim({"operation_id": "op-1", "run_id": "run-1", "request_sha256": "sha"})
    assert claim_resp["lease_token"] == "lease-123"

    # 2. Commit
    commit_resp = await client.commit({"operation_id": "op-1", "lease_token": "lease-123", "result_sha256": "res"})
    assert commit_resp["outcome"] == "completed"

    # 3. Get manifest
    man_resp = await client.get_manifest("man-123")
    assert man_resp["schema_version"] == 1

    # Verify HMAC headers were sent
    assert len(captured_requests) == 3
    for req in captured_requests:
        assert "X-V3-Timestamp" in req.headers
        assert "X-V3-Signature" in req.headers
        assert req.headers["X-V3-Contract-Version"] == "1"
