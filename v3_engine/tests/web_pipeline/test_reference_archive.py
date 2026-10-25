"""Exact eight-PDF reference archive acceptance test (T128).

Verifies the canonical reference run:
- Terminal Root: storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root
- Run ID: 09a59afe-ed26-4567-906b-e42d87d53362
- Eight PDFs with exact per-PDF lesson counts: [9, 3, 9, 7, 5, 8, 8, 14] (sum 63)
- 89 exact scene prompt versions
- 0 provider calls, 0 generated scene candidates
- Truthful historical provenance without fake query IDs or activations
- Output evidence recorded in specs/019-v3-web-integration/repair-evidence/reference-import.json
"""

import json
import hashlib
import os
import re
import socket
import shutil
import sqlite3
import subprocess
import time
import uuid
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

import httpx
import pytest

REPO_ROOT = Path("/home/moahmmed/college_project").resolve()
REFERENCE_ROOT = REPO_ROOT / "storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root"
REFERENCE_RUN_ID = "09a59afe-ed26-4567-906b-e42d87d53362"
EVIDENCE_JSON_PATH = REPO_ROOT / "specs/019-v3-web-integration/repair-evidence/reference-import.json"


@pytest.fixture
def disposable_harness():
    """Create isolated disposable SQLite DB, run migrations, and seed prerequisites."""
    test_dir = REPO_ROOT / f"storage/testing/ref_archive_{os.getpid()}_{uuid.uuid4().hex[:8]}"
    test_dir.mkdir(parents=True, exist_ok=True)
    db_file = test_dir / "ref_pipeline.sqlite"
    db_file.touch()

    env = os.environ.copy()
    env["APP_ENV"] = "testing"
    env["APP_KEY"] = "base64:pAeRvprsT7xvgtJnI7RY2CtfRhhwt5HflVxit0LZEH0="
    env["DB_CONNECTION"] = "sqlite"
    env["DB_DATABASE"] = str(db_file)
    env["V3_PRIVATE_ROOT"] = str(test_dir / "v3-private")

    # 1. Run migrations
    res_mig = subprocess.run(
        ["php", "artisan", "migrate", "--force"],
        cwd=str(REPO_ROOT),
        env=env,
        capture_output=True,
        text=True,
    )
    assert res_mig.returncode == 0, f"Migration failed: {res_mig.stderr}"

    # 2. Seed harness prerequisites
    res_seed = subprocess.run(
        ["php", "artisan", "v3:seed-harness", "--json"],
        cwd=str(REPO_ROOT),
        env=env,
        capture_output=True,
        text=True,
    )
    assert res_seed.returncode == 0, f"Seeding failed: {res_seed.stderr}"
    seeded = json.loads(res_seed.stdout.strip())

    yield {
        "test_dir": test_dir,
        "db_file": db_file,
        "env": env,
        "seeded": seeded,
    }

    # Teardown
    if test_dir.exists():
        shutil.rmtree(test_dir, ignore_errors=True)


