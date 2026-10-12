# Per-PDF processing in one NotebookLM notebook

Implemented and verified on 2026-10-10. Operator instructions: [PER-PDF-NOTEBOOKLM.md](PER-PDF-NOTEBOOKLM.md).

## Result

New submissions support `--pdf-processing per-pdf`. Each PDF receives its own source-scoped Divide, Execution and complete lesson loop in one subject notebook. Exact answers and named lessons are archived locally before clearing server history and advancing. Completed PDF sources remain uploaded. Scene generation begins after every PDF is archived; export-only mode avoids scene-provider requests.

Joint processing remains the default. Existing manifest digests omit the default processing-mode field, and legacy workflow histories retain their original Activity order. Brave/MCP authentication, `.env`, canonical prompts, approved `steps.txt`, Laravel application code and numbered specs were not edited.

## Substantive changes

| Files | Change and reason |
|---|---|
| `v3_engine/terminal_pipeline/manifest.py`, `cli.py` | Capture the selected processing mode, PDF ordinals and byte digests. Reject changed-mode reuse of a submission ID; expose the new flag. |
| `v3_engine/notebooklm/ports.py`, `sdk_provider.py`, `terminal_service.py` | Add an explicit conversation-reset operation and fresh-after-reset hint, while preserving upload/chat separation and the existing authentication provider. Capture PDF provenance on handoffs. |
| `v3_engine/terminal_pipeline/pdf_scope.py` (new) | Separate per-PDF folders; seal receipts, captures, handoffs and accepted-input references. Verify them before reset or downstream consumption. Namespace terminal scene identity by PDF ordinal. |
| `v3_engine/terminal_pipeline/activities.py` | Scope readiness to active sources, retain immutable captures, archive before reset, reconcile uncertain deletes and Divide responses, route scenes to the correct per-PDF store, aggregate exports and derive final counts from verified artifacts. |
| `v3_engine/terminal_pipeline/subject_workflow.py`, `worker.py`, `status.py` | Run the sequential protocol durably, register its Activities on the correct queues, inspect uncertainty without repeating mutations and expose PDF progress. |
| `v3_engine/tests/terminal_pipeline/test_per_pdf.py` (new) | Cover mode capture, source selection, repeated local lesson numbers, corruption, interrupted sends/deletes, receipt reuse, reused provider conversation IDs, both output modes and actual Temporal replay. |
| `v3_engine/tests/terminal_pipeline/test_live_pipeline.py` | Support an opt-in two-PDF live run; verify per-PDF archives, distinct sources and unique terminal scene identities without assuming Google allocates a new chat ID. |
| `scripts/v3/examples/per_pdf_mechanics.json` (new), `docs/PER-PDF-NOTEBOOKLM.md` (new) | Provide the existing eight PDFs in explicit lecture order, a fresh-submission preparation command, service startup, monitoring and result locations. |
| This report | Record actual evidence and its limits. |

## Problems discovered through live testing

1. **Deletion leaves a stale latest-conversation ID.** The initial reset check expected `None` after deletion. Real NotebookLM still returned the old ID, while its history contained zero turns. Reset now proves absence or empty history on the exact archived ID. A durable fence prevents repeating the delete, including after a crash.
2. **A cleared chat can reuse its provider ID.** The next response used the same ID despite having an empty initial history. Requiring a different ID discarded a successful Divide response. That requirement was removed. Local identities now include the PDF ordinal, so repeated lesson/scene numbers cannot collide even when the provider ID is reused.
3. **A successful initial turn can lose its local response.** The retained dispatch intent had no conversation ID yet. Recovery now discovers the notebook's current conversation and matches the expected question and history position without sending another request. The live PDF 2 Divide answer was recovered this way.

The SDK compatibility handling is confined to the notebooklm-py adapter. Pinned version 0.8.4 keeps a private `_deleted_conversations` marker in the deleting client; a newly opened Activity client loses it. The adapter restores that marker only after verifying the exact archived chat is empty. An unsupported SDK shape fails before sending. The marker does not manufacture an identifier or alter answers.

## Verification

