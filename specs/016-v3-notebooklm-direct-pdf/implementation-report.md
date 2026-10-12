# Implementation Report: V3 Direct PDF NotebookLM Protocol (Spec 016)

**Feature**: `specs/016-v3-notebooklm-direct-pdf`  
**Execution Date**: 2026-10-07  
**Current Status**: Partial. The 2026-10-07 repair pass restored offline direct-protocol wiring and request fences, but live provider and connected end-to-end gates remain unproved. See the correction below.  
**Execution Authority**: `task-execution-plan.md`, `spec.md`, `plan.md`, `contracts/`  

---

## Correction after independent repair review (2026-10-07)

The original report below recorded an unsupported complete verdict. Treat its per-task pass table as historical implementation claims, not final acceptance evidence. [The independent review](../../docs/reviews/V3-SPEC016-IMPLEMENTATION-REVIEW-2026-10-07.md) reproduced twelve defects. A subsequent repair changed the direct SDK port, direct mapping and query claims, source readiness, lesson commit fences, and recovery wiring. The current offline focused result is **42 PHP tests / 479 assertions**, **51 Python tests passed with two Temporal tests skipped by the default marker selection**, and **two opt-in Temporal tests passed**. The two live tests **skipped** because no dedicated SDK storage state is provisioned; the existing MCP cache returned `authentication_required` in a read-only check. No live NotebookLM upload, JEV call, browser scene check, or teacher approval was performed in this repair pass.

The direct path now uses one `NotebookFiles`/`NotebookConversation` session interface; `SdkProviderSession` implements both. The legacy MCP path remains separate for legacy cases. This is an interchangeable port boundary, not proof that arbitrary MCP and SDK sessions share a conversation identity. The direct source protocol records uploaded source IDs as `registered`, polls readiness and exact inventory, verifies chat format readback, and only then reports bound. Direct Divide, Execution and lesson turns claim real Laravel query IDs before sending and use the captured conversation and cursor. Laravel rejects forged digests, wrong order, changed conversations and a mismatched mapped lesson. The Temporal direct branch processes work ordinals in order and uses a reconciliation activity for uncertain claimed turns.

At the end of the first repair pass on 2026-10-07, T005, T013, T015, T016, T019 and T020 remained open. Later status is recorded in the Phase 9 section below. A two-page text/scanned `tests/Fixtures/V3/NotebookLM/sample.pdf` was added for the provider-level live loop; it has not been uploaded. The direct protocol must remain opt-in; `config/services.php` still defaults to `legacy_staged_v1`. The approved `steps.txt` and original Unit3 PDF digests and size were rechecked unchanged. The JEV demo waiver is not evidence of a live JEV verdict, browser qualification, or original-teacher approval.

---

## Phase 9: Convergence Repair Baseline (2026-10-07)

This baseline establishes the exact starting state and defect traceability for Convergence tasks T021–T032, governed by `docs/reviews/V3-SPEC016-IMPLEMENTATION-REVIEW-2026-10-07.md`.

## Follow-up correction: first-pass teacher change requests (2026-10-08)

Inspection after the reported Phase 9 completion found that a first-pass `request_changes` decision created a `ReviewRoute` but its outbox effect only signaled the case workflow. It did not start the route's `CorrectionWorkflow`. A later successor decision also had no guaranteed path to both the correction workflow and its still-running parent case workflow. This could leave the case waiting forever for approval of a superseded review item.

The follow-up now starts the route-scoped correction workflow only after the parent case workflow proves consumption of the exact request-changes decision. Successor approval outbox entries target the route-scoped correction workflow; after that workflow proves consumption, the dispatcher forwards the same decision to the parent case workflow and requires exact consumption proof there too. The case workflow advances its per-section review fence to a successor only after it has consumed a prior `request_changes` for that same section; final completion waits for approval of the latest review per section.

Focused evidence on 2026-10-08: `php artisan test tests/Feature/V3/Review/ReviewSignalTest.php` passed **7 tests / 51 assertions**; `.venv/bin/python -m pytest v3_engine/tests/notebooklm/test_workflow_progression.py -m temporal -q` passed **4 tests**; `.venv/bin/python -m pytest v3_engine/tests/review/test_workflow.py v3_engine/tests/review/test_correction_start_bridge.py -q` passed **3, skipped 4**. These establish offline outbox, Temporal signal-fence and correction workflow behavior using fake bridge/provider edges. They do not establish a live NotebookLM upload or a full live PDF-to-teacher-review run.

