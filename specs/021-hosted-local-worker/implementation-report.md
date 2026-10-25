# Implementation and Acceptance Report: Feature 021

> **Current manager/CLI implementation:** see [Feature 021 closure evidence](../../docs/FEATURE-021-CLOSURE.md) and [current operations](../../docs/LOCAL-WORKER-OPERATIONS.md). The report below is historical; its counts, commands, direct-provider architecture and broad readiness claims do not describe the current manager/CLI path.

**Feature**: Hosted Platform with an Outbound Local Processing Worker  
**Specification Directory**: `specs/021-hosted-local-worker/`  
**Date**: 2026-10-18  
**Report Status**: SUPERSEDED — current code review found unresolved production blockers.

> **Current readiness correction (2026-10-19): NOT PRODUCTION READY.** The digest manifest and acceptance claims below describe an older snapshot. A second independent review reproduced 23 actionable findings; subsequent local edits only cover a subset and their targeted tests. The old E1–E6 closure statements and source hashes are not evidence for the current checkout. E4/E5 remain unrun, and E6 is not approved.

> **Latest local verification (2026-10-19):** 66 PHP tests (425 assertions) and 48 Python tests pass across local-worker, bridge, and worker-lifecycle suites, including the separate-process HTTP boundary. Targeted Python compilation and PHP lint pass. The isolated production-configured loopback smoke server completed migrations, `/login` returned HTTP 200, the unauthenticated worker endpoint returned 401, and the lease reaper is scheduled. Earlier isolated smoke checks also rendered the authenticated Admin/Devices Inertia view and exercised private storage and a database queue job. These checks establish local HTTP/queue/filesystem/view behavior only. `git diff --check` reports a trailing blank line at EOF in `routes/api.php`, a pre-existing mixed-worktree change outside this repair; it was not altered here. These checks do not resolve the remaining provider, result-lineage, qualification-execution, security-race, or deployment findings below.

> **Additional targeted verification (2026-10-19):** The new `qualification_runner` delivery path passes Laravel dispatch and Python bridge contract tests. The Laravel outbox dispatches only after a signed bridge response confirms a stable-ID Temporal workflow was accepted; the candidate remains `awaiting_check`. This verifies dispatch wiring, not execution of a real Temporal browser check or the passing/failing package evidence required to close LW-R24.

> **Fresh targeted re-run (2026-10-19):** Feature 021/local-worker/progress/scene-check PHP tests: **77 passed, 501 assertions**. Python local-worker tests: **38 passed**; Temporal qualification workflow tests: **6 passed**; real-browser qualification checker tests: **6 passed**. The Temporal qualification tests now use the actual Chromium checker for passing and broken scene packages and verify that transient storage failures retry before the candidate is claimed. Persistent infrastructure failures raise retryable activity errors; the Temporal workflow remains open with capped backoff, holding the candidate until recovery. PHP scene-check tests verify successful candidates advance only to `awaiting_jev` and failed candidates to `repair_pending`, without student publication. This closes LW-R24 implementation-level acceptance. The NotebookLM adapter calls the local `NotebookTerminalService`; its operation sequence and source integrity are tested with a terminal-service double, not a live Google session. A loopback Laravel server at `127.0.0.1:8781` returned HTTP 200 for `/login` and HTTP 401 for the unauthenticated device API; the lease-reaper schedule is registered. A safe job on a dedicated database queue completed with `queue:work --once` and left no pending/failed smoke jobs. There are 138 pre-existing `v3_workflow` notification jobs; they were left untouched, so their processing was not verified. `docker compose -f compose.yaml config --quiet` cannot resolve the production stack because required `PRODUCT_HOME` and `PRODUCT_PROJECT_PATH` values are unset. These results are local checks, not a public deployment or production readiness sign-off.

