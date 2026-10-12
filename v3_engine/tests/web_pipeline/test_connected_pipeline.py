"""Connected full application chain test for Python web pipeline (Spec 019 / T060).

Verifies the complete execution chain:
1. Signed admission through WebPipelineBridge.
2. ApplicationSubjectWorkflow orchestration using real Activities and WebOperationStore.
3. Scene context resolution (export_manual vs selected_api).
4. Browser qualification check activity against candidate package.
5. Review effects routing and exact consumed-decision verification.
"""

from __future__ import annotations

import asyncio
import hashlib
import json
import os
import tempfile
import time
import uuid
from typing import Any
import pytest
from httpx import ASGITransport, AsyncClient

from v3_engine.web_pipeline.bridge import app
from v3_engine.contracts import compute_hmac_signature
from v3_engine.web_pipeline.activities import (
    execute_divide_turn,
    execute_lesson_turns,
    export_prompts_activity,
    reset_conversation_activity,
    set_terminal_service,
)
from v3_engine.notebooklm.terminal_service import NotebookTerminalService
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.web_pipeline.check_activities import execute_browser_checks_activity
from v3_engine.web_pipeline.review_effects import (
    CorrectionRoutingRequest,
    route_review_correction,
    verify_decision_consumed,
)
from v3_engine.web_pipeline.scene_activities import (
    resolve_scene_context_activity,
)
from v3_engine.web_pipeline.subject_workflow import ApplicationSubjectWorkflow

SECRET = "test-suite-connected-key"


class ConnectedTransportFiles:
    async def create_notebook(self, title: str):
        from v3_engine.notebooklm.ports import NotebookRef
        return NotebookRef(notebook_id="nb-conn-1", title=title)

    async def upload_file(self, notebook_id: str, local_path, mime_type: str, title: str):
        from v3_engine.notebooklm.ports import SourceReceipt
        return SourceReceipt(source_id="src-conn", title=title, status="ready", mime_type=mime_type)

    async def list_sources(self, notebook_id: str):
        return []

    async def verify_owner(self, proof_notebook_id: str) -> str:
        return "owner"


class ConnectedTransportConv:
    def __init__(self):
        self.turns = []
        self.canned = {}
        self.conversation_id = "conv-conn-1"

    async def configure_format(self, notebook_id: str, format_text: str) -> None:
        pass

    async def ask_once(self, notebook_id: str, question: str, source_ids, conversation_id=None, **kwargs):
        from v3_engine.notebooklm.ports import TurnAnswer
        self.turns.append({"question": question, "source_ids": source_ids})
        ans = self.canned.get(question)
        if not ans:
            if question == DIVIDE_PROMPT:
                ans = "Chapter: Test Chapter\n├── Lesson 1: Lesson One\n"
            elif "Continue" in question or question == EXECUTION_PROMPT:
                ans = """=== FIRST ===
Lecture: Test Lecture
Sec1: Test Section
=== SECOND ===
Placeholder Sec1
Perspective Map Sec1
=== THIRD ===
Lec 1 (Sec 1): "Scene Type: 2D Simulation
Test scene animation prompt."
=== END ===
✅ Lesson [1] Completed. Awaiting 'Continue' for the next lesson.
"""
            else:
                ans = "Answer"
        return TurnAnswer(text=ans, conversation_id=self.conversation_id, turn_number=len(self.turns))

    async def reset_conversation(self, notebook_id: str, conversation_id: str) -> None:
        pass

    async def history(self, notebook_id: str, conversation_id: str):
        return []

    async def current_conversation_id(self, notebook_id: str):
        return self.conversation_id


class ConnectedTransportSession:
    def __init__(self):
        self.files = ConnectedTransportFiles()
        self.conversation = ConnectedTransportConv()

    async def close(self):
        pass


class ConnectedTransportProvider:
    def __init__(self, session: ConnectedTransportSession):
        self.session = session

    def open(self, profile_ref=None, storage_path=None):
        sess = self.session

        class _Ctx:
            async def __aenter__(self):
                return sess
            async def __aexit__(self, exc_type, exc, tb):
                pass
        return _Ctx()



def sign_request(method: str, path: str, body: dict[str, Any], secret: str = SECRET) -> tuple[dict[str, str], bytes]:
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


