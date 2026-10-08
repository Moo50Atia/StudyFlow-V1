# Agent Task Execution Plan: 006

This is the implementation agent's direct plan. Follow [tasks.md](tasks.md) IDs in order and use [intake contract](contracts/intake.md), [data model](data-model.md) and [acceptance matrix](contracts/acceptance-matrix.md) as the behavior source. Do not infer a workflow, NotebookLM or scene API from 006.

## Entry conditions and baseline

1. Confirm 004 and 005 are implemented, with `routes/v3.php`, active-teacher policy/owner service, `SelectCaseSettings`, immutable settings table and passing required 004/005 tests. If absent, stop dependent runtime work and report exactly what is missing; do not insert production substitutes.
2. Run `git status --short` and record pre-existing changes. Preserve all unrelated edits; never reset the workspace to make tests green. Record `php -v`, `php artisan --version`, `qpdf --version`, `clamdscan --version`, `php artisan route:list --path=v3` and existing 004/005 baseline test results. Tool absence is a deployment/test gate, not permission to accept unscanned PDFs.
3. Verify writable private storage, configured queue database/worker and scheduler, and PHP/web body ceilings above 100 MiB plus multipart overhead. Do not run an intake worker against production data while developing.

## Ordered groups and checkpoints

| Group | Tasks | Before code | After code/checkpoint |
|---|---|---|---|
| Foundation | T001–T004 | Run 004/005 baselines; T002 creates runnable harness; run T003 to show actual constraint/ownership red | Run `SchemaOwnershipTest.php` and migration on SQLite plus production-equivalent DB; verify owner/FK/unique guards |
| Private intake | T005–T006 | Run T005 and record business assertion failures | Run `UploadRouteTest.php` and `PrivateStorageTest.php`; inspect V3 route list, disk config and a staged file location |
| PDF gates | T007–T008 | Run real qpdf/ClamAV fixture tests where provisioned, record specific red/blocked result | Run `PdfToolIntegrationTest.php`; valid clean passes, malformed/encrypted/active/infected/outage fail closed; no fake scanner claimed live |
| Recovery | T009–T010 | Run crash/retry tests on private disk and DB before coordinator/job code | Run `ValidationRecoveryTest.php`; stop worker mid-promotion and run reconciler twice; verify terminal/no-byte invariants and scheduler entry |
| Duplicates | T011–T014 | Run duplicate assertions, then separate-process contention red | Run `DuplicateTest.php` and repeated `IntakeConcurrencyTest.php`; inspect one same-owner row and separate cross-owner files |
| Cases | T015–T018 | Run first/again/settings tests, then separate-process publication/start race red | Run `CaseStartTest.php` and repeated `CaseConcurrencyTest.php`; inspect owner/version/idempotency uniqueness and `awaiting_workflow` only |
| Security/handoff | T019–T020 | Run real HTTP boundary/privacy and in-test downstream consumer tests | Run `AccessBoundaryTest.php`, `PrivacyTest.php`, `IntegrationContractTest.php`; verify no legacy/public/admin bypass |
| Final | T021 | Re-run any known baseline failures independently to determine provenance | Run all [quickstart](quickstart.md) commands and full affected 004/005/006 suite; record live tool/worker/scheduler results and file/DB evidence |

Keep red and green outputs for each behavior group. A missing import, syntax error, missing table/class or unconfigured daemon is not meaningful red evidence; repair harness/prerequisite first or mark the gate blocked. Existing passing 004/005 paths need regression assertions, not fabricated red. Run concurrency tests with actual overlapping independent processes and shared database; sequential requests and SQLite `:memory:` do not satisfy I09/I13/I15.

## Failure handling and stop conditions

- On baseline failure, capture exact command/output and whether it predates 006. Continue independent 006 documentation/test work, but do not attribute the failure to 006 or claim a clean gate.
- On qpdf/ClamAV absence, inability to run a daemon, incompatible qpdf JSON schema, unsafe PDF profile gap, inaccessible private disk, or worker/scheduler unavailable, keep intake fail closed. Stop any claim of successful live acceptance until the provisioned gate passes.
- On 004/005 contract mismatch, resolve with owner and prerequisite implementer. Do not edit numbered prerequisite docs or work around them by creating an alternative access/settings service within 006.
- On unexplained ownership leak, hash/path disclosure, pending file delivery, inconsistent move/DB state, or case with wrong settings, stop rollout and preserve a safe diagnostic trace without bytes/filenames/tool text.
- If an acceptance case needs a new business choice that the specification cannot settle, report exact question and impact; do not silently relax it.

## Final completion and report

Completion requires every I01–I18 executable case to have meaningful evidence; all affected 004/005 regressions pass; live qpdf and ClamAV clean/infected/outage gate passes in a provisioned environment; race tests show real overlap; private storage, route inventory, queue worker and scheduled recovery are checked; no production future-feature placeholders are present. 006 proves only the committed case handoff, not live Temporal or 008–015 integrations.

Create an implementation report with task IDs and files changed, baseline and final commands/results, red/green evidence by group, live versus doubled tool evidence, concurrency setup/observations, migration/route/storage/worker checks, remaining risks/blockers, deviations from this contract and downstream 007/008 handoff facts. Do not commit or deploy unless separately directed.
