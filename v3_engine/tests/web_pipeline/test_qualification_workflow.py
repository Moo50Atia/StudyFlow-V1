"""Durable dispatch and result handling for local-worker scene qualification."""

from __future__ import annotations

import base64
import hashlib
import json
import uuid
from contextlib import AsyncExitStack

import pytest
from temporalio.exceptions import ApplicationError
from temporalio.worker import Worker

from v3_engine.web_pipeline.qualification_workflow import SceneQualificationWorkflow


def _qualification_package(*, broken: bool) -> list[dict[str, str]]:
    script = (
        "console.error('qualification fixture error');"
        if broken else
        "let count = 0; document.getElementById('toggle').addEventListener('click', function() { count++; this.innerText = count % 2 ? 'Started' : 'Paused'; });"
    )
    return [
        {"path": "index.html", "content": """<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Scene qualification</title>
<link rel="stylesheet" href="styles.css"></head><body><h1>Scene</h1>
<button id="toggle" aria-label="Start scene">Start</button><script src="script.js"></script>
</body></html>"""},
        {"path": "styles.css", "content": "body { font-family: sans-serif; }"},
        {"path": "script.js", "content": script},
    ]


@pytest.mark.temporal
@pytest.mark.asyncio
@pytest.mark.parametrize(("broken", "transient_storage_failure", "expected_verdict"), [
    (False, False, "pass"),
    (True, False, "fail"),
    (False, True, "pass"),
])
async def test_scene_qualification_workflow_runs_real_browser_and_records_evidence(
    temporal_local_env, monkeypatch, broken, transient_storage_failure, expected_verdict
):
    from v3_engine.web_pipeline.application_client import ApplicationPipelineError

    run_id, case_id, candidate_ref, command_id = [str(uuid.uuid4()) for _ in range(4)]
    attempt_ref, check_ref = str(uuid.uuid4()), str(uuid.uuid4())
    files = _qualification_package(broken=broken)
    manifest = {
        "schema_version": 2, "profile": "static_three_file_v1", "entrypoint": "index.html",
        "files": [
            {"path": f["path"], "bytes": len(f["content"].encode()), "sha256": hashlib.sha256(f["content"].encode()).hexdigest()}
            for f in files
        ],
    }
    manifest_bytes = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()
    manifest_sha = hashlib.sha256(manifest_bytes).hexdigest()
    state = {"get_calls": 0, "claim_calls": 0, "persisted": None, "candidate_status": "awaiting_check"}

    class ApplicationClientDouble:
        def __init__(self, timeout=60.0):
            pass

        async def get_scene_candidate(self, _case_id, _candidate_ref):
            state["get_calls"] += 1
            if transient_storage_failure and state["get_calls"] == 1:
                raise ApplicationPipelineError(503, "authoring_unavailable", "private artifact storage unavailable")
            return {"data": {
                "manifest": manifest, "files": files, "manifest_sha256": manifest_sha,
                "manifest_raw_base64": base64.b64encode(manifest_bytes).decode(),
            }}

        async def claim_scene_check(self, _case_id, _candidate_ref, _attempt):
            state["claim_calls"] += 1
            return {"data": {"attempt_ref": attempt_ref, "disposition": "claimed"}}

        async def record_scene_check(self, _case_id, _attempt_ref, payload):
            report_bytes = payload["report_json"].encode("utf-8")
            assert hashlib.sha256(report_bytes).hexdigest() == payload["report_sha256"]
            screenshot = base64.b64decode(payload["screenshot_png_base64"], validate=True)
            assert screenshot.startswith(b"\x89PNG\r\n\x1a\n")
            assert hashlib.sha256(screenshot).hexdigest() == payload["screenshot_sha256"]
            report = json.loads(payload["report_json"])
            verdict = "pass" if all(check["status"] == "pass" for check in report["checks"]) else "fail"
            state["persisted"] = payload
            state["candidate_status"] = "awaiting_jev" if verdict == "pass" else "repair_pending"
            return {"data": {"state": verdict, "check_ref": check_ref}}

    from v3_engine.web_pipeline import check_activities
    monkeypatch.setattr(check_activities, "ApplicationClient", ApplicationClientDouble)

    client = temporal_local_env.client
    workers = [
        Worker(client, task_queue="v3-terminal-control", workflows=[SceneQualificationWorkflow]),
        Worker(client, task_queue="v3-terminal-build", activities=[check_activities.qualify_authoring_candidate_activity]),
    ]
    async with AsyncExitStack() as stack:
        for worker in workers:
            await stack.enter_async_context(worker)
        handle = await client.start_workflow(
            SceneQualificationWorkflow.run,
            {"command_id": command_id, "command_digest": "a" * 64, "run_id": run_id, "case_id": case_id, "candidate_ref": candidate_ref},
            id=f"scene-qualification-test-{command_id}",
            task_queue="v3-terminal-control",
        )
        result = await handle.result()

    assert result["outcome"] == "completed"
    assert result["verdict"] == expected_verdict, json.dumps(json.loads(state["persisted"]["report_json"])["checks"], indent=2)
    assert result["candidate_ref"] == candidate_ref
    assert state["persisted"]["report_sha256"]
    assert state["persisted"]["screenshot_sha256"]
    assert state["candidate_status"] == ("awaiting_jev" if expected_verdict == "pass" else "repair_pending")
    assert state["get_calls"] == (2 if transient_storage_failure else 1)
    assert state["claim_calls"] == 1
    report = json.loads(state["persisted"]["report_json"])
    checks = {check["code"]: check["status"] for check in report["checks"]}
    assert checks["runtime"] == ("fail" if broken else "pass")


