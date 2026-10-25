"""Opt-in live two-PDF web acceptance for manual scene export and legacy lessons."""

import asyncio
import hashlib
import os
import sqlite3
from pathlib import Path

import pytest
from playwright.async_api import async_playwright

from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
from v3_engine.notebooklm.terminal_service import NotebookTerminalService
from v3_engine.terminal_pipeline.worker import run_workers
from v3_engine.tests.web_pipeline.runtime_harness import JoinedRuntimeHarness
from v3_engine.web_pipeline.application_client import ApplicationClient
import v3_engine.web_pipeline.activities as activities


@pytest.mark.live
@pytest.mark.asyncio
async def test_two_live_pdfs_reach_legacy_lessons(temporal_local_env, monkeypatch):
    if os.getenv("RUN_V3_LIVE_UNIFIED_MANUAL") != "1":
        pytest.skip("Set RUN_V3_LIVE_UNIFIED_MANUAL=1 for the live manual-export gate")
    pdf_paths = [Path(os.environ[f"V3_WEB_LIVE_PDF{i}"]) for i in (1, 2)]
    assert all(path.is_file() for path in pdf_paths)
    pdf_bytes = [path.read_bytes() for path in pdf_paths]
    assert all(body.startswith(b"%PDF-") for body in pdf_bytes)
    assert hashlib.sha256(pdf_bytes[0]).digest() != hashlib.sha256(pdf_bytes[1]).digest()

    harness = JoinedRuntimeHarness()
    try:
        harness.setup_database_and_prerequisites()
        harness.start_laravel()
        service = NotebookTerminalService(session_provider=SdkSessionProvider())
        await service.verify_session(os.environ["V3_WEB_LIVE_PROOF_NOTEBOOK_ID"])
        activities.set_terminal_service(service)
        activities.set_application_client(ApplicationClient(harness.laravel_url, harness.signing_secret))
        monkeypatch.setenv("V3_LARAVEL_CALLBACK_URL", f"{harness.laravel_url}/api/internal/v1/case-events")
        monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", harness.signing_secret)
        monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", harness.signing_secret)
        monkeypatch.setenv("V3_STORAGE_ROOT", str(harness.private_root))
        terminal_root = harness.storage_path / "private/v3/terminal"
        terminal_root.mkdir(parents=True, exist_ok=True)
        monkeypatch.setenv("V3_TERMINAL_ROOT", str(terminal_root))
        harness.temporal_address = temporal_local_env.client.service_client.config.target_host
        harness.start_bridge()

        subject_id = harness.seeded_data["subject"]["id"]
        async with async_playwright() as playwright:
            browser = await playwright.chromium.launch(headless=True)
            try:
                page = await browser.new_page()
                await page.goto(f"{harness.laravel_url}/login")
                await page.locator("#email").fill("teacher_a@example.com")
                await page.locator("#password").fill("password")
                await page.get_by_role("button", name="Sign In").click()
                await page.goto(f"{harness.laravel_url}/v3/teacher/subjects/{subject_id}/runs/create")
                for ordinal, body in enumerate(pdf_bytes):
                    if ordinal:
                        await page.get_by_role("button", name="Add Another PDF").click()
                    await page.locator('input[name="titles[]"]').nth(ordinal).fill(f"Live lesson {ordinal + 1}")
                    await page.locator('input[name="files[]"]').nth(ordinal).set_input_files({
                        "name": f"live-{ordinal + 1}.pdf", "mimeType": "application/pdf", "buffer": body,
                    })
                async with page.expect_navigation():
                    await page.get_by_role("button", name="Start Processing Run").click()
                run_id = page.url.rstrip("/").split("/")[-1]
            finally:
                await browser.close()

        stop = asyncio.Event()
        worker = asyncio.create_task(run_workers(
            temporal_local_env.client, lock_path=harness.test_root / "worker.lock", stop_event=stop,
        ))
        try:
            dispatch = await asyncio.to_thread(harness.dispatch_outbox)
            assert dispatch["dispatched_count"] == 1, dispatch
            handle = temporal_local_env.client.get_workflow_handle(f"v3-web-subject-{run_id}")
            result = await asyncio.wait_for(handle.result(), timeout=900)
        finally:
            stop.set()
            await worker
        assert result["outcome"] in ("prompts_exported", "completed"), result
        assert result["pdfs_completed"] == 2, result

        conn = sqlite3.connect(harness.db_path)
        try:
            assert conn.execute("SELECT state FROM v3_subject_runs WHERE id = ?", (run_id,)).fetchone()[0] == "completed"
            rows = conn.execute("""
                SELECT i.pdf_ordinal, l.id, s.id, s.title, s.quick_summary, s.core_concept,
                       s.egyptian_explain, s.formulas, s.real_life
                  FROM v3_subject_run_items i
                  JOIN v3_lecture_pipeline_links ll ON ll.item_id = i.id
                  JOIN lectures l ON l.id = ll.lecture_id
                  JOIN v3_section_pipeline_links sl ON sl.item_id = i.id
                  JOIN sections s ON s.id = sl.section_id AND s.lecture_id = l.id
                 WHERE i.run_id = ? ORDER BY i.pdf_ordinal, s.id
            """, (run_id,)).fetchall()
            assert {row[0] for row in rows} == {1, 2}, rows
            assert all(all(str(value).strip() for value in row[3:]) for row in rows), rows
            assert conn.execute("SELECT COUNT(*) FROM v3_lesson_publications WHERE lecture_id IN "
                                "(SELECT lecture_id FROM v3_lecture_pipeline_links WHERE item_id IN "
                                "(SELECT id FROM v3_subject_run_items WHERE run_id = ?)) AND published_at IS NULL",
                                (run_id,)).fetchone()[0] == 2
            print(f"LIVE_UNIFIED_RUN_ID={run_id} LEGACY_LECTURES=2 LINKED_SECTIONS={len(rows)}")
        finally:
            conn.close()
    finally:
        harness.cleanup()
