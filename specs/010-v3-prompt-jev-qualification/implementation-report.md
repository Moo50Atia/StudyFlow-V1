# Implementation Report: 010 V3 Prompt Generation and JEV Qualification

## 1. Executive Summary & Starting Point

This report documents the complete implementation of **V3 Spec 010: Prompt Generation and JEV Qualification** (`specs/010-v3-prompt-jev-qualification/`) in `/home/moahmmed/college_project`.

### Scope and Boundaries
- Spec 010 implements Execution, Second, and Third step prompt generation over the source-grounded NotebookLM conversation established by 008/009.
- It records immutable original Third-step scene prompts for each Section, submits them to TypeSafe JEV (`choice`, `score`, `noul`) at `/v1/systemone`, and applies captured 005 policy directly without invoking a second judge model.
- Failed prompts are revised using captured `providers.feedback` and approved feedback templates, up to `limits.prompt_revisions`.
- A signed current-pass resolver serves qualified prompts to 011 scene authoring at the handoff boundary.
- The original teacher reviews only the final scene under 013, not the prompt. Teacher and admin routes enforce strict privacy: admin sees metadata only, teacher sees safe per-Section status without raw prompts or scores.

---

## 2. Baseline Inventory & Environmental Status (T001)

### System Tools & Runtimes
- **PHP Version:** 8.5.0 (cli) (built Nov 20 2025, NTS x86_64-linux-musl-gcc)
- **Laravel Framework:** 12.42.0
- **Python Version:** Python 3.12.3 (GCC 13.3.0 on linux)
- **Node.js Version:** v24.20.0
- **NPM Version:** 11.6.0
- **Temporal CLI:** 1.9.1 (server 1.28.1) installed at `/home/moahmmed/.local/bin/temporal`
- **Database Engine:** SQLite 3 (in-memory for isolated unit/feature tests; file-backed with `PRAGMA journal_mode = WAL;` and `PRAGMA busy_timeout = 5000;` for cross-process concurrency suites)

### Pre-Existing Git Status
Prior to Spec 010 implementation changes, `git status --short` reported:
- Modified files:
  - `.specify/feature.json` (value: `{"feature_directory":"specs/004-v3-access-ownership"}`)
  - `app/Http/Controllers/ProfileController.php`, `UserController.php`
  - `app/Http/Requests/UserRequest.php`
  - `app/Models/User.php`
  - `app/Providers/AppServiceProvider.php`
  - `bootstrap/app.php`
  - `config/filesystems.php`, `queue.php`, `services.php`
  - `package.json`, `package-lock.json`
  - `resources/views/layouts/navigation.blade.php`, `resources/views/users/edit.blade.php`
  - `routes/console.php`, `routes/web.php`
- Untracked directories:
  - Pre-existing V3 implementations: migrations `2026_10_01_000004` through `2026_10_02_000009`
  - V3 controllers, middleware, models, services, policies, and routes (`routes/api.php`, `routes/v3.php`)
  - Test suites: `tests/Feature/V3/`, `tests/Integration/`, `tests/Support/`
  - Python engine: `v3_engine/`
  - Spec directories `specs/004-*` through `specs/015-*`

### Baseline Test Results
1. **PHP Feature Tests (`php artisan test tests/Feature/V3`)**:
   - Total Tests: 278
   - Passed: 277
   - Skipped: 1 (Live bridge credential gate in WorkflowStartTest)
   - Failed: 0
   - Duration: 32.34s
2. **PHP Full Suite (`php artisan test`)**:
   - 298 passed, 1 skipped, 5 failed (all 5 failures are legacy `ProfileTest` assertions expecting `email_verified_at` column in users table, unrelated to V3).
3. **Python Test Suites (`v3_engine/tests`)**:
   - `v3_engine/tests/lessons`: 35 passed, 1 skipped
   - `v3_engine/tests/sources`: 23 passed, 1 skipped

---

## 3. Prerequisite Verification (T001)

