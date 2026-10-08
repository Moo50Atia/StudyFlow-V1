# V3 production-path repair tasks

**Status: implementation in progress.** Current task evidence is tracked in [the implementation report](V3-POC-REPAIR-IMPLEMENTATION-REPORT.md); do not infer a task is complete solely from code presence.

Source: [V3 proof-of-concept review, 2026-10-04](V3-POC-REVIEW-2026-10-04.md). This backlog covers all 23 supplied comments: B01–B16 and D01–D06 in that report, with B15 split into workflow start and workflow execution. External verification gates are additional tasks, not retrospectively confirmed defects.

This document was prepared by inspecting the working tree and review. No application fixes, application tests, live provider calls, commits or deployments were performed to create it. Test results in the source review are historical baseline evidence, not results of these tasks.

## 1. Implementer instructions and authority

Implement this backlog in dependency order. Do not implement from the review summaries alone. Before each task, read its governing spec's `spec.md`, `data-model.md`, every file in `contracts/`, `implementation-notes.md`, `task-execution-plan.md`, relevant acceptance cases, and current implementation/report. Read the actual caller, callee, routes, validation, persistence, configuration and tests before changing an interface. Existing checked task boxes and implementation reports do not establish that a production path works.

Read these common authorities first:

- Applicable `AGENTS.md` / `AGENTS.override.md` instructions at implementation time.
- `Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md` and `specs/V3-spec-map.md`.
- `specs/004-v3-access-ownership/` for identity, original-teacher ownership and content privacy.
- `specs/005-v3-versioned-settings/` for captured settings, approved template selection/import and configuration lifetimes.
- `specs/006-v3-pdf-intake-cases/` and `specs/007-v3-workflow-progress/` for admission, callback identity and durable workflow state.
- `specs/008-v3-knowledge-source-adapter/` through `specs/015-v3-history-evidence/` for the stage being repaired and its direct upstream/downstream contracts. In particular, read 013 before changing 012 completion, and 014 before changing workflow waits or provider dispatch.
- The source review linked above and this entire plan before beginning R00.

Preserve these decisions throughout:

- Teacher/admin are the pilot roles. Only the original teacher sees source, prompts, scenes and review notes; admin receives operational metadata.
- Local extraction feeds `notebook_add_text`. NotebookLM performs grounded mapping/research and the canonical prompt conversation, including Second/Third generation. Do not substitute the separate prompt-provider setting for that conversation.
- Canonical dividing, Execution and First/Second/Third prompt bytes remain unchanged. Preserve all eleven First-step Section fields.
- Successful JEV Choice, Score and Noul values feed the approved deterministic policy directly. No additional model decides whether a typed success is usable. Preserve the approved Noul probability interpretation and captured thresholds in 010; transport/protocol failures are not educational verdicts.
- Laravel owns identity, ownership, persisted state, claims, version checks and review. Temporal owns durable coordination. Private bodies stay in activity-side requests/storage, not workflow inputs, signals, search attributes or operational logs.
- V2 is reference-only. Do not introduce KOS/KIE, multitenancy, new provider policy or production placeholders.

If current contracts disagree, record the exact disagreement and stop only dependent work. Do not weaken a contract, fabricate success, alter canonical prompts, or silently edit numbered specs to make a test green. Routine implementation choices consistent with the contracts do not need renewed approval.

## 2. Working-tree and test rules

The reviewed workspace contains substantial existing modified and untracked implementation. R00 must capture both. `git diff` alone misses most V3 files. Do not reset, clean, overwrite unrelated changes, remove historical evidence, or run destructive migrations against the application database. No commit/deploy is part of this backlog unless separately requested.

Each R task is a test-first execution unit:

1. Read its inputs and dependency checkpoints.
2. Add/run its named regression tests against the current behavior. Record a behavioral failure (or record that an already-fixed case passes). Import/syntax errors alone are not red evidence.
3. Implement the deliverables.
4. Run the focused tests and relevant existing regressions; record exact command, result and limitations.
5. Check the completion condition before marking the task done.

New test filenames below are proposed deliverables, not claims that those files already exist. Extend existing tests where indicated. Test fixtures can replace external providers, but must not replace the production clients, activity entry points, persisted state transitions or workflow stages whose connection is being proved. Use real concurrent requests/processes with synchronization barriers for races; two sequential requests are only duplicate tests.

Use explicit markers and opt-in flags for external resources. An offline test must not accidentally download/start a Temporal server or contact NotebookLM/JEV. Separate results into offline regression, local Temporal/database integration, browser container/UI, deployment packaging/database, and authorized live-provider evidence. A mock is never live integration proof.

## 3. Findings-to-task coverage

| Review ID | Supplied comment | Required repair tasks | Connected verification |
|---|---|---|---|
| B01 | Sign serialized source callback bytes | R03 | R23 |
| B02 | Execute mapping and First preparation | R05 | R09, R23 |
| B03 | Schedule prompt generation before waiting | R07 | R09, R23 |
| B04 | Supply source-scoped adapter arguments | R06 | R07, R23 |
| B05 | Persist real prompt qualification | R08 | R23 |
| B06 | Process every mapped work item | R09 | R21, R23 |
| B07 | Connect candidates, qualification and review | R14, R15 | R21, R23 |
| B08 | Match browser runner interface | R10 | R23, R24 |
| B09 | Separate Laravel and JEV clients | R12 | R14, R23 |
| B10 | Use implemented TypeSafe transport/credential | R12 | R24 |
| B11 | Use dedicated JEV model | R13 | R12, R24 |
| B12 | Reuse configured callback secret | R04 | R23 |
| B13 | Expose interactive preview and decisions | R17 | R23, R24 |
| B14 | Dispatch correction outbox entries | R19 | R20, R23 |
| B15a | Actually start correction workflows | R18 | R19, R23 |
| B15b | Actually execute corrections | R20 | R21, R23 |
| B16 | Remove test-only production signing dependency | R01 | R22 |
| D01 | Fail closed without authentication secrets | R02 | R22, R23 |
| D02 | Require complete browser evidence | R11 | R14, R23 |
| D03 | Persist real screenshot bytes | R10 | R11, R24 |
| D04 | Prove exact review decision consumption | R15, R19 | R21, R23 |
| D05 | Avoid recursive preview lock cleanup | R16 | R17, R23 |
| D06 | Persist measured check duration | R11 | R23 |