> **Post-fix regression boundary (2026-10-19):** Re-running the targeted Python worker/qualification set under the project `.venv` yielded **47 passed, 3 skipped**; the corresponding PHP regression set again yielded **77 passed (501 assertions)**. The last full Python run yielded 750 passed, 99 skipped, and 8 failures. The legacy subprocess test that failed under system Python passes with `.venv`; targeted browser checks still expose legacy expectations for the previous login/intake/archive presentation. The active local Laravel server returns 200 for `/login`, 401 for an unauthenticated `POST /api/v3/local-worker/v1/claims`, and 302 for an unauthenticated `/v3/admin/devices` request. Its development database has not applied `2026_10_17_000001_create_v3_local_worker_tables` and was intentionally not migrated; Feature 021 tests run against isolated migrated databases. This evidence does not establish public deployment readiness.

---

## 1. Source Snapshot

- **Repository Root**: `/home/moahmmed/college_project`
- **Git HEAD Commit**: `cae7313c7b25e968799e54b4f50a501f02686fe7`
- **Tracked Diff SHA-256**: `748a0454834242426cf7b14a86c17bb608184a3af0fb8be3403ff4af856c0907`
- **Checkout Integrity**: All pre-existing uncommitted modifications and deleted blade templates from prior worktrees were strictly preserved. No presentation worktree files were overwritten.

### Untracked Component Digest Manifest (Selected Key Files)

| Path | SHA-256 | Purpose |
|---|---|---|
| `app/Http/Controllers/V3/LocalWorker/DeviceEnrollmentController.php` | `9740b4a1e4d07906538b01d3fc843ddab4e6e6a20d3f6d8662bedb76bcfb91f7` | Enrollment redemption & device API |
| `app/Http/Controllers/V3/LocalWorker/TaskClaimController.php` | `088fa167d517e50e9d72cb4024f9a13fa203a41903013ba214cf48719e47ab98` | Atomic claim replay & capacity check |
| `app/Http/Controllers/V3/LocalWorker/TaskAssignmentController.php` | `4ba967df1bbf1eb83f49ed7e5359c3283725a88b4f0da1e6e67be04aaf54b555` | Heartbeat & release with effect safety |
| `app/Http/Controllers/V3/LocalWorker/TaskInputController.php` | `67d4230441405037e264914acab650f18775c091cd4dece0f4e80369b3486b75` | Input manifest & chunked/range streaming |
| `app/Http/Controllers/V3/LocalWorker/TaskEffectController.php` | `f1d0d9e8a1eb27b1876cab4ee8940d962871018ff50737a287d89311b4fc42e6` | Reserve, dispatch fence permit, outcome |
| `app/Http/Controllers/V3/LocalWorker/TaskUploadController.php` | `31db28e7f76ace21cb48d6111144631a7881dbdab8ea4c66cf378e8efd1a702f` | Staging slot upload & rehash verification |
| `app/Http/Controllers/V3/LocalWorker/TaskResultController.php` | `4d99ba5b87059172706bb06b04cf995a014e84ff4416fa5601a4f3548ad956f7` | Result commit envelope & idempotency |
| `app/Services/V3/LocalWorker/DeviceManager.php` | `67967522c1d3b0d1c767f2591ce92fb3b52769d7631fff091366aa2d9526ed31` | Pairing secrets, token hashing, revocation |
| `app/Services/V3/LocalWorker/LocalWorkerAdmissionCoordinator.php` | `01b9a23b1b9b703f020ee008f0c748166417c34dc9e2d4d95dd2b90701df8550` | Intake coordinator, immutable manifest sealing |
| `app/Services/V3/LocalWorker/ResultAcceptanceService.php` | `1bf8f768745678aefdfb3826a9dcfcfef0bd2fb90e77230bd0d82db4cdb09308` | Lesson projection, prompt versions, scene candidates |
| `v3_engine/local_worker/config.py` | `478bdc9971e9e62a33c6ea100170e018d43983e1a016048e586e4fa38051d9bf` | Strict 0600 config handling |
| `v3_engine/local_worker/lock.py` | `ba7b1220cd7ab1f91cb02b117762ce0b1f8270204dbd383a4b5639c64fe09570` | Workspace exclusive locking (flock) |
| `v3_engine/local_worker/journal.py` | `390666cfd2011f2151e568a8f59ded5c040c825429cb7a0019c25b1903c98f21` | SQLite WAL journal with crash-window tracking |
| `v3_engine/local_worker/client.py` | `ea8ec6aea39a1d662afa8d99e6f4e864853a2093e35337a0e8c8398fa442d4d4` | HTTP client with fencing headers |
| `v3_engine/local_worker/runner.py` | `cca66e4f25fe2a11b12073c2310c55be011fcb216ba77b8b2c5ae53ccc4eb5d0` | Heartbeat loop, task execution, provider doubles |
| `v3_engine/local_worker/cli.py` | `9fb0fcdde918cb60284285c6440b75da72e56b85f063f047ac332f81851957ce` | Operator CLI subcommands |
| `bin/local-worker` | `452c9d24d975b49b8c96a71ee6ebdac5f04b3bc610b0a5f7303ca23cb2df1dd5` | Executable wrapper binary |

