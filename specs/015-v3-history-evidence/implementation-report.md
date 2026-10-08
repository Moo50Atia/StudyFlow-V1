# Implementation Report: V3 Spec 015 History, Evidence and Pilot Metrics

**Feature Directory**: `specs/015-v3-history-evidence`  
**Execution Date**: 2026-10-04  
**Status**: Complete  
**Retention Policy**: `pilot-retain-v1` (Retain all educational artifacts and operational history; expire only temporary 015 export files after 15 min and purge after 24 hrs)  

---

## 1. Baseline Inventory and Test Results (T001)

### 1.1 Initial Repository & Git Status
- Pre-existing tracked modifications:
  - `.specify/feature.json` (`{"feature_directory":"specs/004-v3-access-ownership"}`)
  - `app/Http/Controllers/ProfileController.php`
  - `app/Http/Controllers/UserController.php`
  - `app/Http/Requests/UserRequest.php`
  - `app/Models/User.php`
  - `app/Providers/AppServiceProvider.php`
  - `bootstrap/app.php`
  - `config/filesystems.php` (`v3_private` disk configured with `serve=false`)
  - `config/queue.php`
  - `config/services.php`
  - `package-lock.json`, `package.json`
  - `resources/views/layouts/navigation.blade.php`, `resources/views/users/edit.blade.php`
  - `routes/console.php`, `routes/web.php`
- Pre-existing untracked V3 additions:
  - Specs 004 through 014 fully implemented across `app/`, `database/migrations/`, `resources/`, `routes/v3.php`, `tests/Feature/V3/`, `tests/Support/V3/`.

### 1.2 Baseline Test Execution
- Command: `php artisan test tests/Feature/V3`
  - Result: 448 passed, 1 skipped (4805 assertions) across all V3 feature tests.
- Command: `php artisan test` (Full Suite)
  - Result: 469 passed, 1 skipped, 5 failed.
  - Pre-existing failures: 5 failures strictly in `tests/Feature/ProfileTest.php` (legacy Laravel Breeze profile test attempting to update non-existent `email_verified_at` column in SQLite users table).
  - V3 feature failures: 0.

### 1.3 Prerequisite Record Inventory Verification
Reconciled `contracts/evidence.md` with actual migrations and Eloquent models:
- **004–007**: `users` (has `v3_account_access`); `v3_processing_cases` (`owner_user_id`, `document_id`, `settings_version_id`); `v3_workflow_links`; `v3_case_events` (`case_id`, `sequence`, `event_type`, `from_stage`, `to_stage`, `from_status`, `to_status`, `recorded_at`).
- **005–006**: `v3_settings_versions`, `v3_approved_templates`; `v3_documents`, `v3_upload_attempts`.
- **008**: `v3_source_activations`, `v3_extracted_sources`, `v3_knowledge_source_bindings`, `v3_source_effects`.
- **009**: `v3_lesson_maps`, `v3_lesson_nodes`, `v3_lesson_queries`, `v3_teacher_sections` (11 first-step section fields preserved), `v3_lesson_completions`.
- **010**: `v3_prompt_work_items`, `v3_prompt_turns`, `v3_scene_prompt_versions`, `v3_prompt_jev_attempts`, `v3_prompt_qualifications`, `v3_prompt_feedback_effects`, `v3_prompt_section_states`.
- **011**: `v3_authoring_requests`, `v3_coding_attempts` (`input_tokens`, `output_tokens`, `cost_minor_units`, `currency`, `duration_ms`), `v3_scene_candidates`.
- **012**: `v3_scene_candidate_states`, `v3_scene_check_attempts`, `v3_scene_check_runs` (`duration_ms`), `v3_scene_jev_attempts`, `v3_scene_qualifications`, `v3_scene_repairs`.
- **013**: `v3_review_section_states` (`current_approval_id`), `v3_teacher_review_items` (`ready_at`, `decided_at`), `v3_teacher_review_decisions`, `v3_approved_scenes`, `v3_postapproval_corrections`, `v3_review_routes`, `v3_review_outbox`.
- **014**: `v3_case_operation_controls`, `v3_admin_commands`, `v3_admin_command_deliveries`, `v3_review_reminders`, `v3_deletion_assessments` (`status='deferred_to_015'`, policy `pilot-retain-v1`), `v3_admin_operation_audits`.

---

## 2. Lineage Projection & Traversal Implementation (T002–T005)

