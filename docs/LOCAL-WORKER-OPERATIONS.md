# Local Worker Operations & Deployment Guide (Feature 021)

This document provides operational instructions, architectural boundaries, and configuration examples for running the **Outbound Local Processing Worker** with the hosted EduPlatform platform.

The actual laptop is now enrolled with the local Laravel installation and runs supervised standalone services. See [the integration/deployment guide](LOCAL-WORKER-INTEGRATION.md) for the configured runtime, status/start/stop commands, verified protocol boundaries, and changing to a future hosted HTTPS origin. The server is currently local; no public hosting deployment is implied.

## Daily workflow: existing terminal pipeline and manual scene generation

The production laptop runs the **outbound worker and the existing Temporal terminal pipeline**. Laravel, its database, queues and Dashboard belong to the hosted server. Do not start a local Laravel instance to operate the laptop worker.

1. Keep the existing local Temporal service and `run_temporal_pipeline.py worker` running, using the same local terminal root and saved NotebookLM session as before. See [the terminal operations guide](PER-PDF-NOTEBOOKLM.md). No second Google login or `~/.local_worker/credentials.json` file is required by this adapter.
2. Enroll the laptop against the hosted platform. Point `--terminal-manifest` at a **local, already working terminal submission JSON containing one new-mode subject**. Its account-check notebook, provider/model and timeout settings become the local settings template. Downloaded run PDFs replace its sample PDF list; the hosted run's captured framework replaces its steps path. Neither account credentials nor local absolute paths are uploaded as configuration.

   ```bash
   ./bin/local-worker enroll --server https://YOUR-HOSTED-ORIGIN \
     --terminal-manifest /absolute/path/to/your-working-terminal-submission.json
   ./bin/local-worker run
   ```

   Pairing-secret entry is masked. The local worker saves its own hosted-device token separately from the existing NotebookLM session. For an already enrolled worker, `run --terminal-manifest /absolute/path/to/submission.json` supplies the template without re-enrollment. `V3_LOCAL_WORKER_TERMINAL_MANIFEST` is the equivalent local environment setting. If `V3_TERMINAL_ROOT` is customized, give the terminal worker and outbound worker the **same absolute root**.
3. On the hosted platform, submit a run using `local_worker_v1` and **Prompts & Scenes**. The outbound worker downloads and verifies the prepared PDFs, then invokes the existing CLI with `submit --pdf-processing per-pdf --prepared-input-manifest <sealed-input-manifest> --export-prompts --json`. The adjacent `.sha256` seal must match. This optional CLI path snapshots the exact hosted parts, verifies their hashes and page counts, and retains original source/page/item lineage without splitting them again (including historical intact parts larger than 50 pages). Ordinary CLI submissions retain their existing local preparation behavior. The terminal implementation owns NotebookLM processing and its own durable checkpoints. The adapter does not reproduce its map/lesson loop or call a scene-generation API.
4. After authoring is accepted by the hosted server, the worker prints the path to:

   ```text
   <worker-workspace>/runs/<hosted-run-id>/run.json
   ```

   The file contains `run_id`, the separate `terminal_run_id`, local `input.pdfs`, `output.terminal_run_dir`, `output.scenes_dir`, the exact shared generation context and `generated_prompts[]`. Every prompt retains its hosted scene ID, prompt-version ID, exact text and SHA-256, plus the concrete `output_dir` and `entrypoint` for that scene. It contains no NotebookLM cookies or device token.
5. Give the JSON path to a local coding agent such as Gemini and ask it to generate every listed scene into its recorded `output_dir`. Keep the recorded IDs and prompt text unchanged. A self-contained `index.html` is accepted; `styles.css` and `script.js` can also be returned when generated. The worker transfers the actual UTF-8 files without fabricating missing sidecars or judging the educational content.
6. In the hosted teacher run page, or the platform owner's **Subject Runs** page, press **Pull Generated Scenes**. This records a durable request: it does not open an inbound connection to the laptop. The laptop worker claims the queued return task over its existing outbound connection and uploads the run files. Only the device that authored the run can claim its scene-return task.

Manual generation has no running lease: the return task stays `awaiting_pull` until the button is pressed. Pull requires the complete run: all files are checked before any upload. If files are missing, the worker reports the missing scene ID and releases the task back to `awaiting_pull`, so other queued work can continue. The Dashboard button becomes available again after that return assignment is released. Finish the files and press Pull again. At first upload the entire transfer batch is frozen locally; network retries use those bytes and stable upload/submission IDs even if the source HTML changes. Completed runs cannot be silently replaced. Transfer completion is distinct from educational review and student publication; an unavailable qualification service does not hold the file-transfer stage open.

