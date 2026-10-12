# Superseding Status Review: Spec 019 Web Integration (2026-10-12)

This review supersedes the earlier T155 completion claim. The previous report said all offline gates and A01–A28 passed, but its evidence manifest currently fails the validator. Therefore the feature does not have complete Spec 019 acceptance or a full-system green status.

## Verified green: connected web workflow, including live NotebookLM

The browser-driven joined runtime test passed through the following real application boundaries:

1. Chromium teacher login and ordered two-PDF upload to Laravel.
2. Durable Laravel outbox dispatch, signed FastAPI bridge admission, real local Temporal service/workers, and the authorized NotebookLM SDK session.
3. Per-PDF sequential processing, lessons and prompt persistence, and same-run JSON/text prompt downloads.
4. Same-run manual scene import and selected Qwen API authoring through outbox and Temporal, using a scripted Qwen HTTP transport.
5. Real Chromium scene qualification, preview first frame, owner approval, and delivery/consumption of the review decision by a real Temporal review workflow.

The joined browser test now drives the review UI itself: approval remains disabled until frame zero is shown, then the owner approves and submits a postapproval correction. The response is HTTP 201 and the accepted correction is verified in the test database; review and correction effects are dispatched through Temporal.

That test also reopens the processed teacher run and verifies its persisted status and ordered PDFs, then opens the admin operations dashboard and confirms it exposes the run identity and operational boundary without exposing teacher PDF titles.

The exact original eight-PDF archive has now passed the import and owner navigation acceptance in isolated storage/database: 8 lectures, 63 lessons, 90 sections, 89 saved prompts and an exact 89-prompt export. A separate queued-import test passed across signed Laravel outbox, FastAPI bridge, Temporal worker and Laravel materializer, including duplicate import handling. These results close the earlier archive-import/navigation gap, while lost-ack and interrupted-import recovery remain open.

The selected API path exposed and fixed a protocol mismatch. Web cases use `notebooklm_combined_v1`; authoring eligibility had checked only `notebooklm_per_pdf_web_v1`, causing valid owner-waived prompts to be rejected. `ProcessingCase::isWebPipelineCase()` now recognizes the durable `SubjectRunItem` link, and the joined test passes both scene paths.

The live two-PDF run then exposed a second integration defect: Python preserved prompt text placed on the `LecX (SecY):` header, while Laravel's PHP validator discarded that inline text and rejected the next line with HTTP 422. `CombinedLessonValidator` now captures the inline prompt identically to Python. The PHP commit test now covers this response shape.

## Latest regression results (2026-10-12)

- Laravel WebPipeline feature/integration: **174 passed, 1 skipped, 1,005 assertions**. The skipped test is the separate full live-application gate.
- Python WebPipeline suite: **94 passed, 9 skipped** (8 opt-in Temporal cases and 1 live generation gate).
- Joined runtime suite with Temporal opt-in: **13 passed in 234.66 seconds**.
- Focused browser joined two-PDF + live NotebookLM + manual/Qwen-scripted scene + review journey: **1 passed in 330.05 seconds on 2026-10-12** (a prior run passed in 295.82 seconds). Both PDFs completed real Divide, execution, lesson-start, and Continue turns; Laravel committed four lesson bundles. Browser exports, manual scene import, scene qualification, preview, approval, and review decision assertions passed. Qwen remained a scripted HTTP transport because `QWEN_API_KEY` is not configured.

Detailed commands and the limitations of each execution are recorded in `regressions.md`.

## Remaining acceptance blockers

- `scripts/v3/verify_web_repair_evidence.py --require offline` rejects the current manifest with **46 errors**, including missing source snapshot digest, execution metadata, artifact integrity/type, exact test IDs and acceptance-to-execution references. Unsupported pass labels without execution references were downgraded to `not_run`; stale execution entries that hashed narrative documents were removed. The prior reported manifest success is not substantiated.
- Interrupted/lost-ack recovery for queued archive import remains open. Process restart after each required boundary and actual serialized-history privacy scanning are not yet verified (T145/T147). Exact archive materialization, queued happy path, idempotent duplicate handling and owner navigation are verified; do not check archive tasks without reconciling their individual requirements.
- Evidence matrix and all acceptance criteria are not yet completely linked to executions. The full V01–V12 matrix is incomplete (T148), and T120/T151 remain open.
- The live NotebookLM-connected joined test has now passed. Full live scene generation remains unverified because Qwen credentials, explicit spend acknowledgement, proof notebook ID, and two approved PDFs are absent. The opt-in full-chain live runner is implemented and its preflight regressions pass; T152 and T112 remain open until that runner executes. Exact archive recovery and offline acceptance evidence remain open.
- Final task reconciliation remains open (T154–T155). The project `.env` now enables the local web pipeline in `export_manual` mode and points to bridge `127.0.0.1:8765` and Temporal `127.0.0.1:7233` / `v3-terminal`. A disposable local runtime on Laravel `127.0.0.1:8080`, bridge `127.0.0.1:8765`, isolated Temporal and registered worker queues passed signed health and Chromium login/access smoke checks. Port 8000 remains the pre-existing generated-scenes directory server and was left untouched.

## Readiness statement

**Green for the verified joined Laravel-to-Temporal workflow, including live NotebookLM two-PDF processing, browser-driven scene review/correction, exact eight-PDF archive navigation, and queued archive import. Not green for complete Spec 019 acceptance or unconditional production readiness:** live Qwen generation, interrupted/lost-ack import recovery, and valid evidence-ledger verification remain open.

All joined runs used isolated test databases and dynamic test ports. Operator startup docs now use the actual bridge port, namespace, outbox scheduler, and a loopback Laravel port. Port 8000 is a pre-existing Python directory server rooted at `generated_scenes`, not the Laravel web application; I left it running pending owner direction. No real Qwen request was made.


## Superseding local runtime verification — 2026-10-12

The local `.env` now enables the V3 web pipeline with `export_manual` as the default and the documented bridge/Temporal origins. The real project SQLite database was copied to a mode-0600 temporary database for this runtime check. Laravel ran loopback-only with the documented 100M/1100M PHP upload limits; readiness returned `ready=true`. The PHP BridgeRequestSigner produced a valid request accepted by FastAPI at `/internal/v1/health`, which confirmed Temporal reachability. Three real worker queues registered in namespace `v3-terminal`. Chromium rendered the login form and confirmed unauthenticated teacher intake redirects to login. No PDF/job/provider request was submitted. Evidence: `repair-evidence/runtime/local-stack-smoke-20261012.json`.

Local bridge and callback HMAC values were rotated after they appeared in diagnostic output; the signed health check passed after restart. This runtime smoke does not close any view or acceptance row.