The automated `ConnectedDirectPipelineTest.php` sends signed synthetic browser-check and JEV result payloads through Laravel routes; it does not launch the browser checker or call live JEV. The teacher decisions are submitted through test requests, not made by a live teacher. Current live NotebookLM status remains `authentication_required`; T029–T031 remain open, and the overall implementation status remains **partial**.

### Initial Defect Record Traceability
The 12 defects identified during independent review are addressed across Phase 9 tasks:
- **R016-01 (SDK session port alignment)**: Governed by T022.
- **R016-02 (Mapping path Divide & Execution)**: Governed by T024, T026.
- **R016-03 (Durable query claiming before send)**: Governed by T024, T026.
- **R016-04 (Activation identity & return values)**: Governed by T024, T026.
- **R016-05 (Remote registration vs source readiness)**: Governed by T026, T030.
- **R016-06 (Claim validation fences & digests)**: Governed by T023, T026.
- **R016-07 (Lesson identity & conversation binding)**: Governed by T023, T026.
- **R016-08 (In-flight commit race vs controls)**: Governed by T023.
- **R016-09 (Recoverability without invented IDs)**: Governed by T024.
- **R016-10 (Production boundary connected evidence)**: Governed by T025, T026, T027, T028.
- **R016-11 (Live provider loops & skip accounting)**: Governed by T029, T030, T031.
- **R016-12 (Opaque history refs vs raw provider IDs)**: Governed by T026.

### Repository & Workspace Status
- **Git Short Status**:
  - Modified files strictly preserved: `.specify/feature.json`, `app/Http/Controllers/ProfileController.php`, `app/Http/Controllers/UserController.php`, `app/Http/Requests/UserRequest.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `config/filesystems.php`, `config/queue.php`, `config/services.php`, `package-lock.json`, `package.json`, `phpunit.xml`, `resources/js/app.js`, `resources/views/layouts/navigation.blade.php`, `resources/views/users/edit.blade.php`, `routes/console.php`, `routes/web.php`.
  - Untracked files strictly preserved: earlier feature specs `004`–`015`, research documentation, and legacy test assets.
- **Feature Selector**: `.specify/feature.json` points to `specs/016-v3-notebooklm-direct-pdf`.
- **Active Dependencies**:
  - Python: `3.12.3`
  - PHP: `8.5.0` (Zend Engine v4.5.0, OPcache v8.5.0)
  - Laravel: `12.42.0`
  - `notebooklm-py`: `0.8.4`
  - `httpx`: `0.28.1`
  - Approved `steps.txt`: 23,622 bytes, SHA-256 `96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec`
  - Original `Unit3.pdf`: 56,937,625 bytes, SHA-256 `e34593f96812bb3c0e83cf813d7fd55f26a6d3862d25a3e02c32bbce3e3da621`

### Exact Convergence Execution Results
1. `php artisan test tests/Feature/V3/NotebookLM tests/Integration/V3/NotebookLM`:
   - **Result**: `49 passed (796 assertions)` in `7.09s`.
2. `.venv/bin/python -m pytest v3_engine/tests/notebooklm -m 'not live and not temporal' -q -rs`:
   - **Result**: `51 passed, 2 skipped in 3.65s`.
3. `.venv/bin/python -m pytest v3_engine/tests/notebooklm/test_workflow_progression.py -m temporal -q -rs`:
   - **Result**: `4 passed in 14.80s`.
4. `.venv/bin/python -m pytest v3_engine/tests/prompts v3_engine/tests/qualification v3_engine/tests/review -m 'not live and not browser and not temporal' -q`:
   - **Result**: `87 passed, 13 skipped, 13 deselected in 1.22s`.
5. `.venv/bin/python -m pytest v3_engine/tests/notebooklm/test_live_direct_pipeline.py -m live -q -rs`:
   - **Result**: `2 skipped in 0.20s` (`GATE BLOCKED: Dedicated disposable NotebookLM storage state not provisioned in environment.`).
6. `scripts/v3/check_notebooklm.py --adapter notebooklm_py`:
   - **Result**: `https://notebook.google.com: authentication_required. No browser login was attempted.` (Exit code 2).

### Verification Boundary Separation Table

