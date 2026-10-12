# Spec 019 repair acceptance v2

Date: 2026-10-12. This is a required verification plan, not a record of passing execution. Governs T116–T155 in tasks.md. Preserve spec.md FR/SC/A meanings and existing contracts. The user requested detailed implementation tasks, tests, website coverage and explicit completion criteria.

## 1. Findings and evidence boundaries

Confirmed by the preceding source/archive review:

- TerminalArchiveReader reads top-level `prompts`, but the reference export stores 63 entries under `lessons`, containing 89 nested scenes. Handoffs reside under each PDF directory. Archive metadata uses `work_count`/`scene_count`; substituting default zeros or only reading root handoffs loses content.
- MaterializeTerminalImport creates run/item/lecture records and legacy sections without the complete canonical lesson/section/prompt graph. Run counts or a successful CLI dry-run cannot establish owner-visible content.
- Reference root: `storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root`; run `09a59afe-ed26-4567-906b-e42d87d53362`. Prior inspection found 8 PDF archives, 63 handoffs with matching raw-answer hashes, 63 exported lessons and 89 nested prompts. This is not full archive verification: every listed artifact and association still needs verification.
- Existing PHP connected tests fake internal bridge HTTP; Python tests cover different pieces separately. Those remain useful component tests but cannot close the single joined website acceptance.
- Queued import dispatch/materialization and selected-provider propagation have recent repairs with component coverage. Inspect and retain working code; establish connected behavior before declaring them complete. Do not blindly revert to the earlier implementation report's assumptions.
- Previous test totals (180 PHP passed/1 skipped; 110 Python passed/2 skipped) are historical isolated-suite evidence. This documentation turn has not rerun them or validated all runtime behavior.

Check rather than assume additional drift: canonical First fields, provider availability including contracted Qwen, Gemini settings constraints, mode aliases, upload limits, package format and route names. Repairs must honor the approved contract; if it cannot be met, record an unresolved requirement instead of silently reducing scope.

## 2. What each kind of evidence proves

| Lane | Required execution | What it can establish |
|---|---|---|
| Component | Focused PHP/Python behavior; doubles identified | Local validation, algorithms, persistence and contract cases |
| Joined offline | Actual browser, Laravel server/session/CSRF, outbox, signed HTTP bridge, persistent Temporal service/queues, production workers/services/callbacks, shared disposable DB/private disk, actual browser checks/preview | Connected behavior with deterministic external provider responses |
| Retained archive | Exact reference bytes verified and materialized to disposable application DB/storage, then browsed as owner | Historical import fidelity and zero reprocessing |
| Recovery/replay | Real processes stopped/restarted with retained test DB/namespace, histories fetched and actually replayed | Durable recovery, scheduling and replay compatibility |
| Live | Joined journey with actual authorized NotebookLM and selected generation provider, no provider doubles | That dedicated account/provider/model/input run worked |

The joined lane may double only external NotebookLM/generation/JEV transports. Transport fixtures may return a valid static scene package; its real materialization, checks and preview MUST still run. Test fixture seeding is limited to preconditions (accounts, permissions, settings, approved framework/template, approved test credentials). Never seed expected maps, lessons, prompts, candidates, check passes, preview timestamps, decisions or consumed receipts for joined acceptance. Historical archive files are inputs, not seeded database results.

No internal HTTP fakes, direct workflow.run invocation, replaced activity success functions, fabricated frame/check records, fixed completed counts or mocked consumption query can close the joined gate. Do not inject provider fixtures into normal production startup. Use explicit test-only configuration with production guards.

## 3. Website and endpoint acceptance matrix

T118 must enumerate actual registered routes/controllers and attach their exact method/path to each row. The paths below identify current route families; use route names where available. New tests must catch route drift. Include every route serving these services, including internal callbacks and alternate legacy access, even if not enumerated below.

For each row retain: actor, input identity, browser action, sanitized request/response status, trace/run/operation IDs, durable before/after assertions, an error/denial case and test artifact references. Use real rendered UI interactions for website actions; use HTTP/CLI for internal and CLI-only operations. Screenshots illustrate behavior but do not prove persistence.