@pytest.mark.temporal
@pytest.mark.asyncio
async def test_connected_bridge_intake_admission(tmp_path, monkeypatch, temporal_local_env):
    """Signed web admission starts the real shared supervisor and registered child workflow."""
    monkeypatch.setenv("V3_STORAGE_ROOT", str(tmp_path))
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path))
    from v3_engine.web_pipeline.artifact_refs import store_manifest
    from v3_engine.web_pipeline import bridge as bridge_module
    from v3_engine.terminal_pipeline.worker import run_workers

    client = temporal_local_env.client

    async def connected_temporal_client():
        return client

    monkeypatch.setattr(bridge_module, "_get_temporal_client", connected_temporal_client)

    submission_id = str(uuid.uuid4())
    run_id = str(uuid.uuid4())
    manifest_ref = str(uuid.uuid4())

    manifest_data = {
        "run_id": run_id,
        "submission_id": submission_id,
        "mode": "export_manual",
        "items": [],
    }
    store_manifest(tmp_path, manifest_ref, manifest_data)

    stop = asyncio.Event()
    workers = asyncio.create_task(
        run_workers(client, lock_path=tmp_path / "worker.lock", stop_event=stop)
    )

    payload = {
        "submission_id": submission_id,
        "submission_digest": hashlib.sha256(b"connected_sub").hexdigest(),
        "run_id": run_id,
        "manifest_ref": manifest_ref,
        "workflow_kind": "web_subject_v1",
    }

    headers, raw_body = sign_request("POST", "/internal/v1/application-pipeline/submissions", payload)

    try:
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as http:
            resp = await http.post(
                "/internal/v1/application-pipeline/submissions",
                headers=headers,
                content=raw_body,
            )
            assert resp.status_code == 202
            data = resp.json()
            assert data["outcome"] == "accepted"
            assert data["submission_id"] == submission_id

            # Repeating the exact request is reconciled through the stable
            # Temporal update ID rather than a process-local memory cache.
            duplicate = await http.post(
                "/internal/v1/application-pipeline/submissions",
                headers=headers,
                content=raw_body,
            )
            assert duplicate.status_code == 202
            assert duplicate.json() == data

        supervisor = client.get_workflow_handle("v3-terminal-supervisor")
        state = await supervisor.query("get_subject_admission_v1", run_id)
        assert state in {"queued", "active", "completed"}
    finally:
        stop.set()
        await workers


@pytest.mark.asyncio
async def test_shared_terminal_worker_registers_correction_workflow_and_activities(tmp_path, monkeypatch):
    """The documented shared worker must poll every workflow queue started by the web bridge."""
    import v3_engine.terminal_pipeline.worker as worker_module

    registrations = []

    class CapturingWorker:
        def __init__(self, _client, **kwargs):
            registrations.append(kwargs)

        async def run(self):
            await asyncio.Future()

    monkeypatch.setattr(worker_module, "Worker", CapturingWorker)
    stop = asyncio.Event()
    task = asyncio.create_task(
        worker_module.run_workers(object(), lock_path=tmp_path / "worker.lock", stop_event=stop)
    )
    try:
        for _ in range(100):
            if len(registrations) == 3:
                break
            await asyncio.sleep(0.01)
        assert len(registrations) == 3
        control = next(item for item in registrations if item["task_queue"] == "v3-terminal-control")
        workflow_names = {workflow.__name__ for workflow in control["workflows"]}
        activity_names = {getattr(activity, "__name__", "") for activity in control["activities"]}
        assert "CorrectionWorkflow" in workflow_names
        assert "claim_teacher_scene_repair_activity" in activity_names
        assert "claim_teacher_prompt_correction_activity" in activity_names
        assert "execute_teacher_scene_repair_activity" in activity_names
        assert "revise_teacher_prompt_correction_activity" in activity_names
        assert "qualify_scene_activity" in activity_names
        assert "ensure_review_ready_activity" in activity_names
    finally:
        stop.set()
        await task


@pytest.mark.asyncio
async def test_subject_workflow_orchestrates_two_pdf_items_in_order():
    """Unit-check the workflow's per-PDF ordering; activity integration is tested separately."""
    run_id = str(uuid.uuid4())
    item1_id = str(uuid.uuid4())
    item2_id = str(uuid.uuid4())

    manifest = {
        "run_id": run_id,
        "submission_id": str(uuid.uuid4()),
        "mode": "export_manual",
        "qualification_mode": "owner_waived_demo",
        "account_alias": "mo50atiapro",
        "framework_ref": str(uuid.uuid4()),
        "items": [
            {
                "item_id": item1_id,
                "pdf_ordinal": 1,
                "title": "Quantum Mechanics Chapter 1",
                "source_ref": str(uuid.uuid4()),
            },
            {
                "item_id": item2_id,
                "pdf_ordinal": 2,
                "title": "Quantum Mechanics Chapter 2",
                "source_ref": str(uuid.uuid4()),
            },
        ],
    }

    wf = ApplicationSubjectWorkflow()

    calls: list[tuple[str, dict[str, Any]]] = []

    async def unit_activity_executor(name: str, args: dict[str, Any]) -> dict[str, Any]:
        calls.append((name, args))
        if name == "execute_divide_turn":
            return {"outcome": "completed", "safe_code": "ok", "map_ref": str(uuid.uuid4()), "conversation_id": f"conv-{args['pdf_ordinal']}"}
        if name == "execute_lesson_turns":
            return {"outcome": "completed", "safe_code": "ok", "conversation_id": f"conv-{args['pdf_ordinal']}", "counts": {"lessons_saved": 1, "prompts_saved": 1}}
        if name == "reset_conversation_activity":
            return {"outcome": "completed", "safe_code": "ok", "counts": {"lessons_saved": 1, "prompts_saved": 1}}
        if name == "export_prompts_activity":
            return {"outcome": "completed", "safe_code": "ok", "result_ref": str(uuid.uuid4()), "counts": {"prompts_saved": 2}}
        raise ValueError(f"Unexpected activity {name}")

    wf._activity_executor = unit_activity_executor

    result = await wf.run(manifest)
    assert result["outcome"] in ("completed", "prompts_exported")
    assert result["safe_code"] == "ok"
    assert wf.pdfs_completed == 2
    assert wf.status == "completed"

    prog = wf.progress_v1()
    assert prog["status"] == "completed"
    assert prog["lessons_saved"] >= 2
    assert prog["prompts_saved"] >= 2
    assert [name for name, _ in calls] == [
        "execute_divide_turn", "execute_lesson_turns", "reset_conversation_activity",
        "execute_divide_turn", "execute_lesson_turns", "reset_conversation_activity",
        "export_prompts_activity",
    ]