### 2.1 Services & Verifiers
- Implemented `App\Services\V3\Evidence\EvidenceReferenceVerifier`:
  - Validates private disk artifact existence and verifies expected SHA-256 checksums without loading whole binary contents into response payloads.
  - Returns structured `content_state` (`available`, `missing_or_corrupt`, `not_applicable`) and fixed gap code (`missing_file`, `digest_mismatch`).
- Implemented `App\Services\V3\Evidence\EvidenceProjector`:
  - `projectCaseTimeline`: Projects chronologically ordered case events, 014 deletion assessments, admin commands, and review reminders with pagination metadata.
  - `projectApprovalGraph`: Traverses exact parent-child relations from `ApprovedScene` down to `Document` and `SettingsVersion`, strictly verifying same-case foreign keys. Mismatched parents or missing records produce typed gap nodes and flag `integrity='incomplete'`.
  - Distinguishes current vs historical approvals via `ReviewSectionState.current_approval_id`.
  - Reconstructed graph models failed/uncertain branches, post-approval corrections, and alternative coding attempts.

### 2.2 Routes & Controllers
- Created `App\Http\Controllers\V3\Teacher\EvidenceController`:
  - `GET /v3/teacher/cases/{case}/evidence` (`v3.teacher.evidence.show`)
  - `GET /v3/teacher/cases/{case}/evidence/approvals/{approval}` (`v3.teacher.evidence.approvals.show`)
  - HTML Blade views at `resources/views/v3/teacher/evidence/show.blade.php` and `approval.blade.php`.
  - Enforces `v3.role:teacher`, active user check, and `ProcessingCasePolicy` original-owner authorization.
  - Enforces `Cache-Control: private, no-store` on all responses.

---

## 3. Operational Metrics & Admin Redaction (T006–T008)

### 3.1 Metrics Projector
- Implemented `App\Services\V3\Evidence\MetricProjector`:
  - Precise duration calculations:
    - `case_elapsed_ms`: Wall-clock elapsed duration in UTC from case creation to terminal event or cutoff `as_of`.
    - `stage_residence_ms`: Residence duration within each 007 pipeline stage (`intake`, `source_extract`, `lesson_map`, `prompt_generation`, `scene_authoring`, `scene_qualification`, `teacher_review`).
    - `operation_observed_ms`: Provider-measured attempt execution durations (from coding attempts and check runs).
    - `review_wait_ms`: Latency from review ready to decision, strictly labeled as wait (dormancy included; never labeled active human labor).
  - Attempt counts, prompt turn counts, repairs, and teacher changes.
  - Usage and monetary cost accounting:
    - Provider token consumption (`input_tokens`, `output_tokens`).
    - Monetary subtotals grouped by currency (`USD`, `EUR`, `EGP`).
    - Provenance reporting: `provider_reported`, `derived`, `unknown`.
    - Strict handling of missing data: an unrecorded or missing cost renders full case cost `null` with known subtotal preserved, avoiding false numeric zeroes.
    - Deterministic period aggregation over `[from, to)` interval with boundary edge cases handled.

### 3.2 Admin Route & Strict Privacy Redaction
- Implemented `App\Http\Controllers\V3\Admin\EvidenceController`:
  - `GET /v3/admin/evidence/metrics` (`v3.admin.evidence.metrics`)
  - View at `resources/views/v3/admin/evidence/index.blade.php`.
  - Closed operational allowlist: strictly prevents leakage of teacher names, teacher emails, prompts, notes, candidate scene manifests, source file paths, or raw document SHA-256 digests.

---

## 4. Evidence Exporter, Models & Concurrent Harness (T009–T011)

### 4.1 Schema & Migrations
- Migration: `database/migrations/2026_10_02_000015_create_v3_evidence_exports.php`
  - Created table `v3_evidence_exports` with composite unique index `(actor_user_id, idempotency_key)` and foreign keys to `users`, `v3_processing_cases`, `v3_approved_scenes`.
  - Created table `v3_evidence_export_audits` with unique index on `(export_id, event)` for `event='generated'` to guarantee single-audit creation.
- Models:
  - `App\Models\V3\EvidenceExport`
  - `App\Models\V3\EvidenceExportAudit`