| ID | View/service and route family | Mandatory positive assertions | Mandatory failure/denial assertions | Spec cases |
|---|---|---|---|---|
| V01 | Existing subject page; `/v3/teacher/subjects/{subject}/runs/create` and runs POST | Owning teacher can submit ordered PDFs with captured mode/waiver; preserved accepted bytes; readiness agrees with serving PHP/proxy configuration | Invalid/oversize/incomplete PDF; 21 inputs; missing CSRF; permission denial; unavailable prerequisite visibly blocks | A05,A26 |
| V02 | Run detail/progress/events | Queued→mapping→lessons→archive/reset follows actual committed effects; page survives reload; unequal PDF-local lesson counts remain correct | Unknown totals stay unknown; local failure shows partial; offline shows source/age; unsafe shared failure holds | A06–A09,A25 |
| V03 | Imported and freshly created lecture/lesson/section views | Canonical eleven fields and raw-backed prompts accessible through navigation; imported labels and missing fields shown; separate PDFs keep local identities | Foreign teacher/admin cannot read; no fake fields; linked legacy writes forbidden | A01,A04,A08,A26 |
| V04 | Prompt export (`prompt-export`, run/item/scene scopes) | All and scoped JSON/text downloads match exact prompt bytes and approved template; every intended identity included | Foreign/stale selector rejected; zero generation requests | A10,A26 |
| V05 | Scene list and manual import (`scenes`, `scenes/{scene}/imports`) | Exact current prompt, three-file bytes, manual origin, real checks, duplicate reuse | Malformed/traversal/oversize/stale prompt/changed duplicate rejected; failed check blocks | A11,A12,A16 |
| V06 | Provider selection and scene authoring (`generation-mode`, `authoring-request`, command equivalent if used) | Exactly selected scenes use immutable chosen provider/model/template/settings; actual external request capture matches UI | Missing config/consent fails before send; no fallback/unselected sends; unsupported options explicitly accounted for | A13–A15 |
| V07 | Scene qualification and check status | Seven checks actually execute; report/capture/digests bound to exact candidate; demo basis distinct from actual JEV | Broken entrypoint/assets/runtime/network/interaction/accessibility cannot qualify; stale manifest/frame evidence fails | A16 |
| V08 | Case reviews and isolated viewer session/frame/action routes | Owner receives first real frame and interacts; exact current approval/request_changes persists and is consumed | No frame, foreign/inactive/admin actor, stale/superseded candidate or concurrent loser cannot approve | A17,A18 |
| V09 | Postapproval correction and replacement views | Visual/factual manual and explicit API corrections preserve ancestry, create appropriate successor and repeat review gates | No implicit paid generation in manual mode; closed workflow/ack loss does not falsely mark consumed | A19,A20,A24 |
| V10 | Teacher/admin run commands; progress on outage | Pause/resume/cancel/retry execute at settled boundaries; requested versus applied displayed; retry restores retained work | Stale/changed command conflicts; cancelled/paused work does not start next mutation; admin cannot generate/review | A21–A25 |
| V11 | Admin metadata dashboard; teacher evidence/download/approved scenes; alternate legacy listings/CRUD | Safe admin telemetry; owner evidence traces actual source through decision; intended demo/manual absences visible | Content/secret/path/remote-ID sentinels absent from admin/history/logs; alternate APIs cannot leak linked content | A20,A26,A27 |
| V12 | CLI import sync/queue and resulting website; internal signed claims/context/results/events/receipt routes | Exact archive import and owner navigation; real registered import workflow for queue; stable duplicate identities; valid signed scope | Bad signature/replay/stale lease/oversize metadata/corrupt artifact/path escape/dry-run mutations rejected; CLI never reports queued as completed | A01–A04,A21,A24,A27 |

UI success MUST be followed by durable state assertions and reload/reopen checks. Browser console/page errors affecting a required journey are failures. A fixture browser using a test teacher proves the UI flow; it does not prove the user's Google profile or live provider session.

## 4. Mandatory scenario assertions

### G01 — Archive fidelity (T122–T129)

Use a small faithful fixture first, then the exact reference archive. Verify complete file graph, schemas, safe roots, raw answer hashes and prompt associations before materialization. Rehash staged copies before publishing; no session/cookie copies. Test source changes between verification and commit, invalid typed/duplicate ordinals, missing artifacts, symlink ancestors/descendants and unknown schema.

Reference counts: 8 lectures/items/cases, 63 lesson nodes, per-PDF lessons `[9,3,9,7,5,8,8,14]`, 89 current original scene prompts, 0 generated/built/approved scenes and 0 external requests. Derive section cardinality from actual First/scene relationships; do not conflate lessons, sections and scenes. Compare every prompt's exact body/digest and PDF/lesson/section association. Preserve original raw responses and historical evidence. Reimport/restart must preserve identities/counts. Dry-run creates no DB writes, copies, workflow or provider calls. Source tree content hashes remain unchanged.