Eligible tasks are selected by `created_at`, then `id`; one active or reconciliation assignment is allowed per device. Returning scenes and recovering terminal executions stay on the original laptop because their checkpoints/files are local. `run.json` retains subject/PDF/lesson labels, the original prompt text and its digest, and authoritative scene IDs. Filenames are not parsed to determine ownership or destination.

The server fences the launch of the terminal run as one logical effect. Individual NotebookLM operations remain owned by the existing terminal workflow's journal/checkpoints. Stable hosted-job-derived submission and terminal-run IDs prevent a transport retry from selecting a new terminal run. An ambiguous dispatch or expired lease remains held for reconciliation; do not create a new submission ID merely to bypass that hold.

The automated bridge tests use real terminal capture, archive and export code with a clearly isolated provider double. They are not evidence of a live Google session, paid generation, human scene quality or a public deployment.

---

## 1. Architectural Model

```
+-------------------------------------------------------------+
|                     Hosted Laravel Platform                 |
| - Teacher Intake & Document Preparation (Provider-Free)      |
| - Immutable Job & Stage Manifest Sealing (SHA-256)          |
| - Durable FIFO Task Queue & Monotonic Assignment Epochs     |
| - Pre-Send Dispatch Fence & Authoritative Results Commit    |
| - Store Returned Files; Review/Publication Remain Separate |
+-------------------------------------------------------------+
                              ^
                              | Outbound HTTPS only (TLS)
                              | No inbound ports opened on worker
                              v
+-------------------------------------------------------------+
|                 Outbound Local Processing Worker            |
| - Private CLI & Exclusive Process Workspace Lock            |
| - Local SQLite WAL Effect Journal & Crash Window Tracking   |
| - Invoke Existing Local Temporal Terminal Pipeline          |
| - Reuse Existing Terminal NotebookLM Session                |
| - Emit run.json; Upload Operator-Generated Scene Files      |
+-------------------------------------------------------------+
```

### Core Privacy & Security Invariants
- **No Provider Credentials on Server**: Google session tokens, OAuth tokens, and browser profiles are never uploaded, proxied, or copied to the hosted platform.
- **No Server Secrets on Worker**: The worker never receives database credentials, application master keys, or unrestricted Temporal access.
- **Outbound-Only HTTPS**: Communication is initiated exclusively by the worker using Bearer device tokens issued through single-use pairing codes.
- **Fenced Execution**: The worker journals and obtains a hosted permit before launching a terminal run. NotebookLM mutations inside that durable run use the existing terminal pipeline's operation receipts and recovery rules.

---

## 2. Hosted Platform Operations

### Configuration & Migrations
Ensure local worker database migrations have executed:
```bash
php artisan migrate
```

Verify tables are present:
- `v3_processing_devices`
- `v3_device_pairings`
- `v3_local_jobs`
- `v3_local_tasks`
- `v3_local_assignments`
- `v3_local_provider_effects`
- `v3_local_artifacts`
- `v3_local_results`

### Device Management & Pairing Code Generation
Platform owners manage enrolled workers via the web UI at `/v3/admin/devices` or via API:
1. Navigate to **Operations Console** -> **Processing Devices** (`/v3/admin/devices`).
2. Enter a descriptive device name (e.g., `Lab-Worker-Alpha`) and click **Generate Pairing Code**.
3. Copy the one-time pairing secret before its displayed expiry. The raw secret is never stored in plaintext and cannot be retrieved again after display.

---

## 3. Local Worker CLI Reference

The local worker executable is located at `bin/local-worker` (or via Python module `python3 -m v3_engine.local_worker.cli`).

### Command Overview
```text
local-worker enroll --server <https-origin>             # Pair device and save config (0600)
local-worker doctor                                   # Verify environment, storage, and server reachability
local-worker run                                       # Run daemon for authoring tasks
local-worker run --operator-id <teacher-user-id> --provider <reported-provider> --model <reported-model> --effort <reported-effort>
local-worker status                                   # Print worker and SQLite journal status
local-worker stop --drain                              # Signal running daemon to drain and halt
local-worker export-prompts --job <id> --output <dir>   # Export exact accepted prompt texts
local-worker import-scenes --job <id> --input <dir>     # Validate and stage manual 3-file scene packages
local-worker reconcile --job <id>                      # Read-first recovery for unconfirmed effects
```

