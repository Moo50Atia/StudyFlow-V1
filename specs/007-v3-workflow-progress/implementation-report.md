# Implementation Report: 007 V3 Workflow Start and Progress

## 1. Executive Summary

This report documents the complete implementation of **V3 Spec 007: Workflow Start and Progress** (`specs/007-v3-workflow-progress/`) in `/home/moahmmed/college_project`.

All 21 deliverables defined in [tasks.md](tasks.md) and [task-execution-plan.md](task-execution-plan.md) (T001–T021) have been implemented, tested via strict Test-Driven Development (TDD), and verified against their acceptance contracts. The implementation establishes:
1. **Durable Temporal Workflow Identity:** Guaranteed exactly one deterministic Temporal workflow ID (`v3-case-{case_id}`) started per 006 case under concurrent and repeated discovery, with `WorkflowIDReusePolicy.REJECT_DUPLICATE` and exact memo verification.
2. **Signed Worker Boundary:** Dedicated private callback endpoint (`POST /api/internal/v1/case-events`) authenticated by lowercase hex HMAC-SHA256 over method, path, timestamp, and body digest with constant-time verification and ±300s window. Web sessions and admin cookies are strictly prohibited.
3. **Atomic Milestone Projection:** Atomic database transactions that update case state/version, record immutable sequence events, update workflow links, and reject stale versions, unknown cases, or payload conflicts with deterministic HTTP error contracts.
4. **Captured Settings Retry Policy:** Verified propagation of 005 captured immutable technical retries (`technical_retries + 1` maximum attempts, exponential backoff) into Temporal activity execution without falling back to active pointers.
5. **Deterministic Review Gate Primitive:** Reusable Temporal `ReviewGate` workflow primitive (`v3_engine/workflows/review_gate.py`) tested with synthetic candidate/decision fixtures, handling stale versions, duplicate signals, conflicting decisions, and worker restarts while holding no activity worker.
6. **User Progress & Privacy:** Scoped teacher progress route (`GET /v3/teacher/cases/{case}/progress`), closed administrative metadata index/summary (`GET /v3/admin/cases`, `GET /v3/admin/cases/{case}/summary`), DTO resources (`CaseProgressResource`, `ClosedCaseSummary`), Blade views, and strict isolation preventing teacher/document/prompt/scene leakage.
7. **Extraction Handoff Boundary:** Truthful parking at extraction handoff via `await workflow.wait_condition(lambda: False)` in `CaseWorkflow.run`, ensuring no synthetic extraction or mock production activities exist ahead of Spec 008.

---

## 2. Baseline Inventory & Environmental Status

### System Tools & Runtimes
- **PHP Version:** 8.5.0 (cli) (built Nov 20 2025, NTS x86_64-linux-musl-gcc)
- **Laravel Framework:** 12.42.0
- **Python Version:** Python 3.12.3 (GCC 13.3.0 on linux)
- **Temporal CLI:** 1.9.1 (server 1.28.1) installed at `/home/moahmmed/.local/bin/temporal`
- **Temporal Python SDK:** temporalio 1.34.0, pytest-asyncio 1.4.0, fastapi 0.115.12, uvicorn 0.34.0, httpx 0.28.1
- **Test Frameworks:** Pest 3.8.4 / PHPUnit 11.5.46 and pytest 9.1.1
- **Database Engine:** SQLite (in-memory for unit/feature tests; isolated file-backed databases with `PRAGMA journal_mode = WAL;` and `PRAGMA busy_timeout = 5000;` for cross-process concurrency suites)

### Pre-Existing Workspace State
- `.specify/feature.json` retained its original value `{"feature_directory":"specs/004-v3-access-ownership"}`.
- Prerequisite suites (004 Access, 005 Settings, 006 Intake) were preserved and confirmed 100% green before and after 007 changes.
- Untracked files and pre-existing modifications in other directories were preserved.

---

## 3. Inventory of Changes

