# Implementation Plan: V3 Access and Ownership

**Spec**: [spec.md](spec.md) · **Reviewed**: 2026-10-01 · **Scope**: 004 only

## Summary

Reuse Laravel identity, but give V3 its own access record, middleware, routes and policies. Backfill existing teacher/admin eligibility once. Protect the existing account mutation paths so they cannot bypass V3 suspension, role safeguards or retention. Demonstrate future ownership behavior with test-only resources rather than building later features.

## Technical Context

| Concern | Decision |
|---|---|
| Runtime | Existing PHP/Laravel application; `composer.json` requires PHP ^8.2 and Laravel ^12.0. Installed lockfile controls actual versions. |
| Identity | Existing `web` session guard and `users.role` (`admin`, `teacher`, `student`). No second user table or auth provider. |
| V3 eligibility | New `v3_account_access` row, active flag and current allowed role; see data model. |
| Database | Existing configured connection. SQLite is the repository default; unit/feature tests use SQLite memory. Concurrent tests use a separate temporary SQLite file. |
| Testing | Existing Pest 4 feature setup with `RefreshDatabase`; isolated PHPUnit integration test for concurrency. No Python or external APIs in 004. |
| Storage | No actual content storage in 004. Later content must use a V3 private disk with no direct URL exposure. |
| Performance | No provider calls or jobs in authorization. One fresh user/access lookup per entry request; lists must use owner filtering before pagination. |
| Deployment | Existing Laravel app with a new `/v3` namespace. No branch, deploy or production migration during documentation/implementation review. |

## Constitution Check

The checked-in constitution consists of placeholders, not ratified principles. Binding requirements are the user's V3 roles/ownership decisions and TDD request. Pre/post-design checks: teacher remains original owner/reviewer; admin content access is denied; V2 and KOS/KIE are untouched; no future feature is required for 004 completion.

## Evidence from the repository

See [research.md](research.md) for paths and findings. Critical reuse constraints:

- `TeacherAccessMiddleware` grants admins full access and allows unowned teacher GETs. Never attach it to V3 routes.
- `UserController` writes roles and hard-deletes users; `ProfileController::destroy` also hard-deletes. These paths require narrow integration with the V3 service/retention guard.
- `User` has no active field and no `MustVerifyEmail` implementation; the users migration has no `email_verified_at`. Do not turn on `verified` for V3 or repair unrelated verification tests as part of 004.
- `local` filesystem currently has `serve => true`. Merely calling a disk “private” does not authorize downloads. Future V3 artifacts must not use its unguarded/signed-file delivery path.

## Project Structure and file ownership

| Area | Implement in 004 |
|---|---|
| Routes | `routes/v3.php`; load once from `routes/web.php` so normal web/session/CSRF stack applies |
| Middleware | `app/Http/Middleware/V3/RequireV3Access.php`, `V3PrivateResponse.php`; aliases in `bootstrap/app.php` |
| Access service | `app/Services/V3/Access/V3AccessResolver.php`, `ChangeAccountAccess.php`, `AccessAuditRecorder.php`, `RetainV3Account.php` |
| Models/migration | `app/Models/V3/V3AccountAccess.php`, `V3AccessEvent.php`; `database/migrations/2026_10_01_000004_create_v3_access_tables.php` |
| Ownership | `app/Contracts/V3/V3OwnedResource.php`, `app/Models/Concerns/HasV3Owner.php`, `app/Policies/V3/TeacherResourcePolicy.php` |
| Summary | `app/Data/V3/OperationSummaryData.php`, `app/Http/Resources/V3/OperationSummaryResource.php` |
| Controllers | `app/Http/Controllers/V3/EntryController.php`, `Teacher/WorkspaceController.php`, `Admin/WorkspaceController.php`, `Admin/AccountController.php` |
| Input/UI | `app/Http/Requests/V3/UpdateAccountAccessRequest.php`; `resources/views/v3/{teacher,admin}/...`; shared V3 layout |
| Legacy seams | `app/Http/Controllers/UserController.php`, `ProfileController.php`; account form version token in `resources/views/users/edit.blade.php`; V3 entry link in shared navigation |
| Tests | `tests/Feature/V3/Access/`, `tests/Support/V3/`, `tests/Integration/V3/Access/` |