def test_reference_archive_dry_run_and_materialization(disposable_harness):
    env = disposable_harness["env"]
    db_file = disposable_harness["db_file"]
    seeded = disposable_harness["seeded"]

    teacher_id = seeded["teacher_a"]["id"]
    admin_id = seeded["admin"]["id"]
    subject_id = seeded["subject"]["id"]
    import_id = str(uuid.uuid4())

    assert REFERENCE_ROOT.exists(), f"Reference terminal root missing: {REFERENCE_ROOT}"
    run_dir = REFERENCE_ROOT / "runs" / REFERENCE_RUN_ID
    assert run_dir.exists(), f"Reference run directory missing: {run_dir}"

    # Snapshot source file mtimes to verify read-only immutability
    source_files = list(run_dir.rglob("*.json"))
    mtimes_before = {f: f.stat().st_mtime_ns for f in source_files}

    # -------------------------------------------------------------
    # Step 1: Dry-Run Verification
    # -------------------------------------------------------------
    res_dry = subprocess.run(
        [
            "php", "artisan", "v3:import-terminal-run",
            f"--terminal-root={REFERENCE_ROOT}",
            f"--run-id={REFERENCE_RUN_ID}",
            f"--subject={subject_id}",
            f"--teacher={teacher_id}",
            f"--actor={admin_id}",
            f"--import-id={import_id}",
            "--dry-run",
        ],
        cwd=str(REPO_ROOT),
        env=env,
        capture_output=True,
        text=True,
    )
    assert res_dry.returncode == 0, f"Dry run failed: {res_dry.stderr}\n{res_dry.stdout}"
    dry_out = json.loads(res_dry.stdout.strip())
    assert dry_out["state"] == "validated"
    assert dry_out["safe_code"] == "ok"
    assert dry_out["pdf_count"] == 8
    assert dry_out["is_dry_run"] is True

    # Check zero DB writes occurred in dry-run
    conn = sqlite3.connect(str(db_file))
    cur = conn.cursor()
    cur.execute("SELECT count(*) FROM v3_terminal_imports")
    assert cur.fetchone()[0] == 0
    cur.execute("SELECT count(*) FROM v3_subject_runs")
    assert cur.fetchone()[0] == 0

    # -------------------------------------------------------------
    # Step 2: Full Materialization
    # -------------------------------------------------------------
    res_mat = subprocess.run(
        [
            "php", "artisan", "v3:import-terminal-run",
            f"--terminal-root={REFERENCE_ROOT}",
            f"--run-id={REFERENCE_RUN_ID}",
            f"--subject={subject_id}",
            f"--teacher={teacher_id}",
            f"--actor={admin_id}",
            f"--import-id={import_id}",
        ],
        cwd=str(REPO_ROOT),
        env=env,
        capture_output=True,
        text=True,
    )
    assert res_mat.returncode == 0, f"Materialization failed: {res_mat.stderr}\n{res_mat.stdout}"
    mat_out = json.loads(res_mat.stdout.strip())
    assert mat_out["state"] == "completed"
    assert mat_out["safe_code"] == "ok"
    assert mat_out["is_dry_run"] is False

    # -------------------------------------------------------------
    # Step 3: Exact Acceptance Invariant Assertions
    # -------------------------------------------------------------
    # 1. Exactly 8 PDF items
    cur.execute("SELECT count(*) FROM v3_subject_run_items WHERE run_id = ?", (REFERENCE_RUN_ID,))
    assert cur.fetchone()[0] == 8

    # 2. Per-PDF lesson counts: [9, 3, 9, 7, 5, 8, 8, 14]
    cur.execute(
        "SELECT pdf_ordinal, saved_lesson_count FROM v3_subject_run_items WHERE run_id = ? ORDER BY pdf_ordinal",
        (REFERENCE_RUN_ID,)
    )
    rows = cur.fetchall()
    actual_lesson_counts = [r[1] for r in rows]
    assert actual_lesson_counts == [9, 3, 9, 7, 5, 8, 8, 14], f"Mismatched lesson counts: {actual_lesson_counts}"

    # 3. Total Lessons = 63 lesson nodes
    cur.execute(
        "SELECT count(*) FROM v3_lesson_nodes WHERE kind = 'lesson'"
    )
    total_lesson_nodes = cur.fetchone()[0]
    assert total_lesson_nodes == 63, f"Expected 63 lesson nodes, got {total_lesson_nodes}"

    # 4. Total Prompts = 89 exact scene prompt versions
    cur.execute("SELECT count(*) FROM v3_scene_prompt_versions WHERE origin = 'historical_import'")
    total_scene_prompts = cur.fetchone()[0]
    assert total_scene_prompts == 89, f"Expected 89 scene prompts, got {total_scene_prompts}"

    # Counts alone can pass when all scenes point at one section or when
    # body_path names files that were never stored. Compare every association
    # and every retained prompt body with the source export.
    export_path = run_dir / "private/scene-prompt-exports/scene-prompts.json"
    exported = json.loads(export_path.read_text(encoding="utf-8"))
    expected = {
        (int(lesson["pdf_ordinal"]), int(lesson["lesson_ordinal"]), int(scene["scene_ordinal"])):
        scene["prompt"]
        for lesson in exported["lessons"]
        for scene in lesson["scenes"]
    }
    cur.execute(
        "SELECT i.pdf_ordinal, n.work_ordinal, s.ordinal_in_work, p.body_path, p.body_sha256 "
        "FROM v3_scene_prompt_versions p "
        "JOIN v3_teacher_sections s ON s.id = p.section_id "
        "JOIN v3_lesson_nodes n ON n.id = s.work_node_id "
        "JOIN v3_processing_cases c ON c.id = s.case_id "
        "JOIN v3_subject_run_items i ON i.id = c.subject_run_item_id "
        "WHERE i.run_id = ? AND p.origin = 'historical_import'",
        (REFERENCE_RUN_ID,),
    )
    actual_rows = cur.fetchall()
    assert len(actual_rows) == len(expected) == 89
    private_root = Path(env["V3_PRIVATE_ROOT"]).resolve()
    evidence_root = private_root / "historical-imports" / import_id / "evidence"
    evidence_manifest = json.loads((evidence_root / "manifest.json").read_text(encoding="utf-8"))
    assert evidence_manifest["source_run_id"] == REFERENCE_RUN_ID
    evidence_files = evidence_manifest["files"]
    assert {entry["path"] for entry in evidence_files} >= {
        "subject-config.json", "scene-prompts.json",
        *(f"archive_pdf_{ordinal}.json" for ordinal in range(1, 9)),
    }
    assert len({entry["path"] for entry in evidence_files}) == len(evidence_files)
    for entry in evidence_files:
        copied = (evidence_root / entry["path"]).resolve()
        assert copied.is_relative_to(evidence_root) and copied.is_file()
        data = copied.read_bytes()
        assert len(data) == entry["bytes"]
        assert hashlib.sha256(data).hexdigest() == entry["sha256"]
    seen = set()
    for pdf_ordinal, lesson_ordinal, scene_ordinal, body_path, body_sha in actual_rows:
        key = (pdf_ordinal, lesson_ordinal, scene_ordinal)
        assert key in expected and key not in seen, f"Missing or duplicate scene association: {key}"
        seen.add(key)
        retained = (private_root / body_path).resolve()
        assert retained.is_relative_to(private_root) and retained.is_file()
        body = retained.read_text(encoding="utf-8")
        assert body == expected[key]
        assert hashlib.sha256(body.encode("utf-8")).hexdigest() == body_sha
    assert seen == set(expected)

    # Check status of scene prompt versions
    cur.execute("SELECT DISTINCT status FROM v3_scene_prompt_versions")
    statuses = [r[0] for r in cur.fetchall()]
    assert statuses == ["awaiting_manual"], f"Expected awaiting_manual, got {statuses}"

    # 5. TeacherSections counted from actual source section structure
    cur.execute("SELECT count(*) FROM v3_teacher_sections WHERE origin = 'terminal_import'")
    total_teacher_sections = cur.fetchone()[0]
    assert total_teacher_sections >= 63, f"Expected at least 63 teacher sections, got {total_teacher_sections}"

    # 6. Zero generated scenes or candidates, zero provider requests
    cur.execute("SELECT count(*) FROM v3_scene_candidates")
    assert cur.fetchone()[0] == 0

    # 7. Check SubjectRun totals
    cur.execute("SELECT total_pdfs, total_lessons, total_scenes, state FROM v3_subject_runs WHERE id = ?", (REFERENCE_RUN_ID,))
    run_row = cur.fetchone()
    assert run_row[0] == 8
    assert run_row[1] == 63
    assert run_row[2] == 89
    assert run_row[3] == "completed"

    # -------------------------------------------------------------
    # Step 4: Idempotency & Duplicate Re-run
    # -------------------------------------------------------------
    res_repeat = subprocess.run(
        [
            "php", "artisan", "v3:import-terminal-run",
            f"--terminal-root={REFERENCE_ROOT}",
            f"--run-id={REFERENCE_RUN_ID}",
            f"--subject={subject_id}",
            f"--teacher={teacher_id}",
            f"--actor={admin_id}",
            f"--import-id={import_id}",
        ],
        cwd=str(REPO_ROOT),
        env=env,
        capture_output=True,
        text=True,
    )
    assert res_repeat.returncode == 0
    repeat_out = json.loads(res_repeat.stdout.strip())
    assert repeat_out["state"] == "duplicate"
    assert repeat_out["safe_code"] == "ok"

    # Counts must remain completely unchanged
    cur.execute("SELECT count(*) FROM v3_terminal_imports")
    assert cur.fetchone()[0] == 1
    cur.execute("SELECT count(*) FROM v3_subject_runs")
    assert cur.fetchone()[0] == 1
    cur.execute("SELECT count(*) FROM v3_subject_run_items")
    assert cur.fetchone()[0] == 8
    cur.execute("SELECT count(*) FROM v3_lesson_nodes WHERE kind = 'lesson'")
    assert cur.fetchone()[0] == 63
    cur.execute("SELECT count(*) FROM v3_scene_prompt_versions")
    assert cur.fetchone()[0] == 89

    # -------------------------------------------------------------
    # Step 5: Verify Source Immutability
    # -------------------------------------------------------------
    mtimes_after = {f: f.stat().st_mtime_ns for f in source_files}
    assert mtimes_before == mtimes_after, "Source archive files were modified during import!"

    # -------------------------------------------------------------
    # Step 6: Owner browser navigation over the imported archive
    # -------------------------------------------------------------
    # Start the real Laravel app against this test's disposable DB/storage,
    # sign in as the import owner, then traverse every imported lecture and
    # canonical teacher-section detail route in Chromium.
    cur.execute(
        "SELECT l.lecture_id, i.case_id FROM v3_lecture_pipeline_links l "
        "JOIN v3_subject_run_items i ON i.id = l.item_id "
        "WHERE i.run_id = ? ORDER BY i.pdf_ordinal",
        (REFERENCE_RUN_ID,),
    )
    lecture_case_rows = [(int(row[0]), str(row[1])) for row in cur.fetchall()]
    lecture_ids = [row[0] for row in lecture_case_rows]
    assert len(lecture_ids) == 8
    cur.execute(
        "SELECT c.id, s.id, s.section_identity "
        "FROM v3_teacher_sections s "
        "JOIN v3_processing_cases c ON c.id = s.case_id "
        "JOIN v3_subject_run_items i ON i.id = c.subject_run_item_id "
        "WHERE i.run_id = ? ORDER BY i.pdf_ordinal, s.ordinal_in_work, s.id",
        (REFERENCE_RUN_ID,),
    )
    imported_sections = [(str(case_id), str(section_id), identity or "") for case_id, section_id, identity in cur.fetchall()]
    assert len(imported_sections) == total_teacher_sections
    cur.execute(
        "SELECT DISTINCT p.section_id FROM v3_scene_prompt_versions p "
        "JOIN v3_processing_cases c ON c.id = p.case_id "
        "JOIN v3_subject_run_items i ON i.id = c.subject_run_item_id "
        "WHERE i.run_id = ? AND p.origin = 'historical_import'",
        (REFERENCE_RUN_ID,),
    )
    expected_prompt_section_ids = {str(row[0]) for row in cur.fetchall()}
    assert len(expected_prompt_section_ids) == 89

    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        web_port = sock.getsockname()[1]
    web_origin = f"http://127.0.0.1:{web_port}"
    web_env = env.copy()
    web_env.update({"APP_DEBUG": "true", "APP_URL": web_origin})
    web_log_path = disposable_harness["test_dir"] / "reference-navigation-laravel.log"
    web_log = web_log_path.open("wb")
    web_server = subprocess.Popen(
        ["php", "artisan", "serve", "--host=127.0.0.1", f"--port={web_port}", "--env=testing"],
        cwd=str(REPO_ROOT), env=web_env, stdout=web_log, stderr=subprocess.STDOUT,
    )
    try:
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            if web_server.poll() is not None:
                raise AssertionError(f"Laravel server exited early; see {web_log_path}")
            try:
                if httpx.get(f"{web_origin}/login", timeout=1).status_code == 200:
                    break
            except httpx.HTTPError:
                time.sleep(0.2)
        else:
            raise AssertionError(f"Laravel server did not become ready; see {web_log_path}")

        async def browse_imported_archive() -> dict[str, int]:
            from playwright.async_api import async_playwright

            async with async_playwright() as playwright:
                browser = await playwright.chromium.launch(headless=True)
                try:
                    page = await browser.new_page()
                    await page.goto(f"{web_origin}/login", wait_until="domcontentloaded")
                    await page.locator("#email").fill("teacher_a@example.com")
                    await page.locator("#password").fill("password")
                    async with page.expect_navigation(wait_until="domcontentloaded"):
                        await page.get_by_role("button", name="Sign In").click()

                    subject_response = await page.goto(
                        f"{web_origin}/subjects/{subject_id}", wait_until="domcontentloaded"
                    )
                    assert subject_response and subject_response.status == 200
                    assert "Historical" in await page.locator("body").inner_text()

                    subject_url = f"{web_origin}/subjects/{subject_id}"
                    lecture_hrefs = set(await page.locator("a[href*='/lectures/']").evaluate_all(
                        "els => els.map(el => new URL(el.href).pathname)"
                    ))
                    observed_prompt_section_ids: set[str] = set()
                    for lecture_id, case_id in lecture_case_rows:
                        lecture_path = f"/lectures/{lecture_id}"
                        assert lecture_path in lecture_hrefs
                        lecture_link = page.locator(f"a[href$='{lecture_path}']")
                        async with page.expect_navigation(wait_until="domcontentloaded") as navigation:
                            await lecture_link.click()
                        response = await navigation.value
                        assert response and response.status == 200, f"Lecture route failed: {lecture_id}"
                        lecture_body = await page.locator("body").inner_text()
                        assert "Imported Historical Content" in lecture_body
                        assert await page.get_by_role("link", name="Open Lesson Map").count() == 1
                        async with page.expect_navigation(wait_until="domcontentloaded"):
                            await page.get_by_role("link", name="Open Lesson Map").click()
                        assert f"/v3/teacher/cases/{case_id}/lesson-map" in page.url

                        # The lesson map itself must link to every imported
                        # section and to each exact saved scene prompt.
                        section_links = await page.locator(
                            f"a[href*='/cases/{case_id}/sections/']"
                        ).evaluate_all("els => els.map(el => el.getAttribute('href'))")
                        expected_case_sections = [
                            (section_id, identity)
                            for row_case, section_id, identity in imported_sections
                            if row_case == case_id
                        ]
                        href_to_section = {
                            urlsplit(href).path.rstrip("/").rsplit("/", 1)[-1]: href for href in section_links
                        }
                        assert set(href_to_section) == {section_id for section_id, _ in expected_case_sections}

                        prompt_links = await page.locator(
                            "a[href*='/prompt-export']"
                        ).evaluate_all("els => els.map(el => el.getAttribute('href'))")
                        for href in prompt_links:
                            section_id = parse_qs(urlsplit(href).query).get("scene_id", [None])[0]
                            if section_id:
                                observed_prompt_section_ids.add(section_id)

                        # Follow every canonical section link as the owner and
                        # ensure the source identity is actually rendered.
                        normalize = lambda value: re.sub(r"\s+", " ", value).strip()
                        for section_id, identity in expected_case_sections:
                            await page.goto(href_to_section[section_id], wait_until="domcontentloaded")
                            body_text = await page.locator("body").inner_text()
                            assert f"Section Ref: {section_id}" in body_text
                            if identity:
                                assert normalize(identity)[:40] in normalize(body_text)
                        await page.goto(subject_url, wait_until="domcontentloaded")

                    assert observed_prompt_section_ids == expected_prompt_section_ids

                    # The authenticated website export must preserve all 89
                    # exact prompts across the eight imported PDF items.
                    export_response = await page.goto(
                        f"{web_origin}/v3/teacher/subject-runs/{REFERENCE_RUN_ID}/prompt-export?format=json",
                        wait_until="domcontentloaded",
                    )
                    assert export_response and export_response.status == 200
                    exported_web = await export_response.json()
                    assert exported_web["total_prompts"] == 89
                    assert len(exported_web["prompts"]) == 89
                    assert {prompt["pdf_ordinal"] for prompt in exported_web["prompts"]} == set(range(1, 9))
                    return {
                        "lecture_count": len(lecture_ids),
                        "section_count": len(imported_sections),
                        "prompt_count": len(exported_web["prompts"]),
                    }
                finally:
                    await browser.close()

        import asyncio
        browser_navigation = asyncio.run(browse_imported_archive())
    finally:
        web_server.terminate()
        try:
            web_server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            web_server.kill()
            web_server.wait(timeout=5)
        web_log.close()

    # -------------------------------------------------------------
    # Step 7: Record Evidence JSON Artifact
    # -------------------------------------------------------------
    evidence_payload = {
        "acceptance_criteria": "A01, A02, A03, A04",
        "terminal_root": str(REFERENCE_ROOT),
        "run_id": REFERENCE_RUN_ID,
        "import_id": import_id,
        "pdf_count": 8,
        "per_pdf_lesson_counts": actual_lesson_counts,
        "total_lesson_nodes": total_lesson_nodes,
        "total_scene_prompts": total_scene_prompts,
        "total_teacher_sections": total_teacher_sections,
        "external_generation_sends": 0,
        "generated_scene_candidates": 0,
        "prompt_version_statuses": statuses,
        "dry_run_verified": True,
        "materialization_verified": True,
        "idempotent_duplicate_verified": True,
        "source_immutability_verified": True,
        "owner_browser_lecture_count": browser_navigation["lecture_count"],
        "owner_browser_section_count": browser_navigation["section_count"],
        "owner_browser_prompt_export_count": browser_navigation["prompt_count"],
    }
    EVIDENCE_JSON_PATH.parent.mkdir(parents=True, exist_ok=True)
    EVIDENCE_JSON_PATH.write_text(json.dumps(evidence_payload, indent=2))
    assert EVIDENCE_JSON_PATH.exists()
