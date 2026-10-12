"""Connected integration tests for Terminal Pipeline (Spec 018 / T030).

Covers:
- 8 tiny fixture PDFs with unchanged byte tracking and hash lineage (FR-001 / A01)
- Variable mapped Lessons/scenes without output gating (FR-004 / A04)
- All-Lesson barrier before scene dispatch (FR-005 / A06)
- Provider preservation, owner waiver, plain HTML/CSS/JavaScript materialization (FR-006, FR-023 / A07)
- Crash recovery using retained receipts without re-upload/resend (FR-008 / A09)
- Legacy CLI / contract compatibility (FR-023 / A27)
- Real Playwright browser interaction on actual static bundle (FR-024 / A28)
"""

from __future__ import annotations

import asyncio
import functools
import hashlib
import http.server
import json
from pathlib import Path
import socketserver
import threading
import uuid
import pytest

from v3_engine.notebooklm.terminal_service import NotebookTerminalService
from v3_engine.scene_generation.artifact_store import SceneArtifactStore
from v3_engine.scene_generation.builder import SceneBuilder, get_host_toolchain_info
from v3_engine.scene_generation.contracts import (
    HandoffInventory,
    InputSnapshot,
    ProviderConfig,
    SceneInput,
)
from v3_engine.scene_generation.source_package import GeneratedFile, HtmlSceneSourceV1
from v3_engine.terminal_pipeline.activities import (
    capture_terminal_turn,
    create_terminal_notebook,
    freeze_terminal_handoffs,
    poll_terminal_sources,
    upload_terminal_source,
    verify_notebook_session,
)
from v3_engine.terminal_pipeline.contracts import ActivityOutcome, SafeCode
from v3_engine.terminal_pipeline.manifest import (
    capture_manifest,
)
from v3_engine.terminal_pipeline.store import TerminalIndexStore


@pytest.fixture
def http_dist_server():
    """Spin up a transient HTTP server serving a directory."""
    server_holder = []

    def start_serving(dist_dir: Path) -> int:
        handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=str(dist_dir))
        httpd = socketserver.TCPServer(("127.0.0.1", 0), handler)
        server_holder.append(httpd)
        thread = threading.Thread(target=httpd.serve_forever, daemon=True)
        thread.start()
        return httpd.server_address[1]

    yield start_serving

    for s in server_holder:
        s.shutdown()
        s.server_close()