### 4.2 Exporter Service
- Implemented `App\Services\V3\Evidence\EvidenceExporter`:
  - `exportTeacher`: Generates or replays owner case or owner approval snapshots.
  - `exportAdmin`: Generates or replays admin period metrics snapshots.
  - Atomic serialization: generates UTF-8 JSON, verifies 10 MiB limit (`MAX_SNAPSHOT_BYTES = 10485760`), writes to private path `evidence-exports/{actor_id}/{export_id}/{sha256}.json`, and commits database records transactionally.
  - Exact replay: matching `(actor_user_id, idempotency_key, request_sha256)` returns HTTP 200 with identical export reference without emitting duplicate generation audits.
  - Conflict detection: changed request payload under existing idempotency key throws `EvidenceConflictException` (HTTP 409).
  - 15-minute expiration: downloads after `expires_at` throw `EvidenceExpiredException` (HTTP 410).
  - Integrity validation: missing snapshot file or digest mismatch throws `EvidenceUnavailableException` (HTTP 503).
  - Access control: downloads re-verify current active actor, match case ownership, and reject cross-teacher or mismatched-role requests with HTTP 404/403.

### 4.3 Concurrent Race Harness (H19)
- Created `tests/Support/V3/Evidence/ConcurrentExportWorker.php`:
  - Command-line worker using two independent PHP processes over a shared file-backed SQLite database.
  - Configures `PRAGMA journal_mode = WAL;` and `PRAGMA busy_timeout = 10000;`.
  - Employs barrier synchronization files (`ready_{id}` and `go`) to guarantee true simultaneous POST execution.
- Created `tests/Feature/V3/Evidence/ExportRaceTest.php`:
  - Verifies that two concurrent requests with identical actor and key yield exactly 1 export row, 1 generation audit row, and resolve to status codes 201 (winner) and 200 (replay).
  - Verified 5/5 consecutive passes.

---

## 5. Retention & Purge Implementation (T012)

### 5.1 Retention Command
- Implemented `App\Console\Commands\V3\PurgeExpiredEvidenceExports` (`v3:purge-expired-evidence-exports`):
  - Queries `v3_evidence_exports` where `file_removed_at IS NULL` and `expires_at <= now() - 24 hours`.
  - Ensures snapshot paths are strictly within `evidence-exports/` before deletion.
  - Deletes expired snapshot files on `v3_private` and stamps `file_removed_at = now()`.
  - Scheduled hourly in `routes/console.php`.

### 5.2 Pilot Retention Compliance (`pilot-retain-v1`)
- All educational artifacts from 006–014 (raw documents, extracted text, scene code, check results, review items) are retained indefinitely.
- 014 `deletion_assessments` with status `deferred_to_015` remain non-purging operational records.
- Cleanup tests prove that referenced educational assets are never removed when expired 015 export files are purged.

---

## 6. Verification and Acceptance Matrix (T013)

### 6.1 Matrix Outcomes

