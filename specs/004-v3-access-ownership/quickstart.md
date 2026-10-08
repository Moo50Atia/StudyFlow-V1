# Verification Runbook: 004

This file is for the implementation agent after it writes code. The documentation review did not run application tests or migrations. Use synthetic data and isolated test databases only.

## 1. Establish baseline before implementation (T001)

Run from `/home/moahmmed/college_project`:

```bash
pwd
git status --short
php --version
composer show --locked laravel/framework
composer show --locked pestphp/pest
php artisan test tests/Feature/Auth
php artisan test tests/Feature/ProfileTest.php
php artisan test tests/Feature/SectionNavigationTest.php
```

Record each command, exit code and failing test names in the future `implementation-report.md`. Existing email-verification/profile assumptions may already fail because the users migration/factory lacks the corresponding fields/state. Establish the actual baseline; do not pre-label failures without running them. Preserve existing changes in package files and other documents.

If PHP/dependencies are unavailable, record the exact missing prerequisite. Complete independent implementation work, but do not mark executable checks passed. Do not run `composer update` or overwrite lockfiles merely to install existing dependencies.

## 2. Story-focused red/green commands

Before each related production change, run its file or a named acceptance ID and record an intended assertion failure. Existing behavior may already be green; retain its regression coverage.

```bash
php artisan test tests/Feature/V3/Access/AccessSchemaTest.php
php artisan test tests/Feature/V3/Access/AccessAuditTest.php
php artisan test tests/Feature/V3/Access/RoleAccessTest.php
php artisan test tests/Feature/V3/Access/SessionAccessTest.php
php artisan test tests/Feature/V3/Access/AuthCompatibilityTest.php
php artisan test tests/Feature/V3/Access/OwnershipPolicyTest.php
php artisan test tests/Feature/V3/Access/BoundaryHttpTest.php
php artisan test tests/Feature/V3/Access/AdminProjectionTest.php
php artisan test tests/Feature/V3/Access/AccountLifecycleTest.php
php artisan test tests/Integration/V3/Access/AccountConcurrencyTest.php
php artisan test tests/Feature/V3/Access/AccountEndpointTest.php
php artisan test tests/Feature/V3/Access/LegacyAccountBoundaryTest.php
php artisan test tests/Feature/V3/Access/RouteBoundaryTest.php
```

L14 is intentionally introduced with audit tests and fulfilled when the account transaction service exists. Do not hide that pending case or claim its test task fully passed until then.

## 3. Isolation requirements

- Feature tests inherit `RefreshDatabase` and SQLite memory from `tests/Pest.php`/`phpunit.xml`.
- Migration/backfill tests create synthetic users before invoking the target migration in an isolated database, then check bootstrap rows/events and rollback safety. Never roll back the developer's actual database.
- Concurrency tests use their own temporary SQLite file and independent processes/connections. They must wait for both workers, assert outcomes and invariant, then clean up the temp file. Do not wrap these in the feature suite's shared in-memory transaction. Cap worker wait at 10 seconds and fail clearly on deadlock/timeout.
- CSRF test must enable actual token validation through a test-only middleware subclass; a normal Pest request that bypasses CSRF cannot prove A13.
- Fixtures register routes/models only during tests. File download fixtures contain harmless text bytes; no teacher source or scene is used.

## 4. Final feature gate (T028–T029)

```bash
php artisan test tests/Feature/V3/Access
php artisan test tests/Integration/V3/Access
php artisan test tests/Feature/Auth
php artisan test tests/Feature/ProfileTest.php
php artisan test tests/Feature/SectionNavigationTest.php
php artisan route:list --path=v3 -v
git diff --check
```

Expected route inventory: exactly the five 004 V3 routes in `contracts/access.md` at this stage; no test routes, case/document/scene routes or `teacher.access` middleware. Middleware order must include the web session/CSRF stack and V3 gates. Inspect the existing account routes too, since their controllers must invoke the common access service/retention guard.

Run Pint in check mode on all changed PHP files. For the new feature directories:

```bash
vendor/bin/pint --test app/Contracts/V3 app/Data/V3 app/Models/V3 app/Models/Concerns/HasV3Owner.php app/Policies/V3 app/Services/V3/Access app/Http/Middleware/V3 app/Http/Controllers/V3 app/Http/Requests/V3 app/Console/Commands/V3 routes/v3.php tests/Feature/V3/Access tests/Support/V3 tests/Integration/V3/Access
vendor/bin/pint --test app/Http/Controllers/UserController.php app/Http/Controllers/ProfileController.php app/Http/Requests/UserRequest.php bootstrap/app.php routes/web.php
```

If unrelated earlier PHP formatting problems exist, identify them separately; do not reformat the whole repository. Add any additionally changed PHP file to the checked set.

## 5. Demonstrable outcomes

1. Guest opens `/v3`, signs in with a synthetic provisioned teacher and reaches teacher workspace; admin reaches admin workspace.
2. Admin suspends teacher from account list; that teacher's already-open session is denied on its next request. Restoration permits access; ownership fixture stays unchanged.
3. Another teacher and an admin cannot read/review/download A's fixture content. Missing and foreign IDs give indistinguishable unavailable responses.
4. Admin form rejects stale versions and self-lockout. Concurrent worker test cannot remove both admins.
5. Legacy `/users` cannot restore a suspended admin, replace an enrolled teacher's email/password or delete that teacher. `/profile` cannot delete that teacher; ordinary never-enrolled student deletion still works.
6. Operational serializer exposes exactly its allowlist. No future business table is needed for the real admin empty page.
7. Injected audit failure rolls back state/version, while denied access remains denied when denial logging fails.

## 6. Evidence and pass rule

The implementation report maps each FR and every acceptance ID to test name, command/result and relevant file. Include before/after test evidence, migration/backfill and concurrency results, route inventory, formatter/diff results and remaining risks.

Complete requires all 004 acceptance cases passing, no new related regression, all tasks genuinely done, and no unreviewed production stubs or bypasses. Reproduced unrelated baseline failures may remain if identified with evidence and unaffected by the diff. A missing runtime or unexecuted concurrency/CSRF case is a partial result, not complete.

The `v3:bootstrap-access` command is exercised in tests. Run it against a real environment only as an explicit operator action after deployment; this runbook does not authorize production account changes.