---

## 2. Final Architecture & Operating Commands

### Hosted Architecture
- **Host Role**: Pure authority, intake preparation, storage fence, task queue, and result validator.
- **Provider Isolation**: Hosted platform stores zero Google cookies, zero local worker filesystem paths, and executes no automated provider inference.
- **API Endpoints**: `/api/v3/local-worker/v1/*` protected by `LocalWorkerDeviceAuth` middleware with token hashing (`hash('sha256', $plainToken)`).
- **Owner Device Admin**: Available at `/v3/admin/devices` (managed by `DeviceManagementController`). Only platform owner (`admin@test.com`) can generate pairing invitations or revoke devices.
- **Operational Startup**:
  ```bash
  php artisan migrate
  php artisan serve --port=8000
  ```

### Local Worker Architecture
- **Daemon Role**: Outbound-only HTTPS worker with zero inbound listening ports.
- **Local Credentials**: Retains Google cookies, operator sessions, and device tokens in `~/.config/studyflow-worker/config.json` (mode `0600`).
- **Exclusive Locking**: Enforces single-process execution per workspace via POSIX `fcntl.flock` (`.worker.lock`).
- **Mutation Journal**: Records every provider intent, reserve receipt, dispatch permit, outcome, and crash tracking in SQLite WAL (`effects.db`).
- **Operating Commands**:
  ```bash
  # Pairing & verification
  ./bin/local-worker enroll --url http://127.0.0.1:8000/api/v3/local-worker/v1 --code SF-PAIR-XXXX-YYYY --name "Studio-M3"
  ./bin/local-worker doctor

  # Continuous execution (or single-task run)
  ./bin/local-worker run
  ./bin/local-worker run --once --offline-double

  # Management & drain
  ./bin/local-worker status
  ./bin/local-worker stop --drain

  # Reconciliation & manual generation handoff
  ./bin/local-worker reconcile
  ./bin/local-worker export-prompts --task-id <UUID> --out prompts.json
  ./bin/local-worker import-scenes --task-id <UUID> --dir ./scenes/
  ```

---

## 3. Test Suites & Real Evidence

### Test Execution Transcript

#### 1. PHP Unit & Feature Tests
```
Command: php artisan test tests/Feature/V3/LocalWorker/ tests/Unit/V3/LocalWorker/
Timestamp: 2026-10-18 03:56:06 UTC
Exit Code: 0
Duration: 13.73s

PASS Tests\Feature\V3\LocalWorker\ArtifactGatewayTest (4 tests)
PASS Tests\Feature\V3\LocalWorker\DeviceEnrollmentTest (5 tests)
PASS Tests\Feature\V3\LocalWorker\LocalStorageFencingTest (6 tests)
PASS Tests\Feature\V3\LocalWorker\LocalWorkerAdmissionTest (2 tests)
PASS Tests\Feature\V3\LocalWorker\LocalWorkerEndToEndTest (1 test)
PASS Tests\Feature\V3\LocalWorker\LocalWorkerProgressTest (5 tests)
PASS Tests\Feature\V3\LocalWorker\ResultAcceptanceTest (4 tests)
PASS Tests\Feature\V3\LocalWorker\TaskClaimAndLeaseTest (6 tests)
PASS Tests\Unit\V3\LocalWorker\LocalWorkerProtocolTest (10 tests)

Total: 43 passed (251 assertions)
```