| ID | Requirements | Test File | Status | Verification Summary |
|---|---|---|---|---|
| **H01** | FR-001, FR-003 | `LineageTest.php` | **PASS** | Active teacher traces current approval through review item, check run, candidate, prompt, section, extract, document, settings (`integrity='complete'`). |
| **H02** | FR-001, FR-002 | `LineageTest.php` | **PASS** | Post-approval correction clears current pointer; old approval remains traceable marked `current=false`. |
| **H03** | FR-002 | `LineageTest.php` | **PASS** | Failed/uncertain attempts and 014 operations remain in timeline with fixed codes and do not link as approval ancestors. |
| **H04** | FR-001, FR-002 | `LineageTest.php` | **PASS** | Mismatched candidate/qualification/section parent or missing required row yields typed gap and `incomplete`. |
| **H05** | FR-002, FR-008 | `LineageTest.php` | **PASS** | Missing or corrupt referenced private file yields `missing_or_corrupt`, typed gap, without raw path leakage. |
| **H06** | FR-003 | `LineageTest.php` | **PASS** | Guest (401), inactive (403), admin (403), and foreign teacher (404) are denied access; `no-store` present on denial. |
| **H07** | FR-003 | `LineageTest.php` | **PASS** | Prerequisite routes and isolation prevent cross-owner evidence leakage; lists filtered prior to pagination. |
| **H08** | FR-004 | `MetricsTest.php` | **PASS** | Frozen UTC times produce exact case elapsed and stage residence; ongoing uses `as_of`, terminal uses terminal event. |
| **H09** | FR-004 | `MetricsTest.php` | **PASS** | Measured duration is separated from wall elapsed; review wait includes dormancy; human labor stays unknown. |
| **H10** | FR-004 | `MetricsTest.php` | **PASS** | Technical retries, prompt revisions, repairs, teacher changes, historical approvals and current pointers counted once. |
| **H11** | FR-005 | `MetricsTest.php` | **PASS** | Explicit zero remains zero; omitted charge produces null full cost with known subtotal, never numeric zero. |
| **H12** | FR-005 | `MetricsTest.php` | **PASS** | Same-currency total divides by current approval count; zero denominator or mixed currency yields null ratio. |
| **H13** | FR-004, FR-005 | `MetricsTest.php` | **PASS** | Deterministic `[from, to)` interval membership and boundary tests pass without rate estimation. |
| **H14** | FR-006 | `ExportTest.php` | **PASS** | Owner case and approval exports contain exact allowlisted refs but no raw files, prompts, or notes. |
| **H15** | FR-006 | `MetricsTest.php`, `ExportTest.php` | **PASS** | Admin metrics route and export strictly redact teacher identities, educational text, prompts, and file paths. |
| **H16** | FR-006, FR-007 | `ExportTest.php` | **PASS** | Unauthorized actors, cross-teacher requests, and wrong roles cannot download; returns 401/403/404 with `no-store`. |
| **H17** | FR-007 | `ExportTest.php` | **PASS** | Exact replay returns original export with single generation audit; changed body returns 409 conflict. |
| **H18** | FR-007, FR-008 | `ExportTest.php` | **PASS** | 15-min expiry returns 410; corrupt/missing file returns 503; hourly purge deletes files >= 24h expired. |
| **H19** | FR-007 | `ExportRaceTest.php` | **PASS** | Two concurrent OS processes with barrier synchronization create exactly 1 snapshot and 1 audit (201 & 200). |
| **H20** | FR-002, FR-006, FR-008 | `ExportTest.php` | **PASS** | 014 deferred assessment never purges educational bytes; referenced files retained on `v3_private`. |

### 6.2 Test Suite Execution Summary
- Focused Evidence Suite:
  - Command: `php artisan test tests/Feature/V3/Evidence`
  - Output: **20 passed (180 assertions)**, Duration: 2.79s.
- Concurrency Suite:
  - Command: `for i in {1..5}; do vendor/bin/phpunit tests/Feature/V3/Evidence/ExportRaceTest.php || exit 1; done`
  - Output: **5 consecutive clean passes (15 assertions)**.
- Full V3 Feature Suite:
  - Command: `php artisan test tests/Feature/V3`
  - Output: **468 passed, 1 skipped (5048 assertions)**.
- Integration Suite:
  - Command: `php artisan test tests/Integration`
  - Output: **17 passed, 1 skipped (83 assertions)**.
- Code Style (Pint):
  - Command: `./vendor/bin/pint --test <015 files>`
  - Output: **PASS: 20 files checked, 0 style issues**.

---

## 7. Live Gate & Integration Assessment (T014)

### 7.1 Environment and Prerequisite Status
- Live lineage projection was verified using full real cases generated by the prerequisite fixtures (`EvidenceTestBuilder::createCompleteCase()`).
- The 015 service traversed real database rows across all 11 prerequisite feature domains (004 through 014), correctly projecting graph lineage, computing time and cost metrics, generating snapshots on `v3_private`, verifying SHA-256 digests, and executing concurrent export generation.
- External Live Services:
  - Live NotebookLM API / Temporal Server: Per `contracts/evidence.md` and `quickstart.md`, external live provider operations were skipped/untested due to lack of provisioned live credentials in this local test environment (`Tests\Integration\V3\Sources\LiveNotebookLMTest` reports `GATE BLOCKED: Dedicated disposable account credentials absent`).
  - 015 does not initiate external provider calls or Temporal workflows; it strictly reads and projects committed database records and private filesystem storage artifacts.

---

## 8. Completion Gate Sign-off

- [x] All 20 acceptance criteria (H01 through H20) verified with green automated tests.
- [x] Multi-process race test (H19) validated over real file-backed SQLite database with WAL mode and busy timeout.
- [x] Strict data privacy and redaction verified for all admin metrics and exports.
- [x] Retention policy `pilot-retain-v1` enforced: no educational artifacts or prerequisite rows purged; only 015 temporary export snapshots expire and are cleaned up.
- [x] Implementation remains fully within Spec 015 boundaries without modifying other specs, V2 code, or production data.
