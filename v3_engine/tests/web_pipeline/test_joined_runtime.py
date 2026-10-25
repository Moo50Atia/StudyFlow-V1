"""Joined runtime harness sensitivity tests (T121 / Phase 19).

Proves that the runtime harness detects disconnected boundaries:
1. Unavailable bridge prevents admission.
2. Wrong signing key causes 401 rejection.
3. Stopped worker prevents workflow advancement.
4. Failed callback prevents uncommitted state progression.
5. Failed browser check prevents review qualification.

Restoring each boundary proves safe recovery.
"""

from __future__ import annotations

import asyncio
import io
import hashlib
import json
import os
import time
from pathlib import Path
import subprocess
import uuid
import re
import pytest
import httpx

from v3_engine.contracts import compute_hmac_signature
from v3_engine.tests.web_pipeline.runtime_harness import JoinedRuntimeHarness
from v3_engine.web_pipeline.check_activities import execute_browser_checks_activity


@pytest.fixture
def runtime_harness():
    harness = JoinedRuntimeHarness()
    harness.setup_database_and_prerequisites()
    harness.start_laravel()
    harness.start_bridge()
    yield harness
    harness.cleanup()


@pytest.mark.asyncio
async def test_browser_run_controls_pause_resume_and_cancel_active_work(runtime_harness, temporal_local_env, monkeypatch):
    """Exercise live run controls through the owner page, durable outbox and Temporal child."""
    import sqlite3
    import v3_engine.web_pipeline.activities as web_acts
    from temporalio import activity
    from playwright.async_api import async_playwright
    from v3_engine.notebooklm.terminal_service import NotebookTerminalService
    from v3_engine.tests.web_pipeline.runtime_harness import (
        VariableNotebookLMTransport,
        VariableSessionProvider,
        make_minimal_pdf,
    )
    from v3_engine.terminal_pipeline.worker import run_workers
    from v3_engine.web_pipeline.application_client import ApplicationClient

    transport = VariableNotebookLMTransport()
    web_acts.set_terminal_service(NotebookTerminalService(session_provider=VariableSessionProvider(transport)))
    web_acts.set_application_client(ApplicationClient(
        base_url=runtime_harness.laravel_url,
        hmac_key=runtime_harness.signing_secret,
    ))
    monkeypatch.setenv("V3_LARAVEL_CALLBACK_URL", f"{runtime_harness.laravel_url}/api/internal/v1/case-events")
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", runtime_harness.signing_secret)
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", runtime_harness.signing_secret)
    monkeypatch.setenv("V3_STORAGE_ROOT", str(runtime_harness.private_root))
    terminal_root = runtime_harness.storage_path / "private/v3/terminal"
    terminal_root.mkdir(parents=True, exist_ok=True)
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(terminal_root))
    runtime_harness.stop_bridge()
    runtime_harness.temporal_address = temporal_local_env.client.service_client.config.target_host
    runtime_harness.start_bridge()

    second_item_id: str | None = None
    second_sources_ready = asyncio.Event()
    release_sources_ready = asyncio.Event()
    second_divide_finished = asyncio.Event()
    release_second_divide = asyncio.Event()
    original_check_sources = web_acts.check_web_sources_activity
    original_divide = web_acts.execute_divide_turn

    @activity.defn(name="check_web_sources_activity")
    async def hold_second_source_check(args):
        result = await original_check_sources(args)
        if str(args.get("item_id")) == second_item_id:
            second_sources_ready.set()
            await release_sources_ready.wait()
        return result

    @activity.defn(name="execute_divide_turn")
    async def hold_after_second_divide(args):
        result = await original_divide(args)
        if int(args.get("pdf_ordinal", 0)) == 2:
            second_divide_finished.set()
            await release_second_divide.wait()
        return result

    monkeypatch.setattr(web_acts, "check_web_sources_activity", hold_second_source_check)
    monkeypatch.setattr(web_acts, "execute_divide_turn", hold_after_second_divide)

    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(headless=True)
        worker_stop = asyncio.Event()
        worker_task = None
        try:
            page = await browser.new_page()
            await page.goto(f"{runtime_harness.laravel_url}/login")
            await page.locator("#email").fill("teacher_a@example.com")
            await page.locator("#password").fill("password")
            await page.get_by_role("button", name="Sign In").click()
            subject_id = runtime_harness.seeded_data.get("subject_a", {}).get("id") or runtime_harness.seeded_data.get("subject_id", 1)
            await page.goto(f"{runtime_harness.laravel_url}/v3/teacher/subjects/{subject_id}/runs/create")
            for ordinal, title, body in [
                (0, "Control PDF 1", "Chapter one content for control checks."),
                (1, "Control PDF 2", "Chapter two content for control checks."),
            ]:
                await page.locator('input[name="titles[]"]').nth(ordinal).fill(title)
                await page.locator('input[name="files[]"]').nth(ordinal).set_input_files({
                    "name": f"control-{ordinal + 1}.pdf",
                    "mimeType": "application/pdf",
                    "buffer": make_minimal_pdf(1, body),
                })
                if ordinal == 0:
                    await page.get_by_role("button", name="Add Another PDF").click()
            async with page.expect_navigation():
                await page.get_by_role("button", name="Start Processing Run").click()
            run_id = page.url.rstrip("/").split("/")[-1]
            conn = sqlite3.connect(str(runtime_harness.db_path))
            rows = conn.execute(
                "SELECT id FROM v3_subject_run_items WHERE run_id = ? ORDER BY pdf_ordinal", (run_id,)
            ).fetchall()
            assert len(rows) == 2
            second_item_id = rows[1][0]

            worker_task = asyncio.create_task(run_workers(
                temporal_local_env.client,
                lock_path=runtime_harness.test_root / "control-worker.lock",
                stop_event=worker_stop,
            ))
            admission = await asyncio.to_thread(runtime_harness.dispatch_outbox)
            assert admission["dispatched_count"] == 1
            await asyncio.wait_for(second_sources_ready.wait(), timeout=90)
            handle = temporal_local_env.client.get_workflow_handle(f"v3-web-subject-{run_id}")

            async def submit_from_run_page(button_name: str) -> None:
                await page.goto(f"{runtime_harness.laravel_url}/v3/teacher/subject-runs/{run_id}")
                button = page.get_by_role("button", name=button_name)
                await button.wait_for(state="visible", timeout=15000)
                async with page.expect_response(
                    lambda response: response.request.method == "POST" and response.url.endswith("/commands")
                ) as posted:
                    await button.click()
                response = await posted.value
                assert response.status == 202
                dispatch = await asyncio.to_thread(runtime_harness.dispatch_outbox)
                assert dispatch["dispatched_count"] == 1

            await submit_from_run_page("Pause Run")
            assert (await handle.query("progress_v1"))["status"] == "paused"
            release_sources_ready.set()
            await asyncio.sleep(0.1)
            assert len(transport.divide_requests) == 1, "Pause must prevent starting the next PDF Divide turn"

            await submit_from_run_page("Resume Run")
            assert (await handle.query("progress_v1"))["status"] == "running"
            await asyncio.wait_for(second_divide_finished.wait(), timeout=30)

            await submit_from_run_page("Pause Run")
            assert (await handle.query("progress_v1"))["status"] == "paused"
            page.on("dialog", lambda dialog: asyncio.create_task(dialog.accept()))
            await submit_from_run_page("Cancel Run")
            assert (await handle.query("progress_v1"))["status"] == "cancelled"
            release_second_divide.set()
            result = await asyncio.wait_for(handle.result(), timeout=30)
            assert result["outcome"] == "cancelled"
            assert len(transport.divide_requests) == 2
            assert len(transport.start_requests) == 1, "Cancellation after Divide must prevent this PDF's lesson prompt sends"
            assert len(transport.continue_requests) == 1
            assert conn.execute(
                "SELECT state FROM v3_subject_runs WHERE id = ?", (run_id,)
            ).fetchone()[0] == "cancelled"
            states = [row[0] for row in conn.execute(
                "SELECT state FROM v3_pipeline_commands WHERE run_id = ? AND action IN ('pause','resume','cancel') ORDER BY created_at",
                (run_id,),
            )]
            assert states == ["applied", "applied", "applied", "applied"]
            conn.close()
        finally:
            release_sources_ready.set()
            release_second_divide.set()
            worker_stop.set()
            if worker_task is not None:
                await worker_task
            await browser.close()


@pytest.mark.temporal
@pytest.mark.asyncio
async def test_browser_cancels_run_waiting_in_shared_supervisor_queue(runtime_harness, temporal_local_env, monkeypatch):
    """Cancel the fifth website submission while four real child workflows occupy capacity."""
    import sqlite3
    import hashlib
    import v3_engine.web_pipeline.activities as web_acts
    from temporalio import activity
    from playwright.async_api import async_playwright
    from v3_engine.notebooklm.terminal_service import NotebookTerminalService
    from v3_engine.tests.web_pipeline.runtime_harness import VariableNotebookLMTransport, VariableSessionProvider, make_minimal_pdf
    from v3_engine.terminal_pipeline.worker import run_workers
    from v3_engine.web_pipeline.application_client import ApplicationClient

    transport = VariableNotebookLMTransport()
    web_acts.set_terminal_service(NotebookTerminalService(session_provider=VariableSessionProvider(transport)))
    web_acts.set_application_client(ApplicationClient(runtime_harness.laravel_url, runtime_harness.signing_secret))
    monkeypatch.setenv("V3_LARAVEL_CALLBACK_URL", f"{runtime_harness.laravel_url}/api/internal/v1/case-events")
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", runtime_harness.signing_secret)
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", runtime_harness.signing_secret)
    monkeypatch.setenv("V3_STORAGE_ROOT", str(runtime_harness.private_root))
    terminal_root = runtime_harness.storage_path / "private/v3/terminal"
    terminal_root.mkdir(parents=True, exist_ok=True)
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(terminal_root))
    runtime_harness.stop_bridge()
    runtime_harness.temporal_address = temporal_local_env.client.service_client.config.target_host
    runtime_harness.start_bridge()

    active_run_ids: set[str] = set()
    active_checks: set[str] = set()
    four_children_blocked = asyncio.Event()
    release_checks = asyncio.Event()
    original_check = web_acts.check_web_sources_activity

    @activity.defn(name="check_web_sources_activity")
    async def hold_active_children(args):
        result = await original_check(args)
        run_id = str(args.get("run_id"))
        if run_id in active_run_ids:
            active_checks.add(run_id)
            if len(active_checks) == 4:
                four_children_blocked.set()
            await release_checks.wait()
        return result

    monkeypatch.setattr(web_acts, "check_web_sources_activity", hold_active_children)
    conn = sqlite3.connect(str(runtime_harness.db_path))
    worker_stop = asyncio.Event()
    worker_task = None
    try:
        async with async_playwright() as playwright:
            browser = await playwright.chromium.launch(headless=True)
            try:
                page = await browser.new_page()
                await page.goto(f"{runtime_harness.laravel_url}/login")
                await page.locator("#email").fill("teacher_a@example.com")
                await page.locator("#password").fill("password")
                await page.get_by_role("button", name="Sign In").click()
                subject_id = runtime_harness.seeded_data.get("subject_a", {}).get("id") or runtime_harness.seeded_data.get("subject_id", 1)
                run_ids: list[str] = []
                for ordinal in range(5):
                    await page.goto(f"{runtime_harness.laravel_url}/v3/teacher/subjects/{subject_id}/runs/create")
                    await page.locator('input[name="titles[]"]').nth(0).fill(f"Queued control PDF {ordinal + 1}")
                    await page.locator('input[name="files[]"]').nth(0).set_input_files({
                        "name": f"queued-control-{ordinal + 1}.pdf",
                        "mimeType": "application/pdf",
                        "buffer": make_minimal_pdf(1, f"Chapter {ordinal + 1} for queued control."),
                    })
                    async with page.expect_navigation():
                        await page.get_by_role("button", name="Start Processing Run").click()
                    run_ids.append(page.url.rstrip("/").split("/")[-1])
                active_run_ids.update(run_ids[:4])

                worker_task = asyncio.create_task(run_workers(
                    temporal_local_env.client,
                    lock_path=runtime_harness.test_root / "queued-control-worker.lock",
                    stop_event=worker_stop,
                ))
                dispatch = await asyncio.to_thread(runtime_harness.dispatch_outbox)
                assert dispatch["dispatched_count"] == 5
                await asyncio.wait_for(four_children_blocked.wait(), timeout=90)

                queued_run_id = run_ids[4]
                supervisor = temporal_local_env.client.get_workflow_handle("v3-terminal-supervisor")
                assert await supervisor.query("get_subject_admission_v1", queued_run_id) == "queued"
                await page.goto(f"{runtime_harness.laravel_url}/v3/teacher/subject-runs/{queued_run_id}")
                assert await page.get_by_text("Queued", exact=True).count() >= 1
                cancel = page.get_by_role("button", name="Cancel Run")
                await cancel.wait_for(state="visible")
                page.on("dialog", lambda dialog: asyncio.create_task(dialog.accept()))
                async with page.expect_response(
                    lambda response: response.request.method == "POST" and response.url.endswith("/commands")
                ) as posted:
                    await cancel.click()
                response = await posted.value
                assert response.status == 202
                dispatched = await asyncio.to_thread(runtime_harness.dispatch_outbox)
                assert dispatched["dispatched_count"] == 1
                assert await supervisor.query("get_subject_admission_v1", queued_run_id) == "cancelled_before_start"
                assert conn.execute(
                    "SELECT state FROM v3_subject_runs WHERE id = ?", (queued_run_id,)
                ).fetchone()[0] == "cancelled"

                # Clean up the four deliberately held active workflows after the website assertion.
                for active_run_id in run_ids[:4]:
                    child = temporal_local_env.client.get_workflow_handle(f"v3-web-subject-{active_run_id}")
                    cleanup_id = str(uuid.uuid4())
                    await child.execute_update(
                        "control_v1",
                        {"command_id": cleanup_id, "action": "cancel", "command_digest": hashlib.sha256(cleanup_id.encode()).hexdigest()},
                        id=f"test-cleanup-{cleanup_id}",
                    )
                release_checks.set()
                for active_run_id in run_ids[:4]:
                    await asyncio.wait_for(
                        temporal_local_env.client.get_workflow_handle(f"v3-web-subject-{active_run_id}").result(),
                        timeout=30,
                    )
            finally:
                await browser.close()
    finally:
        release_checks.set()
        worker_stop.set()
        if worker_task is not None:
            await worker_task
        conn.close()


@pytest.mark.asyncio
async def test_sensitivity_unavailable_bridge(runtime_harness):
    """Stopping the bridge prevents admission; restarting restores it."""
    # 1. Stop the bridge
    runtime_harness.stop_bridge()

    # 2. Assert connection failure when attempting to reach bridge
    with pytest.raises(httpx.ConnectError):
        async with httpx.AsyncClient(timeout=2.0) as client:
            await client.get(f"{runtime_harness.bridge_url}/health")

    # 3. Restore bridge
    runtime_harness.start_bridge()

    # 4. Assert safe recovery
    async with httpx.AsyncClient(timeout=5.0) as client:
        resp = await client.get(f"{runtime_harness.bridge_url}/health")
        assert resp.status_code == 200
        assert resp.json()["status"] == "ok"