Use deterministic import IDs and per-item receipts. A partial import contains only individually verified completed PDFs and remains explicitly partial in command/workflow/page status. Genuine historical evidence may populate historical records; absent live request/qualification data must stay absent through an appropriate schema, never invented solely for foreign keys.

### G02 — Two-PDF website processing (T130–T134)

Fixture maps: two lessons for PDF 1, three for PDF 2, unequal sections/prompts. Assert outputs from returned raw fixture bytes, not hardcoded generic success. External transport journal proves one framework upload, correct selected source IDs for each turn, separate Divide and lesson sequences and archive/reset before the next PDF. Request types/ordering follow actual service semantics; do not require redundant Start requests where canonical service combines them.

Assert one accepted case/lecture per PDF, canonical fields, exact saved prompts, no duplicates after callback retry, correct committed counts, full/scoped export and zero scene-generation requests in default export_manual. Settled local failure, shared auth/quota failure, unknown map, unusable prompts, uncertain send and failed persistence/archive/reset each get a dedicated behavioral assertion.

### G03 — Manual and selected API scenes (T135–T139)

Manual source format: schema_version 1 with exactly `index.html`, `styles.css`, `script.js` file contents under the contracted files array. The internal ScenePackageV2 manifest is produced by the application; it is not a replacement upload contract. Reject malformed packages before publishing.

Exercise manual UI import and one selected API generation using real internal services. Default/manual generation sends = 0; explicit one-scene offline API generation sends = 1. Compare UI settings against actual provider request payload. No fallback. Per-adapter wire tests cover every supported contracted provider; a live pass for one provider must not be generalized to others. Gemini default and explicitly selected thinking/output values must follow captured approved policy; no forced zero or blanket unsupported-model restrictions to make a test pass.

Retain response metadata as returned, source/build identity, real reports and frame evidence. Seven checks: integrity, entrypoint, assets, runtime, network, interaction, accessibility. Include failures, not only a valid fixture. Build-only/response recovery sends = 0 additional requests. Uncertain sends require a distinct consented retry; do not resend on a local transaction retry.

### G04 — Owner review and corrections (T140–T143)

The actual isolated viewer generates first-frame evidence for the current candidate. Interact with the scene through the viewer; approval before the first frame fails. Assert decision identity/digest/version is actually handled by registered workflow code before outbox delivery closes. Concurrent decisions produce one winner, and transport acknowledgment loss does not duplicate effects.

Cover manual visual replacement, manual factual correction instructions/source operation, selected API successor, and correction after original processing closure. Changed prompt/candidate requires fresh checks/preview. Preserve historical approval chain and display demo basis. Unauthorized users cannot read content even through old routes, exports or pagination counts.

### G05 — Durability, runtime controls and privacy (T144–T147)

Stop/restart actual processes after durable admission, after remote response retention before callback, after a committed lesson before reset, and before finalization. Retain the same disposable roots/DB/Temporal namespace. Verify stable operation/candidate/decision identities, no duplicate remote request or database projection, bounded exact receipts, queue slot recovery and actual history replay.

Exercise real shared limits (4 subjects, 4 IO, 2 build, 64 queued), queued cancellation, paused cancellation, lost acceptance acknowledgment and Continue-As-New compatibility. Waiting for manual/review must not consume generation slots. Capture counters at operation boundaries rather than timing-based guesses.

Check actual signed byte payloads, timestamps/replay protection, stale leases and <=32 KiB metadata. Inspect serialized history/log/error/admin outputs using fixture sentinels. Educational data and credentials stay private; unknowns are NULL/unknown rather than fabricated zeros.

### G06 — Live website acceptance (T149,T152)

Implement an opt-in gate with the SAME internal boundaries as offline. Validate effective session/proof/account, approved disposable PDFs, actual server origins, provider/model, explicit generation opt-in/spending scope and browser runner. Google browser sign-in, NotebookLM session availability and generation API credentials are different prerequisites. Default manual export does not require paid generation credentials.

With prerequisites missing before execution: report exact missing names and keep T112/T152 OPEN. In an ordinary suite a safe skip is acceptable, but it never closes this gate. With the gate explicitly enabled and prerequisites satisfied: any processing, provider, check or review failure is a failed test, never converted to skip. Run upload→actual NotebookLM processing/reset→saved prompts→one selected generation→real checks→owner preview/approval. New live lesson/prompt counts are derived from actual outputs, not forced to equal the historical 63/89.

Use existing approved profile/session; do not launch runtime login, switch accounts, create/rotate keys or widen spending. If user authentication or explicit spending information is missing, request only that missing input while completing independent offline work. Record remote resource retention/cleanup before creating disposable live resources.

