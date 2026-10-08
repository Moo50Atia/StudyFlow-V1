# Implementation Report: V3 Spec 005 Versioned Settings

**Date**: 2026-10-02  
**Spec**: `specs/005-v3-versioned-settings/`  
**Prerequisite**: Spec 004 Access and Ownership (verified implemented and passing)  
**Status**: Complete (All 005 mandatory tests passing; live operator template prerequisite documented)

---

## 1. Starting Workspace & Environment (T001)

### Environment State
- **PHP Version**: `PHP 8.5.0 (cli)`
- **Composer Version**: `Composer 2.8.12`
- **Database Driver**: SQLite (`:memory:` for feature tests; isolated temporary SQLite file for concurrency integration tests)
- **Feature Selector**: `.specify/feature.json` has original value `{"feature_directory":"specs/004-v3-access-ownership"}` (preserved throughout)

### Initial Git Status
```
 M .specify/feature.json
 M app/Http/Controllers/ProfileController.php
 M app/Http/Controllers/UserController.php
 M app/Http/Requests/UserRequest.php
 M app/Models/User.php
 M bootstrap/app.php
 M package-lock.json
 M package.json
 M resources/views/layouts/navigation.blade.php
 M resources/views/users/edit.blade.php
 M routes/web.php
?? Conversations/22_LegacyNotebookLMGoogleAIStudioWorkflow.md
?? Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md
?? "First Research Round_ Adaptive Hybrid Corrective Graph RAG for Interactive Visual Learning.md"
?? Google-Ai-studio-System-prompts
?? Interactive-Seens-Material/Comparing-Between-Old-and-new/
?? "Interactive-Seens-Material/Data Structure/"
?? "Interactive-Seens-Material/Math prep1 /"
?? "Interactive-Seens-Material/Math prep3/"
?? Legacy_Way/
?? "Second Research Round_ Verification of the Deterministic Educational RAG and Simulation Architecture.md"
?? app/Console/
?? app/Contracts/
?? app/Data/
?? app/Http/Controllers/V3/
?? app/Http/Middleware/V3/
?? app/Http/Requests/V3/
?? app/Http/Resources/V3/
?? app/Models/Concerns/
?? app/Models/V3/
?? app/Policies/
?? app/Services/
?? database/migrations/2026_10_01_000004_create_v3_access_tables.php
?? resources/views/v3/
?? routes/v3.php
?? specs/004-v3-access-ownership/
?? specs/005-v3-versioned-settings/
?? specs/006-v3-pdf-intake-cases/
?? specs/007-v3-workflow-progress/
?? specs/008-v3-knowledge-source-adapter/
?? specs/009-v3-lesson-map-sections/
?? specs/010-v3-prompt-jev-qualification/
?? specs/011-v3-scene-authoring/
?? specs/012-v3-scene-qualification/
?? specs/013-v3-teacher-review/
?? specs/014-v3-admin-operations/
?? specs/015-v3-history-evidence/
?? specs/V3-implementation-agent-prompt.md
?? specs/V3-spec-map.md
?? steps.txt
?? tests/Feature/V3/
?? tests/Integration/
?? tests/Support/
```

### Prerequisite 004 Verification
- **Routes (`php artisan route:list --path=v3`)**:
  - `GET|HEAD v3` -> `v3.entry` (`RequireV3Access`)
  - `GET|HEAD v3/admin` -> `v3.admin.workspace` (`RequireV3Access:admin`)
  - `GET|HEAD v3/admin/accounts` -> `v3.admin.accounts.index` (`RequireV3Access:admin`)
  - `PATCH v3/admin/accounts/{user}/access` -> `v3.admin.accounts.access.update` (`RequireV3Access:admin`)
  - `GET|HEAD v3/teacher` -> `v3.teacher.workspace` (`RequireV3Access:teacher`)
- **Middleware Aliases in `bootstrap/app.php`**:
  - `v3.access` -> `App\Http\Middleware\V3\RequireV3Access::class`
  - `v3.role` -> `App\Http\Middleware\V3\RequireV3Access::class`
  - `v3.private` -> `App\Http\Middleware\V3\V3PrivateResponse::class`