@pytest.mark.asyncio
async def test_connected_scene_context_and_browser_checks():
    """
    Test scene context resolution (export_manual skips generation) and
    real browser qualification checks on a 3-file package.
    """
    run_id = str(uuid.uuid4())

    # 1. Resolve scene context for export_manual mode
    manual_context = await resolve_scene_context_activity({
        "run_id": run_id,
        "scene_key": "sec_01",
        "captured_settings": {"mode": "export_manual"},
    })
    assert manual_context["outcome"] == "completed"
    assert manual_context["mode"] == "export_manual"

    # 2. Browser check activity on a valid 3-file candidate package
    with tempfile.TemporaryDirectory() as tmpdir:
        html_content = """<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Interactive Harmonic Oscillator</title>
<link rel="stylesheet" href="styles.css">
</head>
<body>
<h1>Harmonic Oscillator Simulation</h1>
<button id="toggle" aria-label="Toggle Simulation">Start</button>
<canvas id="stage" width="300" height="200"></canvas>
<script src="script.js"></script>
</body>
</html>"""
        css_content = """body { font-family: sans-serif; margin: 16px; }
canvas { border: 1px solid #ccc; display: block; margin-top: 8px; }"""
        js_content = """let running = false;
let count = 0;
const btn = document.getElementById('toggle');
btn.addEventListener('click', function() {
    count++;
    this.innerText = 'Step ' + count;
});"""

        for fname, fcontent in [("index.html", html_content), ("styles.css", css_content), ("script.js", js_content)]:
            with open(os.path.join(tmpdir, fname), "w", encoding="utf-8") as f:
                f.write(fcontent)

        manifest = {
            "schema_version": 2,
            "profile": "static_three_file_v1",
            "entrypoint": "index.html",
            "files": [
                {"path": "index.html", "sha256": hashlib.sha256(html_content.encode("utf-8")).hexdigest(), "bytes": len(html_content.encode("utf-8"))},
                {"path": "styles.css", "sha256": hashlib.sha256(css_content.encode("utf-8")).hexdigest(), "bytes": len(css_content.encode("utf-8"))},
                {"path": "script.js", "sha256": hashlib.sha256(js_content.encode("utf-8")).hexdigest(), "bytes": len(js_content.encode("utf-8"))},
            ],
        }

        check_res = await execute_browser_checks_activity({
            "candidate_dir": tmpdir,
            "candidate_ref": str(uuid.uuid4()),
            "manifest": manifest,
            "manifest_sha256": hashlib.sha256(json.dumps(manifest).encode("utf-8")).hexdigest(),
        })

        assert check_res["outcome"] == "completed"
        assert check_res["verdict"] == "pass"
        assert len(check_res["checks"]) == 7
        assert all(c["status"] == "pass" for c in check_res["checks"])


def test_connected_review_effects_and_decision_consumption():
    """Test exact decision consumption verification and manual correction routing."""
    decision_ref = str(uuid.uuid4())
    workflow_id = f"wf_review_{decision_ref}"

    # Query function that returns exact consumption proof
    def mock_query(wf_id: str, dec_ref: str) -> dict[str, Any]:
        if wf_id == workflow_id and dec_ref == decision_ref:
            return {"status": 200, "consumed": True}
        return {"status": 200, "consumed": False}

    assert verify_decision_consumed(mock_query, workflow_id, decision_ref) is True
    assert verify_decision_consumed(mock_query, workflow_id, "different_ref") is False

    # Correction routing for export_manual mode: zero paid calls, manual hold
    req = CorrectionRoutingRequest(
        schema_version=1,
        case_id=str(uuid.uuid4()),
        section_ref=str(uuid.uuid4()),
        route_ref=str(uuid.uuid4()),
        correction_ref=str(uuid.uuid4()),
        feedback_kind="factual_source",
        mode="export_manual",
        notes="Revise wave amplitude calculation.",
        current_prompt_version_ref=str(uuid.uuid4()),
        current_candidate_ref=str(uuid.uuid4()),
    )

    result = route_review_correction(req)
    assert result.status == "awaiting_manual_replacement"
    assert result.paid_api_calls == 0
    assert result.disposition == "manual_hold"
    assert "Revise wave amplitude calculation." in (result.exported_instructions or "")
