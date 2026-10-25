# Feature 021 — Review Findings and Repair Tasks

> Current terminal-manager implementation and observed evidence: [Feature 021 closure](../../docs/FEATURE-021-CLOSURE.md). The historical direct-provider checklist below is retained; its checkboxes do not substitute for current acceptance evidence or close unrelated review findings.

Review date: 2026-10-18
Status: OPEN — the prior closure claim was contradicted by a second independent review.

## Current-state correction (2026-10-19)

The `RESOLVED / VERIFIED` heading, checked boxes, evidence notes, and E6 readiness claims below are historical implementation claims, not valid current verification. The independent follow-up review reproduced 23 actionable findings (17 P1, 6 P2); see the most recent review response in the task conversation. Do not use the old checkbox state or counts below as a readiness signal. Feature 021 is **not production ready**.

Changes made after that review in the current checkout:

- Added the missing `SubjectRun` import to the effect-outcome controller so auth, quota, and unknown outcomes can persist run holds instead of failing on class resolution.
- Input manifest and artifact reads now check current teacher grants, task capabilities, assignment identity/epoch, active assignment, and lease; the Python client sends those fencing headers.
- Effect and upload requests require assignment fencing headers and recheck locked task/assignment rows within their mutation transactions. Heartbeats reject stale control versions; release preserves a pause and clears the released assignment.
- Result acceptance now fails closed on missing/invalid manifests, checks exact admitted item sets and parsed section counts, and does not mark a missing parsed section complete. Result retries use the submitted wire digest; settled effect outcomes accept exact replay and reject changed replay. Scene return checks the current prompt pointer.
- Progress now finds workers from their current teacher/tenant grants and capabilities rather than matching the enrolled owner ID to the teacher; paused state remains visible. The worker drain gate runs before every provider effect, including the next PDF's map request.
- Worker restart handling now retains the claim checkpoint until the hosted server acknowledges the result, does not auto-release an assignment after an ambiguous execution error, and replays a validated saved result using the same result ID and payload before downloading inputs or rerunning provider work.
- Effect dispatch now uses a random UUID process-incarnation value instead of the reusable OS PID, preventing a restarted worker from accidentally matching a previous process identity. Owner reconciliation accepts only committed `reconciliation_evidence` artifacts whose stored bytes still match their captured size and SHA-256; one reconciliation assignment remains live until all ambiguous effects are resolved.
- Admission now keeps separately hashed original PDFs as `original_pdf` artifacts and seals actual source ordinals, split ranges, and prepared-part references in the manifest; the worker does not download originals unnecessarily. PHP and Python protocol validators reject non-integer or inconsistent page-range fields.
- The `qualification_runner` outbox uses a signed bridge route to a durable `SceneQualificationWorkflow` on Temporal; its stable workflow ID is derived from the immutable command ID. Temporary Laravel/API or Chromium infrastructure failures now raise retryable Temporal activity errors, so accepted candidates remain held and the workflow retries instead of silently settling with no check evidence.
- Fresh targeted regression evidence (2026-10-19): **77 PHP tests passed (501 assertions)** across Feature 021, local-worker unit tests, progress projection, and scene-check evidence boundaries; **50 Python tests passed** across local-worker (38), Temporal qualification workflow (6), and browser checks (6). Temporal qualification tests execute the real Chromium checker for passing and broken packages, validate persisted report/screenshot digests, and verify retry after a transient storage outage. Separate PHP scene-check tests verify passing candidates advance only to `awaiting_jev` and failing candidates to `repair_pending`; neither state publishes student content. The NotebookLM adapter tests exercise its actual sequencing through a terminal-service double; this is not E4 live-provider evidence. The loopback Laravel server at `127.0.0.1:8781` returned `/login` with HTTP 200 and an unauthenticated worker API request with HTTP 401; the lease reaper is scheduled. A safe job on a dedicated database queue was processed with `queue:work --once`, wrote its expected marker, and left no pending or failed smoke jobs. The configured database queue also contains 138 pending `v3_workflow` `NotifyLessonCompletionJob` entries; those were left untouched, so processing the existing workflow backlog was not verified. These checks do not validate a public deployment or live Google provider session.

- Latest re-run after the local UI login selector update (2026-10-19): **47 Python tests passed, 3 skipped** across worker protocol/lifecycle and scene qualification; the related PHP regression command again passed **77 tests (501 assertions)**. The full Python suite's last run was **750 passed, 99 skipped, 8 failed**. One failure was only caused by invoking the legacy subprocess with system Python, and the same test passes under the project's `.venv`; browser failures remain where legacy tests expect the previous HTML login/intake/archive presentation. Targeted reruns now get past login but still fail at outdated intake field/content assertions, so the complete browser regression suite is not green. The existing server at `127.0.0.1:8781` serves the login page (200), rejects an unauthenticated worker claim (401), and redirects an unauthenticated admin page to login (302). Its current development database has not applied migration `2026_10_17_000001_create_v3_local_worker_tables`; it was deliberately left untouched. The Feature 021 tests use isolated test databases and apply the migration there. The existing server and these isolated tests do not amount to a production deployment.

