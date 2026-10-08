# Independent implementation review — Spec 016

Date: 2026-10-07. Repository: `/home/moahmmed/college_project`.

## Verdict

**Incomplete: the agreed production workflow does not work end to end.** The implementing agent's “Complete / all tasks finished” statement is not supported by the code or verification evidence. Credentials alone cannot resolve the defects below.

The authority is `specs/016-v3-notebooklm-direct-pdf/task-execution-plan.md`, its tasks, requirements and contracts. Required behavior is original PDF plus unchanged approved `steps.txt`, verified source readiness and format, PDF-only Divide, both-source Execution with exact `System Ready`, then `Start with First lesson` and one `Continue` per subsequent mapped work item in the recorded conversation. Each combined response must preserve First/Second/Third data, commit through durable identities and cursor fences, and connect to qualification, scene checks and original-teacher review. A local JEV waiver is not a browser pass or teacher approval.

This review inspected the production SDK wrapper, protocols, registered activities, workflow, Laravel clients/controllers/services and their tests. It ran the focused suites and additional isolated reproductions. No application fixes, production migrations, provider uploads, live model calls or browser authentication were performed. Temporary reproduction files were outside the repository and used disposable test databases or mocks.

## Independent verification results

| Command/check | Observed result | What it proves / limitation |
|---|---|---|
| `.venv/bin/python -m pytest v3_engine/tests/notebooklm -m 'not live' -q -rs` | 45 passed, 2 skipped, 2 deselected | Focused offline suite passes. The two skips require explicit Temporal opt-in. |
| `.venv/bin/python -m pytest v3_engine/tests/notebooklm/test_workflow_replay.py v3_engine/tests/notebooklm/test_workflow_progression.py -m temporal -q -rs` | 2 passed | Their current synthetic histories/fake activities work; this is not production activity wiring or historical rollout compatibility proof. |
| `php artisan test tests/Feature/V3/NotebookLM tests/Integration/V3/NotebookLM` | 39 passed, 456 assertions | Existing focused PHP tests pass; missing negative and connected-path cases remain. |
| `.venv/bin/python -m pytest v3_engine/tests/notebooklm/test_live_direct_pipeline.py -m live -q -rs` | **2 skipped** | Dedicated disposable storage state was not provisioned in the test environment. No live NotebookLM success is established. |
| SDK/protocol reproduction | `AttributeError: 'SdkProviderSession' object has no attribute 'conversation'` | Production objects cannot execute the direct chat protocol. |
| Direct Divide reproduction with compatible fake session | Zero query claims; result reference is literal `divide` | A successful provider response is posted with no real durable query identity. |
| Activity reproduction with actual client return shapes | Conversation and cursor become `None`; completion/bundle refs become `None` despite populated inputs/results | Activity double-unwrapping loses valid context and committed references. |
| Source-readiness reproduction | Both polls returned `processing`; protocol nevertheless returned `status: bound` | Timeout does not enforce the source-readiness gate. |
| Temporary PHPUnit state probes | Completed cursor accepted W999 with stale version, unrelated conversation and wrong digest; unrelated Lecture/conversation committed with HTTP-equivalent service status 201 | Missing business invariants are reproducible through real Laravel services. These probe tests deliberately assert the current defective behavior; “pass” is not correct-business-behavior evidence. |

Live case-reference environment variables were absent as well. Skipping the dedicated test gate does **not** prove that the user's existing Brave/MCP login is invalid; the dedicated SDK test environment and a previous login are different evidence. No runtime browser was launched to work around it.

## Findings, ordered for remediation

### R016-01 — P1: Align the SDK session with the protocol before any provider operation

Evidence: `v3_engine/notebooklm/sdk_provider.py:37` implements file/chat operations directly; `SdkSessionProvider.open` yields that object at lines 231–234. `ports.py:108` also describes a flat session. However `protocol.py:127` calls `session.files.create_notebook`, and `protocol.py:482` calls `session.conversation.ask_once`. Neither namespace exists on the production object.