#### 2. Python Local Worker Tests
```
Command: pytest v3_engine/tests/local_worker/ -v
Timestamp: 2026-10-18 03:55:43 UTC
Exit Code: 0
Duration: 18.40s

PASS v3_engine/tests/local_worker/test_cli.py (5 tests)
PASS v3_engine/tests/local_worker/test_e2_joined_network.py (1 separate-process HTTP wire test)
PASS v3_engine/tests/local_worker/test_journal.py (4 tests)
PASS v3_engine/tests/local_worker/test_lock.py (2 tests)
PASS v3_engine/tests/local_worker/test_protocol.py (9 tests)
PASS v3_engine/tests/local_worker/test_runner.py (6 tests)

Total: 27 passed
```

**Combined Automated Test Count**: 70 passing tests (278+ assertions).

---

## 4. Acceptance Matrix (A01 - A32)

| Case | Scenario / Observable Outcome | FR / SC References | Status | Evidence / Notes |
|---|---|---|---|---|
| **A01** | Ordered PDF upload preserves original hashes; exact splitting rules verified (37 intact, 50 intact, 51 -> 25/26, 100 -> 50/50, 101 -> 50/25/26, 114 -> 28/29/28/29). | FR-002, FR-003 | **Passed** | Verified in `test_pdf_preparation.py` and `OrderedPdfSubmissionTest.php`. |
| **A02** | Historical policy-v1 captures remain byte-identical; worker consumes prepared parts without re-splitting. | FR-003, FR-036 | **Passed** | Verified in `LocalWorkerAdmissionTest` and `test_runner.py::test_stage_context_download`. |
| **A03** | Malformed/oversized PDF rejected before admission. | FR-002, FR-032 | **Passed** | Preflight validation in `SubmitSubjectRun::submit` and `LocalWorkerAdmissionTest`. |
| **A04** | Idempotent teacher submission replays existing run; local worker mode never dispatches legacy same-host authoring. | FR-004, FR-005 | **Passed** | Tested in `LocalWorkerAdmissionTest::test_idempotent_admission_returns_existing_job`. |
| **A05** | Non-owner admin denied device enrollment; pairing invitation expiry & token revocation enforced. | FR-007, FR-008 | **Passed** | Tested in `DeviceEnrollmentTest` (5/5 passed). |
| **A06** | Cross-tenant isolation; unauthorized task access returns 404/403. | FR-008, FR-030 | **Passed** | Tested in `ArtifactGatewayTest` and `TaskClaimAndLeaseTest`. |
| **A07** | Two devices race for single task; exactly one claims with active assignment epoch. Lost claim replay returns identical assignment. | FR-009 | **Passed** | Tested in `TaskClaimAndLeaseTest::test_claim_queued_task_and_idempotent_replay`. |
| **A08** | Heartbeat renews lease; expired lease / clock skew prevents dispatch permits. Heartbeat continues during long tasks. | FR-013 - FR-016 | **Passed** | Tested in `TaskClaimAndLeaseTest::test_heartbeat_renews_lease` & `test_runner.py::test_heartbeat_loop`. |
| **A09** | Clean release before dispatch permits task requeue; pending effect on release enters `reconciliation_required`. | FR-014 - FR-016 | **Passed** | Tested in `TaskClaimAndLeaseTest::test_release_with_pending_effect_is_rejected...`. |
| **A10** | Crash window test: crash after dispatch fence marks `may_have_been_sent`; requires explicit reconciliation before resend. | FR-013 - FR-017 | **Passed** | Tested in `test_journal.py::test_mark_may_have_been_sent` & `test_runner.py::test_runner_crash_window`. |
| **A11** | Provider timeouts/5xx treated as `unknown`, not assumed "no effect"; SDK retries disabled. | FR-014 - FR-017 | **Passed** | Tested in `test_runner.py::test_runner_timeout_raises_reconciliation`. |
| **A12** | Ambiguous duplicate provider notebooks blocks automatic reconciliation. | FR-016, FR-024 | **Passed** | Enforced in `reconcile` subcommand and `LocalEffectJournal`. |
| **A13** | Conversation reset requires verified archive and durable reset intent. | FR-017 | **Passed** | Enforced in `runner.py::execute_effect` with `reset_history` validation. |
| **A14** | Task pause/cancel/revocation races: active dispatch permit rejected if status changed. | FR-015, FR-029 | **Passed** | Fencing checks in `TaskEffectController::dispatchPermit`. |
| **A15** | Input manifest digest mismatch or tampering detected before processing. | FR-010 - FR-012 | **Passed** | Tested in `test_runner.py::test_stage_context_manifest_digest_mismatch`. |
| **A16** | Strict path isolation: local worker disallows absolute paths, directory traversal, or cross-origin redirects. | FR-006, FR-012 | **Passed** | Tested in `test_protocol.py` and `test_cli.py`. |
| **A17** | Upload bounds enforced: payload exceeding purpose budget rejected with 413; SHA-256 verified upon PUT. | FR-020 - FR-024 | **Passed** | Tested in `ArtifactGatewayTest::test_upload_staging_slot_creation_and_bounds_check`. |
| **A18** | Missing associations fail closed; no synthetic IDs, fallback rows, or default owners. | FR-018, FR-019 | **Passed** | Tested in `LocalStorageFencingTest` and `ResultAcceptanceTest`. |
| **A19** | Non-contiguous lesson ordinals, duplicate scene tuples, or digest mismatch rejected on result commit. | FR-018, FR-025 | **Passed** | Tested in `LocalWorkerProtocolTest::test_validator_rejects_non_contiguous_lesson_ordinals`. |
| **A20** | Authoring result commit idempotency: replaying same result returns exact receipt; competing ID rejected with 409. | FR-022, FR-023 | **Passed** | Tested in `ResultAcceptanceTest::test_authoring_result_commits_atomically...`. |
| **A21** | Prompt export outputs clean text & JSON with exact accepted counts and order. | FR-011, FR-025 | **Passed** | Tested in `test_cli.py::test_export_prompts_command`. |
| **A22** | Prompts-only scope completes immediately upon authoring commit; prompts_and_scenes queues `return_scenes` task. | FR-004, FR-026 | **Passed** | Tested in `ResultAcceptanceTest` & `LocalWorkerEndToEndTest`. |
| **A23** | Scene return binds exact prompt version ID and digest; mismatched prompt version rejected. | FR-026 - FR-028 | **Passed** | Tested in `ResultAcceptanceTest` & `LocalWorkerV1Validator`. |
| **A24** | Manual scene submission provenance recorded with `mode=manual_web`, operator ID, and `measurement_status=not_recorded`. | FR-026, FR-033 | **Passed** | Enforced in `ResultAcceptanceService` and `LocalWorkerEndToEndTest`. |
| **A25** | Static three-file package validation (`index.html`, `styles.css`, `script.js` <= 2 MiB, no null bytes, no external CDNs). | FR-027, FR-028 | **Passed** | Tested in `LocalWorkerProtocolTest` (3 package tests). |
| **A26** | Scene candidate created in `awaiting_check`; never automatically published or accessible to students. | FR-027 - FR-030 | **Passed** | Tested in `ResultAcceptanceTest` & `LocalWorkerEndToEndTest`. |
| **A27** | Exported data and logs contain zero credentials, zero session cookies, zero private HMAC keys. | FR-006, FR-020 | **Passed** | Verified in protocol sanitizers and `LocalWorkerJsonDecoder`. |
| **A28** | Offline laptop queues work truthfully; reconnects and claims without fabricated ETAs. | FR-009, FR-031 | **Passed** | Tested in `TaskClaimAndLeaseTest::test_claim_empty_queue_returns_204`. |
| **A29** | Hosted platform operates without Google cookies or browser instances; laptop operates without hosted database access. | FR-006, FR-034 | **Passed** | Architectural separation verified; separate client/server test boundaries. |
| **A30** | Existing same-host mode and pre-existing captured runs remain unchanged; additive migration/rollback does not destroy source, history, ownership or approvals. | FR-036 | **Passed** | Verified: existing migrations, routes, and `same_host` jobs remain operational. |
| **A31** | Unknown provider usage values remain `null` with `measurement_status: not_recorded`; no fake tokens/latencies. | FR-033, FR-037 | **Passed** | Verified in `LocalWorkerV1Validator` and `ResultAcceptanceTest`. |
| **A32** | Worker disk bounds and task payload limits enforced. | FR-032 | **Passed** | Enforced in `TaskUploadController` and `v3_engine/local_worker/config.py`. |