- **Prerequisite 004 Test Results**:
  - `php artisan test tests/Feature/V3/Access`: **56 passed** (477 assertions)

### Baseline Non-V3 Test Results
- `php artisan test tests/Feature/Auth/AuthenticationTest.php`: **3 passed**
- `php artisan test tests/Feature/ProfileTest.php`: **2 failed, 3 passed**
  - Pre-existing failures: `ProfileTest` fails on `email_verified_at` column in SQLite due to pre-existing modifications in `User` model / migration. These are unrelated legacy tests and preserved as documented baseline.

---

## 2. Changed Files and Purpose

| File | Type | Purpose |
|---|---|---|
| `database/migrations/2026_10_01_000005_create_v3_settings_tables.php` | Migration | Creates `v3_approved_templates`, `v3_settings_versions`, `v3_active_settings`, and `v3_settings_publications` with restrictive FKs, indexes, and seeded singleton pointer. |
| `app/Models/V3/ApprovedTemplate.php` | Model | Model for operator-registered immutable templates; rejects model update and delete. |
| `app/Models/V3/SettingsVersion.php` | Model | Model for published immutable settings versions; rejects model update and delete. |
| `app/Models/V3/ActiveSettings.php` | Model | Singleton pointer row (`key='pilot'`) holding `active_settings_version_id` and atomic `revision` token. |
| `app/Models/V3/SettingsPublication.php` | Model | Append-only publication audit holding version ID, publisher ID, and sorted `changed_paths`. |
| `app/Console/Commands/V3/RegisterApprovedTemplate.php` | Artisan Command | Trusted CLI command `v3:register-approved-template {kind} {id} {path} {sha256}`. Verifies size, UTF-8, digest, and writes immutable catalog row without logging body. |
| `app/Services/V3/Settings/SettingsSchema.php` | Service | Strict allowlist schema validation for v1 settings payloads, ranges, provider descriptors, and template existence/digest checks. |
| `app/Http/Requests/V3/PublishSettingsRequest.php` | Form Request | Validates publish payload via `SettingsSchema`. Suppresses session old-input flashing on browser validation failure to prevent credential retention. |
| `app/Services/V3/Settings/CanonicalSettings.php` | Service | Normalizes and recursively sorts payload keys, encodes canonical JSON without whitespace, calculates SHA-256 checksum, and calculates leaf-path differences. |
| `app/Services/V3/Settings/SettingsUnavailable.php` | Exception | Typed 503 exception returning safe generic message and `Cache-Control: private, no-store`. |
| `app/Services/V3/Settings/SettingsConflict.php` | Exception | Typed 409 exception returning `"Settings changed. Reload and retry."` with `Cache-Control: private, no-store`. |
| `app/Services/V3/Settings/PublishSettings.php` | Service | Atomic publication service taking `v3_access_control_locks` increment, re-checking active admin, incrementing pointer revision, canonicalizing payload, writing version and publication event, and updating active pointer. |
| `app/Services/V3/Settings/SelectCaseSettings.php` | Service | Transaction-bound selection service for Spec 006. Increments pointer revision, verifies active version checksum, and returns immutable version model. |
| `app/Services/V3/Settings/SettingsVersionRepository.php` | Repository | Loads captured settings version by ID and validates stored SHA-256 canonical checksum, failing closed on corruption or absence. |
| `app/Services/V3/Settings/ApprovedTemplateRepository.php` | Repository | Loads captured template by ID and checksum and validates exact SHA-256 body bytes, failing closed on corruption or absence. |
| `app/Http/Resources/V3/SettingsResource.php` | Resource | Safe projection serializer for settings show endpoint, returning allowlisted fields only. |
| `app/Http/Controllers/V3/Admin/SettingsController.php` | Controller | Handles `show`, `templates`, `store`, and `history` endpoints behind 004 active-admin middleware. |
| `resources/views/v3/admin/settings/show.blade.php` | Blade View | Operating settings form displaying active version info, limits, and publication form. |
| `resources/views/v3/admin/settings/templates.blade.php` | Blade View | Displays catalog of approved templates with IDs, kinds, registered dates, and checksums (no bodies). |
| `resources/views/v3/admin/settings/history.blade.php` | Blade View | Displays chronological table of published versions with changed paths and publisher IDs. |
| `routes/v3.php` | Routes | Registers the 4 settings endpoints inside `admin` prefix protected by `v3.private`, `web`, and `v3.role:admin`. |
| `tests/Feature/V3/Settings/SettingsSchemaTest.php` | Feature Test | Asserts tables, columns, seeded singleton pointer, restrictive FKs, and model immutability (S01, S07). |
| `tests/Feature/V3/Settings/ApprovedTemplateTest.php` | Feature Test | Asserts trusted CLI import, validation, digest mismatch, idempotency, and body omission in output (S15). |
| `tests/Feature/V3/Settings/SettingsValidationTest.php` | Feature Test | Asserts strict schema validation, limits ranges, provider credentials, and canonical prompt override rejection (S03, S15). |
| `tests/Feature/V3/Settings/SettingsBoundaryTest.php` | Feature Test | Asserts guest, teacher, student, and inactive admin denials, CSRF enforcement, and legacy route isolation (S04). |
| `tests/Feature/V3/Settings/PublicationTest.php` | Feature Test | Asserts first publish, replacement publish, stale 409 conflict, identical payload handling, and rollback on error (S01, S02, S05, S06, S07). |
| `tests/Feature/V3/Settings/CaptureContractTest.php` | Feature Test | Asserts `SelectCaseSettings`, A-after-B capture stability, fail-closed on corrupt/missing data, and template byte preservation (S08, S09, S10, S11, S14, S15). |
| `tests/Feature/V3/Settings/SettingsHistoryTest.php` | Feature Test | Asserts pagination, newest-first order, changed leaf paths only, and no values in audit (S12). |
| `tests/Feature/V3/Settings/SettingsPrivacyTest.php` | Feature Test | Asserts credential sentinel exclusion from DB/logs/session/responses, template body exclusion, and null initial show state (S13, S15). |
| `tests/Feature/V3/Settings/SettingsRouteInventoryTest.php` | Feature Test | Asserts exactly 9 V3 production routes, middleware coverage, and absence of mutable/template-body routes (S04, S07, S13, S15). |
| `tests/Integration/V3/Settings/PublicationConcurrencyTest.php` | Integration Test | Genuine 2-worker process contention test against temporary SQLite database verifying exactly 1 winner and 1 409 conflict for same expected version (S05). |
| `tests/Integration/V3/Settings/SelectionConcurrencyTest.php` | Integration Test | Genuine 2-worker process contention test verifying selection and publication linearize cleanly without corruption (S09). |
| `tests/Support/V3/SettingsFixtures.php` | Test Support | Test fixture helper providing default valid payloads and test-only template registration. |
| `tests/Support/V3/ConcurrentSettingsWorker.php` | Test Support | CLI worker process executing `PublishSettings::publish` against isolated database for concurrency tests. |
| `tests/Support/V3/ConcurrentSelectionWorker.php` | Test Support | CLI worker process executing `SelectCaseSettings::selectForNewCase` against isolated database for concurrency tests. |
| `specs/005-v3-versioned-settings/tasks.md` | Spec Tasks | All tasks T001–T015 checked off upon completion. |