| Prerequisite | Required Contract Elements | Verification Evidence | Status |
|---|---|---|---|
| **004 Access & Ownership** | Role-based gate (`teacher`, `admin`), active status check, case ownership policy | Verified in `tests/Feature/V3/Access`, `v3.role`, `v3.access` middleware | **PASS** |
| **005 Versioned Settings** | Captured settings versioning, approved templates catalog (`ApprovedTemplateRepository::getCaptured`), score/confidence thresholds, revision limits | Verified in `tests/Feature/V3/Settings`, `ApprovedTemplateRepository.php` | **PASS** |
| **006 PDF Intake & Cases** | Private document storage (`v3_private`), case lifecycle, UUID entities | Verified in `tests/Feature/V3/Intake` | **PASS** |
| **007 Workflow & Progress** | Worker HMAC signature validation (`v3.worker.signature`), case events, milestone projector (`ProjectCaseEvent`), safe status codes | Verified in `tests/Feature/V3/Workflow`, `routes/api.php` | **PASS** |
| **008 Knowledge Source Adapter** | NotebookLM MCP source binding, extraction store, in-memory query adapter | Verified in `tests/Feature/V3/Sources`, `v3_engine/sources` | **PASS** |
| **009 Lesson Map & Sections** | 7 lesson tables, map nodes, 11 canonical section fields, lesson cursor (`awaiting_010`), completion receipt validation | Verified in `tests/Feature/V3/Lessons`, `v3_engine/lessons` | **PASS** |

---

## 4. Canonical Prompt SHA-256 Digest Verification (T001)

The canonical prompt texts defined in `specs/009-v3-lesson-map-sections/contracts/canonical-prompts.md` and locked in `v3_engine/lessons/canonical_prompts.py` were verified against Spec 010 requirements:

| Prompt Key | Contract Required SHA-256 Digest | Actual Code SHA-256 Digest | Status |
|---|---|---|---|
| `execution` | `5ab2448f34c9b2b90199347772d10d7d217ba9fd468dffc28e3169bd0a7fdce9` | `5ab2448f34c9b2b90199347772d10d7d217ba9fd468dffc28e3169bd0a7fdce9` | **MATCH (Exact)** |
| `second` | `a8776affc821a1b913a4685b309dc0304afc03c7389fcfe44b366b6f5683f203` | `a8776affc821a1b913a4685b309dc0304afc03c7389fcfe44b366b6f5683f203` | **MATCH (Exact)** |
| `third` | `07798d744b973ec11248897d2efe0e769ab481c4a21cce7562696dd131217955` | `07798d744b973ec11248897d2efe0e769ab481c4a21cce7562696dd131217955` | **MATCH (Exact)** |

---

## 5. Task & Acceptance Matrix Status

All 18 tasks (T001–T018) mapped to 18 acceptance criteria (P01–P18) have been fully implemented and verified against their behavioral specifications:

| Task ID | Acceptance ID | Description | Primary Deliverable / Implementation | Verification Evidence | Status |
|---|---|---|---|---|---|
| **T001** | P01–P18 | Baseline inspection and prerequisite checks | Prerequisite verification, canonical hashes | Section 3 and 4 of this report | **COMPLETE** |
| **T002** | P01–P04, P12, P13 | Author PHP & Python generation tests | `PromptTurnTest.php`, `WorkerBoundaryTest.php`, `test_generation.py` | Behavioral red confirmed before T003/T004 | **COMPLETE** |
| **T003** | P04, P12, P13 | Schema, models, internal turn & receipt services | Migration `2026_10_02_000010_create_v3_prompt_tables.php`, 7 models, controllers, services | `php artisan test tests/Feature/V3/Prompts/PromptTurnTest.php tests/Feature/V3/Prompts/WorkerBoundaryTest.php` (10 tests pass) | **COMPLETE** |
| **T004** | P01–P03 | Canonical parser & cycle runner | `v3_engine/prompts/{canonical,parser,cycle}.py` | `python3 -m pytest -q v3_engine/tests/prompts/test_generation.py` (9 tests pass) | **COMPLETE** |
| **T005** | P11 | Author two-process claim race test | `tests/Integration/V3/Prompts/PromptRaceTest.php` | Genuine concurrency red observed on file DB | **COMPLETE** |
| **T006** | P11, P12 | Concurrency locking & replay reconciliation | Row locks (`lockForUpdate`), unique composite constraints in migration & services | `php artisan test tests/Integration/V3/Prompts/PromptRaceTest.php` (2 tests pass) | **COMPLETE** |
| **T007** | P05–P07 | Author JEV policy fixtures & tests | `v3_engine/tests/prompts/test_jev_policy.py` | 101-level rubric, Noul confidence, Choice blocking tests green | **COMPLETE** |
| **T008** | P05–P07 | Shared question asset & TypeSafe JEV client | `resources/v3/jev/prompt-questions-v1.json`, `v3_engine/prompts/{policy,jev}.py` | `python3 -m pytest -q v3_engine/tests/prompts/test_jev_policy.py` (6 tests pass) | **COMPLETE** |
| **T009** | P05, P07, P08 | Author qualification feature tests | `tests/Feature/V3/Prompts/QualificationTest.php` | Red confirmed at HTTP endpoint before T010 | **COMPLETE** |
| **T010** | P05–P08 | RecordPromptQualification & Controller | `RecordPromptQualification.php`, `PromptQualificationController.php` | `php artisan test tests/Feature/V3/Prompts/QualificationTest.php` (6 tests pass) | **COMPLETE** |
| **T011** | P09, P10, P12 | Author feedback adapter & revision tests | `test_feedback_adapters.py`, `test_revision.py`, `RevisionTest.php` | Behavioral red confirmed before T012 | **COMPLETE** |
| **T012** | P09, P10, P12 | Feedback adapters & RecordPromptRevision | `v3_engine/prompts/providers/{gemini,glm,deepseek}.py`, `RecordPromptRevision.php`, `PromptFeedbackController.php` | `php artisan test tests/Feature/V3/Prompts/RevisionTest.php` (4 tests pass); Python adapter tests pass | **COMPLETE** |
| **T013** | P14, P15, P17 | Author access, projection & retention tests | `AccessRegressionTest.php`, `ProjectionTest.php`, `RetentionTest.php` | Behavioral red confirmed before T014 | **COMPLETE** |
| **T014** | P14, P15, P17 | Access hardening, warning logger, orphan cleaner | `PromptWarningLogger.php`, `PrivatePromptArtifactReader.php`, `PromptOrphanCleaner.php`, `PromptStatusController.php`, `ProjectCaseEvent.php` | `php artisan test tests/Feature/V3/Prompts/AccessRegressionTest.php tests/Feature/V3/Prompts/ProjectionTest.php tests/Feature/V3/Prompts/RetentionTest.php` (14 tests pass) | **COMPLETE** |
| **T015** | P16 | Author 011 handoff resolver tests | `tests/Feature/V3/Prompts/HandoffTest.php` | Red confirmed at signed route boundary before T016 | **COMPLETE** |
| **T016** | P16 | QualifiedPromptResolver & Controller | `QualifiedPromptResolver.php`, `QualifiedPromptController.php`, signed route | `php artisan test tests/Feature/V3/Prompts/HandoffTest.php` (6 tests pass) | **COMPLETE** |
| **T017** | P18 | Live prompt cycle gate authoring | `v3_engine/tests/prompts/test_live_prompt_cycle.py`, `live_manifest.json`, pytest `live` marker | Fails as incomplete gate when credentials absent; deselected in regular runs | **COMPLETE** |
| **T018** | P01–P18 | Workflow wiring & complete report | `v3_engine/workflows/case_workflow.py`, `v3_engine/prompts/activities.py`, `implementation-report.md` | `test_workflow_wiring.py` (2 tests pass); all quickstart verification suites pass | **COMPLETE** |

---

## 6. Architecture, Schemas, Routes, and Files

### Database Layer (`database/migrations/2026_10_02_000010_create_v3_prompt_tables.php`)
The migration defines the exact 7 tables required by Spec 010:
1. **`v3_prompt_work_items`**: Case work item ledger per work ordinal (`case_id`, `work_ordinal`, `map_id`, `work_node_ref`, `expected_cursor_version`, `section_refs`, `completion_ref`, `completion_sha256`, `state`). Unique index on `(case_id, work_ordinal)`.
2. **`v3_prompt_turns`**: Sent and reused prompt turns (`work_item_id`, `step`, `origin`, `canonical_sha256`, `request_sha256`, `send_count`, `answer_sha256`, `conversation_out`, `state`). Unique index on `(work_item_id, step)`.
3. **`v3_scene_prompt_versions`**: Immutable prompt version records (`case_id`, `section_id`, `work_item_id`, `version_no`, `prompt_body_sha256`, `storage_path`, `scene_type`, `origin`, `state`). Unique index on `(section_id, version_no)`.
4. **`v3_prompt_jev_attempts`**: Ledger of TypeSafe evaluations (`case_id`, `section_id`, `prompt_version_id`, `attempt_no`, `request_sha256`, `requested_model`, `answer_sha256`, `raw_response_json`, `state`). Unique index on `(prompt_version_id, attempt_no)`.
5. **`v3_prompt_qualifications`**: Immutable policy verdicts (`case_id`, `section_id`, `prompt_version_id`, `winning_attempt_id`, `decision`, `failed_checks`, `settings_version_id`, `settings_checksum`, `typed_values_json`). Unique index on `prompt_version_id`.
6. **`v3_prompt_feedback_effects`**: Nonstreaming revision attempts (`case_id`, `section_id`, `failed_prompt_version_id`, `qualification_id`, `template_id`, `template_sha256`, `provider_id`, `provider_model`, `request_sha256`, `revised_prompt_version_id`, `raw_provider_json`, `state`). Unique index on `(failed_prompt_version_id, request_sha256)`.
7. **`v3_prompt_section_states`**: Bounded Section state tracker (`section_id` PK, `case_id`, `current_prompt_version_id`, `revision_count`, `status`, `error_code`). Indexed on `(case_id, status)`.