Reproduction with the actual wrapper and an `AsyncMock` SDK client raises the AttributeError above before a chat call. Source upload has the equivalent `.files` failure. The unit fakes expose the nonexistent namespaces, hiding this defect.

Fix: choose one coherent port shape, update production callers and fakes together, and exercise the actual yielded session through ingestion and chat with SDK transport doubles. Do not change credentials to mask an object-interface error.

### R016-02 — P1: Wire direct Divide and Execution into the actual mapping path

Evidence: `v3_engine/activities/map_case.py:8–19` unconditionally creates legacy `MapService(KnowledgeSourceAdapter(), ...)`. `case_workflow.py:324–333` calls this activity for direct cases too. `sources/notebooklm_adapter.py:309–313` requires a single `source_id`; the direct binding exposes a two-source manifest instead. `lessons/map_service.py:162–211` also schedules the legacy coverage question, which the direct protocol excludes.

Production search finds no caller of `DirectLessonProtocol.divide` or `.execution`; only `process_lesson` is called from the new activity. Thus the required both-source Execution / recorded `System Ready` handshake is not part of the direct workflow.

Fix: dispatch mapping using the captured case protocol; execute the direct claimed Divide and Execution path, persist its map and conversation/cursor, and require acknowledgement before lesson start. Retain the legacy path for legacy cases. Test the registered production mapping activity against real Laravel callback handlers, not a fake `ready` return.

### R016-03 — P1: Claim actual query identities before sending stateful turns

Evidence: `protocol.py:495`, `:537` and `:575` post results using literal `divide`, `execution` and `lesson-{ordinal}`. These methods never call `HttpLaravelNotebookClient.claim_lesson_query`, never use its disposition and UUID, and never record the history anchor before sending. `AcceptCombinedLesson.php:42–44` looks up a real `LessonQuery` row by ID and rejects absent rows.

A successful remote lesson therefore cannot be accepted normally. Retrying an activity can repeat the stateful question after a lost/failed callback instead of reconciling the already-sent turn.

Fix: claim with the canonical request digest, captured conversation, source roles, cursor version and history anchor; send only for `send_now`; use the returned query UUID for every result/uncertain callback. Recover `reconcile_only` from anchored history or known private evidence, never a new send. Exercise lost-response/result-post failure against real callback routes and count transport sends.

### R016-04 — P1: Use the correct activation identity and preserve client return values

Evidence: `case_workflow.py:387` supplies the **map** activation ID to `process_notebook_lesson`. The activity uses it at line 21 with `get_binding`; `laravel_client.py:182` requests `/source-activations/{id}/binding`. `SourceBindingController.php:14` looks up a **SourceActivation**, so a normal distinct MapActivation ID produces 404.

Additionally `HttpLaravelNotebookClient._request` already unwraps `data` at line 107. The activity unwraps again at lines 30 and 48. An isolated reproduction with correctly populated flat context/result dictionaries loses the conversation, expected cursor version, bundle, completion and prompt references while reporting `status: completed`.

Fix: fetch the captured binding with a contractually correct source activation or case/binding operation; consume one consistent response shape and the actual context field names. Missing context is a blocker, not a swallowed exception and guessed conversation. A completed activity must carry verified committed refs.

### R016-05 — P1: Do not equate remote file registration with source readiness

Evidence: `sdk_provider.py:85` uploads with `wait=False`. `protocol.py:167–174` and `:199–206` immediately confirm upload effects. `SourceEffectLedger.php:246–276` responds by marking both manifest rows `ready`. Later the Python polling loop at `protocol.py:217–225` exits normally when the polling deadline expires, then configures chat and returns `bound`.

The reproduction returned `bound` after every actual observed source status was still `processing`. PHP is therefore also told readiness occurred before it was observed. The completed/reconcile chat-configure path does not perform the same settings readback as `send_now`.