### 1. Enrollment (`enroll`)
Enrolls the machine with the hosted server using the masked pairing code:
```bash
./bin/local-worker enroll --server https://eduplatform.example.com
# Prompts securely for pairing code:
# Enter pairing secret: ********
```
Saved configuration: `~/.local_worker/config.json` with strict POSIX permissions `0600`.

### 2. Environment Doctor (`doctor`)
Runs a comprehensive, secret-free preflight audit:
```bash
./bin/local-worker doctor
```
Checks verified:
- Configuration file existence, readability, and `0600` permissions.
- Local workspace directory write access and disk storage headroom (>= 1 GB).
- Local SQLite WAL journal health.
- Server connectivity and authentication (`GET /api/v3/local-worker/v1/device`).
- Provider credentials presence (secret-free check without contacting Google).

### 3. Worker Daemon (`run`)
Starts the outbound long-polling execution daemon:
```bash
# Standard background daemon
./bin/local-worker run

# Process a single queued task and exit (useful for CI/testing)
./bin/local-worker run --once

# Use deterministic offline double for transport testing without live credentials
./bin/local-worker run --once --offline-double

# For manual scene returns, report the actual teacher/operator and the details
# supplied by the person who created the scene packages:
./bin/local-worker run --operator-id 42 --provider Gemini --model "Gemini 2.5 Pro" --effort high
```

The worker binds the authenticated device ID and defaults the operator identity to that device's enrolled platform owner. Use `--operator-id` when the assigned teacher performed the generation instead. The server accepts only that teacher or the enrolled device owner. Provider, model and effort remain null unless reported by the operator; API usage remains `not_recorded` for manual web authoring.

### 4. Status Check (`status`)
Inspects active lock, process status, and uncommitted journal effects:
```bash
./bin/local-worker status
```

### 5. Graceful Drain & Stop (`stop --drain`)
Signals the running daemon to stop at a safe boundary without claiming new work. During terminal processing it requests pause on the existing Temporal run and retains its checkpoint; an uncertain dispatch remains held for owner reconciliation:
```bash
./bin/local-worker stop --drain
```

### 6. Export Prompts (`export-prompts`)
Optional legacy export utility for an already downloaded scene-return manifest. The daily workflow uses the automatically written `run.json` directly, without needing this command:
```bash
./bin/local-worker export-prompts --job <JOB_UUID> --output ./prompts_export
```

### 7. Import Scene Packages (`import-scenes`)
Optional legacy staging utility for three-file packages. The daily workflow reads the agent's actual files directly from the `output_dir` entries in `run.json`, so no import command is needed:
```bash
./bin/local-worker import-scenes --job <JOB_UUID> --input ./scenes_dir
```

### 8. Reconciliation (`reconcile`)

For normal owner-operated recovery, use the Dashboard's **Recover run** link (also available in **Subject Runs**). The recovery page first offers **Check worker**. This creates the existing owner-issued read-only assignment; the running outbound daemon collects and uploads the saved terminal evidence automatically, once per assignment. When valid evidence is available, **Continue processing** authorizes the existing fenced `resolve-terminal` operation. The daemon then attaches to the same terminal run and resumes it if paused. No local reconciliation command or daemon shutdown is needed for this dashboard path. The page refreshes automatically, and an expired assignment remains held until its lease is cleared and another check is requested.

The command-based procedure below remains available for advanced/manual recovery.
Executes read-first recovery when an effect crashed in the `may_have_been_sent` window:
```bash
./bin/local-worker reconcile --job <JOB_UUID>
```
For terminal-backed tasks, the operator workflow is:

1. Stop the outbound daemon; keep the local Temporal service and terminal worker available. Let the hosted lease reaper expire its assignment. The reaper clears dead assignment pointers without clearing holds. It also expires abandoned reconciliation assignments, allowing another owner-led recovery attempt.
2. As the platform owner, call `POST /v3/admin/local-worker/tasks/<TASK_UUID>/reconciliation` in an authenticated web session. This creates a short-lived, read-only reconciliation assignment for the original device.
3. Run `./bin/local-worker reconcile --job <JOB_UUID> --task <TASK_UUID>`. It verifies the saved input manifest and terminal identity, then either validates the retained export/archive bytes or queries the existing Temporal run. It submits bounded evidence and prints the real `effect_id`, `terminal_run_id`, and `evidence_artifact_id`. A missing, failed, ambiguous or unavailable run remains held. Exit code 4 means owner action is still required; it is not a successful processing claim.
4. Review the evidence as owner, then call `POST /v3/admin/local-worker/tasks/<TASK_UUID>/reconciliation/resolve-terminal` with JSON fields `request_id` (a fresh UUID), `effect_id`, `evidence_artifact_id`, and `acknowledge_existing_terminal_run: true`. The request is idempotent. Evidence must be committed, unchanged, task-bound, and name the deterministic terminal run and original request digest. Another device cannot consume this recovery.
5. Restart `./bin/local-worker run`. The fresh fenced assignment may attach only to that existing terminal run: completed exports are reused, running workflows are observed, and paused workflows receive the existing CLI `resume` command. No new `submit` is sent on the recovery path. A new failure requires fresh evidence/authorization; previous send history is retained.

The existing `resolve-no-effect` route is only for genuinely verified absence of an external effect. Do not use it for a terminal run that exists. Unknown provider measurements stay null/`not_recorded`. Existing exact result receipts are replayed without new processing; an uncommitted retained result can be fenced to a newer active assignment while preserving its output bytes.

For one-task automation, `run --once` returns a nonzero status when execution fails or remains held. A premature manual Pull returns to waiting rather than inventing a completed result.

If NotebookLM requires refreshed authentication, update the **existing run's isolated session copy** as well as the configured main cookie file. Follow the check/import commands with `--run-dir` in [the terminal recovery guide](PER-PDF-NOTEBOOKLM.md#resume-and-failure-boundaries), verify live access, then resume the same hosted/terminal run through the reconciliation procedure above. Changing only the main cookie file does not replace the private copy used by an existing run. Provider HTTP errors or ambiguous chat delivery are not themselves proof that cookies expired. Native provider holds are queryable/versioned; an existing ambiguous chat intent is inspected rather than blindly sent again. Configuration recovery only reapplies the desired settings after a successful read proves a mismatch, then requires positive readback.

### Test-only Pull of retained prompts

To verify ordering without generating HTML or continuing NotebookLM processing, `scripts/v3/verify_retained_prompt_pull.py` accepts an earlier isolated verification runtime, its terminal run ID and a count of existing lessons. It creates a new private clone and never resumes the provider. For the retained Medical Informatics run:

```bash
.venv/bin/python scripts/v3/verify_retained_prompt_pull.py \
  --runtime storage/app/private/v3/local-worker-verification/live-efcea2c5-98a5-4ac1-a23c-41a0d57304de \
  --terminal-run-id a03c78b8-6563-50e4-9ec1-a7f54c8d30b4 \
  --lesson-count 9 --output /absolute/path/to/a-new-private-probe-directory
```

This explicitly scoped fixture uses real retained responses, real Dashboard Pull/claims and the normal artifact gateway, uploads prompt `.txt` files in reverse order and compares server bytes/IDs and Dashboard order. It leaves the original incomplete run unchanged and does not accept text as HTML scene content. Outputs include exact individual text files, a ZIP, Dashboard exports and verification JSON. It automatically stops its temporary server. Normal daily Pull continues to require actual scene files.

---

## 4. Disaster Recovery & Health Maintenance

### Lease Expiration & Disconnected Workers
- Default heartbeat: every 15 seconds.
- Default lease: 120 seconds.
- Pre-send dispatch permits expire within 10 seconds.
- If a worker loses connectivity with no dispatched effects, the server safely requeues the task.
- If a worker crashes after dispatch permit issuance, the task enters `reconciliation_required` and will NOT be automatically replayed to prevent duplicate provider mutations.

### Database Backups
- Server-side SQLite or PostgreSQL backup preserves all `v3_local_*` tables with referential integrity.
- Worker-side `~/.local_worker/workspace/journal.sqlite` retains local crash history and WAL files.

---

## 5. Summary of Compliance & Security Auditing
- **No Synthetic Results**: Incomplete tasks fail closed; unknown measurements remain `not_recorded`.
- **Content-Free Operational Events**: Task logs carry opaque UUIDs; private educational PDFs and prompt texts are never printed in public server logs.
- **Strict Fencing**: Server-enforced monotonic epochs prevent split-brain execution across multiple worker daemons.
