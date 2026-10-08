# Direct Implementation Execution Plan: 014

This is the plan the future implementation agent follows. Use [T001–T018](tasks.md), the [contract](contracts/admin-operations.md), [model](data-model.md) and [O01–O24](contracts/acceptance-matrix.md). This documentation review ran no application code or tests.

## Entry and baseline

004–013 must actually exist and pass their own mandatory gates. Verify 004 active-admin/owner middleware, `ChangeAccountAccess`, ClosedSummary and V2 `/users` guard; 005 captured policy; 006 case; 007 fixed workflow ID/event stream/HMAC bridge; 008–012 effect ledgers; and 013 per-Section review/outbox/workspace. Map every planned 014 extension point to the delivered class/route and record differences. A missing required contract is a blocker, not license to add a fake prerequisite model. Require a shared transactional test DB for two-process races and a real Temporal test/deployment worker plus signed bridge for the final gate. Record `git status --short`, `git diff --name-only`, versions, route/migration inventory, `php artisan test` and `python3 -m pytest v3_engine/tests` before edits. Preserve unrelated work; do not reset, overwrite, commit or deploy. Reproduced unrelated baseline failures are documented with before/after evidence.

## Ordered groups and checkpoints

| Group | Tasks | Run before and after the group | Checkpoint |
|---|---|---|---|
| 0 Baseline | T001 | Baseline PHP/Python suites and route inventory if prerequisites exist | Required 004–013 services/worker/DB identified or dependent work stops with exact mismatch. |
| 1 Read projection | T002–T003 | `php artisan test tests/Feature/V3/Admin/AdminOperationsReadTest.php` | Fixed 004 wrapper, filters/order/20-page, real owner/V2 denials and no secret-bearing response/log. |
| 2 Health | T004–T005 | `php artisan test tests/Feature/V3/Admin/AdminHealthTest.php` | Real bounded signed Temporal probe distinct from backlog and provider unknown. |
| 3 Command ledger | T006–T007 | `php artisan test tests/Feature/V3/Admin/AdminCommandTest.php tests/Feature/V3/Admin/AdminControlIntegrationTest.php` | Atomic audit/fence/command, strict reason/version/idempotency, no bridge before commit. |
| 4 Fence/workflow | T008–T009 | `php artisan test tests/Feature/V3/Admin/AdminCommandRaceTest.php tests/Feature/V3/Admin/AdminControlIntegrationTest.php`; `python3 -m pytest v3_engine/tests/admin/test_admin_control_workflow.py` | Two-process race, stage claims fenced, late evidence safe, one signed cancellation proof/event, replay-compatible 007/013 worker. |
| 5 Retry | T010–T011 | `php artisan test tests/Feature/V3/Admin/AdminRetryTest.php` | Six same-operation targets use original ledgers; terminal/ambiguous sends never reissued. |
| 6 Reminder | T012–T013 | `php artisan test tests/Feature/V3/Admin/AdminReminderTest.php` | One current dormant alert, same original owner, no messaging/content path. |
| 7 Account regression | T014 | `php artisan test tests/Feature/V3/Admin/AdminAccountRegressionTest.php tests/Feature/V3/Access` | Real 004/V2 account guards still hold; no 014 account writer. |
| 8 Retention assessment | T015–T016 | `php artisan test tests/Feature/V3/Admin/AdminDeletionAssessmentTest.php` | Fixed policy/snapshot, no file/row deletion, ineligible/duplicate safe. |
| 9 Live and report | T017–T018 | [quickstart](quickstart.md), full prerequisite regressions, live bridge/Temporal worker, shared-DB race | O01–O24 evidence and implementation report or explicit partial/blocked outcome. |

For each red/green pair, author tests first. At the start of the green task add the **final** route/table/class skeleton needed to execute them, record a failed behavioral assertion, then implement until green. A missing class, migration, syntax, container or credential is setup failure, not meaningful red. Existing 004 behavior receives regression coverage. Security tests traverse the actual web/API middleware, CSRF, V2 alternatives and 013 owner routes. Races require separate processes/connections on one shared transactional DB, not SQLite `:memory:`. Fixture bridge/worker tests verify local logic; they do not prove the live gate.

## Stop and recovery rules

Stop dependent work if a delivered prerequisite cannot provide an original-owner review fence, fixed 007 workflow identity, authenticated callback or same-effect ledger. Do not weaken 004's closed DTO, change 005 active settings for an old case, mint a replacement terminal workflow, repeat an uncertain NotebookLM or text-generation send, or infer a typed JEV result. If a hold/cancel bridge response is lost, leave the local fence active and query the same command/version; if Temporal is unavailable, report uncertain rather than applied. A known late provider result may be retained privately through its original ledger, but cannot trigger a new claim after cancellation. If a real Temporal worker, shared DB or signed bridge is unavailable at T017, complete independent tests and report the mandatory gate as unrun; do not label the feature complete. A 014 deletion assessment never authorizes physical deletion.

## Final completion and report

Completion requires T001–T018 deliverables, O01–O24 executable results, real admin/teacher/V2 boundary tests, two-process race, real bounded Temporal signal/callback/cancellation gate, prerequisite regressions and proof that admin never receives teacher content. The live test may use a controlled bounded activity to exercise Temporal cancellation; label that evidence as workflow/guard proof, not a live provider call. The future `implementation-report.md` must include outcome complete/partial/blocked; baseline/workspace changes; exact changed files/migrations; command, test and exit results with skips; meaningful red→green; all O-case outcomes; actual actor denial/CSRF/no-store/admin DTO and log inspection; case/control/command IDs, versions and digests without bodies; two-process race order; lost-ack and late-result handling; live versus fixture Temporal and provider evidence; timings/cost if known; unresolved blockers; and 015 retention/export obligations. No skipped mandatory gate is reported as passing.