## 4. Ordered repair tasks

### R00 — Preserve the baseline and make verification selection explicit

**Depends on:** none. **Governs:** every finding; 007–015 verification boundaries.

**Deliverables:** `docs/reviews/V3-POC-REPAIR-IMPLEMENTATION-REPORT.md` (start the report), `v3_engine/pyproject.toml`, `v3_engine/tests/conftest.py` (create/extend), and affected external test modules under `v3_engine/tests/`.

- Record HEAD, status including untracked files, dependency/tool versions, existing feature selector and hashes of files to be changed. Preserve unrelated changes. Record whether relevant migrations have already been applied; do not assume this new/untracked code has never run.
- Inventory test collection without running external fixtures. Add/register `temporal`, `browser_ui`, `integration`, and `deployment` markers where needed, retaining `live` and `container`. Ensure all real-MCP/provider tests are `live`; Temporal download/server tests are `temporal`; Docker tests are `container`.
- Make external fixtures require explicit opt-in and fail/skip with a clear reason before opening connections. Keep offline regressions runnable without credentials. Do not hide production-path regressions behind external markers when a wire-level regression is possible offline.
- Run baseline Pest V3/full suites and the deliberately selected offline Python suite. Compare with the source review's 468 V3 passes/one skip, five legacy full-suite failures, and limited 104 Python passes; these counts are historical, not target numbers. Classify new failures separately. Do not depend on the review's temporary `/tmp` network shim still existing.

**Tests/checkpoint:** collect both offline and external sets; prove no external fixture setup occurs in offline selection. Document every skip/deselection and the actual isolation mechanism. Done when baseline and safe commands are reproducible and no workspace changes have been lost.

### R01 — Move bridge signing into production PHP code

**Depends on:** R00. **Covers:** B16; 007/010/013/014 transport.

