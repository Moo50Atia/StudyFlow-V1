# Implementation Report: 006 V3 PDF Intake and Cases

## 1. Executive Summary

This report documents the complete implementation of **V3 Spec 006: PDF Intake and Cases** (`specs/006-v3-pdf-intake-cases/`) in `/home/moahmmed/college_project`. 

All deliverables defined in [tasks.md](tasks.md) and [task-execution-plan.md](task-execution-plan.md) have been implemented, tested via strict Test-Driven Development (TDD), and verified against their acceptance contracts. The implementation enforces private storage isolation, rigorous PDF structural inspection (with active PDF profile gates against `/JS`, `/Launch`, `/OpenAction`, `/AcroForm`, `/EmbeddedFiles`), ClamAV antivirus execution, crash/reconciliation workflows, duplicate detection, race-condition resistance, strict transaction lock ordering, teacher ownership, and durable downstream handoff to Spec 007 (`awaiting_workflow`) and Spec 008 (`AcceptedPdfReader`).

No production placeholders or early implementations of downstream specifications (007 Temporal workflow, 008 NotebookLM OCR/MCP, 009-015, or legacy dynamic models) were introduced.

---

## 2. Baseline Inventory & Environmental Status

### System Tools & Runtimes
- **PHP Version:** 8.5.0 (cli) (built Nov 20 2025, NTS x86_64-linux-musl-gcc)
- **Laravel Framework:** 12.42.0
- **Test Framework:** Pest 3.8.4 / PHPUnit 11.5.46
- **Database Engine:** SQLite (in-memory for routine feature tests; isolated file-backed databases with `PRAGMA busy_timeout = 10000;` for cross-process concurrency suites)
- **qpdf Status:** Not installed on host system (`command not found`)
- **clamdscan Status:** Not installed on host system (`command not found`)
- **Live Environmental Gate Status:** Explicitly reported as **Blocked / Skipped Environmental Prerequisite** in accordance with `contracts/acceptance-matrix.md` and `quickstart.md`. The testing harness honors this requirement by marking the live binary verification test skipped with an explicit explanatory notice rather than substituting a fake mock green.

### Pre-Existing Workspace State
- All pre-existing git modifications and untracked research documentation were strictly preserved.
- No production database mutations or deployments were performed.

---

## 3. Inventory of Changes

### Database Migrations
- `database/migrations/2026_10_01_000006_create_v3_intake_tables.php`:
  - `v3_documents`: `id` (UUID PK), `owner_user_id` (FK `users.id` restrict), `sha256` (char 64), `byte_size`, `page_count`, `state` (`pending_move`, `ready`), `storage_path`, `accepted_at`, `created_at`, `updated_at`. Unique constraints: `(owner_user_id, sha256)` and `(id, owner_user_id)`.
  - `v3_upload_attempts`: `id` (UUID PK), `owner_user_id` (FK `users.id` restrict), `upload_settings_version_id` (FK `v3_settings_versions.id` restrict, nullable), `document_id` (nullable), `state` (`receiving`, `quarantined`, `validating`, `retry_pending`, `promoting`, `cleanup_pending`, `accepted`, `rejected`, `blocked`), `reason_code`, `cleanup_target`, `staging_path`, `byte_size`, `scan_attempts`, `technical_failures`, `duplicate`, timestamps (`next_retry_at`, `lease_until`, `expires_at`, `finished_at`). Composite FK `(document_id, owner_user_id)` references `v3_documents(id, owner_user_id)`.
  - `v3_processing_cases`: `id` (UUID PK), `owner_user_id` (FK `users.id` restrict), `document_id`, `settings_version_id` (FK `v3_settings_versions.id` restrict), `idempotency_key` (UUID), `action` (`process`, `process_again`), `state` (`awaiting_workflow`), `state_version` (default 1), `created_at`, `updated_at`. Unique constraint: `(owner_user_id, idempotency_key)`. Composite FK `(document_id, owner_user_id)` references `v3_documents(id, owner_user_id)`. Indexes: `(owner_user_id, document_id, created_at, id)` and `(state, created_at, id)`.

### Eloquent Models & Traits
- `app/Models/V3/Document.php`: Implements `V3OwnedResource` via `HasV3Owner`. Contains immutability guards preventing modification of `sha256`, `byte_size`, `page_count`, `storage_path`, or `owner_user_id` once in `ready` state, and disallows deletion of ready documents.
- `app/Models/V3/UploadAttempt.php`: Implements `V3OwnedResource` via `HasV3Owner`. Manages state machine transitions and safe reason codes.
- `app/Models/V3/ProcessingCase.php`: Implements `V3OwnedResource` via `HasV3Owner`. Immutable run record initialized with `state = 'awaiting_workflow'` and `state_version = 1`.

