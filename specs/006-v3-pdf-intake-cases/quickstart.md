# Future Implementer Verification: 006

These are commands and expected results **after implementation**, not results from this documentation review. Run from `/home/moahmmed/college_project` in a provisioned test environment with 004/005 implemented, a disposable database, private V3 disk, qpdf 11+ and running ClamAV daemon. Preserve existing workspace changes and record baseline failures first.

```sh
php -v
php artisan --version
qpdf --version
clamdscan --version
php artisan route:list --path=v3
php artisan schedule:list
```

Expect V3 upload/list/poll, Document download and case POST/GET under 004's web/active-teacher stack; a `v3:reconcile-intake` one-minute schedule; compatible qpdf and ClamAV versions. The reviewed environment lacks qpdf/ClamAV, so their absence must be reported as a blocked live gate, never treated as acceptance success.

```sh
php artisan migrate:status
php artisan test tests/Feature/V3/Intake/SchemaOwnershipTest.php
php artisan test tests/Feature/V3/Intake/UploadRouteTest.php tests/Feature/V3/Intake/PrivateStorageTest.php tests/Feature/V3/Intake/TeacherIntakeUiTest.php
php artisan test tests/Feature/V3/Intake/PdfToolIntegrationTest.php
php artisan test tests/Feature/V3/Intake/ValidationRecoveryTest.php
php artisan test tests/Feature/V3/Intake/DuplicateTest.php tests/Feature/V3/Intake/IntakeConcurrencyTest.php
php artisan test tests/Feature/V3/Intake/CaseStartTest.php tests/Feature/V3/Intake/CaseConcurrencyTest.php
php artisan test tests/Feature/V3/Intake/AccessBoundaryTest.php tests/Feature/V3/Intake/PrivacyTest.php tests/Feature/V3/Intake/IntegrationContractTest.php
php artisan test tests/Feature/V3/Intake
php artisan test tests/Feature/V3/Access tests/Feature/V3/Settings tests/Integration/V3/Settings
```

Expect all 006 tests green. `PdfToolIntegrationTest.php` must state a separate live clean/infected/outage result. `IntakeConcurrencyTest.php` and `CaseConcurrencyTest.php` must use overlapping independent processes against a shared database. The last command runs planned 004/005 access, settings and selection-concurrency regressions after those prerequisites are implemented; report exact outcomes. A tool skip or unavailable shared DB is an explicit incomplete gate.

Run on disposable staged attempts only:

```sh
php artisan v3:reconcile-intake
php artisan v3:reconcile-intake
php artisan queue:work v3_intake --queue=v3_intake --timeout=270 --tries=1 --stop-when-empty
```

Expect two reconciler runs to be idempotent; due attempts finish or remain safely retrying, no pending Document becomes downloadable, expired quarantine and unreferenced stale files are purged. Worker processes complete clean accepted attempts only after both PDF and malware gates. Inspect `storage/app/v3` and the three V3 tables: accepted files use opaque Document paths and match stored size/hash; rejected/blocked attempts retain no bytes; no V3 file appears under `public/storage` or on legacy lecture routes. Use a disposable DB/disk rather than production files.

Finally check teacher/admin/foreign-owner HTTP outcomes from the tests: own ready download 200/private/no-store; foreign/missing 404 equal safe bodies; admin/inactive 403; pending own Document 409; same case key replay 200 same case/settings; newly created case 201 `awaiting_workflow`. This proves 006's handoff only. Temporal, NotebookLM, JEV, and final review require later specs' evidence.
