# Spec 009 documentation review report

**Date:** 2026-10-02. **Scope:** only `specs/009-v3-lesson-map-sections/`. No application implementation, runtime test, commit or deployment was performed.

## Authority, method and baseline

I read Conversation 23, the V3 spec map, every original 009 file, 004–008 prerequisite contracts, directly affected 010/011/015 contracts and the actual Laravel routes/controllers/models/migrations/tests/config. I used [Spec 004](../004-v3-access-ownership/spec.md) for expected depth, not as a copied design. No root/ancestor `AGENTS.md` applies to this directory; `.agents/AGENTS.md` applies only to `.agents`. `.specify/memory/constitution.md` is an unratified placeholder. The `speckit-analyze` prerequisite script selected preexisting 004 from `.specify/feature.json`; I left that selector unchanged and performed the initial read-only semantic analysis of 009 directly. There are no `.specify/extensions.yml` hooks.

Current code is Laravel 12/PHP ^8.2 with V2 `Lecture`/`Section` only; `bootstrap/app.php` has no V3 API loader, `config/filesystems.php` has no 006 private disk and `phpunit.xml` defaults to SQLite `:memory:`/sync queue. The 004–008 specs are not implemented in this checkout, and selected MCP/Temporal live tools are not available here. These are future implementation prerequisites, not work done in this review.

## Initial read-only findings before edits

| Severity | Finding | Evidence | 009 resolution |
|---|---|---|---|
| Critical | Original 009 said advance after Third outputs while 010 owns Second/Third, yet 009's tasks tried to implement a complete lesson loop first. | Original `plan.md` step 3, T009–T013; 010 [spec](../010-v3-prompt-jev-qualification/spec.md) and [plan](../010-v3-prompt-jev-qualification/plan.md). | 009 now owns Divide/First/cursor gate; signed 010 completion attests Third persistence, and full live continuation remains a 010 obligation. |
| Critical | A literal `Continue` was treated as safely repeatable despite a stateful provider conversation and 008's generic query retry. | Original US3/T011–T013; 008 [query contract](../008-v3-knowledge-source-adapter/contracts/knowledge-source-adapter.md); selected [MCP query source](https://github.com/m4yk3ldev/notebooklm-mcp/blob/main/src/tools/query.ts) exposes conversation ID but no idempotency token/history lookup. | Added committed one-send query ledger, `query_once`, unknown-outcome stop, exact duplicate handling and independent-process races. |
| High | 008's parked workflow had no 009 activation, signal, event provenance or return signal from 010. | 008 [handoff](../008-v3-knowledge-source-adapter/contracts/integration-obligations.md) assigns `mapping_activation_v1` to 009; original 009 had no route/job/signal fields. | Added activation row/dispatch/bridge signal, 007 `map_blocked` extension, replay tests and durable `lesson_ready_v1` notification. |
| High | The map was called complete without a parse grammar, parent constraints, coverage evidence or duplicate-label rule. | Original `data-model.md` was a field sketch; canonical Divide prompt requests freeform structural map. | Fixed conservative hierarchy grammar, Mini promotion, distinct duplicate titles, page/work coverage query, 16,384-byte limit and live known-fixture gate. |
| High | The eleven fields lacked exact columns, label/empty rules and atomic raw-response lineage. | Original `data-model.md` said eleven headings; V2 `sections` migration has only partial fields. | Added eleven non-null V3 long-text columns, exact display labels, optional empty laws value, private raw answer and atomic First acceptance. |
| High | A V3 Section view could inherit V2 admin-readable routes. | `routes/web.php` mounts `/sections` under `teacher.access`; `SectionController`/V2 schema lack V3 owner lineage. | New 004 owner-scoped V3 routes, case-first child lookup and actual legacy/admin bypass tests; V2 unchanged. |
| High | Private answers, conversation IDs, retry/error behavior and retention were not defined. | Original contract had four generic operation rows; original task plan had no artifact/worker API/schema. | Seven-table model, signed worker routes, private immutable artifacts, safe errors/logging and unresolved-attempt retention. |
| Medium | Mechanical FR/task coverage masked nonexecutability: schema before behavior tests, vague tests, no live integration proof or implementation-report gate. | Original T001–T014 and nine-line quickstart. | T001–T023 with runnable red/green groups, test files, proof levels, baseline/stop/report instructions and acceptance matrix. |

## Files edited and why

