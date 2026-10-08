# Future Implementer Verification: 005

These commands and expected results are instructions for the later implementation agent. They were **not run** in this documentation-only review.

From repository root, before edits:

```sh
git status --short
php -v
composer --version
php artisan route:list --path=v3
php artisan test tests/Feature/Auth/AuthenticationTest.php tests/Feature/ProfileTest.php
```

Record 004 route availability and any preexisting failures. `route:list` must show 004 V3 routes before 005 implementation. If the installed test runner rejects multiple file arguments, run each separately. Do not infer 004 completion merely from its spec files.

After each task group, run the named test file(s) in [task execution plan](task-execution-plan.md). Final focused commands:

```sh
php artisan test tests/Feature/V3/Settings
php artisan test tests/Integration/V3/Settings
php artisan route:list --path=v3
php artisan test tests/Feature/V3/Access
vendor/bin/pint --test

git diff --check
```

If Pint's check output includes unrelated preexisting files, rerun it on changed PHP paths and report the distinction. The 004 suite path is expected only after 004 is implemented. Do not claim production provider or JEV verification from these commands.

Expected observable results: test-only approved feedback/scene files import with matching SHA-256; mismatch and duplicate-ID replacement fail; no body is returned by catalog/admin routes. A valid first publish selecting those test IDs returns version 1; valid replacement returns version 2 and preserves version 1; invalid template selection or stale submission leaves row/event count and pointer unchanged; two real concurrent publishes with the same expected version produce one winner; no-active selection fails; a test-only consumer keeps A and its exact selected template bytes after B/newer template import, while new selection gets B; admin history exposes IDs/times/checksum/paths only; guest/teacher/inactive actor denied under 004; no credential sentinel in DB/HTTP/log/session old input; all 005 responses have private/no-store; no 005 edit/delete, teacher or legacy alias route. 006 must later run its own real case tests before claiming case behavior.

For live first publication, a trusted operator must run `php artisan v3:register-approved-template feedback <approved-id> <absolute-approved-file> <sha256>` and the corresponding `scene` command using owner-approved files and their exact SHA-256 digests. Those file paths/digests are operational inputs not supplied in this review; do not use the unapproved repository candidate as a default. The test suite uses its own temporary fixture files and does not prove owner approval of live content.