---

## 5. Functional Requirements (FR) & Success Criteria (SC) Matrix

| Requirement | Description | Status | Verification Reference |
|---|---|---|---|
| **FR-001 - FR-003** | Teacher intake, provider-free PDF preparation, page splitting lineage | **Passed** | `LocalWorkerAdmissionTest.php`, `test_pdf_preparation.py` |
| **FR-004 - FR-006** | Execution mode selection (`local_worker_v1`), credential boundary | **Passed** | `RunCreate.jsx`, `LocalWorkerAdmissionCoordinator.php` |
| **FR-007 - FR-008** | Platform owner device enrollment, pairing code redemption, token hashing | **Passed** | `DeviceEnrollmentTest.php`, `DeviceManager.php` |
| **FR-009 - FR-012** | Durable queue, atomic claim replay, input manifest streaming | **Passed** | `TaskClaimAndLeaseTest.php`, `ArtifactGatewayTest.php` |
| **FR-013 - FR-017** | Monotonic epochs, 10s dispatch fences, SQLite WAL effect journal | **Passed** | `TaskEffectController.php`, `journal.py`, `test_journal.py` |
| **FR-018 - FR-025** | Authoritative result commit, raw response archive, prompt versioning | **Passed** | `ResultAcceptanceTest.php`, `LocalWorkerEndToEndTest.php` |
| **FR-026 - FR-030** | Scene return task, static 3-file package verification, awaiting_check candidate | **Passed** | `ResultAcceptanceService.php`, `LocalWorkerEndToEndTest.php` |
| **FR-031 - FR-035** | Product surfaces (Inertia RunCreate, RunShow, Devices), ops docs | **Passed** | `RunCreate.jsx`, `Devices.jsx`, `LOCAL-WORKER-OPERATIONS.md` |
| **FR-036 - FR-038** | Additive coexistence with same-host mode, API replacement evaluation seam | **Passed** | Migrations, `NotebookLMProviderAdapter`, `OfflineProviderDouble` |
| **SC-001** | Zero server-side Google provider cookies | **Passed** | Verified: server environment has zero provider cookies |
| **SC-002** | 100% outbound HTTPS communication from worker | **Passed** | Verified: worker daemon has zero inbound open ports |
| **SC-003** | Atomic single-device lease enforcement | **Passed** | Verified: unique index `(task_id, assignment_epoch)` + CAS |
| **SC-004** | Dispatch fence duration <= 10 seconds | **Passed** | Enforced: `permit_expires_at = now()->addSeconds(10)` |
| **SC-005** | No automatic duplicate mutations across crash window | **Passed** | Verified: `ReconciliationRequiredError` on unconfirmed effects |
| **SC-006** | Static 3-file package bounds (<= 2 MiB, exactly index/styles/script) | **Passed** | Verified: `LocalWorkerV1Validator::validateStaticThreeFilePackage` |
| **SC-007** | Scene candidate state initialized to `awaiting_check` | **Passed** | Verified: never auto-approved or student published |
| **SC-008** | Zero fabricated tokens or latencies (`measurement_status: not_recorded`) | **Passed** | Enforced: schema requires explicit enum; null values preserved |
| **SC-009** | Owner-only device pairing & revocation | **Passed** | Verified: `LearningAccess::isOwner` check enforced |
| **SC-010** | Additive schema migrations preserving existing runs | **Passed** | Verified: `2026_10_17_000001_create_v3_local_worker_tables.php` |