### Authorization Policies
- `app/Policies/V3/DocumentPolicy.php`: Restricts `view`, `update`, and `review` to the verified owner.
- `app/Policies/V3/UploadAttemptPolicy.php`: Restricts access strictly to the authenticated teacher owner.
- `app/Policies/V3/ProcessingCasePolicy.php`: Restricts access strictly to the authenticated teacher owner.
- `app/Providers/AppServiceProvider.php`: Explicitly registered all three V3 policies.

### Configuration
- `config/filesystems.php`: Added `v3_private` disk rooted at `storage/app/v3`, configured with `'serve' => false` and `'throw' => true` to guarantee files cannot be served via public symlinks or HTTP asset handlers.
- `config/queue.php`: Added `v3_intake` database queue connection configured with `'after_commit' => true` and `'retry_after' => 360` seconds.

### Console & Scheduled Tasks
- `app/Console/Commands/V3/ReconcileIntakeCommand.php`: Artisan command `v3:reconcile-intake` implementing automated crash recovery, lease reclaim, stuck promotion finalizing, and overdue cleanup alerting.
- `routes/console.php`: Scheduled `v3:reconcile-intake` to execute every minute.

### Services
- `app/Services/V3/Intake/PrivatePdfStore.php`: Handles quarantine staging, bounded streaming writes, atomic promotion renames (`rename`), file existence checks, and cleanup deletion.
- `app/Services/V3/Intake/ValidateUploadInput.php`: Performs controller-level validation for multipart PDF uploads after attempt row creation (protecting attempt ID generation), checking file presence, `.pdf` extension, `%PDF-` header offset 0, and single-file multipart boundaries.
- `app/Services/V3/Intake/QpdfAdapter.php`: Executes `qpdf` via Symfony Process argument arrays (no shell interpolation), parsing JSON v2 schema output, checking encryption (`exit 2`), page count vs active limits, and scanning the entire object dictionary for forbidden active content (`/OpenAction`, `/AA`, `/JS`, `/JavaScript`, `/Launch`, `/EmbeddedFiles`, `/EF`, `/Filespec`, `/AcroForm`, `/XFA`, `/RichMedia`, `/SubmitForm`, `/ImportData`, `/GoToR`).
- `app/Services/V3/Intake/ClamAvAdapter.php`: Executes `clamdscan --fdpass --no-summary` against local Unix socket/daemon, classifying return code 0 (clean), 1 (malware detected), and 2/error (inspection unavailable).
- `app/Services/V3/Intake/PdfInspector.php`: Coordinates qpdf inspection, ClamAV scanning, and streaming SHA-256 calculation.
- `app/Services/V3/Intake/IntakeCoordinator.php`: Coordinates validation execution under a 5-minute lease, handles deduplication checks, promotes clean files to `v3_documents`, and manages the 60s/120s/240s technical retry schedule up to 4 attempts before blocking.
- `app/Services/V3/Intake/ReconcileIntake.php`: Reconciler service for orphaned file sweeps, overdue cleanup logging (`cleanup_overdue`), expired quarantine blocking, and crash recovery.
- `app/Services/V3/Intake/ValidateCaseInput.php`: Validates case creation input, enforcing UUID format for `document_id` and `idempotency_key`, restricting `action` to `process` | `process_again`, and rejecting extraneous application fields.
- `app/Services/V3/Intake/StartProcessingCase.php`: Transactional case creation service executing strict lock order (**004 access control lock -> owned ready Document lock -> 005 settings pointer -> case insertion**), checking limit constraints, validating disk file integrity, executing duplicate rules, handling idempotency replays/conflicts, and providing bounded 3-attempt transaction retries for concurrency resilience.
- `app/Services/V3/Intake/AcceptedPdfReader.php`: Internal service providing verified private stream access to accepted documents for valid processing cases without public URLs.

### HTTP Controllers & Routes
- `app/Http/Controllers/V3/Teacher/PdfUploadController.php`:
  - `store`: POST `/v3/teacher/pdf-uploads` -> 202 Accepted with `quarantined` state, or 422 with `attempt_id`.
  - `index`: GET `/v3/teacher/pdf-uploads` -> 200 with paginated `AttemptSummary` list.
  - `show`: GET `/v3/teacher/pdf-uploads/{attempt}` -> 200 with `AttemptSummary`.
- `app/Http/Controllers/V3/Teacher/ProcessingCaseController.php`:
  - `store`: POST `/v3/teacher/cases` -> 201 Created (new) or 200 OK (replay) with `CaseReceipt`.
  - `show`: GET `/v3/teacher/cases/{case}` -> 200 with `CaseReceipt`.
