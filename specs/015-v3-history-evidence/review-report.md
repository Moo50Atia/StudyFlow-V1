# Review Report: V3 Spec 015

**Review date:** 2026-10-02  
**Scope:** Only `specs/015-v3-history-evidence/` was edited. This is a documentation review. 004–014, application code, migrations, routes, configuration and tests were read for context and were not changed by this review.

## Initial read-only findings

The first pass used the available `speckit-analyze` instructions as a read-only semantic audit. Its prerequisite script resolved the pre-existing Spec Kit selector to 004, so I directly analyzed 015 without changing the selector. The constitution file is an unratified template. Findings below precede remediation.

| ID | Severity | Finding and repository evidence | Resolution in 015 |
|---|---|---|---|
| F01 | Critical | Original `plan.md`/`tasks.md` required a `LineageEdge` table and builder but 006–013 already specify immutable same-case FKs, 013 historical approvals and a Section current pointer. A second graph could contradict the authoritative chain. | Read-only exact-FK graph projection, typed gaps, no new edge table; [model](data-model.md), [contract](contracts/evidence.md). |
| F02 | High | Original `spec.md` asked for complete trace but did not define which IDs, branches, current/historical approval or missing-file condition made a chain complete. 013 explicitly clears a current pointer while retaining an old approval. | Approval-specific traversal and branch/gap rules; H01–H05. |
| F03 | High | Original `plan.md` assumed a new Python usage callback even though no `v3_engine` exists in current code; 011 already has nullable tokens/cost/duration, while 008/009 NotebookLM and human review do not promise billing data. No 005 rate card exists. | Derive from committed rows, separate timing concepts, known subtotal plus unknown full cost, no unapproved price estimate; H08–H13. |
| F04 | Critical | Original `contracts/evidence.md` had four operation labels without method/path, request/response fields, error precedence or download reauthorization. 004 requires fresh owner/admin checks, no-store and equal foreign/missing denial; actual V2 `TeacherAccessMiddleware` admits admin to legacy content. | Exact V3 routes, closed DTOs, actor-bound private snapshots, errors, rechecks and sentinel tests; H06–H07,H14–H19. |
| F05 | High | Original `spec.md` mentioned deleted artifacts but 014 only records `deferred_to_015` assessment under `pilot-retain-v1`; 006–013 retain referenced/uncertain evidence. No retention duration or evidence-hold release was approved. | Explicit no-physical-delete pilot behavior; missing/corrupt is an incident; only 015 export files expire; H18,H20. |
| F06 | High | Original `tasks.md` put schema/models before tests, named broad “implement” steps and had no real concurrency or final live evidence gate. Original runbook did not handle baseline failures or workspace changes. | T001–T014 include paths, dependencies, FR/H IDs, red/green checkpoints, two-process race and live gate; [execution plan](task-execution-plan.md). |
| F07 | High | Actual `routes/web.php`, `User.php`, `AppServiceProvider.php`, migrations, `phpunit.xml` and `config/filesystems.php` are V2-only; V3 application routes/tables/worker are not present. Original plan described 015 as though upstream records were already implemented. | Explicit 004–014 entry condition and stop rule; no production placeholder or claimed runtime success. |

## Files edited and why

| File | Substantive change | Reason |
|---|---|---|
| [spec.md](spec.md) | Replaced vague stories with eight bounded requirements, outcome definitions, no-delete policy and material open policy. | Prevent a future agent from inventing retention or cost rules. |
| [plan.md](plan.md) | Matched current V2 repository and future 004–014 foundations; chose Laravel projections and export-only persistence, ordered tests and recovery. | Avoid duplicate ledgers and nonexistent Python telemetry. |
| [research.md](research.md) | Recorded inspected evidence and rejected alternatives. | Make architecture choices reviewable. |
| [data-model.md](data-model.md) | Specified derived graph/metric value objects and exact export/audit tables, constraints, atomic file and cleanup rules. | Give implementer concrete data and concurrency behavior. |
| [contracts/evidence.md](contracts/evidence.md) | Added prerequisite inventory, lineage walk, exact routes/DTOs, metric formulas, failure/replay/privacy/retention contract. | Close the interface and business-rule gaps. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | Added H01–H20 mapped to FRs, future test files and observable outcomes. | Prove semantics rather than file existence. |
| [tasks.md](tasks.md) | Reordered T001–T014 around baseline, test-first groups, real route boundary, race, cleanup and live evidence. | Make work executable with completion conditions. |
| [task-execution-plan.md](task-execution-plan.md) | Added entry checks, ordered groups, before/after tests, workspace/baseline handling, stops and report contents. | Provide the direct future agent plan. |
| [implementation-notes.md](implementation-notes.md) | Documented V2 middleware/disk traps, current pointer, privacy, time/cost meaning and no-delete boundary. | Explain repository-specific hazards without repeating task order. |
| [quickstart.md](quickstart.md) | Added concrete future verification commands and expected focused, full, race and live results. | Avoid falsely certifying tests during documentation review. |
| [checklists/requirements.md](checklists/requirements.md) | Replaced premature all-checked claims with reviewed checks and explicit open policy/prerequisite items. | Reflect actual review state. |
| [review-report.md](review-report.md) | Recorded findings, edits, decisions, coverage and limits. | Make this review auditable. |