**Files:** new `app/Services/V3/Workflow/BridgeRequestSigner.php`; existing `BridgeClient.php`, `ReviewSignalGateway.php`, `app/Services/V3/Prompts/PromptReceiptSender.php`, `app/Services/V3/Review/{ApplyPreviewAction,SubmitReviewDecision,StartReviewPreview}.php`, `app/Services/V3/Admin/DispatchAdminCommands.php`, `app/Http/Controllers/V3/Teacher/ReviewPreviewController.php`. Search all production paths for other `Tests\` references.

**Tests first:** new `tests/Feature/V3/BridgeSigningBoundaryTest.php` and `v3_engine/tests/workflow/test_signing_vectors.py`. Independent shared vectors must cover UTF-8, query/path canonicalization as specified by 007, empty body, timestamp and replay/tamper rejection. Exercise receiving middleware, not only helper equality.

**Implement:** use the exact existing protocol; serialize once, sign the bytes actually sent, preserve request identity/replay protections. Production code cannot import `Tests\Support\V3\WorkflowFixtures`. Tests may call the production signer but also retain independent fixed expected signatures.

**Done:** all production senders use the production implementation; wire tests pass. R22 must subsequently prove no-dev boot/start works.

### R02 — Require explicit bridge and callback secrets

**Depends on:** R01. **Covers:** D01; 007/008/012/013 authentication.

**Files:** `config/services.php`, `v3_engine/settings.py` (`EngineSettings` and loader), bridge/callback middleware and affected client factories; deployment environment examples (never actual credentials). Search for remaining fallback values throughout the production paths.

**Tests first:** new `tests/Feature/V3/InternalSecretConfigurationTest.php` and `v3_engine/tests/workflow/test_secret_configuration.py`. At actual authenticated endpoints, absent/empty secret and the former public default must fail before content lookup/mutation; correctly configured independent secrets must work only in their intended direction. Explicit test fixture keys remain allowed in isolated tests.

**Implement:** remove implicit known-secret fallbacks from production configuration and clients. Validate required configuration before dispatch/start/readiness. Return the existing contract's safe unavailable/authentication failure; never expose a key or turn a configuration fault into a verdict. Keep bridge and callback secret roles distinct. Do not rotate installed keys automatically.

**Done:** unset configuration cannot authenticate an internal operation; positive configured-key tests pass; configuration documentation names required variables and safe startup behavior.

### R03 — Serialize and sign actual Python source request bytes

**Depends on:** R02. **Covers:** B01; 008 extraction/source claims.

**Files:** `v3_engine/activities/extract_case_source.py`, `v3_engine/sources/notebooklm_adapter.py`; a shared production transport helper if needed within `v3_engine/`.

**Tests first:** new `v3_engine/tests/sources/test_signed_source_http.py`; extend corresponding Laravel source callback tests. Capture real `httpx.Request.content` from extraction result, `claim_effect` and `record_result`. Include Arabic/Unicode, nested payloads, null values, exact retries and one-byte tampering.

**Implement:** replace private `httpx._content.encode_json()` tuple usage with public JSON serialization to bytes. Sign that byte buffer and send it as content with the correct content type; do not reserialize via `json=` after signing. Apply identical rules to every affected source method.

**Done:** the real HTTP clients produce valid byte requests; Laravel verifies those exact requests and rejects tampering. No fake source client substitutes for the code under regression.

### R04 — Unify qualification callback configuration and URL construction

**Depends on:** R02, R03. **Covers:** B12; 007/012 callback boundary.

**Files:** `v3_engine/qualification/activity.py` (including its existing `SceneCheckClient`), `v3_engine/qualification/repair.py`, and `v3_engine/settings.py`. There is currently no separate `check_client.py`; do not assume one exists.

**Tests first:** new `v3_engine/tests/qualification/test_callback_configuration.py`. Exercise check/repair/attempt requests with the same configured `V3_CALLBACK_HMAC_KEY` accepted by Laravel. Test configured URL with the existing callback path, and ensure no duplicated `/api/internal/v1` or accidental callback-endpoint-as-origin path.

**Implement:** reuse validated engine configuration; remove `V3_WORKER_HMAC_SECRET`/`worker-hmac-secret-test` fallback behavior. Respect existing `V3_LARAVEL_CALLBACK_URL` semantics when resolving internal routes; centralize origin/path derivation rather than replacing a working deployment variable silently. Document any explicit compatibility conversion.

**Done:** every qualification-side Laravel client authenticates using the existing worker setup; wrong/missing keys fail closed without fallback.

### R05 — Run real mapping and First-step preparation activities

**Depends on:** R03, R04. **Covers:** B02; 008→009.

**Files:** `v3_engine/activities/{map_case,prepare_lesson}.py`, `v3_engine/lessons/{map_service,lesson_processor}.py`; new `v3_engine/lessons/client.py` implementing the signed Laravel methods these services call; existing 009 internal routes/controllers as needed to fulfill their documented contracts.

**Tests first:** new `v3_engine/tests/lessons/test_activity_persistence.py` and `tests/Feature/V3/LessonActivityBoundaryTest.php`. Invoke registered activity entry points with the real service/client stack and a controlled external adapter. Assert persisted map, actual ordered work count, actual Section references and every First field. Cover missing binding/context, malformed map/First, partial persistence, duplicate delivery and claim uncertainty.

**Implement:** fetch authoritative activation/source context, resolve the verified binding through 008, call `MapService`/`LessonProcessor`, and persist through real signed routes. Do not invent notebook IDs, page/work counts of one, Section counts, or ready refs. Verify the actual context response supplies every field consumed; add only contract-required projection fields where genuinely missing. Reuse the claim/result ledger and dispositions; a retry must reconcile an uncertain query instead of blindly repeating a stateful NotebookLM turn.

**Done:** ready output can be traced to committed map/Section records. Adapter and storage failures yield contract-defined non-ready outcomes, and a repeated completed call reuses committed results.

### R06 — Bind every prompt conversation turn to the real source adapter

**Depends on:** R05. **Covers:** B04; 008/009/010.

**Files:** `v3_engine/prompts/cycle.py`, its production source-binding resolver/client wiring.

**Tests first:** extend `v3_engine/tests/prompts/test_generation.py`; add `test_source_scoped_cycle.py` in the same directory. Use the actual `KnowledgeSourceAdapter` signature/autospec plus wire-level tests; a permissive `*args` fake is insufficient. Cover Execution, Second, Third, reuse and Continue with case/binding/conversation identity, stale binding and recovery after response loss.

**Implement:** resolve a verified binding, then call `query_once(case_id=..., binding=..., query_text=..., conversation_id=...)` consistently. Preserve source scoping, canonical bytes, conversation continuity, response identity and ledger recovery. Do not create a new notebook/source to avoid context errors.

**Done:** real adapter calls are signature-compatible and scoped to the case's recorded source; all canonical turns are covered without unsolicited provider calls.

### R07 — Schedule and register canonical prompt generation

**Depends on:** R06. **Covers:** B03; 007/010.

**Files:** `v3_engine/prompts/activities.py`, `v3_engine/workflows/case_workflow.py`, `v3_engine/worker.py`; existing prompt receipt sender as required.

**Tests first:** extend `v3_engine/tests/prompts/test_workflow_wiring.py`; add `test_generation_receipt_recovery.py`. Start from a persisted First-ready item without injecting a fabricated lesson-ready signal. Assert one production generation activity is scheduled, Third outputs commit, then completion is consumed. Cover activity retry, signal-before-wait, duplicate signal and commit-success/receipt-loss recovery.

**Implement:** expose/register a Temporal activity around `PromptCycleRunner`; inputs are opaque case/work/binding refs, not private prompts. Schedule before waiting. Validate completion against persisted identity/version, not merely a signal name/count. Use deterministic workflow code and activity-side I/O.

**Done:** normal First completion leads to actual Third generation and no indefinite wait caused by a missing producer. Worker startup validates all referenced activity names are registered.

### R08 — Replace prompt qualification placeholders with persisted typed JEV work

**Depends on:** R07, R13. **Covers:** B05; 010→011.

**Files:** `v3_engine/prompts/{activities,jev,policy,feedback}.py` and their signed Laravel qualification client; existing 010 claim/result/revision services as needed.

**Tests first:** extend `v3_engine/tests/prompts/{test_jev_policy,test_revision,test_workflow_wiring}.py`; new `tests/Feature/V3/PromptQualificationActivityTest.php`. Cover each approved typed pass/fail boundary, Choice block, Noul boundary, low confidence, revision limit, retryable transport failure, malformed response, duplicate claim, committed-result/lost-ack, and a mixed set of qualified/blocked Sections. Assert actual persisted passing refs, not caller-provided counts.

**Implement:** iterate authoritative Sections; claim evaluation, use `TypeSafeJevClient.evaluate_prompt`, persist raw/typed evidence, apply captured policy, and invoke `FeedbackTextAdapter.revise` only for allowed revisions. Persist revision ancestry and re-evaluate until pass or the specified terminal/budget state. Successful JEV is consumed directly; no meta-judge. Remove case-only success and `mock_mode` production success escapes in `resolve_qualified_prompt`. Production failures cannot return fake passing refs.

**Done:** authoring receives only real current persisted passing qualification references; counts derive from storage. Provider failures cannot masquerade as qualification completion/pass.

### R09 — Advance through all mapped work items

**Depends on:** R05, R07, R08. **Covers:** B06; 009/010/011 workflow cursor.

**Files:** `v3_engine/workflows/case_workflow.py`, `v3_engine/lessons/cursor.py`, and relevant callback receipt validation.

**Tests first:** new `v3_engine/tests/workflow/test_multi_work_progression.py`. Use at least three mapped work items, multiple Sections, out-of-order/duplicate receipts, a blocked Section and worker restart. Assert every mapped item is processed exactly under its own identity and the final item sends no extra Continue. Exercise uncertain Continue recovery.

**Implement:** replace the hardcoded ordinal-one/single receipt behavior with the persisted 009 cursor and per-item durable receipt state. Apply canonical Continue only at the contracted transition. Reject cross-case, wrong-version and stale receipts. Process sibling Sections/items without requiring a teacher to finish reviewing the first item before later mapping work can proceed; retain independent Section outcomes and bounded concurrency.

**Done:** every mapped item either reaches its downstream state or has an explicit persisted blocker; no item silently disappears, repeats a stateful turn, or is counted complete from another item's signal.

### R10 — Call the browser runner correctly and carry its real screenshot

**Depends on:** R04. **Covers:** B08, D03; 011→012 evidence.

**Files:** `v3_engine/qualification/{activity,container_runner,browser_checks}.py` and the existing private candidate-package reader.

**Tests first:** new `v3_engine/tests/qualification/test_runner_activity_contract.py`; extend `test_container.py`. Feed a valid packaged fixture through the real activity/runner interface. Assert the runner receives tar bytes, exact candidate ref and exact manifest digest; decode its actual screenshot and compare stored digest/bytes. Cover timeout, nonzero exit, bad result shape, absent/invalid PNG, mismatched digest, unsafe tar entries and corrupted artifact bytes.

**Implement:** fetch/revalidate 011 package in the activity; call `run_container_check(candidate_tar_bytes, candidate_ref, manifest_sha256)` and consume its result dictionary (`success`, `error`, `report`, `screenshot_png`). Preserve the runner's actual base64 PNG and SHA; no synthetic fallback. Preserve manifest byte identity rather than hashing a differently serialized JSON object. Keep tar creation/extraction safe against traversal/symlinks. Do not run blocking Docker subprocess work on an unbounded async request loop; maintain the runner's timeout and isolation options.

**Done:** the production activity reaches the runner without TypeError/unpacking errors and forwards authentic bytes. Missing evidence is a technical failure and cannot reach JEV as a pass. Real-container confirmation remains R24.

### R11 — Validate complete browser evidence and measured duration in Laravel

**Depends on:** R10. **Covers:** D02, D06; 012/015 evidence integrity.

**Files:** `app/Services/V3/SceneQualification/RecordSceneCheck.php`, its request validator/controller, `resources/v3/scene/check-codes-v1.json`, and evidence projection code only if needed.

**Tests first:** new `tests/Feature/V3/SceneCheckEvidenceBoundaryTest.php`. Submit signed HTTP requests at the actual internal route. Include missing/empty/duplicate/unknown check codes, wrong candidate/manifest/checker, invalid base64/non-PNG/wrong dimensions/hash, oversized content, invalid timestamps, stale claim, replay and same-key/different-body conflict. Verify database state, private storage and absence of JEV dispatch after rejected evidence. Test all-pass and legitimate check-fail reports separately; use measured 137 ms and 2350 ms examples.

**Implement:** enforce the 012 report schema, including `schema_version`, claimed candidate/manifest identity, `checker_version`, observations, screenshot SHA and UTC start/finish. Require the seven prescribed ordered checks: `integrity`, `entrypoint`, `assets`, `runtime`, `network`, `interaction`, `accessibility`; explicit `pass|fail` values and permitted detail codes. Enforce contract limits (report 262144 bytes; PNG 1280×720 at 1×, at most 2097152 bytes; request at most 3 MiB). Validate decoded PNG, not just its file extension/signature. A pass requires all seven explicit passes; valid negative evidence is a fail; malformed/technical evidence is neither.

- Derive `duration_ms` from validated measured start/finish values with the specified runtime bound; reject reversed/invalid/out-of-bound measurements. Remove the constant 1000. Do not insert zero to mean unknown into a required duration field.
- Perform validation before committing a definitive run/transition. Preserve claim/idempotency semantics and 012 staging/fsync/exclusive-rename rules. Reconcile orphan writes before cleanup; delete only unreferenced, nonuncertain files older than the contracted 24 hours. Keep report identity tied to the current claimed candidate, not user-supplied labels alone.

**Done:** malformed/incomplete evidence never advances a candidate; correct actual evidence and measured timing persist and appear consistently in 015 projections.

### R12 — Separate Laravel attempt transport from the external TypeSafe evaluator

**Depends on:** R04, R13. **Covers:** B09, B10; 012 JEV transport.

**Files:** `v3_engine/qualification/{activity,jev}.py`; new `v3_engine/qualification/client.py` for the signed Laravel attempt client; `resources/v3/jev/scene-questions-v1.json` as read-only question authority.

**Tests first:** new `v3_engine/tests/qualification/test_attempt_transport.py`; extend `test_jev.py`. Assert Laravel `claim_attempt/get_context/record_result` use callback authentication and internal URLs; external evaluation uses TypeSafe authentication/base URL/model only. Test actual client factories, captured request bodies, valid typed answers, protocol/HTTP timeout errors, credential separation, result replay and model mismatch handling. Reject an invented unimplemented function import as the transport.

**Implement:** construct distinct clients with explicit dependencies. Reuse `SceneJevClient`'s implemented request/evaluation behavior and the canonical question file; remove `call_typesafe_jev` import and `GEMINI_API_KEY` selection. Use validated `TYPESAFE_API_KEY` and dedicated JEV model configuration. Preserve exact request/result digests, returned model identity and typed evidence; do not invent a model fallback or substitute generator credentials.

**Done:** real clients can execute the entire claim→context→external request→record sequence in wire tests. No client is expected to implement another service's methods. Live provider compatibility is still R24.

### R13 — Capture the dedicated JEV model in reservations and digests

**Depends on:** R02. **Covers:** B11; 005/010/012 configuration identity.

**Files:** `app/Services/V3/SceneQualification/{ClaimSceneJevAttempt,RecordSceneJevResult,SceneEvidenceAssembler}.php`, relevant JEV configuration/context projection, and matching prompt-side model selection if it shares the same faulty assumption.

**Tests first:** new `tests/Feature/V3/SceneJevModelIdentityTest.php`. Vary valid Gemini/GLM/DeepSeek generator models while holding the dedicated JEV model fixed; assert identical correct JEV identity in reservation, context and digest. Cover missing JEV config, model change after reservation, retry and actual provider-returned model mismatch under the governing contract.

**Implement:** reserve the configured, verified `JEV_MODEL` independently of `providers.prompt.model`. Capture it immutably at the contract's reservation boundary; retries reuse the captured model. Missing required configuration fails before a billable reservation/request. Do not rewrite historical attempts or compare results against a newly changed global setting.

**Done:** every scene JEV request and its persistence/digest/context agree on the dedicated model; generator selection cannot change that identity.

### R14 — Connect candidate qualification, permitted repair and review readiness

**Depends on:** R08, R10, R11, R12. **Covers:** B07; 011→012→013.

**Files:** `v3_engine/workflows/case_workflow.py`, `v3_engine/worker.py`, `v3_engine/qualification/activity.py`, existing qualification/repair activities and 012 completion callbacks.

**Tests first:** extend `v3_engine/tests/qualification/test_workflow.py`; new `test_candidate_review_handoff.py`. Begin at a real committed candidate receipt, run production activity boundaries, assert checks→JEV→review-ready ordering. Include deterministic check failure, JEV failure, exhausted repair, technical uncertainty, one allowed repair successor, duplicate/stale candidate receipts and mixed Section outcomes.

**Implement:** decorate/register the qualification activities and schedule them after candidate-ready. Only persisted passing checks permit JEV; only current persisted complete qualification permits review. Wire the existing repair claim/authoring path and requalify the successor within captured limits; never promote the failed predecessor. Publish milestones from committed outcomes. Keep blocked/repair/review states per Section and preserve admin hold/cancel behavior.

**Done:** candidate-ready is no longer the terminal workflow action. Every candidate reaches a qualified review, explicit block, permitted repair or recoverable uncertainty; none silently completes or bypasses either gate. R15 adds the decision wait/proof.

### R15 — Implement exact review decision consumption and durable waits

**Depends on:** R14. **Covers:** B07, D04; 007/013 bridge protocol.

**Files:** `v3_engine/workflows/case_workflow.py`, `v3_engine/bridge.py`, review signal/query definitions and worker registration if split into a dedicated workflow.

**Tests first:** extend `v3_engine/tests/workflow/test_review_gate.py`; new `v3_engine/tests/review/test_decision_consumption.py`. Verify exact decision ID, Section/candidate/review version, duplicate delivery, different unknown decision on a completed workflow, query failure, signal-before-wait, restart and incompatible/stale decision. Completion alone must never prove any supplied ID was consumed.

**Implement:** add the contracted `review_decision_v1` handler and `query_decision_consumed` proof. Validate/resolve committed decision refs through activity-side Laravel operations, apply the appropriate transition once, and only then record consumption. Do not mark consumed at signal receipt. Queries expose only safe identity/status. Remove bridge fallback that converts any failed query on a completed workflow to `true`; unavailable/unknown proof remains retryable, with the contract's error response. Closed workflows may prove only an exact retained ledger/result if the contract supports it.

**Done:** approval/change-request transition and its consumption proof agree for each exact decision. Workflow remains durably waiting as required until a decision/block/cancel, and transport acceptance cannot end recovery.

### R16 — Make preview initialization failure bounded and deadlock-free

**Depends on:** R00. **Covers:** D05; 013 preview availability.

**Files:** `v3_engine/review/preview_gateway.py` and its subprocess initialization/read helper.

**Tests first:** new `v3_engine/tests/review/test_preview_failure_cleanup.py`. Run malformed JSON, EOF, child exit, missing first frame and timeout in a bounded future/process so a regression fails rather than hanging pytest. Synchronize concurrent start/close calls and verify process/container/handle cleanup exactly once.

**Implement:** separate already-locked cleanup from public locking `close()` or release ownership before cleanup. Preserve session synchronization, bound startup reads, and return the contracted unavailable response. Do not merely replace the lock without testing lifecycle races/resource cleanup.

**Done:** every failure path terminates within the configured bound and leaves no live preview process/session; happy path and concurrent cleanup regressions pass.

### R17 — Wire teacher preview, decisions and correction controls into the real page

**Depends on:** R01, R15, R16. **Covers:** B13; 004/013 user boundary.

**Files:** `resources/views/v3/teacher/reviews/show.blade.php`, `resources/js/{app,v3-review-preview}.js`, existing teacher review/preview controllers and requests only where required; new `v3_engine/tests/review/test_teacher_review_ui.py` (`browser_ui`), plus `tests/Feature/V3/ReviewPageBoundaryTest.php`.

**Tests first:** render/login as original teacher, another teacher, admin and guest through actual routes. Browser test must load the built module, start a viewer, render the returned frame, send a real supported interaction, and exercise approval/change request with persisted effects. Cover CSRF, notes/feedback validation, stale version/candidate, duplicate submit, unavailable preview, first-frame/preview prerequisite and postapproval correction entry. Assertions must include database/outbox effects, not only button text.

**Implement:** import/initialize the preview client from the application bundle; render authenticated remote PNG frames and supported controls. Never execute the candidate's HTML in the Laravel origin via iframe/srcdoc. Use existing routes under `/v3/teacher/cases/{case}/reviews/{review}` for viewer sessions/actions and `/decisions`; use `/v3/teacher/cases/{case}/approved-scenes/{approval}/corrections` for postapproval requests. Send required expected versions, candidate/idempotency identities and CSRF. Display pending/conflict/error states, prevent accidental duplicate submissions, support meaningful keyboard interactions and cleanup viewer sessions. Use the exact 013 decision schema and do not rely on client controls for authorization.

**Done:** the original teacher can interact and make a permitted decision from the actual page. Alternative users and stale requests cannot access content or mutate review through any existing route. A UI mock is not approval proof.

### R18 — Actually start and register correction workflows

**Depends on:** R02, R15. **Covers:** B15a; 013 correction admission.

**Files:** `v3_engine/bridge.py`, `v3_engine/worker.py`, `v3_engine/review/correction_workflow.py`; new `v3_engine/tests/review/test_correction_start_bridge.py`.

**Tests first:** actual authenticated bridge handler with a strict Temporal client fake at the external boundary. Assert `start_workflow` is called once with the registered workflow, correct queue and deterministic `v3-review-correction-{correction_uuid}` ID. Cover same identity replay, mismatched identity, Temporal unavailable, concurrent start/already-started race and closed prior correction. Zero starts cannot return a new-start accepted response.

**Implement:** resolve/validate required recorded correction/route identity and start the workflow; distinguish genuinely already-existing matching workflow from connection/describe/start failure. Never swallow arbitrary exceptions into 202. Preserve exact safe refs/feedback kind required by 013; private notes are fetched only in activities. Register workflow and every referenced activity. Do not restart the original completed case workflow to implement postapproval correction.

**Done:** accepted means a real matching durable execution exists; failures remain recoverable and do not acknowledge nonexistent work. Execution itself is R20.

### R19 — Dispatch both review effects with recoverable, concurrent-safe delivery

**Depends on:** R01, R15, R18. **Covers:** B14, D04; 013 outbox.

**Files:** `app/Services/V3/Review/DispatchReviewEffects.php`, `app/Services/V3/Workflow/ReviewSignalGateway.php`, correction bridge client/operation, existing scheduler/command wiring; new `tests/Feature/V3/ReviewEffectRecoveryTest.php` and `tests/Integration/V3/ReviewEffectConcurrencyTest.php`.

**Tests first:** `signal_decision` and `start_correction`, false/unknown consumption, accepted-but-lost-ack, bridge unavailable, expired sending lease, uncertain row recovery, and two genuinely concurrent dispatchers competing for the same row. Assert bounded claims and one logical transition/start, not necessarily one HTTP attempt after uncertainty.

**Implement:** atomically claim eligible pending/uncertain/expired effects with the specified two-minute lease, release database locks before network I/O, and reconcile using stable identity. Branch explicitly on both effect types. Mark decision delivered only after exact consumption proof; false/query failure stays eligible for recovery. Mark correction start delivered only after proof of the matching durable start, not after correction completion. Preserve committed correction state and old approval history; do not clear/recreate identities on retry. Reject unsupported effect kinds visibly instead of silently delivering them.

**Done:** both kinds drain through the real scheduler/dispatcher, uncertain rows are not stranded, and concurrent/lost-response cases cause no duplicated business action or lost decision.

### R20 — Execute visual and factual/mixed corrections through a new qualified review

**Depends on:** R08, R14, R15, R18, R19. **Covers:** B15b; 010–013 correction lifecycle.

**Files:** `v3_engine/review/correction_workflow.py`, correction activities and their signed clients, `v3_engine/worker.py`; existing 010 teacher-correction and 011/012 repair services. Tests: new `v3_engine/tests/review/test_correction_execution.py`, extend PHP 013 correction tests.

**Tests first:** from committed corrections, exercise visual and factual/mixed branches separately. Verify actual successor ancestry and expected provider/gate sequence. Cover no-op claim reuse, feedback/revision failure, exhausted budget, hold/cancel, worker restart, response loss and duplicate outbox delivery. A workflow that only reserves an effect must fail the completion assertion.

**Implement:** visual correction uses the permitted scene-repair/authoring path then full browser/JEV qualification and a fresh review. Factual/mixed correction retrieves the exact private notes/context, generates and records the allowed corrected prompt, runs typed prompt qualification, authors a successor, then full scene qualification and a new review. Reuse existing services/ledgers instead of creating a parallel correction policy. Retain original approved artifact/history; enforce current-candidate/version checks and captured budgets. Complete only at the contracted persisted terminal outcome, including the required new review lifecycle; reservation/context retrieval alone is not completion.

**Done:** accepted teacher corrections produce a traceable successor and actionable review, or an explicit recoverable/blocked outcome. No factual path skips prompt JEV and no visual path unnecessarily rewrites canonical content.

### R21 — Prove workflow replay, controls and races across the connected path

**Depends on:** R09, R14, R15, R20. **Covers:** B03/B06/B07/B15/D04 integration; 007/014 durability.

**Files/tests:** `v3_engine/tests/workflow/test_connected_replay.py` (new), existing `v3_engine/tests/admin/test_admin_control_workflow.py`, workflow fixtures under tests only; workflow changes strictly as failures require.

- Record/replay representative histories: before/after generation receipt, uncertain external turn, candidate-ready, review wait/decision, correction start and multi-item completion. Use actual local Temporal execution and replay, not direct method invocation alone.
- Establish whether existing executions/histories must be supported. Use Temporal versioning/patch mechanisms for command-sequence changes where necessary; test both retained histories and new execution. Never purge running workflows to avoid nondeterminism without explicit operational authorization.
- Race hold/cancel against dispatch, stale receipt against a successor, and repeated review/correction signals. Verify Laravel admission/lease fences are rechecked at the real side-effect boundary; control acknowledgment does not falsely certify that an already-issued request was undone.
- Inspect history and captured logs for private source/prompt/scene/notes or credentials; only allowed safe refs/metadata may appear.

**Done:** replay succeeds for supported histories, controls retain their 014 guarantees, and each race has a documented final persisted state without duplicate billable intent or invented external success.

### R22 — Verify production packaging and the intended database

**Depends on:** R01, R02, R11, R19. **Covers:** B16/D01 deployment; report section C database risk.

**Files:** `composer.json` production autoload only if necessary; V3 migrations under `database/migrations/`; deployment/testing instructions; new `tests/Integration/V3/ProductionBootTest.php` and `MigrationCompatibilityTest.php` where appropriate.

**Tests first:** in an isolated disposable installation, run Composer without dev dependencies and boot the app/resolve production signing and bridge/review/admin services. Exercise a workflow-start request against a controlled bridge. Do not remove the current checkout's dev dependencies.

- Identify the intended deployment database from actual configuration/operator confirmation without printing secrets. Current review did not establish it. If unspecified, record this gate blocked while continuing other tasks.
- On that disposable backend, test fresh migrations, relevant foreign keys and claim/outbox concurrency. Specifically inspect 012/013 filenames which sort before 007–011, and settings FK widths/types. SQLite passing is insufficient evidence of another backend.
- If ordering/types fail, design a migration repair compatible with whether migrations have already been applied. Do not blindly rename historical deployed migrations or reset data. Test upgrade from the supported existing state as well as empty installation.

**Done:** no-dev boot/start and selected-backend fresh/upgrade tests pass, or the exact external prerequisite remains explicitly blocked. No production DB is altered by verification.

### R23 — Run connected offline/local acceptance scenarios through production boundaries

**Depends on:** R03–R21. **Covers:** all 23 comments together.

**Files:** new `v3_engine/tests/integration/test_v3_connected_pipeline.py`, `tests/Integration/V3/ConnectedReviewLifecycleTest.php`, deterministic provider fixture responses under test directories, and the implementation report.

Use local Laravel, a disposable real database and local Temporal worker. Replace only external NotebookLM/TypeSafe/generator transport responses for this gate; do not replace the registered mapping/generation/qualification/correction activities with ready/pass stubs. Use the real signed callback clients and real route authentication/authorization. Browser-container proof may run separately under its own marker, but a stubbed browser cannot certify browser qualification.

Required scenarios and assertions:

| Scenario | Expected evidence |
|---|---|
| E01 Single-item happy path | Accepted PDF → extracted source → verified binding → stored map/eleven-field Section → original Third output → typed prompt pass → private package → complete browser evidence → typed scene pass → original-teacher preview/approval; all refs form one consistent lineage. |
| E02 Three-item mixed outcome | Every work item traversed; one blocked Section does not erase successful siblings; final counts match storage and no extra Continue. |
| E03 Crash and lost acknowledgments | Kill/restart between external response, persistence and receipt; committed results reused, uncertain source conversations reconciled, no invented pass or blind repeat. |
| E04 Concurrent duplicate operations | Parallel claims/dispatch/review requests preserve uniqueness, leases, exact identity and version checks; stale successors cannot be promoted. |
| E05 Review change and postapproval corrections | Visual and factual/mixed requests take their correct distinct paths, produce qualified successors/new reviews, preserve prior history and exact decision consumption. |
| E06 Privacy and control boundaries | Other teacher/admin/guest denied private content and review; missing secrets rejected; hold/cancel honored at dispatch boundary; no private bodies in workflow/log/export metadata. |
| E07 Invalid evidence and outage | Empty checks, fake PNG, wrong identity, unavailable JEV/browser/bridge and false consumption proof cannot advance to approval or disappear from recovery. |

**Done:** the report contains persisted references/assertions and exact commands for E01–E07, clearly separating fixture-provider evidence from container and live results. Passing isolated unit tests cannot substitute for this gate.

### R24 — Perform bounded real integration checks and close the handoff

**Depends on:** R21, R22, R23. **Covers:** full proof of concept and report section C.

**Files:** existing marked live/container suites for 008/010/011/012/013, their sanitized evidence manifests, and `docs/reviews/V3-POC-REPAIR-IMPLEMENTATION-REPORT.md`.

- Verify installed NotebookLM MCP tool schema/authentication and source-scoped grounding; verify TypeSafe's current official API contract, account entitlement, supported dedicated model and returned typed schema. Record verification date/version. Historical default strings and mocked SDK calls are not provider verification.
- Obtain necessary explicit authorization for billable/live calls and teacher-source upload, with a disposable approved PDF, budget/call limits and stop conditions. This task document itself does not authorize external disclosure or spend. Missing credentials/authorization block this gate only.
- Run the real isolated browser runner and preview UI. Assert actual PNG capture, actual interaction, complete checks and measured duration. Do not weaken browser sandbox/network controls to make fixtures work.
- Run one bounded live production-path case through NotebookLM, prompt JEV, authoring, scene checks/JEV and teacher review. Prove multiple-item/correction durability with R23/R21; extend live coverage only within the approved budget. Record exactly which live branches were exercised and which remain local-only.
- Run the final selected regression suites, inspect the complete diff (including new files), check for production `Tests\` references, success placeholders, fake screenshot bytes and accidental default keys. Review semantically; text search alone is not proof. Run whitespace/syntax/build checks and document all skips/failures.
- Do not mark overall completion if any confirmed finding remains unfixed. If external gates cannot run, label the outcome “repairs implemented; live/deployment verification incomplete,” list blockers and next commands, and do not claim a complete proven PoC.

**Done:** final report meets section 7 and distinguishes implementation completion from each evidence gate. No commit or deployment without a separate request.

## 5. Execution checkpoints

Execute in this valid order (other orders must preserve each task's dependencies):

| Group | Ordered tasks | Before | Exit checkpoint |
|---|---|---|---|
| A Transport/configuration | R00, R01, R02, R03, R04, R13 | Record baseline; run each new regression before its fix | Exact-byte signing, explicit secrets, correct callback/model identities |
| B Mapping/prompts | R05, R06, R07, R08, R09 | R05 activity persistence; R06 real-signature regression; R07/R08 workflow tests | Real stored maps/Third outputs/typed qualifications for all work items |
| C Scene gates | R10, R11, R12, R14 | Runner-contract, evidence-boundary and attempt-transport tests | Actual browser evidence plus typed scene gate reaches review-ready |
| D Review/corrections | R15, R16, R17, R18, R19, R20 | Exact decision, cleanup, page, bridge-start and outbox regressions | Interactive review, consumed decision proof and executable correction paths |
| E Acceptance | R21, R22, R23, R24 | Replay/server/backend/browser prerequisites checked explicitly | Connected local results, deployment results, authorized live results and limitations reported separately |

After each group, rerun that group's focused tests plus prior groups' regressions. After groups B–D run the V3 PHP and offline Python suites. Use explicit integration lanes for tests needing services. Do not repeatedly broaden testing without a concrete new risk; do not skip a required lane by calling a stubbed test equivalent.

## 6. Verification commands for the future implementer

These are instructions, **not commands claimed to have passed when this plan was written**. Run from `/home/moahmmed/college_project`. Use isolated test configuration and a disposable database. R00 must establish external fixture opt-ins before broad Python runs.

```sh
git status --short
git diff --check
vendor/bin/pest tests/Feature/V3
vendor/bin/pest
.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests --collect-only -q
.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests -m 'not live and not container and not temporal and not browser_ui and not integration and not deployment' -q
.venv/bin/python -m compileall -q v3_engine
npm run build
```

For each task, substitute its test file path into the matching runner, for example:

```sh
vendor/bin/pest tests/Feature/V3/SceneCheckEvidenceBoundaryTest.php
.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests/qualification/test_runner_activity_contract.py -q
vendor/bin/pest tests/Integration/V3/ReviewEffectConcurrencyTest.php
```

After preparing/authorizing the corresponding lane, use explicit marker selection and only its required opt-in environment (R00 must document the exact opt-in names and service setup):

```sh
.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests -m 'temporal and not live' -q
.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests -m 'container and not live' -q
.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests -m 'browser_ui and not live' -q
.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests -m 'integration and not live' -q
```

Expected results: new regressions first demonstrate the reviewed behavioral defect, then pass; V3 regressions introduce no failures; baseline legacy failures remain separately explained; external lane skips never count as passes. Build/compile success does not certify workflow behavior. A live command must target the approved scenario/tests explicitly rather than run every `live` test without budget controls.

## 7. Required implementation report and stop conditions

Keep `docs/reviews/V3-POC-REPAIR-IMPLEMENTATION-REPORT.md` current with:

1. Starting HEAD/workspace inventory and unrelated changes preserved.
2. R00–R24 status: pending/in progress/done/blocked, dependencies, actual files changed and reason.
3. Every B/D finding mapped to its fix and behavioral red/green evidence; explain any already-fixed finding using current evidence.
4. Exact commands, counts, duration, baseline/new failures, skips and service/runtime versions; no transcribed historical pass as a fresh result.
5. E01–E07 outcomes, persisted safe refs/lineage, retry/race/replay evidence and original-teacher authorization results.
6. Separate no-dev/database, Temporal, browser/UI, mock-provider and live-provider gate outcomes. Record actual model/adapter versions without credentials/private content.
7. Migration compatibility decisions, deployment prerequisites, supported old workflow histories, remaining risks and precise next steps.
8. No commit/deploy performed unless separately authorized; no claims beyond the evidence collected.

Stop dependent work for unresolved contradictory business contracts, unsafe migration changes to an already-applied schema, unsupported history transitions without an agreed compatibility strategy, required unavailable service/credential/budget, or an unknown deployment database for its backend gate. Continue independent tasks. Missing credentials are not a reason to substitute fake production results. Record unavailable live gates explicitly instead of inventing a successful demonstration.

## 8. Plan review record

This plan covers all 23 supplied comments and adds connected verification to expose the failure mode that allowed isolated passing tests to coexist with disconnected production code. Each task names concrete deliverables, prerequisites, governing specs/review IDs, test scenarios and completion evidence. The order in section 5 satisfies the listed dependencies, including dedicated model capture before prompt/scene qualification and correction admission before outbox delivery.

Only this task document was authored for this planning request. Application implementation and tests are assigned to the future implementer. The source review and numbered specs remain the authority/context; this backlog does not certify their runtime implementation.