## 5. Commands and reproducible environments

Implementer must record the actual commands used and all required environment *names*, never secrets. Resolve current modules/flags from source/help. Do not copy a stale startup command from a report. Use an unused port and validate returned application identity; do not kill another service occupying the expected port.

Focused PHP command family for single-process tests:

```sh
env APP_ENV=testing DB_DATABASE=:memory: APP_CONFIG_CACHE=/tmp/spec019-repair-no-config-cache.php php artisan test tests/Feature/V3/WebPipeline/ tests/Integration/V3/WebPipeline/ tests/Feature/V3/Authoring/
```

The config-cache path must not contain an old cached config. The joined harness MUST override DB_DATABASE with an absolute disposable on-disk SQLite file and use that same file in every PHP subprocess. Create/migrate only that test DB. Test servers use separate private storage/config roots and random test signing keys shared across the real boundaries.

Python regression command families (honor repository live opt-in safeguards):

```sh
.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests/web_pipeline/ v3_engine/tests/authoring/ --run-temporal -q -ra
.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests/terminal_pipeline/ v3_engine/tests/notebooklm/ v3_engine/tests/scene_generation/ -m 'not live' -q -ra
npm run build
```

Also run affected PHP NotebookLM, access/intake, qualification, review/evidence, populated migration and actual race groups identified in the diff. The implementer must add reproducible joined/reference tests at proposed paths and provide their runnable command lines; merely naming these future test files is not evidence. The joined test run must execute real browser and Temporal work with no mandatory offline scenario skipped. Record selected/deselected/skipped separately and avoid counting overlapping invocations as unique test passes.

Tests are required here because the user explicitly requested them. Prefer observable behavior and regressions that would catch the discovered defects. Do not create trivial tests that reproduce implementation choices or assert only flags/counts set by the test itself.

## 6. Evidence manifest and validator contract

T120 creates `repair-evidence/manifest.json` and `scripts/v3/verify_web_repair_evidence.py` (new files to implement, not existing tools to assume). Required fields:

- schema_version, capture timestamp, git revision and hash of relevant dirty/untracked source, harness version.
- environment descriptors: isolated DB/root identifiers, service/namespace identities, runtime versions; no credentials/content.
- one entry per G01–G06, V01–V12 and A01–A28, with covering task/test IDs, status `passed|failed|blocked|not_run`, evidence lane and exact reason for anything non-passing.
- each execution: command, start/end, exit code, passed/failed/skipped/deselected counts, explicit list of doubled boundaries, run/workflow/operation/candidate/decision correlation IDs as applicable, artifact paths and SHA-256 digests, and named behavioral assertions with observed values.
- test runner skips map to blocked/not_run gate status, never passed. Shared evidence can cover multiple rows through references, without duplicating execution totals.
- integrity/protection results, current limitations and unresolved defects. Keep detailed private educational artifacts in the test private root; the report contains sanitized references only.

The validator MUST check required fields/references, present artifact hashes, status consistency, source-snapshot freshness and completeness of required rows. It cannot prove test honesty by itself; final review inspects governing tests and production dispatch. Add meaningful validator cases for required skipped row, missing artifact, changed source digest, falsely complete live state and a valid explicitly offline bundle.

`--require offline` succeeds only if G01–G05, V01–V12 offline coverage, A01–A28 applicable offline assertions, and affected regression gates pass; G06 may be explicitly blocked. `--require complete` additionally requires G06 live evidence and no unresolved mandatory defects. Do not edit the validator's required IDs to hide incomplete work. Final documentation changes may be outside the relevant runtime-source digest; any runtime fix invalidates its affected earlier evidence until rerun.

## 7. Completion vocabulary

- **Implemented:** code exists; runtime behavior may still be unverified.
- **Component verified:** listed isolated tests passed.
- **Offline integration verified:** T151 passes the joined/reference/browser/recovery evidence validator.
- **Live verified:** T152 executed with real approved providers and full owner review evidence.
- **Spec 019 complete:** all original required behaviors have current evidence, all website services are covered, mandatory regression defects are closed and both offline/live acceptance pass.

A skipped live gate permits delivery of an offline-verified repair with an explicit open gate. It does not permit “100% complete,” “all services tested live,” or closing T112/T152. Do not call every remaining issue credentials-related: archive fidelity and internal joined tests are independently repairable without paid providers. Do not infer deception or intent from an incorrect prior report; identify unsupported claims and exact evidence gaps.
