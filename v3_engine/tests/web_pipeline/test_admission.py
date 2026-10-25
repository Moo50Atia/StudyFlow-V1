"""Tests for signed bridge application-pipeline admission and supervisor integration (T023)."""

import hashlib
import json
import time
import uuid
import pytest
from httpx import ASGITransport, AsyncClient

from v3_engine.contracts import compute_hmac_signature
from v3_engine.bridge import app
from v3_engine.web_pipeline.artifact_refs import store_manifest
from v3_engine.web_pipeline.bridge import get_storage_root


SECRET = "test-suite-bridge-key"


def sign(method: str, path: str, body: dict | bytes = None, secret: str = SECRET) -> tuple[dict[str, str], bytes]:
    if body is None:
        raw_body = b""
    elif isinstance(body, bytes):
        raw_body = body
    else:
        raw_body = json.dumps(body, separators=(",", ":")).encode("utf-8")

    clean_path = "/" + path.lstrip("/").split("?")[0]
    ts = int(time.time())
    sig = compute_hmac_signature(method, clean_path, ts, raw_body, secret)
    headers = {
        "X-V3-Timestamp": str(ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
        "Content-Type": "application/json",
    }
    return headers, raw_body


@pytest.fixture(autouse=True)
def setup_bridge_secret(monkeypatch):
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", SECRET)


@pytest.mark.asyncio
@pytest.mark.parametrize('result,expected', [
    ({'outcome': 'blocked', 'safe_code': 'protocol_validation'}, 202),
    ({'outcome': 'uncertain', 'safe_code': 'delivery_uncertain'}, 409),
    ({'outcome': 'completed', 'safe_code': 'ok'}, 409),
])
async def test_explicit_retry_of_closed_validation_hold_preserves_input_and_rejects_uncertain_or_successful_runs(tmp_path, monkeypatch, result, expected):
    from types import SimpleNamespace
    from v3_engine.web_pipeline import bridge as module
    run, ref, command = str(uuid.uuid4()), str(uuid.uuid4()), str(uuid.uuid4())
    store_manifest(tmp_path, ref, {'run_id': run, 'items': []})
    updates = []
    class Handle:
        async def query(self, name, value):
            return None if name == 'get_submission_receipt_v1' else 'closed'
        async def describe(self):
            return SimpleNamespace(status=SimpleNamespace(name='COMPLETED'))
        async def result(self):
            return result
        async def execute_update(self, name, payload, id):
            updates.append(payload)
            return {'outcome': 'accepted'}
    class Client:
        def get_workflow_handle(self, name):
            return Handle()
    async def client():
        return Client()
    monkeypatch.setattr(module, '_get_temporal_client', client)
    monkeypatch.setattr(module, 'get_storage_root', lambda: tmp_path)
    monkeypatch.setattr(module, '_commands_store', {})
    payload = {'command_id': command, 'run_id': run, 'action': 'retry', 'expected_version': 1,
        'command_digest': hashlib.sha256(b'retry').hexdigest(), 'manifest_ref': ref}
    headers, body = sign('POST', '/internal/v1/application-pipeline/commands', payload)
    async with AsyncClient(transport=ASGITransport(app=app), base_url='http://test') as http:
        response = await http.post('/internal/v1/application-pipeline/commands', headers=headers, content=body)
    assert response.status_code == expected
    if expected == 202:
        assert len(updates) == 1 and updates[0]['retry'] is True and updates[0]['manifest_ref'] == ref
    else:
        assert updates == []


@pytest.mark.asyncio
async def test_admission_requires_valid_hmac():
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        # 1. No auth headers -> 401
        resp = await client.post(
            "/internal/v1/application-pipeline/submissions",
            json={"submission_id": str(uuid.uuid4())},
        )
        assert resp.status_code == 401

        # 2. Wrong secret -> 401
        headers, body = sign("POST", "/internal/v1/application-pipeline/submissions", {"dummy": 1}, secret="wrong-key")
        resp2 = await client.post(
            "/internal/v1/application-pipeline/submissions",
            headers=headers,
            content=body,
        )
        assert resp2.status_code == 401


@pytest.mark.asyncio
async def test_scene_qualification_starts_durable_workflow_idempotently(monkeypatch):
    from types import SimpleNamespace
    from v3_engine.web_pipeline import bridge as module
    command_id, run_id, case_id, candidate_ref = [str(uuid.uuid4()) for _ in range(4)]
    digest = hashlib.sha256(b"immutable qualification command").hexdigest()
    started = []

    class Client:
        async def start_workflow(self, workflow, payload, **kwargs):
            started.append((workflow, payload, kwargs))
            return object()

    async def temporal_client():
        return Client()

    monkeypatch.setattr(module, "_get_temporal_client", temporal_client)
    monkeypatch.setattr(module, "get_terminal_settings", lambda: SimpleNamespace(control_queue="control-test"))
    payload = {
        "command_id": command_id, "run_id": run_id, "case_id": case_id,
        "candidate_ref": candidate_ref, "command_digest": digest,
    }
    headers, body = sign("POST", "/internal/v1/application-pipeline/qualifications", payload)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post("/internal/v1/application-pipeline/qualifications", headers=headers, content=body)
    assert response.status_code == 202
    assert response.json() == {"outcome": "accepted", "command_id": command_id}
    assert len(started) == 1
    workflow, args, options = started[0]
    assert workflow.__qualname__ == "SceneQualificationWorkflow.run"
    assert args == payload
    assert options["id"] == f"scene-qualification-{command_id}"
    assert options["task_queue"] == "control-test"


@pytest.mark.asyncio
async def test_scene_qualification_rejects_invalid_payload_before_starting_workflow(monkeypatch):
    from v3_engine.web_pipeline import bridge as module
    async def temporal_client():
        raise AssertionError("invalid payload must not contact Temporal")
    monkeypatch.setattr(module, "_get_temporal_client", temporal_client)
    payload = {"command_id": "not-a-uuid"}
    headers, body = sign("POST", "/internal/v1/application-pipeline/qualifications", payload)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post("/internal/v1/application-pipeline/qualifications", headers=headers, content=body)
    assert response.status_code == 422


@pytest.mark.asyncio
async def test_admission_rejects_unknown_keys_and_oversized_payloads():
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        # Unknown key
        sub_id = str(uuid.uuid4())
        bad_payload = {
            "submission_id": sub_id,
            "submission_digest": hashlib.sha256(b"sub").hexdigest(),
            "run_id": str(uuid.uuid4()),
            "manifest_ref": str(uuid.uuid4()),
            "workflow_kind": "web_subject_v1",
            "forbidden_extra": "not_allowed",
        }
        headers, body = sign("POST", "/internal/v1/application-pipeline/submissions", bad_payload)
        resp = await client.post("/internal/v1/application-pipeline/submissions", headers=headers, content=body)
        assert resp.status_code == 422

        # Oversized payload (> 32 KiB)
        big_payload = {
            "submission_id": str(uuid.uuid4()),
            "submission_digest": hashlib.sha256(b"sub").hexdigest(),
            "run_id": str(uuid.uuid4()),
            "manifest_ref": str(uuid.uuid4()),
            "workflow_kind": "web_subject_v1",
        }
        headers_big, body_big = sign("POST", "/internal/v1/application-pipeline/submissions", big_payload)
        # Pad body beyond 32 KiB with spaces
        padded_body = body_big + b" " * 33000
        headers_big_padded, _ = sign("POST", "/internal/v1/application-pipeline/submissions", padded_body)
        resp_big = await client.post("/internal/v1/application-pipeline/submissions", headers=headers_big_padded, content=padded_body)
        assert resp_big.status_code == 422


@pytest.mark.asyncio
async def test_admission_does_not_claim_success_when_temporal_is_unavailable(monkeypatch):
    from v3_engine.web_pipeline import bridge as bridge_module

    async def temporal_unavailable():
        return None

    monkeypatch.setattr(bridge_module, "_get_temporal_client", temporal_unavailable)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        sub_id = str(uuid.uuid4())
        run_id = str(uuid.uuid4())
        manifest_ref = str(uuid.uuid4())
        digest = hashlib.sha256(b"manifest1").hexdigest()

        storage_root = get_storage_root()
        store_manifest(storage_root, manifest_ref, {"run_id": run_id, "mode": "export_manual", "items": []})

        valid_payload = {
            "submission_id": sub_id,
            "submission_digest": digest,
            "run_id": run_id,
            "manifest_ref": manifest_ref,
            "workflow_kind": "web_subject_v1",
        }

        # No supervisor means no accepted receipt. A local cache must not
        # turn an unavailable Temporal service into a false success.
        headers1, body1 = sign("POST", "/internal/v1/application-pipeline/submissions", valid_payload)
        resp1 = await client.post("/internal/v1/application-pipeline/submissions", headers=headers1, content=body1)
        assert resp1.status_code == 503
        assert resp1.json()["error"]["code"] == "storage_unavailable"

        # The same identity can be retried after recovery; this response is
        # still uncertain/unavailable rather than a fabricated queued state.
        headers2, body2 = sign("POST", "/internal/v1/application-pipeline/submissions", valid_payload)
        resp2 = await client.post("/internal/v1/application-pipeline/submissions", headers=headers2, content=body2)
        assert resp2.status_code == 503

        assert sub_id not in bridge_module._submissions_store


@pytest.mark.asyncio
async def test_command_endpoint_routes_active_and_queued_controls_to_temporal(monkeypatch):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        from v3_engine.web_pipeline import bridge as bridge_module

        class FakeHandle:
            def __init__(self, name, calls, state):
                self.name, self.calls, self.state = name, calls, state

            async def query(self, query_name, run_id):
                self.calls.append((self.name, "query", query_name, run_id))
                return self.state[run_id]

            async def execute_update(self, update_name, payload, id):
                self.calls.append((self.name, "update", update_name, payload, id))
                return {"outcome": "applied", "command_id": payload["command_id"]}

        class FakeClient:
            def __init__(self):
                self.calls = []
                self.state = {}

            def get_workflow_handle(self, name):
                return FakeHandle(name, self.calls, self.state)

        temporal = FakeClient()
        active_run, queued_run = str(uuid.uuid4()), str(uuid.uuid4())
        temporal.state.update({active_run: "active", queued_run: "queued"})

        async def get_client():
            return temporal

        monkeypatch.setattr(bridge_module, "_get_temporal_client", get_client)

        async def send_control(run_id, action):
            cmd_id = str(uuid.uuid4())
            digest = hashlib.sha256(f"{cmd_id}:{action}".encode()).hexdigest()
            payload = {
                "command_id": cmd_id, "run_id": run_id, "action": action,
                "expected_version": 1, "command_digest": digest,
            }
            headers, body = sign("POST", "/internal/v1/application-pipeline/commands", payload)
            return cmd_id, digest, await client.post("/internal/v1/application-pipeline/commands", headers=headers, content=body)

        # Active cancellation must signal the child workflow, not the queued supervisor path.
        cmd_id, cmd_digest, resp = await send_control(active_run, "cancel")
        assert resp.status_code == 202
        assert resp.json()["outcome"] == "applied"
        assert (f"v3-web-subject-{active_run}", "update", "control_v1") in [call[:3] for call in temporal.calls]
        assert not any(call[0] == "v3-terminal-supervisor" and call[1] == "update" for call in temporal.calls)

        # Cancellation of queued work must use supervisor's queued-cancel update.
        _, _, queued_resp = await send_control(queued_run, "cancel")
        assert queued_resp.status_code == 202
        assert any(call[0] == "v3-terminal-supervisor" and call[1] == "update" and call[2] == "cancel_queued_v1" for call in temporal.calls)

        # 3. Query the accepted receipt
        query_path = f"/internal/v1/application-pipeline/receipts/{cmd_id}"
        q_headers, _ = sign("GET", query_path)
        q_resp = await client.get(query_path, headers=q_headers)
        assert q_resp.status_code == 200
        q_data = q_resp.json()
        assert q_data["command_id"] == cmd_id
        assert q_data["digest"] == cmd_digest

        # 3. Query status
        status_path = f"/internal/v1/application-pipeline/runs/{active_run}/status"
        s_headers, _ = sign("GET", status_path)
        s_resp = await client.get(status_path, headers=s_headers)
        assert s_resp.status_code == 200
        assert "status" in s_resp.json()


@pytest.mark.asyncio
async def test_command_does_not_claim_success_when_temporal_is_unavailable(monkeypatch):
    from v3_engine.web_pipeline import bridge as bridge_module

    async def temporal_unavailable():
        return None

    monkeypatch.setattr(bridge_module, "_get_temporal_client", temporal_unavailable)
    cmd_id = str(uuid.uuid4())
    payload = {
        "command_id": cmd_id, "run_id": str(uuid.uuid4()), "action": "pause",
        "expected_version": 1, "command_digest": hashlib.sha256(b"pause").hexdigest(),
    }
    headers, body = sign("POST", "/internal/v1/application-pipeline/commands", payload)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post("/internal/v1/application-pipeline/commands", headers=headers, content=body)
    assert response.status_code == 503
    assert response.json()["error"]["code"] == "storage_unavailable"
    assert cmd_id not in bridge_module._commands_store


@pytest.mark.asyncio
async def test_admission_rejects_non_uuid_manifest_ref():
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        bad_payload = {
            "submission_id": str(uuid.uuid4()),
            "submission_digest": hashlib.sha256(b"sub").hexdigest(),
            "run_id": str(uuid.uuid4()),
            "manifest_ref": "invalid-uuid-format",
            "workflow_kind": "web_subject_v1",
        }
        headers, body = sign("POST", "/internal/v1/application-pipeline/submissions", bad_payload)
        resp = await client.post("/internal/v1/application-pipeline/submissions", headers=headers, content=body)
        assert resp.status_code == 422
        assert resp.json()["error"]["code"] == "contract_invalid"


@pytest.mark.asyncio
async def test_admission_rejects_missing_or_corrupt_manifest():
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        missing_manifest_ref = str(uuid.uuid4())
        payload = {
            "submission_id": str(uuid.uuid4()),
            "submission_digest": hashlib.sha256(b"sub").hexdigest(),
            "run_id": str(uuid.uuid4()),
            "manifest_ref": missing_manifest_ref,
            "workflow_kind": "web_subject_v1",
        }
        headers, body = sign("POST", "/internal/v1/application-pipeline/submissions", payload)
        resp = await client.post("/internal/v1/application-pipeline/submissions", headers=headers, content=body)
        assert resp.status_code in (404, 422)
        assert resp.json()["error"]["code"] in ("manifest_missing", "artifact_missing", "contract_invalid")


@pytest.mark.asyncio
async def test_command_rejects_unsupported_actions_unknown_fields_and_oversize():
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        # Unsupported action
        bad_action_payload = {
            "command_id": str(uuid.uuid4()),
            "run_id": str(uuid.uuid4()),
            "action": "unsupported_action_name",
            "expected_version": 1,
            "command_digest": hashlib.sha256(b"cmd").hexdigest(),
        }
        headers, body = sign("POST", "/internal/v1/application-pipeline/commands", bad_action_payload)
        resp = await client.post("/internal/v1/application-pipeline/commands", headers=headers, content=body)
        assert resp.status_code == 422
        assert resp.json()["error"]["code"] == "contract_invalid"

        # Unknown field
        unknown_field_payload = {
            "command_id": str(uuid.uuid4()),
            "run_id": str(uuid.uuid4()),
            "action": "pause",
            "expected_version": 1,
            "command_digest": hashlib.sha256(b"cmd").hexdigest(),
            "extra_field": "forbidden",
        }
        headers, body = sign("POST", "/internal/v1/application-pipeline/commands", unknown_field_payload)
        resp = await client.post("/internal/v1/application-pipeline/commands", headers=headers, content=body)
        assert resp.status_code == 422
        assert resp.json()["error"]["code"] == "contract_invalid"

        # Oversized command payload (> 32 KiB)
        big_cmd = {
            "command_id": str(uuid.uuid4()),
            "run_id": str(uuid.uuid4()),
            "action": "pause",
            "expected_version": 1,
            "command_digest": hashlib.sha256(b"cmd").hexdigest(),
        }
        headers_big, body_big = sign("POST", "/internal/v1/application-pipeline/commands", big_cmd)
        padded = body_big + b" " * 33000
        headers_padded, _ = sign("POST", "/internal/v1/application-pipeline/commands", padded)
        resp_big = await client.post("/internal/v1/application-pipeline/commands", headers=headers_padded, content=padded)
        assert resp_big.status_code == 422
        assert resp_big.json()["error"]["code"] == "contract_invalid"
