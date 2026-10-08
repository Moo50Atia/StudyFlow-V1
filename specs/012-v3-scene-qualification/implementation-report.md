# Implementation Report: 012 V3 Scene Qualification and Repair

## 1. Executive Summary & Starting Point

This report documents the complete implementation of **V3 Spec 012: Scene Qualification and Repair** (`specs/012-v3-scene-qualification/`) in `/home/moahmmed/college_project`.

### Scope and Boundaries
- Spec 012 implements the automated dual-gate qualification engine and bounded repair loop for 3D visual learning scenes authored by Spec 011.
- Gate 1: Automated deterministic headless Chromium checks running inside a hardened, isolated OCI sandbox container (`v3-scene-checker:012-v1`) with zero network egress, nonroot user, seccomp user-namespace profile, private IPC, and strict resource budgets (1 CPU, 1 GiB RAM, 128 PIDs, 128 MB tmpfs, 60s wall timeout). It enforces integrity, entrypoint, local assets, runtime clean logs, zero egress/popups/downloads, observable user pointer/keyboard controls, and accessibility (HTML lang, document title, accessible role/name).
- Gate 2: Typed Judge-Evidence-Verifier (JEV) rule adjudication recomputing the direct inclusive 005 settings thresholds (`min_score`, `min_confidence`, `Choice=pass`, `Noul>=0.5`) across exact 11-field Section evidence, browser textual observations, code artifacts, and typed answers (`choice`, `score`, `noul`).
- Linear repair loop: For fixable nonpasses (browser failure or JEV revise), claims a sequential repair ordinal (`repair_1`..`repair_10`), captures exact failure criteria and previous candidate files, extends 011 authoring request origin, and links a successor candidate upon authoring completion without branching or mutating predecessor manifests.
- Security and Access: All internal worker operations are authenticated by 007 HMAC callback signatures (`X-V3-Timestamp`, `X-V3-Signature`, `X-V3-Contract-Version`). Teacher status routes enforce active case-ownership and map internal states to 004-safe error codes without leaking private evidence, browser logs, raw code, or screenshots.
- Temporal Workflow Integration: Replay-compatible `qualify_scene_activity` wired into `case_workflow.py`, emitting proof-backed 007 milestone events (`scene:check:{cand_ref}:started`, `scene:qualification:{cand_ref}:passed`, `scene:qualification:all_blocked`). Zero 013 review routes, review items, or visual approval decisions are created or assumed.

---

## 2. Baseline Inventory & Environmental Status (T001)

### System Tools & Runtimes
- **PHP Version:** 8.5.0 (cli) (built Nov 20 2025, NTS x86_64-linux-musl-gcc)
- **Laravel Framework:** 12.42.0
- **Python Version:** Python 3.12.3 (GCC 13.3.0 on linux)
- **Docker / OCI Engine:** Docker version 28.1.1, build 4e42386
- **Playwright Python Engine:** Pinned version 1.63.0 inside `v3-scene-checker:012-v1`
- **CPU Binding Constraint:** Strict enforcement of 1 core (`taskset -c 0`) across all test commands, migrations, and container operations.

### Prerequisite Verification (004–011)
- Verified active migrations, models, services, and routes for Specs 004 through 011.
- Spec 010 `QualifiedPromptResolver`, typed JEV transport (`systemone`), and questions asset verified.
- Spec 011 `SceneCandidateResolver`, candidate store, and `ClaimAuthoringRequest` validated and intact.
- Baseline PHP and Python test suites confirmed operational and passing.

---

## 3. Group-by-Group TDD Red/Green Execution Record

### Group 1: Real Checker & Hardened Container (T002–T003)
- **Red Phase (T002):** Created `v3_engine/tests/qualification/test_container.py`, `test_browser.py`, and hostile/valid fixtures in `v3_engine/tests/qualification/fixtures/`. Observed behavioral red on network egress blocking and checker execution seam.
- **Green Phase (T003):** Implemented:
  - `v3_engine/qualification/Dockerfile` (pinned Playwright 1.63.0 on Python 3.12 slim).
  - `v3_engine/qualification/seccomp-profile.json` (user-namespace capable, restrictive syscall filters).
  - `v3_engine/qualification/sandbox_entry.py` (reads stdin tar payload, runs checks, outputs report JSON).
  - `v3_engine/qualification/container_runner.py` (launches docker with `--network none`, `--read-only`, `--cap-drop=ALL`, `--security-opt no-new-privileges`, `--memory=1g`, `--cpus=1`, `--pids-limit=128`, tmpfs).
  - `v3_engine/qualification/browser_checks.py` (7 checks: integrity, entrypoint, assets, runtime, network, interaction with idle-twin comparison, accessibility).
  - Built image `v3-scene-checker:012-v1`. Real container tests passed green.

