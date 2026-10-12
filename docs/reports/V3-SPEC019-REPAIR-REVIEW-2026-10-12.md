# Spec 019 repair implementation review

**Date:** 2026-10-12 · **Scope:** current implementation versus repair tasks T066–T115 and the supplied delivery summary.

## Verdict

**Not accepted as complete.** Some repairs are real, but the core web-to-working-pipeline chain still has execution blockers. This is not merely an unavailable paid/live gate.

The reviewer reproduced a production worker initialization failure against a real managed Temporal server. In that server's empty namespace, the signed bridge returned HTTP 202/accepted while zero workflows existed. Service-backed diagnostic calls also showed invented provider identifiers, an incorrect lesson-turn sequence, duplicate remote sends, reset failure reported as success, and empty prompt exports reported as completed.

T112 remaining open is appropriate. It does not explain or excuse incomplete offline admission, service setup, persistence, controls, import, replay or connected acceptance implementation.

## Independently executed verification

| Check | Result | What it establishes |
| --- | --- | --- |
| Laravel WebPipeline feature/integration suites | 143 passed, 1 skipped; 776 assertions; 14.21 s | Existing application tests pass; connected test still substitutes internal bridge/preview responses. |
| Python WebPipeline offline plus scene service recovery | 102 passed, 1 skipped, 3 deselected; 24.00 s | 84 WebPipeline passes plus 18 scene service passes; useful isolated service/browser coverage. |
| Python controls/replay with Temporal opt-in | 9 passed; 5.52 s | Real test server starts, but capacity uses a mock child and replay only constructs Replayer. |
| Fresh-namespace signed admission | HTTP 202 accepted; actual Temporal workflow list empty | Admission does not create the supervisor or establish durable acceptance. |
| Actual production run_workers initialization | ValueError | New synchronous archive activities lack an activity executor. |
| Documented worker module command | Exit 0 in 0.81 s; no output; no worker started | `python -m v3_engine.terminal_pipeline.cli worker` does not invoke main. |
| Standalone behavioral probes | Confirmed gaps listed below | Isolate real handler/activity logic without paid provider requests. |

The Python suites overlap by six cases: 105 unique passes and one skip, not 111 unique passes. The existing PHP/Python suite totals are reproducible; their coverage is insufficient to establish the claimed chain.

Test environments used isolated in-memory Laravel SQLite, temporary artifact roots, and managed local Temporal servers. No live provider requests, login, production data changes, application fixes or commits were made. The workspace selector remains Spec 020.

## Findings

### F01 — P0: production shared worker cannot initialize

**Evidence:** `run_workers` on a real local Temporal client raised:

> Activity verify_archive_activity is not async so an activity_executor must be present

[worker.py](../../v3_engine/terminal_pipeline/worker.py:179) registers the synchronous functions from [import_activities.py](../../v3_engine/web_pipeline/import_activities.py:28) without supplying an executor. This occurs before any web or terminal processing starts.

**Impact:** the shared production worker startup path is broken by the new registration. Passing tests construct smaller workers and do not exercise this configuration.

**Reassess:** T068, T077, T082, T101, T111, T115. Fix synchronous activity execution/registration and add a startup test using the production worker function.

### F02 — P0: admission still acknowledges unstarted work

[bridge.py](../../v3_engine/web_pipeline/bridge.py:158) obtains a handle to a preexisting supervisor and calls execute_update. It never performs Update-With-Start. Connection/update exceptions are swallowed, then the handler manufactures accepted/queued and stores it in module dictionaries.

**Actual Temporal evidence:** a signed request against a new real `v3-terminal` namespace returned accepted, followed by an empty workflow listing. Additional isolated handler probes returned accepted with no client and after an update exception. Reloading the module lost the command receipt.

**Impact:** successful HTTP dispatch is still not durable execution. Fresh startup, acknowledgment loss and process restart requirements remain unmet.

**Reassess:** T069, T074, T104–T106. Resolve admission through actual Update-With-Start and authoritative retained receipts; propagate uncertainty/unavailability instead of success.

### F03 — P0: actual web inputs are not prepared for NotebookLM processing

The Laravel manifest contains upload_attempt_id/accepted_input_ref/framework_version_id, but [subject_workflow.py](../../v3_engine/web_pipeline/subject_workflow.py:124) reads source_ref/framework_ref and substitutes `src_1`, `src_framework`, etc. [activities.py](../../v3_engine/web_pipeline/activities.py:43) substitutes notebook ID `nb_{run_id}`.