### Internal Worker API Routes (`routes/api.php`)
All routes protected by `v3.worker.signature` (HMAC-SHA256, ±300s window) returning `Cache-Control: private, no-store`:
- `GET /api/internal/v1/cases/{case_id}/prompt-context/{work_ordinal}`
- `POST /api/internal/v1/cases/{case_id}/prompt-work-items/{work_ordinal}/claim`
- `POST /api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/{step}/claim`
- `GET /api/internal/v1/cases/{case_id}/prompt-turns/{turn_ref}`
- `POST /api/internal/v1/cases/{case_id}/prompt-turns/{turn_ref}/result`
- `POST /api/internal/v1/cases/{case_id}/prompt-turns/{turn_ref}/uncertain`
- `GET /api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/qualification-context`
- `POST /api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/jev-attempts/claim`
- `POST /api/internal/v1/cases/{case_id}/prompt-jev-attempts/{attempt_ref}/result`
- `POST /api/internal/v1/cases/{case_id}/prompt-jev-attempts/{attempt_ref}/technical-failure`
- `GET /api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/feedback-context`
- `POST /api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/feedback/claim`
- `POST /api/internal/v1/cases/{case_id}/prompt-feedback-effects/{effect_ref}/result`
- `POST /api/internal/v1/cases/{case_id}/prompt-feedback-effects/{effect_ref}/uncertain`
- `GET /api/internal/v1/cases/{case_id}/qualified-prompts/{prompt_version_ref}` (011 handoff boundary)

### Teacher Prompt Status Route (`routes/v3.php`)
- `GET /v3/teacher/cases/{case}/prompt-status` (`v3.teacher.cases.prompt-status`):
  Guarded by `v3.role:teacher`, `v3.access:active`, and owner verification middleware.
  Returns safe metadata: `{data: {case_id, sections: [{section_ref, work_ordinal, ordinal_in_work, status, error_code, revision_count}], updated_at}}`.
  Blade view: `resources/views/v3/teacher/prompts/status.blade.php`. Contains no educational text, prompt bodies, scores, or provider credentials.

---

## 7. JEV TypeSafe Integration Details

- **TypeSafe Endpoint:** `POST /v1/systemone`
- **Model:** Configured via `JEV_MODEL` (e.g. `typesafe-jev-v1`), verified via `GET /v1/models`.
- **Question Asset:** `resources/v3/jev/prompt-questions-v1.json` (identical SHA-256 loaded by PHP and Python).
- **Exact Named Questions:**
  1. `choice`: Criteria keys `pass`, `revise`, `block`.
  2. `score`: 101 discrete level strings (`0/100: contradictory...` to `100/100: grounded, complete...`). Fractional expected score in `[0, 100]`.
  3. `noul`: Instructions verifying source grounding and visual misconception correction. Probability in `[0, 1]`.
- **Unrounded Direct Policy Evaluation:**
  - Pass condition: `choice == 'pass' && score >= min_score && 100*choice_confidence >= min_confidence && 100*score_confidence >= min_confidence && noul >= 0.5 && 100*noul >= min_confidence`.
  - Noul probability serves as both the yes-decision (`noul >= 0.5`) and confidence for yes answer (`100 * noul >= min_confidence`).
  - Choice `block` causes terminal `block` regardless of score.
  - Non-pass causes `revise` if `revision_count < revision_limit`; otherwise terminal `block` with `revision_budget_exhausted`.
  - Failed check codes recorded: `choice`, `score`, `choice_confidence`, `score_confidence`, `noul`, `noul_confidence`, `revision_budget_exhausted`.
  - No second judge model is invoked; TypeSafe typed response is authoritative.