### Python Package (`v3_engine/`)
- `pyproject.toml`: Configured `v3_engine` package with dependencies `temporalio`, `fastapi`, `uvicorn`, `httpx`, and dev dependencies `pytest`, `pytest-asyncio`. Installed in editable mode (`pip install -e /home/moahmmed/college_project/v3_engine`).
- `v3_engine/__init__.py`: Package root.
- `v3_engine/contracts.py`: HMAC-SHA256 signature computation and constant-time validation (`compute_hmac_signature`, `verify_hmac_signature`).
- `v3_engine/settings.py`: Dynamically reads `V3_TEMPORAL_ADDRESS`, `V3_TEMPORAL_NAMESPACE`, `V3_TASK_QUEUE`, `V3_BRIDGE_HMAC_KEY`, `V3_CALLBACK_HMAC_KEY`, `V3_LARAVEL_CALLBACK_URL`, `V3_BRIDGE_PORT`, `V3_BRIDGE_HOST`.
- `v3_engine/client.py`: Async connection helper returning authenticated Temporal SDK `Client`.
- `v3_engine/bridge.py`: FastAPI HTTP bridge for Laravel-to-Python communication:
  - `POST /internal/v1/workflows/start`: Verifies HMAC signature, validates payload, invokes `client.start_workflow` with `WorkflowIDReusePolicy.REJECT_DUPLICATE` and exact memo containing `case_id`, `settings_version_id`, `settings_checksum`, `schema_version: 1`. On conflict, describes existing execution, verifies exact memo, and returns existing run ID or 409 mismatch.
  - `GET /internal/v1/workflows/{workflow_id}`: Verifies HMAC signature over empty body, queries Temporal execution description and memo, and returns `temporal_status`, `run_id`, `case_id`, `settings_version_id`, `settings_checksum`.
- `v3_engine/worker.py`: Temporal worker runner registered on task queue `v3-cases` with `CaseWorkflow` and `publish_workflow_started` activity.
- `v3_engine/workflows/case_workflow.py`: Production workflow execution:
  - Schedules `publish_workflow_started` activity with captured retry policy (`RetryPolicy(initial_interval=retry_delay_seconds, maximum_attempts=technical_retries+1, non_retryable_error_types=["ContractInvalidError", "EventConflictError"])`).
  - Parks truthfully at extraction handoff via `await workflow.wait_condition(lambda: False)`.
- `v3_engine/workflows/review_gate.py`: Reusable deterministic review wait primitive (`ReviewGate`) with `enter()`, `handle_signal()`, `wait_for_decision()`, and `get_state()`.
- `v3_engine/activities/publish_milestone.py`: Activity `publish_workflow_started` computing dynamic HMAC timestamp/signature and POSTing event payload to Laravel callback URL. Classifies 401/422 as `ContractInvalidError` (non-retryable), 409 as `EventConflictError` (non-retryable), and timeout/503 as retryable `ApplicationError`.

### Database Migrations
- `database/migrations/2026_10_02_000007_create_v3_workflow_tables.php`:
  - `v3_workflow_links`:
    - `case_id` (UUID PK, FK references `v3_processing_cases.id` ON DELETE RESTRICT).
    - `workflow_id` (string 128, unique).
    - `run_id` (string 64, nullable).
    - `settings_version_id` (FK references `v3_settings_versions.id` ON DELETE RESTRICT).
    - `settings_checksum` (char 64).
    - `start_state` (`pending`, `starting`, `started`, `uncertain`, `identity_conflict`, `history_missing`, `closed`).
    - `stage_code` (`intake`, `extraction`, etc.).
    - `status_code` (`queued`, `running`, `blocked`, `failed`).
    - `error_code` (nullable).
    - `lease_until`, `started_at`, `start_attempted_at`, `last_event_at`, `last_reconciled_at`, `last_alerted_at`, `last_alert_code`.
  - `v3_case_events`:
    - `id` (UUID PK).
    - `case_id` (UUID FK references `v3_processing_cases.id` ON DELETE RESTRICT).
    - `event_key` (string 128).
    - `payload_digest` (char 64).
    - `sequence` (integer unsigned).
    - `workflow_id` (string 128).
    - `run_id` (string 64).
    - `event_type` (`workflow_started`, `stage_started`, `stage_completed`, `workflow_failed`).
    - `stage_code`, `status_code`, `error_code` (nullable).
    - `recorded_at` (timestamp).
    - Unique constraints: `(case_id, event_key)` and `(case_id, sequence)`.