@pytest.mark.asyncio
async def test_sensitivity_wrong_signing_key(runtime_harness):
    """An incorrect HMAC signing key causes 401 rejection; correct key succeeds."""
    payload = json.dumps({"schema_version": 1, "test": "sensitivity"}).encode("utf-8")
    now_ts = str(int(time.time()))

    # 1. Sign with WRONG key
    wrong_sig = compute_hmac_signature(
        "POST",
        "/internal/v1/application-pipeline/submissions",
        now_ts,
        payload,
        "completely-wrong-secret-key",
    )
    headers_wrong = {
        "X-V3-Timestamp": now_ts,
        "X-V3-Signature": wrong_sig,
        "X-V3-Contract-Version": "1",
        "Content-Type": "application/json",
    }

    async with httpx.AsyncClient() as client:
        resp_wrong = await client.post(
            f"{runtime_harness.bridge_url}/internal/v1/application-pipeline/submissions",
            content=payload,
            headers=headers_wrong,
        )
        assert resp_wrong.status_code == 401
        assert resp_wrong.json()["error"]["code"] == "worker_unauthenticated"

    # 2. Sign with CORRECT key
    correct_sig = compute_hmac_signature(
        "POST",
        "/internal/v1/application-pipeline/submissions",
        now_ts,
        payload,
        runtime_harness.signing_secret,
    )
    headers_correct = {
        "X-V3-Timestamp": now_ts,
        "X-V3-Signature": correct_sig,
        "X-V3-Contract-Version": "1",
        "Content-Type": "application/json",
    }

    async with httpx.AsyncClient() as client:
        resp_correct = await client.post(
            f"{runtime_harness.bridge_url}/internal/v1/application-pipeline/submissions",
            content=payload,
            headers=headers_correct,
        )
        # Even if payload fails validation, it PASSED authentication (422 contract invalid or 202 accepted)
        assert resp_correct.status_code != 401
        assert resp_correct.status_code in (202, 422)


from v3_engine.qualification.browser_checks import run_browser_checks


@pytest.mark.asyncio
async def test_sensitivity_failed_browser_check_blocks_qualification():
    """A scene with a syntax/runtime error fails qualification; a valid scene passes."""
    import tempfile

    # 1. Broken package with Javascript syntax error
    with tempfile.TemporaryDirectory() as tmp_broken:
        html = "<!DOCTYPE html><html><body><canvas id='c'></canvas><script src='script.js'></script></body></html>"
        css = "body { background: #000; }"
        js_broken = "const foo = ; // SyntaxError breaking runtime"

        for name, content in [("index.html", html), ("styles.css", css), ("script.js", js_broken)]:
            with open(os.path.join(tmp_broken, name), "w", encoding="utf-8") as f:
                f.write(content)

        manifest_broken = {
            "schema_version": 2,
            "profile": "static_three_file_v1",
            "entrypoint": "index.html",
            "files": [
                {"path": name, "sha256": hashlib.sha256(content.encode("utf-8")).hexdigest(), "bytes": len(content.encode("utf-8"))}
                for name, content in [("index.html", html), ("styles.css", css), ("script.js", js_broken)]
            ],
        }

        result_broken = await run_browser_checks(tmp_broken, "cand-broken-1", manifest_broken)
        checks_broken = {c["code"]: c["status"] for c in result_broken["checks"]}
        assert checks_broken["runtime"] == "fail", f"Runtime check must fail for broken JS, got: {checks_broken}"

    # 2. Valid working package
    with tempfile.TemporaryDirectory() as tmp_valid:
        js_valid = "const c = document.getElementById('c'); window.__scene_ready = true;"

        for name, content in [("index.html", html), ("styles.css", css), ("script.js", js_valid)]:
            with open(os.path.join(tmp_valid, name), "w", encoding="utf-8") as f:
                f.write(content)

        manifest_valid = {
            "schema_version": 2,
            "profile": "static_three_file_v1",
            "entrypoint": "index.html",
            "files": [
                {"path": name, "sha256": hashlib.sha256(content.encode("utf-8")).hexdigest(), "bytes": len(content.encode("utf-8"))}
                for name, content in [("index.html", html), ("styles.css", css), ("script.js", js_valid)]
            ],
        }

        result_valid = await run_browser_checks(tmp_valid, "cand-valid-1", manifest_valid)
        checks_valid = {c["code"]: c["status"] for c in result_valid["checks"]}
        assert checks_valid["integrity"] == "pass"
        assert checks_valid["entrypoint"] == "pass"
        assert checks_valid["assets"] == "pass"
        assert checks_valid["runtime"] == "pass"


@pytest.mark.asyncio
async def test_imported_teacher_navigation_over_joined_runtime(runtime_harness):
    """T129 / A01, A04, A26: Verify owning teacher can navigate imported lectures via real HTTP server."""
    # 1. Materialize reference archive in the harness DB
    ref_root = Path("/home/moahmmed/college_project/storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root")
    run_id = "09a59afe-ed26-4567-906b-e42d87d53362"
    teacher_id = runtime_harness.seeded_data["teacher_a"]["id"]
    admin_id = runtime_harness.seeded_data["admin"]["id"]
    subject_id = runtime_harness.seeded_data["subject"]["id"]
    import_id = str(uuid.uuid4())

    env = os.environ.copy()
    env["APP_ENV"] = "testing"
    env["DB_CONNECTION"] = "sqlite"
    env["DB_DATABASE"] = str(runtime_harness.db_path)
    env["V3_PRIVATE_ROOT"] = str(runtime_harness.private_root)

    res_mat = subprocess.run(
        [
            "php", "artisan", "v3:import-terminal-run",
            f"--terminal-root={ref_root}",
            f"--run-id={run_id}",
            f"--subject={subject_id}",
            f"--teacher={teacher_id}",
            f"--actor={admin_id}",
            f"--import-id={import_id}",
        ],
        cwd=str(runtime_harness.repo_root),
        env=env,
        capture_output=True,
        text=True,
    )
    assert res_mat.returncode == 0, f"Import failed: {res_mat.stderr}"

    # 2. Query lectures created for this run
    import sqlite3
    conn = sqlite3.connect(str(runtime_harness.db_path))
    cur = conn.cursor()
    cur.execute(
        "SELECT l.id, l.title FROM lectures l "
        "JOIN v3_lecture_pipeline_links lpl ON lpl.lecture_id = l.id "
        "WHERE lpl.owner_user_id = ? ORDER BY l.id",
        (teacher_id,)
    )
    imported_lectures = cur.fetchall()
    assert len(imported_lectures) == 8, f"Expected 8 lectures, found {len(imported_lectures)}"

    # 3. Authenticate owning teacher via real login form
    async with runtime_harness.create_browser_client() as client_a:
        login_ok = await runtime_harness.login_teacher(client_a, "teacher_a@example.com", "password")
        assert login_ok is True, "Owning teacher login failed"

        # Visit subject show page
        resp_subj = await client_a.get(f"/subjects/{subject_id}")
        assert resp_subj.status_code == 200

        # Visit each imported lecture
        for lec_id, lec_title in imported_lectures:
            resp_lec = await client_a.get(f"/lectures/{lec_id}")
            assert resp_lec.status_code == 200, f"Failed to view lecture {lec_id}"
            assert "Imported Historical Content" in resp_lec.text

    # 4. Non-owning teacher receives 403 Forbidden
    async with runtime_harness.create_browser_client() as client_b:
        login_b_ok = await runtime_harness.login_teacher(client_b, "teacher_b@example.com", "password")
        assert login_b_ok is True, "Other teacher login failed"

        for lec_id, _ in imported_lectures[:2]:
            resp_forbidden = await client_b.get(f"/lectures/{lec_id}")
            assert resp_forbidden.status_code == 403


@pytest.mark.temporal
@pytest.mark.asyncio
async def test_queued_reference_import_crosses_signed_bridge_temporal_and_laravel(runtime_harness, temporal_local_env, monkeypatch):
    """Exercise the queued archive path through production bridge and import activity."""
    ref_root = Path("/home/moahmmed/college_project/storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root")
    source_run_id = "09a59afe-ed26-4567-906b-e42d87d53362"
    teacher_id = int(runtime_harness.seeded_data["teacher_a"]["id"])
    admin_id = int(runtime_harness.seeded_data["admin"]["id"])
    subject_id = int(runtime_harness.seeded_data["subject"]["id"])
    import_id = str(uuid.uuid4())

    env = os.environ.copy()
    env.update({
        "APP_ENV": "testing",
        "APP_KEY": "base64:pAeRvprsT7xvgtJnI7RY2CtfRhhwt5HflVxit0LZEH0=",
        "DB_CONNECTION": "sqlite",
        "DB_DATABASE": str(runtime_harness.db_path),
        "V3_PRIVATE_ROOT": str(runtime_harness.private_root),
    })
    queued = subprocess.run(
        [
            "php", "artisan", "v3:import-terminal-run",
            f"--terminal-root={ref_root}",
            f"--run-id={source_run_id}",
            f"--subject={subject_id}",
            f"--teacher={teacher_id}",
            f"--actor={admin_id}",
            f"--import-id={import_id}",
            "--queue",
        ], cwd=str(runtime_harness.repo_root), env=env, capture_output=True, text=True, timeout=45,
    )
    assert queued.returncode == 3, f"Expected truthful queued exit 3, got {queued.returncode}: {queued.stdout} {queued.stderr}"
    queued_result = json.loads(queued.stdout.strip())
    assert queued_result["state"] == "queued" and queued_result["pdf_count"] == 8

    import sqlite3
    conn = sqlite3.connect(str(runtime_harness.db_path))
    conn.row_factory = sqlite3.Row
    command = conn.execute(
        "SELECT id, run_id FROM v3_pipeline_commands WHERE id = ? AND action = 'import_terminal_run'",
        (import_id,),
    ).fetchone()
    assert command is not None
    assert command["run_id"] == source_run_id

    monkeypatch.setenv("V3_LARAVEL_CALLBACK_URL", f"{runtime_harness.laravel_url}/api/internal/v1/case-events")
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", runtime_harness.signing_secret)
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", runtime_harness.signing_secret)
    monkeypatch.setenv("V3_STORAGE_ROOT", str(runtime_harness.private_root))
    monkeypatch.setenv("V3_TEMPORAL_ADDRESS", temporal_local_env.client.service_client.config.target_host)
    monkeypatch.setenv("V3_TEMPORAL_NAMESPACE", "v3-terminal")
    runtime_harness.stop_bridge()
    runtime_harness.temporal_address = temporal_local_env.client.service_client.config.target_host
    runtime_harness.start_bridge()

    from v3_engine.terminal_pipeline.worker import run_workers

    stop = asyncio.Event()
    workers = asyncio.create_task(run_workers(
        temporal_local_env.client,
        lock_path=runtime_harness.test_root / "queued-import-worker.lock",
        stop_event=stop,
    ))
    try:
        dispatch = await asyncio.to_thread(runtime_harness.dispatch_outbox)
        assert dispatch["dispatched_count"] == 1
        outbox = conn.execute(
            "SELECT state FROM v3_pipeline_outbox WHERE command_id = ?", (import_id,)
        ).fetchone()
        assert outbox["state"] == "acknowledged"
        handle = temporal_local_env.client.get_workflow_handle(f"v3-import-{import_id}")
        workflow_result = await asyncio.wait_for(handle.result(), timeout=180)
    finally:
        stop.set()
        await workers

    assert workflow_result["outcome"] == "completed"
    assert workflow_result["safe_code"] == "ok"
    imported = conn.execute(
        "SELECT state FROM v3_terminal_imports WHERE id = ?", (import_id,)
    ).fetchone()
    assert imported and imported["state"] == "completed"
    assert conn.execute("SELECT count(*) FROM v3_subject_run_items WHERE run_id = ?", (source_run_id,)).fetchone()[0] == 8
    assert conn.execute("SELECT count(*) FROM v3_lesson_nodes WHERE kind = 'lesson'").fetchone()[0] == 63
    assert conn.execute("SELECT count(*) FROM v3_scene_prompt_versions WHERE origin = 'historical_import'").fetchone()[0] == 89

    # Repeating the same source and destination is an idempotent duplicate.
    repeated = subprocess.run(
        [
            "php", "artisan", "v3:import-terminal-run",
            f"--terminal-root={ref_root}", f"--run-id={source_run_id}",
            f"--subject={subject_id}", f"--teacher={teacher_id}", f"--actor={admin_id}",
            f"--import-id={import_id}",
        ], cwd=str(runtime_harness.repo_root), env=env, capture_output=True, text=True, timeout=45,
    )
    assert repeated.returncode == 0
    assert json.loads(repeated.stdout.strip())["state"] == "duplicate"
    assert conn.execute("SELECT count(*) FROM v3_subject_run_items WHERE run_id = ?", (source_run_id,)).fetchone()[0] == 8
    conn.close()


