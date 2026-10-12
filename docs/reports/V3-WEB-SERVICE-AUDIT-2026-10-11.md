# Web services and endpoint audit

**Date:** 2026-10-11 · **Workspace:** `/home/moahmmed/college_project` · **HEAD:** `89dfa1a`, with existing uncommitted changes.

## Conclusion

The web version exposes the base services: account access, PDF intake, subject runs, progress, prompt export, scene submission, qualification, teacher review, and administrative operations. Their Laravel contracts have substantial passing coverage.

**The new web subject-run processing chain is not ready.** The new Python web bridge acknowledges submissions without starting Temporal. Its divide/lesson activities return fixed successful results without processing the supplied PDFs. Additional worker registration, failure propagation, and archive import gaps prevent a truthful end-to-end completion claim for that path. This verdict does not establish that the previously working terminal pipeline has stopped working.

### Scope clarification after reviewing the previous successful run

The retained `storage/app/private/v3/per-pdf-live-verification-20261010/test_live_terminal_pipeline_sm0/terminal/runs/be9b2b0b-d02f-4b5a-a13e-01b3e12e1ba0/final-report.json` exists and records `completed`: two PDFs, two saved lessons, two generated/built scenes, and zero failed/uncertain/queued items. [The previous implementation report](../PER-PDF-IMPLEMENTATION-REPORT.md) records its real NotebookLM/Gemini execution through `scripts/v3/run_temporal_pipeline.py`.

That successful terminal path and the new Laravel subject-run adapter use different orchestration entry points. The audit did not rerun the prior live execution or demonstrate a regression in it. Describing the entire system as "blocked" would be too broad; the confirmed gap is the new web adapter's connection to execution. The legacy offline fixture's `provider_unavailable` result is also a separate failure and is not proof that the user's signed-in provider session or previous terminal workflow is broken.

The stored NotebookLM SDK session successfully read the configured proof notebook after the user signed in. That establishes a working read operation; it does not establish the browser profile identity, PDF upload, conversation reset, lesson generation, or the complete web workflow.

## Executed checks

| Check | Passed | Failed assertions | Setup errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| Full Laravel Unit / Feature / Integration suite | 740 | 6 | 3 | 3 |
| Python offline suite, excluding separately run SDK contracts | 624 | 0 | 0 | 1 |
| Python SDK contracts | 19 | 0 | 0 | 0 |
| Python Temporal lane | 55 | 0 | 3 | 0 |
| Python browser / integration / deployment selection, excluding live / container / Temporal | 9 | 0 | 0 | 0 |
| Explicit connected legacy Laravel lifecycle recheck | 0 | 1 | 0 | 0 |

The Python selections are disjoint: **707 passes, 3 setup errors, 1 skip**, from 711 selected cases. The full collection contained 732 cases; 21 were outside these selections. The connected Laravel recheck exercises one case skipped in the full run; do not add it as a new unique test. Across the final Laravel checks, that leaves **740 passes, 10 unsuccessful cases, and 2 unexecuted cases** out of 752.

Other results:

- Frontend production build: **passed**, 56 modules transformed, output written under the audit's temporary directory.
- Read-only live NotebookLM SDK check: **passed**, adapter `notebooklm_py`, version `0.8.4`; notebook content was not printed.
- Laravel route smoke: **221 / 221 registered routes probed**, no missing controller actions, no HTTP 500 responses.
- Python bridge smoke: **22 / 22 OpenAPI operations probed** through ASGI.
- Focused Laravel V3 recheck: 147 passes, 4 failures, 1 skip. This overlaps the full suite and is not added to its total.

### Endpoint smoke results

Laravel anonymous requests returned 194 HTTP 401, 15 HTTP 404, 4 HTTP 422, 1 HTTP 403, and 7 HTTP 200. All **135 V3/internal routes** returned 401 or 404: 120 and 15 respectively. The seven public successes were `/up`, `/`, registration, login, password request/reset pages, and `/mohammed-test`.

Python anonymous requests returned 21 HTTP 401 and one HTTP 422. The 422 was the consumed-query endpoint, where required query validation occurs before the handler's signature check. No unsigned request succeeded.

