# V3 Web Pipeline Integration: Operator & Administrator Guide

## 1. Overview & Architecture

The **V3 Web Pipeline** bridges the web application with the deterministic Temporal processing pipeline, enabling teachers to submit multiple PDF documents per subject, coordinate lesson and scene extraction, export prompts, manually import scenes, and review qualified 3D visualizations—all under strict privacy, security, and reproducibility guarantees.

### Key Architecture Principles
* **Separation of Concerns**: Laravel handles teacher intake, authentication, authorization, storage, and review decisions. Temporal (`v3_engine`) manages orchestrations, long-running activities, and worker capacity.
* **Privacy by Design**: Administrative monitoring exposes only operational metadata (counts, states, timing, errors). Sensitive educational contents (PDF texts, prompt bodies, lesson titles, OCR extracts) are never disclosed to admin endpoints.
* **Default Export/Manual Mode**: New runs default to `export_manual` mode. No paid provider calls or synthetic scenes are executed unless explicitly selected and authorized.
* **Idempotency & Replay Determinism**: All bridge admissions, outbox deliveries, and worker operations require unique command IDs and lease tokens with single-owner execution.

---

## 2. Prerequisites & Environment Setup

### 2.1 Runtime Requirements
* **PHP**: 8.2+ with `pdo_sqlite` or `pdo_mysql`, `fileinfo`, `openssl`, `mbstring`, `json`.
* **Python**: 3.11+ with `fastapi`, `uvicorn`, `temporalio`, `playwright`, `pydantic`.
* **Temporal Server**: v1.22+ cluster running locally or accessible via network.
* **Chromium**: Installed via Playwright for browser qualification checks (`playwright install chromium`).

### 2.2 PHP Configuration (`php.ini`)
The pipeline requires adequate upload capacity for multi-PDF batches (up to 10 PDFs of up to 100MB each):
```ini
upload_max_filesize = 100M
post_max_size = 1100M
max_file_uploads = 20
memory_limit = 512M
```
Verify that `upload_tmp_dir` is located on private local storage with restricted filesystem permissions (`0700` or `0750`).

### 2.3 Application Environment (`.env`)
Use the names consumed by the current Laravel and Python configuration. Keep the signing values in the deployment secret store and provide the matching values to both processes:

```dotenv
V3_WEB_PIPELINE_ENABLED=true
V3_DEFAULT_MODE=export_manual
V3_BRIDGE_URL=http://127.0.0.1:8765
V3_WEB_PIPELINE_BRIDGE_URL=http://127.0.0.1:8765
V3_BRIDGE_HMAC_KEY=<secret-store reference>
V3_CALLBACK_HMAC_KEY=<secret-store reference>
V3_TERMINAL_TEMPORAL_ADDRESS=127.0.0.1:7233
V3_TERMINAL_TEMPORAL_NAMESPACE=v3-terminal
```

Set PHP upload limits in `php.ini` as shown above; the old `V3_UPLOAD_MAX_FILESIZE`, `V3_POST_MAX_SIZE`, and `V3_MAX_FILE_UPLOADS` names are not read by the application. The bridge listens on 8765 by default in Laravel config. The bridge and workers read `V3_TERMINAL_TEMPORAL_NAMESPACE` (default `v3-terminal`); do not configure the worker under Temporal's default `default` namespace.

### 2.4 Storage Permissions
Use a private storage directory writable only by the application account. Inspect the existing ownership and permissions before changing them; do not recursively change permissions on a directory containing existing source files or artifacts. For a new empty private directory, create it with restricted permissions and the service account as owner.

---

## 3. Operational Lifecycle & Services

### 3.1 Starting the Services

Use the persistent Temporal service configured for Spec 018; do not start a second server against its state store. The web/worker namespace must match (`v3-terminal`). Check all ports before startup.

Configure the Laravel and Python processes from the same secret store/environment:

- `V3_WEB_PIPELINE_ENABLED=true` for an explicitly enabled deployment.
- `V3_BRIDGE_URL=http://127.0.0.1:8765` and `V3_WEB_PIPELINE_BRIDGE_URL=http://127.0.0.1:8765`.
- Matching `V3_BRIDGE_HMAC_KEY`, `V3_CALLBACK_HMAC_KEY`, and worker verification keys; never use blank/default keys.
- `V3_TERMINAL_TEMPORAL_ADDRESS=127.0.0.1:7233` and `V3_TERMINAL_TEMPORAL_NAMESPACE=v3-terminal` for workers; set `V3_TEMPORAL_ADDRESS` may override the address; the worker and bridge both read `V3_TERMINAL_TEMPORAL_NAMESPACE` (default `v3-terminal`).

Start the bridge on the same port Laravel is configured to call:

```bash
.venv/bin/python -m uvicorn v3_engine.bridge:app --host 127.0.0.1 --port 8765
```

Start the shared terminal/web Temporal workers:

```bash
.venv/bin/python -m v3_engine.terminal_pipeline.cli worker
```

Start Laravel on a verified unused loopback port. Port 8000 is currently occupied by an unrelated Python directory server in this workspace; use 8080 unless that ownership has changed:

```bash
APP_URL=http://127.0.0.1:8080 php artisan serve --host=127.0.0.1 --port=8080
```

Laravel's `v3:dispatch-web-pipeline` command accepts `--limit` and `--json`; it does **not** have an `--once` option. The command is registered in Laravel's scheduler. Run the scheduler worker so pending outbox commands are dispatched:

```bash
php artisan schedule:work
```

For a one-time manual dispatch, use:

```bash
php artisan v3:dispatch-web-pipeline --limit=50 --json
```

`queue:work` does not replace this outbox scheduler. The current workspace's existing port 8000 process serves a private generated-scenes directory listing and returns 404 for Laravel/bridge health routes; do not use it as the web application endpoint.

### 3.2 Readiness Health Checks
Run the operational readiness check:
```bash
php artisan tinker --execute="echo json_encode(app(\App\Services\V3\WebPipeline\Readiness::class)->check(), JSON_PRETTY_PRINT);"
```
Ensure `healthy: true` and all diagnostics report passing thresholds without exposing private credentials.

---

## 4. Workflows & Command Procedures

### 4.1 Teacher Subject Run Intake (Web UI)
1. Navigate to `/v3/teacher/subject-runs/create`.
2. Select target **Subject**.
3. Upload 1 to 10 PDF syllabus documents (each <= 100MB).
4. If demo generation is intended without full JEV qualification, check the **Demo Mode Waiver** checkbox.
5. Submit the form. The system assigns a unique UUID run ref and stages intake items in `storage/app/private/v3/`.

### 4.2 Run Controls: Pause, Resume, and Cancel
Teachers can pause, resume, or cancel active runs from the show view (`/v3/teacher/subject-runs/{id}`) or via API:
* **Pause Run**: Halts further activity dispatch after the currently executing task finishes.
* **Resume Run**: Unpauses an idle run, resuming queued activities.
* **Cancel Run**: Terminates in-flight activities and marks remaining items as cancelled.

API invocation:
```bash
POST /api/v3/teacher/subject-runs/{run_id}/control
Content-Type: application/json
Authorization: Bearer <teacher_token>

{
  "command_id": "uuid-v4",
  "action": "pause",     # pause | resume | cancel
  "reason": "Operator maintenance pause"
}
```

### 4.3 Prompt Manifest Export
When a run completes its initial NotebookLM and scene extraction phases, teachers can export prompts for offline or external manual authoring:
* **JSON Format**:
  ```bash
  GET /v3/teacher/subject-runs/{run_id}/export/prompts?format=json
  ```
  Returns full scene manifest including prompt IDs, section references, scene titles, and verbatim generation prompts.
* **Text Format**:
  ```bash
  GET /v3/teacher/subject-runs/{run_id}/export/prompts?format=text
  ```
  Returns human-readable text document structured by lecture and section.

### 4.4 Manual Scene Import Workflow
When authoring scenes manually without provider API calls:
1. Prepare a standard V2 static scene package consisting of exactly 3 files:
   - `scene.js`: Three.js procedural animation code.
   - `index.html`: Self-contained entry point canvas runner.
   - `manifest.json`: Metadata schema (title, description, version, dependencies).
2. Package must not contain nested subdirectories, symlinks, or files exceeding 5MB.
3. Import the package via teacher scene interface (`POST /v3/teacher/runs/{run_id}/scenes/{section_id}/import-manual`):
   ```json
   {
     "command_id": "uuid-v4",
     "prompt_version_id": "prompt-uuid",
     "package": {
       "index_html": "<!DOCTYPE html>...",
       "scene_js": "import * as THREE from 'three'; ...",
       "manifest_json": "{\"title\": \"Harmonic Oscillator\", ...}"
     },
     "reported_provider": "teacher_custom",
     "reported_model": "manual_code_v1"
   }
   ```
4. System stages the files under `storage/app/private/v3/cases/{case_id}/candidates/{candidate_id}/`.
5. Background worker automatically claims a browser check attempt.

### 4.5 Automated Browser Qualification Checks
The Playwright checker evaluates 7 strict requirements:
1. **Integrity**: Files match manifest SHA-256 hashes.
2. **Entrypoint**: `index.html` loads without fatal HTTP errors.
3. **Assets**: Zero missing CDN scripts, textures, or styles.
4. **Runtime**: Zero uncaught JavaScript errors or WebGL context losses.
5. **Network**: Zero unauthorized external tracking or remote resource requests.
6. **Interaction**: Canvas registers mouse/touch controls without crash.
7. **Accessibility**: Initial rendering passes canvas label and fallback inspections.

If all 7 checks pass and a valid 1280x720 PNG frame is captured:
- Candidate state advances to `demo_review_ready` (if demo-waived or manual) or `qualified`.
- Candidate becomes eligible for teacher review.

### 4.6 Teacher Review & Approval
1. Navigate to `/v3/teacher/reviews/{id}`.
2. System establishes a preview session and fetches `frame_0` proof image.
3. The teacher evaluates the rendered scene canvas.
4. Submission:
   - **Approve**: Confirms scene for student display. Requires frame-0 proof; notes must be null.
   - **Request Changes**: Rejects candidate and queues repair notes for prompt or code revision.