@pytest.mark.asyncio
async def test_variable_external_transport_fixtures_invariants():
    """T130 / A05–A10: Variable NotebookLM external transport with PDF 1 (2 lessons) and PDF 2 (3 lessons)."""
    from v3_engine.notebooklm.terminal_service import NotebookTerminalService
    from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
    from v3_engine.tests.web_pipeline.runtime_harness import VariableNotebookLMTransport, VariableSessionProvider

    transport = VariableNotebookLMTransport()
    service = NotebookTerminalService(session_provider=VariableSessionProvider(transport))

    # 1. Create notebook
    nb = await service.create_notebook("V3 Kinematics and Kinetics Run")
    nb_id = nb.id

    # 2. Upload Framework (must be uploaded once)
    fw_path = Path("/tmp/framework.txt")
    fw_path.write_text("Framework test content", encoding="utf-8")
    fw_receipt = await service.upload_source(nb_id, fw_path, "text/plain", "Curriculum Framework v1")
    fw_id = fw_receipt.source_id

    # -------------------------------------------------------------
    # PDF 1 Processing: 2 lessons, unequal scenes [2, 1]
    # -------------------------------------------------------------
    pdf1_path = Path("/tmp/pdf1.pdf")
    pdf1_path.write_bytes(b"%PDF-1.4 sample content 1")
    pdf1_receipt = await service.upload_source(nb_id, pdf1_path, "application/pdf", "01_kinematics.pdf")
    pdf1_id = pdf1_receipt.source_id

    # Divide Scope: ONLY pdf1_id (never framework)
    divide1_ans = await service.send_turn(
        notebook_id=nb_id,
        question=DIVIDE_PROMPT,
        source_ids=[pdf1_id],
        conversation_id="conv-pdf-1",
    )
    assert "Lesson 1: Rectilinear Particle Motion" in divide1_ans.text
    assert "Lesson 2: Curvilinear Trajectory" in divide1_ans.text

    # Execution Turn: System Ready
    exec1_ans = await service.send_turn(
        notebook_id=nb_id,
        question=EXECUTION_PROMPT,
        source_ids=[pdf1_id, fw_id],
        conversation_id="conv-pdf-1",
    )
    assert exec1_ans.text == "System Ready"

    # Lesson 1 Start: Scoped to BOTH pdf1 and framework -> 2 scenes
    l1_ans = await service.send_turn(
        notebook_id=nb_id,
        question="Start with First lesson",
        source_ids=[pdf1_id, fw_id],
        conversation_id="conv-pdf-1",
    )
    assert "Phase Space Explorer" in l1_ans.text
    assert "Dynamic Vector Plot" in l1_ans.text

    # Lesson 2 Continue: Scoped to BOTH pdf1 and framework -> 1 scene
    l2_ans = await service.send_turn(
        notebook_id=nb_id,
        question="Continue",
        source_ids=[pdf1_id, fw_id],
        conversation_id="conv-pdf-1",
    )
    assert "Osculating Circle Demo" in l2_ans.text

    # Reset conversation before next PDF
    await service.reset_conversation(notebook_id=nb_id, conversation_id="conv-pdf-1")
    empty_hist1 = await service.get_history(notebook_id=nb_id, conversation_id="conv-pdf-1")
    assert empty_hist1 == [], "Conversation history must be empty after reset"

    # -------------------------------------------------------------
    # PDF 2 Processing: 3 lessons, unequal scenes [2, 1, 3]
    # -------------------------------------------------------------
    pdf2_path = Path("/tmp/pdf2.pdf")
    pdf2_path.write_bytes(b"%PDF-1.4 sample content 2")
    pdf2_receipt = await service.upload_source(nb_id, pdf2_path, "application/pdf", "02_kinetics.pdf")
    pdf2_id = pdf2_receipt.source_id

    # Divide Scope: ONLY pdf2_id
    divide2_ans = await service.send_turn(
        notebook_id=nb_id,
        question=DIVIDE_PROMPT,
        source_ids=[pdf2_id],
        conversation_id="conv-pdf-2",
    )
    assert "Lesson 1: Newton Second Law" in divide2_ans.text
    assert "Lesson 2: Work and Energy" in divide2_ans.text
    assert "Lesson 3: Impulse and Momentum" in divide2_ans.text

    # Execution Turn: System Ready
    exec2_ans = await service.send_turn(
        notebook_id=nb_id,
        question=EXECUTION_PROMPT,
        source_ids=[pdf2_id, fw_id],
        conversation_id="conv-pdf-2",
    )
    assert exec2_ans.text == "System Ready"

    # Lesson 1 Start: 2 scenes
    p2_l1_ans = await service.send_turn(
        notebook_id=nb_id,
        question="Start with First lesson",
        source_ids=[pdf2_id, fw_id],
        conversation_id="conv-pdf-2",
    )
    assert "Friction Threshold Visualizer" in p2_l1_ans.text
    assert "Normal Reaction Vector" in p2_l1_ans.text

    # Lesson 2 Continue: 1 scene
    p2_l2_ans = await service.send_turn(
        notebook_id=nb_id,
        question="Continue",
        source_ids=[pdf2_id, fw_id],
        conversation_id="conv-pdf-2",
    )
    assert "Work Energy Integrator" in p2_l2_ans.text

    # Lesson 3 Continue: 3 scenes
    p2_l3_ans = await service.send_turn(
        notebook_id=nb_id,
        question="Continue",
        source_ids=[pdf2_id, fw_id],
        conversation_id="conv-pdf-2",
    )
    assert "Collision Momentum Bar" in p2_l3_ans.text
    assert "Restitution Coefficient Gauge" in p2_l3_ans.text
    assert "Kinetic Energy Loss 3D" in p2_l3_ans.text

    # -------------------------------------------------------------
    # Verify Boundary Invariants
    # -------------------------------------------------------------
    transport.assert_framework_uploaded_once()
    transport.assert_divide_scope_pdf_only()
    transport.assert_lesson_scope_includes_framework()
    transport.assert_reset_between_pdfs()

    # Clean up temp files
    fw_path.unlink(missing_ok=True)
    pdf1_path.unlink(missing_ok=True)
    pdf2_path.unlink(missing_ok=True)


