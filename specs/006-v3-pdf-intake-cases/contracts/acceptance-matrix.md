# 006 Acceptance Matrix

IDs I01–I18 are stable scenarios in [spec.md](../spec.md). Test paths are planned executable evidence for the future implementer; they have not been run in this documentation review. Tool doubles isolate decisions, while live-command checks must use qpdf and a running ClamAV daemon.

| Scenario | Requirements | Planned test file(s) | Expected observable result |
|---|---|---|---|
| I01 | FR-001,002,003 | `tests/Feature/V3/Intake/UploadRouteTest.php`, `tests/Feature/V3/Intake/TeacherIntakeUiTest.php`, `PdfToolIntegrationTest.php` | JSON 202/browser 303 to own detail, then one ready private Document/accepted attempt; no case |
| I02 | FR-001,002 | `tests/Feature/V3/Intake/PdfToolIntegrationTest.php`, `UploadRouteTest.php` | Determinate invalid fixtures rejected; no ready row or bytes |
| I03 | FR-001,008 | `tests/Feature/V3/Intake/ValidationRecoveryTest.php`, `PdfToolIntegrationTest.php` | Malware rejected; outage stays isolated then blocks/purges; live scanner check distinct from fake |
| I04 | FR-001 | `tests/Feature/V3/Intake/PdfToolIntegrationTest.php` | Valid image-only PDF accepted; no 006 text requirement |
| I05 | FR-002 | `tests/Feature/V3/Intake/UploadRouteTest.php`, `tests/Feature/V3/Intake/TeacherIntakeUiTest.php` | Controller-level JSON errors have attempt ID; browser 303 detail shows safe state; upstream limit documented |
| I06 | FR-002,008 | `tests/Feature/V3/Intake/ValidationRecoveryTest.php` | Crash points reconcile to verified ready or blocked/no bytes; no pending access |
| I07 | FR-003,004 | `tests/Feature/V3/Intake/DuplicateTest.php`, `tests/Feature/V3/Intake/TeacherIntakeUiTest.php` | Same owner exact bytes reuse row; one-byte change new row; detail offers latest own case/open or process-again |
| I08 | FR-003,004,007 | `tests/Feature/V3/Intake/DuplicateTest.php`, `AccessBoundaryTest.php` | Cross-owner same bytes separate row and equal foreign/missing responses |
| I09 | FR-003,004 | `tests/Feature/V3/Intake/IntakeConcurrencyTest.php` | Separate DB processes converge on one same-owner row; cross-owner stays separate |
| I10 | FR-005 | `tests/Feature/V3/Intake/CaseStartTest.php` | Old Document above current limits gets 409/no new case; missing/corrupt accepted bytes get 503/no case |
| I11 | FR-005,009 | `tests/Feature/V3/Intake/CaseStartTest.php`, `tests/Feature/V3/Intake/TeacherIntakeUiTest.php` | First process form commits owner/Document/settings and awaiting_workflow receipt only |
| I12 | FR-006 | `tests/Feature/V3/Intake/CaseStartTest.php` | Same key replay returns original version; conflicting use gets 409 |
| I13 | FR-006 | `tests/Feature/V3/Intake/CaseConcurrencyTest.php` | Actual concurrent starts serialize; process_again different keys yield two runs |
| I14 | FR-005,007 | `tests/Feature/V3/Intake/AccessBoundaryTest.php`, `CaseStartTest.php` | Real middleware/policy denies role/foreign/extra fields/pending; own GET works |
| I15 | FR-005 | `tests/Feature/V3/Intake/CaseStartTest.php`, `CaseConcurrencyTest.php` | No/corrupt 005 version fails; publisher race commits one complete version |
| I16 | FR-009 | `tests/Feature/V3/Intake/CaseStartTest.php`, `IntegrationContractTest.php` | Durable discoverable case, no 006 Temporal start or duplicate replacement |
| I17 | FR-007 | `tests/Feature/V3/Intake/AccessBoundaryTest.php`, `PrivateStorageTest.php` | Own private stream works; other roles/legacy/public/signed paths fail |
| I18 | FR-002,008,010 | `tests/Feature/V3/Intake/ValidationRecoveryTest.php`, `PrivacyTest.php` | 24h cleanup or inaccessible overdue cleanup with fixed alert, retained accepted file/rows, allowlisted response/log/audit data |

Every FR maps to at least one scenario and every scenario to a test. `PdfToolIntegrationTest` includes real qpdf fixtures and a live ClamAV clean/infected/outage check in a provisioned environment; it must report an explicit skipped environmental gate when the daemon is absent and cannot by itself be replaced by a fake scanner. Concurrency tests use two independent processes/connections to a shared file-backed SQLite with `PRAGMA busy_timeout=5000` on both connections or a production-equivalent test database, not sequential calls or in-memory SQLite. `IntegrationContractTest` tests 006's handoff shape with an in-test 007 consumer fixture; it does not prove a live Temporal workflow.

## Requirement-to-task trace

| Requirement | Implementation/test tasks |
|---|---|
| FR-001 | T005–T008, T021 |
| FR-002 | T002, T005–T006, T009–T010, T021 |
| FR-003 | T002–T004, T011–T014, T021 |
| FR-004 | T011–T014, T021 |
| FR-005 | T002–T004, T015–T018, T021 |
| FR-006 | T015–T018, T021 |
| FR-007 | T003–T006, T019–T021 |
| FR-008 | T007–T010, T013–T014, T021 |
| FR-009 | T015–T016, T019–T021 |
| FR-010 | T009–T010, T019–T021 |