There is no account proof verification, notebook creation, upload/readiness or chat configuration path in these web activities. They immediately call send_turn. ApplicationClient exists but is not called by the PDF activities to resolve inputs or perform intake/callback persistence.

**Transport probe:** with a Laravel-shaped manifest and an external transport double, the production functions sent invented notebook/source identifiers. No create/upload/verify/configure request occurred. This probe isolates production workflow/activity logic with a local executor; it is not presented as end-to-end Temporal evidence.

**Reassess:** T070, T081, T083, T085, T089. Resolve actual accepted bytes and private service context, invoke setup through existing NotebookTerminalService, then send with returned provider IDs.

### F04 — P1: lesson protocol and saved/exported counts remain untruthful

[execute_lesson_turns](../../v3_engine/web_pipeline/activities.py:149) treats Execution as the first lesson response and sends `Continue {ordinal}` afterward. It omits `Start with First lesson` and changes literal Continue. A System Ready response can therefore become a purported saved lesson.

The same function falls back to one prompt when no scenes were extracted. It records counts in its local operation store without committing lessons/prompts through Laravel. Raw turn/archive evidence is not saved before advancing.

[export_prompts_activity](../../v3_engine/web_pipeline/activities.py:264) writes `prompts: []`, uses `exported_at: "now"`, and defaults its recorded count to one.

**Probe:** two PDFs each mapped to two lessons produced `lessons_saved: 4`, `prompts_saved: 4`, `pdfs_completed: 2`, while the responses were System Ready/malformed answers with no usable prompts and the actual export had zero prompts.

**Reassess:** T084–T089. Follow the existing protocol exactly, commit actual parsed records and raw evidence, and derive counts/exports from verified application data.

### F05 — P1: duplicate sends and failed resets still advance

[WebOperationStore](../../v3_engine/web_pipeline/operation_store.py:39) can persist when explicitly given a file path, but the production [activity singleton](../../v3_engine/web_pipeline/activities.py:20) uses `WebOperationStore()` without one. Completed/pending claims return a record and activity execution then sends again. Receipt recovery also looks up an operation ID in a dictionary keyed by receipt UUID.

**Probe:** the same completed Divide operation invoked twice made two remote transport calls. Recreating the default store lost its operations.

[reset_conversation_activity](../../v3_engine/web_pipeline/activities.py:227) ignores both claim and provider reset exceptions and reports completed. It does not verify archive or empty-history evidence. [subject_workflow.py](../../v3_engine/web_pipeline/subject_workflow.py:195) ignores reset and export outcomes.

**Probe:** an external reset exception returned completed/ok. Injecting failed reset/export outcomes into workflow logic still returned prompts_exported/ok with a completed PDF.

**Reassess:** T073, T084, T086–T088, T100, T103–T104. Restore durable send fences/receipt recovery and enforce every settled outcome before advancement.

### F06 — P1: generate/retry/active cancellation are not connected

[bridge.py](../../v3_engine/web_pipeline/bridge.py:263) rejects generate, although Laravel permits it. Retry is accepted but has no execution branch. Command payloads omit scene selection/settings/phase/consent at the dispatcher boundary.

The cancel branch targets only supervisor cancel_queued_v1; the subsequent child-control branch containing cancel is unreachable for cancel requests. An accepted command is still recorded consumed even when applied is false.

**Probe:** generate returned HTTP 422. Cancel targeted only the supervisor, returned applied false and its receipt returned consumed true. No active child control request was made.

ApplicationSubjectWorkflow returns completion in API mode without dispatching scenes. ApplicationSceneWorkflow runs generation/build when invoked separately but does not schedule the registered browser check or commit review readiness in its shown path.

**Reassess:** T092, T095, T097, T100–T102, T109. Route complete captured commands to actual bounded children, distinguish requested/applied/consumed, and connect checks/review persistence.

### F07 — P1: new payload allowances violate the private reference contract

[bridge.py](../../v3_engine/web_pipeline/bridge.py:169) passes the full manifest, including educational titles/filenames, into the supervisor update. [contracts.py](../../v3_engine/web_pipeline/contracts.py:240) explicitly retains maps, bundles and remote conversation IDs; bundles can contain prompt text. Scene workflows also serialize prompts/settings/titles directly.