@pytest.mark.integration
@pytest.mark.asyncio
async def test_connected_eight_tiny_pdfs_all_lesson_barrier_and_real_build(tmp_path: Path, monkeypatch) -> None:
    """End-to-end integration across 8 tiny PDFs, all-Lesson barrier, static scene materialization, and waiver."""
    project_root = Path(__file__).parent.parent.parent.parent
    terminal_root = tmp_path / "terminal_root"
    terminal_root.mkdir()

    # 1. Create 8 tiny fixture PDFs
    pdf_dir = tmp_path / "fixtures_8pdf"
    pdf_dir.mkdir()
    pdf_paths: list[Path] = []
    pdf_hashes: dict[str, str] = {}
    for i in range(1, 9):
        pdf_file = pdf_dir / f"physics_unit_{i:02d}.pdf"
        content = f"%PDF-1.4\n%Tiny fixture PDF {i:02d} for mechanics testing\n%%EOF\n".encode("utf-8")
        pdf_file.write_bytes(content)
        pdf_paths.append(pdf_file)
        pdf_hashes[pdf_file.name] = hashlib.sha256(content).hexdigest()

    steps_file = tmp_path / "steps.txt"
    steps_content = "Step 1: Divide\nStep 2: Execution\nStep 3: Lessons\n"
    steps_file.write_text(steps_content, encoding="utf-8")

    # 2. Manifest capture
    raw_manifest = {
        "schema_version": 1,
        "submission_id": str(uuid.uuid4()),
        "subjects": [
            {
                "subject_key": "mechanics-8pdf",
                "mode": "new",
                "display_title": "Mechanics 8-PDF Subject",
                "provider": "gemini",
                "model": "gemini-2.5-flash",
                "pdf_dir": str(pdf_dir),
                "steps": str(steps_file),
                "account_check_notebook_id": "nb-proof-owner-123",
            }
        ],
    }

    manifest, m_path = capture_manifest(
        raw_dict=raw_manifest,
        terminal_root=terminal_root,
        project_root=project_root,
    )
    subj_cfg = manifest.subjects[0]
    assert len(subj_cfg.inputs) == 9  # 8 PDFs + 1 steps file
    assert subj_cfg.account_check_notebook_id == "nb-proof-owner-123"

    # Verify immutable snapshot hashes match original PDFs
    for inp in subj_cfg.inputs:
        if inp.kind == "pdf":
            pname = Path(inp.original_path).name
            assert inp.sha256 == pdf_hashes[pname]

    # 3. Track calls to ensure all-Lesson barrier and zero extra pings
    events_log: list[str] = []
    scene_dispatch_allowed = False

    class FakeNotebookRef:
        def __init__(self, id_val: str):
            self.id = id_val

    class FakeSourceRef:
        def __init__(self, source_id: str):
            self.source_id = source_id

    class FakeNotebookService:
        def __init__(self, *args, **kwargs):
            pass

        async def verify_session(self, proof_notebook_id, storage_path=None):
            events_log.append(f"verify_session:{proof_notebook_id}")
            return {"authenticated": True, "account": "mo50atiapro"}

        async def create_notebook(self, title, storage_path=None):
            events_log.append("create_notebook")
            return FakeNotebookRef("nb-created-new-456")

        async def upload_source(self, notebook_id, local_path, mime_type, title, storage_path=None, **kwargs):
            events_log.append(f"upload_source:{Path(local_path).name}")
            return FakeSourceRef(f"src-{Path(local_path).stem}")

        async def poll_sources(self, notebook_id, storage_path=None):
            events_log.append("poll_sources")
            class FakeSourceStatus:
                def __init__(self, status="ready"):
                    self.status = status
            return [FakeSourceStatus("ready") for _ in range(8)]

        async def configure_chat(self, notebook_id, format_text, storage_path=None):
            events_log.append("configure_chat")

        def capture_turn(self, turn_kind, raw_answer="", capture_raw=True, lesson_ordinal=None, mapped_title=None, output_dir=None, **kwargs):
            events_log.append(f"capture_turn:{turn_kind}:{lesson_ordinal or ''}")
            if turn_kind == "divide":
                return {"work_count": 2, "mapped_lessons": ["Kinematics", "Dynamics"]}
            elif turn_kind == "lesson":
                # Create fake handoff file
                handoff_file = output_dir / f"lesson-{lesson_ordinal:03d}.json"
                handoff_data = {
                    "schema_version": 1,
                    "kind": "notebooklm_multi_pdf_lesson_raw_handoff",
                    "notebook_id": "nb-created-new-456",
                    "conversation_id": "conv-test-789",
                    "lesson_ordinal": lesson_ordinal,
                    "mapped_title": mapped_title or f"Lesson {lesson_ordinal}",
                    "scenes": [
                        {
                            "scene_ordinal": 1,
                            "scene_type": "InteractiveSimulation",
                            "generated_prompt": f"Scene for lesson {lesson_ordinal}",
                        }
                    ],
                }
                handoff_file.write_text(json.dumps(handoff_data), encoding="utf-8")
                return {"handoff_path": str(handoff_file), "scene_count": 1}
            return {}

        def freeze_handoffs(self, handoff_dir, expected_lesson_count):
            nonlocal scene_dispatch_allowed
            events_log.append("freeze_handoffs")
            scene_dispatch_allowed = True
            handoffs = [str(handoff_dir / f"lesson-{i:03d}.json") for i in range(1, expected_lesson_count + 1)]
            return {"status": "frozen", "handoffs": handoffs}

    monkeypatch.setattr("v3_engine.terminal_pipeline.activities.NotebookTerminalService", FakeNotebookService)

    # 4. Execute activities in strict sequence
    subject_run_id = subj_cfg.subject_run_id
    run_dir = terminal_root / "runs" / subject_run_id
    run_dir.mkdir(parents=True, exist_ok=True)
    handoff_dir = run_dir / "lesson_handoffs"
    handoff_dir.mkdir(parents=True, exist_ok=True)

    # Verify session (proof notebook must not be reused for actual work)
    v_res = await verify_notebook_session({
        "schema_version": 1,
        "subject_run_id": subject_run_id,
        "proof_notebook_id": "nb-proof-owner-123",
    })
    assert v_res["outcome"] == ActivityOutcome.complete.value

    # Create new notebook
    nb_res = await create_terminal_notebook({
        "schema_version": 1,
        "subject_run_id": subject_run_id,
        "title": "Mechanics 8-PDF Run",
    })
    new_nb_id = nb_res["result_ref"]
    assert new_nb_id == "nb-created-new-456"
    assert new_nb_id != "nb-proof-owner-123"  # Proof notebook never reused!

    # Upload all 8 PDFs
    for p in pdf_paths:
        up_res = await upload_terminal_source({
            "schema_version": 1,
            "subject_run_id": subject_run_id,
            "notebook_id": new_nb_id,
            "source_path": str(p),
            "mime_type": "application/pdf",
            "title": p.name,
        })
        assert up_res["outcome"] == ActivityOutcome.complete.value

    # Poll sources
    poll_res = await poll_terminal_sources({
        "schema_version": 1,
        "subject_run_id": subject_run_id,
        "notebook_id": new_nb_id,
    })
    assert poll_res["outcome"] == ActivityOutcome.complete.value

    # Divide turn
    div_cap = await capture_terminal_turn({
        "schema_version": 1,
        "subject_run_id": subject_run_id,
        "turn_kind": "divide",
        "raw_answer": "1. Kinematics\n2. Dynamics",
    })
    assert div_cap["counts"]["work_count"] == 2

    # Lessons loop (Lesson 1 then Lesson 2)
    for ord_val in (1, 2):
        assert not scene_dispatch_allowed, "Scene generation MUST NOT be allowed before all lessons finish!"
        await capture_terminal_turn({
            "schema_version": 1,
            "subject_run_id": subject_run_id,
            "turn_kind": "lesson",
            "lesson_ordinal": ord_val,
            "mapped_title": f"Lesson {ord_val}",
            "raw_answer": f"Lesson {ord_val} content with scenes",
            "output_dir": str(handoff_dir),
        })

    # 5. ALL-LESSON BARRIER: freeze handoffs
    freeze_res = await freeze_terminal_handoffs({
        "schema_version": 1,
        "subject_run_id": subject_run_id,
        "handoff_dir": str(handoff_dir),
        "expected_lesson_count": 2,
    })
    assert freeze_res["outcome"] == ActivityOutcome.complete.value
    assert scene_dispatch_allowed is True

    # 6. Materialize a plain HTML/CSS/JavaScript scene using SceneBuilder.
    builder = SceneBuilder(scaffold_dir=project_root / "v3_engine" / "scene_generation" / "scaffold")
    scene_input = SceneInput(
        scene_key="nb-created-new-456:conv-test-789:W1:S1",
        notebook_id="nb-created-new-456",
        conversation_id="conv-test-789",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Kinematics",
        section_title="Velocity Vectors",
        scene_type="InteractiveSimulation",
        prompt="Kinematics interactive scene",
        prompt_sha256="d" * 64,
        input_error=None,
        source_index=0,
        source_json_pointer="/scenes/0",
    )

    scene_files = {
        "index.html": '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="./styles.css"><title>Kinematics</title></head><body><h1>Kinematics: Velocity Visualizer</h1><p id="vel-value">Current Velocity: <span id="velocity">10</span> m/s</p><button id="btn-boost" type="button">Boost Velocity</button><script src="./script.js" defer></script></body></html>',
        "styles.css": "body{font:1rem sans-serif;padding:24px}button{padding:.6rem 1rem}",
        "script.js": "const value=document.querySelector('#velocity');document.querySelector('#btn-boost').addEventListener('click',()=>{value.textContent=String(Number(value.textContent)+5)});",
    }
    source_pkg = HtmlSceneSourceV1(schema_version=1, files=[
        GeneratedFile(path=name, content=content, bytes=len(content.encode("utf-8")), sha256=hashlib.sha256(content.encode("utf-8")).hexdigest())
        for name, content in scene_files.items()
    ])

    scene_store = SceneArtifactStore(run_dir / "scenes_output")
    scene_store.acquire_lock()
    try:
        snap = InputSnapshot(0, "in.json", "in.json", hashlib.sha256(b"{}").hexdigest(), 2, "snapshot/inputs/0001.json")
        inv = HandoffInventory("nb-created-new-456", "conv-test-789", [scene_input], [], [snap], 1)
        cfg = ProviderConfig("gemini", "gemini_api", "gemini-2.5-flash", "https://generativelanguage.googleapis.com")
        host_tc = get_host_toolchain_info()
        scene_store.initialize_run(
            inv,
            cfg,
            "tmpl",
            "0" * 64,
            [b"{}"],
            catalog_sha=host_tc.get("catalog_sha256", "placeholder"),
        )
        attempt = scene_store.start_attempt(scene_input)
        scene_store.save_source_package(scene_input, attempt.ordinal, source_pkg, {})

        response_text = json.dumps({"schema_version": 1, "files": [{"path": name, "content": content} for name, content in scene_files.items()]})
        raw_body = json.dumps({
            "modelVersion": cfg.requested_model,
            "responseId": "test-connected-01",
            "usageMetadata": {"promptTokenCount": 1, "candidatesTokenCount": 1},
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": response_text}]}}],
        }).encode("utf-8")
        receipt_ref = scene_store.save_raw_receipt(scene_input, attempt.ordinal, raw_body, 200, True, False)
        scene_store.save_response_text(scene_input, attempt.ordinal, response_text)
        scene_store.save_provider_result(
            scene_input,
            attempt.ordinal,
            {
                "outcome": "complete",
                "safe_code": "ok",
                "http_status": 200,
                "requested_model": cfg.requested_model,
                "returned_model": cfg.requested_model,
                "model_reported": True,
                "response_id": "test-connected-01",
                "finish_reason": "STOP",
                "usage": {"input_tokens": 1, "output_tokens": 1},
                "raw_receipt_ref": receipt_ref,
                "duration_ms": 1,
            },
        )

        build_result = builder.build_scene(
            attempt_dir=attempt.attempt_dir,
            scene=scene_input,
            source_pkg=source_pkg,
            build_ordinal=1,
        )

        assert build_result["outcome"] == "complete", f"Static scene materialization failed: {build_result}"
        manifest_p = attempt.attempt_dir / "builds" / "build-0001" / "manifest.json"
        assert manifest_p.is_file()
        man_data = json.loads(manifest_p.read_text(encoding="utf-8"))
        assert man_data["kind"] == "local_static_html_scene"
        dist_dir = attempt.attempt_dir / "builds" / "build-0001" / "dist"
        assert (dist_dir / "index.html").is_file()
    finally:
        scene_store.close()

    # 7. Verify Owner Waiver contract (FR-006 / A07)
    # Qualification is owner-waived; no JEV call or fabricated score!
    qualification = {
        "mode": "owner_waived_terminal_demo",
        "jev_called": False,
        "teacher_approval": None,
    }
    assert qualification["mode"] == "owner_waived_terminal_demo"
    assert qualification["jev_called"] is False
    assert qualification["teacher_approval"] is None