Do not create `ProcessingCase`, Document, Scene, Review or worker models here. Do not add a placeholder model named like a later production entity.

## 1. State and bootstrap

Create the three tables specified in [data-model.md](data-model.md). A one-time migration inserts active access rows for current teachers/admins and a `bootstrap` event for each. Students have no row. Application code must never auto-enroll a user merely because they visit `/v3`; an absent row denies access.

Legacy admin creation/promotion into teacher/admin uses the shared service to enroll explicitly. An access row remains after suspension or demotion, making the user ineligible for hard deletion. No ownership tables need to be queried to enforce that retention latch.

No access rows or events include educational content. Users retain their current role value; V3 does not add a competing role column.

For identities seeded after migration, provide the explicit local `v3:bootstrap-access` command specified in the data model. It creates missing eligible rows/events only, never credentials or implicit reactivation.

## 2. Route/authentication order

Exact routes and response formats are in [contracts/access.md](contracts/access.md).

1. Run the normal `web` session/CSRF stack.
2. V3 middleware authenticates from the existing guard; unauthenticated browser GET redirects to login with a same-origin intended URL. JSON receives 401.
3. Resolver reloads user ID/role and access row from storage. Never trust cached session role, a request field or a loaded relationship across requests.
4. Missing access row, disabled state, unsupported role, or wrong area yields 403. Database unavailability yields a generic 503 and never executes the controller.
5. Owner-specific routes then resolve within the teacher's owner-scoped query and, for a child, its actual parent relationship. Foreign/missing resources share a 404 response.
6. Add `Cache-Control: private, no-store` to V3 responses, including errors; do not cache rendered owner pages in shared caches.

Reuse login/password reset/logout and their existing redirect behavior. A guest who started at `/v3` returns there via the existing intended redirect. A direct ordinary login may still use the legacy dashboard, which receives a V3 entry link. Do not require email verification or rewrite login for every legacy user.

Suspension is effective on the next V3 request. This does not retract an already transmitted response or retroactively cancel an action whose authorization already completed. Each new mutation must authorize at submission; account mutations recheck the actor inside their transaction.

## 3. Working UI for this spec

- `/v3/teacher`: teacher workspace shell with signed-in identity, a truthful empty processing state and sign-out/navigation. Do not display working upload/review actions before their specs exist.
- `/v3/admin`: admin operations shell with empty state, account-access link and no legacy lecture/Section query.
- `/v3/admin/accounts`: paginated account access list and forms to grant/suspend/restore/change role for existing users. Reuse the existing account-creation page for new identities; its store action is secured below.
- Account forms submit a CSRF token, expected access version and fixed reason code. No free-form teacher content enters audit reasons.

## 4. Ownership components

`V3OwnedResource::v3OwnerId(): int` exposes trusted stored owner identity. `TeacherResourcePolicy` defines `view`, `update`, `review` and `download`; all require a fresh active teacher whose ID equals the owner. No `before` method or global admin shortcut may override it.

`HasV3Owner` supplies an `ownedBy(User $actor)` query scope and rejects a changed `owner_user_id` on model update. Creation services in later specs assign owner from authenticated user or trusted parent; client owner fields are prohibited. Worker creation in later specs uses trusted case context, not an admin identity. Bulk database updates bypass model events, so later writers must not use them to alter ownership.

Nested membership is a repository/query obligation: resolve child through the authorized parent's relationship, then apply policy. Checking two owner IDs alone does not establish that the child belongs to the requested parent.

004 tests register fixture routes in test setup only, using temporary fixture tables/models and the actual policy/query scope. They cover read, write, review, download, list pagination and parent substitution. Explicitly register policy bindings for fixture models in tests; future specs register their production model policies in `AppServiceProvider`. A generic interface policy is not assumed to be automatically discovered.

## 5. Closed admin projections