@pytest.mark.asyncio
async def test_browser_intake_and_sequential_service_execution(runtime_harness, temporal_local_env, monkeypatch):
    """Submit over Laravel HTTP and execute via outbox, bridge and real Temporal workers."""
    import sqlite3
    import v3_engine.web_pipeline.activities as web_acts
    from v3_engine.notebooklm.terminal_service import NotebookTerminalService
    from v3_engine.web_pipeline.application_client import ApplicationClient
    from v3_engine.terminal_pipeline.worker import run_workers
    from playwright.async_api import async_playwright
    from v3_engine.tests.web_pipeline.runtime_harness import (
        VariableNotebookLMTransport,
        VariableSessionProvider,
        make_minimal_pdf,
    )

    # 1. Use the real authorized NotebookLM session only for an explicit live
    # invocation. Normal regression runs stay provider-free and deterministic.
    live_notebooklm = os.getenv("V3_WEB_LIVE_NOTEBOOKLM_TEST") == "1"
    live_qwen = os.getenv("V3_WEB_LIVE_QWEN_TEST") == "1"
    transport = None
    if live_notebooklm:
        proof_notebook_id = os.getenv("V3_WEB_LIVE_PROOF_NOTEBOOK_ID")
        if not proof_notebook_id:
            pytest.fail("Live NotebookLM test requires V3_WEB_LIVE_PROOF_NOTEBOOK_ID")
        from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
        service = NotebookTerminalService(session_provider=SdkSessionProvider())
        await service.verify_session(proof_notebook_id)
    else:
        transport = VariableNotebookLMTransport()
        service = NotebookTerminalService(session_provider=VariableSessionProvider(transport))
    web_acts.set_terminal_service(service)

    app_client = ApplicationClient(
        base_url=runtime_harness.laravel_url,
        hmac_key=runtime_harness.signing_secret,
    )
    web_acts.set_application_client(app_client)

    # Configure env for sub-activities
    monkeypatch.setenv("V3_LARAVEL_CALLBACK_URL", f"{runtime_harness.laravel_url}/api/internal/v1/case-events")
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", runtime_harness.signing_secret)
    monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", runtime_harness.signing_secret)
    monkeypatch.setenv("V3_STORAGE_ROOT", str(runtime_harness.private_root))
    terminal_root = runtime_harness.storage_path / "private/v3/terminal"
    terminal_root.mkdir(parents=True, exist_ok=True)
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(terminal_root))
    runtime_harness.stop_bridge()
    runtime_harness.temporal_address = temporal_local_env.client.service_client.config.target_host
    runtime_harness.start_bridge()

    seeded = runtime_harness.seeded_data
    subject_id = seeded.get("subject_a", {}).get("id") or seeded.get("subject_id", 1)

    # 2. A Chromium page logs in, fills both upload rows and submits the form.
    if live_notebooklm:
        from pypdf import PdfReader, PdfWriter
        live_pdf1 = os.getenv("V3_WEB_LIVE_PDF1")
        live_pdf2 = os.getenv("V3_WEB_LIVE_PDF2")
        if live_qwen and (not live_pdf1 or not live_pdf2):
            pytest.fail("Live full-chain gate requires V3_WEB_LIVE_PDF1 and V3_WEB_LIVE_PDF2")
        if live_pdf1 and live_pdf2:
            pdf1_bytes = Path(live_pdf1).read_bytes()
            pdf2_bytes = Path(live_pdf2).read_bytes()
        else:
            sample_bytes = (runtime_harness.repo_root / "tests/Fixtures/V3/NotebookLM/sample.pdf").read_bytes()
            writer = PdfWriter()
            writer.append(PdfReader(io.BytesIO(sample_bytes)))
            writer.add_metadata({"/Title": "V3 Web Integration Live Test Copy"})
            second_pdf = io.BytesIO()
            writer.write(second_pdf)
            pdf1_bytes, pdf2_bytes = sample_bytes, second_pdf.getvalue()
    else:
        pdf1_bytes = make_minimal_pdf(1, "Kinematics Chapter 1. A body changes velocity when a net force acts on its mass.")
        pdf2_bytes = make_minimal_pdf(1, "Kinetics Chapter 2. Momentum is mass multiplied by velocity and changes under impulse.")
    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(headless=True)
        try:
            page = await browser.new_page()
            await page.goto(f"{runtime_harness.laravel_url}/login")
            await page.locator("#email").fill("teacher_a@example.com")
            await page.locator("#password").fill("password")
            await page.get_by_role("button", name="Sign In").click()
            await page.goto(f"{runtime_harness.laravel_url}/v3/teacher/subjects/{subject_id}/runs/create")
            assert await page.get_by_text("Upload Curriculum PDFs").count() > 0
            await page.locator('input[name="titles[]"]').nth(0).fill("01 Kinematics")
            await page.locator('input[name="files[]"]').nth(0).set_input_files({
                "name": "01_kinematics.pdf", "mimeType": "application/pdf", "buffer": pdf1_bytes,
            })
            await page.get_by_role("button", name="Add Another PDF").click()
            await page.locator('input[name="titles[]"]').nth(1).fill("02 Kinetics")
            await page.locator('input[name="files[]"]').nth(1).set_input_files({
                "name": "02_kinetics.pdf", "mimeType": "application/pdf", "buffer": pdf2_bytes,
            })
            async with page.expect_navigation():
                await page.get_by_role("button", name="Start Processing Run").click()
            assert page.url.startswith(f"{runtime_harness.laravel_url}/v3/teacher/subject-runs/"), page.url
            run_id = page.url.rstrip("/").split("/")[-1]
            assert await page.get_by_text("01 Kinematics").count() > 0
            assert await page.get_by_text("02 Kinetics").count() > 0
        finally:
            await browser.close()

    # 3. Database verification of browser intake
    conn = sqlite3.connect(str(runtime_harness.db_path))
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()

    cur.execute("SELECT * FROM v3_subject_runs WHERE id = ?", (run_id,))
    run_row = cur.fetchone()
    assert run_row is not None
    assert run_row["total_pdfs"] == 2
    assert run_row["state"] == "staging"

    cur.execute("SELECT * FROM v3_subject_run_items WHERE run_id = ? ORDER BY pdf_ordinal", (run_id,))
    items = cur.fetchall()
    assert len(items) == 2
    assert items[0]["display_title"] == "01 Kinematics"
    assert items[0]["input_sha256"] == hashlib.sha256(pdf1_bytes).hexdigest()
    assert items[1]["display_title"] == "02 Kinetics"
    assert items[1]["input_sha256"] == hashlib.sha256(pdf2_bytes).hexdigest()

    cur.execute("SELECT * FROM v3_pipeline_commands WHERE run_id = ? AND action = 'submit'", (run_id,))
    cmd_row = cur.fetchone()
    assert cmd_row is not None
    manifest_ref = cmd_row["private_payload_ref"]
    assert manifest_ref is not None

    cur.execute("SELECT * FROM v3_pipeline_outbox WHERE command_id = ?", (cmd_row["id"],))
    outbox_row = cur.fetchone()
    assert outbox_row is not None
    assert outbox_row["destination"] == "application_bridge"
    assert outbox_row["state"] == "pending"

    # 4. Production outbox -> signed bridge -> supervisor -> registered worker
    stop = asyncio.Event()
    workers = asyncio.create_task(run_workers(
        temporal_local_env.client,
        lock_path=runtime_harness.test_root / "worker.lock",
        stop_event=stop,
    ))
    try:
        dispatch = await asyncio.to_thread(runtime_harness.dispatch_outbox)
        assert dispatch["dispatched_count"] == 1, dispatch
        cur.execute("SELECT state FROM v3_pipeline_outbox WHERE command_id = ?", (cmd_row["id"],))
        assert cur.fetchone()[0] == "acknowledged"
        handle = temporal_local_env.client.get_workflow_handle(f"v3-web-subject-{run_id}")
        workflow_result = await asyncio.wait_for(handle.result(), timeout=300 if live_notebooklm else 120)
    finally:
        stop.set()
        await workers
    assert workflow_result["outcome"] in ("prompts_exported", "completed"), f"Workflow failed: {workflow_result}"
    assert workflow_result["safe_code"] == "ok"
    assert workflow_result["pdfs_completed"] == 2
    assert workflow_result["pdfs_failed"] == 0
    assert workflow_result["lessons_saved"] > 0
    assert workflow_result["prompts_saved"] > 0
    expected_prompt_count = workflow_result["prompts_saved"]

    # 5. Verify Transport Invariants
    if transport is not None:
        transport.assert_framework_uploaded_once()
        transport.assert_divide_scope_pdf_only()
        transport.assert_lesson_scope_includes_framework()
        transport.assert_reset_between_pdfs()

    # 6. Verify Database State
    cur.execute("SELECT id, title, pdf_path FROM lectures WHERE subject_id = ? ORDER BY id", (subject_id,))
    lectures = cur.fetchall()
    assert len(lectures) >= 2
    for lec in lectures:
        assert lec["pdf_path"] is None, "Lecture pdf_path must remain private/null"

    cur.execute(
        "SELECT COUNT(*) FROM v3_processing_cases WHERE id IN (SELECT case_id FROM v3_subject_run_items WHERE run_id = ?)",
        (run_id,),
    )
    cases_count = cur.fetchone()[0]
    assert cases_count == 2, f"Expected 2 processing cases, got {cases_count}"

    cur.execute(
        "SELECT COUNT(*) FROM v3_scene_prompt_versions WHERE case_id IN (SELECT case_id FROM v3_subject_run_items WHERE run_id = ?)",
        (run_id,),
    )
    prompts_count = cur.fetchone()[0]
    assert prompts_count == expected_prompt_count, f"Expected {expected_prompt_count} saved prompt versions, got {prompts_count}"

    # The owner-facing run page must reflect the persisted processing result,
    # and the admin page must expose operational metadata without content.
    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(headless=True)
        try:
            owner_page = await browser.new_page()
            await owner_page.goto(f"{runtime_harness.laravel_url}/login")
            await owner_page.locator("#email").fill("teacher_a@example.com")
            await owner_page.locator("#password").fill("password")
            await owner_page.get_by_role("button", name="Sign In").click()
            await owner_page.goto(f"{runtime_harness.laravel_url}/v3/teacher/subject-runs/{run_id}")
            assert await owner_page.get_by_text("Subject Run Details").count() == 1
            assert await owner_page.get_by_text("Ordered PDF Items").count() == 1
            assert await owner_page.get_by_text("01 Kinematics").count() == 1
            assert await owner_page.get_by_text("02 Kinetics").count() == 1
            owner_text = await owner_page.locator("body").inner_text()
            assert "Completed" in owner_text or "Prompts_ready" in owner_text

            admin_page = await browser.new_page()
            await admin_page.goto(f"{runtime_harness.laravel_url}/login")
            await admin_page.locator("#email").fill("admin_v3@example.com")
            await admin_page.locator("#password").fill("password")
            await admin_page.get_by_role("button", name="Sign In").click()
            await admin_page.goto(f"{runtime_harness.laravel_url}/v3/admin/subject-runs")
            admin_text = await admin_page.locator("body").inner_text()
            assert "Admin - Subject Runs Operations" in admin_text
            assert "Operational Boundary" in admin_text
            assert run_id[:8] in admin_text
            assert "01 Kinematics" not in admin_text and "02 Kinetics" not in admin_text
        finally:
            await browser.close()

    async with runtime_harness.create_browser_client() as client:
        assert await runtime_harness.login_teacher(client)
        exported_json = await client.get(
            f"/v3/teacher/subject-runs/{run_id}/prompt-export",
            headers={"Accept": "application/json"},
        )
        assert exported_json.status_code == 200, exported_json.text[:300]
        export = exported_json.json()
        assert export["total_prompts"] == prompts_count
        for entry in export["prompts"]:
            assert entry["prompt_text"]
            assert hashlib.sha256(entry["prompt_text"].encode("utf-8")).hexdigest() == entry["prompt_sha256"]
        exported_text = await client.get(f"/v3/teacher/subject-runs/{run_id}/prompt-export?format=text")
        assert exported_text.status_code == 200
        assert f"Total Prompts: {prompts_count}" in exported_text.text

    # Continue the SAME web-created run into a teacher manual scene and a
    # real Chromium qualification check. No lesson/prompt rows are seeded.
    cur.execute(
        "SELECT p.id, p.section_id, p.case_id FROM v3_scene_prompt_versions p "
        "JOIN v3_subject_run_items i ON i.case_id = p.case_id "
        "WHERE i.run_id = ? ORDER BY i.pdf_ordinal, p.created_at LIMIT 1",
        (run_id,),
    )
    prompt_id, section_id, case_id = cur.fetchone()
    package = {
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<!doctype html><html lang='en'><head><title>Motion Scene</title><link rel='stylesheet' href='styles.css'></head><body><h1>Motion Scene</h1><canvas id='scene' width='800' height='500'></canvas><button id='toggle' aria-label='Toggle motion'>Toggle motion</button><script src='script.js'></script></body></html>"},
            {"path": "styles.css", "content": "body{margin:0;background:#111;color:white;font-family:sans-serif}canvas{display:block;width:100%;max-width:800px}button{padding:12px}"},
            {"path": "script.js", "content": "const c=document.getElementById('scene');const x=c.getContext('2d');x.fillStyle='#38bdf8';x.fillRect(0,0,100,100);const b=document.getElementById('toggle');let n=0;b.addEventListener('click',()=>{n++;b.textContent='Toggle motion '+n;b.setAttribute('data-count',n);x.fillStyle=n%2?'#e11d48':'#38bdf8';x.fillRect(0,0,100,100)});window.__scene_ready=true;"},
        ],
    }
    async with runtime_harness.create_browser_client() as client:
        assert await runtime_harness.login_teacher(client)
        scenes_page = await client.get(f"/v3/teacher/subject-runs/{run_id}/scenes")
        assert scenes_page.status_code == 200
        csrf_match = re.search(r'name="_token"\s+value="([^"]+)"', scenes_page.text)
        csrf_token = csrf_match.group(1) if csrf_match else client.cookies.get("XSRF-TOKEN")
        assert csrf_token
        response = await client.post(
            f"/v3/teacher/subject-runs/{run_id}/scenes/{section_id}/imports",
            json={
                "command_id": str(uuid.uuid4()),
                "prompt_version_id": prompt_id,
                "package": package,
                "waiver_acknowledged": True,
            },
            headers={"X-CSRF-TOKEN": csrf_token, "Accept": "application/json"},
        )
        assert response.status_code == 201, response.text
        candidate_id = response.json()["candidate_id"]

    from v3_engine.web_pipeline.check_activities import qualify_authoring_candidate_activity
    checked = await qualify_authoring_candidate_activity({
        "run_id": run_id, "case_id": case_id, "candidate_ref": candidate_id,
    })
    assert checked["outcome"] == "completed", checked
    assert checked["verdict"] == "pass", checked
    assert len(checked.get("checks", [])) == 7
    cur.execute("SELECT status FROM v3_scene_candidate_states WHERE candidate_id = ?", (candidate_id,))
    assert cur.fetchone()[0] == "demo_review_ready"
    cur.execute("SELECT id, state_version, candidate_version FROM v3_teacher_review_items WHERE candidate_id = ?", (candidate_id,))
    review_row = cur.fetchone()
    assert review_row is not None, "A passing manual candidate must become reviewable without a manual repair command"
    review_id, review_version, candidate_version = review_row

    # Drive the rendered website controls, including its JavaScript preview,
    # frame-zero approval gate, and post-approval correction form.
    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(headless=True)
        try:
            page = await browser.new_page()
            await page.goto(f"{runtime_harness.laravel_url}/login")
            await page.locator("#email").fill("teacher_a@example.com")
            await page.locator("#password").fill("password")
            await page.get_by_role("button", name="Sign In").click()
            await page.goto(f"{runtime_harness.laravel_url}/v3/teacher/cases/{case_id}/reviews/{review_id}")
            assert await page.locator("#v3-review-approve").is_disabled()
            await page.get_by_role("button", name="Start Preview").click()
            await page.locator("#v3-preview-img").wait_for(state="visible", timeout=15000)
            await page.get_by_role("button", name="Approve after frame 0 is shown").wait_for(state="visible")
            assert await page.locator("#v3-review-approve").is_enabled()
            async with page.expect_navigation(wait_until="domcontentloaded"):
                await page.locator("#v3-review-approve").click()
            correction_form = page.locator("#v3-postapproval-correction")
            await correction_form.wait_for(state="visible")
            await correction_form.locator("textarea[name=notes]").fill("Browser verified correction: increase label size.")
            async with page.expect_response(lambda response: response.request.method == "POST" and "/corrections" in response.url) as correction_response:
                await correction_form.get_by_role("button", name="Submit correction").click()
            response = await correction_response.value
            assert response.status == 201
        finally:
            await browser.close()
    cur.execute("SELECT id FROM v3_approved_scenes WHERE candidate_id = ?", (candidate_id,))
    approval_row = cur.fetchone()
    assert approval_row is not None
    approval_id = approval_row[0]
    cur.execute("SELECT id, state FROM v3_postapproval_corrections WHERE approval_id = ?", (approval_id,))
    correction_row = cur.fetchone()
    assert correction_row is not None and correction_row[1] == "accepted"
    cur.execute("SELECT id FROM v3_approved_scenes WHERE id = ? AND candidate_id = ?", (approval_id, candidate_id))
    assert cur.fetchone() is not None
    cur.execute(
        "SELECT id, workflow_id, state FROM v3_review_outbox "
        "WHERE review_decision_id = (SELECT id FROM v3_teacher_review_decisions WHERE candidate_id = ?)",
        (candidate_id,),
    )
    review_outbox_id, review_workflow_id, review_outbox_state = cur.fetchone()
    assert review_workflow_id == f"v3-web-review-{review_id}"
    assert review_outbox_state == "pending"

    review_stop = asyncio.Event()
    review_workers = asyncio.create_task(run_workers(
        temporal_local_env.client,
        lock_path=runtime_harness.test_root / "worker.lock",
        stop_event=review_stop,
    ))
    try:
        dispatch_result = await asyncio.to_thread(runtime_harness.dispatch_review_effects)
        assert "Dispatched 2 review effects" in dispatch_result, dispatch_result
        cur.execute("SELECT state FROM v3_review_outbox WHERE id = ?", (review_outbox_id,))
        assert cur.fetchone()[0] == "delivered"
        review_handle = temporal_local_env.client.get_workflow_handle(review_workflow_id)
        cur.execute("SELECT id FROM v3_teacher_review_decisions WHERE candidate_id = ?", (candidate_id,))
        decision_id = cur.fetchone()[0]
        assert await review_handle.query("query_decision_consumed", decision_id) is True
    finally:
        review_stop.set()
        await review_workers

    # Select a second prompt from this run for the API lane. The Qwen HTTP
    # boundary is scripted, while outbox, Temporal, authoring persistence and
    # Chromium qualification remain real.
    cur.execute(
        "SELECT p.id, p.section_id, p.case_id FROM v3_scene_prompt_versions p "
        "JOIN v3_subject_run_items i ON i.case_id = p.case_id "
        "WHERE i.run_id = ? AND p.section_id != ? ORDER BY i.pdf_ordinal, p.created_at LIMIT 1",
        (run_id, section_id),
    )
    api_prompt_row = cur.fetchone()
    if api_prompt_row is None:
        cur.execute(
            "SELECT p.id, p.section_id, p.case_id FROM v3_scene_prompt_versions p "
            "JOIN v3_subject_run_items i ON i.case_id = p.case_id "
            "WHERE i.run_id = ? ORDER BY i.pdf_ordinal, p.created_at LIMIT 1",
            (run_id,),
        )
        api_prompt_row = cur.fetchone()
    assert api_prompt_row is not None
    api_prompt_id, api_section_id, api_case_id = api_prompt_row
    async with runtime_harness.create_browser_client() as client:
        assert await runtime_harness.login_teacher(client)
        scenes_page = await client.get(f"/v3/teacher/subject-runs/{run_id}/scenes")
        assert scenes_page.status_code == 200
        csrf_match = re.search(r'name="_token"\s+value="([^"]+)"', scenes_page.text)
        csrf_token = csrf_match.group(1) if csrf_match else client.cookies.get("XSRF-TOKEN")
        headers = {"X-CSRF-TOKEN": csrf_token, "Accept": "application/json"}
        qwen_model = os.getenv("QWEN_MODEL", "qwen-plus") if live_qwen else "qwen-plus"
        selection = await client.post(
            f"/v3/teacher/subject-runs/{run_id}/generation-mode",
            json={"mode": "selected_api", "provider_id": "qwen_api", "model": qwen_model},
            headers=headers,
        )
        assert selection.status_code == 200, selection.text
        request = await client.post(
            f"/v3/teacher/subject-runs/{run_id}/scenes/{api_section_id}/authoring-request",
            json={"waiver_acknowledged": True}, headers=headers,
        )
        assert request.status_code == 202, request.text
        authoring_request_id = request.json()["data"]["request_id"]

    from v3_engine.authoring.activity import AuthoringRunner
    qwen_sends = []
    def qwen_transport(request: httpx.Request) -> httpx.Response:
        qwen_sends.append(request)
        return httpx.Response(200, json={
            "id": "chatcmpl-joined-offline",
            "model": "qwen-plus",
            "choices": [{"finish_reason": "stop", "message": {"role": "assistant", "content": json.dumps(package)}}],
            "usage": {"prompt_tokens": 100, "completion_tokens": 300},
        })
    qwen_client = None
    if live_qwen:
        if not os.getenv("QWEN_API_KEY") or not os.getenv("QWEN_MODEL"):
            pytest.fail("Live Qwen gate requires QWEN_API_KEY and QWEN_MODEL")
        AuthoringRunner._transport_client = None
    else:
        qwen_client = httpx.AsyncClient(transport=httpx.MockTransport(qwen_transport))
        AuthoringRunner._transport_client = qwen_client
        monkeypatch.setenv("QWEN_API_KEY", "joined-offline-qwen-key")
    author_stop = asyncio.Event()
    author_workers = asyncio.create_task(run_workers(
        temporal_local_env.client,
        lock_path=runtime_harness.test_root / "worker.lock",
        stop_event=author_stop,
    ))
    try:
        dispatched = await asyncio.to_thread(runtime_harness.dispatch_outbox)
        assert dispatched["dispatched_count"] == 1, dispatched
        author_handle = temporal_local_env.client.get_workflow_handle(
            f"v3-web-authoring-{authoring_request_id}"
        )
        author_result = await asyncio.wait_for(author_handle.result(), timeout=120)
        assert author_result["outcome"] == "completed", author_result
        assert author_result["verdict"] == "pass", author_result
        if live_qwen:
            assert qwen_sends == [], "Live Qwen must use the configured network client, not a test transport."
        else:
            assert len(qwen_sends) == 1
            assert qwen_sends[0].headers.get("authorization") == "Bearer joined-offline-qwen-key"
        cur.execute("SELECT id, origin FROM v3_scene_candidates WHERE request_id = ?", (authoring_request_id,))
        api_candidate = cur.fetchone()
        assert api_candidate is not None and api_candidate[1] == "web"
        cur.execute("SELECT status FROM v3_scene_candidate_states WHERE candidate_id = ?", (api_candidate[0],))
        assert cur.fetchone()[0] == "demo_review_ready"
        cur.execute("SELECT id, case_id FROM v3_teacher_review_items WHERE candidate_id = ?", (api_candidate[0],))
        api_review_row = cur.fetchone()
        assert api_review_row is not None
        api_review_id, api_review_case_id = api_review_row
        cur.execute(
            "SELECT provider_id, requested_model, state, returned_model, provider_response_id "
            "FROM v3_coding_attempts WHERE request_id = ?",
            (authoring_request_id,),
        )
        attempt_row = cur.fetchone()
        assert attempt_row is not None and attempt_row[0] == "qwen_api"
        assert attempt_row[1] == qwen_model and attempt_row[2] == "candidate_ready"
        assert attempt_row[3] and attempt_row[4]
    finally:
        author_stop.set()
        await author_workers
        AuthoringRunner._transport_client = None
        if qwen_client is not None:
            await qwen_client.aclose()

    # The selected API candidate must pass the same website preview and review
    # flow as a manual candidate; the live gate must not stop at qualification.
    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(headless=True)
        try:
            page = await browser.new_page()
            await page.goto(f"{runtime_harness.laravel_url}/login")
            await page.locator("#email").fill("teacher_a@example.com")
            await page.locator("#password").fill("password")
            await page.get_by_role("button", name="Sign In").click()
            await page.goto(
                f"{runtime_harness.laravel_url}/v3/teacher/cases/{api_review_case_id}/reviews/{api_review_id}"
            )
            assert await page.locator("#v3-review-approve").is_disabled()
            await page.get_by_role("button", name="Start Preview").click()
            await page.locator("#v3-preview-img").wait_for(state="visible", timeout=15000)
            assert await page.locator("#v3-review-approve").is_enabled()
            async with page.expect_navigation(wait_until="domcontentloaded"):
                await page.locator("#v3-review-approve").click()
        finally:
            await browser.close()

    cur.execute("SELECT id FROM v3_teacher_review_decisions WHERE review_item_id = ?", (api_review_id,))
    api_decision = cur.fetchone()
    assert api_decision is not None
    api_decision_id = api_decision[0]
    cur.execute(
        "SELECT workflow_id, state FROM v3_review_outbox WHERE review_decision_id = ?",
        (api_decision_id,),
    )
    api_outbox_row = cur.fetchone()
    assert api_outbox_row is not None and api_outbox_row[1] == "pending"
    api_review_workflow_id = api_outbox_row[0]
    api_review_stop = asyncio.Event()
    api_review_workers = asyncio.create_task(run_workers(
        temporal_local_env.client,
        lock_path=runtime_harness.test_root / "api-review-worker.lock",
        stop_event=api_review_stop,
    ))
    try:
        await asyncio.to_thread(runtime_harness.dispatch_review_effects)
        api_review_handle = temporal_local_env.client.get_workflow_handle(api_review_workflow_id)
        assert await api_review_handle.query("query_decision_consumed", api_decision_id) is True
        cur.execute("SELECT state FROM v3_review_outbox WHERE review_decision_id = ?", (api_decision_id,))
        assert cur.fetchone()[0] == "delivered"
    finally:
        api_review_stop.set()
        await api_review_workers

    conn.close()