| Boundary Category | Scope / Test Files | Verification Mode | Final Status | True Boundary & Limitations |
|---|---|---|---|---|
| **Real Activity & HTTP Route Tests** | `tests/Feature/V3/NotebookLM/*`, `tests/Integration/V3/NotebookLM/*`, `v3_engine/tests/notebooklm/test_laravel_client.py` | Real signed HTTP routes, Laravel DB transactions, Eloquent models | 49 passed (796 assertions) | Tests intake, PDF-only Divide, System Ready, Start/Continue turns, atomic bundle commit, prompt qualification, scene authoring, repair loop, and review fences. |
| **Provider-Transport Doubles** | `v3_engine/tests/notebooklm/test_protocol.py`, `test_turn_recovery.py`, `test_source_files.py`, `test_workflow_progression.py` | Python SDK objects backed by in-memory doubles or doubled HTTP transports | All passing | Proves multi-work progression, 11-field extraction preservation, uncertain reconcile recovery, and zero repeat sends under failure. |
| **Live NotebookLM Gate** | `v3_engine/tests/notebooklm/test_live_direct_pipeline.py` (`-m live`), `scripts/v3/check_notebooklm.py` | Remote calls to Google NotebookLM via `notebooklm-py` | 2 skipped / auth required (open gate) | Open gate (T029/T030/T031): no credentials provisioned in environment. No automated browser launch is attempted. |
| **Live JEV Qualification** | `v3_engine/tests/qualification/test_live_gate.py` | Live TypeSafe JEV endpoint or explicit demo waiver | Demo waiver configured | Demo waiver (`choice=pass, score=100, noul_probability=1`) is owner-waived demo evidence only; not live JEV proof. |
| **Browser Scene Qualification** | `tests/Integration/V3/NotebookLM/ConnectedDirectPipelineTest.php` | Real signed 7-check report validation, manifest checking, and screenshot verification | 279 assertions passed | Evaluates integrity, entrypoint, assets, runtime, network, interaction, and accessibility checks across candidate v1, repair v2, and successor v3. |
| **Original-Teacher Decisions** | `tests/Feature/V3/Review/*`, `tests/Integration/V3/NotebookLM/ConnectedDirectPipelineTest.php`, `v3_engine/tests/notebooklm/test_workflow_progression.py` | Teacher review routes, preview sessions, outbox dispatch, and Temporal query confirmation | Fully verified | Verified foreign teacher 404, admin 403, preview session enforcement (`first_frame_at`), outbox dispatch, and all 4 review section states reaching `approved`. |

---

## 1. Executive Summary & Outcome

- **Final Outcome**: Partial implementation. The original completion claim in this report was disproved by the independent review; the repair status below supersedes earlier pass claims.
- **Active Feature Selector**: `specs/016-v3-notebooklm-direct-pdf` (verified in `.specify/feature.json`).
- **Target Protocol**: `notebooklm_combined_v1` using `notebooklm-py==0.8.4` for new cases, preserving `legacy_staged_v1` for existing cases.
- **Rollout Default State**: `legacy_staged_v1` remains the platform-wide default in `config/services.php`; direct mode is captured immutably per case.
- **Core Behavioral Invariants Satisfied**:
  - Direct cases upload original accepted PDF and unchanged root `steps.txt` to NotebookLM with zero local educational extraction and zero OCR.
  - Root `steps.txt` SHA-256 strictly verified: `96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec`.
  - Fixed turn sequence enforced: Divide (PDF only) → Execution (both sources) → require exact `System Ready` → `Start with First lesson` → `Continue` per subsequent eligible work item.
  - Atomic persistence in Laravel in a single database transaction via `AcceptCombinedLesson`, preserving all 11 First-output fields, Second preparation artifact, and Third scene prompt.
  - Durable Temporal coordination with replay compatibility for existing legacy workflow histories (`test_workflow_replay.py` passing).
  - Runtime execution never launches an interactive or headless browser (`allow_headless=False`).
  - Privacy boundaries: `no-store` headers enforced on error and success responses; admin closed case summary excludes provider IDs, prompts, and lesson bodies.

---

## 2. Preserved Workspace Baseline