---

## 3. TDD Meaningful Red / Green Observations

1. **Group A (Schema & CLI Importer)**:
   - *Red*: `SettingsSchemaTest` failed with `Class "App\Models\V3\SettingsVersion" not found` and missing tables; `ApprovedTemplateTest` failed with `CommandNotFoundException` for `v3:register-approved-template`.
   - *Green*: Migration and models implemented; both tests passed (7 passed, 43 assertions).
2. **Group B (Validation & Boundary)**:
   - *Red*: With minimal route skeleton, all 7 validation test cases failed with status `201` instead of `422` (asserting substantive schema validation was missing); CSRF test received `201` instead of `419`.
   - *Green*: `SettingsSchema` and `PublishSettingsRequest` implemented; all 7 validation tests and 4 boundary tests passed (18 passed, 101 assertions).
3. **Group C (Publication & Contention)**:
   - *Red*: `PublicationTest` failed on checksum property of null, 409 conflict assertions, and 503 rollback assertions.
   - *Green*: `CanonicalSettings`, `PublishSettings`, `SettingsConflict`, and `SettingsUnavailable` implemented; all 6 publication tests passed.
   - *Concurrency*: `PublicationConcurrencyTest` executed 2 simultaneous worker processes on temporary SQLite DB; exactly 1 winner, 1 conflict, 1 version row, and 1 publication event.