---

## 8. Provider Adapters & Revision Feedback Loop

- **Captured Feedback Configuration:** Loaded from 005 settings row (`providers.feedback`), including provider ID, model, and credential reference.
- **Approved Feedback Template:** Loaded via `ApprovedTemplateRepository::getCaptured($templateId, $checksum)` as exact UTF-8 bytes.
- **Provider Adapters (`v3_engine/prompts/providers/`):**
  1. `gemini_api`: Google `models.generateContent` (`POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`). Checks single candidate, `finishReason == "STOP"`.
  2. `glm_api`: Z.AI Chat Completions (`POST https://api.z.ai/api/paas/v4/chat/completions`). Binds strictly to documented Z.AI endpoint with `stream: false`, single choice with `finish_reason == "stop"`.
  3. `deepseek_api`: DeepSeek Chat Completions (`POST https://api.deepseek.com/chat/completions`). `stream: false`, single choice with `finish_reason == "stop"`. Ignores `reasoning_content` in output extraction.
- **Atomic Successor Pointer:** Successful feedback result stores new immutable `v3_scene_prompt_versions` row (`version_no = previous + 1`), preserves predecessor, and updates `current_prompt_version_id` under Section lock.

---

## 9. Security, Privacy, and Access Boundaries

1. **HMAC Signature Protection:** All internal API routes require 007 HMAC-SHA256 signature calculated over `METHOD\nPATH\nTIMESTAMP\nBODY`. Web session cookies, CSRF tokens, and admin credentials cannot authorize internal routes.
2. **Timing & Replay Protection:** Request timestamps must fall within ±300 seconds of server clock.
3. **Artifact Integrity Guard:** `PrivatePromptArtifactReader` re-computes SHA-256 digest on every read of prompt files. Any disk corruption or byte discrepancy fails closed with `PromptUnavailableException`.
4. **Rate-Limited Warnings:** `PromptWarningLogger` logs safe operational codes (`prompt_turn_uncertain`, `prompt_feedback_uncertain`, `prompt_artifact_missing`, `prompt_provider_unavailable`) with IDs only, rate-limited to once per condition per case per 24 hours.
5. **No Prompt Text in History:** Temporal activity inputs and results contain only IDs and status flags. No educational content, prompts, or provider responses enter Temporal workflow history.
6. **Strict Access Regression:**
   - Teacher owner can access `/v3/teacher/cases/{case}/prompt-status`.
   - Foreign teacher receives 404 `resource_unavailable`.
   - Admin receives 403 `access_denied`.
   - V2 routes (`/sections`, `/lectures`, `/api/lectures`) reject V3 UUIDs.

---

## 10. Concurrency, Idempotency, and Recovery Evidence

1. **Two-Process Claim Barrier Race (`PromptRaceTest.php`):**
   - Verified on file-backed SQLite database with WAL mode and busy timeout.
   - Two concurrent processes race to claim the same prompt work turn.
   - Result: Exactly one process commits with `send_now` (`send_count = 1`). Contender receives 409 conflict or reconciles.
2. **Replay Idempotency:**
   - Exact duplicate request replay returns cached result with 200 without incrementing `send_count` or creating duplicate rows.
   - Changed request replay with different digest returns 409 conflict without overwriting stored state.
3. **Post-Send Uncertainty:**
   - A network timeout or failure after send claim transitions turn/effect state to `uncertain` without automatic re-send.
   - No duplicate calls to external models.

---

## 11. Workflow Wiring & 011 Handoff Boundary

- **Temporal Workflow Orchestration (`v3_engine/workflows/case_workflow.py`):**
  - Workflow receives `lesson_ready_v1` signal upon 009 completion receipt commit.
  - Emits milestone `prompt:work:{ordinal}:started` via `publish_case_event`.
  - Emits milestone `prompt:work:{ordinal}:originals` via `publish_case_event`.
  - Emits milestone `prompt:qualification:started` via `publish_case_event`.
  - Executes activity `qualify_prompts` (`v3_engine/prompts/activities.py`).
  - If qualified: emits milestone `prompt:qualification:completed`.
  - At the 011 handoff boundary: invokes `resolve_qualified_prompt` activity for each passing prompt ref.
  - If all sections blocked: emits milestone `prompt:qualification:all_blocked`.
