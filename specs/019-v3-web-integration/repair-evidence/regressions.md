# Spec 019 Regression Results (measured 2026-10-12)

The regression suites below ran against test databases and scripted provider transports. A separate live NotebookLM joined-runtime run is recorded after them.

## Current rerun summary (2026-10-12)

- PHP WebPipeline suites: **174 passed, 1 skipped (1,005 assertions)**.
- Python WebPipeline suite: **94 passed, 9 skipped** (8 opt-in Temporal cases and 1 live scene-generation gate).
- Temporal-enabled joined browser runtime: **13 passed in 234.66 seconds**.
- Capacity and Temporal replay: **9 passed in 6.05 seconds**.
- Exact reference archive browser import: **1 passed in 43.45 seconds**.
- Real NotebookLM, two-PDF browser-to-Temporal flow: **1 passed in 330.05 seconds**; Qwen stayed scripted.
- Evidence validator tests: **2 passed**; offline manifest validation currently fails with **45 errors** because required gate, view and acceptance rows remain `not_run`. Traceable smoke/preflight executions and artifact digests are now present; `--require schema` succeeds.

The older counts below are retained as historical results. Use this rerun summary and the dated runtime logs later in this file for the current checkout.

## Historical baseline suites (superseded by current rerun)

## Laravel web pipeline

Command:

```sh
php artisan test tests/Feature/V3/WebPipeline/ tests/Integration/V3/WebPipeline/ --compact
```

Result: **175 passed, 1 skipped, 1,134 assertions**. The skipped test is `LiveApplicationGateTest`; the full live scene-provider prerequisite is absent.

## Python web pipeline

Command:

```sh
.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests/web_pipeline/ -q -ra --tb=short
```

Result: **105 passed, 7 skipped** (including NotebookLM bundle parser tests). Skips are opt-in Temporal/live cases.

## Joined runtime with Temporal enabled

Command:

```sh
.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests/web_pipeline/test_joined_runtime.py -q -ra --tb=short --run-temporal
```

Result: **10 passed**. This covers the joined runtime and its enabled Temporal boundary/recovery cases.

## Browser-driven connected acceptance path

Command:

```sh
.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests/web_pipeline/test_joined_runtime.py::test_browser_intake_and_sequential_service_execution -q -ra --tb=short
```

Result: **1 passed in 32.80 seconds** with scripted NotebookLM and Qwen transports. Chromium verifies the owner run status after processing, checks that the admin dashboard shows operational metadata without PDF titles, then drives the rendered review UI: approval stays disabled until frame zero displays, the owner approves the candidate, submits a postapproval correction, and the test checks HTTP 201 plus an accepted correction row in the isolated database. Review and correction effects are dispatched through Temporal. A separate live NotebookLM execution is recorded below.

## Exact historical archive and queued import

Commands:

```sh
python3 -m pytest -q v3_engine/tests/web_pipeline/test_reference_archive.py
python3 -m pytest -q v3_engine/tests/web_pipeline/test_joined_runtime.py::test_queued_reference_import_crosses_signed_bridge_temporal_and_laravel --run-temporal
```

Results: **1 passed** for each command. The exact eight-PDF archive materializes 63 lesson nodes, 90 sections, and 89 prompts; Chromium owner navigation opens all 8 lecture pages and all 90 section pages, checks the prompt links, and verifies the exact 89-prompt export. The test checks source immutability, duplicate idempotency, and zero scene generation sends. The queued test crosses the signed Laravel outbox, bridge, Temporal worker and Laravel materializer and confirms duplicate import handling. Interrupted/lost-ack recovery is still open.

## Frontend asset build

Command: `npm run build`

Result: Vite build passed and refreshed `public/build/manifest.json` and the generated CSS/JS bundles used by the browser journey.

## Latest Laravel WebPipeline regression

Command: `php artisan test tests/Feature/V3/WebPipeline/ tests/Integration/V3/WebPipeline/ --compact`

Result: **174 passed, 1 skipped (1,005 assertions)** on 2026-10-12. The skip is the live application gate because the required live provider setup is not present. The skip is the live application gate because the required live provider setup is not present.

## Latest Python WebPipeline regression

Command: `python3 -m pytest --import-mode=importlib v3_engine/tests/web_pipeline/ -q -ra --tb=short`

Result: **94 passed, 9 skipped** on 2026-10-12 (8 opt-in Temporal cases and 1 live-credential gate). Seven skips require the opt-in Temporal environment; one requires live provider credentials. Three FastAPI deprecation warnings were emitted; no failures.