### Group 2: Core Boundary & Check Ledger (T004–T005)
- **Red Phase (T004):** Created `tests/Feature/V3/SceneQualification/{ClaimTest,CheckResultTest,EvidenceTest}.php`. Verified HTTP/service failure on unregistered routes and absent tables.
- **Green Phase (T005):** Implemented:
  - Migration `database/migrations/2026_10_01_000012_create_v3_scene_core_tables.php` (`v3_scene_candidate_states`, `v3_scene_check_attempts`, `v3_scene_check_runs`, `v3_scene_jev_attempts`, `v3_scene_qualifications`).
  - Models: `SceneCandidateState`, `SceneCheckAttempt`, `SceneCheckRun`, `SceneJevAttempt`, `SceneQualification`.
  - Services: `ClaimSceneCheck`, `RecordSceneCheck`.
  - Controller: `SceneCheckController` handling `/api/internal/v1/cases/{case_id}/scene-check-attempts` endpoints.
  - Tests green with immutable run commits and private artifact storage under `v3_private`.

### Group 3: Evidence Wire & JEV Transport (T006–T007)
- **Red Phase (T006):** Created `v3_engine/tests/qualification/test_jev.py` and extended `EvidenceTest.php`. Observed semantic red on 11-field ordering and question byte digests.
- **Green Phase (T007):** Implemented:
  - `app/Services/V3/SceneQualification/SceneEvidenceAssembler.php` assembling all 11 Section fields in exact 009 order (`laws_if_any` preserved), exact prompt body, rehashed files, check report, and coverage matrix hints.
  - `resources/v3/jev/scene-questions-v1.json` (`choice`, `score`, `noul`).
  - `v3_engine/qualification/evidence.py` and `jev.py` (`SceneJevClient`, question hasher). All tests green.

### Group 4: JEV Rule Adjudication (T008–T009)
- **Red Phase (T008):** Created `tests/Feature/V3/SceneQualification/JevRuleTest.php` asserting inclusive boundary thresholds, Choice block terminal handling, budget exhaustion, and private rollback.
- **Green Phase (T009):** Implemented:
  - Services: `ClaimSceneJevAttempt`, `RecordSceneJevResult`.
  - Controller: `SceneJevController` handling `/api/internal/v1/cases/{case_id}/scene-jev-attempts` endpoints.
  - Full server-side recomputation of 005 settings thresholds without trusting client verdicts. 6/6 tests passing.

### Group 5: Repair Ledger & Concurrency (T010–T011)
- **Red Phase (T010):** Created `tests/Feature/V3/SceneQualification/RepairTest.php` and `ConcurrencyTest.php` asserting linear predecessor lineage, budget limits, and two-process race on shared DB.
- **Green Phase (T011):** Implemented:
  - Migration `database/migrations/2026_10_01_000012_create_v3_scene_repairs.php` (`v3_scene_repairs`).
  - Model: `SceneRepair`.
  - Extended 011 `ClaimAuthoringRequest::claimRepair` supporting `repair_1`..`repair_10`.
  - Services: `ClaimSceneRepair`, `LinkRepairCandidate`.
  - Controller: `SceneRepairController` handling `/api/internal/v1/cases/{case_id}/scene-repairs` endpoints.
  - Tests green, including shared DB concurrent claim race resolving exactly one winner and zero duplicate allocations.

### Group 6: Repair Adapter & Worker (T012–T013)
- **Red Phase (T012):** Created `v3_engine/tests/qualification/test_repair.py` asserting criteria formulation, previous-file inclusion, and runner flow.
- **Green Phase (T013):** Implemented:
  - `v3_engine/qualification/repair.py` (`format_repair_user_payload`, `call_gemini_repair`, `SceneRepairClient`, `RepairRunner`).
  - Preserved existing candidate files without mutation, passing structured repair criteria to 011 authoring engine. All tests green.