4. **Group D (Selection & Handoff)**:
   - *Red*: `CaptureContractTest` failed with `BindingResolutionException` for `SelectCaseSettings`, `SettingsVersionRepository`, and `ApprovedTemplateRepository`. `SelectionConcurrencyTest` failed with target class missing.
   - *Green*: Implemented all three services; all 6 capture tests and selection concurrency test passed (7 passed, 28 assertions).
5. **Group E (Safe Read Surfaces & Route Inventory)**:
   - *Red*: `SettingsHistoryTest` failed expecting pagination/ordered paths; `SettingsPrivacyTest` failed expecting metadata-only templates and null empty state.
   - *Green*: `SettingsResource`, controller actions, and blade templates implemented; `SettingsHistoryTest`, `SettingsPrivacyTest`, and `SettingsRouteInventoryTest` all passed.

---

## 4. Acceptance ID to Test Result Matrix

| Scenario ID | Requirement | Test File | Result | Evidence Summary |
|---|---|---|---|---|
| **S01** | FR-002, FR-003 | `PublicationTest.php`, `SettingsSchemaTest.php` | **PASS** | Valid first publish from empty pointer creates version 1, moves pointer, and records single publication audit event. |
| **S02** | FR-003 | `PublicationTest.php` | **PASS** | Replacement publish increments version to 2; version 1 row, checksum, and published_at remain unchanged. Identical payload creates version 3. |
| **S03** | FR-002, FR-007, FR-008 | `SettingsValidationTest.php` | **PASS** | Missing/extra keys, out-of-range limits, OCR > PDF pages, invalid types, duplicate/unknown providers, mismatched credentials, and canonical prompt overrides all rejected with 422; 0 DB writes. |
| **S04** | FR-001, FR-006 | `SettingsBoundaryTest.php`, `SettingsRouteInventoryTest.php` | **PASS** | Guest gets 401 JSON / 302 login; teacher/student/inactive admin gets 403 `access_denied`; CSRF 419 enforced; legacy `/users` and `/study-flow` expose no settings action. |
| **S05** | FR-003 | `PublicationTest.php`, `PublicationConcurrencyTest.php` | **PASS** | Stale expected version returns 409 `settings_conflict`. Two simultaneous independent worker processes yield exactly 1 committed version and 1 409 conflict. |
| **S06** | FR-003, FR-007 | `PublicationTest.php` | **PASS** | Injected failure during publication rolls back version insert and pointer update; returns 503 `settings_unavailable`. |
| **S07** | FR-003 | `SettingsSchemaTest.php`, `PublicationTest.php`, `SettingsRouteInventoryTest.php` | **PASS** | Eloquent models for version, template, and publication throw `LogicException` on update/delete; no edit/delete routes exist in route collection. |
| **S08** | FR-004 | `CaptureContractTest.php` | **PASS** | Calling `selectForNewCase` with no active version throws `SettingsUnavailable`; test consumer inserts zero rows. |
| **S09** | FR-004 | `CaptureContractTest.php`, `SelectionConcurrencyTest.php` | **PASS** | Same-transaction select returns committed version row; concurrent publication and selection linearize cleanly with zero corrupt/half-written data. |
| **S10** | FR-004, FR-005, FR-008 | `CaptureContractTest.php` | **PASS** | Test consumer captures version A, retains version A after version B publishes; subsequent selection gets version B. |
| **S11** | FR-005 | `CaptureContractTest.php` | **PASS** | Missing version ID or tampered database checksum throws `SettingsUnavailable`; never falls back to active version. |
| **S12** | FR-006 | `SettingsHistoryTest.php` | **PASS** | Admin history is newest-first, paginated (20/page), validates page param (422 on invalid), and returns sorted changed leaf paths without values. |
| **S13** | FR-007 | `SettingsPrivacyTest.php`, `SettingsBoundaryTest.php` | **PASS** | Credential sentinel injected in rejected request is absent from DB, responses, logs, and session old input; all success and error responses have `Cache-Control: private, no-store`. |
| **S14** | FR-008 | `CaptureContractTest.php` | **PASS** | Frozen provider order array (`providers.coding_order`) is preserved and verified in captured payload. |
| **S15** | FR-009 | `ApprovedTemplateTest.php`, `SettingsValidationTest.php`, `CaptureContractTest.php`, `SettingsPrivacyTest.php`, `SettingsRouteInventoryTest.php` | **PASS** | Trusted CLI import validates UTF-8, bounds (1–65536 bytes), digest; catalog list exposes metadata only (no body); old case gets exact old bytes after newer template import; unknown/mismatched template rejected. |