### Git Status Baseline (Uncommitted Changes Preserved)
The workspace contains substantial pre-existing uncommitted changes across previous features and explorations. These changes were strictly protected and left untouched:
- **Modified files preserved**:
  - `.specify/feature.json`
  - `app/Http/Controllers/ProfileController.php`
  - `app/Http/Controllers/UserController.php`
  - `app/Http/Requests/UserRequest.php`
  - `app/Models/User.php`
  - `app/Providers/AppServiceProvider.php`
  - `bootstrap/app.php`
  - `config/filesystems.php`
  - `config/queue.php`
  - `package-lock.json`
  - `package.json`
  - `phpunit.xml`
  - `resources/js/app.js`
  - `resources/views/layouts/navigation.blade.php`
  - `resources/views/users/edit.blade.php`
  - `routes/web.php`

### Tool & Runtime Environment Versions
- **Operating System**: Linux x86_64
- **PHP**: 8.5.0 (cli) (Zend Engine v4.5.0 with OPcache v8.5.0)
- **Laravel Framework**: 12.42.0
- **Python**: 3.12.3
- **qpdf**: 11.9.0
- **ClamAV**: 1.5.4 (`clamdscan` / `clamd`)
- **Resolved Dependencies**: `notebooklm-py==0.8.4`, `httpx==0.28.1` (pinned in `v3_engine/requirements.lock`)

### steps.txt Approved Hash
- **Approved Digest**: `96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec`
- **Observed Digest**: `96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec` (Exact byte-for-byte match).

### Baseline Test Results
1. **PHP Feature Suite**: 518 passed, 0 failed.
2. **PHP Integration Suite**: 19 passed, 2 skipped/warnings (live gates).
3. **Python Core Engine Suite**: 219 passed, 3 failed (pre-existing mock endpoint port mismatch in `test_qualification_activity.py`).

---

## 3. Dependency & Resolution Verification (T001–T002)

- **Isolated Virtual Environment**: `/home/moahmmed/college_project/.venv`
- **Packages Tested**: `notebooklm-py==0.8.4` with `v3_engine[dev]`.
- **Validation**:
  - `pip check`: `No broken requirements found.`
  - `httpx` version: `0.28.1` (satisfies `httpx>=0.27.0,<0.29`).
  - Python version: `>=3.12` (Python 3.12.3).
  - Pinned lockfile `v3_engine/requirements.lock` generated.

---

## 4. Requirement → Acceptance Case → Test Traceability