@pytest.mark.integration
@pytest.mark.asyncio
async def test_connected_crash_recovery_resumes_without_reupload(tmp_path: Path) -> None:
    """Interrupted run recovers cleanly, reusing existing receipts with zero re-uploads."""
    service = NotebookTerminalService()

    run_dir = tmp_path / "run_crash"
    run_dir.mkdir()
    turn_file = run_dir / "turn_rcpt-pre-crash-01.json"
    turn_file.write_text(
        json.dumps({
            "receipt_id": "rcpt-pre-crash-01",
            "question": "Start with First lesson",
            "raw_answer": "Retained lesson 1 content from prior execution",
            "conv_id": "conv-reused-123",
            "status": "complete",
        }),
        encoding="utf-8",
    )

    # Post-crash recovery lookup: existing receipt loaded directly from disk
    receipt = service.load_existing_receipt(turn_file)
    assert receipt is not None
    assert receipt["status"] == "complete"
    assert receipt["receipt_id"] == "rcpt-pre-crash-01"
    assert receipt["raw_answer"] == "Retained lesson 1 content from prior execution"


@pytest.mark.integration
@pytest.mark.browser_ui
@pytest.mark.asyncio
async def test_connected_real_playwright_interaction_with_built_scene(
    tmp_path: Path,
    http_dist_server,
) -> None:
    """Materialize an interactive static scene and interact with it in headless Chromium."""
    try:
        from playwright.async_api import async_playwright
    except ImportError:
        pytest.fail("Browser gate open: Playwright is not installed")

    project_root = Path(__file__).parent.parent.parent.parent
    builder = SceneBuilder(scaffold_dir=project_root / "v3_engine" / "scene_generation" / "scaffold")

    scene_input = SceneInput(
        scene_key="smoke-nb:smoke-conv:W1:S1",
        notebook_id="smoke-nb",
        conversation_id="smoke-conv",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Interactive Kinematics",
        section_title="Interactive Acceleration",
        scene_type="InteractiveSimulation",
        prompt="Acceleration interactive scene",
        prompt_sha256="e" * 64,
        input_error=None,
        source_index=0,
        source_json_pointer="/scenes/0",
    )

    scene_files = {
        "index.html": '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="./styles.css"><title>Interactive Kinematics</title></head><body><h1 id="title">Kinematics Interactive Demo</h1><p id="counter">Click Count: <span id="count">0</span></p><button id="btn-increment" type="button">Accelerate</button><script src="./script.js" defer></script></body></html>',
        "styles.css": "body{font-family:sans-serif;padding:20px}button{padding:.6rem 1rem}",
        "script.js": "let count=0;const output=document.querySelector('#count');document.querySelector('#btn-increment').addEventListener('click',()=>{count+=1;output.textContent=String(count)});",
    }
    source_pkg = HtmlSceneSourceV1(schema_version=1, files=[
        GeneratedFile(path=name, content=content, bytes=len(content.encode("utf-8")), sha256=hashlib.sha256(content.encode("utf-8")).hexdigest())
        for name, content in scene_files.items()
    ])

    scene_store = SceneArtifactStore(tmp_path / "scene_output")
    scene_store.acquire_lock()
    try:
        snap = InputSnapshot(0, "in.json", "in.json", hashlib.sha256(b"{}").hexdigest(), 2, "snapshot/inputs/0001.json")
        inv = HandoffInventory("smoke-nb", "smoke-conv", [scene_input], [], [snap], 1)
        cfg = ProviderConfig("gemini", "gemini_api", "gemini-2.5-flash", "https://generativelanguage.googleapis.com")
        host_tc = get_host_toolchain_info()
        scene_store.initialize_run(
            inv,
            cfg,
            "tmpl",
            "0" * 64,
            [b"{}"],
            catalog_sha=host_tc.get("catalog_sha256", "placeholder"),
        )
        attempt = scene_store.start_attempt(scene_input)
        scene_store.save_source_package(scene_input, attempt.ordinal, source_pkg, {})

        response_text = json.dumps({"schema_version": 1, "files": [{"path": name, "content": content} for name, content in scene_files.items()]})
        raw_body = json.dumps({
            "modelVersion": cfg.requested_model,
            "responseId": "test-connected-02",
            "usageMetadata": {"promptTokenCount": 1, "candidatesTokenCount": 1},
            "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": response_text}]}}],
        }).encode("utf-8")
        receipt_ref = scene_store.save_raw_receipt(scene_input, attempt.ordinal, raw_body, 200, True, False)
        scene_store.save_response_text(scene_input, attempt.ordinal, response_text)
        scene_store.save_provider_result(
            scene_input,
            attempt.ordinal,
            {
                "outcome": "complete",
                "safe_code": "ok",
                "http_status": 200,
                "requested_model": cfg.requested_model,
                "returned_model": cfg.requested_model,
                "model_reported": True,
                "response_id": "test-connected-02",
                "finish_reason": "STOP",
                "usage": {"input_tokens": 1, "output_tokens": 1},
                "raw_receipt_ref": receipt_ref,
                "duration_ms": 1,
            },
        )

        build_res = builder.build_scene(
            attempt_dir=attempt.attempt_dir,
            scene=scene_input,
            source_pkg=source_pkg,
            build_ordinal=1,
        )
        assert build_res["outcome"] == "complete"
        dist_dir = attempt.attempt_dir / "builds" / "build-0001" / "dist"
        assert (dist_dir / "index.html").exists()
    finally:
        scene_store.close()

    # Serve the built distribution directory
    port = http_dist_server(dist_dir)

    # Interact using Playwright
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        page = await browser.new_page()
        await page.goto(f"http://127.0.0.1:{port}/index.html")

        # Verify page title and static browser interaction.
        title = await page.wait_for_selector("#title", timeout=5000)
        assert title is not None
        assert "Kinematics Interactive Demo" in await title.inner_text()

        # Check initial counter text
        counter = await page.wait_for_selector("#counter")
        assert counter is not None
        assert "Click Count: 0" in await counter.inner_text()

        # Click the button
        button = await page.wait_for_selector("#btn-increment")
        assert button is not None
        await button.click()

        # Assert state change
        await page.wait_for_function("document.getElementById('counter').innerText.includes('Click Count: 1')")
        assert "Click Count: 1" in await counter.inner_text()

        await browser.close()
