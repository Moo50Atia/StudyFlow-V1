# Agent Task Execution Plan: 007

This is the direct implementation plan. Follow [tasks.md](tasks.md) T001–T021 in order, using [workflow contract](contracts/workflow.md), [data model](data-model.md) and [acceptance matrix](contracts/acceptance-matrix.md). Do not substitute a new feature plan or claim that 008–013 work is live from synthetic fixtures.

## Entry and baseline

1. Confirm 004, 005 and 006 are implemented and their required suites pass: real `routes/v3.php` access middleware/policies, `SettingsVersionRepository::getCaptured`, 006 ready private Document/ProcessingCase with `awaiting_workflow`/version 1. If absent, stop dependent 007 runtime work and record the missing contract; do not build production substitutes.
2. Record `git status --short` and preserve every pre-existing change. Record `php -v`, `python3 --version`, `php artisan --version`, Temporal CLI/namespace reachability, current route list, queue/schedule configuration and exact 004–006 baseline commands/results. A baseline failure predating 007 stays attributed to its owner; continue only independent work.
3. Provision a disposable DB, local Temporal dev server with at least 30-day namespace retention for reconciliation tests, Python environment/locked dependencies, private bridge/callback keys, Laravel queue worker and scheduler. Set both internal endpoints to loopback/private network; if crossing hosts, use TLS. Never run smoke tests on production cases.

## Ordered red/green groups

| Group | Tasks | Before implementation | After/checkpoint |
|---|---|---|---|
| Foundation | T001–T004 | T002 makes schema/package runnable; T003 shows constraint/immutability red | Run `WorkflowSchemaTest.php` and migrations against SQLite plus production-equivalent DB; check exact FK/unique keys |
| Callback contract | T005–T006 | Create signed route skeleton, then run `WorkerBoundaryTest.php`/`EventProjectionTest.php` for substantive red | Run same tests; signed valid event atomically updates case/link/event, bad signature never looks up case |
| Start discovery | T007–T008 | Run start and independent-process concurrency tests; observe wrong identity/recovery behavior | Run `WorkflowStartTest.php` and repeated `WorkflowStartConcurrencyTest.php`; inspect link-before-HTTP and worker/scheduler settings |
| Python bridge | T009–T010 | Run `test_start_bridge.py` against a provisioned Temporal test server, not a missing SDK | Run it green; inspect one fixed Temporal ID, memo, closed-run non-reuse and no content payload |
| Event races | T011–T012 | Run real overlapping event writers and callback-before-ack cases | Run `EventProjectionTest.php`, `EventConcurrencyTest.php`; verify one event/version and exact 409 on loser |
| User progress | T013–T014 | Add minimally protected route skeleton then run real 004 teacher/admin assertions | Run `ProgressAccessTest.php`, `PrivacyTest.php`; inspect HTML/JSON exact fields, no-store and equal foreign/missing responses |
| Security/handoff | T015–T016 | Run route/worker inventory and bypass assertions | Run `WorkerBoundaryTest.php`, `PrivacyTest.php`, `HandoffContractTest.php`; no review/content route or fake activity |
| Live retry/recovery | T017–T018 | Run Python and PHP live bridge tests with worker pause/kill and lost acknowledgements; record behavioral red | Run `test_retry_policy.py`, `test_case_workflow.py`, `LiveBridgeTest.php`; compare Temporal describe/history with case/link/events |
| Review primitive | T019–T020 | Run time-skipped test-only gate with stale/duplicate signals | Run `test_review_gate.py`; wait survives restart, no activity worker held, no auto-approval |
| Final | T021 | Re-run known baseline failures independently | Run all [quickstart](quickstart.md) commands, 004–007 affected suites, live smoke and inventory; write implementation report |

A missing import, class, route, service, Temporal CLI or signing key is not meaningful red; fix harness/provisioning or mark gate blocked. Existing passing 004–006 behavior gets regression coverage rather than a manufactured failure. Concurrency tests require two independent processes/connections and real overlap against shared file-backed SQLite (`PRAGMA busy_timeout=5000` on both) or production-equivalent DB. Test doubles cannot prove a live bridge, worker restart or Temporal history.

## Stop conditions and recovery

- If a start response is uncertain, persist/keep `uncertain` and describe the deterministic ID. Never infer not-started from timeout, create a new case/ID or change captured settings. A mismatched memo/run or expired Temporal history is an operator stop with fixed safe alert.
- If a callback is rejected as stale/conflicting, do not overwrite Laravel projection. Exact duplicate returns the original version. If DB event/case commit fails, roll back both and retry same event key.
- If owner access changes, 004 denies teacher HTTP immediately. An inactive owner before first remote start is deferred until restoration. 014/013 later govern pause/cancel and review actions; do not invent approval or transfer ownership.
- If a live Temporal server/worker, private bridge, credentials, queue worker or scheduler cannot run, continue isolated tests/docs but stop short of claiming SC-001/SC-003 live proof. If real progress/admin privacy fails, stop rollout and capture only safe IDs/codes.
- If later feature-specific stage preconditions are absent, keep the live case queued at extraction. Do not add fake extraction, mapping, JEV or scene services to get a green test.

## Completion and required report

Complete 007 only when W01–W20 have meaningful evidence appropriate to their proof limit; real 004 owner/admin routes and signed callback are tested; independent-process races and live Temporal/bridge crash/uncertain-start tests pass; retry settings come from the captured 005 row; a live case remains honestly queued for 008; all affected 004–006 regressions pass. The synthetic review gate is complete as a primitive, but no live teacher review claim is made until 013.

Write an implementation report listing task IDs/files, pre-existing workspace state, baseline and final commands/results, red/green checkpoints, real versus doubled service evidence, concurrent test setup, Temporal namespace/SDK/retention and bridge/queue/scheduler checks, state/DB/history comparison, privacy checks, deviations, unresolved blockers and handoff obligations for 008–015. Do not commit or deploy without separate direction.