### Eloquent Models
- `app/Models/V3/WorkflowLink.php`: Model with immutability guards protecting `case_id`, `workflow_id`, `settings_version_id`, and `settings_checksum` once set.
- `app/Models/V3/CaseEvent.php`: Model with strict immutability preventing any updates or deletions of committed event records.
- `app/Models/V3/ProcessingCase.php`: Extended with `workflowLink()` HasOne relation.

### HTTP Middleware & Internal Routing
- `app/Http/Middleware/V3/VerifyWorkerSignature.php`: Validates worker signature headers (`X-V3-Timestamp`, `X-V3-Signature`, `X-V3-Contract-Version: 1`), enforces ±300s window, verifies constant-time hash_equals against configured key, and rejects requests without model lookup.
- `routes/api.php`: Dedicated internal route `POST /api/internal/v1/case-events` using `VerifyWorkerSignature` middleware (loaded in `bootstrap/app.php` with no session or CSRF middleware).

### Services
- `app/Services/V3/Workflow/BridgeClient.php`: Signs outgoing requests with `services.v3.bridge_hmac_key`, communicates with Python bridge (`startWorkflow`, `describeWorkflow`).
- `app/Services/V3/Workflow/ProjectCaseEvent.php`: Validates event payloads, verifies state transitions and expected versions, deduplicates identical payloads (returning 200), rejects conflicting payloads (409) or stale versions (409), commits immutable events, and updates case and link projections within a retryable transaction.
- `app/Services/V3/Workflow/DiscoverCases.php`: Claims `awaiting_workflow` cases without links under row locks, inserts `pending` link, and dispatches `StartCaseWorkflowJob` after transaction commit.
- `app/Services/V3/Workflow/StartWorkflow.php`: Rechecks 004 active teacher status, verifies 005 captured settings checksum, acquires CAS lease, sends HTTP POST to bridge, updates link start state (`started` on 200, `uncertain` on timeout/network failure).
- `app/Services/V3/Workflow/ReconcileWorkflowStart.php`: Reconciles `started`, `uncertain`, and expired `starting` leases, describes execution, detects closed failures, enforces 24-hour overdue alerts and 30-day history missing rules, and logs deduplicated operator alerts.

### Jobs & Commands
- `app/Jobs/V3/StartCaseWorkflowJob.php`: Dispatches workflow start on dedicated `v3_workflow` queue (`after_commit=true`, `tries=1`, `timeout=30`).
- `app/Console/Commands/V3/DispatchWorkflowsCommand.php`: Artisan command `v3:dispatch-workflows` executing discovery and reconciliation, scheduled every minute in `routes/console.php`.

### User Progress & Administrative Endpoints
- `app/Http/Controllers/V3/Teacher/CaseProgressController.php`: Handles `GET /v3/teacher/cases/{case}/progress` (`v3.teacher.cases.progress`), enforces 004 ownership (404 missing/foreign equivalence), calculates safe `delayed` flag (300s elapsed without events), paginates milestones (20/page, `sequence ASC`), and supports JSON and HTML views with `Cache-Control: private, no-store`.
- `app/Http/Controllers/V3/Admin/CaseSummaryController.php`:
  - `GET /v3/admin/cases` (`v3.admin.cases.index`): Paginates closed cases ordered newest first (`created_at DESC, id DESC`).
  - `GET /v3/admin/cases/{case}/summary` (`v3.admin.cases.summary`): Details endpoint returning exact allowlisted operational scalars.