### 4.7 Historical Terminal Run Archive Import (CLI)
Administrators can import historical runs from terminal runs without re-executing pipelines or contacting LLM providers:

**Dry-Run Validation** (Validates paths, hashes, permissions; 0 database writes):
```bash
php artisan v3:import-terminal-run \
  --terminal-root="/path/to/terminal-root" \
  --run-id="be9b2b0b-d02f-4b5a-a13e-01b3e12e1ba0" \
  --subject=1 \
  --teacher=2 \
  --actor=1 \
  --dry-run
```
Expected output:
```json
{
  "import_id": "uuid-v4",
  "run_id": "be9b2b0b-d02f-4b5a-a13e-01b3e12e1ba0",
  "state": "validated",
  "safe_code": "ok",
  "is_dry_run": true,
  "pdf_count": 2
}
```

**Materialization** (Writes lectures, sections, prompts, links, and private archives):
```bash
php artisan v3:import-terminal-run \
  --terminal-root="/path/to/terminal-root" \
  --run-id="be9b2b0b-d02f-4b5a-a13e-01b3e12e1ba0" \
  --subject=1 \
  --teacher=2 \
  --actor=1
```

---

## 5. Monitoring & Administrator Privacy Boundaries

### 5.1 Admin Operational Dashboard
Administrators access global run metrics at `/v3/admin/subject-runs`:
* **Visible fields**: Run UUID, subject ID, teacher ID, current stage, item counts, duration, error codes.
* **Redacted / Omitted fields**: Lesson names, PDF titles, OCR extracts, section texts, teacher feedback notes.
* **Access Control**: Super-admin only (`role: admin` and active status).

The admin workspace also provides **Check Notebook Health**. It makes a signed, read-only request that checks whether the configured Google session can list NotebookLM notebooks; it does not generate content or use a paid model. A working session displays “All right.” If authentication is missing or expired, the page displays a local terminal command to refresh the cookie manually. Run that command in the project terminal, restart the bridge and workers as prompted, then check again. Bridge/network failures are reported separately so they are not mistaken for an expired cookie. Cookie values are never sent to the browser or shown in the page.

### 5.2 Log Sanitization & Event Storage
All pipeline logs generated by Temporal activities pass through `RunEventStore` and `sanitize_activity_result`:
* Stack traces and internal exception arguments are stripped before database insertion.
* Failure codes are normalized to high-level error categories (`validation`, `storage`, `timeout`, `quota`, `internal`).
* Secrets and authentication tokens are masked prior to any persistence.

---

## 6. Retention, Storage Cleanup, and Disaster Recovery

### 6.1 Storage Retention Rules
* **PDF Source Files**: Retained in `storage/app/private/v3/subjects/{subject_id}/runs/{run_id}/sources/`.
* **Prompt Versions & Artifacts**: Kept permanently as cryptographic lineage records.
* **Screenshots & Check Reports**: Kept in private disk; temporary browser logs rotated after 30 days.

### 6.2 Rollback & Replay
* Runs can be replayed deterministically through the temporal supervisor without duplicate event creation.
* If a run must be rolled back, use the soft-cancel control rather than manual SQL row deletion to preserve audit lineage.

---

## 7. Current Acceptance Status & Live Gate Prerequisites

### 7.1 Offline Verification Status: Core Web Integration Verified; Full Acceptance Open
* **Connected website path**: Chromium teacher intake of two PDFs was followed through Laravel, signed FastAPI bridge admission, local Temporal workers, NotebookLM service transport, lesson/prompt persistence and JSON/text downloads. In the same run, manual import and selected Qwen authoring reached Chromium checks, preview, approval, and a consumed Temporal review decision. The external NotebookLM and Qwen provider transports were scripted for this offline test.
* **Current regressions**: 170 Laravel WebPipeline tests passed (978 assertions), one live-gate test skipped; 92 Python WebPipeline tests passed, seven opt-in Temporal/live tests skipped. The Temporal-enabled joined runtime file passed 10 tests.
* **Acceptance limits**: The evidence manifest currently fails `scripts/v3/verify_web_repair_evidence.py --require offline` with 78 errors. The exact original archive import/navigation acceptance and complete A01–A28 execution mapping are still open. See `specs/019-v3-web-integration/repair-evidence/final-review.md` for the superseding status.

### 7.2 Dedicated Live Gate: Explicitly Open (G06 / T112 / T152)
In accordance with the Spec 019 acceptance protocol, live gate G06 remains explicitly open until dedicated live credentials and container capabilities are provisioned:
1. `NOTEBOOKLM_SESSION_PROOF_V3`: Valid live Google NotebookLM session cookies and storage state proof.
2. `QWEN_LIVE_API_CREDENTIAL`: Live funded Alibaba Cloud Qwen API token for paid external inference.
3. `LIVE_PLAYWRIGHT_CHROMIUM_SANDBOX_SYSADMIN`: Host container privileges for Chromium sandboxing in production environments.