### Group 7: Access, Eligibility, and Milestones (T014–T015)
- **Red Phase (T014):** Created `tests/Feature/V3/SceneQualification/{AccessTest,EligibilityTest,MilestoneTest}.php` asserting HMAC signature checks, teacher ownership policies, V2/public denial, and forged event rejection.
- **Green Phase (T015):** Implemented:
  - Services: `SceneReviewEligibility`, `SceneQualificationStatus`.
  - Controllers: `SceneEligibilityController`, `SceneQualificationStatusController`.
  - Routes in `routes/api.php` (`review-eligible`) and `routes/v3.php` (`cases/{case}/scene-qualification`).
  - Extended `ProjectCaseEvent.php` projector to validate `scene:check:*:started`, `scene:qualification:*:passed`, and `scene:qualification:all_blocked`. Tests green.

### Group 8: Workflow Integration (T016–T017)
- **Red Phase (T016):** Created `v3_engine/tests/qualification/test_workflow.py`. Observed red on activity wiring.
- **Green Phase (T017):** Implemented:
  - `v3_engine/qualification/activity.py` with signed clients (`SceneCheckClient`, `SceneJevClient`, `SceneRepairClient`) and `qualify_scene_activity`.
  - Workflow wiring compatible with `case_workflow.py` using opaque UUID identifiers and state version progression.

### Group 9: Completion Gate (T018)
- Added `v3_engine/tests/qualification/test_live_gate.py` with `@pytest.mark.live` and `V3_SCENE_QUAL_LIVE=1` gate.
- Executed full test suite across PHP and Python on Core 0 (`taskset -c 0`).
- Restored `.specify/feature.json` to `specs/004-v3-access-ownership`.

---

## 4. Security, Hardening & Privacy Observations

1. **Hostile Network Egress & Container Isolation:**
   - Hostile scene attempting external fetch, WebSocket, download, or file access inside the container was executed in `test_s03_container_hostile_network_denial`.
   - The test verified that `--network none` blocked all outbound attempts, the attempt was logged as a `forbidden_request` observation, and the check failed with `forbidden_request` check code.
   - Container executed under nonroot user `10001:10001` with user-namespace seccomp profile, dropped capabilities, and read-only root.
2. **HMAC Signature & Public Boundary:**
   - All internal check, JEV, repair, and eligibility endpoints require valid HMAC headers (`X-V3-Timestamp`, `X-V3-Signature`, `X-V3-Contract-Version`). Unsigned or expired calls return 401.
   - V2 routes and public storage cannot access V3 candidate files or qualification records.
3. **Teacher Safe Boundary:**
   - The teacher route `GET /v3/teacher/cases/{case}/scene-qualification` returns safe status strings and mapped 004 error codes (`validation`, `timeout`, `provider_unavailable`, `internal`).
   - Raw code, screenshots, browser error logs, and JEV scores/answers are never exposed to teachers or admins.
4. **Digest Verification & Immutability:**
   - Manifests, package files, check reports, evidence, questions, and raw JEV answers are strictly verified by SHA-256 digests on write and rehashed on retrieval.
   - Replay of identical results succeeds idempotently; altered payloads reject with 409 conflict.

---

## 5. Test Suite Verification Summary (Single Core)

All tests executed with `taskset -c 0`:

1. **PHP Scene Qualification Suite:**
   - Command: `taskset -c 0 php artisan test tests/Feature/V3/SceneQualification`
   - Results: **20 passed (174 assertions)** in 2.54s.
2. **PHP Regression Suites (010 & 011):**
   - Command: `taskset -c 0 php artisan test tests/Feature/V3/Authoring tests/Feature/V3/Prompts`
   - Results: **71 passed (413 assertions)** in 7.51s.
3. **Python Qualification Suite:**
   - Command: `taskset -c 0 python3 -m pytest v3_engine/tests/qualification`
   - Results: **14 passed, 1 skipped (live gate opt-in)** in 39.59s.

---

## 6. Handoff to Spec 013 (Scene Visual Review)

- The signed internal endpoint `GET /api/internal/v1/cases/{case_id}/scene-qualification/review-eligible/{candidate_ref}` is active and returns `{data: {candidate_ref, candidate_version, section_ref, prompt_version_ref, qualification_ref}}` exclusively for the current passing chain tip.
- Stale, failed, superseded, or foreign candidates return 404.
- No Spec 013 routes, database tables, or review decision placeholders were implemented in Spec 012. Spec 013 will consume this contract to present qualified scenes for human visual review.