`OperationSummaryData` contains only the fields and enumerated codes in the access contract. `OperationSummaryResource` explicitly enumerates output keys; it never spreads model arrays/relations or accepts arbitrary metadata. Validate input scalars before construction; map unknown codes to the fixed `unknown` value. Do not put raw exception messages into `error_code`.

Use synthetic records for projection tests; the actual 004 operations page is empty. 007/014 supply real records later. Account administration has a separate narrow identity projection and is not a route to educational artifacts.

## 6. Account transaction and bypass protection

All new and legacy access/role mutations go through `ChangeAccountAccess`.

The service exposes three entry methods: `updateAccess(actor, targetId, validatedAccessChange)` for the V3 PATCH; `createFromLegacy(actor, validatedIdentity)` for existing account creation; and `updateFromLegacy(actor, targetId, validatedIdentity, expectedVersion, expectedRole)` for existing account edits. All use the same locked transaction and actor checks. Creation writes the user and its required access/audit rows inside that transaction, not before it. Identity-only edits do not invent an access-change event when role/access state is unchanged.

1. Begin transaction. First perform an update of singleton `v3_access_control_locks.id=1` (increment revision) to serialize mutation transactions on SQLite and row-locking databases. Do this before eligibility/count reads.
2. Reload actor and target; require active admin actor. Check expected access version (`0` means no row) and existing role captured by the form. Reject conflicts before mutating.
3. Validate transition: a student cannot have active V3 access; allowed roles are fixed; disabling/demoting self is prohibited. Recompute active-admin count under the lock and prohibit removing the final one.
4. Create access row if granting teacher/admin, or update state/role and increment version. A role-only legacy change preserves an existing inactive flag; changing role never implicitly restores suspension. Demotion to student sets active false and retains the row.
5. Append one safe account event in the same transaction; commit only if the audit insert succeeds. Identical desired state with current version returns a no-change result without a new version/event. A stale repeated request returns 409, not a second success.
6. Roll back on failure. Translate lock contention/transaction availability failures to 503 after the database transaction retry policy (maximum three attempts); do not report a successful mutation.

Adapt `UserController::store/update` to call this same service with the actual authenticated actor. Include `expected_version` and `expected_role` in every existing user edit form (version 0 for a target without a V3 row); require them on update so a concurrent promotion cannot bypass the expected-state check. Creation has no existing target and does not require these fields. The service rechecks actors even when a legacy route only uses `role:admin`. A suspended admin cannot restore itself or create a bypass admin through `/users`. A student-only identity operation does not provision a V3 row.

Apply `RetainV3Account` in both `UserController::destroy` and `ProfileController::destroy` before deletion or logout. An existing access row means 409 and no mutation. The access-to-user FK uses restrictive deletion as a second boundary. Ordinary never-enrolled student deletion preserves current behavior. No hard-delete or cascade cleanup UI is introduced.

Close the credential-edit shortcut as well: if the target has a retained V3 access row, `updateFromLegacy` rejects changed email or any nonempty replacement password with 403 before mutation. Keep name/role/access edits permitted under their rules. Render enrolled targets' email read-only and omit password replacement in the admin edit form, but enforce the restriction again in the service. The account holder's shared profile/password/recovery flows remain the credential-maintenance path. This includes demoted enrolled accounts; removing their role must not unlock a password-edit bypass.

## 7. Audit and failure handling

Persist one access-denial event per denied V3 request, with server-generated request ID, actor ID if known, route name, fixed denial code and time. Do not log URL query, body, cookies, source filename, resource payload, email or arbitrary route parameter. A denied request stays denied if audit storage fails; emit only a fixed fallback logger message plus request ID.

Account mutation events are mandatory and atomic. If they cannot be written, roll back the mutation and return 503. A model observer is not a substitute for the transaction service and actor validation.

## 8. Delivery and verification

Follow [tasks.md](tasks.md) and [task-execution-plan.md](task-execution-plan.md), which specify the red/green sequence. [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) is the coverage ledger; [quickstart.md](quickstart.md) fixes commands and evidence rules.

Do not claim future real artifact authorization has been tested in 004. Record fixture coverage now and the mandatory future wiring in [contracts/integration-obligations.md](contracts/integration-obligations.md).