Fix: persist known source identity separately from processing status; mark ready only after provider readiness verification. Timeout stays pending/uncertain and resumes polling the same IDs. Verify both source type/title and chat configuration on reconciliation before binding. Add processing-timeout, terminal-error and crash-between-registration/readiness tests through the actual coordinator and adapter.

### R016-06 — P1: Enforce phase, ordinal, conversation and digest fences in query claims

Evidence: `DirectQueryLedger.php:116–139` only adds basic Divide/Execution argument checks. At lines 195–204 it increments a cursor and sets an arbitrary requested lesson ordinal without checking the expected cursor version, current phase, mapped ordinal range or recorded conversation. `computeRequestDigest` is not used to validate claims; lines 216–217 accept the supplied digest or a generic fallback. Existing-query handling does not compare the full request identity. `LessonQueryController.php:35` additionally allows legacy `first`/`continue` kinds for direct cases.

Reproduction: a cursor with phase `completed`, version 10 and a two-item map accepted `lesson_continue` ordinal 999 with expected version 1, a wrong conversation and an all-zero digest, returning `send_now` and changing the cursor to W999.

Fix: enforce the direct kind allowlist and complete transition table under case/control/cursor locks. Recompute and compare request identities, reject stale versions and changed duplicates, and reject out-of-range/out-of-order work. Add actual signed HTTP negative tests plus concurrent conflicting-claim tests.

### R016-07 — P1: Bind the combined response to the mapped lesson and recorded conversation

Evidence: `AcceptCombinedLesson.php:82–85` only requires a nonempty output conversation. Its validator call at line 135 receives the work ordinal but not the authoritative mapped lesson title/path. All three artifacts can agree with each other yet describe a different mapped lesson.

Reproduction: replacing all Lecture titles in a valid W1 fixture with `Completely Unrelated Lesson`, and returning `unrelated-conversation` rather than the claim's conversation, still produced status 201 and a committed bundle. The existing “connected” fixture itself submits a different conversation at `ConnectedDirectPipelineTest.php:271`.

Fix: compare the response's lesson identity with the mapped work item and its conversation with the recorded request/cursor. Reject mismatch without advancing; retain private raw evidence. Cover title/path mismatch and conversation rotation at the real commit boundary.

### R016-08 — P1: Recheck controls and cursor invariants inside the commit transaction

Evidence: `AcceptCombinedLesson.php:57` checks hold/cancel before the transaction. Lines 115–131 check phase, ordinal and optionally the supplied version before artifact staging. The transaction later locks the query and cursor at lines 196 and 214 without repeating those checks or taking the case/control lock. A concurrent control/cursor change after validation can therefore pass through. The inner already-recorded branch also returns existing refs without comparing the competing payload's outcome.

`DirectControlRaceTest.php` places a hold before calling the service; it does not overlap control mutation with an in-flight commit, so its name does not prove the required race protection.

Fix: lock in a consistent order and repeat permission, phase/ordinal/version, query-state and exact-duplicate checks immediately before projection. Require the captured version rather than allowing omission. Add an overlapping-process test with a barrier between prevalidation and transaction, proving hold/cancel cannot permit advancement and changed duplicates cannot be accepted as exact replays.

### R016-09 — P1: Make uncertainty recoverable without invented identities

Evidence: direct mapping uncertainty at `case_workflow.py:336` and lesson uncertainty at `:395` use `wait_condition(lambda: False)`. A reconciliation signal cannot make that predicate true. The later receipt predicate does not help a workflow already stuck at either earlier wait.

`recover_turn_from_history` exists in `protocol.py:594` but has no production caller. `ReconcileDirectProtocol.php:79–117` only reposts stored lesson answers, does not retrieve anchored provider history for lost replies/Divide/Execution, and fabricates `convo-reconciled` at line 99 when the conversation is unavailable.

Fix: wait on a verified reconciliation/control predicate tied to the same query; recover anchored history through the provider port, persist the actual conversation, and resume once. Missing conversation/history remains uncertain. Prove workflow progress after a timeout and restart without another Start/Continue send.