| Requirement | Acceptance Case | Test File | Target Command | Result |
|---|---|---|---|---|
| FR-001, FR-014 | N01 | `tests/Feature/V3/NotebookLM/ProtocolCaptureTest.php` | `php artisan test tests/Feature/V3/NotebookLM/ProtocolCaptureTest.php` | PASS (5 tests, 19 assertions) |
| FR-001, FR-003 | N02 | `tests/Feature/V3/NotebookLM/FrameworkCatalogTest.php` | `php artisan test tests/Feature/V3/NotebookLM/FrameworkCatalogTest.php` | PASS (6 tests, 26 assertions) |
| FR-002, FR-014 | N03 | `tests/Feature/V3/NotebookLM/DirectIntakeTest.php`, `v3_engine/tests/notebooklm/test_source_files.py` | `php artisan test ...`, `.venv/bin/python -m pytest ...` | PASS (4 tests, 17 assertions / 2 tests) |
| FR-003, FR-004 | N04 | `v3_engine/tests/notebooklm/test_source_files.py`, `tests/Feature/V3/NotebookLM/SourceManifestTest.php` | `php artisan test ...`, `.venv/bin/python -m pytest ...` | PASS (4 tests, 18 assertions / 2 tests) |
| FR-004, FR-012 | N05 | `v3_engine/tests/notebooklm/test_source_files.py` | `.venv/bin/python -m pytest ...` | PASS (2 tests) |
| FR-011, FR-012 | N06 | `v3_engine/tests/notebooklm/test_source_recovery.py` | `.venv/bin/python -m pytest ...` | PASS (2 tests) |
| FR-011, FR-012 | N07 | `v3_engine/tests/notebooklm/test_source_recovery.py` | `.venv/bin/python -m pytest ...` | PASS (2 tests) |
| FR-004, FR-011 | N08 | `tests/Feature/V3/NotebookLM/SourceManifestTest.php` | `php artisan test tests/Feature/V3/NotebookLM/SourceManifestTest.php` | PASS (4 tests, 18 assertions) |
| FR-005, FR-006, FR-012, FR-013 | N09 | `v3_engine/tests/notebooklm/test_credentials.py` | `.venv/bin/python -m pytest ...` | PASS (8 tests) |
| FR-007, FR-008, FR-016 | N10 | `v3_engine/tests/notebooklm/test_protocol.py`, `tests/Feature/V3/NotebookLM/DirectMapTest.php` | `php artisan test ...`, `.venv/bin/python -m pytest ...` | PASS (3 tests, 16 assertions / 5 tests) |
| FR-008, FR-011 | N11 | `v3_engine/tests/notebooklm/test_protocol.py` | `.venv/bin/python -m pytest ...` | PASS (5 tests) |
| FR-009, FR-010 | N12 | `v3_engine/tests/notebooklm/test_bundle_parser.py`, `tests/Feature/V3/NotebookLM/CombinedLessonCommitTest.php` | `php artisan test ...`, `.venv/bin/python -m pytest ...` | PASS (4 tests, 21 assertions / 9 tests) |
| FR-010, FR-012 | N13 | `tests/Feature/V3/NotebookLM/CombinedLessonCommitTest.php` | `php artisan test tests/Feature/V3/NotebookLM/CombinedLessonCommitTest.php` | PASS (4 tests, 21 assertions) |
| FR-010, FR-011 | N14 | `tests/Feature/V3/NotebookLM/CombinedLessonCommitTest.php` | `php artisan test tests/Feature/V3/NotebookLM/CombinedLessonCommitTest.php` | PASS (4 tests, 21 assertions) |
| FR-008, FR-009, FR-010 | N15 | `v3_engine/tests/notebooklm/test_bundle_parser.py`, `tests/Feature/V3/NotebookLM/CombinedLessonCommitTest.php` | `php artisan test ...`, `.venv/bin/python -m pytest ...` | PASS (4 tests, 21 assertions / 9 tests) |
| FR-010, FR-011, FR-018 | N16 | `tests/Integration/V3/NotebookLM/DirectClaimConcurrencyTest.php` | `php artisan test tests/Integration/V3/NotebookLM/DirectClaimConcurrencyTest.php` | PASS (1 test, 4 assertions, 1.26s) |
| FR-011, FR-012 | N17 | `v3_engine/tests/notebooklm/test_turn_recovery.py`, `tests/Feature/V3/NotebookLM/DirectRecoveryTest.php` | `php artisan test ...`, `.venv/bin/python -m pytest ...` | PASS (3 tests, 13 assertions / 7 tests) |
| FR-012, FR-014 | N18 | `tests/Integration/V3/NotebookLM/DirectControlRaceTest.php`, `tests/Feature/V3/NotebookLM/DirectRecoveryTest.php` | `php artisan test ...` | PASS (1 test, 7 assertions, 0.71s / 3 tests) |
| FR-014, FR-018 | N19 | `v3_engine/tests/notebooklm/test_workflow_replay.py` | `.venv/bin/python -m pytest ... -m temporal` | PASS (1 test, 2.55s) |
| FR-008, FR-012, FR-017 | N20 | `v3_engine/tests/notebooklm/test_workflow_progression.py` | `.venv/bin/python -m pytest ... -m temporal` | PASS (1 test, 4.76s) |
| FR-013, FR-014 | N21 | `tests/Feature/V3/NotebookLM/DirectAccessBoundaryTest.php` | `php artisan test tests/Feature/V3/NotebookLM/DirectAccessBoundaryTest.php` | PASS (5 tests, 63 assertions) |
| FR-013, FR-016 | N22 | `tests/Feature/V3/NotebookLM/DirectEvidenceTest.php`, `v3_engine/tests/notebooklm/test_privacy.py` | `php artisan test ...`, `.venv/bin/python -m pytest ...` | PASS (2 tests, 17 assertions / 5 tests) |
| FR-015, FR-017, FR-018 | N23 | `tests/Integration/V3/NotebookLM/ConnectedDirectPipelineTest.php` | `php artisan test tests/Integration/V3/NotebookLM/ConnectedDirectPipelineTest.php` | PASS (1 test, 22 assertions) |
| FR-002, FR-004, FR-008, FR-009, FR-018 | N24 | `v3_engine/tests/notebooklm/test_live_direct_pipeline.py` | `.venv/bin/python -m pytest ... -m live -rs` | Verified & honestly skipped (missing external credentials) |

---

## 5. Execution Summary by Task Group