| Command / evidence | Result | What it proves |
|---|---|---|
| `pytest --import-mode=importlib v3_engine/tests/terminal_pipeline v3_engine/tests/notebooklm/test_sdk_contract.py v3_engine/tests/scene_generation -m 'not temporal and not live and not integration' -q` | **266 passed, 1 skipped, 23 deselected**, 49.72 s | Offline regressions and SDK contracts; the skip is not a pass. Ten existing SDK deprecation warnings remain. |
| `pytest v3_engine/tests/terminal_pipeline -m 'temporal and not live and not integration' -q` | **18 passed, 113 deselected**, 52.61 s | Real Temporal orchestration/replay. External providers are substituted in this lane. |
| Four new connected Temporal cases inside the preceding lane | **4 passed** | Generate and export-only modes, with both distinct and reused chat IDs. Production workers/Activities/stores/builders run; the external SDK/provider transports are doubled. Each generation case retains six bundles across three mapped lessons; export cases make zero scene-provider sends. |
| Actual two-PDF live execution, including recovery through the production worker CLI | **Completed: 2 PDFs, 2 lessons, 2 generated and built scenes; 0 failed/uncertain/queued items** | Real NotebookLM upload, mapping, lesson responses and Gemini calls; actual retained HTML/CSS/JavaScript bundles. |
| Fresh read-only archive, receipt and compiled-file verification | **Passed for both PDFs** | Seven sealed capture/receipt/handoff references per PDF, accepted input digests, complete HTTP 200 provider receipts and valid current build pointers/file hashes. |
| Live NotebookLM source read after completion | **3 ready sources retained** | Both original PDFs and the framework remain in the single notebook. |
| Replay of the completed live workflow | **Passed, 232 events** | The actual live history, including the recovered Divide, replays with the current workflow. |

The live pytest attempts were interrupted while fixing the reset assumptions; they are **not reported as passing pytest runs**. The retained execution was resumed using the production `run_temporal_pipeline.py worker` command against its persistent Temporal database. Its final result and artifacts were independently asserted, and the completed history was replayed. No completed lesson or uncertain Divide was resent to achieve recovery.

An earlier disposable test used pytest's default temporary retention, and its local files were pruned. The final live run uses a dedicated project-local evidence folder. Production run directories use the persistent terminal root and are not subject to pytest's temporary cleanup.

## Live evidence and scene files

- Subject run: `be9b2b0b-d02f-4b5a-a13e-01b3e12e1ba0`.
- Notebook: `d06d9f00-6f6e-4553-af26-89638121f80d`.
- [Final report](../storage/app/private/v3/per-pdf-live-verification-20261010/test_live_terminal_pipeline_sm0/terminal/runs/be9b2b0b-d02f-4b5a-a13e-01b3e12e1ba0/final-report.json).
- [Saved live workflow history](../storage/app/private/v3/per-pdf-live-verification-20261010/live-workflow-history.json).
- [PDF 1 scene: average velocity](../storage/app/private/v3/per-pdf-live-verification-20261010/test_live_terminal_pipeline_sm0/terminal/runs/be9b2b0b-d02f-4b5a-a13e-01b3e12e1ba0/pdfs/pdf-0001/scene-generation/lesson-0001/scene-0001/attempt-0001/builds/build-0001/dist/index.html).
- [PDF 2 scene: net force](../storage/app/private/v3/per-pdf-live-verification-20261010/test_live_terminal_pipeline_sm0/terminal/runs/be9b2b0b-d02f-4b5a-a13e-01b3e12e1ba0/pdfs/pdf-0002/scene-generation/lesson-0001/scene-0001/attempt-0001/builds/build-0001/dist/index.html).

Both provider results identify `gemini-2.5-flash`. The captured thinking budget was **24,576**, with a **65,536** output-token cap. Provider-reported input/output usage was 3,547/6,764 tokens for PDF 1 and 3,281/4,848 for PDF 2; measured request durations were 92,600 ms and 72,808 ms. These numbers do not assert consumption of the full thinking budget or an estimated price.

## Final scope and limits

The baseline hash inventory covered 8,926 existing files. Ten changed existing files were all within the intended NotebookLM/terminal implementation and its live test. No baseline file was deleted. Other existing files, numbered specs, canonical prompts and Laravel code retained their baseline hashes. New files are the per-PDF helper, tests, example and documentation; test inputs/evidence are in private storage. No commit was made.

The owned test Temporal servers and worker were stopped after verification. The persistent live database and outputs remain available. Remote sources and the final chat were retained.

The eight mechanics PDFs are present and referenced by the example, but **an eight-PDF live per-PDF run was not performed**. The live smoke used two newly authored one-page PDFs. Coverage and scene quality still require educational review; lesson counts alone do not prove coverage. JEV remains the existing recorded demo waiver, and this verification does not establish live JEV qualification, teacher approval, Laravel upload integration or production deployment readiness.