- **Signed Qualified Prompt Resolver (`QualifiedPromptResolver.php`):**
  - Endpoint `GET /api/internal/v1/cases/{case_id}/qualified-prompts/{prompt_version_ref}`.
  - Verified under Section lock that prompt version is current for section and qualification decision is `pass`.
  - Re-reads private prompt artifact and validates SHA-256 before serving.
  - Rejects foreign, stale, or non-passing prompt refs with 404 `prompt_unavailable`.
  - Provides clean, secure handoff to 011 scene authoring without creating any 011 production model or premature candidate generator.

---

## 12. Test Verification Summary

### 1. PHP Prompts Feature & Integration Suites
```sh
php artisan test tests/Feature/V3/Prompts tests/Integration/V3/Prompts
```
- **Result:** 42 passed (229 assertions), 0 failures.
- **Duration:** 4.89s
- **Suites:**
  - `AccessRegressionTest`: 5 passed
  - `HandoffTest`: 6 passed
  - `ProjectionTest`: 6 passed
  - `PromptTurnTest`: 4 passed
  - `QualificationTest`: 6 passed
  - `RetentionTest`: 3 passed
  - `RevisionTest`: 4 passed
  - `WorkerBoundaryTest`: 6 passed
  - `PromptRaceTest`: 2 passed

### 2. Predecessor Suites Regression
```sh
php artisan test tests/Feature/V3/Lessons tests/Feature/V3/Workflow tests/Feature/V3/Access
```
- **Result:** 142 passed (2377 assertions), 0 failures.
- **Duration:** 16.07s

### 3. Full V3 Feature Test Suite
```sh
php artisan test tests/Feature/V3/
```
- **Result:** 317 passed (3501 assertions), 1 skipped (live bridge credential gate), 0 failures.
- **Duration:** 24.71s

### 4. Python Prompts Suite
```sh
python3 -m pytest -q v3_engine/tests/prompts -m 'not live'
```
- **Result:** 22 passed, 1 deselected (live test).
- **Duration:** 7.06s
- **Suites:**
  - `test_generation.py`: 9 passed
  - `test_jev_policy.py`: 6 passed
  - `test_feedback_adapters.py`: 4 passed
  - `test_revision.py`: 1 passed
  - `test_workflow_wiring.py`: 2 passed

### 5. Python Lessons & Sources Suites
```sh
python3 -m pytest -q v3_engine/tests/lessons
python3 -m pytest -q v3_engine/tests/sources
```
- `v3_engine/tests/lessons`: 35 passed, 1 skipped.
- `v3_engine/tests/sources`: 23 passed, 1 skipped.

### 6. Git Hygiene
- `git diff --check`: 0 whitespace errors, clean exit.
- `git status --short`: Only expected V3 implementation paths modified/untracked.

---

## 13. Statement Distinguishing Mock Integration from Live Credential Gate

- **Mock & Local Unit Integration:**
  All automated test suites in `tests/Feature/V3/Prompts/`, `tests/Integration/V3/Prompts/`, and `v3_engine/tests/prompts/` (excluding `@pytest.mark.live`) execute against deterministic, contract-conforming mocks and fixtures. These verify schema conformance, unrounded mathematical boundaries, two-process SQLite WAL concurrency, signed authentication, provenance-checked milestone transitions, and error recovery without external network dependencies.

- **Mandatory Live Credential Gate (P18):**
  Test `v3_engine/tests/prompts/test_live_prompt_cycle.py` (marked `@pytest.mark.live`) is registered as the live release gate. When executed without live credentials in the environment (`V3_PROMPT_LIVE=1`, `TYPESAFE_API_KEY`, `JEV_MODEL`, and captured feedback provider credential), it explicitly fails with:
  `Failed: Live prompt release gate incomplete: required live credentials/environment absent.`
  In accordance with contract requirements, credential absence is treated as an incomplete release gate rather than a pass or skip. Mock tests are not accepted as evidence of third-party model compatibility. Deployment requires provisioning live secrets in the worker environment to execute the final live gate against real vendor endpoints.
