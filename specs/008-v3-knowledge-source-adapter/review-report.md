# Spec 008 documentation review report

**Date:** 2026-10-02. **Scope:** `specs/008-v3-knowledge-source-adapter/` only. This is a specification and documentation review, not application implementation.

## Authority and repository baseline

I read Conversation 23, the V3 map, every original 008 artifact, applicable 004–007 prerequisite contracts and directly affected 009/010/015 contracts. I inspected actual Laravel bootstrap/routes, legacy access middleware, models, migrations, storage/queue/tests/config, V2 extraction/OCR reference code and available binaries. No repository-root or ancestor `AGENTS.md` applies to this directory; `.agents/AGENTS.md` governs that subdirectory only. The read-only `speckit-analyze` semantic pass was performed before remediation; its selector helper targets 004 in the pre-existing `.specify/feature.json`, so I left the selector unchanged and reviewed 008 directly. The constitution file is an unratified template with placeholders, not an operative principle set.

The checkout has Laravel 12/PHP ^8.2, Python 3.12, Node/npm and Poppler `pdftoppm`, but no implemented 004–007 application code, `v3_engine`, selected NotebookLM MCP CLI, Tesseract or Temporal CLI. `config/filesystems.php` has no 006 private disk yet; `bootstrap/app.php` loads web/console only; `phpunit.xml` uses SQLite `:memory:` and sync queue. These are prerequisites for a future implementer, not changes made here.

## Initial read-only findings