@pytest.mark.asyncio
async def test_partial_failure_and_uncertainty_semantics():
    """Verify partial failure, explicit holds on uncertainty/auth/quota/storage, and retained recovery (T133)."""
    from v3_engine.web_pipeline.subject_workflow import ApplicationSubjectWorkflow

    # 1. Settled PDF-local failure: PDF 1 fails locally with map_unreadable, settles via reset, PDF 2 succeeds
    wf1 = ApplicationSubjectWorkflow()
    manifest = {
        "run_id": str(uuid.uuid4()),
        "items": [
            {"item_id": "item-1", "pdf_ordinal": 1, "source_ref": "src-1"},
            {"item_id": "item-2", "pdf_ordinal": 2, "source_ref": "src-2"},
        ],
        "framework_ref": "fw-1",
        "mode": "export_manual",
    }

    resets = []
    async def dispatch_local_failure(name: str, args: dict):
        if name == "execute_divide_turn":
            if args.get("pdf_ordinal") == 1:
                return {"outcome": "failed", "safe_code": "map_unreadable"}
            return {"outcome": "completed", "safe_code": "ok", "map": {"lessons": [{"ordinal": 1}]}}
        elif name == "execute_lesson_turns":
            return {"outcome": "completed", "safe_code": "ok", "counts": {"lessons_saved": 2, "prompts_saved": 3}}
        elif name == "reset_conversation_activity":
            resets.append(args.get("pdf_ordinal"))
            return {"outcome": "completed", "safe_code": "ok"}
        elif name == "export_prompts_activity":
            return {"outcome": "completed", "safe_code": "ok"}
        return {"outcome": "completed", "safe_code": "ok"}

    wf1._activity_executor = dispatch_local_failure
    res1 = await wf1.run(manifest)
    assert res1["outcome"] == "partial"
    assert res1["pdfs_completed"] == 1
    assert res1["pdfs_failed"] == 1
    assert res1["lessons_saved"] == 2
    assert res1["prompts_saved"] == 3
    assert 1 in resets, "Failed PDF 1 must be settled via isolation reset before continuing"

    # 2. Authentication failure holds immediately before unsafe next work
    wf_auth = ApplicationSubjectWorkflow()
    async def dispatch_auth_failure(name: str, args: dict):
        if name == "execute_divide_turn":
            return {"outcome": "blocked", "safe_code": "authentication_required"}
        return {"outcome": "completed", "safe_code": "ok"}

    wf_auth._activity_executor = dispatch_auth_failure
    res_auth = await wf_auth.run(manifest)
    assert res_auth["outcome"] == "paused_auth"
    assert res_auth["safe_code"] == "authentication_required"
    assert wf_auth.status == "paused_auth"
    assert wf_auth.pdfs_completed == 0

    # 3. Quota failure holds immediately before unsafe next work
    wf_quota = ApplicationSubjectWorkflow()
    async def dispatch_quota_failure(name: str, args: dict):
        if name == "execute_divide_turn":
            return {"outcome": "failed", "safe_code": "provider_rate_limit"}
        return {"outcome": "completed", "safe_code": "ok"}

    wf_quota._activity_executor = dispatch_quota_failure
    res_quota = await wf_quota.run(manifest)
    assert res_quota["outcome"] == "paused_quota"
    assert res_quota["safe_code"] == "provider_rate_limit"
    assert wf_quota.status == "paused_quota"

    # 4. Uncertain remote send holds immediately without advancing or repeating
    wf_unc = ApplicationSubjectWorkflow()
    async def dispatch_uncertain_send(name: str, args: dict):
        if name == "execute_divide_turn":
            return {"outcome": "uncertain", "safe_code": "delivery_uncertain"}
        return {"outcome": "completed", "safe_code": "ok"}

    wf_unc._activity_executor = dispatch_uncertain_send
    res_unc = await wf_unc.run(manifest)
    assert res_unc["outcome"] == "uncertain"
    assert res_unc["safe_code"] == "delivery_uncertain"
    assert wf_unc.status == "held_uncertain"

    # 5. Failed reset barrier prevents advancing to next PDF
    wf_reset_fail = ApplicationSubjectWorkflow()
    async def dispatch_reset_fail(name: str, args: dict):
        if name == "execute_divide_turn":
            return {"outcome": "failed", "safe_code": "map_unreadable"}
        elif name == "reset_conversation_activity":
            return {"outcome": "failed", "safe_code": "reset_uncertain"}
        return {"outcome": "completed", "safe_code": "ok"}

    wf_reset_fail._activity_executor = dispatch_reset_fail
    res_reset_fail = await wf_reset_fail.run(manifest)
    assert res_reset_fail["outcome"] == "failed"
    assert res_reset_fail["safe_code"] == "reset_uncertain"
    assert wf_reset_fail.status == "failed"

    # 6. All inputs failed cannot become completed
    wf_all_fail = ApplicationSubjectWorkflow()
    async def dispatch_all_fail(name: str, args: dict):
        if name == "execute_divide_turn":
            return {"outcome": "failed", "safe_code": "map_unreadable"}
        elif name == "reset_conversation_activity":
            return {"outcome": "completed", "safe_code": "ok"}
        elif name == "export_prompts_activity":
            return {"outcome": "completed", "safe_code": "ok"}
        return {"outcome": "completed", "safe_code": "ok"}

    wf_all_fail._activity_executor = dispatch_all_fail
    res_all_fail = await wf_all_fail.run(manifest)
    assert res_all_fail["outcome"] == "failed"
    assert res_all_fail["safe_code"] == "all_inputs_failed"
    assert wf_all_fail.status == "failed"


