# 007 Acceptance Matrix and Proof Boundaries

W01–W20 are stable scenarios in [spec.md](../spec.md). Paths below are future implementation tests, not tests run in this documentation review. Live Temporal/bridge evidence is separate from fake HTTP and test-only review-candidate fixtures.

| Case | Requirements | Planned test file(s) | Expected outcome |
|---|---|---|---|
| W01 | FR-001,002 | `tests/Feature/V3/Workflow/WorkflowStartTest.php`, `tests/Integration/V3/Workflow/WorkflowStartConcurrencyTest.php`, `v3_engine/tests/workflow/test_start_bridge.py` | One link/Temporal ID/case under duplicate and concurrent discovery |
| W02 | FR-001,006 | `tests/Feature/V3/Workflow/WorkflowStartTest.php`, `tests/Integration/V3/Workflow/LiveBridgeTest.php` | Lost ack adopts matching run; verified not-found retries fixed ID; mismatch stops |
| W03 | FR-001,006 | `tests/Feature/V3/Workflow/WorkflowStartTest.php`, `tests/Feature/V3/Workflow/ProgressAccessTest.php` | Unavailable stays visible/delayed; fixed alert at 24h; same case later recovers |
| W04 | FR-002,004 | `tests/Feature/V3/Workflow/EventProjectionTest.php`, `tests/Integration/V3/Workflow/LiveBridgeTest.php` | Real callback commits one sequence-2 event/queued case; late ack no duplicate |
| W05 | FR-001,003 | `tests/Feature/V3/Workflow/WorkflowStartTest.php`, `tests/Feature/V3/Workflow/ProgressAccessTest.php` | Suspended owner defers start; restore resumes same identity; HTTP denied while inactive |
| W06 | FR-002,003 | `tests/Feature/V3/Workflow/ProgressAccessTest.php` | Own ordered progress; missing/foreign equal 404; wrong role denied |
| W07 | FR-003,008 | `tests/Feature/V3/Workflow/ProgressAccessTest.php`, `tests/Feature/V3/Workflow/PrivacyTest.php` | Admin exact closed DTO, safe list/detail; no content sentinel |
| W08 | FR-002,004 | `tests/Feature/V3/Workflow/EventProjectionTest.php` | Atomic case/event; exact replay 200; changed key payload/stale/unknown/wrong run no mutation |
| W09 | FR-002,004 | `tests/Integration/V3/Workflow/EventConcurrencyTest.php`, `tests/Feature/V3/Workflow/ProgressAccessTest.php` | Two independent writers overlap; one commit/one conflict; stable owner pagination |
| W10 | FR-003,004,008 | `tests/Feature/V3/Workflow/WorkerBoundaryTest.php`, `tests/Feature/V3/Workflow/PrivacyTest.php` | Bad/missing/stale signature fails before lookup; no web/admin/legacy bypass |
| W11 | FR-005 | `v3_engine/tests/workflow/test_retry_policy.py`, `tests/Feature/V3/Workflow/WorkflowStartTest.php` | Laravel-verified captured version fields drive Python max attempts/base delay after active version changes |
| W12 | FR-005,006 | `v3_engine/tests/workflow/test_case_workflow.py`, `tests/Integration/V3/Workflow/LiveBridgeTest.php` | Retryable resumes, policy error no retry, exhaustion safe failed; offline worker delayed |
| W13 | FR-007 | `v3_engine/tests/workflow/test_review_gate.py` | One candidate/version/decision; duplicate no-op; stale/conflict does not advance |
| W14 | FR-007,008 | `tests/Feature/V3/Workflow/WorkerBoundaryTest.php`, `v3_engine/tests/workflow/test_review_gate.py` | No 007 public review route/model; fixture proves gate only |
| W15 | FR-007 | `v3_engine/tests/workflow/test_review_gate.py` | Time-skipped long wait/restart retains candidate; no auto-approval |
| W16 | FR-004,006 | `tests/Feature/V3/Workflow/EventProjectionTest.php`, `tests/Integration/V3/Workflow/LiveBridgeTest.php` | Lost callback ack dedups; verified closed failure one terminal event, no restart |
| W17 | FR-006,007 | `tests/Feature/V3/Workflow/HandoffContractTest.php`, `v3_engine/tests/workflow/test_case_workflow.py` | Real start remains queued for 008; no fake content activity/production fixture |
| W18 | FR-003,008 | `tests/Feature/V3/Workflow/PrivacyTest.php`, `tests/Feature/V3/Workflow/WorkflowSchemaTest.php` | Safe retained rows/logs, no content/secret fields or delete route |
| W19 | FR-001,005,006 | `tests/Feature/V3/Workflow/WorkflowStartTest.php` | Missing/corrupt captured row prevents bridge POST, leaves pending case/link and fixed alert; trusted restoration of exact row starts same case with stored version |
| W20 | FR-001,006 | `tests/Feature/V3/Workflow/WorkflowStartTest.php`, `tests/Integration/V3/Workflow/LiveBridgeTest.php` | Verified not-found after 30 days stops automatic start with alert; Temporal 503 never triggers not-found path |

## Requirement-to-task trace

| Requirement | Planned tasks |
|---|---|
| FR-001 | T001–T004, T007–T010, T021 |
| FR-002 | T003–T006, T011–T012, T021 |
| FR-003 | T013–T016, T021 |
| FR-004 | T005–T006, T011–T012, T015–T016, T021 |
| FR-005 | T017–T018, T021 |
| FR-006 | T007–T012, T017–T018, T021 |
| FR-007 | T019–T020, T021 |
| FR-008 | T005–T006, T013–T016, T019–T021 |

`LiveBridgeTest.php` starts a provisioned Temporal dev server/worker/Python bridge and Laravel callback server against disposable DB/keys; a fake bridge does not satisfy W02/W04/W12/W16. `WorkflowStartConcurrencyTest.php` and `EventConcurrencyTest.php` use independent processes/connections, not sequential requests or SQLite `:memory:`. `test_review_gate.py` can use Temporal time skipping and test-only candidate signals; it does not prove 012/013 live integration or actual teacher authorization.