| Severity | Finding before edits | Repository/provider evidence | Resolution in 008 |
|---|---|---|---|
| Critical | The original spec assumed `notebook_add_text` returned a source ID and could bind immediately. | Selected [source tool](https://github.com/m4yk3ldev/notebooklm-mcp/blob/main/src/tools/source.ts) returns only a message from `notebook_add_text`; `source_get_content` exists. | Added one-send ledger, notebook inventory plus exact source-content verification, and live installed-schema/round-trip gate. |
| Critical | Python was told to read 006's accepted private Laravel PDF without a defined transport or worker authorization. | 006 exposes Laravel `AcceptedPdfReader::openForCase`; no shared Python disk/path/API exists. | Signed activation-scoped PDF stream and private extraction/text/effect endpoints using 007 HMAC, case/Document/settings checks and no public path. |
| High | Start, create and add timeouts could duplicate external notebooks/sources or silently adopt a wrong title; `IngestionAttempt` had no constraints or algorithm. | Selected MCP create/add tools expose no idempotency key; 007 replay alone does not make provider effects exactly once. | One activation/case, unique effect `(case,operation)`, send claim before call, `send_count≤1`, read-only reconciliation and explicit uncertain stops. |
| High | The extraction completeness rule was subjective, with no OCR budget, page format, structural gap decision or size bound. | 005 captures `max_ocr_pages`; 006 accepts image-only PDFs; V2 extractor uses density fallback, arbitrary output paths and raw error logging. | Canonical page markers/report, captured OCR cap, deterministic fail-closed page/visual/table gate, 1-MiB no-truncation bound, atomic private artifact. |
| High | 007's parked workflow was not activated or extended; original tasks would make an adapter while no live case could reach it. | 007 [handoff contract](../007-v3-workflow-progress/contracts/workflow.md) parks at extraction and assigns `source_activation_v1` to 008. | Durable Laravel activation, signed bridge signal, replay-compatible worker, safe extraction/sourcing events and 009 parking point. |
| High | Query/adapter responsibilities blurred 008 and 009, risking NotebookLM IDs or raw answers in Temporal history and invented mapping in 008. | Selected [query tool](https://github.com/m4yk3ldev/notebooklm-mcp/blob/main/src/tools/query.ts) supports `source_ids`; 009 owns map, prompts and Sections. | Case-scoped activity-local query method; 009 must persist answers privately and preserve canonical prompts/all eleven fields. |
| High | Ownership/privacy tests did not cover signed worker routes, legacy admin access, same-PDF cross-case isolation or exact admin DTO. | Legacy `TeacherAccessMiddleware` gives admin broad V2 content access; 004 restricts V3 admin to a closed summary. | Owner-only source-status, HMAC before lookup, case-scoped internal refs, no admin source route, live boundary/isolation/privacy tests. |
| Medium | Task sequence put schema/implementation before behavioral tests, named few files and could pass on fake adapter alone. | Original T001–T014 and quickstart listed only generic pytest/PHP commands. | T001–T024 dependency table, runnable behavioral red/green groups, independent-process races and separate live MCP/OCR/Temporal gates. The Temporal control group uses test-only activities until extraction/ingestion are implemented. |
| Medium | Transfer evidence and retention were vague. | 015 owns eventual export/retention; 004 forbids content in admin output. | Exact attempted/confirmed transfer digest/bytes/time, private retained rows/artifact, safe logs and unresolved-effect preservation. |

## Files edited and why

| File | Change and need |
|---|---|
| [spec.md](spec.md) | Replaced vague stories with FR-001–FR-008, K01–K18 and proof-limited SC-001–SC-003; makes business and technical acceptance observable. |
| [plan.md](plan.md) | Gives actual repository fit, exact Laravel/Python files, ordered architecture and live stop gates; removes assumed cross-process file access. |
| [research.md](research.md) | Records inspected code and selected MCP source/query/package evidence, plus inference and live capability limits. |
| [data-model.md](data-model.md) | Defines four exact tables, keys, state sets, private artifact/page report, one-send effect and transfer lineage. |
| [contracts/knowledge-source-adapter.md](contracts/knowledge-source-adapter.md) | Specifies signed internal routes, activation, extraction/OCR, provider reconciliation, query, 007 events, teacher view, errors and privacy. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | Maps every K case to FR IDs, specific planned tests/outcomes and FR-to-task trace. |
| [contracts/integration-obligations.md](contracts/integration-obligations.md) | Defines the 009+ handoff and distinguishes what 008 proves now from later mapping/JEV/scene/review/history work. |
| [tasks.md](tasks.md) | Replaces generic sequence with T001–T024, concrete paths, dependencies, FR/K IDs and completion conditions. |
| [task-execution-plan.md](task-execution-plan.md) | Makes the future agent's direct plan: prerequisites, baseline, red/green groups, checkpoints, blocker handling, final criteria and report contents. |
| [implementation-notes.md](implementation-notes.md) | Documents repo-specific traps, selected MCP message-only result, V2 limitations and fixed boundaries without duplicating the plan. |
| [quickstart.md](quickstart.md) | Adds future verification commands and expected results, distinguishing isolated tests from live NotebookLM/Temporal proof. |
| [checklists/requirements.md](checklists/requirements.md) | Separates completed documentation checks from unmet runtime gates. |
| [review-report.md](review-report.md) | Records initial findings, evidence, changes, coverage, final consistency result and limitations. |

## Design rationale and coverage

The design keeps Laravel as owner of case/Document/settings, private artifacts and effect ledger; Python uses the existing 007 worker bridge for local extraction and selected MCP calls. It uses 006's verified reader rather than a guessed shared mount. The selected MCP's message-only add response requires content proof before binding, and its non-idempotent create/add operations get at most one automatic send. Ambiguous outcomes remain visible; an empty list does not prove the provider never accepted a call. The extraction gate blocks detectable unreadable/visual/structural gaps instead of claiming semantic completeness. 008 query stays activity-local and source-scoped; 009 owns grounded learning-unit mapping and answer persistence.

The [matrix](contracts/acceptance-matrix.md) maps all eight FRs, all 18 K scenarios and all 24 ordered tasks. K01/K18 require real 007 Temporal history/replay; K02/K08/K15 require real Laravel/004 security boundaries; K04–K07 need fixture/real OCR and private artifact checks; K09–K13 require ledger races and uncertainty tests; K17 requires installed selected MCP with real source-content proof; K14 is a scoped adapter contract, not a live 009 map. Fake MCP tests cannot certify K17.

## Final review result and remaining gates

The final fresh read-only cross-artifact check found eight FRs, 18 K scenarios, 24 ordered tasks and no missing FR-to-matrix, FR-to-task or K-to-task mappings. It checked planned test paths, state/field/route terminology, task dependencies, local links, unresolved placeholders, notes/plan alignment and the unchanged feature selector. The T007–T008 checkpoint proves signal/replay with test-only activities; T018–T019 add the scoped query before T020–T021 require the full live path. This is documentation consistency, not runtime certification. No unresolved owner business-rule choice was identified; a material **technical capability risk** remains: the selected live MCP may normalize returned source content or show incomplete notebook inventory, preventing exact binding proof. If so, the future implementer must report a blocked live gate and resolve the adapter capability explicitly rather than treat a success message as evidence. The 004–007 application foundations, Tesseract, Temporal and MCP account/tooling are also absent from this checkout and mandatory for implementation completion. 009–015 remain separate features.

The extraction gate detects specified geometry and residual ink; it cannot prove that every educational diagram or formula is semantically represented. Representative scan, vector, table and equation fixtures and owner-visible blocked gaps are required; any detected unsupported content stops transfer. This residual visual risk is separate from the live MCP capability gate.

No application code or application tests were implemented or run during this documentation review. No other numbered spec was edited, and no commit or deployment was made.