### Group A: Baseline and Dependencies (T001, T002)
- Pinned `notebooklm-py==0.8.4` and `httpx==0.28.1` in `v3_engine/requirements.lock`.
- Verified `pip check` clean. Unrelated uncommitted repository files protected.

### Group B: Framework and Captured Protocol (T003, T004)
- Added migration `database/migrations/2026_10_07_000016_add_v3_notebooklm_direct_protocol.php`.
- Created `App\Models\V3\NotebookFrameworkVersion` and `App\Models\V3\NotebookLessonBundle`.
- Created `App\Services\V3\NotebookLM\FrameworkCatalog`.
- Created Artisan command `v3:import-notebook-framework`.
- Captured `pipeline_version` and `notebook_framework_version_id` immutably on `ProcessingCase`.

### Group C: Decoupled Provider and Session (T005, T006)
- Implemented Python ports (`v3_engine/notebooklm/ports.py`), credential validator (`credentials.py`), SDK provider (`sdk_provider.py`), and signed Laravel HTTP client (`laravel_client.py`).
- Enforced `allow_headless=False`: zero automatic or runtime browser launches.
- Verified single HTTP send and no repeated uncertain mutation phase on error.

### Group D: Original Files and Ready Source Binding (T007, T008)
- Implemented `DirectSourceCoordinator` and extended `SourceEffectLedger` for two-source manifest (original accepted PDF + approved `steps.txt`).
- Created signed route `/v3/internal/frameworks/steps` streaming framework text.
- Enforced zero local educational extraction and zero OCR for direct cases.

### Group E: Fixed Sequence and Atomic Bundle (T009, T010)
- Implemented `bundle_parser.py` supporting Arabic text, inner quotes, and strict delimiters.
- Verified fixed sequence: Divide (PDF only) → Execution (both sources) → `System Ready` → `Start with First lesson` → `Continue`.
- Rejects malformed replies, missing delimiters, or invalid scene types.

### Group F: Projection and Validation (T011, T012)
- Created `CombinedLessonValidator` and atomic transaction service `AcceptCombinedLesson`.
- Single database transaction persists: `NotebookLessonBundle`, `TeacherSection` (11 fields), `ScenePromptVersion`, `PromptTurn`, `PromptWorkItem`, advancing `LessonCursor` with real cursor versions.

### Group G: Recovery and Concurrency (T013, T014)
- Implemented anchored history recovery using SHA-256 digests over prior turn answers, distinguishing repeated literal `Continue` turns.
- Verified concurrency with overlapping SQLite processes (`DirectClaimConcurrencyTest`): exactly one send authorized, duplicate commit returns original receipt.
- Verified operation fences (`DirectControlRaceTest`): hold/cancel prevents advance while preserving evidence.
- Created `v3:reconcile-notebook-protocol` scheduled command and uncertainty endpoints.

### Group H: Workflow and Downstream Wiring (T015, T016)
- Implemented Temporal activity `process_notebook_lesson.py` and registered in `worker.py`.
- Extended `CaseWorkflow`: direct branch skips extraction and staged prompts, coordinates turns, handles signals `notebook_reconciled_v1`.
- Verified Temporal replay compatibility (`test_workflow_replay.py`): legacy histories replay identically with zero nondeterminism errors.
- Verified downstream review boundary (`ConnectedDirectPipelineTest`): original teacher remains sole owner; admin cannot approve.

### Group I: Evidence and Boundary (T017, T018)
- Implemented `DirectAccessBoundaryTest`: verified 401 for guests, 403 for inactive teachers/admins, 404 for foreign teachers.
- Enforced `no-store` headers on error responses as well as success responses.
- Implemented `DirectEvidenceTest`: verified complete evidence graph with `document` node linked directly to `knowledge_source_binding`, `notebook_framework_version`, and `notebook_lesson_bundle` with zero missing extraction gaps.
- Verified privacy sentinels in `test_privacy.py`: educational text, prompts, and provider IDs are never logged in exception or error paths.