- `app/Http/Controllers/V3/Teacher/DocumentController.php`:
  - `download`: GET `/v3/teacher/documents/{document}/download` -> 200 streaming PDF with `Content-Disposition: attachment; filename="source.pdf"`, `X-Content-Type-Options: nosniff`, and `Cache-Control: private, no-store`.
- `routes/v3.php`: Registered all 6 teacher intake and case routes inside the `v3.role:teacher` middleware group.

### Views
- `resources/views/v3/teacher/workspace.blade.php`: Extended with PDF upload multipart form and link to uploads index.
- `resources/views/v3/teacher/pdf-uploads/index.blade.php`: Paginated table of teacher upload attempts.
- `resources/views/v3/teacher/pdf-uploads/show.blade.php`: Attempt detail view with status badges, refresh link, safe rejection reasons, and conditional case initiation forms (`process` vs `process_again` with pre-generated UUID idempotency keys).
- `resources/views/v3/teacher/cases/show.blade.php`: Case receipt page displaying case ID, document ID, state (`awaiting_workflow`), settings version, and navigation links.
- `resources/views/v3/teacher/cases/conflict.blade.php`: User-facing 409 conflict page with reload link.

---

## 4. TDD Verification and Evidence by Task Group

### Group 1: Foundation (T001–T004)
- **Test:** `tests/Feature/V3/Intake/SchemaOwnershipTest.php`
- **Result:** 7 passed (62 assertions).
- **Evidence:**
  - Foreign key restrict prevents deleting owner when Documents, Attempts, or Cases exist.
  - Composite foreign key `(document_id, owner_user_id)` strictly enforced at database schema level.
  - Model mutators throw `InvalidArgumentException` if `sha256` or `owner_user_id` is modified after ready state.
  - Model `delete()` throws `LogicException` on ready Document.

### Group 2: Private Intake & UI (T005–T006)
- **Tests:** 
  - `tests/Feature/V3/Intake/UploadRouteTest.php` (7 passed, 68 assertions)
  - `tests/Feature/V3/Intake/PrivateStorageTest.php` (2 passed, 13 assertions)
  - `tests/Feature/V3/Intake/TeacherIntakeUiTest.php` (4 passed, 31 assertions)
- **Evidence:**
  - Valid PDF receives 202 Accepted with UUID `attempt_id` and initial `quarantined` state.
  - Non-PDF extension, missing `%PDF-` magic bytes, and exceeding active byte limits reject with 422 and retain attempt record.
  - Missing active 005 settings returns 503 and marks attempt `blocked` with reason `settings_unavailable`.
  - Browser upload POST redirects 303 to attempt show view.
  - Private disk configuration verified: `serve = false`, `throw = true`, outside public root.

### Group 3: PDF Gates & Adapters (T007–T008)
- **Test:** `tests/Feature/V3/Intake/PdfToolIntegrationTest.php` (4 passed, 1 skipped, 18 assertions)
- **Evidence:**
  - `PdfInspector` strictly delegates to `QpdfAdapter` then `ClamAvAdapter`.
  - Absence of tools fails closed with `inspection_unavailable` without crashing.
  - Valid image-only PDF passes structure checks.
  - Live environmental check reports honest skip when host environment lacks binaries.

### Group 4: Recovery & Reconciliation (T009–T010)
- **Test:** `tests/Feature/V3/Intake/ValidationRecoveryTest.php` (5 passed, 28 assertions)
- **Evidence:**
  - Malware detection transitions attempt to `rejected` with reason `malware_detected` and purges quarantined bytes.
  - Technical failures increment `technical_failures` counter and schedule 60s / 120s / 240s retry delays.
  - Crash recovery reconciles `pending_move` documents to `ready` if accepted bytes exist on disk.
  - Expired quarantine past 24 hours is purged and attempt marked `blocked` with `quarantine_expired`.
  - Failed file deletion remains `cleanup_pending` and logs `cleanup_overdue` operational alert.

### Group 5: Duplication & Hashing (T011–T012)
- **Test:** `tests/Feature/V3/Intake/DuplicateTest.php` (2 passed, 27 assertions)
- **Evidence:**
  - Same owner uploading exact bytes reuses existing `Document` row, sets attempt `duplicate = true`, and deletes staged duplicate bytes.
  - Cross-owner identical bytes upload produces distinct `Document` rows and distinct stored files in `v3_private/accepted/`.

