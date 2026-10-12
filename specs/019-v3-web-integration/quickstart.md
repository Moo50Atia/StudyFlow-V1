# Spec 019 Verification Guide

**Status updated 2026-10-12:** the browser-driven offline web-to-workflow chain is verified through lessons, prompt exports, manual and scripted-Qwen scenes, Chromium checks, preview, approval, and Temporal review-decision consumption. This is not full acceptance: the evidence manifest fails offline validation (78 errors), exact reference archive navigation/recovery remains open, and the dedicated live provider gate has not run. See `repair-evidence/final-review.md` and `repair-evidence/regressions.md` for measured results. Commands below that describe future acceptance requirements remain requirements, not passed evidence.

These commands are instructions. They were NOT run as application/runtime tests during this documentation task. Use repository root and disposable test roots/databases; never refresh the owner's actual SQLite database.

## Baseline (T001)

```sh
cd /home/moahmmed/college_project
git status --short
php --version
.venv/bin/python --version
qpdf --version
clamdscan --version
php artisan migrate:status
php artisan test tests/Feature/V3/NotebookLM tests/Integration/V3/NotebookLM
.venv/bin/python -m pytest v3_engine/tests/scene_generation v3_engine/tests/terminal_pipeline -m 'not live and not temporal'
```

Expected: explicit environment inventory and categorized baseline results, not assumed green. Missing clamdscan executable or unavailable daemon is a separate intake readiness failure. Do not print .env or cookies. Check actual browser runner/container availability separately before browser groups; Node/npm are only prerequisites for legacy React recovery/browser runner tooling, not a requirement to compile new plain-static code.

## Focused feature verification

After adding the listed tests:

```sh
php artisan test tests/Feature/V3/WebPipeline
php artisan test tests/Integration/V3/WebPipeline
.venv/bin/python -m pytest v3_engine/tests/web_pipeline -m 'not live and not temporal'
.venv/bin/python -m pytest v3_engine/tests/web_pipeline -m 'temporal and not live'
```

Expected: schema/owner/import/intake/export/manual/gate/review/progress tests pass; actual two-process races and real isolated browser tests execute. Temporal tests start disposable persistent namespace/registered queues and prove empty-start admission, shared capacity, control recovery and actual replay. Do not count deselected/skipped tests as passed gates. Per-group files/expected evidence are in acceptance-matrix.md.

## Current readiness and worker setup

The implementation uses the feature switch `V3_WEB_PIPELINE_ENABLED`, the shared bridge signer, and the existing Spec 018 Temporal namespace. Configure `V3_WEB_PIPELINE_ENABLED=true` only for the intended deployment, and set matching bridge URLs, HMAC keys, Temporal address, and `v3-terminal` namespace in Laravel and Python process environments. Keep all secrets in the existing secret store.

Use the existing persistent Temporal service from Spec 018; do not start a second server against its data. The bridge defaults to port 8765, and `config/services.php` points Laravel there unless `V3_WEB_PIPELINE_BRIDGE_URL` overrides it:

```sh
.venv/bin/python -m uvicorn v3_engine.bridge:app --host 127.0.0.1 --port 8765
.venv/bin/python -m v3_engine.terminal_pipeline.cli worker
APP_URL=http://127.0.0.1:8080 php artisan serve --host=127.0.0.1 --port=8080
php artisan schedule:work
```

Check port ownership first. In the current workspace, port 8000 is occupied by a Python directory server rooted in generated scenes, not Laravel. Laravel's outbox dispatcher is a scheduled command (`v3:dispatch-web-pipeline`, every minute); it does not support `--once`, and `queue:work` does not dispatch these outbox rows. For a manual one-time dispatch, run `php artisan v3:dispatch-web-pipeline --limit=50 --json`.

The actual worker facade is `python3 -m v3_engine.terminal_pipeline.cli worker`; the old `scripts/v3/run_temporal_pipeline.py worker` command is not the current entry point. The connected and live NotebookLM test results and remaining gates are recorded in `repair-evidence/regressions.md` and `repair-evidence/final-review.md`.

## Archive dry-run (no providers)

Replace destination IDs and generate a stable UUID once; use the same UUID on repeat:

```sh
php artisan v3:import-terminal-run \
 --terminal-root /home/moahmmed/college_project/storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root \
 --run-id 09a59afe-ed26-4567-906b-e42d87d53362 \
 --subject SUBJECT_ID --teacher TEACHER_ID --actor ADMIN_ID \
 --import-id IMPORT_UUID --dry-run
```

Required acceptance: verify the exact archive shape of 8 PDFs, 63 lessons and 89 prompt bodies; dry-run must not mutate application data; materialization and retries must preserve identities and source provenance; then verify owner navigation. Current isolated archive inspection confirms 8/63/89 and retained file hashes, but the complete queued-recovery and owner-navigation acceptance is still open. Do not interpret this requirement as completed evidence.

## Web processing and scenes

Sign in as active original teacher, open existing subject page, submit two disposable accepted PDFs in order with export_manual and explicit demo acknowledgment. Observe separate Divide/lesson loops/reset boundaries, actual counts and downloadable exact prompts. No generation key required and generation request count0. Import one static three-file scene JSON bound to exact prompt version; verify actual browser report and isolated preview, then teacher approves. Foreign teacher404/admin403. Demo basis remains visible.

For API test, select ONE saved scene, captured configured provider/model and explicit API generation/waiver acknowledgment. Verify actual requested/returned model, source manifest, runnable package and checks. Do not turn off thinking to bypass truncation. Recovery of an intact source is build-only without another provider send; uncertain sends require deliberate consent.

## Live gate

```sh
RUN_V3_LIVE_APPLICATION_GATE=1 .venv/bin/python -m pytest --run-live --import-mode=importlib v3_engine/tests/web_pipeline/test_live_web_pipeline.py -q -ra
RUN_V3_LIVE_APPLICATION_GATE=1 php artisan test tests/Integration/V3/WebPipeline/LiveApplicationGateTest.php
```

The live gate validates the saved NotebookLM session and approved proof notebook, two distinct disposable PDFs, Qwen key/model, and the exact `V3_LIVE_SPENDING_ACK=I_AUTHORIZE_ONE_QWEN_SCENE_REQUEST` before starting its joined Chromium run. Both commands remain skipped unless their gate-specific opt-in is set; the Python command also requires pytest’s `--run-live` marker opt-in. Missing prerequisites produce a skip before provider work; failures after startup fail the run. A live pass must exercise real web/bridge/worker/storage/provider/check/preview/decision boundaries and retain sanitized evidence. The runner is implemented, but this environment has not provisioned the Qwen credentials, spending acknowledgement, approved proof notebook ID, or dedicated PDFs, so T112/T152 remain open.