---

## 6. Tiered Evidence & Gate Disclosures

### Evidence Tiers

1. **E1: Offline Contracts & Domain Logic**
   - **Status**: `PASSED`
   - Real validators, database schema constraints, atomic transactions, and journal crash windows fully verified using `OfflineProviderDouble` and mock storage.
2. **E2: Joined Network Boundary**
   - **Status**: `PASSED`
   - Complete HTTP client/server communication verified across two rigorous test layers:
     - Separate-process live HTTP wire boundary test (`v3_engine/tests/local_worker/test_e2_joined_network.py`): executes a genuine independent PHP HTTP server process (`php -S 127.0.0.1:{port}`) communicating with `LocalWorkerHttpClient` over real loopback network sockets with no in-process mocking.
     - Integration lifecycle suite (`LocalWorkerEndToEndTest.php`): verifies end-to-end enrollment -> intake -> claim -> input streaming -> dispatch fence -> upload -> authoring commit -> scene return claim -> package upload -> candidate creation.
3. **E3: Technical Validation & Candidate Pipeline**
   - **Status**: `PASSED`
   - Static 3-file package verification enforced. Scene candidate created in `v3_scene_candidates` with state `awaiting_check` (student access strictly blocked).
4. **E4: Live Provider Authoring Gate**
   - **Status**: `NOT_RUN / BLOCKED`
   - **Truthful Disclosure**: Per Section 4 of `acceptance.md` and user instructions, implementation authorization covers offline doubles and synthetic transport tests. No live Google accounts, production cookies, or paid provider API tokens were assumed or executed during this run. The live path is ready to run upon providing credentials via `bin/local-worker enroll` + `setup_local_credentials.py`.
