# Feature 021 — Manager/CLI implementation and verification

Update 2026-10-22: the four-stage workflow passed twice with the production outbound daemon, real CLI/Temporal/Laravel HTTP, retained 114-page OOP provider responses, and 96 named placeholder HTML files. A populated-provider-settings wire mismatch was fixed. See [the watcher test report](FEATURE-021-WATCHER-TEST.md) for exact evidence, observer boundaries and regressions. This verifies orchestration and transfer; it does not satisfy live-provider or genuinely generated-scene acceptance gates.

Date: 2026-10-19. Checkout: `/home/moahmmed/college_project` (existing dirty checkout; unrelated changes retained).

## Implemented scope

Hosted upload/preparation and the durable task queue hand work to an outbound laptop manager. The manager invokes the existing Temporal terminal CLI in export-prompts mode, writes a local `run.json`, waits for operator-created scene files, then uploads the complete scene inventory after an authenticated Dashboard Pull. Laravel is not a laptop dependency. Review/publication and R&D are separate from transfer completion.

The active production entrypoint selects `TerminalPipelineAdapter`. The older direct NotebookLM runner remains for compatibility/test callers; it is not the normal daemon backend. No paid scene-generation API is called by the manager.

## Completed behavior

| Area | Implementation / observed verification |
| --- | --- |
| PHP/Python wire contract | Empty settings maps serialize as objects. Actual PHP-produced bytes reach Python unchanged in the separate-process HTTP test. Real lists remain lists. |
| Hosted-prepared PDFs | Optional `submit --prepared-input-manifest` snapshots the sealed ordered parts without splitting again. Tests include two PDFs, a historical 70-page intact input, unchanged hashes/page lineage, and changed-manifest replay rejection. Existing CLI submissions keep their preparation behavior. |
| Queue ownership | Eligible tasks sort by creation time, then ID. Device capacity is serialized under its row lock. Terminal recovery and scene return remain on the original device. |
| Durable execution | Hosted/terminal IDs and submission identities survive retries. Retained results are replayed before processing. An uncommitted result may be fenced to a newer active assignment without altering its educational output. |
| Recovery | Owner-issued reconciliation assignment → actual local terminal evidence → owner `resolve-terminal` → fresh fenced attachment. Completed exports are reused; paused runs use the existing CLI resume; no fresh submit is sent on that path. Missing evidence remains held. Dead and expired reconciliation assignments release their pointers without removing holds. |
| Handoff | `run.json` contains actual inputs/output paths, hosted and terminal IDs, subject/PDF/lesson labels, ordered exact prompt text/digests, scene/prompt-version IDs and concrete destinations. No provider cookies/device tokens are exported. |
| Scene Pull | Requires all required scenes. A premature Pull uploads nothing and returns to awaiting Pull. The UI uses the actual assignment epoch to re-enable after missing files, including when polling misses intermediate states. |
| Transfer retry | The whole scene batch is frozen before first upload. Retried uploads keep the same bytes and stable artifact/submission identities even if the operator edits source HTML. Server acceptance requires the full required ID set. |
| Content handling | Accepts a self-contained index.html and optional actual CSS/JS. Transfer validation covers paths, identity, UTF-8, hashes and existing size bounds; no pedagogical/design score is a transfer gate. Qualification stays asynchronous. |
| Provenance | Prompt import checks the retained terminal response using the terminal's existing raw-capture extraction. Section/context hashes and framework references derive from real captured data. Synthetic return-manifest fallbacks were removed. Unknown manual model/provider/usage stays null/not_recorded. |
| Native provider holds | A live NotebookLM uncertainty exposed a pre-existing RuntimeError workflow-task replay loop. Terminal activities now inspect the same retained chat operation, then enter a queryable versioned pause if unresolved. Explicit resume retries the same durable operation; it does not blindly resend the ambiguous request. |

## Verification commands

```bash
.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests/local_worker v3_engine/tests/terminal_pipeline v3_engine/tests/web_pipeline/test_pdf_preparation.py -q --tb=short
php artisan test tests/Feature/V3/LocalWorker tests/Unit/V3/LocalWorker tests/Feature/V3/WebPipeline/SubjectRunIntakeTest.php tests/Feature/V3/WebPipeline/ManualSceneImportTest.php tests/Feature/V3/WebPipeline/ProgressProjectionTest.php tests/Feature/V3/WebPipeline/PromptExportTest.php tests/Feature/V3/Authoring/CandidateStoreTest.php --compact --no-ansi
npm run build
```

The explicit pytest import mode avoids the repository's pre-existing duplicate test-module basename collection collision. Resource/live tests remain explicitly gated; skipped tests are not counted as passes. The automatic HTTP lane uses a real isolated PHP process/database/storage, real device HTTP client and real production TaskRunner/terminal adapter, with provider transport and Temporal admission doubles clearly confined to the test boundary. It checks forged-prompt rejection, exact result replay, early Pull/re-Pull, and actual hosted HTML bytes independently of educational quality.