## Design decisions and coverage

The authoritative lineage is the 006 case and captured settings/Document, 008 extraction/binding, 009 map/Section, 010 prompt/JEV, 011 candidate/attempt, 012 check/JEV/repair and 013 review/approval, with 007/014 case-level events. The 013 current pointer controls current approval count; historical approval rows remain traceable. `process_again` is its own case. Period cost includes unsuccessful case spend in the numerator, so unknown charges block a full cost-per-current-approval value rather than lowering it silently. Admin reports use a closed aggregate serializer with no teacher/content IDs; owner exports contain references, not artifact bytes.

| Requirement | Scenarios | Implementation tasks |
|---|---|---|
| FR-001 | H01,H02,H04 | T001,T003–T005,T013–T014 |
| FR-002 | H02–H05,H20 | T003–T005,T009,T013–T014 |
| FR-003 | H01,H06,H07 | T002–T005,T013–T014 |
| FR-004 | H08–H10,H13 | T006–T008,T013–T014 |
| FR-005 | H11–H13 | T006–T008,T013–T014 |
| FR-006 | H14–H16,H20 | T006,T008–T011,T013–T014 |
| FR-007 | H17–H19 | T009–T013 |
| FR-008 | H05,H18,H20 | T003–T004,T009,T012–T014 |

All 20 H scenarios name planned test files in the [matrix](contracts/acceptance-matrix.md). H19 specifically requires two overlapping processes on a shared transactional database. H15 requires checking real admin route *and* generated export bytes. The implementation report must map actual test names/results and distinguish fixture evidence from live integration.

## Final read-only review

- Cross-artifact pass: eight requirements, 20 scenarios and 14 ordered tasks are covered; the acceptance matrix has a planned test path and expected result for each scenario. Tests for owner graph, metrics/admin route and export/race precede their governing implementation groups. State, route, role, current/historical approval, UTC timing, cost and retention terms were reconciled across artifacts.
- Local Markdown links and task/FR references were checked after the edits; no unresolved design placeholder remains. `review-report.md` now satisfies the report links. The 004 Spec Kit selector was already set before this review and was not changed.
- The initial repository status already included modified `.specify/feature.json`, `package.json`, `package-lock.json` and untracked V3 spec directories. This review's file edits were confined to 015. `git diff --check` found no tracked-file whitespace errors, though untracked 015 files are outside that command's coverage.
- These are document consistency checks. **Application implementation and application tests were not performed during this documentation review.** No runtime, live provider, Temporal, security or billing behavior is certified here.

## Open decisions, risks and prerequisites

1. **Physical retention**: no approved duration, scope or evidence-hold release exists. The implementable pilot policy retains referenced/uncertain educational artifacts; 014's deferred assessment cannot trigger deletion. A physical purge feature needs a later approved contract. This is a material open business decision for any deletion rollout.
2. **Estimated money**: no approved tariff, human labor rate or billing feed exists. The implementable pilot shows verified reported charges as known subtotal and full cost as unknown when components are missing. An estimate rollout needs an approved versioned basis. A cost-source clarification was requested and is still pending at report time.
3. **Prerequisite implementation**: the inspected application remains V2-only. 004–014 must be implemented and their gates met before 015 tasks begin; a fixture-only 015 pass cannot certify live NotebookLM/JEV/Temporal linkage or actual billed cost.
4. **Export size**: the specified 10 MiB JSON cap yields a fixed 413 instead of truncation. Approval-scope export or a narrower admin period is the defined fallback. A later larger-case export mechanism would require a separate contract if pilot data exceeds this bound.