These are authentication and basic dispatch checks, **not exhaustive successful-response coverage**. Dummy identifiers can produce 404 from constraints or missing records. One primary verb per Laravel route was exercised; HEAD and additional PUT/PATCH aliases were not separately tested. The probe enabled the web feature only in its isolated test process, used SQLite in memory, and did not change the saved feature configuration.

The complete methods, paths, route names, controller actions, middleware, and observed statuses are in [the endpoint inventory](v3-audit-2026-10-11/ENDPOINT-INVENTORY.md) and [CSV](v3-audit-2026-10-11/laravel-endpoints.csv).

## Service coverage

Counts below are from the full Laravel run. They measure test cases in each area, not the number of endpoints or complete live coverage.

| Service | Laravel results | Assessment |
| --- | --- | --- |
| V3 account access and ownership | 58 passed | Contract coverage passes, including access boundaries. |
| Settings and templates | 40 passed | Publication, validation, and captured settings contracts pass. |
| PDF intake | 62 passed | Validation and private storage contracts pass; unrestricted PDF tool check passes. |
| Source activation | 40 passed, 1 failure, 1 skip | Suspended-owner contract mismatch; legacy connected recheck also stops at source activation. |
| NotebookLM lesson protocol | 50 passed | Fixture/provider contract checks pass; live SDK proof read passes separately. |
| Lesson mapping and saved lessons | 53 passed | Isolated application contracts pass. New web worker does not perform real lesson processing. |
| Prompts | 44 passed | Stored prompt contracts pass; new worker's export activity does not perform a real export. |
| Authoring | 31 passed | Application contract coverage passes. New subject workflow does not dispatch scene generation. |
| Scene qualification | 21 passed, 1 failure | Eligibility HTTP contract mismatch; actual browser checks pass in Python. |
| Teacher review | 47 passed | Fixture-backed decisions, preview, and corrections pass; full connected lifecycle fails before review. |
| Evidence | 20 passed | Evidence and lineage contracts pass in isolation. |
| Admin operations | 42 passed | Administrative contracts pass. |
| New web pipeline | 134 passed, 1 skip | Intake, exports, private metadata, imports, UI responses, claims, and progress contracts pass; real orchestration remains incomplete. |
| Legacy workflow | 40 passed, 2 failures, 1 skip | Start bridge integration passes using the project environment; separate lifecycle recheck fails. |

Additional Laravel cases cover signing, secret configuration, prompt qualification, scene evidence/model identity, authentication, profile, and basic navigation. The browser tests execute real browsers on scene fixtures/builds; they are not a human-style walkthrough of every deployed web page.

### Main registered web endpoints

| Purpose | Method and path |
| --- | --- |
| Submit multiple PDFs for a subject | `POST /v3/teacher/subjects/{subject}/runs` |
| Submission form | `GET /v3/teacher/subjects/{subject}/runs/create` |
| Run detail / progress / events | `GET /v3/teacher/subject-runs/{run}` and suffixes `/progress`, `/events` |
| Pause / resume / cancel | `POST /v3/teacher/subject-runs/{run}/commands` |
| Prompt export | `GET /v3/teacher/subject-runs/{run}/prompt-export` |
| Scene list / generation selection | `GET .../{run}/scenes`, `POST .../{run}/generation-mode` |
| Request authoring / import a scene | `POST .../{run}/scenes/{scene}/authoring-request` and `/imports` |
| PDF uploads and cases | `POST /v3/teacher/pdf-uploads`, `POST /v3/teacher/cases` |
| Review and decisions | `GET /v3/teacher/cases/{case}/reviews`, `POST .../{case}/reviews/{review}/decisions` |
| Admin run summaries / controls | `GET /v3/admin/subject-runs/{run}/summary`, `POST .../{run}/commands` |
| Worker claims / results / events | `POST /api/v3/internal/web-pipeline/claims`, `/results`, `/events` |
| Worker contexts / artifacts | `GET /api/v3/internal/web-pipeline/contexts/{ref}`, `/artifacts/{ref}` |
| Python admission / commands | `POST /internal/v1/application-pipeline/submissions`, `/commands` |

See the inventory for every endpoint and complete, unabbreviated paths.

## Confirmed execution gaps

### P0 — New web admission never starts the workflow

