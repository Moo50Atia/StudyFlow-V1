# Direct Agent Execution Plan: 005

This is the implementation agent's controlling sequence. Read [tasks](tasks.md), [contract](contracts/settings.md), [data model](data-model.md), [acceptance matrix](contracts/acceptance-matrix.md) and [integration obligations](contracts/integration-obligations.md) before touching code. Do not regenerate a competing task plan.

## Entry conditions

1. 004 is actually implemented: `routes/v3.php`, V3 active-admin middleware/resolver, access audit, private/no-store response and 004 tests exist and pass or have a documented baseline failure. If not, stop 005 implementation and report the missing prerequisite; do not fabricate it in 005.
2. Record branch, `git status --short`, current 005 docs/application changes and installed PHP/composer state. Preserve preexisting user changes; if a target file has unrelated edits, inspect the diff and make a narrow compatible edit. Never reset/clean to get a baseline.
3. Run the [quickstart](quickstart.md) baseline commands. Record failures before adding tests. Distinguish existing failures from new ones. Do not broaden 005 to repair unrelated legacy email-verification/profile tests. The approved-template catalog starts empty; use test-only approved files for implementation tests and record the need for operator-provided production files.
4. Confirm database driver. Pest feature tests run in-memory SQLite; contention tests use an isolated temporary file DB with two independent workers and clean it after the test. Do not run migrations against production/local user data for this task.

## Group execution and checkpoints

| Group | Tasks | Before implementation | After implementation / checkpoint |
|---|---|---|---|
| A persistence/catalog | T001–T003 | Baseline auth/004 tests; write schema and trusted-import assertions first | `SettingsSchemaTest`, `ApprovedTemplateTest` green; empty singleton/catalog, restrictive FKs, no seeded version/body |
| B validation/boundary | T004–T005 | Write route/validation tests; add minimal 004-protected route skeleton, then observe a substantive validation/role assertion fail | `SettingsValidationTest`, `SettingsBoundaryTest` invalid-input and authorization cases green, including wrong template kind/digest; 004 denials/no-store/CSRF intact |
| C publication | T006–T009 | Write `PublicationTest`; record substantive atomicity/conflict failures; write real two-worker test after service exists | Publication plus concurrency green; exactly one version/event for a same-expected-version race; injected audit failure leaves pointer unchanged |
| D selection | T010–T011 | Write test-only consumer and selection/race tests; add minimal callable service skeleton to get substantive behavior failures | Capture/selection/template-byte tests green; no production ProcessingCase or provider implementation |
| E history/final | T012–T015 | Write history/privacy tests against real routes; existing current-view regressions may already pass | History/catalog privacy/route inventory plus all 005 tests green; report evidence and downstream handoffs |

Run the focused group test command after each group and run `php artisan test tests/Feature/V3/Settings tests/Integration/V3/Settings` at final checkpoint. If the installed PHPUnit/Pest runner does not accept two directories, run them as separate commands as shown in quickstart. A red phase is a failed business assertion; unresolved import/syntax/route-not-found alone is setup failure. Record any test already passing before its implementation as regression coverage, not a fabricated failure.

## Stop conditions and recovery

Stop dependent work and report if 004's real access boundary is absent, the intended 005 route cannot be protected, the DB driver cannot provide an atomic publication path, a provider API schema is being required to pass 005 tests, or a required test cannot exercise its claimed boundary. Continue independent documentation/report work. For an uncertain prior publish, reload the active version/history; do not retry with a guessed expected version. A failed migration/test transaction is diagnosed without destructive reset of user data. Do not substitute mocks for live access or two-worker race evidence. Credential values must not be dumped while debugging.

## Final completion gate and report

Complete only when T001–T015 conditions and S01–S15's 005-owned evidence pass, all 005 routes obey 004 authorization/CSRF/no-store rules, no later-spec production entities or V2/KOS/KIE code were added, and changed files pass focused format/diff checks. The implementation report at `specs/005-v3-versioned-settings/implementation-report.md` must list: starting workspace state; changed files and purpose; exact commands/results; meaningful red/green observations; acceptance ID → test result; two-worker race outcome; DB/HTTP/log/session privacy and template-body isolation evidence; baseline versus introduced failures; 004 prerequisite state; whether owner-approved production template files were imported; explicit unproved 006/007/010–013 obligations; remaining task IDs and complete/partial/blocked status. Never claim live provider/JEV/Temporal integration from fakes. Do not commit or deploy unless separately requested.