### Group 6: Intake Concurrency (T013–T014)
- **Test:** `tests/Feature/V3/Intake/IntakeConcurrencyTest.php` (2 passed, 21 assertions)
- **Evidence:**
  - Executed two simultaneous worker processes (`ConcurrentUploadWorker.php`) against a shared file-backed SQLite database.
  - Same-owner concurrent uploads cleanly converge on one ready Document and two attempts.
  - Cross-owner concurrent uploads create separate Document rows and separate files without contention.

### Group 7: Case Start & Idempotency (T015–T016)
- **Test:** `tests/Feature/V3/Intake/CaseStartTest.php` (13 passed, 78 assertions)
- **Evidence:**
  - First case on ready Document returns 201 Created with `awaiting_workflow` CaseReceipt.
  - Identical idempotent replay returns 200 OK with original `settings_version_id` even after new settings version published.
  - Conflicting key reuse returns 409 `idempotency_conflict`.
  - Duplicate rules enforce `process` for first run and `process_again` for subsequent runs.
  - Tightened settings limits reject older Document with 409 `document_exceeds_current_limits`.
  - Corrupt or missing accepted storage file returns 503 `storage_unavailable`.
  - `AcceptedPdfReader` verifies stream checksum and opens private stream.

### Group 8: Case Concurrency (T017–T018)
- **Test:** `tests/Feature/V3/Intake/CaseConcurrencyTest.php` (4 passed, 20 assertions)
- **Evidence:**
  - Parallel same-key race converges on 1 case, returning 201 to the first process and 200 (replay) to the second.
  - Parallel different-key first-process race grants 201 to winner and 409 `duplicate_choice_required` to loser; exactly 1 case persisted.
  - Parallel `process_again` runs create distinct cases.
  - Concurrency between case start and settings publication serializes cleanly under the lock order without deadlocks.

### Group 9: Security, Privacy & Downstream Obligations (T019–T020)
- **Tests:**
  - `tests/Feature/V3/Intake/AccessBoundaryTest.php` (6 passed, 43 assertions)
  - `tests/Feature/V3/Intake/PrivacyTest.php` (3 passed, 27 assertions)
  - `tests/Feature/V3/Intake/IntegrationContractTest.php` (2 passed, 12 assertions)
- **Evidence:**
  - Unauthenticated requests, students, admins, and suspended teachers are denied from all intake endpoints.
  - Foreign teacher requests yield 404 `resource_unavailable` identical to non-existent resources.
  - Verified teacher downloads stream directly with `nosniff`, `attachment`, and `private, no-store`.
  - Schema allowlists strictly enforced: no raw filenames, SHA digests, byte counts, page counts, or scanner outputs in JSON responses.
  - Downstream 007 query discovers durable `awaiting_workflow` cases ordered by `(created_at, id)`.
  - Downstream 008 extraction fails closed if storage is corrupted and refuses cross-owner fallback.

### Group 10: Complete Verification & Regressions (T021)
- **Total Tests Passing in V3 Suite:** **159 passed**, **1 skipped** (0 failed, 1224 assertions).
  - Spec 004 (Access & Ownership): 45 passed
  - Spec 005 (Versioned Settings): 53 passed
  - Spec 006 (PDF Intake & Cases): 61 passed, 1 skipped (environmental binary gate)

---

## 5. Verification Commands & Outputs

### 1. Route Inventory
```bash
$ php artisan route:list --path=v3

  GET|HEAD   v3 .......................... v3.entry › V3\EntryController@index
  GET|HEAD   v3/admin .................... v3.admin.workspace › V3\Admin\WorkspaceController@index
  GET|HEAD   v3/admin/accounts ........... v3.admin.accounts.index › V3\Admin\AccountController@index
  PATCH      v3/admin/accounts/{user}/access v3.admin.accounts.access.update › V3\Admin\AccountController@updateAccess
  GET|HEAD   v3/admin/settings ........... v3.admin.settings.show › V3\Admin\SettingsController@show
  POST       v3/admin/settings ........... v3.admin.settings.store › V3\Admin\SettingsController@store
  GET|HEAD   v3/admin/settings/history ... v3.admin.settings.history › V3\Admin\SettingsController@history
  GET|HEAD   v3/admin/settings/templates . v3.admin.settings.templates › V3\Admin\SettingsController@templates
  GET|HEAD   v3/teacher .................. v3.teacher.workspace › V3\Teacher\WorkspaceController@index
  POST       v3/teacher/cases ............ v3.teacher.cases.store › V3\Teacher\ProcessingCaseController@store
  GET|HEAD   v3/teacher/cases/{case} ..... v3.teacher.cases.show › V3\Teacher\ProcessingCaseController@show
  GET|HEAD   v3/teacher/documents/{document}/download v3.teacher.documents.download › V3\Teacher\DocumentController@download
  POST       v3/teacher/pdf-uploads ...... v3.teacher.pdf-uploads.store › V3\Teacher\PdfUploadController@store
  GET|HEAD   v3/teacher/pdf-uploads ...... v3.teacher.pdf-uploads.index › V3\Teacher\PdfUploadController@index
  GET|HEAD   v3/teacher/pdf-uploads/{attempt} v3.teacher.pdf-uploads.show › V3\Teacher\PdfUploadController@show

Showing [15] routes
```