---

## 5. Security, Concurrency, and Route Inventory Evidence

### Two-Worker Process Concurrency Evidence
- **`PublicationConcurrencyTest`**:
  - Spawned 2 concurrent Symfony Process workers against an isolated SQLite database file (`/tmp/v3_settings_concurrency_*.sqlite`).
  - Both submitted requests with identical `expected_version=0`.
  - Process 1 and Process 2 completed: exactly 1 worker reported `success: true, version: 1`; exactly 1 worker reported `success: false, status: 409, code: settings_conflict`.
  - Database verification: exactly 1 row in `v3_settings_versions`, exactly 1 event in `v3_settings_publications`, pointer `active_settings_version_id` points to the winning version.
- **`SelectionConcurrencyTest`**:
  - Spawned simultaneous `ConcurrentSelectionWorker` and `ConcurrentSettingsWorker` (publishing version 2).
  - Both succeeded: selection worker received either version 1 or 2, and consumer table row persisted with matching checksum. Zero half-written records.

### Route Inventory Check
- `php artisan route:list --path=v3` output:
  - `GET|HEAD v3` (`v3.entry`)
  - `GET|HEAD v3/admin` (`v3.admin.workspace`)
  - `GET|HEAD v3/admin/accounts` (`v3.admin.accounts.index`)
  - `PATCH v3/admin/accounts/{user}/access` (`v3.admin.accounts.access.update`)
  - `GET|HEAD v3/admin/settings` (`v3.admin.settings.show`)
  - `POST v3/admin/settings` (`v3.admin.settings.store`)
  - `GET|HEAD v3/admin/settings/history` (`v3.admin.settings.history`)
  - `GET|HEAD v3/admin/settings/templates` (`v3.admin.settings.templates`)
  - `GET|HEAD v3/teacher` (`v3.teacher.workspace`)
- Total production V3 routes: **9** (5 from 004, 4 from 005).
- Zero edit, delete, patch, or template-body download routes exist.

### Privacy & Body Isolation Evidence
- Template catalog list endpoint (`/v3/admin/settings/templates`) returns only `id`, `kind`, `checksum`, `registered_at`; response payload tested and confirmed free of template body text.
- Settings show endpoint (`/v3/admin/settings`) returns `SettingsResource` with non-secret payload; no `expected_active_version` or secrets.
- `PublishSettingsRequest` explicitly redirects back without flashing submitted input into session, preventing secret leakage via session old-input bags.
- Publication audit stores only scalar leaf paths (e.g. `limits.max_pdf_bytes`, `providers.coding_order`), never values.

