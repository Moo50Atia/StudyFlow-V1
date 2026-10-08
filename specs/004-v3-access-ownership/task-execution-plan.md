# Agent Task Execution Plan: 004

**Purpose**: The prepared execution plan for the implementation agent. Follow it directly; do not generate another implementation plan or regenerate this task list.

## Entry conditions

1. Select `specs/004-v3-access-ownership` explicitly in `.specify/feature.json` before Spec Kit commands; verify the resolved path.
2. Read all files listed in `implementation-notes.md`, the V3 map and flow 1 of Conversation 23. No prerequisite feature implementation is needed.
3. Record workspace baseline and run the baseline commands from `quickstart.md` (T001). Protect unrelated work. Do not assume a green baseline from the documentation review.
4. Create `implementation-report.md` for actual implementation evidence. Keep it distinct from `review-report.md`, which describes this documentation revision.

## Execution sequence with concrete checkpoints

| Step | Tasks | Required sequence | Exit evidence |
|---|---|---|---|
| 1. Baseline/fixtures | T001–T002 | Inspect repository, capture baseline, add only test support | Baseline failures named; A/B fixtures available; no production future model |
| 2. Schema/bootstrap | T003–T004 | Schema/backfill/bootstrap/FK tests red, migration/models/command green | L01/L02/L17 pass; existing users retained |
| 3. Audit primitive | T005–T006 | Fault/privacy tests red, recorder green | Recorder assertions pass; HTTP and account-service integration explicitly pending |
| 4. Role/session access | T007–T011 | HTTP/session tests red, resolver/middleware/routes/views green; auth regressions | A01–A12, M04 and L15/L16 pass; L14 remains pending |
| 5. Ownership | T012–T015 | Policy/query tests red, ownership components green; nested/download tests red, response integration green | O01–O11 pass; missing/foreign equal; no foreign bytes |
| 6. Admin projection | T016–T018 | Allowlist/sentinel tests red, DTO/resource green | M01–M04 pass; no generic metadata or legacy content query |
| 7. Account service | T019–T021 | Transition/concurrency tests red, locked transaction service green | L03–L09 and L14 pass; rollback and concurrent actor reload proven |
| 8. Account HTTP/UI | T022–T023 | Endpoint tests red, request/controller/UI green | M05/M06/L06 and exact response contract pass |
| 9. Legacy closure | T024–T025 | Actual legacy-route tests red, service/guard/form integration green | L10/L12/L13/L18/L19 pass; enrolled credentials protected; student deletion preserved |
| 10. Final HTTP boundary | T026–T027 | Route inventory/real CSRF/cache tests, fix any missing wiring | O12/A13 pass; fixture routes absent at runtime |
| 11. Completion gate | T028–T030 | Execute runbook, inspect diff/routes, map every requirement/case | Mandatory checks complete or honest partial status |

## Rules while implementing

- Start with the next unchecked task and respect its prerequisites. If resuming a checked task, verify its artifacts and evidence before trusting the checkbox.
- A failing test must demonstrate the missing behavior or violated boundary. Syntax/bootstrap failures alone do not count as meaningful red evidence.
- When a new production type does not yet exist, write its tests first, then add only signatures or a deny-by-default skeleton needed to reach a behavioral assertion. Implement the behavior after observing that assertion fail; a skeleton is not a completed task.
- A baseline behavior that already passes needs regression coverage, not an artificial defect.
- Run focused tests after each story, then final relevant regressions. Rerun affected checks after subsequent fixes.
- Use acceptance IDs in test names and the report. Requirement-ID mentions in tasks do not prove actual coverage.
- Keep T005 incomplete until T021 proves L14 rollback through the real mutation service, even if recorder-only assertions pass.
- Do not implement future resource routes to satisfy fixture cases; follow `contracts/integration-obligations.md`.
- Reload the account mutation actor after obtaining the transaction lock. A stale User argument cannot authorize a suspended admin.

## Stop conditions and continuing independent work

If a missing dependency or baseline defect prevents execution, record the exact command/error and complete unaffected tasks. If a contract conflict requires a new business decision, identify both statements and ask only for that decision. Never weaken a policy, bypass middleware, change required test results or mark skipped checks complete.

NotebookLM/JEV/Temporal access is not required for 004; missing credentials for those systems are not a 004 blocker.

## Final completion decision

Declare complete only when T001–T030 are satisfied, every matrix case passes, related regressions introduce no new failures, production routes contain no fixtures, and the final diff stays within scope. Reproduced unrelated baseline failures may remain only with precise before/after evidence.

Otherwise declare partial or blocked and list exact unchecked task IDs and failed/unrun cases. Report implementation evidence instead of repeating the plan. Do not start 005, commit or deploy without a separate instruction.

## Required implementation report layout

1. Outcome: complete/partial/blocked and concrete delivered behavior.
2. Baseline: pre-existing changes, runtime/dependency versions and failing tests.
3. Changes: files/purpose and migration/backfill effects.
4. Evidence table: requirement, acceptance case, test name/command, result.
5. TDD record: meaningful initial failure and passing result per behavior group.
6. Security evidence: role/owner matrix, legacy bypass closure, real CSRF, concurrency and audit rollback.
7. Final checks: routes, focused/regression tests, formatting and diff check.
8. Outstanding task IDs, failed/unrun checks, limitations and downstream obligations.