**Probe:** a private-content sentinel was forwarded to the Temporal-client update input. sanitize_activity_result retained a private prompt sentinel in a 40,127-byte result, exceeding the required 32 KiB limit.

**Impact:** retaining these fields is not a safe metadata repair. It bypasses the specification's opaque-ref, history privacy and payload bounds.

**Reassess:** T070, T082, T091, T103. Store bodies/config/remote IDs privately; serialize only bounded local refs and permitted safe metadata. Verify actual histories and error causes.

### F08 — P1: Python import is still fabricated; queued CLI import is misrouted

[import_activities.py](../../v3_engine/web_pipeline/import_activities.py:28) still validates UUID/root existence and manufactures verify/copy/materialize receipts. Copy does not copy files, materialize does not call the application, and finalization defaults item_count to two. Compatibility aliases satisfy imports but do not implement item verification/materialization.

**Probe:** random nonexistent archive verification and copy both returned completed; the copy destination was not created.

The new [CLI queue branch](../../app/Console/Commands/V3/ImportTerminalRun.php:139) creates a command action `import_terminal_run`. DispatchRunOutbox sends every non-submit action to the command endpoint, which rejects this action. It does not admit web_import_v1 or stage a resolvable import manifest. Repeated CLI queue requests also generate new command IDs.

**Reassess:** T075–T079, T108. Preserve useful synchronous Laravel import functionality, but connect queued import through a real captured manifest and durable import admission/activities. The cited TerminalImportTest contains no explicit eight-PDF/63/89 reference assertions; its passes alone do not establish the claimed reference verification.

### F09 — P1: the tests still bypass the repaired boundary

- [Laravel connected test](../../tests/Integration/V3/WebPipeline/ConnectedWebPipelineTest.php:164): Http::fake returns accepted bridge response; AcceptWebLesson is called directly with test-authored content; preview responses are also supplied.
- [Python connected test](../../v3_engine/tests/web_pipeline/test_connected_pipeline.py:225): creates ApplicationSubjectWorkflow directly, injects _activity_executor and calls wf.run; does not test production registration, admission-to-worker connection or Laravel persistence.
- [Capacity test](../../v3_engine/tests/web_pipeline/test_controls_capacity.py:43): uses mock_child_execute with mock admission. The production supervisor was changed to prioritize the mock branch; passing these tests is not real mixed production chain evidence.
- [Replay test](../../v3_engine/tests/web_pipeline/test_replay.py:146): still only constructs Replayer and checks non-null, without fetching/replaying history.
- [Python live gate](../../v3_engine/tests/web_pipeline/test_live_web_pipeline.py:36): still checks flags/credentials only. [Laravel live gate](../../tests/Integration/V3/WebPipeline/LiveApplicationGateTest.php:105): still stops at staging count/mode.
- Tests/Support/V3/WebPipelineRuntime.php records local counters and deletes a test directory; it does not start bridge/Laravel/Temporal/workers as required by T068.

The new external NotebookLM transport double and actual browser fixture checks are useful, but they do not complete a linked application acceptance test. T110 is incomplete implementation, independently of T112 being an appropriately open live execution gate.

**Reassess:** T068–T069, T080, T089, T099, T104–T107, T109–T110, T114–T115. Add the actual production-startup/linked-chain checks before certifying fixes.

### F10 — P1: callback lease/version validation does not guard bundle mutation

By code inspection, [result controller](../../app/Http/Controllers/V3/Internal/WebPipelineController.php:143) accepts the bundle before CommitOperation validates the lease. These use separate transactions. A valid bundle can therefore be committed before the result request is rejected for its operation lease.

[ClaimOperation](../../app/Services/V3/WebPipeline/ClaimOperation.php:24) stores expected_version but does not compare it with current state. [CommitOperation](../../app/Services/V3/WebPipeline/CommitOperation.php:33) does not enforce supplied expected_version or lease expiry before advancing state. This finding concerns signed worker/state fencing; it is not a guest authentication bypass.

AcceptWebLesson also contains fallback production records with invented document/extracted-source hashes/paths rather than requiring verified input lineage. Those paths should not manufacture prerequisites for live acceptance.

**Reassess:** T072–T073, T085, T104. Validate exact scope/digest/current lease/version first, then atomically commit bundle/cursor/receipt/event. Add rejection-with-zero-mutation and independent-process race tests.