- `app/Http/Resources/V3/CaseProgressResource.php`: Formats progress payload.
- `app/Http/Resources/V3/ClosedCaseSummary.php`: Explicit scalar DTO converter using `OperationSummaryData` ensuring no model relationships or sensitive attributes can leak.
- `app/Data/V3/OperationSummaryData.php`: Extended allowlist with 007 stage and error codes.
- `resources/views/v3/teacher/cases/progress.blade.php`: Teacher workflow progress Blade view.
- `resources/views/v3/admin/cases/index.blade.php`: Admin operational cases monitoring table.
- `resources/views/v3/admin/cases/summary.blade.php`: Admin operational case detail summary.
- Navigation links updated in `resources/views/v3/teacher/cases/show.blade.php` and `resources/views/v3/admin/workspace.blade.php`.

---

## 4. TDD Verification and Evidence by Task Group

### Group 1: Foundation (T001–T004)
- **Tests:** `tests/Feature/V3/Workflow/WorkflowSchemaTest.php`
- **Result:** 6 passed (24 assertions).
- **Evidence:** Verified primary key, foreign keys (`RESTRICT`), unique constraints on `(case_id, event_key)` and `(case_id, sequence)`, and model immutability guards.

### Group 2: Callback Contract (T005–T006)
- **Tests:** `tests/Feature/V3/Workflow/WorkerBoundaryTest.php`, `tests/Feature/V3/Workflow/EventProjectionTest.php`
- **Result:** 11 passed (52 assertions).
- **Evidence:** Verified HMAC verification, ±300s expiration, 401 without model lookup, prevention of web cookie bypass, atomic event insertion, exact duplicate replay (200), changed payload conflict (409), stale version conflict (409), and contract validation (422).

### Group 3: Start Discovery (T007–T008)
- **Tests:** `tests/Feature/V3/Workflow/WorkflowStartTest.php`, `tests/Integration/V3/Workflow/WorkflowStartConcurrencyTest.php`
- **Result:** 8 passed (29 assertions).
- **Evidence:** Verified single link creation before HTTP POST, link-before-HTTP invariant, two concurrent discovery processes linearizing to exactly one link and job, inactive teacher deferral, corrupt captured settings rejection (`settings_unavailable`), 24h overdue alert deduplication, and 30d history missing detection.

### Group 4: Python Bridge (T009–T010)
- **Tests:** `v3_engine/tests/workflow/test_start_bridge.py`
- **Result:** 4 passed (live Temporal dev server).
- **Evidence:** Verified bridge start on Temporal dev server with fixed `v3-case-{case_id}` ID, exact memo verification, reject duplicate policy, memo mismatch 409 conflict, and GET describe memo extraction.

### Group 5: Event Races (T011–T012)
- **Tests:** `tests/Integration/V3/Workflow/EventConcurrencyTest.php`, `tests/Feature/V3/Workflow/EventProjectionTest.php`
- **Result:** 8 passed (52 assertions).
- **Evidence:** Verified two independent PHP processes attempting concurrent distinct events against shared file-backed SQLite under WAL mode; exactly one process committed (201) and the competing process failed with version conflict (409). State version strictly advanced from 2 to 3 with exactly 2 total events recorded.

### Group 6: User Progress & Privacy (T013–T014)
- **Tests:** `tests/Feature/V3/Workflow/ProgressAccessTest.php`, `tests/Feature/V3/Workflow/PrivacyTest.php`
- **Result:** 11 passed (160 assertions).
- **Evidence:** Verified teacher progress route ownership (foreign/missing 404 equivalence), `delayed` flag computation (true after 300s of inactivity), stable pagination, admin closed metadata DTO with exact 9 allowlisted keys, zero-count projection for undiscovered cases, prevention of DELETE routes (405), and HTML view rendering with `Cache-Control: private, no-store`.