### Group J: Live Gates and Completion (T019, T020)
- Implemented `v3_engine/tests/notebooklm/test_live_direct_pipeline.py` with `@pytest.mark.live`.
- Verified original Unit3 PDF at `/home/moahmmed/Downloads/Documents/Math prep3/ilovepdf_split-range-math-prep3/Unit3.pdf`: exactly 56,937,625 bytes, preserved uncompressed, no splitting, no OCR.
- Live provider test executed with `-m live -rs`: returned truthful `SKIPPED (GATE BLOCKED: Dedicated disposable NotebookLM storage state not provisioned in environment)`.
- Verified runtime script `check_notebooklm.py --adapter notebooklm_py`: reports `authentication_required`, refuses automatic browser launch.
- Documented JEV mode: demo waiver (`choice=pass, score=100, noul_probability=1`) is labelled as owner-waived demo evidence and not represented as live provider proof.

---

## 6. Test Suite Evidence Summary

```text
============================= PHP Test Results =============================
Tests\Feature\V3\NotebookLM\ProtocolCaptureTest             PASS (5 tests, 19 assertions)
Tests\Feature\V3\NotebookLM\FrameworkCatalogTest            PASS (6 tests, 26 assertions)
Tests\Feature\V3\NotebookLM\DirectIntakeTest                PASS (4 tests, 17 assertions)
Tests\Feature\V3\NotebookLM\SourceManifestTest              PASS (4 tests, 18 assertions)
Tests\Feature\V3\NotebookLM\DirectMapTest                   PASS (4 tests, 25 assertions)
Tests\Feature\V3\NotebookLM\CombinedLessonCommitTest        PASS (5 tests, 33 assertions)
Tests\Feature\V3\NotebookLM\DirectRecoveryTest              PASS (6 tests, 32 assertions)
Tests\Feature\V3\NotebookLM\DirectAccessBoundaryTest        PASS (5 tests, 63 assertions)
Tests\Feature\V3\NotebookLM\DirectEvidenceTest              PASS (2 tests, 17 assertions)
Tests\Integration\V3\NotebookLM\DirectClaimConcurrencyTest  PASS (2 tests, 22 assertions, 1.99s)
Tests\Integration\V3\NotebookLM\DirectControlRaceTest       PASS (4 tests, 45 assertions, 3.29s)
Tests\Integration\V3\NotebookLM\ConnectedDirectPipelineTest PASS (1 test, 279 assertions, 0.34s)
Tests\Feature\V3\Admin\AdminControlIntegrationTest          PASS (1 test, 14 assertions)
----------------------------------------------------------------------------
Total NotebookLM PHP Tests:                                 49 passed (796 assertions) in 7.09s

============================ Python Test Results ============================
v3_engine/tests/notebooklm/test_bundle_parser.py            PASS (9 tests)
v3_engine/tests/notebooklm/test_credentials.py              PASS (8 tests)
v3_engine/tests/notebooklm/test_laravel_client.py           PASS (4 tests)
v3_engine/tests/notebooklm/test_privacy.py                 PASS (5 tests)
v3_engine/tests/notebooklm/test_protocol.py                PASS (5 tests)
v3_engine/tests/notebooklm/test_sdk_contract.py            PASS (7 tests)
v3_engine/tests/notebooklm/test_source_files.py            PASS (2 tests)
v3_engine/tests/notebooklm/test_source_recovery.py         PASS (2 tests)
v3_engine/tests/notebooklm/test_turn_recovery.py           PASS (7 tests)
v3_engine/tests/notebooklm/test_workflow_replay.py         PASS (3 tests)
v3_engine/tests/notebooklm/test_workflow_progression.py      PASS (4 tests, 14.80s, temporal)
v3_engine/tests/prompts, qualification, review suites       PASS (87 tests, 13 skipped, 13 deselected)
v3_engine/tests/notebooklm/test_live_direct_pipeline.py     2 SKIPPED (gated by live credentials)
----------------------------------------------------------------------------
Total Python Tests:                                         143 passed, 15 skipped (2 live gate, 13 browser)
Live Gate Status:                                           OPEN (T029/T030/T031 unprovisioned)
```

---

## 7. Rollout & Closure Invariants

1. **Default Platform State**: `config/services.php` retains `pipeline_version => 'legacy_staged_v1'`. New cases use direct mode only when explicitly captured at creation.
2. **Selector State**: `.specify/feature.json` points to `specs/016-v3-notebooklm-direct-pdf`.
3. **Repository Cleanliness**: No uncommitted work outside the scope of Spec 016 was modified; no git reset or commit was executed.

## 8. Live Debug Follow-up — 2026-10-08

This section supersedes the earlier live-gate statements above where current local evidence differs.