@pytest.mark.asyncio
async def test_qualification_activity_checks_and_records_candidate(monkeypatch):
    from v3_engine.web_pipeline import check_activities

    candidate_ref, case_id, run_id, attempt_ref, check_ref = [str(uuid.uuid4()) for _ in range(5)]
    files = [
        {"path": "index.html", "content": "<html><body><h1>Lesson</h1></body></html>"},
        {"path": "styles.css", "content": "body { color: #123; }"},
        {"path": "script.js", "content": "document.body.dataset.ready = 'yes';"},
    ]
    manifest = {
        "schema_version": 2,
        "profile": "static_three_file_v1",
        "entrypoint": "index.html",
        "files": [
            {"path": f["path"], "bytes": len(f["content"].encode()), "sha256": hashlib.sha256(f["content"].encode()).hexdigest()}
            for f in files
        ],
    }
    manifest_bytes = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()
    manifest_sha = hashlib.sha256(manifest_bytes).hexdigest()
    screenshot = b"\x89PNG\r\n\x1a\nfixture"

    class ApplicationClientDouble:
        def __init__(self, timeout=60.0):
            self.persisted = None

        async def get_scene_candidate(self, _case_id, _candidate_ref):
            return {"data": {
                "manifest": manifest, "files": files, "manifest_sha256": manifest_sha,
                "manifest_raw_base64": base64.b64encode(manifest_bytes).decode(),
            }}

        async def claim_scene_check(self, _case_id, _candidate_ref, _attempt):
            return {"data": {"attempt_ref": attempt_ref, "disposition": "claimed"}}

        async def record_scene_check(self, _case_id, _attempt_ref, payload):
            self.persisted = payload
            return {"data": {"state": "pass", "check_ref": check_ref}}

    async def browser_checks(**kwargs):
        assert kwargs["manifest_sha256"] == manifest_sha
        assert (kwargs["candidate_dir"])
        return {
            "checks": [{"code": "integrity", "status": "pass"}],
            "screenshot_png_base64": base64.b64encode(screenshot).decode(),
            "screenshot_sha256": hashlib.sha256(screenshot).hexdigest(),
        }

    monkeypatch.setattr(check_activities, "ApplicationClient", ApplicationClientDouble)
    monkeypatch.setattr(check_activities, "run_browser_checks", browser_checks)
    result = await check_activities.qualify_authoring_candidate_activity({
        "run_id": run_id, "case_id": case_id, "candidate_ref": candidate_ref,
    })

    assert result["outcome"] == "completed"
    assert result["verdict"] == "pass"
    assert result["candidate_ref"] == candidate_ref
    assert result["report_sha256"]


@pytest.mark.asyncio
async def test_qualification_activity_rejects_corrupt_package_before_check_claim(monkeypatch):
    from v3_engine.web_pipeline import check_activities

    candidate_ref, case_id, run_id = [str(uuid.uuid4()) for _ in range(3)]
    content = "<html>changed</html>"
    manifest = {
        "schema_version": 2, "profile": "static_three_file_v1", "entrypoint": "index.html",
        "files": [
            {"path": "index.html", "bytes": 1, "sha256": "0" * 64},
            {"path": "styles.css", "bytes": 1, "sha256": "1" * 64},
            {"path": "script.js", "bytes": 1, "sha256": "2" * 64},
        ],
    }

    class ApplicationClientDouble:
        claimed = False

        def __init__(self, timeout=60.0):
            pass

        async def get_scene_candidate(self, _case_id, _candidate_ref):
            return {"data": {"manifest": manifest, "files": [
                {"path": "index.html", "content": content},
                {"path": "styles.css", "content": "x"},
                {"path": "script.js", "content": "x"},
            ], "manifest_sha256": "3" * 64}}

        async def claim_scene_check(self, *_args):
            self.claimed = True
            raise AssertionError("corrupt candidate must not claim a check attempt")

    monkeypatch.setattr(check_activities, "ApplicationClient", ApplicationClientDouble)
    result = await check_activities.qualify_authoring_candidate_activity({
        "run_id": run_id, "case_id": case_id, "candidate_ref": candidate_ref,
    })
    assert result["outcome"] == "failed"
    assert result["safe_code"] == "artifact_corrupt"


@pytest.mark.asyncio
async def test_qualification_activity_keeps_candidate_held_when_storage_is_unavailable(monkeypatch):
    from v3_engine.web_pipeline import check_activities
    from v3_engine.web_pipeline.application_client import ApplicationPipelineError

    class UnavailableApplicationClient:
        def __init__(self, timeout=60.0):
            pass

        async def get_scene_candidate(self, _case_id, _candidate_ref):
            raise ApplicationPipelineError(503, "authoring_unavailable", "private artifact storage unavailable")

    monkeypatch.setattr(check_activities, "ApplicationClient", UnavailableApplicationClient)
    with pytest.raises(ApplicationError) as error:
        await check_activities.qualify_authoring_candidate_activity({
            "run_id": str(uuid.uuid4()),
            "case_id": str(uuid.uuid4()),
            "candidate_ref": str(uuid.uuid4()),
        })
    assert error.value.type == "qualification_temporarily_unavailable"