### R016-10 — P1: Replace simulated connected success with production-path evidence

Evidence: `test_workflow_progression.py` registers fake activities for source ingestion, mapping, lesson processing, qualifications, authoring and review. These do not exercise the broken production interfaces above. `ConnectedDirectPipelineTest.php:58–59` fakes storage and completion dispatch, constructs ready records manually, and finishes its “review boundary” by comparing owner IDs at lines 305–310. It neither generates a scene nor submits a real teacher review decision. `test_workflow_replay.py:147–235` generates a history with the current workflow and replays that history with the same implementation; it does not replay saved histories from the previous implementation.

Fix: use registered production activities with provider transport doubles only; connect real Laravel claims, callbacks, completion delivery, qualification, scene authoring/checks and owner review routes. Save and replay representative actual prechange legacy histories covering source/lesson/review waits. Report fake transport coverage separately from live provider proof.

### R016-11 — P1: Complete the mandatory live gates and report skips as incomplete

Evidence: `test_live_direct_pipeline.py:38–120` directly uses the SDK and stops after lesson 1. It does not configure the combined response format, poll readiness, run Continue to map exhaustion or exercise Laravel/Temporal persistence. Lines 112–114 expect `--- First Output ---` / `--- Second Output ---` / `--- Third Output ---`, contradicting the approved `=== FIRST ===` / `=== SECOND ===` / `=== THIRD ===` delimiters. The Unit3 test at lines 125–195 ends after Execution, without any lesson bundle or downstream review.

`test_sdk_contract.py:75–85` defines a mock 429 handler and counter but never makes a request or asserts a send count; its single-send test passes without testing single-send behavior.

Both live tests independently skipped during this review. Nevertheless `implementation-report.md:5` declares complete and all tasks T001–T020 are checked. The execution plan explicitly requires full live loops and connected gates; skipped authentication is a blocked gate, not verification of that behavior.

Fix: implement the specified live small mixed/scanned PDF and unchanged Unit3 full loops with provider readiness/configuration, authoritative map counts, correct format, persisted refs and connected downstream evidence. Perform actual transport-level retry tests. Record live JEV versus demo waiver, genuine browser checks and original-teacher decision separately. Until gates pass, mark the outcome partial/blocked and list unfinished tasks.

### R016-12 — P2: Keep raw provider identifiers out of workflow history

Evidence: `protocol.py:247–253` returns the notebook and both provider source IDs. `ingest_case_source.py:28–35` returns that dictionary directly as a Temporal activity result, so it enters workflow history. Task T008 requires opaque application refs only and notes explicitly identify Temporal activity results as history.

Fix: return opaque binding/effect status refs and load provider identifiers privately inside activities. Inspect recorded history in a test to ensure raw IDs/content do not leak into payloads.

## Integrity and rollout observations

- Original Unit3 exists at the supplied Downloads path, is **56,937,625 bytes**, and its independently streamed SHA-256 is `e34593f96812bb3c0e83cf813d7fd55f26a6d3862d25a3e02c32bbce3e3da621`. This review only read it. Local integrity does not prove successful remote upload or faithful lesson outputs.
- Root `steps.txt` is 23,622 bytes with SHA-256 `96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec`, matching the approved digest.
- The actual config key is `services.v3.notebook_protocol`, with fallback `legacy_staged_v1` in `config/services.php:43`; it is not a `pipeline_version` config key. `StartProcessingCase.php:190–229` captures the protocol/framework on the case. Focused capture tests pass. Runtime behavior is intended to follow that immutable case capture.
- The SDK wrapper explicitly requests `allow_headless=False` and zero rate-limit/server-error retries. That is positive configuration evidence; the vacuous transport test does not prove no hidden resend across all SDK operations.
- The workspace has extensive existing uncommitted/untracked work. This review changed only this report. No trustworthy preimplementation file-hash snapshot was available to this review, so the implementing agent's claim that every prior file remained unchanged cannot be independently certified from current Git status alone.
- This review did not change the feature selector, commit, deploy or leave application services running. The Temporal tests used their temporary test-server contexts.