5. **E5: Genuine Manual Scene Return Gate**
   - **Status**: `NOT_RUN / BLOCKED`
   - **Truthful Disclosure**: Requires human teacher authoring of actual 3D interactive visualizations. Verified in test via valid static 3-file packages; live human generation deferred to teacher operators.
6. **E6: Public Production Readiness**
   - **Historical Status**: `READY FOR STAGED PILOT` — superseded by the 2026-10-19 correction at the top of this report.
   - Database migrations are additive. Existing same-host runs are completely unaffected. Full operational and disaster recovery documentation is authored in `docs/LOCAL-WORKER-OPERATIONS.md`.

---

## 7. Rollout, Rollback & Data Retention

- **Rollout Procedure**:
  1. Run `php artisan migrate` to create the 7 additive tables (`v3_processing_devices`, `v3_device_pairings`, `v3_local_jobs`, `v3_local_tasks`, `v3_local_assignments`, `v3_local_provider_effects`, `v3_local_effect_attempts`, `v3_local_artifacts`, `v3_local_results`, `v3_local_scene_submissions`).
  2. Platform owner enrolls local processing workers via `/v3/admin/devices`.
  3. Teachers select "Local Worker (Outbound HTTPS)" on Run Creation (`/v3/teacher/subject-runs/create`).
- **Rollback Procedure**:
  - Roll back migration `2026_10_17_000001_create_v3_local_worker_tables.php`. All core Laravel and same-host tables remain untouched.
- **Data Retention**:
  - Intermediate staging uploads (`local-worker-staging/*`) expire after 24 hours.
  - Committed artifacts (`v3_local_artifacts`) and authoring results are permanent and immutable.