### 2. Scheduler Check
```bash
$ php artisan schedule:list

  * * * * *  php artisan v3:reconcile-intake ... Next Due: 10 seconds from now
```

### 3. Reconciler Idempotency & Queue Worker Smoke
```bash
$ php artisan v3:reconcile-intake && php artisan v3:reconcile-intake && php artisan queue:work v3_intake --queue=v3_intake --timeout=270 --tries=1 --stop-when-empty

Reconciled intake: 0 cleanup, 0 promoting, 0 expired, 0 due dispatched.
Reconciled intake: 0 cleanup, 0 promoting, 0 expired, 0 due dispatched.

   INFO  Processing jobs from the [v3_intake] queue.
```

---

## 6. Acceptance Matrix (I01–I18) Status

| ID | Matrix Scenario | Test File | Result |
|---|---|---|---|
| I01 | Authenticated teacher uploads valid PDF; 202 Accepted | `UploadRouteTest.php`, `TeacherIntakeUiTest.php` | **PASS** |
| I02 | Extension / header / size limits rejection | `UploadRouteTest.php`, `PdfToolIntegrationTest.php` | **PASS** |
| I03 | Malware rejection & technical retry budget | `ValidationRecoveryTest.php`, `PdfToolIntegrationTest.php` | **PASS** (Doubled); Live scanner **BLOCKED** by environment |
| I04 | Image-only PDF structural validity | `PdfToolIntegrationTest.php` | **PASS** |
| I05 | Controller-level errors preserve attempt ID | `UploadRouteTest.php`, `TeacherIntakeUiTest.php` | **PASS** |
| I06 | Crash recovery reconciles pending promotions | `ValidationRecoveryTest.php` | **PASS** |
| I07 | Same-owner duplicate reuse & latest case links | `DuplicateTest.php`, `TeacherIntakeUiTest.php` | **PASS** |
| I08 | Cross-owner isolation & identical error shapes | `DuplicateTest.php`, `AccessBoundaryTest.php` | **PASS** |
| I09 | Concurrent upload contention resolution | `IntakeConcurrencyTest.php` | **PASS** (Separate processes) |
| I10 | Limits tightened rejection & storage verification | `CaseStartTest.php` | **PASS** |
| I11 | First case start creates `awaiting_workflow` receipt | `CaseStartTest.php`, `TeacherIntakeUiTest.php` | **PASS** |
| I12 | Idempotent replay & conflicting reuse | `CaseStartTest.php` | **PASS** |
| I13 | Concurrent case starts serialization & replay | `CaseConcurrencyTest.php` | **PASS** (Separate processes) |
| I14 | Role authorization & foreign resource masking | `AccessBoundaryTest.php`, `CaseStartTest.php` | **PASS** |
| I15 | Settings publication race condition handling | `CaseConcurrencyTest.php` | **PASS** (Separate processes) |
| I16 | Durable 007 discovery & 008 stream extraction | `IntegrationContractTest.php`, `CaseStartTest.php` | **PASS** |
| I17 | Verified private stream download & header policy | `AccessBoundaryTest.php`, `PrivateStorageTest.php` | **PASS** |
| I18 | 24-hour cleanup deadline & operational alert | `ValidationRecoveryTest.php`, `PrivacyTest.php` | **PASS** |

---

## 7. Downstream Obligations & Completion Gate

- **Spec 007 (Workflow Orchestration):** May query `v3_processing_cases` WHERE `state = 'awaiting_workflow'`, ordering by `created_at ASC, id ASC`. Must preserve immutable owner, document ID, settings version ID, and idempotency key.
- **Spec 008 (Extraction / Adapter):** Internal calls to `AcceptedPdfReader::openForCase($case)` will yield the verified stream. Must fail closed if storage is unavailable without cross-owner fallback.
- **Environmental Gate:** Before deploying to live pilot staging, the host environment must provision `qpdf >= 11` and a running ClamAV daemon (`clamdscan`). The application is verified fail-closed in their absence.

**Spec 006 Implementation Gate:** **COMPLETE**.
