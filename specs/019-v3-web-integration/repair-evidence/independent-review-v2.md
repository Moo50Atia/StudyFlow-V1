# Independent review of T116–T155 delivery

Verdict: approval withheld, including the claimed 100% offline acceptance. Review performed against current source and retained documents. This review does not claim that all implementation work is defective or infer intent from unsupported completion statements.

## Review execution

1. Read final-review.md, regressions.md, website-matrix.md, manifest.json, live.json, task checkboxes, validator, joined runtime tests/harness, import implementation/reference test and both live gates.
2. Invoked the existing validator against the supplied manifest: offline result `(True, [])`.
3. In a temporary file only, replaced execution records with one unexecuted command, empty artifact list, deliberately invalid dirty source digest, and changed G06 to passed. Existing validator in complete mode returned `(True, [])`. Original manifest unchanged.
4. Ran only the Python live-gate test with process-local placeholder values: RUN_V3_LIVE_APPLICATION_GATE=1, V3_NOTEBOOKLM_STORAGE_STATE=/does-not-exist/spec019-review-session, GEMINI_API_KEY=not-a-real-key. Result: **1 passed in 0.01s**. Source inspection confirmed this test contains only environment boolean assertions, so no external provider calls were made.
5. Read reference export keys/counts without changing source artifacts: 89 nonempty prompt bodies across 63 lessons, including 22 lessons with multiple scenes.

Broad suites and import materialization were not rerun in this review. The joined harness currently overwrites/deletes repository .env.testing, and its storage isolation needs correction before reuse. The prior reported 218/181 totals have not been independently reproduced here.

## Blocking findings

### P1 — Evidence validator accepts unsupported completion

scripts/v3/verify_web_repair_evidence.py: validate_manifest accepts check_source_digest but never uses it. It does not enforce evidence links per gate/view/acceptance, execution timestamps, measured assertions, skips, trace IDs or nonempty artifact sets. Current artifacts mostly hash narrative Markdown rather than runtime outputs. Reproduction above proves complete mode can accept no runtime artifacts and an invalid source digest. T120/T151 cannot be considered complete.

Required closure: implement the acceptance schema checks, source freshness, required trace/artifact/assertion coverage and explicit skip accounting; add rejection cases for this reproduction. Regenerate evidence from actual executions, not status declarations.

### P1 — Joined acceptance bypasses the required runtime

v3_engine/tests/web_pipeline/test_joined_runtime.py:539–542 instantiates ApplicationSubjectWorkflow, injects _activity_executor and calls workflow.run directly. Queue names recorded by a local function do not prove production Temporal queues dispatched the work. The harness starts Laravel and FastAPI, without a Temporal service/worker/outbox lifecycle. create_browser_client at runtime_harness.py:297 returns httpx.AsyncClient; it does not render or interact with the website.

Manual/API tests insert lesson/map/section/prompt rows directly (for example test_joined_runtime.py:805–854 and 1161–1210). Selected authoring/check activities are then called directly. These tests may prove components but cannot close T119/T121/T131/T137/T138/T148 or full offline acceptance.

Required closure: browser-driven upload through actual outbox/bridge/Temporal workers and callbacks into canonical outputs, then manual/API scene, real preview and decision consumption on the same linked run. Preserve existing useful tests under accurate evidence labels. Fault injection must prevent actual pipeline milestones.

### P1 — Historical prompt associations and stored artifacts remain incorrect

app/Services/V3/WebPipeline/MaterializeTerminalImport.php:373 retains the first TeacherSection ID. At 391–417 every scene in that lesson is attached to that first section; scene ordinal is stored as version_no. Separate scenes therefore become successive versions of one section instead of their proper source associations.

It records body_path="prompts/{UUID}.txt" without writing that file; the materializer does not copy filesToCopy or persist the raw archive evidence graph. ExportScenePrompts.php:47–55 selects the current/latest prompt per section and reads body_path from private disk, so imported database counts do not establish complete usable exports. The source has 22 multi-scene lessons affected by the association logic.

Additional placeholder framework fallback remains at MaterializeTerminalImport.php:80–96, using invented framework paths/hashes when no existing framework is found. This violates truthful historical provenance.

Required closure: source-correct section/prompt mapping, initial version per real section, current prompt state, written/verified body artifacts and retained raw evidence, truthful framework provenance. Assert all 89 exported bodies and associations after import/reload, not merely 89 database rows. T126/T128/T129 need renewed evidence.

### P1 — Live gate implementation is still a placeholder

v3_engine/tests/web_pipeline/test_live_web_pipeline.py:16–39 only checks environment strings. It passed with nonexistent session and dummy credential. PHP LiveApplicationGateTest.php likewise ends after direct submission/staging assertions with fake storage. T149 being checked is unsupported independently of whether T152 is correctly open.

live.json names NOTEBOOKLM_SESSION_PROOF_V3, QWEN_LIVE_API_CREDENTIAL and LIVE_PLAYWRIGHT_CHROMIUM_SANDBOX_SYSADMIN, while the actual Python gate checks different flags/session variables and Gemini/GLM keys. The documented blocked reasons are not a demonstrated prerequisite assessment by that gate.

Required closure: implement the actual full opt-in chain and effective prerequisite checks; keep T112/T152 open until an authorized real execution passes. Runtime failure must fail rather than skip.

### P2 — Website coverage and regression evidence are overstated

website-matrix.md lists routes/commands not present in the current route/command definitions, including runs/{run}/lessons, candidate-checks, /control and v3:import-archive. Actual correction route uses approved-scenes/{approval}/corrections; actual import command is v3:import-terminal-run. It records a 32 MB upload limit inconsistent with the approved 100 MiB per-file contract.

regressions.md reports Temporal opt-in skips while final-review.md calls all offline gates passed. manifest.json records broad command lines but references narrative summaries without the required execution logs/correlations. It also remaps several acceptance meanings: for example A01 is exact reference content/count fidelity, A03 is invalid-artifact rejection, and A04 is historical/missing-aware projection, not the replacement assertions listed there.

Required closure: derive actual routes, execute browser actions/denials, retain test IDs/results and durable effects, restore original A01–A28 meanings and account for required skipped cases. Reassess T145/T148/T150/T151/T154/T155.

### P2 — Test harness can alter shared workspace state

runtime_harness.py stores env_testing_path as repository .env.testing, overwrites it in setup and unlinks it during cleanup without preserving any preexisting bytes. Both migration/seeding commands in setup use the repository storage configuration; a separate self.storage_path alone does not isolate Laravel's configured v3_private disk. The reference fixture likewise sets the DB but not private storage isolation.

SeedWebPipelineHarness.php is a production-discovered Artisan command without a testing/disposable-database guard and creates/updates active users, including an admin, with a fixed password. Do not invoke it outside an explicitly isolated fixture environment.

Required closure: preserve workspace files, configure isolated storage for every subprocess, and enforce test-only fixture execution against a verified disposable DB/root. Restore rather than delete preexisting configuration.

## What improved

The reader now recognizes nested lessons/scenes and per-PDF handoffs, and the materializer creates canonical lesson/TeacherSection/prompt rows. A reference count test exists. Qwen authoring and actual local browser-check coverage have been added. These are meaningful implementation improvements, but their existence does not resolve the blocking runtime, association and evidence findings above.

## Approval condition

Reopen affected tasks and repair the existing acceptance gaps without creating another blanket completed checklist. First fix the import/export association defect and validator rejection tests; then supply one genuine joined browser→Temporal→service→callback→scene→preview→decision execution with retained evidence. Complete mandatory offline recovery/coverage checks before claiming offline approval. Full completion additionally requires the real live gate.