**Observed:** a signed submission with a structurally valid but unresolvable manifest received HTTP 202 and `queued`. A pause received HTTP 202 with `applied: false`; its receipt then reported `consumed: true`, while the run remained queued.

**Cause:** [web bridge](../../v3_engine/web_pipeline/bridge.py) stores submissions, commands, and run status in module dictionaries. Its application-pipeline handlers have no Temporal admission/update dispatch. Queued records are never progressed by these handlers.

The Laravel [outbox dispatcher](../../app/Services/V3/WebPipeline/DispatchRunOutbox.php) also creates a fresh random `manifest_ref` while sending each submission instead of supplying a persisted, resolvable input manifest. This needs to be connected to the actual accepted inputs when wiring the working pipeline into web submission.

**Impact:** an accepted web submission does not demonstrate processing has started; command consumption can be reported without applying the command.

### P0 — PDF activities report synthetic success

**Observed:** running the subject workflow's local executor against its actual activities, with nonexistent PDF/framework references, returned `prompts_exported`, two completed PDFs, two saved lessons, and two saved prompts.

**Cause:** [activities.py](../../v3_engine/web_pipeline/activities.py) creates a fixed one-lesson divide map, fixed lesson/prompt counts, a reset receipt without resetting NotebookLM, and an export receipt without exporting prompts. It does not retrieve/process the source PDFs or commit actual lessons through Laravel.

**Impact:** successful counts from these activities are not evidence of generated or saved content. The local executor probe deliberately bypassed Temporal scheduling to isolate activity behavior; worker scheduling has additional failures below.

### P1 — Worker names and queues do not match

Code inspection of [worker.py](../../v3_engine/terminal_pipeline/worker.py), [subject_workflow.py](../../v3_engine/web_pipeline/subject_workflow.py), [supervisor.py](../../v3_engine/terminal_pipeline/supervisor.py), and [import_workflow.py](../../v3_engine/web_pipeline/import_workflow.py) confirmed:

1. The worker imports three archive activities that do not exist: `commit_import_item_activity`, `plan_import_items_activity`, and `read_import_manifest_activity`. Its broad `ImportError` catch silently omits the import workflow.
2. The import workflow registers as `web_import_v1`; the supervisor requests `ApplicationImportWorkflow`.
3. The subject workflow sends every activity to `v3-terminal-io`; the worker registers reset and prompt export on `v3-terminal-control`.

**Impact:** real scheduling cannot reliably complete the advertised web subject/import paths. These registration mismatches were verified by source inspection and missing-symbol probes, not by claiming a completed production web run.

### P1 — Failures can still produce successful completion

**Observed:** the local subject workflow was supplied failed lesson and export outcomes. It still returned `prompts_exported`, `safe_code: ok`, one completed PDF, zero failed PDFs, and zero lessons/prompts.

**Cause:** [subject_workflow.py](../../v3_engine/web_pipeline/subject_workflow.py) checks divide failures but does not enforce successful lesson/reset/export outcomes before advancing. Its API mode also returns completion without launching a scene workflow.

**Impact:** success and progress can misrepresent failed or omitted work. Scene generation/build implementation exists separately, but the subject path does not connect it to completion.

### P1 — Receipts and operations disappear on process recreation

**Observed:** reloading the bridge module made a previous run `unknown` and its command receipt HTTP 404. A fresh `WebOperationStore` had no previous operation records.

**Cause:** [bridge.py](../../v3_engine/web_pipeline/bridge.py) and [operation_store.py](../../v3_engine/web_pipeline/operation_store.py) use memory dictionaries.

**Impact:** restart recovery, cross-process consistency, and durable deduplication are not established for the new Python web path. The module reload is a simulation of process recreation, not a production restart/load test.

### P1 — Command validation is incomplete

**Observed:** a signed command containing invalid UUIDs, an unsupported action, invalid digest, and an unknown field with 40,000 characters received HTTP 202.

**Cause:** the command handler only extracts required keys; it does not enforce the admission handler's 32 KiB ceiling or a full command schema.

**Impact:** authenticated callers can submit malformed/oversized commands. Signature protection still rejects unsigned requests; this finding is not an authentication bypass.

### P1 — Python archive import does not verify or copy the archive

**Observed:** verification reported completed for a random manifest UUID with `/tmp` as an existing root. Copy reported completed despite a nonexistent destination and no matching archive.