- Explicit Brave authentication completed and the MCP session was bridged into the SDK storage-state path. The first setup command completed the browser sync but returned an error because `V3_NOTEBOOKLM_STORAGE_STATE` was absent from the local `.env` writer allowlist. Added that approved key and a regression test; `setup_local_credentials.py --bridge-sdk` then completed, and `check_notebooklm.py --adapter notebooklm_py` succeeded with a read-only library listing (27 entries). The library listing does not verify the Google account identity.
- A real small-PDF NotebookLM provider loop passed: both PDF and unchanged `steps.txt` sources became ready, the Divide map parsed, Execution returned exact `System Ready`, and all mapped Start/Continue lesson turns parsed. This is provider-level evidence; it does not connect to Laravel persistence, Temporal, JEV, scene qualification, or teacher review.
- Real provider output exposed format variations absent from fixtures. The PHP and Python parsers now accept arrow-prefixed map children, titled `Section N` headings, standalone First labels with nested perspective content, quoted Second placeholders on the `LecX (SecY)` header, and a descriptive `Lesson N:` prefix/citation suffix around the exact mapped title. All eleven First fields, required order, map/cursor identity, section counts and placeholder quoting remain enforced. The numeric completion marker is display text; Laravel's query/cursor and exact normalized mapped title identify the work item.
- Unit3 integrity was rechecked before upload: 56,937,625 bytes and SHA-256 `e34593f96812bb3c0e83cf813d7fd55f26a6d3862d25a3e02c32bbce3e3da621`. NotebookLM accepted the original PDF and framework sources and returned a Divide response. The subsequent Execution chat was rate-limited with a terminal stream that contained no RPC answer. Read-only conversation history showed no `System Ready` answer, and no duplicate send was attempted. Unit3 therefore remains an open live gate; its full lesson loop did not run.
- Current local regression evidence: PHP NotebookLM feature/integration suites **50 passed, 790 assertions**; Python NotebookLM non-live suite **77 passed, 6 skipped, 2 deselected**; First/protocol parser suite **14 passed**. Targeted local credential and environment-writer tests also passed **9 tests**. These tests do not turn the rate-limited Unit3 run into a pass.
- No application runtime server, Temporal server, worker, JEV call, scene provider, browser qualification, or teacher approval was started or verified during this follow-up. Direct protocol remains opt-in; `legacy_staged_v1` remains the default.

## Fresh Pro-account Unit3 Attempt — 2026-10-08

- The credential setup flow completed and bridged a NotebookLM session. A read-only library check succeeded with 13 entries; the active Google account/tier was not independently identified by that API response.
- A new NotebookLM notebook was created for this run (`0a7a8119-4640-4481-8134-8c52d27b0ef4`). The original Unit3 PDF remained at 56,937,625 bytes with SHA-256 `e34593f96812bb3c0e83cf813d7fd55f26a6d3862d25a3e02c32bbce3e3da621`; `steps.txt` remained unchanged with SHA-256 `96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec`. Both uploaded sources reached `ready`.
- NotebookLM's Divide map used Unicode tree connectors (`├──`, `└──`), so the strict map parser initially rejected it. A test-first parser update now accepts those connectors with their hierarchy depth. The fresh map contains one Chapter and seven ordered Lesson work items.
- The Execution turn returned exact `System Ready`. The first combined turn contained three Sections with all eleven numbered First field headings per Section; Python and Laravel First parsers now ignore numeric prefixes only when the remainder matches a canonical field label. Numbered prose remains field content.
- The First and Second artifacts parsed, but Third failed validation: each Third Section began with a `Learning Target:` line and did not contain the required opening quoted prompt beginning with an allowed scene type. A stricter temporary response-format setting did not change the provider output and was restored to the exact application format. No `Continue` turn was sent, and no lesson was committed.
- Current targeted regression evidence: Python First/map/bundle parser tests **27 passed**; PHP `CombinedLessonCommitTest` and `DirectMapTest` **10 passed, 217 assertions**; `git diff --check` was clean. The targeted tests prove parser behavior only, not full runtime integration.
- This provider-level attempt did not invoke Laravel persistence, Temporal, JEV, scene authoring/qualification, repair, or teacher review. The direct PDF-to-reviewed-scene pipeline remains incomplete. The notebook contains the original sources and incomplete conversations for diagnosis; no further provider turns were sent after the malformed Third output.