| File | Substantive change and purpose |
|---|---|
| [spec.md](spec.md) | FR-001–FR-008 and L01–L13 distinguish live map/First proof from later 010 completion; makes acceptance observable. |
| [plan.md](plan.md) | Actual Laravel/Python file inventory, 007/008 reuse, transactions and boundaries; removes assumed V3/V2 reuse. |
| [research.md](research.md) | Records inspected repository/provider evidence and limits of provider page-coverage claims. |
| [data-model.md](data-model.md) | Seven exact 009 tables, unique/FK/state/field constraints, answer artifacts, cursor and per-work completion evidence. |
| [contracts/lesson-structure.md](contracts/lesson-structure.md) | Exact prompts/digests, query grammar, signed routes, workflow signals/events, continuation/errors/teacher DTO/privacy. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | New L-to-FR/test/outcome and FR-to-task trace; separates fake from live proof. |
| [contracts/integration-obligations.md](contracts/integration-obligations.md) | New 010–015 obligations, especially combined `Continue` response and Third persistence; states 009 proof limit. |
| [tasks.md](tasks.md) | T001–T023 concrete paths, dependencies, FR/L IDs and completion conditions with tests before implementation. |
| [task-execution-plan.md](task-execution-plan.md) | Direct agent plan with entry gates, baseline, ordered red/green checkpoints, stop rules, final criteria and report contents. |
| [implementation-notes.md](implementation-notes.md) | Repository traps and fixed boundaries: V2 access, malformed-but-immutable Execution body, 008 query retry and 010 receipt trust. |
| [quickstart.md](quickstart.md) | Future verification commands and expected isolated/live results; makes no claim they passed now. |
| [checklists/requirements.md](checklists/requirements.md) | Separates completed documentation checks from runtime and downstream gates. |
| [review-report.md](review-report.md) | Initial findings, evidence, decisions, coverage and remaining risks. |

The five bodies in [canonical-prompts.md](contracts/canonical-prompts.md) were **not edited**. The contract records SHA-256 digests of their exact fenced contents, including the Execution block's awkward wording and unfinished quote. `steps.txt` remains noncanonical.

## Design rationale and coverage

Laravel holds case/map/Section identity, private answer/completion records and active-owner views; Python calls the already bound 008 adapter; Temporal runs activation and parks without a worker while waiting. The extra coverage query is NotebookLM source-grounded evidence after the unchanged Divide query, not a local unit generator or another model judge. Its page assignment can detect explicit gaps but cannot certify all semantics of an arbitrary source. A narrow parser fails closed instead of manufacturing a Lesson from prose. The First parser stores every field and the raw answer; 010 owns Execution/Second/Third, JEV and actual Third-output evidence. 009's completion receipt checks structure and trust identity but cannot prove a future 010 store exists.

The [matrix](contracts/acceptance-matrix.md) covers all eight FRs, 13 L scenarios and 23 tasks. L01/L13 require persisted 007/008 Temporal replay; L02/L04/L06/L13 require installed selected MCP with a known fixture; L05/L09–L11 require real transactional/race assertions; L08/L12 require real 004/admin/legacy/privacy boundaries. A test-only 010 caller proves cursor gating, not a complete three-step production cycle.

## Final documentation review and remaining gates

The fresh read-only cross-artifact pass checked FR/L/task/test coverage, state/field/route terminology, dependency order, local links, placeholders, prompt-block digests, notes/execution-plan alignment and the unchanged feature selector. It found eight FRs, 13 scenarios and 23 ordered tasks with no unmapped IDs or missing planned test names. The canonical block's preexisting trailing spaces were preserved intentionally; whitespace checks exclude that immutable artifact. This is documentation consistency, not runtime certification.

The material remaining **downstream integration question** is how 010 consumes a literal `Continue` reply if NotebookLM returns all three steps in one answer, while its current plan says it will invoke Second and Third separately. 010 must specify and live-test reuse versus staged turns before full multi-Lesson completion can be claimed; 009's signed receipt and private raw reply make either contract reviewable, but do not resolve provider behavior. The installed selected MCP's map/coverage/First formatting and conversation continuity are also unverified live capability gates. 004–008 application foundations are absent in this checkout. Therefore this review does not certify 009 as fully implementation-ready for a complete multi-Lesson workflow.

No application code or application tests were implemented or run during this documentation review. No other numbered spec was edited. No commit or deployment was made.