## Repair order and completion checkpoints

1. **Ports and callback shapes:** R016-01 and R016-04. Checkpoint: actual production SDK/session and activity/client shapes pass connected tests with all required refs intact.
2. **Original sources:** R016-05 and R016-12. Checkpoint: immutable files, actual readiness/type/config verification, opaque history, recoverable polling without reupload.
3. **Durable sequence:** R016-02 and R016-03. Checkpoint: registered production mapping sends exactly Divide → Execution → exact acknowledgement, and every lesson uses a persisted claim/anchor before Start/Continue.
4. **State and projection integrity:** R016-06, R016-07 and R016-08. Checkpoint: invalid/stale/wrong-lesson/conversation requests fail, and genuine concurrent controls/duplicates cannot advance incorrectly.
5. **Recovery:** R016-09. Checkpoint: lost replies/callbacks recover and the workflow resumes once, with actual identities and no repeated stateful sends.
6. **Proof and reporting:** R016-10 and R016-11. Checkpoint: real connected scene/check/review path, previous legacy histories, meaningful transport tests, both mandatory live full loops, and an accurate completion report.

Tasks T006/T008/T010/T012/T014/T016/T019/T020 require renewed evidence; T015's claimed connected/replay proof also requires replacement. Existing passing coverage remains useful regression coverage, but does not close the listed defects. Do not enable direct-default rollout on these results.

**Final acceptance condition:** all reproduced defects have regression tests and fixes; actual source-to-final-original-teacher-review behavior passes for every mapped work item; uncertainty never causes an extra provider turn; required live gates pass; waived JEV behavior is clearly labeled. Until then, do not describe the platform as fully working or claim that only a JEV API key is missing.

## Follow-up repair status, 2026-10-07

The findings above record the **initial** review. They are retained as evidence of the defects before repair. A later repair in the same workspace addressed R016-01 through R016-07 and R016-12 in code, and added guards for R016-08 and R016-09. The SDK session now implements the file and conversation ports; registered mapping invokes the direct claimed Divide and Execution sequence; lesson processing carries actual binding, conversation, cursor and committed refs; uploads remain processing until source readiness and inventory are observed; Laravel validates direct kind, canonical digest, phase, ordinal, conversation, mapped title and exact result identity. An uncertain query has a registered read-only history reconciliation activity, and workflow sleeps/retries instead of waiting on a constant false predicate. Direct activity results contain opaque application refs.

New regression evidence includes signed HTTP negative tests for a forged digest, legacy kind, changed conversation and wrong mapped lesson; a source-ledger registered-to-ready test; a processing-timeout test; SDK-port and registered-activity wiring tests with provider transport doubles; and a history test that rejects extra manual turns. Focused results after repair: `php artisan test tests/Feature/V3/NotebookLM tests/Integration/V3/NotebookLM` **42 passed, 479 assertions**; `.venv/bin/python -m pytest v3_engine/tests/notebooklm -q -m 'not live'` **51 passed, two Temporal-marked skips**; explicit Temporal replay/progression **two passed**. These tests establish offline behavior and integration boundaries under doubles, not a live provider or complete scene/review path.

R016-08 still lacks a process-barrier test that overlaps a hold/cancel mutation with an in-flight commit. R016-09's lost Divide reply cannot be safely recovered if NotebookLM never returned a conversation ID; it blocks rather than resends. R016-10 and R016-11 remain open: no saved prechange legacy workflow histories or real connected source-to-teacher-review test, and the corrected live tests still skip. The read-only MCP check returned `authentication_required`; no SDK storage state is provisioned and no browser was launched. Consequently no PDF was uploaded during this repair, and no real JEV, browser qualification, scene generation, or teacher decision was verified. `specs/016-v3-notebooklm-direct-pdf/implementation-report.md` now states partial status and its unproved task checkboxes were reopened. The default protocol remains `legacy_staged_v1`.