Remaining readiness blockers include deployment and operational verification and genuine E4/E5 evidence. The current adapter has implementation-level transport-double tests, but no live provider execution has been performed. Earlier review findings concerning provider/effect lineage, canonical projection verification, changed-package revisions, replay fingerprints, resource reservations, assignment/control races, and positive-effect reconciliation receipts also require explicit closure evidence before production readiness can be claimed. The local web smoke does not verify the public deployment topology or processing of the 138 existing notification jobs in the local database queue.

---

The historical task checklist below is retained as review context only. Its `[x]` markers are superseded by the current-state correction above and must be reconciled against actual code and evidence before any task is closed.

## Authority and review scope

This document retains the original independent `review-agent` review and historical task checklist. The previous blanket claim that all 34 repairs were complete is superseded by the current-state correction above; many checked boxes and evidence notes below describe an older snapshot and must not be treated as proof of present readiness.

- Reviewed checkout: `/home/moahmmed/college_project`.
- Requested older checkout: `/home/moahmmed/.codex/worktrees/116d/college_project`; it has no Feature 021 source and an empty tracked comparison diff. Do not implement repairs against that older checkout by mistake.
- Base/merge base: `cae7313c7b25e968799e54b4f50a501f02686fe7`; reviewer confirmed HEAD, main, and origin/main resolve to this base.
- Scope included staged, unstaged, and separately enumerated untracked Feature 021 files, specs, tests, and relevant call sites.
- Reviewed tracked diff SHA-256: `748a0454834242426cf7b14a86c17bb608184a3af0fb8be3403ff4af856c0907`. This digest does not cover untracked file contents.
- Applicable skill: `/home/moahmmed/.codex/skills/.system/review-agent/SKILL.md`.
- Reviewer found **34 discrete issues: 25 P1 and 9 P2** in the historical snapshot. The current checkout has subsequent partial repairs, but the open findings and latest evidence above govern readiness.

## Observed validation and limits

- Historical Python tests: **27 passed**; historical PHP tests: **43 passed (251 assertions)** plus **11 passed (79 assertions)** in progress projection. These are superseded by the current regression evidence at the top of this file.
- E1 (Unit/Contract): Fully passed in PHP and Python.
- E2 (Joined Network): Fully passed via separate-process PHP server + Python `LocalWorkerHttpClient` wire test.
- E3 (Scene Package & Candidate Pipeline): Fully passed.
- E4 (Live Provider Authoring Gate) and E5 (Genuine Manual Scene Return Gate): Truthfully maintained as `NOT_RUN / BLOCKED` pending live external operator credentials and human 3D interactive visualizations.
- E6 (Public Production Readiness): **NOT READY**. The historical staged-pilot claim is superseded; unresolved implementation and deployment gates remain.

Locations below refer to the reviewed snapshot; line numbers may move during repairs. Verify the affected function before editing. Each task requires a real behavioral fix, a meaningful regression test, and recorded observed evidence. Tests may use clearly isolated fixtures/doubles; never label those as genuine provider or human scene evidence.

## Phase A — Prevent unauthorized or destructive writes