## Live NotebookLM browser joined run

Command: `python3 -m pytest -q v3_engine/tests/web_pipeline/test_joined_runtime.py::test_browser_intake_and_sequential_service_execution` with `V3_WEB_LIVE_NOTEBOOKLM_TEST=1`, the saved owner-verified proof notebook id, and private artifact preservation enabled.

Historical result: **1 passed in 295.82 seconds**. Two browser-uploaded PDFs completed live Divide, execution, lesson-start, and Continue turns through the signed Laravel/FastAPI/Temporal path; four lesson bundles were committed. Export, manual scene import, browser qualification, preview, approval, and review assertions passed. This verifies live NotebookLM integration. It does not verify live Qwen scene generation or close archive-import/evidence-manifest acceptance.

The live reply uncovered PHP/Python parser drift: Python accepts a prompt beginning inline on the `LecX (SecY):` line, but PHP previously discarded that captured text. Laravel now captures and validates it consistently, and the combined-lesson callback test covers the inline form.

## Evidence validator

Command:

```sh
python3 scripts/v3/verify_web_repair_evidence.py --require offline
```

Result: **FAILED, 46 errors**. The current `manifest.json` has a current source snapshot digest, but the offline ledger still fails because G01–G05, V01–V12 and A01–A28 are `not_run` pending traceable execution evidence, and no execution records are currently present. Unsupported pass labels without execution references were downgraded to `not_run`; legacy records that hashed narrative Markdown were removed. The validator now rejects missing test IDs, malformed timestamps, narrative Markdown artifacts and malformed source digests. Its regression tests pass (2 passed). No manifest pass is claimed.

## Scope limits

These results establish a connected workflow with real live NotebookLM calls, browser-driven review/correction submission, exact eight-PDF archive navigation, and signed queued import. They do not close interrupted/lost-ack archive recovery, complete evidence-ledger validation (T120, T148, T151), or live Qwen/full acceptance execution (T112/T152). The T149 opt-in runner implementation and prerequisite tests are complete; its actual live execution remains open. The full feature is not ready for an unconditional production green declaration.

## Control and progress re-verification (2026-10-12)

Commands and observed results from the current worktree:

- `php artisan test tests/Feature/V3/WebPipeline/ProgressProjectionTest.php tests/Integration/V3/WebPipeline/CommandRaceTest.php --compact` — **12 passed (76 assertions)**. Covers null unknown totals, stale/offline projection, live Temporal state, event filtering, privacy, command version/idempotency/races, durable requested-vs-applied state, outage behavior, and admin restrictions.
- `python3 -m pytest --import-mode=importlib -q v3_engine/tests/web_pipeline/test_admission.py` — **8 passed** (3 framework deprecation warnings).
- `python3 -m pytest --import-mode=importlib -q v3_engine/tests/web_pipeline/test_controls_capacity.py --run-temporal` — **5 passed**.
- `python3 -m pytest --import-mode=importlib -q v3_engine/tests/web_pipeline/test_joined_runtime.py::test_browser_run_controls_pause_resume_and_cancel_active_work v3_engine/tests/web_pipeline/test_joined_runtime.py::test_browser_cancels_run_waiting_in_shared_supervisor_queue --run-temporal` — **2 passed in 37.98 seconds**. Chromium verified active pause/resume, cancellation while paused, no following lesson send after cancellation, and cancellation of a queued fifth run while four shared slots were occupied.
- `python3 -m pytest -q scripts/v3/tests/test_verify_web_repair_evidence.py` — **1 passed**.

These results strengthen V10 and portions of V02, A21/A22 and progress projection. They do not cover all required V02 states or close the full V01–V12 journeys. T146 and T148 remain open. The full manifest is still invalid; the offline validator result recorded below remains authoritative until a newly captured complete evidence bundle passes.

- `.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests/web_pipeline/test_joined_runtime.py -q -ra --tb=short --run-temporal` — **13 passed in 234.66 seconds**. Full output: `repair-evidence/runtime/joined-runtime-temporal-20261012.log`. It includes browser intake and sequential execution, active/queued controls, signed queued import, owner navigation and scene review paths. This run uses scripted external NotebookLM/Qwen transports except the separately recorded live NotebookLM execution.

The fresh broad Python result is 94 passed/9 skipped, and the broad PHP result is 174 passed/1 skipped. They do not close T145 process-restart recovery, T147 serialized-history privacy inspection, T146 full browser state matrix, T148 complete route journeys, T149 live-runner implementation, or T151 manifest validation.