### Group 7: Security & Handoff Contract (T015–T016)
- **Tests:** `tests/Feature/V3/Workflow/HandoffContractTest.php`, `WorkerBoundaryTest.php`, `PrivacyTest.php`
- **Result:** 16 passed (1196 assertions).
- **Evidence:** Verified entire route inventory contains no premature review, approval, or content pipeline routes; confirmed `case_workflow.py` truthfully parks at `await workflow.wait_condition(lambda: False)`; verified no premature database tables exist; confirmed no secret keys or document payloads are logged.

### Group 8: Live Retry/Recovery (T017–T018)
- **Tests:** `v3_engine/tests/workflow/test_retry_policy.py`, `v3_engine/tests/workflow/test_case_workflow.py`, `tests/Integration/V3/Workflow/LiveBridgeTest.php`
- **Result:** 6 passed (Python tests on Temporal dev server; LiveBridgeTest with live Temporal server, FastAPI bridge, Laravel HTTP server, and Python worker).
- **Evidence:**
  - `test_retry_policy.py`: Verified captured settings retry policy configuration (`technical_retries + 1`), error classification (401/422/409 non-retryable, timeout/503 retryable).
  - `test_case_workflow.py`: Verified `CaseWorkflow` execution, milestone publication, parking wait condition, failure on contract error, and worker restart survival.
  - `LiveBridgeTest.php`: Live end-to-end integration proving `BridgeClient` -> FastAPI bridge -> Temporal dev server -> Python worker -> Laravel HTTP callback -> atomic event commit & state transition -> describe reconciliation -> worker restart.

### Group 9: Review Primitive (T019–T020)
- **Tests:** `v3_engine/tests/workflow/test_review_gate.py`
- **Result:** 4 passed (live Temporal dev server).
- **Evidence:** Verified `ReviewGate` primitive: valid decision signal resolution, rejection of stale version or mismatched candidate ref, idempotent duplicate signal handling, rejection of conflicting outcomes on same decision ID, long wait persistence without auto-approval, and state preservation across worker restarts without holding an activity worker.

---

## 5. Acceptance Matrix Verification (W01–W20)