The manager/terminal regression run passed **181 Python tests, with 23 skipped**; skipped gated/resource tests are not counted as successes. The earlier PHP regression passed **108 tests (754 assertions)** and the frontend build passed. A subsequent retained-nine-lesson probe exposed and fixed two ordering defects and a multi-section acceptance defect; its final targeted regression evidence is recorded in the retained-prompt section below.

## Live evidence boundaries

The saved NotebookLM session passed a genuine accessible-notebooks request. The complete 37-page Medical Informatics source then ran through the real CLI and real isolated Temporal service. Google returned a delivery-uncertain outcome after nine of nineteen mapped lessons. The existing run and its real receipts were preserved; the host correctly records `reconciliation_required` / `held_reconciliation`, not completion. Runtime: `storage/app/private/v3/local-worker-verification/live-efcea2c5-98a5-4ac1-a23c-41a0d57304de`. This is live failure/hold evidence, not a successful full-course run.

A separate live verification **passed** using actual pages 3–4 from the same source, real NotebookLM/Temporal/CLI and the authenticated HTTP Pull path. Owner-authorized recovery attached to the same terminal run, resumed its saved checkpoints and exported eight prompts. The HTTP Pull returned eight HTML files, independently verified byte-for-byte in hosted storage. It replayed an existing Chronic Coronary Syndrome HTML file into the required destinations. This is an existing-scene transfer test, not evidence of newly generated medical scenes. Evidence: `storage/app/private/v3/local-worker-verification/live-228e392c-1517-4012-82f1-e3a62d29f670/verification-recovery.json`.

## Retained nine-lesson text Pull (user-requested test scope)

The user subsequently requested **no further authoring of the full source**: verify the last transfer/order step with the nine retained lessons only, substituting exact text prompt files for ungenerated HTML. No NotebookLM request, cookie refresh or Temporal resume was performed for this test.

`scripts/v3/verify_retained_prompt_pull.py` clones the original isolated HTTP fixture database/storage, reads the nine real retained handoffs and submits their exact raw responses through the production PHP authoring-result projector. This is an explicitly scoped test fixture; it does not fabricate a final terminal archive or make the incomplete original run complete. It exercises authenticated teacher Dashboard Pull, duplicate-request replay, same-device claiming and the production artifact upload gateway. **Nineteen prompt files were deliberately uploaded in reverse order.** Server-side bytes, the uploaded ordered identity index, Dashboard JSON prompt export and the scene-list response all matched the original PDF → lesson → scene sequence.

The text probe exposed actual defects that the earlier single-scene sample had not covered:

- Prompt exports sorted alphabetically by section title. They now sort by PDF, retained lesson ordinal and section ordinal.
- The scene Dashboard interleaved equally numbered sections from different lessons. It now uses the same lesson/section hierarchy.
- Authoring acceptance created one work item per scene, violating the unique case/lesson constraint for multi-scene lessons. It now creates one work item per lesson with all ordered section references.

Evidence: `storage/app/private/v3/local-worker-verification/retained-nine-prompt-pull-03/verification.json`, `dashboard-prompts.json`, `dashboard-prompts.txt` and `received-prompts.zip`. The script checks that the original database and retained handoff hashes remain unchanged. The original run stays held at its saved checkpoint. The normal HTML package validator was not relaxed: text files are transport-probe artifacts, not scene candidates or student content. The text probe does **not** exercise final HTML result acceptance; the separate eight-file live replay above covers that boundary. Its temporary server was stopped afterwards.

After these fixes, the PHP command above passed **120 tests (812 assertions)**. The Python local-worker re-run (`.venv/bin/python -m pytest --import-mode=importlib v3_engine/tests/local_worker -q --tb=short`) passed **49 tests, with one explicitly gated live test skipped**. This includes the separate-process HTTP boundary. The new regression tests cover a multi-section lesson sharing one work item, and PDF/lesson/section presentation despite reversed insertion order and misleading alphabetical titles. No native terminal processing was continued for this final verification.

Private runtime directories contain local operational state and, for live runs, isolated provider session files. Do not publish or copy those directories as public evidence. `run.json` is the intended generation handoff; it excludes authentication state.

## Readiness meaning and operations

Use [the current operations guide](LOCAL-WORKER-OPERATIONS.md) for registration, daily operation, Pull and owner recovery. Public domain/TLS deployment, arbitrary repository changes, scene semantic quality, and R&D are not covered by this manager-protocol sign-off. Older checked repair lists, source hashes and broad E1–E6 claims are historical and must not be used as current evidence.

Temporary verification servers use separate databases/storage and are stopped afterwards. The existing application database and notification backlog are not migrated, erased or processed by these checks.