---

## 6. Execution of Verification Commands

```sh
# 1. Feature tests for Spec 005
php artisan test tests/Feature/V3/Settings
# Result: 38 passed (275 assertions)

# 2. Integration tests for Spec 005
php artisan test tests/Integration/V3/Settings
# Result: 2 passed (12 assertions)

# 3. Route inventory
php artisan route:list --path=v3
# Result: Exactly 9 routes listed

# 4. Prerequisite Spec 004 tests
php artisan test tests/Feature/V3/Access
# Result: 56 passed (477 assertions)

# 5. Code style check with Pint
vendor/bin/pint --test app/Services/V3/Settings app/Models/V3/Settings*.php app/Models/V3/ActiveSettings.php app/Models/V3/ApprovedTemplate.php app/Http/Controllers/V3/Admin/SettingsController.php app/Http/Requests/V3/PublishSettingsRequest.php app/Http/Resources/V3/SettingsResource.php app/Console/Commands/V3/RegisterApprovedTemplate.php database/migrations/2026_10_01_000005_create_v3_settings_tables.php tests/Feature/V3/Settings tests/Integration/V3/Settings tests/Support/V3/SettingsFixtures.php tests/Support/V3/ConcurrentSettingsWorker.php tests/Support/V3/ConcurrentSelectionWorker.php
# Result: PASS (31 files checked, 0 style issues)

# 6. Git diff whitespace check
git diff --check
# Result: Clean (exit 0)
```

---

## 7. Downstream Obligations & Live Operational Prerequisite

### Downstream Integration Obligations (from `contracts/integration-obligations.md`)
- **Spec 006 (PDF Intake & Cases)**:
  - Must call `SelectCaseSettings::selectForNewCase()` on the same DB connection/transaction that inserts `processing_cases`.
  - Must persist non-null restrictive `settings_version_id`.
  - Must test duplicate idempotency keys return existing case/version rather than selecting a new one.
  - Must enforce captured PDF byte and page limits from that version.
- **Spec 007 (Workflow & Progress)**:
  - Must start with case's stored version ID; load that version via `SettingsVersionRepository::getCaptured($id)` for retries, never reading active pointer.
  - Must apply captured technical retry count and delay seconds.
- **Spec 008 (Knowledge Source Adapter)**:
  - Must use captured `max_ocr_pages`.
- **Spec 009 (Lesson Map & Sections)**:
  - Preserves canonical dividing/execution/First prompt text and all eleven Section fields; settings cannot override them.
- **Spec 010 (Prompt Generation & JEV)**:
  - Must load captured feedback template via `ApprovedTemplateRepository::getCaptured(id, checksum)`.
  - Must apply captured score and confidence thresholds.
- **Spec 011 (Scene Authoring)**:
  - Must use captured `coding_order`, `coding_attempts`, and scene template bytes via `ApprovedTemplateRepository`.
- **Spec 012 (Scene Qualification)**:
  - Must use captured scene score/confidence and repair budget.
- **Spec 013 (Teacher Review)**:
  - Must use captured review dormancy days.

### Live Production First-Publication Prerequisite
- The approved-template catalog starts empty.
- For live first publication in a production deployment, a trusted operator must execute:
  ```sh
  php artisan v3:register-approved-template feedback <approved-id> <absolute-approved-file> <sha256>
  php artisan v3:register-approved-template scene <approved-id> <absolute-approved-file> <sha256>
  ```
  using owner-approved template text files and their verified lowercase SHA-256 digests.
- Until both template kinds are imported by an operator, live publication of operating settings will correctly fail validation, and Spec 006 case creation will fail closed with `settings_unavailable`.

---

## 8. Final Status Declaration

- **All Tasks T001–T015**: Completed and checked off in `tasks.md`.
- **All Acceptance Scenarios S01–S15**: Tested through real HTTP, service, model, database, and process boundaries and passing.
- **Mandatory Completion Gate**: Satisfied.
- **Status**: **COMPLETE**.