- `.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests/web_pipeline/test_controls_capacity.py v3_engine/tests/web_pipeline/test_replay.py -q -ra --tb=short --run-temporal` — **9 passed in 6.05 seconds**. Full output: `repair-evidence/runtime/capacity-replay-temporal-20261012.log`.
- `python3 -m pytest -q scripts/v3/tests/test_verify_web_repair_evidence.py` — **2 passed** after validator hardening.
- `python3 scripts/v3/verify_web_repair_evidence.py --require offline` — **failed with 46 errors**; full output: `repair-evidence/runtime/manifest-offline-20261012.log`. The ledger still does not support an offline-green declaration.

## Current host endpoint diagnostic (2026-10-12)

- `ss -ltnp` shows PID 234227, `python3 -m http.server 8000`, bound to `0.0.0.0:8000`.
- Its current working directory is under `storage/app/private/v3/eight_pdf_runs/.../generated_scenes`; `GET /` returns a directory listing. `/up`, `/health`, `/healthz`, and `/openapi.json` all return 404.
- Therefore the service reachable on port 8000 is not Laravel or the FastAPI pipeline bridge. Do not use it as evidence that the live website is connected. I did not stop or alter this pre-existing process; owner direction is pending because stopping it would change a running service.

## Current authorized live NotebookLM web run (2026-10-12)

Command:

```sh
V3_WEB_LIVE_NOTEBOOKLM_TEST=1 V3_WEB_LIVE_PROOF_NOTEBOOK_ID=85b76e80-ea8a-4f86-9311-185634fcd2ed python3 -m pytest --import-mode=importlib v3_engine/tests/web_pipeline/test_joined_runtime.py::test_browser_intake_and_sequential_service_execution -q -ra --tb=short
```

Result: **1 passed in 330.05 seconds**. Chromium submitted two disposable PDFs through the isolated Laravel application; real NotebookLM SDK turns ran through the signed bridge and Temporal workers; lessons/prompts were committed; the same run completed manual import, browser qualification, owner preview/approval, correction, and a selected Qwen API path using a scripted Qwen transport. This verifies the real NotebookLM web path. It is not live Qwen generation and does not close G06/T149/T152. Captured test output: `repair-evidence/runtime/live-notebooklm-browser-20261012.log`.

The environment check confirmed a valid saved NotebookLM session. No Qwen/Gemini/GLM API key or full live-gate opt-in was present, so no paid scene-generation request was made.

## Operator startup and live host configuration audit (2026-10-12)

- `php artisan v3:dispatch-web-pipeline --help` confirms the only dispatch options are `--limit` and `--json`; the documented `--once` option did not exist. `routes/console.php` schedules this outbox command every minute, and `php artisan schedule:list` lists it. `php artisan schedule:work --help` confirms the long-running scheduler entry point.
- `config/services.php` and `v3_engine/web_pipeline/settings.py` confirm the current bridge default is `127.0.0.1:8765` and worker namespace default is `v3-terminal`; the old guide's 8100/default Temporal namespace was inconsistent.
- Temporal CLI help confirms `--ip`, `--port`, and `--namespace` flags. The current quickstart now uses the shared worker facade, matching bridge URL, and loopback Laravel port 8080.
- Safe `.env` inspection found the pipeline feature flag, explicit bridge URLs and Temporal address/namespace unset. Laravel therefore defaults the feature off and bridge URL to 8765. HMAC key values were present but not printed. The active port-8000 process is still the Python static server; no Laravel, FastAPI or Temporal endpoint is currently listening for the live application.
- `test_reference_archive.py` rerun: **1 passed in 43.45 seconds**; output retained at `repair-evidence/runtime/reference-archive-20261012.log`.


## Local web/bridge/Temporal runtime smoke — 2026-10-12

- Enabled local `.env` V3 web-pipeline settings in `export_manual` mode with bridge `127.0.0.1:8765` and Temporal `127.0.0.1:7233`, namespace `v3-terminal`.
- Started Laravel loopback-only at `127.0.0.1:8080` against a temporary copy of the SQLite database, with PHP upload limits set to 100M/1100M. Readiness reported enabled and ready.
- PHP `BridgeRequestSigner` signed `/internal/v1/health`; the bridge returned HTTP 200 and confirmed Temporal reachability. Control, IO and build worker queues registered.
- Chromium rendered `/login` and confirmed unauthenticated intake redirects to login. No job or external provider request was made. Evidence is in `repair-evidence/runtime/local-stack-smoke-20261012.json`.
- The manifest schema check passes; offline acceptance still fails on unverified gate/view/criterion rows.