**Cause:** [import_activities.py](../../v3_engine/web_pipeline/import_activities.py) checks UUID/root existence and manufactures receipt references. It does not verify the manifest/files, copy them, or materialize application records; finalization returns a fixed accepted-PDF count of two. The Temporal import workflow invokes these helpers directly with hardcoded `/tmp` paths.

**Impact:** successful Python import receipts do not establish imported content. Passing Laravel historical-import contract tests do not repair this Python implementation.

## Remaining test failures

| Area / test | Result and interpretation |
| --- | --- |
| `Auth/EmailVerificationTest`, three cases | Setup errors: `UserFactory::unverified()` is undefined. These fail before testing email verification behavior. |
| `ProfileTest`: update profile | Actual HTTP 500 when changing email: missing `users.email_verified_at` column in the migrated test schema. Controller still assigns that field. |
| `ProfileTest`: unchanged verification status | Expected non-null timestamp is null. Schema/model/test assumptions disagree. |
| `SceneQualification/EligibilityTest` S21 | Expected 404 for an unqualified candidate; received 200. Current service returns an eligibility result instead of throwing. This is a response-contract mismatch, not proof that unauthorized approval succeeds. |
| `Sources/ActivationTest` K03 | Suspended teacher after workflow start produced no expected pending source activation. Reconcile automated continuation and suspension policy. |
| `Workflow/HandoffContractTest` W17 | Hardcoded activity allowlist omits later `process_notebook_lesson.py` and `reconcile_notebook_query.py`. This assertion is outdated relative to the current tree. |
| `Workflow/WorkflowStartTest` W05 | Deferred reason expected `inactive_owner`; actual `operation_fenced`. Resolve guard precedence/contract. |
| Connected legacy lifecycle recheck | Failed after 123.34 seconds: extraction completed, sourcing blocked with `provider_unavailable`; no lesson cursors/completions, scene candidates/checks, or review items. Root cause of provider unavailability was not established by this audit. |
| Three Python web Temporal cases | Fixture `temporal_local_env` is not found. It exists under sibling `terminal_pipeline/conftest.py`, outside these tests' fixture scope. Affects capacity, queued cancellation, and web replay tests. |

The connected legacy test uses an offline MCP/provider fixture. Its failure does not prove the signed-in Google account is invalid. The successful SDK read and this failing legacy adapter/fixture path exercise different integrations.

## Readiness and test-quality limitations

### Current local readiness

The application's readiness service reported `ready: false` before the isolated endpoint probe enabled the feature:

- `V3_WEB_PIPELINE_ENABLED` is effectively disabled.
- Default mode is `export_manual`; legacy protocol remains `legacy_staged_v1`.
- PHP **CLI** upload limit is 2 MiB against the check's required 100 MiB.
- PHP **CLI** POST limit is 8 MiB against the check's required 1100 MiB.
- Maximum file uploads is 20 and passes.
- Private storage is writable; bridge configuration and provider credential presence checks pass.

These are local CLI measurements. PHP-FPM/deployed web settings were not inspected and may differ. Credential presence is not an API execution test.

Initial sandboxed runs produced additional PDF-inspection and loopback binding failures. These were rechecked with unrestricted execution. PDF-tool/intake checks then passed. Launching the Python bridge with the system interpreter failed to import `notebooklm`; using the project's virtual environment in PATH allowed the live start-bridge integration test to pass. The final suite counts above reflect that corrected environment.

### Passing gates do not establish the full chain

- [Python live web gate](../../v3_engine/tests/web_pipeline/test_live_web_pipeline.py) checks environment flags/credential presence only.
- [Laravel live web gate](../../tests/Integration/V3/WebPipeline/LiveApplicationGateTest.php), when enabled, submits two fixture PDFs and asserts staging state/count/mode. It does not wait for Temporal, NotebookLM, generation, qualification, or approval.
- [Web replay test](../../v3_engine/tests/web_pipeline/test_replay.py) constructs a `Replayer` and checks it is non-null; it does not replay workflow history. It currently also has the missing-fixture error.
- [Connected Laravel web test](../../tests/Integration/V3/WebPipeline/ConnectedWebPipelineTest.php) exercises Laravel lifecycle contracts with fake storage/HTTP and supplied worker/check results. It is useful contract coverage, not a real provider chain.