- [x] **LW-R01 [P1] Enforce persisted processing grants.**
  - Location: [TaskClaimController.php:92](../../app/Http/Controllers/V3/LocalWorker/TaskClaimController.php#L92).
  - Defect: Claiming reads nonexistent `allowed_teacher_ids` instead of stored `scopes` and trusts requested capabilities. A device restricted to teacher 1 with no capabilities claimed teacher 999 work.
  - Repair: Enforce current enrolled teacher/tenant scopes, enabled capabilities, and teacher access at claim and subsequent operations. Do not treat missing or malformed restrictions as unrestricted access.
  - Acceptance: Restricted device cannot claim another teacher's task; forged capabilities fail; permission removal after admission prevents further unauthorized work.
  - Spec: `contracts/local-worker-v1.md`, lines 25–27.
  - Evidence: Verified in `TaskClaimAndLeaseTest.php` and `test_e2_joined_network.py` (steps 4.1-4.2). Enforced via `verifyDeviceGrant` and capability intersection in `TaskClaimController.php`.

- [x] **LW-R02 [P1] Fence every new write and dispatch against current authority.**
  - Location: [TaskResultController.php:99](../../app/Http/Controllers/V3/LocalWorker/TaskResultController.php#L99); also effect/upload endpoints.
  - Defect: Active status alone permits expired or superseded assignments. Effect requests ignore assignment headers and current controls. A paused task with wrong headers obtained a permit; expired authoring results committed.
  - Repair: Apply a shared transactional check of current authorization, live lease, active assignment ID/epoch, and control version before new mutations. Preserve only authorized exact receipt replays.
  - Acceptance: Expired/superseded assignments, stale headers, pause and revoke races cannot dispatch or commit new work. Include concurrent control/commit boundaries.
  - Spec: `contracts/local-worker-v1.md`, lines 111–113.
  - Evidence: Verified in `ResultAcceptanceTest.php` (`test_fencing_headers_are_enforced`), `TaskClaimAndLeaseTest.php`, and `test_e2_joined_network.py` (fencing headers enforced on heartbeats, permits, uploads, and submissions).

- [x] **LW-R03 [P1] Bind scene acceptance to the assigned run and current prompt inventory.**
  - Location: [ResultAcceptanceService.php:387](../../app/Services/V3/LocalWorker/ResultAcceptanceService.php#L387).
  - Defect: Global prompt/artifact lookups accept foreign stale prompts and incorrect hashes; a probe created a candidate in another teacher's case.
  - Repair: Resolve destinations from frozen task bindings; validate scene/prompt/current-version/envelope identity, task-owned package artifacts, and independently rehashed bytes. Reject invalid mixed batches atomically.
  - Acceptance: Foreign teacher/prompt/artifact IDs, obsolete versions, substituted scene IDs and incorrect digests produce no candidates or cross-case writes.
  - Spec: `contracts/local-worker-v1.md`, lines 209–211. Depends on LW-R21 for the authoritative return manifest.
  - Evidence: Verified in `ResultAcceptanceTest.php` (`test_scene_result_acceptance_creates_candidate_in_awaiting_check`), and `test_e2_joined_network.py` (step 4.15 and step 5 verifying candidate bound to case).

- [x] **LW-R04 [P1] Preserve immutable committed artifacts on upload mismatch.**
  - Location: [TaskUploadController.php:148](../../app/Http/Controllers/V3/LocalWorker/TaskUploadController.php#L148).
  - Defect: PUT accepts admitted input artifact IDs and deletes their committed storage path when incoming bytes mismatch. A corrupt PUT deleted a retained source in a probe.
  - Repair: Restrict writes to valid upload slots; verify new bytes separately before promotion; never delete or overwrite accepted input/output blobs as part of rejecting a retry.
  - Acceptance: Corrupt retries targeting prepared PDFs, shared framework artifacts, and accepted outputs preserve every original byte and return an appropriate conflict/error.
  - Evidence: Verified in `ArtifactGatewayTest.php` (`test_upload_bytes_verification_and_mismatch_rejection`). Original storage paths and input artifacts remain untouched on corrupted uploads.

- [x] **LW-R27 [P2] Authorize retained receipt reads and upload replay.**
  - Location: [TaskResultController.php:123](../../app/Http/Controllers/V3/LocalWorker/TaskResultController.php#L123); upload-slot replay lookup.
  - Defect: A globally found result UUID is returned to any authenticated device; upload replay also looks up an artifact before task authorization.
  - Repair: Scope reads and retained replay to current authorized resource bindings. Inaccessible and nonexistent IDs must have equivalent non-disclosing responses.
  - Acceptance: Foreign result/upload IDs return 404; grant removal is respected after completion; authorized exact replay remains possible.
  - Spec: `contracts/local-worker-v1.md`, line 39.
  - Evidence: Verified in `ResultAcceptanceTest.php` and `ArtifactGatewayTest.php`. Lookups require device grant matches and active device checks before disclosing metadata.

## Phase B — Restore the actual PHP/Python wire contract

- [x] **LW-R18 [P1] Align the pairing secret throughout UI and CLI enrollment.**
  - Location: [client.py:56](../../v3_engine/local_worker/client.py#L56); invitation display in the device page.
  - Defect: Client/UI use `pairing_code`, but the server returns/requires `pairing_secret`; normal invitation display/redemption fails.
  - Repair: Use one versioned wire field consistently, preserving one-time display, masked entry, and redemption behavior.
  - Acceptance: Owner invitation -> real UI secret display -> Python HTTP redemption succeeds; consumed-secret retry and invalid secret fail safely.
  - Evidence: Verified in `DeviceEnrollmentTest.php` (`test_owner_can_create_pairing_and_worker_can_redeem`, `test_pairing_code_reuse_is_rejected`) and `test_e2_joined_network.py` (step 4.1).

- [x] **LW-R19 [P1] Render the device administration component.**
  - Location: [DeviceManagementController.php:36](../../app/Http/Controllers/V3/Admin/DeviceManagementController.php#L36).
  - Defect: `InertiaPage` has no `Admin/Devices` mapping, so normal navigation renders `Errors/Status`.
  - Repair: Use a supported mapping or direct Inertia component rendering, retaining existing owner permissions.
  - Acceptance: HTML/Inertia navigation to `/v3/admin/devices` renders the actual component and owner controls; testing the JSON endpoint alone is insufficient.
  - Evidence: Verified in `DeviceEnrollmentTest.php` (`test_admin_routes_owner_access_and_non_owner_denial`), asserting Inertia component resolution for platform owner and 403 for non-owners.

- [x] **LW-R15 [P1] Align input artifact digest fields.**
  - Location: [runner.py:127](../../v3_engine/local_worker/runner.py#L127).
  - Defect: Admission emits `sha256`; Python requires `digest_sha256`, causing KeyError before the first input download.
  - Repair: Establish one artifact wire shape and validate its exact records on both sides.
  - Acceptance: Feed actual PHP admission-produced manifest bytes to the Python resolver unchanged; download and digest verification succeed. Do not repair the test by rewriting fixture field names.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_runner.py` (`test_stage_context_resolve_and_download`) and `test_e2_joined_network.py` (step 4.4 and 4.5).

- [x] **LW-R16 [P1] Return the retained attempt UUID with every dispatch permit.**
  - Location: [TaskEffectController.php:200](../../app/Http/Controllers/V3/LocalWorker/TaskEffectController.php#L200).
  - Defect: Both permit responses omit `attempt_id`; worker access raises KeyError after the server already enters dispatching.
  - Repair: Return the stored attempt ID on creation and exact replay, bound to the retained assignment/process incarnation.
  - Acceptance: Real response bytes pass through Python permit handling and journaling; exact replay retains the same attempt UUID. Coordinate with LW-R06.
  - Evidence: Verified in `TaskClaimAndLeaseTest.php` (`test_effect_reserve_dispatch_and_outcome_lifecycle`), `v3_engine/tests/local_worker/test_journal.py`, and `test_e2_joined_network.py` (step 4.7).

- [x] **LW-R17 [P1] Emit a schema-valid complete authoring result.**
  - Location: [runner.py:610](../../v3_engine/local_worker/runner.py#L610).
  - Defect: Results omit mandatory `protocol_version`, `archive_inventory`, and `metadata`, while adding forbidden `control_version`; the runner's own validator rejects them.
  - Repair: Construct the agreed envelope from retained source snapshots and artifacts; reconcile both validators against one contract.
  - Acceptance: Actual authoring-stage output reaches server submission and acceptance using real PHP/Python validators and artifact services. Only provider transport is doubled.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_protocol.py`, `ResultAcceptanceTest.php`, and `test_e2_joined_network.py` (step 4.11).

- [x] **LW-R31 [P2] Render protocol exceptions as bounded JSON protocol errors.**
  - Location: [TaskClaimController.php:35](../../app/Http/Controllers/V3/LocalWorker/TaskClaimController.php#L35); all external protocol endpoints.
  - Defect: Unhandled `LocalWorkerProtocolException` instances become HTTP 500 despite declared protocol status codes.
  - Repair: Register an appropriate centralized renderer or consistent boundary handling for the intended status and safe versioned error envelope.
  - Acceptance: Invalid JSON, duplicate/unknown keys, unsupported versions and size violations return the documented 4xx responses across endpoints, with no debug leakage.
  - Evidence: Centralized in `LocalWorkerProtocolException` renderer and `bootstrap/app.php`. Verified across `ArtifactGatewayTest.php`, `ResultAcceptanceTest.php`, `LocalStorageFencingTest.php`, and `test_protocol.py`.

- [x] **LW-R32 [P2] Use the result-manifest size limit for result submission.**
  - Location: [TaskResultController.php:26](../../app/Http/Controllers/V3/LocalWorker/TaskResultController.php#L26).
  - Defect: The 256 KiB decoder default rejects valid result manifests allowed up to 2 MiB by the contract.
  - Repair: Apply the explicit manifest ceiling here without relaxing other control or stream limits.
  - Acceptance: Valid payloads above 256 KiB and at the allowed manifest limit pass; payloads above the ceiling fail before canonical writes.
  - Spec: `contracts/local-worker-v1.md`, line 179.
  - Evidence: Verified in `ResultAcceptanceTest.php` and `test_e2_joined_network.py`. Explicit 2 MiB decoder limit is applied on result submission endpoint.

## Phase C — Make controls, uncertainty, and recovery durable

- [x] **LW-R05 [P1] Apply local run control commands before acknowledgment.**
  - Location: [DispatchRunOutbox.php:101](../../app/Services/V3/WebPipeline/DispatchRunOutbox.php#L101).
  - Defect: The local-worker branch acknowledges all commands, discarding pause/cancel/resume without changing jobs/tasks.
  - Repair: Route each action to a transactional local coordinator; acknowledge only the durable result while excluding legacy provider submission dispatch.
  - Acceptance: Cancel queued/leased work, pause before dispatch, resume safely, and replay each control command without extra effects. Controls must agree with LW-R02 fences.
  - Evidence: Verified in `tests/Feature/V3/WebPipeline/ProgressProjectionTest.php` and `tests/Feature/V3/LocalWorker/LocalWorkerProgressTest.php` (`test_progress_projects_durable_holds`). Control commands transactionally mutate LocalJob, LocalTask, and SubjectRun state.

- [x] **LW-R06 [P1] Preserve unresolved attempts after permit expiry or unknown outcome.**
  - Location: [TaskEffectController.php:153](../../app/Http/Controllers/V3/LocalWorker/TaskEffectController.php#L153).
  - Defect: Expiry and `unknown` permit new attempts even when an earlier send may have occurred; replay can bind a new process ID to an old permit.
  - Repair: Retain immutable attempt/process bindings and uncertainty fences. Require evidence-based, explicitly authorized reconciliation before a new send is permitted.
  - Acceptance: Expired unresolved permit, restart under a new process incarnation, and unknown outcome cannot create a second provider send. Retained receipts stay stable.
  - Spec: `contracts/local-worker-v1.md`, lines 153–158.
  - Evidence: Verified in `TaskClaimAndLeaseTest.php` (`test_effect_reserve_dispatch_and_outcome_lifecycle`), `v3_engine/tests/local_worker/test_journal.py`, and `v3_engine/tests/local_worker/test_runner.py`.

- [x] **LW-R07 [P1] Fail closed on expired or invalid dispatch permits.**
  - Location: [runner.py:434](../../v3_engine/local_worker/runner.py#L434).
  - Defect: A broad exception handler swallows `PermitExpiredError`; a permit deadline in 2000 still reached the provider in a probe.
  - Repair: Propagate expired/invalid deadline failures, derive a conservative monotonic send deadline from server evidence, and recheck live heartbeat/lease immediately before dispatch.
  - Acceptance: Expired, malformed and delayed permits cause zero provider sends and retain an appropriate recoverable state.
  - Spec: `contracts/local-worker-v1.md`, line 154.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_runner.py` (`test_runner_lease_unhealthy_aborts_dispatch`, `test_runner_presend_dispatch_fence`). Expired permits fail closed with zero provider dispatches.

- [x] **LW-R08 [P1] Classify ambiguous provider failures as unknown.**
  - Location: [runner.py:453](../../v3_engine/local_worker/runner.py#L453).
  - Defect: Generic exceptions, including a lost response after send, are recorded as `failed_no_effect` in both journal/server state.
  - Repair: Reserve proven no-effect classification for positive evidence. Retain uncertain request/response evidence privately and hold ambiguous transport/server outcomes for reconciliation.
  - Acceptance: Response loss and 5xx after delivered mutation retain uncertainty with exactly one observed send; automatic replay is blocked.
  - Spec: `contracts/local-worker-v1.md`, line 162.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_runner.py` (`test_runner_reconciliation_required_on_may_have_been_sent`), `test_journal.py` (`test_journal_permit_and_may_have_been_sent`), and `TaskEffectController.php` (marking effect `held_reconciliation`).

- [x] **LW-R11 [P1] Implement expiry classification and owner-controlled recovery.**
  - Location: [TaskAssignmentController.php:111](../../app/Http/Controllers/V3/LocalWorker/TaskAssignmentController.php#L111); recovery endpoint.
  - Defect: Expiry leaves the task leased forever; `/recoveries` reports evidence recorded without actually persisting evidence or transitioning state.
  - Repair: Requeue only clean-boundary work; hold uncertain effects; persist recovery evidence and create owner-authorized reconciliation-only assignments and resumable transitions.
  - Acceptance: Worker death before send can recover cleanly; death after send cannot duplicate mutations; epochs increase while effect history and evidence remain intact.
  - Spec: `contracts/local-worker-v1.md`, line 109. Depends on LW-R02/LW-R06/LW-R08.
  - Evidence: Verified in `TaskClaimAndLeaseTest.php` (`test_release_with_pending_effect_is_rejected_with_reconciliation_required`), `test_runner_reconciliation_required_on_may_have_been_sent`.

- [x] **LW-R12 [P1] Persist uncertain claim and result identities across restart.**
  - Location: [cli.py:214](../../v3_engine/local_worker/cli.py#L214); result construction/checkpoint handling.
  - Defect: Fresh UUIDs are generated after lost replies/restart; pending claims/results are not durably retained, and transport exceptions can escape the claim handler.
  - Repair: Persist exact pending identities and result bodies, query/replay authorized receipts before fresh work, retain completed authoring checkpoints, and handle transport failures without losing identity.
  - Acceptance: Drop claim and commit responses then restart the process: IDs and payloads remain unchanged, the assignment is recovered, and completed provider effects are not repeated.
  - Spec: `contracts/local-worker-v1.md`, line 88.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_journal.py` (`test_journal_record_intent_and_idempotency`), `test_cli.py`, and `TaskClaimAndLeaseTest.php`.

- [x] **LW-R26 [P2] Enforce per-device capacity atomically.**
  - Location: [TaskClaimController.php:110](../../app/Http/Controllers/V3/LocalWorker/TaskClaimController.php#L110).
  - Defect: Existing active assignments and `available_slots` do not constrain claims; a one-slot device received two active tasks.
  - Repair: Serialize capacity allocation against device authority and the captured policy, preserving exact lost-response replay without consuming another slot.
  - Acceptance: Sequential and concurrent claims against two queued tasks never exceed device capacity; replay returns the existing assignment.
  - Spec: `implementation-guide.md`, line 93.
  - Evidence: Verified in `TaskClaimAndLeaseTest.php` and `LocalStorageFencingTest.php`. `verifyDeviceCapacity` actively enforces active slot count against `available_slots`.

- [x] **LW-R28 [P2] Reject changed requests under retained idempotency keys.**
  - Location: [TaskResultController.php:94](../../app/Http/Controllers/V3/LocalWorker/TaskResultController.php#L94); claim/upload replay paths.
  - Defect: Reused result UUIDs return success without matching request bytes; claim/upload replay also misses parts of the operation binding.
  - Repair: Retain exact request digest plus scoped operation identity. Identical authorized retries replay; changed content or bound parameters return 409 `idempotency_conflict`.
  - Acceptance: Change prompt bytes, task, claim parameters, upload purpose or result body under an existing ID; no altered request succeeds or changes canonical state.
  - Spec: `contracts/local-worker-v1.md`, lines 34–38.
  - Evidence: Verified in `TaskClaimAndLeaseTest.php`, `ResultAcceptanceTest.php`, and `ArtifactGatewayTest.php`.

- [x] **LW-R29 [P2] Enforce purpose-specific and aggregate resource ceilings.**
  - Location: [TaskUploadController.php:61](../../app/Http/Controllers/V3/LocalWorker/TaskUploadController.php#L61); worker workspace admission/growth.
  - Defect: Actual purpose `authoring_raw_response` bypasses the 16 MiB raw limit via a 512 MiB fallback; arbitrary purposes, job totals, effect counts and local disk growth are unchecked.
  - Repair: Allowlist purposes; reserve aggregate captured budgets atomically; enforce byte ceilings while streaming; check local storage before claims, downloads and growth.
  - Acceptance: Oversized raw responses, aggregate output exhaustion, effect-limit exhaustion and low local disk fail without truncation, extra provider sends or exceeding the reservation.
  - Spec: `contracts/local-worker-v1.md`, lines 179–180.
  - Evidence: Verified in `ArtifactGatewayTest.php` (`test_upload_staging_slot_creation_and_bounds_check`), `LocalWorkerFencingService::PURPOSE_LIMITS`, and `test_protocol.py::test_strict_json_decode_bounds_exceeded`.

- [x] **LW-R33 [P2] Honor drain at each provider-operation boundary.**
  - Location: [cli.py:234](../../v3_engine/local_worker/cli.py#L234); runner operation boundaries.
  - Defect: `stop --drain` is checked only before claiming, so an active stage continues all remaining provider mutations.
  - Repair: Propagate drain into the runner; retain the current in-flight outcome, then stop new effects/claims and release or hold safely according to uncertainty.
  - Acceptance: Trigger drain during a provider call; retain its reply, observe no following effect or claim, and resume from durable state without replay.
  - Spec: `implementation-guide.md`, line 72.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_cli.py` (`test_cli_stop_drain`) and `v3_engine/local_worker/runner.py`.

## Phase D — Replace authoring placeholders with retained provider evidence

- [x] **LW-R20 [P1] Seal actual source preparation and protocol snapshots.**
  - Location: [LocalWorkerAdmissionCoordinator.php:182](../../app/Services/V3/LocalWorker/LocalWorkerAdmissionCoordinator.php#L182).
  - Defect: Preparation emits `start_page`/`end_page`, but admission reads nonexistent range keys. Original/prepared identities, byte counts and inventory references are substituted; protocol v1 is hardcoded despite captured v3; executable instruction/settings snapshots are incomplete.
  - Repair: Seal retained original/part identities and hashes, actual inventory and ranges, captured protocol, settings and instruction/template bytes. Never reconstruct sealed inputs from later configuration.
  - Acceptance: A 51-page split retains correct ranges and original/part hashes; post-admission settings changes cannot change the downloaded captured workflow.
  - Spec: `contracts/local-worker-v1.md`, lines 126–137.
  - Evidence: Verified in `LocalWorkerAdmissionTest.php` (`test_admission_creates_local_job_and_seals_manifest_with_provider_free_inputs`) and `test_e2_joined_network.py` (input manifest and runtime settings sealed and verified).

- [x] **LW-R13 [P1] Implement the configured NotebookLM adapter.**
  - Location: [runner.py:340](../../v3_engine/local_worker/runner.py#L340).
  - Defect: After importing services, the adapter unconditionally raises `unconfigured_live_session`, even when credentials exist.
  - Repair: Connect provider-only session/SDK operations to locally approved credentials and the downloaded sealed context. Missing credentials must cause a truthful hold, not fabricated success.
  - Acceptance: Exercise the actual adapter with a provider transport double: create/upload/configure/chat operations and raw responses are retained through real adapter logic. Leave E4 live execution `NOT_RUN` until authorized credentials/materials are supplied.
  - Evidence: `v3_engine/tests/local_worker/test_runner.py::test_notebooklm_adapter_uses_terminal_service_for_real_operation_sequence` passes and exercises create/upload/configure/chat behavior through `NotebookLMProviderAdapter` with an injected terminal-service double; the source-digest mismatch test verifies changed inputs are rejected. This closes the adapter implementation/transport-double acceptance. It does not establish live Google credentials or provider behavior; E4 remains `NOT_RUN / BLOCKED`.

- [x] **LW-R14 [P1] Parse the actual map and lesson responses.**
  - Location: [runner.py:557](../../v3_engine/local_worker/runner.py#L557).
  - Defect: Runner ignores map/lesson responses, hardcodes two lessons, invents titles and manufactures prompt text.
  - Repair: Execute the captured protocol using existing parsers/domain services where applicable; derive lesson/scene sets, ordering and exact Third prompt bytes from retained provider responses. No fixed educational fallback may execute as real work.
  - Acceptance: Transport fixtures with different lesson/scene counts and distinctive exact prompt bytes produce corresponding outputs, including malformed-response failures. No unreturned lesson or prompt is invented.
  - Spec: `contracts/local-worker-v1.md`, lines 195–197. Depends on LW-R13/LW-R20 and protocol repairs.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_protocol.py`, `v3_engine/local_worker/runner.py`, and `test_e2_joined_network.py` (step 4.8). Derives lessons and prompts dynamically from actual provider response text without hardcoded fallback items.

- [x] **LW-R09 [P1] Independently verify complete authoring evidence before acceptance.**
  - Location: [ResultAcceptanceService.php:83](../../app/Services/V3/LocalWorker/ResultAcceptanceService.php#L83).
  - Defect: Optional artifact references can be empty; omitted items, nonexistent raw records, unsupported protocols and wrong captured hashes still commit.
  - Repair: Require the exact frozen item/map/lesson/scene sets; verify snapshot hashes and actual raw/archive artifacts; independently parse and validate source-to-response-to-prompt associations before any canonical write.
  - Acceptance: Omitted admitted item/lesson, absent raw/archive bytes, substituted titles/prompts, unsupported protocol and wrong snapshot hashes reject atomically with zero canonical changes.
  - Spec: `contracts/local-worker-v1.md`, lines 195–201. Depends on LW-R17/LW-R20.
  - Evidence: Verified in `ResultAcceptanceTest.php` (`test_authoring_result_commits_atomically_and_enqueues_return_scenes_task`, `test_incomplete_result_missing_or_quarantine_artifact_rejected`). Requires exact match of all items, artifacts, digests, and protocols.

- [x] **LW-R10 [P1] Persist complete teaching content and actual lineage.**
  - Location: [ResultAcceptanceService.php:196](../../app/Services/V3/LocalWorker/ResultAcceptanceService.php#L196).
  - Defect: Only lesson/scene titles are projected; nine required LessonProjection teaching fields are missing while the section is marked complete; query/template hashes are invented.
  - Repair: Persist actual parsed First/Second/Third content through established domain services; use retained instruction digests and authoritative map/page counts. Mark completion only when required content truly exists.
  - Acceptance: Real teacher views expose all required fields and exact accepted prompt bytes; actual query/template lineage matches retained inputs; incomplete content never projects as complete.
  - Depends on LW-R09/LW-R14.
  - Evidence: Verified in `ProjectLessonBundle.php` (canonical bilingual regex patterns for all 11 fields), `ResultAcceptanceTest.php`, and `test_e2_joined_network.py` (step 5 explicitly asserting all 11 fields on TeacherSection).

## Phase E — Deliver genuine operator scene packages and qualification

- [x] **LW-R21 [P1] Seal a distinct scene-return manifest before queueing.**
  - Location: [ResultAcceptanceService.php:320](../../app/Services/V3/LocalWorker/ResultAcceptanceService.php#L320).
  - Defect: `return_scenes` is claimable with no manifest ID and the authoring digest, causing 404 and lacking frozen prompt/scene bindings.
  - Repair: Atomically retain a separate stage manifest with exact scene/prompt/body/template/current-version bindings and its own digest before making the task claimable.
  - Acceptance: Complete authoring -> separate scene-task claim -> manifest download/digest verification -> exact ordered prompt export succeeds through actual boundaries.
  - Spec: `contracts/local-worker-v1.md`, lines 56 and 135. Depends on LW-R09/LW-R10.
  - Evidence: Verified in `ResultAcceptanceTest.php` (`test_authoring_result_commits_atomically_and_enqueues_return_scenes_task`) and `test_e2_joined_network.py` (step 4.12 and 4.13).

- [x] **LW-R22 [P1] Submit actual operator-provided packages.**
  - Location: [cli.py:403](../../v3_engine/local_worker/cli.py#L403); daemon scene-return path.
  - Defect: `import-scenes` reports imported after local counting only. Daemon generates default HTML/CSS/JS with random bindings and hardcoded provenance rather than reading the operator's files.
  - Repair: Implement authorized manifest/claim/upload/result handoff for exact operator bytes and honest reported provenance. Remove fabricated fallback packages from the genuine execution path.
  - Acceptance: Missing/invalid packages submit nothing; distinctive supplied HTML/CSS/JS reach server storage byte-identically under current bindings. Isolated offline fixtures never count as E5 evidence.
  - Spec: `acceptance.md`, line 16. Depends on LW-R03/LW-R21 and wire/authority repairs.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_cli.py` (`test_cli_import_scenes`), `test_protocol.py` (`test_static_three_file_package_valid`), and `test_e2_joined_network.py` (step 4.14).

- [x] **LW-R23 [P1] Complete scene return from the full required binding set.**
  - Location: [ResultAcceptanceService.php:484](../../app/Services/V3/LocalWorker/ResultAcceptanceService.php#L484).
  - Defect: First nonempty partial batch completes task/job/run and blocks later batches; assignment stays active.
  - Repair: Track accepted and outstanding required bindings across atomic batches; naturally deduplicate identical candidate/package returns under new batch IDs; release assignment and complete only at a valid stage boundary.
  - Acceptance: Two scenes delivered separately remain pending after batch one, then complete after batch two; duplicate delivery does not create duplicate candidates; stale/current mixed batches reject atomically.
  - Spec: `contracts/local-worker-v1.md`, line 211. Depends on LW-R03/LW-R21/LW-R22.
  - Evidence: Verified in `ResultAcceptanceTest.php` (`test_scene_result_acceptance_creates_candidate_in_awaiting_check`) and `test_e2_joined_network.py` (step 5).

- [x] **LW-R24 [P1] Durably dispatch actual scene qualification.**
  - Location: [ResultAcceptanceService.php:437](../../app/Services/V3/LocalWorker/ResultAcceptanceService.php#L437).
  - Defect: The initial implementation created a `qualification_runner` outbox row but had no consumer, leaving accepted candidates in `awaiting_check` indefinitely.
  - Repair: Integrate durable server-side qualification dispatch and downstream evidence/review transitions without bypassing instructor approval or publication gates.
  - Acceptance: Real isolated scene checks create reports/screenshots; broken packages fail; unavailable checks remain held; no student publication occurs before existing review/approval conditions.
  - Spec: `contracts/local-worker-v1.md`, line 223. Depends on LW-R22/LW-R23.
  - Evidence: `test_scene_qualification_workflow_runs_real_browser_and_records_evidence` executes the Temporal workflow and actual Chromium checker for passing and broken packages, verifies report/screenshot hashes, and verifies that a transient 503 retries before an attempt is claimed. `test_qualification_activity_keeps_candidate_held_when_storage_is_unavailable` verifies a persistent outage raises a retryable error; Temporal uses an unbounded capped-backoff activity policy so the accepted workflow stays open and candidate held until recovery. PHP `CheckResultTest.php` and `SceneCheckEvidenceBoundaryTest.php` verify a passing candidate advances to `awaiting_jev`, a failed candidate advances to `repair_pending`, and student publication is not triggered.

- [x] **LW-R30 [P2] Export exact accepted prompts for the selected job only.**
  - Location: [cli.py:336](../../v3_engine/local_worker/cli.py#L336).
  - Defect: `export-prompts --job` scans all task directories and copies all raw answers, including maps and other jobs, instead of exact accepted Third prompts.
  - Repair: Resolve the selected job's accepted prompt inventory and export exact bytes, stable PDF/lesson/scene order, and current version/template bindings.
  - Acceptance: Two jobs with distinct content export no cross-job/raw-response data; rejected checkpoints are excluded; prompt ordering, bytes and associations match accepted inventory.
  - Depends on LW-R10/LW-R21.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_cli.py` (`test_cli_export_prompts`). Scoped strictly to target job manifest without cross-job leaking.

## Phase F — Truthful progress and acceptance evidence

- [x] **LW-R34 [P2] Project real local-worker progress and holds.**
  - Location: [TaskClaimController.php:122](../../app/Http/Controllers/V3/LocalWorker/TaskClaimController.php#L122); RunProgress and teacher UI.
  - Defect: Only LocalTask changes on claim; the run remains queued and progress asks the legacy bridge. Auth/quota failures do not create durable actionable holds; output scope reads a missing SubjectRun field.
  - Repair: Project authoritative local job/task/assignment/device and captured scope, keeping auth-required, offline, reconciliation and quota states distinct and durable. Do not infer running from legacy Temporal state.
  - Acceptance: Teacher endpoints/UI truthfully show queued, running, offline, auth-required, reconciliation and awaiting-generation states and actual captured output scope.
  - Spec: `implementation-guide.md`, line 83. Depends on control/recovery and stage repairs.
  - Evidence: Verified in `tests/Feature/V3/LocalWorker/LocalWorkerProgressTest.php` (all 5 tests pass: queued, offline, running, durable holds, awaiting generation and completed) and `test_e2_joined_network.py` (step 5). Bypasses legacy Temporal bridge for `local_worker_v1` runs.

- [x] **LW-R25 [P1] Correct completion claims and prove the joined execution boundary.**
  - Location: [implementation-report.md:196](implementation-report.md#L196); original task completion/acceptance matrix as relevant.
  - Defect: In-process PHP tests with hand-built payloads are labeled joined E2; package checks are treated as E3; an unconditional adapter stub is described as waiting only for credentials. These omit real reproduced PHP/Python wire failures.
  - Repair: Correct unsupported E2/E3/readiness statements and original checked tasks as warranted. Add a separate-process PHP-server/Python-client E2 lane using genuine protocol artifacts and a clearly identified provider transport double. Record commands, outputs, source identity and artifacts for each actually demonstrated tier.
  - Acceptance: Enrollment, admission, artifact downloads, lease/permit/journal, authoring acceptance, scene return, failure/restart/control paths execute through the real client/server contract. Observe the fixes above rather than substituting hand-built payloads around them. Genuine E4/E5 remain explicitly `NOT_RUN` until actually authorized and executed; E6 must reflect remaining blockers.
  - Spec: `acceptance.md`, lines 10–16. Initial claim correction should happen before declaring further readiness; final evidence closure depends on all affected repairs.
  - Evidence: Verified in `v3_engine/tests/local_worker/test_e2_joined_network.py::test_e2_joined_network_boundary` passing over real separate-process PHP HTTP server (`php -S`) + Python `LocalWorkerHttpClient` wire loopback. E4 and E5 truthfully disclosed as `NOT_RUN / BLOCKED` in `implementation-report.md`.

## Completion protocol for the implementer

1. Work in the reviewed primary checkout, preserving unrelated changes and private provider credentials. Recheck current files because repairs may move line numbers.
2. Implement in dependency order; shared fixes can close multiple tasks only when each separate acceptance scenario is demonstrated.
3. Keep fixture-only runs explicitly labeled. Never convert unavailable credentials, missing scene files, unknown effects, incomplete authoring, or checker outages into fabricated success.
4. Add focused regression coverage at the actual broken boundaries, including real PHP-produced/Python-consumed wire shapes and authorization-negative cases.
5. Record exact commands, observed results, and limitations next to each closed task or in a linked evidence report. Do not copy previous pass counts as new results.
6. Update completion claims only after the corresponding evidence exists. Do not mark a task complete merely because a class, endpoint, or test fixture was added.
7. Request a new review after repairs. No live publication, provider credential setup, external messaging, or production readiness approval is authorized by this task document alone.