| Case | Status | Test Path(s) | Proof Limit & Outcome |
|---|---|---|---|
| **W01** | Passed | `WorkflowStartTest.php`, `WorkflowStartConcurrencyTest.php`, `test_start_bridge.py` | Exactly one link and Temporal workflow ID created across repeated and concurrent discovery. |
| **W02** | Passed | `WorkflowStartTest.php`, `LiveBridgeTest.php` | Start timeout records uncertain; reconciler describes fixed ID, verifies memo, and adopts run ID. |
| **W03** | Passed | `WorkflowStartTest.php`, `ProgressAccessTest.php` | Bridge/Temporal outage leaves honest `awaiting_workflow` case; delayed flag set after 300s; 24h overdue alert emitted. |
| **W04** | Passed | `EventProjectionTest.php`, `LiveBridgeTest.php` | Worker callback atomically commits sequence 2 event and transitions case from `awaiting_workflow` to `queued`. Replay is no-op. |
| **W05** | Passed | `WorkflowStartTest.php`, `ProgressAccessTest.php` | Inactive teacher defers start without external request; restoration resumes same ID; HTTP access denied while inactive. |
| **W06** | Passed | `ProgressAccessTest.php` | Owning teacher receives ordered milestones and stage/status; foreign/missing cases return identical 404; non-teachers denied. |
| **W07** | Passed | `ProgressAccessTest.php`, `PrivacyTest.php` | Admin lists and inspects exact closed DTO with zero-count defaults; no owner, document, or educational content leaked. |
| **W08** | Passed | `EventProjectionTest.php` | Signed worker event commits case and link atomically; exact replay returns 200; changed payload/stale version/unknown code returns 409/422. |
| **W09** | Passed | `EventConcurrencyTest.php`, `ProgressAccessTest.php` | Concurrent events with same expected version yield one 201 commit and one 409 conflict; sequence-ordered pagination verified. |
| **W10** | Passed | `WorkerBoundaryTest.php`, `PrivacyTest.php` | Worker callback authenticated with dedicated HMAC key; missing/bad/stale signature rejected before model lookup; no cookie bypass. |
| **W11** | Passed | `test_retry_policy.py`, `WorkflowStartTest.php` | 005 captured settings retry fields (`technical_retries + 1`, backoff) passed to Temporal activity retry policy. |
| **W12** | Passed | `test_case_workflow.py`, `test_retry_policy.py`, `LiveBridgeTest.php` | Retryable errors resume within budget; non-retryable validation errors fail safely; offline worker leaves progress delayed. |
| **W13** | Passed | `test_review_gate.py` | `ReviewGate` accepts valid decision, ignores stale versions or candidate mismatches, and treats duplicate signals as no-ops. |
| **W14** | Passed | `HandoffContractTest.php`, `test_review_gate.py` | No public teacher review routes or production `ReviewSignal` models exist in 007; test fixtures prove gate logic only. |
| **W15** | Passed | `test_review_gate.py` | Review gate wait survives worker restart; no dormancy timer automatically approves. |
| **W16** | Passed | `EventProjectionTest.php`, `LiveBridgeTest.php` | Duplicate callback replay deduplicates cleanly; closed Temporal failure reconciles to terminal failure event without restart. |
| **W17** | Passed | `HandoffContractTest.php`, `test_case_workflow.py` | Workflow honestly parks at extraction handoff; no synthetic extraction or prompt activities in production engine. |
| **W18** | Passed | `PrivacyTest.php`, `WorkflowSchemaTest.php`, `HandoffContractTest.php` | Links and events retain minimal operational data; no HTTP DELETE routes exist; no secrets or payloads logged. |
| **W19** | Passed | `WorkflowStartTest.php` | Missing or corrupt captured 005 settings prevents bridge start, leaves case/link pending, and emits `settings_unavailable`. |
| **W20** | Passed | `WorkflowStartTest.php`, `ReconcileWorkflowStart.php` | Verified absent execution after 30-day window marks `history_missing` with alert; Temporal 503 stays uncertain without marking missing. |

---

## 6. Handoff Obligations for Spec 008

Spec 008 (Extraction / Source Activation) must inherit and respect the following boundaries established by Spec 007:
1. **Extraction Parking Handoff:** In `v3_engine/workflows/case_workflow.py`, the line `await workflow.wait_condition(lambda: False)` marks the exact handoff point where Spec 008 must introduce replay-compatible extraction activities and the `source_activation_v1` signal.
2. **Replay Compatibility:** Spec 008's workflow revisions must remain replay-compatible with histories started under Spec 007.
3. **Signal Provenance:** 008 must authorize and persist the activation record in its own database tables before signaling Temporal.
4. **Milestone Events:** 008 must publish stage progress using the established signed callback route (`POST /api/internal/v1/case-events`) with stable event keys, expected state versions, and allowlisted stage/status/error codes.
5. **Private Data Isolation:** 008 extraction workers must access document files via `AcceptedPdfReader` or internal authenticated streams; extracted text must never be returned to administrative metadata endpoints or logged in telemetry.

---

## 7. Final Verification Summary

- **Total V3 PHP Tests:** 199 passed, 1 skipped (live ClamAV binary environmental gate), 0 failed.
- **Total Python Workflow Tests:** 13 passed in `v3_engine/tests/workflow/`, 0 failed.
- **Total Combined Assertions:** > 2,600 assertions across unit, feature, integration, and live harness tests.
- **Spec Directory Status:** Only files belonging to Spec 007 and required application extensions were created or modified. All changes strictly adhere to RFC 2119 requirement language and implementation-agnostic constraints.