### Operator documentation disagrees with registered services

[V3-WEB-PIPELINE.md](../V3-WEB-PIPELINE.md) references an absent `v3_engine.web_pipeline.worker` module, incorrect create/control/export URLs, and a scene package containing `scene.js` / `manifest.json`. The actual manual package requires `index.html`, `styles.css`, and `script.js`. Its readiness instructions refer to `healthy`, while the service reports `ready`.

Correct startup and endpoint documentation before relying on it for deployment or manual acceptance testing.

## Recommended completion order

1. Wire web admission, commands, receipts, and queries to the real Temporal supervisor/workflows and durable application operation records.
2. Replace fixed-result PDF activities with source resolution, real NotebookLM turns/reset, and authoritative Laravel lesson/prompt commits.
3. Align registered workflow names and activity queues; stop silently swallowing missing registrations.
4. Enforce truthful failed/partial outcomes, then connect selected scene generation, build, qualification, and review stages.
5. Implement verified archive import and restart-safe operation receipts; enforce full command validation.
6. Resolve profile/schema, suspension, eligibility, and guard-reason regressions; update outdated tests and operator docs.
7. Repair web Temporal fixture scope and implement actual history replay and a real two-PDF acceptance test through saved prompts and teacher approval.
8. Recheck readiness using the serving PHP runtime, then run the full web acceptance chain with approved fixture sources and configured providers.

## Reproduction and evidence

Primary commands (executed with timeouts and JUnit output under the audit directory):

```sh
env PATH="$PWD/.venv/bin:$PATH" APP_ENV=testing DB_DATABASE=:memory: \
  APP_CONFIG_CACHE=/tmp/v3-web-services-audit-20261011-01/unused-config.php \
  php vendor/bin/pest tests/Unit tests/Feature tests/Integration

.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests \
  --ignore=v3_engine/tests/notebooklm/test_sdk_contract.py \
  -m 'not live and not temporal and not container and not browser_ui and not integration and not deployment' -q -ra

.venv/bin/python -m pytest --import-mode=importlib \
  v3_engine/tests/notebooklm/test_sdk_contract.py -q -ra

.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests \
  -m 'temporal and not live and not container and not deployment' \
  --run-temporal --run-integration --run-browser-ui -q -ra

.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests \
  -m '(integration or browser_ui or deployment) and not temporal and not live and not container' \
  --run-integration --run-browser-ui --run-deployment -q -ra

env PATH="$PWD/.venv/bin:$PATH" APP_ENV=testing DB_DATABASE=:memory: \
  APP_CONFIG_CACHE=/tmp/v3-web-services-audit-20261011-01/unused-config.php \
  RUN_V3_CONNECTED_PIPELINE=1 php vendor/bin/pest \
  tests/Integration/V3/Workflow/LiveBridgeTest.php \
  --filter=test_connected_offline_pdf_to_teacher_approval_and_postapproval_correction_lifecycle

npm run build -- --outDir /tmp/v3-web-services-audit-20261011-01/frontend-build
```

Durable audit artifacts:

- [Test totals](v3-audit-2026-10-11/test-summary.json) and [individual test outcomes](v3-audit-2026-10-11/test-cases.csv).
- [Endpoint inventory](v3-audit-2026-10-11/ENDPOINT-INVENTORY.md), [Laravel request results/readiness](v3-audit-2026-10-11/endpoint-smoke-all.json), and [Python request results](v3-audit-2026-10-11/fastapi-smoke.json).
- [Direct behavioral probe results](v3-audit-2026-10-11/behavior-probes.json), [NotebookLM read result](v3-audit-2026-10-11/notebooklm-readonly.log), and [frontend build log](v3-audit-2026-10-11/frontend-build.log).

Full transient logs, JUnit XML, and standalone probe scripts are under `/tmp/v3-web-services-audit-20261011-01`. Interrupted initial runs are excluded from the final totals.

**Scope limits:** no real paid scene generation, new notebook/source creation, full live multi-PDF web acceptance run, deployed-server audit, load test, or exhaustive browser navigation was completed. The report identifies the blockers instead of treating fixture passes as live proof. No application implementation fixes or commits were made; report/evidence files were added to the existing workspace.