### F11 — P2: delivery documentation and statuses remain unsupported

[Operator manual](../V3-WEB-PIPELINE.md:80) uses `python3 -m v3_engine.terminal_pipeline.cli worker`. The module defines main but has no module entrypoint invocation. Running this command exited successfully without starting workers. The existing scripts/v3/run_temporal_pipeline.py entrypoint is separate; startup also needs F01 repaired.

The manual still describes healthy rather than ready, 1–10 rather than the planned 1–20 PDFs, and scene.js/index.html/manifest.json instead of index.html/styles.css/script.js.

tasks.md has 49 checked repair tasks and T112 open, but 28 original tasks remain reopened. The current code/test evidence does not support many new checkmarks. The implementation report still substitutes weaker descriptions for some repair completion conditions; e.g. T068 is described as fixture/archive restoration instead of a managed connected runtime harness.

**Reassess:** T067, T108, T113–T115. Correct evidence mapping/status only after actual behaviors pass; keep implemented, offline verified and live verified distinct.

## Repairs that are substantiated

- Captured manifest UUIDs are persisted and reused by normal submission dispatch instead of regenerated per delivery.
- Laravel PipelineOperation records improve persistence over cache-only claim/result storage, though validation/atomicity remains incomplete.
- Divide calls now invoke NotebookTerminalService and parse returned map nodes instead of constructing a fixed map.
- Reset/export queues now match their worker registration.
- Import workflow definition naming/registered symbols are improved, although activity semantics and executor configuration remain broken.
- Root-level Temporal fixture availability fixes the previous missing-fixture errors.
- Command size/action/unknown-field validation has improved.
- T112 remains open, and the selector was restored to Spec 020.

## Recommended order

1. Fix production worker initialization and verify the actual startup entrypoint.
2. Implement true durable Update-With-Start admission with no success on errors.
3. Resolve and prepare real source/framework/notebook context, correct the protocol, and wire signed atomic lesson persistence and real exports.
4. Restore durable mutation fences and truthful archive/reset/failure/control behavior.
5. Finish queued import, selected scene/check/review routing and private reference payloads.
6. Replace bypassed acceptance tests with one connected production chain and actual replay; correct completion evidence afterward.

No new engine or new feature specification is required. The original T066–T115 completion conditions already describe these obligations.

## Evidence and reproduction

Artifacts are in [v3-spec019-repair-review-20261012](v3-spec019-repair-review-20261012/):

- [Parsed suite totals](v3-spec019-repair-review-20261012/test-summary.json).
- [Behavior probes](v3-spec019-repair-review-20261012/behavior-probes.json), including synthetic external transport calls and explicit fault injection.
- [Real Temporal admission/production worker startup result](v3-spec019-repair-review-20261012/fresh-temporal-probe.json).
- Focused PHP/Python logs and documented-worker-command output; reproduction scripts and full JUnit are retained under `/tmp/v3-spec019-repair-review-20261012`.

Commands used:

```sh
env APP_ENV=testing DB_DATABASE=:memory: \
  APP_CONFIG_CACHE=/tmp/v3-spec019-repair-review-20261012/unused-config.php \
  php vendor/bin/pest tests/Feature/V3/WebPipeline tests/Integration/V3/WebPipeline

.venv/bin/python -m pytest --import-mode=importlib \
  v3_engine/tests/web_pipeline v3_engine/tests/scene_generation/test_service.py \
  -m 'not live and not temporal and not container and not deployment' -q -ra

.venv/bin/python -m pytest --import-mode=importlib \
  v3_engine/tests/web_pipeline/test_controls_capacity.py \
  v3_engine/tests/web_pipeline/test_replay.py --run-temporal -q -ra

.venv/bin/python -m v3_engine.terminal_pipeline.cli worker
```

The review's actual suite runs additionally forced temporary storage roots, disabled live flags, and used an unavailable Temporal address for the offline lane. The real Temporal probe used a separate managed empty namespace and temporary database, then shut it down. Production startup was tested with a temporary process lock and a pre-set stop event to prevent any work consumption if initialization succeeded.

The retained successful terminal final-report.json SHA-256 remains `35719a193a6d9d9308cefd6134bf5213030fb1296d21c8a06ad1f1307ca309a5`, matching the repair agent's baseline. This checks retained report integrity, not current production execution success. The new startup failure is reported separately.