@pytest.mark.asyncio
async def test_manual_import_and_real_browser_qualification(runtime_harness):
    """T137 / A11, A12, A16: Connect manual import to real browser qualification over joined runtime."""
    import sqlite3
    from v3_engine.web_pipeline.check_activities import qualify_authoring_candidate_activity

    teacher_id = runtime_harness.seeded_data["teacher_a"]["id"]
    subject_id = runtime_harness.seeded_data["subject"]["id"]
    settings_id = runtime_harness.seeded_data["settings_version_id"]
    framework_id = runtime_harness.seeded_data["framework_version_id"]

    run_id = str(uuid.uuid4())
    item_id = str(uuid.uuid4())
    case_id = str(uuid.uuid4())
    doc_id = str(uuid.uuid4())
    map_id = str(uuid.uuid4())
    node_id = str(uuid.uuid4())
    query_id = str(uuid.uuid4())
    sec_id = str(uuid.uuid4())
    wi_id = str(uuid.uuid4())
    prompt_id = str(uuid.uuid4())
    now_str = "2026-10-12 00:00:00"

    prompt_text = "Create an interactive harmonic oscillator simulation with spring stiffness slider."
    prompt_sha = hashlib.sha256(prompt_text.encode("utf-8")).hexdigest()
    prompt_rel_path = f"prompt-versions/{case_id}/{sec_id}/v1_{prompt_sha}.txt"
    full_prompt_path = runtime_harness.private_root / prompt_rel_path
    full_prompt_path.parent.mkdir(parents=True, exist_ok=True)
    full_prompt_path.write_text(prompt_text, encoding="utf-8")

    # Seed run, item, case, section, prompt version in SQLite test DB
    conn = sqlite3.connect(str(runtime_harness.db_path))
    cur = conn.cursor()

    cur.execute(
        """INSERT INTO v3_subject_runs (
            id, owner_user_id, subject_id, origin, protocol,
            settings_version_id, framework_version_id, mode, qualification_mode,
            captured_settings_json, capture_sha256, submission_key, request_sha256,
            state, state_version, total_pdfs, total_lessons, total_scenes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            run_id, teacher_id, subject_id, "web", "notebooklm_per_pdf_web_v1",
            settings_id, framework_id, "export_manual", "owner_waived_demo",
            "{}", hashlib.sha256(b"{}").hexdigest(), str(uuid.uuid4()), hashlib.sha256(b"run").hexdigest(),
            "prompts_ready", 2, 1, 1, 1, now_str, now_str
        )
    )

    cur.execute(
        """INSERT INTO v3_subject_run_items (
            id, run_id, owner_user_id, pdf_ordinal, display_title,
            document_id, case_id, original_filename, input_sha256, input_bytes,
            state, outcome, safe_code, state_version, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            item_id, run_id, teacher_id, 1, "Harmonic Motion",
            doc_id, case_id, "harmonic.pdf", hashlib.sha256(b"pdf").hexdigest(), 100,
            "prompts_ready", "completed", "ok", 1, now_str, now_str
        )
    )

    cur.execute(
        """INSERT INTO v3_processing_cases (
            id, owner_user_id, document_id, settings_version_id, idempotency_key,
            action, state, state_version, pipeline_version, notebook_framework_version_id,
            subject_run_item_id, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            case_id, teacher_id, doc_id, settings_id, str(uuid.uuid4()),
            "process", "ready", 1, "notebooklm_per_pdf_web_v1", framework_id,
            item_id, now_str, now_str
        )
    )

    src_act_id = str(uuid.uuid4())
    cur.execute(
        """INSERT INTO v3_source_activations (id, case_id, workflow_id, state, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)""",
        (src_act_id, case_id, "wf-src", "ready", now_str, now_str)
    )

    src_id = str(uuid.uuid4())
    cur.execute(
        """INSERT INTO v3_extracted_sources (
            id, case_id, activation_id, document_id, settings_version_id,
            status, text_sha256, page_report, extractor_version, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (src_id, case_id, src_act_id, doc_id, settings_id, "stored", "sha_src", "{}", "v1", now_str, now_str)
    )
    bind_id = str(uuid.uuid4())
    cur.execute(
        """INSERT INTO v3_knowledge_source_bindings (
            id, case_id, extracted_source_id, adapter_kind, adapter_version,
            notebook_title, source_title, state, text_sha256, text_bytes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (bind_id, case_id, src_id, "nb", "1", "title", "src", "bound", "text_sha", 10, now_str, now_str)
    )
    map_act_id = str(uuid.uuid4())
    cur.execute(
        "INSERT INTO v3_map_activations (id, case_id, binding_id, workflow_id, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
        (map_act_id, case_id, bind_id, "w", "ready", now_str, now_str)
    )
    cur.execute(
        "INSERT INTO v3_lesson_maps (id, case_id, binding_id, activation_id, state, page_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        (map_id, case_id, bind_id, map_act_id, "ready", 1, now_str, now_str)
    )
    cur.execute(
        """INSERT INTO v3_lesson_nodes (
            id, map_id, kind, position_path, ordinal_in_parent, title, work_ordinal, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (node_id, map_id, "l", "001", 1, "Oscillator", 1, now_str, now_str)
    )
    cur.execute(
        """INSERT INTO v3_lesson_queries (
            id, case_id, map_id, kind, target_work_ordinal, state, send_count, request_sha256, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (query_id, case_id, map_id, "first", 1, "recorded", 1, "q_sha", now_str, now_str)
    )
    cur.execute(
        """INSERT INTO v3_teacher_sections (
            id, case_id, map_id, work_node_id, query_id, ordinal_in_work,
            section_identity, core_understanding, first_prompt_sha256, answer_sha256,
            origin, subject_run_item_id, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            sec_id, case_id, map_id, node_id, query_id, 1,
            "Harmonic Oscillator", "Dynamics of harmonic motion",
            hashlib.sha256(b"f").hexdigest(), hashlib.sha256(b"a").hexdigest(),
            "pipeline", item_id, now_str, now_str
        )
    )
    cur.execute(
        """INSERT INTO v3_prompt_work_items (
            id, case_id, map_id, work_node_id, work_ordinal, expected_cursor_version,
            section_query_id, state, section_refs, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (wi_id, case_id, map_id, node_id, 1, 1, query_id, "receipt_committed", json.dumps([sec_id]), now_str, now_str)
    )
    cur.execute(
        """INSERT INTO v3_scene_prompt_versions (
            id, case_id, work_item_id, section_id, version_no, origin,
            body_path, body_sha256, body_bytes, scene_type,
            section_fields_sha256, canonical_third_sha256, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            prompt_id, case_id, wi_id, sec_id, 1, "third",
            prompt_rel_path, prompt_sha, len(prompt_text), "Interactive 2D Canvas",
            hashlib.sha256(b"fields").hexdigest(), hashlib.sha256(b"third").hexdigest(),
            now_str
        )
    )
    cur.execute(
        """INSERT INTO v3_prompt_section_states (
            section_id, case_id, current_prompt_version_id, status, revision_count, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?)""",
        (sec_id, case_id, prompt_id, "awaiting_manual", 0, now_str, now_str)
    )
    conn.commit()
    conn.close()

    async with runtime_harness.create_browser_client() as client:
        login_ok = await runtime_harness.login_teacher(client, "teacher_a@example.com", "password")
        assert login_ok is True, "Owning teacher login failed"

        # Fetch CSRF token from scenes page
        scenes_page = await client.get(f"/v3/teacher/subject-runs/{run_id}/scenes")
        assert scenes_page.status_code == 200, f"Error: {scenes_page.status_code} {scenes_page.text[:500]}"
        csrf_match = re.search(r'name="_token"\s+value="([^"]+)"', scenes_page.text)
        csrf_token = csrf_match.group(1) if csrf_match else client.cookies.get("XSRF-TOKEN")
        headers = {"X-CSRF-TOKEN": csrf_token, "Accept": "application/json"}

        # 1. Post valid 3-file package
        command_id = str(uuid.uuid4())
        valid_package = {
            "schema_version": 1,
            "files": [
                {
                    "path": "index.html",
                    "content": "<!DOCTYPE html><html lang=\"en\"><head><title>Harmonic Oscillator Simulation</title><link rel=\"stylesheet\" href=\"styles.css\"></head><body><h1>Harmonic Oscillator</h1><canvas id=\"c\" width=\"800\" height=\"500\"></canvas><button id=\"btn\" aria-label=\"Toggle Spring\">Toggle Spring</button><script src=\"script.js\"></script></body></html>"
                },
                {
                    "path": "styles.css",
                    "content": "body { margin: 0; background: #111; color: #fff; font-family: sans-serif; } canvas { display: block; width: 100%; max-width: 800px; height: 500px; } button { margin: 10px; padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; }"
                },
                {
                    "path": "script.js",
                    "content": "const canvas = document.getElementById('c'); const ctx = canvas ? canvas.getContext('2d') : null; if (ctx) { ctx.fillStyle = '#38bdf8'; ctx.fillRect(0, 0, 100, 100); } window.__scene_ready = true; const btn = document.getElementById('btn'); let count = 0; function toggle() { count++; btn.setAttribute('data-count', count); btn.textContent = 'Toggle Spring (' + count + ')'; if (ctx) { ctx.fillStyle = count % 2 === 0 ? '#38bdf8' : '#e11d48'; ctx.fillRect(0, 0, 100, 100); } } if (btn) { btn.addEventListener('click', toggle); btn.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { toggle(); } }); }"
                }
            ]
        }
        resp_import = await client.post(
            f"/v3/teacher/subject-runs/{run_id}/scenes/{sec_id}/imports",
            json={
                "command_id": command_id,
                "expected_version": 2,
                "prompt_version_id": prompt_id,
                "package": valid_package,
                "user_reported_provider": "Anthropic Claude",
                "user_reported_model": "claude-3-7-sonnet",
                "waiver_acknowledged": True,
            },
            headers=headers,
        )
        assert resp_import.status_code == 201, f"Import failed: {resp_import.text}"
        data_import = resp_import.json()
        candidate_id = data_import["candidate_id"]
        import_id = data_import["import_id"]
        assert candidate_id and import_id

        # 2. Idempotent re-submission with same command_id
        resp_replay = await client.post(
            f"/v3/teacher/subject-runs/{run_id}/scenes/{sec_id}/imports",
            json={
                "command_id": command_id,
                "expected_version": 2,
                "prompt_version_id": prompt_id,
                "package": valid_package,
                "user_reported_provider": "Anthropic Claude",
                "user_reported_model": "claude-3-7-sonnet",
                "waiver_acknowledged": True,
            },
            headers=headers,
        )
        assert resp_replay.status_code == 200
        assert resp_replay.json()["candidate_id"] == candidate_id
        assert resp_replay.json()["is_replay"] is True

        # Invariant check: zero authoring requests, zero coding attempts, zero prompt qualifications
        conn = sqlite3.connect(str(runtime_harness.db_path))
        cur = conn.cursor()
        cur.execute("SELECT COUNT(*) FROM v3_authoring_requests WHERE case_id = ?", (case_id,))
        assert cur.fetchone()[0] == 0
        cur.execute("SELECT COUNT(*) FROM v3_coding_attempts WHERE case_id = ?", (case_id,))
        assert cur.fetchone()[0] == 0
        cur.execute("SELECT COUNT(*) FROM v3_prompt_qualifications WHERE section_id = ?", (sec_id,))
        assert cur.fetchone()[0] == 0

        # Assert candidate origin and metadata
        cur.execute("SELECT origin, file_count, manual_import_id FROM v3_scene_candidates WHERE id = ?", (candidate_id,))
        cand_row = cur.fetchone()
        assert cand_row[0] == "manual_import"
        assert cand_row[1] == 3
        assert cand_row[2] == import_id
        conn.close()

        # 3. Schedule and run real browser qualification
        os.environ["V3_LARAVEL_CALLBACK_URL"] = f"{runtime_harness.laravel_url}/api/internal/v1/case-events"
        os.environ["V3_CALLBACK_HMAC_KEY"] = runtime_harness.signing_secret

        check_outcome = await qualify_authoring_candidate_activity({
            "run_id": run_id,
            "case_id": case_id,
            "candidate_ref": candidate_id,
        })
        assert check_outcome["outcome"] == "completed"
        assert check_outcome["verdict"] == "pass", f"Checks: {check_outcome.get('checks')}"

        # Assert candidate state becomes demo_review_ready
        conn = sqlite3.connect(str(runtime_harness.db_path))
        cur = conn.cursor()
        cur.execute("SELECT status FROM v3_scene_candidate_states WHERE candidate_id = ?", (candidate_id,))
        assert cur.fetchone()[0] == "demo_review_ready"

        # Assert check run record exists and passed
        cur.execute("SELECT verdict FROM v3_scene_check_runs WHERE candidate_id = ?", (candidate_id,))
        assert cur.fetchone()[0] == "pass"
        conn.close()

        # 4. Broken package fails qualification and prevents review readiness
        broken_command_id = str(uuid.uuid4())
        broken_package = {
            "schema_version": 1,
            "files": [
                {
                    "path": "index.html",
                    "content": "<!DOCTYPE html><html><body><script src='script.js'></script></body></html>"
                },
                {"path": "styles.css", "content": "body {}"},
                {"path": "script.js", "content": "const syntax_err = ;"}
            ]
        }
        resp_broken = await client.post(
            f"/v3/teacher/subject-runs/{run_id}/scenes/{sec_id}/imports",
            json={
                "command_id": broken_command_id,
                "expected_version": 2,
                "prompt_version_id": prompt_id,
                "package": broken_package,
                "waiver_acknowledged": True,
            },
            headers=headers,
        )
        assert resp_broken.status_code == 201
        broken_cand_id = resp_broken.json()["candidate_id"]

        broken_outcome = await qualify_authoring_candidate_activity({
            "run_id": run_id,
            "case_id": case_id,
            "candidate_ref": broken_cand_id,
        })
        assert broken_outcome["outcome"] == "completed"
        assert broken_outcome["verdict"] == "fail"

        conn = sqlite3.connect(str(runtime_harness.db_path))
        cur = conn.cursor()
        cur.execute("SELECT status FROM v3_scene_candidate_states WHERE candidate_id = ?", (broken_cand_id,))
        assert cur.fetchone()[0] == "repair_pending"
        conn.close()

        # 5. UI verifies truthful scene management and review eligibility
        scenes_ui = await client.get(f"/v3/teacher/subject-runs/{run_id}/scenes")
        assert scenes_ui.status_code == 200
        assert "Harmonic Motion" in scenes_ui.text, f"UI text: {scenes_ui.text}"
        assert "Manual Package Import" in scenes_ui.text


@pytest.mark.asyncio
async def test_selected_api_command_to_candidate_checks(runtime_harness):
    """T138 / A13, A16: Connect selected API command to candidate checks in joined runtime.

    Validates:
    - Teacher clicks generate on current saved prompt via UI with waiver acknowledgment.
    - Authoring request, command (action=generate_scene) and manifest created.
    - External generation transport double receives exactly 1 request with selected provider (qwen_api),
      model (qwen-plus), template, prompt, and no unselected provider requests.
    - Valid 3-file package returned, signed candidate callback records attempt and candidate.
    - Seven real Chromium qualification checks run and pass.
    - Candidate state advances to demo_review_ready.
    - Truthful UI reflects candidate origin and review eligibility.
    - Replay returns idempotent HTTP 200 with zero additional generation sends.
    """
    import sqlite3
    from v3_engine.authoring.activity import AuthoringRunner
    from v3_engine.authoring.activities import authoring_execute_attempts
    from v3_engine.web_pipeline.authoring_activities import resolve_web_authoring_manifest_activity
    from v3_engine.web_pipeline.check_activities import qualify_authoring_candidate_activity

    teacher_id = runtime_harness.seeded_data["teacher_a"]["id"]
    subject_id = runtime_harness.seeded_data["subject"]["id"]
    settings_id = runtime_harness.seeded_data["settings_version_id"]
    framework_id = runtime_harness.seeded_data["framework_version_id"]

    run_id = str(uuid.uuid4())
    item_id = str(uuid.uuid4())
    case_id = str(uuid.uuid4())
    doc_id = str(uuid.uuid4())
    map_id = str(uuid.uuid4())
    node_id = str(uuid.uuid4())
    query_id = str(uuid.uuid4())
    sec_id = str(uuid.uuid4())
    wi_id = str(uuid.uuid4())
    prompt_id = str(uuid.uuid4())
    now_str = "2026-10-12 00:00:00"

    prompt_text = "Create an interactive Wave-Particle Duality simulation with detector interference toggle."
    prompt_sha = hashlib.sha256(prompt_text.encode("utf-8")).hexdigest()
    prompt_rel_path = f"prompt-versions/{case_id}/{sec_id}/v1_{prompt_sha}.txt"
    full_prompt_path = runtime_harness.private_root / prompt_rel_path
    full_prompt_path.parent.mkdir(parents=True, exist_ok=True)
    full_prompt_path.write_text(prompt_text, encoding="utf-8")

    captured_settings = {
        "mode": "selected_api",
        "provider": {
            "id": "qwen_api",
            "name": "Alibaba Qwen",
            "requested_model": "qwen-plus",
            "credential_alias": "qwen_api_key",
        },
    }
    captured_json = json.dumps(captured_settings)

    # 1. Seed database with selected_api run, case, section, prompt version and approved template
    conn = sqlite3.connect(str(runtime_harness.db_path))
    cur = conn.cursor()

    cur.execute(
        """INSERT OR REPLACE INTO v3_approved_templates (id, kind, body, checksum, registered_at)
           VALUES (?, ?, ?, ?, ?)""",
        (
            "tmpl-scene-v1",
            "scene",
            "tmpl",
            hashlib.sha256(b"tmpl").hexdigest(),
            now_str,
        ),
    )

    cur.execute(
        """INSERT INTO v3_subject_runs (
            id, owner_user_id, subject_id, origin, protocol,
            settings_version_id, framework_version_id, mode, qualification_mode,
            captured_settings_json, capture_sha256, submission_key, request_sha256,
            state, state_version, total_pdfs, total_lessons, total_scenes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            run_id, teacher_id, subject_id, "web", "notebooklm_per_pdf_web_v1",
            settings_id, framework_id, "selected_api", "owner_waived_demo",
            captured_json, hashlib.sha256(captured_json.encode("utf-8")).hexdigest(),
            str(uuid.uuid4()), hashlib.sha256(b"api-run").hexdigest(),
            "prompts_ready", 1, 1, 1, 1, now_str, now_str,
        ),
    )

    cur.execute(
        """INSERT INTO v3_subject_run_items (
            id, run_id, owner_user_id, pdf_ordinal, display_title,
            document_id, case_id, original_filename, input_sha256, input_bytes,
            state, outcome, safe_code, state_version, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            item_id, run_id, teacher_id, 1, "Quantum Foundations",
            doc_id, case_id, "quantum.pdf", hashlib.sha256(b"quantum.pdf").hexdigest(), 1024,
            "prompts_ready", "completed", "ok", 1, now_str, now_str,
        ),
    )

    cur.execute(
        """INSERT INTO v3_processing_cases (
            id, owner_user_id, document_id, settings_version_id, idempotency_key,
            action, state, state_version, pipeline_version, notebook_framework_version_id,
            subject_run_item_id, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            case_id, teacher_id, doc_id, settings_id, str(uuid.uuid4()),
            "process", "ready", 1, "notebooklm_per_pdf_web_v1", framework_id,
            item_id, now_str, now_str,
        ),
    )

    src_act_id = str(uuid.uuid4())
    cur.execute(
        """INSERT INTO v3_source_activations (id, case_id, workflow_id, state, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)""",
        (src_act_id, case_id, "wf-src", "ready", now_str, now_str)
    )

    src_id = str(uuid.uuid4())
    cur.execute(
        """INSERT INTO v3_extracted_sources (
            id, case_id, activation_id, document_id, settings_version_id,
            status, text_sha256, page_report, extractor_version, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (src_id, case_id, src_act_id, doc_id, settings_id, "stored", "sha_src", "{}", "v1", now_str, now_str)
    )
    bind_id = str(uuid.uuid4())
    cur.execute(
        """INSERT INTO v3_knowledge_source_bindings (
            id, case_id, extracted_source_id, adapter_kind, adapter_version,
            notebook_title, source_title, state, text_sha256, text_bytes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (bind_id, case_id, src_id, "nb", "1", "title", "src", "bound", "text_sha", 10, now_str, now_str)
    )
    map_act_id = str(uuid.uuid4())
    cur.execute(
        "INSERT INTO v3_map_activations (id, case_id, binding_id, workflow_id, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
        (map_act_id, case_id, bind_id, "w", "ready", now_str, now_str)
    )
    cur.execute(
        "INSERT INTO v3_lesson_maps (id, case_id, binding_id, activation_id, state, page_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        (map_id, case_id, bind_id, map_act_id, "ready", 1, now_str, now_str)
    )
    cur.execute(
        """INSERT INTO v3_lesson_nodes (
            id, map_id, kind, position_path, ordinal_in_parent, title, work_ordinal, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (node_id, map_id, "l", "001", 1, "Quantum Foundations", 1, now_str, now_str)
    )
    cur.execute(
        """INSERT INTO v3_lesson_queries (
            id, case_id, map_id, kind, target_work_ordinal, state, send_count, request_sha256, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (query_id, case_id, map_id, "first", 1, "recorded", 1, "q_sha", now_str, now_str)
    )
    cur.execute(
        """INSERT INTO v3_teacher_sections (
            id, case_id, map_id, work_node_id, query_id, ordinal_in_work,
            section_identity, core_understanding, first_prompt_sha256, answer_sha256,
            origin, subject_run_item_id, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            sec_id, case_id, map_id, node_id, query_id, 1,
            "Wave Particle Duality", "Dynamics of wave-particle duality",
            hashlib.sha256(b"f").hexdigest(), hashlib.sha256(b"a").hexdigest(),
            "pipeline", item_id, now_str, now_str
        )
    )
    cur.execute(
        """INSERT INTO v3_prompt_work_items (
            id, case_id, map_id, work_node_id, work_ordinal, expected_cursor_version,
            section_query_id, state, section_refs, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (wi_id, case_id, map_id, node_id, 1, 1, query_id, "receipt_committed", json.dumps([sec_id]), now_str, now_str)
    )
    cur.execute(
        """INSERT INTO v3_scene_prompt_versions (
            id, case_id, work_item_id, section_id, version_no, origin,
            body_path, body_sha256, body_bytes, scene_type,
            section_fields_sha256, canonical_third_sha256, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            prompt_id, case_id, wi_id, sec_id, 1, "third",
            prompt_rel_path, prompt_sha, len(prompt_text), "Interactive 2D Canvas",
            hashlib.sha256(b"fields").hexdigest(), hashlib.sha256(b"third").hexdigest(),
            now_str
        )
    )
    cur.execute(
        """INSERT INTO v3_prompt_section_states (
            section_id, case_id, current_prompt_version_id, status, revision_count, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?)""",
        (sec_id, case_id, prompt_id, "prompt_ready", 0, now_str, now_str)
    )

    conn.commit()
    conn.close()

    # 2. Login teacher and request scene generation via browser client
    async with runtime_harness.create_browser_client() as client:
        logged_in = await runtime_harness.login_teacher(client)
        assert logged_in, "Teacher A login failed"

        # Fetch CSRF token from scenes page
        scenes_page = await client.get(f"/v3/teacher/subject-runs/{run_id}/scenes")
        assert scenes_page.status_code == 200, f"Error: {scenes_page.status_code} {scenes_page.text[:500]}"
        csrf_match = re.search(r'name="_token"\s+value="([^"]+)"', scenes_page.text)
        csrf_token = csrf_match.group(1) if csrf_match else client.cookies.get("XSRF-TOKEN")

        # Request generation
        headers = {
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": csrf_token,
        }
        gen_resp = await client.post(
            f"/v3/teacher/subject-runs/{run_id}/scenes/{sec_id}/authoring-request",
            json={"waiver_acknowledged": True},
            headers=headers,
        )
        assert gen_resp.status_code == 202, f"Expected 202, got {gen_resp.status_code}: {gen_resp.text}"
        req_data = gen_resp.json().get("data", {})
        request_id = req_data.get("request_id")
        assert request_id is not None
        assert req_data.get("state") == "pending"
        assert req_data.get("eligibility_basis") == "owner_waived_demo"

        # Verify command and manifest created
        conn = sqlite3.connect(str(runtime_harness.db_path))
        cur = conn.cursor()
        cur.execute("SELECT action, private_payload_ref FROM v3_pipeline_commands WHERE id = ?", (request_id,))
        cmd_row = cur.fetchone()
        assert cmd_row is not None
        assert cmd_row[0] == "generate_scene"
        manifest_ref = cmd_row[1]
        assert manifest_ref is not None

        # Verify waiver gate recorded
        cur.execute("SELECT basis FROM v3_qualification_gates WHERE section_id = ? AND gate_stage = 'prompt'", (sec_id,))
        gate_row = cur.fetchone()
        assert gate_row is not None
        assert gate_row[0] == "owner_waived_demo"
        conn.close()

        # 3. Setup external generation transport double for Alibaba Qwen
        captured_requests: list[dict[str, Any]] = []

        valid_package = {
            "schema_version": 1,
            "files": [
                {
                    "path": "index.html",
                    "content": (
                        "<!DOCTYPE html>\n"
                        "<html lang=\"en\">\n"
                        "<head>\n"
                        "  <meta charset=\"utf-8\">\n"
                        "  <title>Qwen Wave Simulation</title>\n"
                        "  <link rel=\"stylesheet\" href=\"styles.css\">\n"
                        "</head>\n"
                        "<body>\n"
                        "  <h1>Wave Particle Simulation</h1>\n"
                        "  <canvas id=\"wave-canvas\" width=\"800\" height=\"500\"></canvas>\n"
                        "  <div id=\"status\" aria-live=\"polite\">State: Initial</div>\n"
                        "  <button id=\"toggle-btn\" aria-label=\"Toggle Wave State\">Toggle Wave State</button>\n"
                        "  <script src=\"script.js\"></script>\n"
                        "</body>\n"
                        "</html>"
                    ),
                },
                {
                    "path": "styles.css",
                    "content": (
                        "body { margin: 0; background: #111; color: #fff; font-family: sans-serif; }\n"
                        "canvas { display: block; width: 100%; max-width: 800px; height: 500px; }\n"
                        "#toggle-btn { margin: 10px; padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; }"
                    ),
                },
                {
                    "path": "script.js",
                    "content": (
                        "const canvas = document.getElementById('wave-canvas');\n"
                        "const ctx = canvas ? canvas.getContext('2d') : null;\n"
                        "if (ctx) {\n"
                        "  ctx.fillStyle = '#38bdf8';\n"
                        "  ctx.fillRect(0, 0, 800, 500);\n"
                        "}\n"
                        "window.__scene_ready = true;\n"
                        "const btn = document.getElementById('toggle-btn');\n"
                        "const status = document.getElementById('status');\n"
                        "let count = 0;\n"
                        "function toggleState(e) {\n"
                        "  count++;\n"
                        "  btn.setAttribute('data-count', count);\n"
                        "  btn.textContent = 'Toggle Wave State (' + count + ')';\n"
                        "  status.textContent = count % 2 === 0 ? 'State: Wave Pattern' : 'State: Particle Collapse';\n"
                        "  if (ctx) {\n"
                        "    ctx.fillStyle = count % 2 === 0 ? '#38bdf8' : '#e11d48';\n"
                        "    ctx.fillRect(0, 0, 800, 500);\n"
                        "    ctx.fillStyle = '#ffffff';\n"
                        "    ctx.fillRect(50 * (count % 8), 100, 100, 100);\n"
                        "  }\n"
                        "}\n"
                        "if (btn) {\n"
                        "  btn.addEventListener('click', toggleState);\n"
                        "  btn.addEventListener('keydown', function(e) {\n"
                        "    if (e.key === 'Enter' || e.key === ' ') {\n"
                        "      toggleState(e);\n"
                        "    }\n"
                        "  });\n"
                        "}\n"
                    ),
                },
            ],
        }

        def mock_qwen_handler(request: httpx.Request) -> httpx.Response:
            url_str = str(request.url)
            # Assert only Qwen endpoint is called
            assert "dashscope.aliyuncs.com" in url_str, f"Unexpected endpoint called: {url_str}"
            assert "generativelanguage.googleapis.com" not in url_str
            assert "open.bigmodel.cn" not in url_str
            assert "api.deepseek.com" not in url_str

            body = json.loads(request.content.decode("utf-8"))
            captured_requests.append({
                "method": request.method,
                "url": url_str,
                "headers": dict(request.headers),
                "body": body,
            })

            qwen_response = {
                "id": "chatcmpl-qwen-test-abc",
                "model": "qwen-plus",
                "choices": [
                    {
                        "finish_reason": "stop",
                        "message": {
                            "role": "assistant",
                            "content": json.dumps(valid_package),
                        },
                    }
                ],
                "usage": {
                    "prompt_tokens": 120,
                    "completion_tokens": 480,
                },
            }
            return httpx.Response(200, json=qwen_response)

        mock_transport = httpx.MockTransport(mock_qwen_handler)
        transport_client = httpx.AsyncClient(transport=mock_transport)
        AuthoringRunner._transport_client = transport_client

        # Configure environment for workers and internal callbacks
        os.environ["QWEN_API_KEY"] = "test-qwen-api-key"
        os.environ["V3_LARAVEL_CALLBACK_URL"] = f"{runtime_harness.laravel_url}/api/internal/v1/case-events"
        os.environ["V3_CALLBACK_HMAC_KEY"] = runtime_harness.signing_secret
        os.environ["V3_STORAGE_ROOT"] = str(runtime_harness.private_root)

        try:
            # 4. Resolve authoring manifest via production activity
            resolved = await resolve_web_authoring_manifest_activity({
                "run_id": run_id,
                "manifest_ref": manifest_ref,
            })
            assert resolved["outcome"] == "completed"
            assert resolved["case_id"] == case_id
            assert resolved["request_ref"] == request_id

            # 5. Execute authoring attempts via production activity
            authored = await authoring_execute_attempts({
                "case_id": case_id,
                "request_ref": request_id,
            })
            assert authored["status"] == "candidate_ready", f"Authoring outcome: {authored}"
            cand_id = authored.get("candidate_ref")
            assert cand_id is not None

            # Assert external generation transport invariants (A13):
            # Exactly 1 request sent, correct provider, requested model, authentication
            assert len(captured_requests) == 1, f"Expected 1 external request, got {len(captured_requests)}"
            captured = captured_requests[0]
            assert captured["method"] == "POST"
            assert captured["headers"].get("authorization") == "Bearer test-qwen-api-key"
            assert captured["body"]["model"] == "qwen-plus"
            assert len(captured["body"]["messages"]) == 2
            assert captured["body"]["messages"][0]["role"] == "system"
            assert captured["body"]["messages"][1]["role"] == "user"

            # 6. Qualify candidate via real isolated Chromium browser checks (A16)
            checked = await qualify_authoring_candidate_activity({
                "run_id": run_id,
                "case_id": case_id,
                "candidate_ref": cand_id,
            })
            assert checked["outcome"] == "completed"
            assert checked["verdict"] == "pass", f"Check failure: {checked.get('checks')}"
            checks = checked.get("checks", [])
            assert len(checks) == 7, f"Expected 7 Chromium checks, got {len(checks)}"
            check_codes = {c.get("code") for c in checks}
            assert check_codes == {
                "integrity",
                "entrypoint",
                "assets",
                "runtime",
                "network",
                "interaction",
                "accessibility",
            }
            for c in checks:
                assert c.get("status") == "pass", f"Check {c.get('code')} failed: {c}"

            # 7. Assert durable database invariants
            conn = sqlite3.connect(str(runtime_harness.db_path))
            cur = conn.cursor()

            # Authoring request state
            cur.execute("SELECT state FROM v3_authoring_requests WHERE id = ?", (request_id,))
            assert cur.fetchone()[0] == "candidate_ready"

            # Coding attempt record
            cur.execute(
                "SELECT ordinal, state, provider_id, requested_model, returned_model, duration_ms, input_tokens, output_tokens FROM v3_coding_attempts WHERE request_id = ?",
                (request_id,),
            )
            attempt_row = cur.fetchone()
            assert attempt_row is not None
            assert attempt_row[0] == 1
            assert attempt_row[1] == "candidate_ready"
            assert attempt_row[2] == "qwen_api"
            assert attempt_row[3] == "qwen-plus"
            assert attempt_row[4] == "qwen-plus"
            assert attempt_row[5] >= 0
            assert attempt_row[6] == 120
            assert attempt_row[7] == 480

            # Candidate record & provenance
            cur.execute("SELECT origin, file_count, total_bytes, package_profile FROM v3_scene_candidates WHERE id = ?", (cand_id,))
            cand_row = cur.fetchone()
            assert cand_row is not None
            assert cand_row[0] == "web"
            assert cand_row[1] == 3
            assert cand_row[2] > 0
            assert cand_row[3] == "standard"

            # Candidate state is demo_review_ready
            cur.execute("SELECT status FROM v3_scene_candidate_states WHERE candidate_id = ?", (cand_id,))
            assert cur.fetchone()[0] == "demo_review_ready"

            # Check run verdict pass
            cur.execute("SELECT verdict, duration_ms, screenshot_path FROM v3_scene_check_runs WHERE candidate_id = ?", (cand_id,))
            check_row = cur.fetchone()
            assert check_row is not None
            assert check_row[0] == "pass"
            assert check_row[1] > 0
            assert check_row[2] is not None
            conn.close()

            # 8. Truthful UI projection reflects generated candidate and review readiness
            scenes_ui = await client.get(f"/v3/teacher/subject-runs/{run_id}/scenes")
            assert scenes_ui.status_code == 200
            assert "Wave Particle Duality" in scenes_ui.text
            assert "API Generation" in scenes_ui.text
            assert "Demo Review Ready" in scenes_ui.text

            # 9. Replay check: second generate request is idempotent
            replay_resp = await client.post(
                f"/v3/teacher/subject-runs/{run_id}/scenes/{sec_id}/authoring-request",
                json={"waiver_acknowledged": True},
                headers=headers,
            )
            assert replay_resp.status_code == 200, f"Expected replay 200, got {replay_resp.status_code}"
            assert replay_resp.json().get("data", {}).get("request_id") == request_id
            # Zero additional external generation requests
            assert len(captured_requests) == 1, "Replay must not trigger another external provider send"

        finally:
            AuthoringRunner._transport_client = None
            await transport_client.aclose()


@pytest.mark.asyncio
async def test_scene_response_build_and_uncertain_send_recovery(runtime_harness: JoinedRuntimeHarness) -> None:
    """T139 / A14, A15: Prove response, build, and uncertain-send recovery end-to-end.

    1. Uncertain send halts fallback, records uncertain state, zero automatic resends.
    2. Explicit new consented attempt resets uncertain state and generates candidate under same prompt/provider.
    3. Tampered source fails closed in browser qualification (artifact_corrupt).
    4. Local response and qualification recovery executes with zero additional external provider sends.
    """
    import sqlite3
    from v3_engine.authoring.activity import AuthoringRunner
    from v3_engine.authoring.activities import authoring_execute_attempts
    from v3_engine.web_pipeline.authoring_activities import resolve_web_authoring_manifest_activity
    from v3_engine.web_pipeline.check_activities import qualify_authoring_candidate_activity

    teacher_id = runtime_harness.seeded_data["teacher_a"]["id"]
    subject_id = runtime_harness.seeded_data["subject"]["id"]
    settings_id = runtime_harness.seeded_data["settings_version_id"]
    framework_id = runtime_harness.seeded_data["framework_version_id"]

    run_id = str(uuid.uuid4())
    item_id = str(uuid.uuid4())
    case_id = str(uuid.uuid4())
    doc_id = str(uuid.uuid4())
    map_id = str(uuid.uuid4())
    node_id = str(uuid.uuid4())
    query_id = str(uuid.uuid4())
    sec_id = str(uuid.uuid4())
    wi_id = str(uuid.uuid4())
    prompt_id = str(uuid.uuid4())
    now_str = "2026-10-12 00:00:00"

    prompt_text = "Educational Scene: Simulate Carnot cycle with PV diagram."
    prompt_sha = hashlib.sha256(prompt_text.encode("utf-8")).hexdigest()
    prompt_rel_path = f"prompt-versions/{case_id}/{sec_id}/v1_{prompt_sha}.txt"
    full_prompt_path = runtime_harness.private_root / prompt_rel_path
    full_prompt_path.parent.mkdir(parents=True, exist_ok=True)
    full_prompt_path.write_text(prompt_text, encoding="utf-8")

    captured_settings = {
        "mode": "selected_api",
        "provider": {
            "id": "qwen_api",
            "name": "Alibaba Qwen",
            "requested_model": "qwen-plus",
            "credential_alias": "qwen_api_key",
        },
    }
    captured_json = json.dumps(captured_settings)

    conn = sqlite3.connect(str(runtime_harness.db_path))
    cur = conn.cursor()

    cur.execute(
        """INSERT OR REPLACE INTO v3_approved_templates (id, kind, body, checksum, registered_at)
           VALUES (?, ?, ?, ?, ?)""",
        ("tmpl-scene-v1", "scene", "tmpl", hashlib.sha256(b"tmpl").hexdigest(), now_str),
    )

    cur.execute(
        """INSERT INTO v3_subject_runs (
            id, owner_user_id, subject_id, origin, protocol,
            settings_version_id, framework_version_id, mode, qualification_mode,
            captured_settings_json, capture_sha256, submission_key, request_sha256,
            state, state_version, total_pdfs, total_lessons, total_scenes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            run_id, teacher_id, subject_id, "web", "notebooklm_per_pdf_web_v1",
            settings_id, framework_id, "selected_api", "owner_waived_demo",
            captured_json, hashlib.sha256(captured_json.encode("utf-8")).hexdigest(),
            str(uuid.uuid4()), hashlib.sha256(b"api-run-2").hexdigest(),
            "prompts_ready", 1, 1, 1, 1, now_str, now_str,
        ),
    )

    cur.execute(
        """INSERT INTO v3_subject_run_items (
            id, run_id, owner_user_id, pdf_ordinal, display_title,
            document_id, case_id, original_filename, input_sha256, input_bytes,
            state, outcome, safe_code, state_version, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            item_id, run_id, teacher_id, 1, "Carnot Engine Foundations",
            doc_id, case_id, "carnot.pdf", hashlib.sha256(b"carnot.pdf").hexdigest(), 1024,
            "prompts_ready", "completed", "ok", 1, now_str, now_str,
        ),
    )

    cur.execute(
        """INSERT INTO v3_processing_cases (
            id, owner_user_id, document_id, settings_version_id, idempotency_key,
            action, state, state_version, pipeline_version, notebook_framework_version_id,
            subject_run_item_id, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            case_id, teacher_id, doc_id, settings_id, str(uuid.uuid4()),
            "process", "ready", 1, "notebooklm_per_pdf_web_v1", framework_id,
            item_id, now_str, now_str,
        ),
    )

    src_act_id = str(uuid.uuid4())
    cur.execute(
        """INSERT INTO v3_source_activations (id, case_id, workflow_id, state, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?)""",
        (src_act_id, case_id, "wf-src-2", "ready", now_str, now_str),
    )

    src_id = str(uuid.uuid4())
    cur.execute(
        """INSERT INTO v3_extracted_sources (
            id, case_id, activation_id, document_id, settings_version_id,
            status, text_sha256, page_report, extractor_version, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (src_id, case_id, src_act_id, doc_id, settings_id, "stored", "sha_src_2", "{}", "v1", now_str, now_str),
    )
    bind_id = str(uuid.uuid4())
    cur.execute(
        """INSERT INTO v3_knowledge_source_bindings (
            id, case_id, extracted_source_id, adapter_kind, adapter_version,
            notebook_title, source_title, state, text_sha256, text_bytes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (bind_id, case_id, src_id, "nb", "1", "title", "src", "bound", "text_sha_2", 10, now_str, now_str),
    )
    map_act_id = str(uuid.uuid4())
    cur.execute(
        "INSERT INTO v3_map_activations (id, case_id, binding_id, workflow_id, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
        (map_act_id, case_id, bind_id, "w", "ready", now_str, now_str),
    )
    cur.execute(
        "INSERT INTO v3_lesson_maps (id, case_id, binding_id, activation_id, state, page_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        (map_id, case_id, bind_id, map_act_id, "ready", 1, now_str, now_str),
    )
    cur.execute(
        """INSERT INTO v3_lesson_nodes (
            id, map_id, kind, position_path, ordinal_in_parent, title, work_ordinal, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (node_id, map_id, "l", "001", 1, "Carnot Engine Foundations", 1, now_str, now_str),
    )
    cur.execute(
        """INSERT INTO v3_lesson_queries (
            id, case_id, map_id, kind, target_work_ordinal, state, send_count, request_sha256, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (query_id, case_id, map_id, "first", 1, "recorded", 1, "q_sha_2", now_str, now_str),
    )
    cur.execute(
        """INSERT INTO v3_teacher_sections (
            id, case_id, map_id, work_node_id, query_id, ordinal_in_work,
            section_identity, core_understanding, first_prompt_sha256, answer_sha256,
            origin, subject_run_item_id, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            sec_id, case_id, map_id, node_id, query_id, 1,
            "Carnot Engine Efficiency", "Efficiency of heat engine cycles",
            hashlib.sha256(b"f2").hexdigest(), hashlib.sha256(b"a2").hexdigest(),
            "pipeline", item_id, now_str, now_str,
        ),
    )
    cur.execute(
        """INSERT INTO v3_prompt_work_items (
            id, case_id, map_id, work_node_id, work_ordinal, expected_cursor_version,
            section_query_id, state, section_refs, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (wi_id, case_id, map_id, node_id, 1, 1, query_id, "receipt_committed", json.dumps([sec_id]), now_str, now_str),
    )
    cur.execute(
        """INSERT INTO v3_scene_prompt_versions (
            id, case_id, work_item_id, section_id, version_no, origin,
            body_path, body_sha256, body_bytes, scene_type,
            section_fields_sha256, canonical_third_sha256, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            prompt_id, case_id, wi_id, sec_id, 1, "third",
            prompt_rel_path, prompt_sha, len(prompt_text), "Interactive 2D Canvas",
            hashlib.sha256(b"fields2").hexdigest(), hashlib.sha256(b"third2").hexdigest(),
            now_str,
        ),
    )
    cur.execute(
        """INSERT INTO v3_prompt_section_states (
            section_id, case_id, current_prompt_version_id, status, revision_count, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?)""",
        (sec_id, case_id, prompt_id, "qualified", 0, now_str, now_str),
    )
    conn.commit()
    conn.close()

    async with runtime_harness.create_browser_client() as client:
        logged_in = await runtime_harness.login_teacher(client)
        assert logged_in, "Teacher A login failed"

        scenes_page = await client.get(f"/v3/teacher/subject-runs/{run_id}/scenes")
        assert scenes_page.status_code == 200
        csrf_match = re.search(r'name="_token"\s+value="([^"]+)"', scenes_page.text)
        csrf_token = csrf_match.group(1) if csrf_match else client.cookies.get("XSRF-TOKEN")
        headers = {
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": csrf_token,
        }

        # 2. Test unconsented retry rejection (422)
        no_consent = await client.post(
            f"/v3/teacher/subject-runs/{run_id}/scenes/{sec_id}/authoring-request",
            json={"waiver_acknowledged": False},
            headers=headers,
        )
        assert no_consent.status_code == 422, "Request without waiver consent must be rejected with 422"

        # 3. Request scene generation with consent
        req_resp = await client.post(
            f"/v3/teacher/subject-runs/{run_id}/scenes/{sec_id}/authoring-request",
            json={"waiver_acknowledged": True},
            headers=headers,
        )
        assert req_resp.status_code == 202
        request_id = req_resp.json()["data"]["request_id"]

        conn = sqlite3.connect(str(runtime_harness.db_path))
        cur = conn.cursor()
        cur.execute("SELECT private_payload_ref FROM v3_pipeline_commands WHERE id = ?", (request_id,))
        manifest_ref = cur.fetchone()[0]
        conn.close()

        # 4. Setup mock transport: First attempt simulates timeout/connection loss (unproven send)
        captured_requests = []
        attempt_count = 0

        valid_package = {
            "schema_version": 1,
            "files": [
                {
                    "path": "index.html",
                    "content": (
                        "<!DOCTYPE html>\n"
                        "<html lang=\"en\">\n"
                        "<head>\n"
                        "  <meta charset=\"utf-8\">\n"
                        "  <title>Carnot Scene Simulation</title>\n"
                        "  <link rel=\"stylesheet\" href=\"styles.css\">\n"
                        "</head>\n"
                        "<body>\n"
                        "  <h1>Carnot Cycle Simulation</h1>\n"
                        "  <canvas id=\"sim\" width=\"800\" height=\"500\" role=\"img\" aria-label=\"Carnot Cycle PV Diagram\"></canvas>\n"
                        "  <div id=\"status\" aria-live=\"polite\">State: Initial</div>\n"
                        "  <button id=\"toggle-btn\" aria-label=\"Step Cycle State\">Step Cycle State</button>\n"
                        "  <script src=\"script.js\"></script>\n"
                        "</body>\n"
                        "</html>"
                    ),
                },
                {
                    "path": "styles.css",
                    "content": (
                        "body { margin: 0; background: #0f172a; color: #fff; font-family: sans-serif; }\n"
                        "canvas { display: block; width: 100%; max-width: 800px; height: 500px; }\n"
                        "#toggle-btn { margin: 10px; padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; min-height: 44px; min-width: 44px; }"
                    ),
                },
                {
                    "path": "script.js",
                    "content": (
                        "const canvas = document.getElementById('sim');\n"
                        "const ctx = canvas ? canvas.getContext('2d') : null;\n"
                        "if (ctx) {\n"
                        "  ctx.fillStyle = '#0f172a';\n"
                        "  ctx.fillRect(0, 0, 800, 500);\n"
                        "}\n"
                        "window.__scene_ready = true;\n"
                        "const btn = document.getElementById('toggle-btn');\n"
                        "const status = document.getElementById('status');\n"
                        "let count = 0;\n"
                        "function toggleState(e) {\n"
                        "  count++;\n"
                        "  btn.setAttribute('data-count', count);\n"
                        "  btn.textContent = 'Step Cycle State (' + count + ')';\n"
                        "  status.textContent = count % 2 === 0 ? 'State: Isothermal Expansion' : 'State: Adiabatic Compression';\n"
                        "  if (ctx) {\n"
                        "    ctx.fillStyle = count % 2 === 0 ? '#10b981' : '#f59e0b';\n"
                        "    ctx.fillRect(0, 0, 800, 500);\n"
                        "    ctx.fillStyle = '#ffffff';\n"
                        "    ctx.fillRect(50 * (count % 8), 100, 100, 100);\n"
                        "  }\n"
                        "}\n"
                        "if (btn) {\n"
                        "  btn.addEventListener('click', toggleState);\n"
                        "  btn.addEventListener('keydown', function(e) {\n"
                        "    if (e.key === 'Enter' || e.key === ' ') {\n"
                        "      toggleState(e);\n"
                        "    }\n"
                        "  });\n"
                        "}\n"
                    ),
                },
            ],
        }

        def mock_qwen_handler(request: httpx.Request) -> httpx.Response:
            nonlocal attempt_count
            attempt_count += 1
            captured_requests.append({
                "method": request.method,
                "url": str(request.url),
                "attempt": attempt_count,
            })
            if attempt_count == 1:
                # Simulate timeout/connection reset on first send
                raise httpx.ConnectTimeout("Connection timed out to dashscope.aliyuncs.com")

            return httpx.Response(200, json={
                "id": "chatcmpl-qwen-retry-xyz",
                "model": "qwen-plus",
                "choices": [{"finish_reason": "stop", "message": {"role": "assistant", "content": json.dumps(valid_package)}}],
                "usage": {"prompt_tokens": 100, "completion_tokens": 400},
            })

        mock_transport = httpx.MockTransport(mock_qwen_handler)
        transport_client = httpx.AsyncClient(transport=mock_transport)
        AuthoringRunner._transport_client = transport_client

        os.environ["QWEN_API_KEY"] = "test-qwen-key"
        os.environ["V3_LARAVEL_CALLBACK_URL"] = f"{runtime_harness.laravel_url}/api/internal/v1/case-events"
        os.environ["V3_CALLBACK_HMAC_KEY"] = runtime_harness.signing_secret
        os.environ["V3_STORAGE_ROOT"] = str(runtime_harness.private_root)

        try:
            # 5. Resolve and run attempt 1 -> encounters timeout
            resolved = await resolve_web_authoring_manifest_activity({
                "run_id": run_id,
                "manifest_ref": manifest_ref,
            })
            assert resolved["outcome"] == "completed"

            outcome_1 = await authoring_execute_attempts({
                "case_id": case_id,
                "request_ref": request_id,
            })
            # A14: Unproven send halts fallback and records uncertain
            assert outcome_1["status"] == "uncertain"
            assert outcome_1.get("safe_error_code") == "authoring_attempt_uncertain"

            # Check DB records uncertain state
            conn = sqlite3.connect(str(runtime_harness.db_path))
            cur = conn.cursor()
            cur.execute("SELECT state FROM v3_authoring_requests WHERE id = ?", (request_id,))
            assert cur.fetchone()[0] == "uncertain"
            cur.execute("SELECT ordinal, state, failure_code FROM v3_coding_attempts WHERE request_id = ?", (request_id,))
            attempt_1_row = cur.fetchone()
            assert attempt_1_row[0] == 1
            assert attempt_1_row[1] == "uncertain"
            assert attempt_1_row[2] in ("internal", "timeout")
            conn.close()

            # Zero automatic resends: only 1 attempt made so far
            assert len(captured_requests) == 1

            # 6. Explicit consented retry by teacher
            retry_resp = await client.post(
                f"/v3/teacher/subject-runs/{run_id}/scenes/{sec_id}/authoring-request",
                json={"waiver_acknowledged": True},
                headers=headers,
            )
            assert retry_resp.status_code == 202
            retry_request_id = retry_resp.json()["data"]["request_id"]
            assert retry_request_id != request_id

            # Verify successor request was created in pending state with web_retry origin
            conn = sqlite3.connect(str(runtime_harness.db_path))
            cur = conn.cursor()
            cur.execute("SELECT state, origin FROM v3_authoring_requests WHERE id = ?", (retry_request_id,))
            req_row = cur.fetchone()
            assert req_row[0] == "pending"
            assert req_row[1] == "web_retry_1"
            cur.execute("SELECT private_payload_ref FROM v3_pipeline_commands WHERE id = ?", (retry_request_id,))
            retry_manifest_ref = cur.fetchone()[0]
            conn.close()

            # Resolve retry manifest
            resolved_retry = await resolve_web_authoring_manifest_activity({
                "run_id": run_id,
                "manifest_ref": retry_manifest_ref,
            })
            assert resolved_retry["outcome"] == "completed"

            # Execute attempt 2 -> succeeds via mock provider
            outcome_2 = await authoring_execute_attempts({
                "case_id": case_id,
                "request_ref": retry_request_id,
            })
            assert outcome_2["status"] == "candidate_ready"
            cand_id = outcome_2.get("candidate_ref")
            assert cand_id is not None
            assert len(captured_requests) == 2, "Exactly 2 external requests across attempts"

            # 7. Tampered source fails closed in browser qualification (A15)
            # Find candidate manifest and tamper a file
            conn = sqlite3.connect(str(runtime_harness.db_path))
            cur = conn.cursor()
            cur.execute("SELECT manifest_path FROM v3_scene_candidates WHERE id = ?", (cand_id,))
            manifest_rel = cur.fetchone()[0]
            conn.close()

            private_root = runtime_harness.private_root
            manifest_file = private_root / manifest_rel
            assert manifest_file.exists(), f"Manifest file missing: {manifest_file}"

            # Tamper script.js on disk
            script_path = manifest_file.parent / "script.js"
            original_script_bytes = script_path.read_bytes()
            script_path.write_bytes(original_script_bytes + b"// TAMPERED")

            try:
                # Qualification fails closed due to SHA mismatch
                tampered_check = await qualify_authoring_candidate_activity({
                    "run_id": run_id,
                    "case_id": case_id,
                    "candidate_ref": cand_id,
                })
                assert tampered_check["outcome"] == "failed"
                assert tampered_check["safe_code"] == "artifact_corrupt"
                assert tampered_check["verdict"] == "fail"
            finally:
                # Restore original script.js
                script_path.write_bytes(original_script_bytes)

            # 8. Intact qualification succeeds and advances state to demo_review_ready
            intact_check = await qualify_authoring_candidate_activity({
                "run_id": run_id,
                "case_id": case_id,
                "candidate_ref": cand_id,
            })
            assert intact_check["outcome"] == "completed"
            assert intact_check["verdict"] == "pass"

            # Re-running authoring executes locally with ZERO additional external provider sends
            replay_authoring = await authoring_execute_attempts({
                "case_id": case_id,
                "request_ref": retry_request_id,
            })
            assert replay_authoring["status"] == "candidate_ready"
            assert replay_authoring["candidate_ref"] == cand_id
            assert len(captured_requests) == 2, "Zero additional provider sends on replay/recovery"

        finally:
            AuthoringRunner._transport_client = None
            await transport_client.aclose()




