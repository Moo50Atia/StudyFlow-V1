# Tasks: V3 Access and Ownership

**Revision**: 004-r2 · **Implementation status**: All tasks pending  
**Inputs**: [spec](spec.md), [plan](plan.md), [data model](data-model.md), [access contract](contracts/access.md), [acceptance matrix](contracts/acceptance-matrix.md).

Test tasks precede the production changes they govern. If a regression already passes on the baseline, record that result; do not manufacture a failure. New behavior must demonstrate an intended failing assertion before its implementation. Syntax/autoload errors alone are insufficient evidence of a meaningful red phase.

## Phase 0 — Baseline and isolated test setup

- [x] T001 Record current branch/status, installed PHP/dependencies, existing relevant auth/profile test results, and absence/presence of planned files in `specs/004-v3-access-ownership/implementation-report.md`; use the exact commands in `quickstart.md`. Do not run production migrations or fix unrelated baseline failures.
- [x] T002 Add reusable actor and resource test support in `tests/Support/V3/AccessFixtures.php`, `OwnedFixture.php`, and `FixtureRoutes.php`. Register fixtures only inside tests; provision actors explicitly rather than changing default `UserFactory` behavior. No fixture route may appear in production route inventory.

**Checkpoint**: Baseline evidence and synthetic A/B content are available; no future business models have been introduced.

## Phase 1 — Access persistence and audit foundation

- [x] T003 Write `tests/Feature/V3/Access/AccessSchemaTest.php` for L01, L02 and L17: additive migration, exact backfill, idempotent operator bootstrap, retained inactive rows, restrictive FK, and safe down migration. Record intended failures (FR-001, FR-010, FR-013, FR-014).
- [x] T004 Implement `database/migrations/2026_10_01_000004_create_v3_access_tables.php`, `app/Models/V3/V3AccountAccess.php`, `V3AccessEvent.php`, and `app/Console/Commands/V3/BootstrapAccess.php` exactly as `data-model.md`; pass T003 without modifying existing users/role schema.
- [x] T005 Write `tests/Feature/V3/Access/AccessAuditTest.php` for recorder primitives and L14–L16 integration: allowlisted events, rollback, single denial record and safe fallback. Use fault injection; record recorder failures now, complete L15/L16 after T009 HTTP wiring and L14 after T021 transaction wiring (FR-013, FR-015).
- [x] T006 Implement `app/Services/V3/Access/AccessAuditRecorder.php` with fixed event/reason fields and one event per outcome/request. Keep account-event writes on the caller's database transaction; pass T005 recorder-level assertions, retaining the explicitly pending HTTP/service assertions.

**Checkpoint**: Persistence and audit primitives pass their direct tests. L15/L16 HTTP integration completes at T009 and L14 service integration at T021; T005 is not marked done prematurely.

## Phase 2 — US1: Role entry, sessions and working V3 pages

**Independent result**: Existing login leads to the correct empty V3 workspace; ineligible actors are denied.

- [x] T007 [US1] Write `tests/Feature/V3/Access/RoleAccessTest.php` for A01–A05 and M04; assert real route status, body/privacy and absence of nonexistent case/legacy content queries (FR-001, FR-003, FR-009).
- [x] T008 [US1] Write `tests/Feature/V3/Access/SessionAccessTest.php` for A06–A08 and A12; change stored state between requests using an independent model instance and prove controller nonexecution on lookup failure (FR-004, FR-007, FR-015).
- [x] T009 [US1] Implement `app/Services/V3/Access/V3AccessResolver.php` and `app/Http/Middleware/V3/RequireV3Access.php`; reload actor/access, enforce role parameter, normalize denials, and use T006 audit once per denied request. Complete T005's L15/L16 HTTP assertions once T010 routes are present (FR-001, FR-003, FR-004, FR-013).
- [x] T010 [US1] Add `routes/v3.php`, load it once in `routes/web.php`, register aliases in `bootstrap/app.php`, and create `app/Http/Controllers/V3/EntryController.php`, `Teacher/WorkspaceController.php`, `Admin/WorkspaceController.php`. Create `resources/views/v3/layout.blade.php`, `teacher/workspace.blade.php`, `admin/workspace.blade.php`; add V3 entry link in `resources/views/layouts/navigation.blade.php`. Keep pages honest empty states (FR-003, FR-009).
- [x] T011 [US1] Write `tests/Feature/V3/Access/AuthCompatibilityTest.php` for A09–A11 and L11; run existing login/reset tests and prove public registration/profile cannot grant role/access and reset cannot restore suspension. Preserve existing secure credential flow in `app/Http/Controllers/Auth/` and `app/Http/Requests/Auth/LoginRequest.php`; change it only if the new boundary assertions expose an actual bypass (FR-002, FR-004, FR-011).

**Checkpoint**: T007/T008/T011 green. No email verification dependency, broad login rewrite or automatic request-time enrollment.

## Phase 3 — US2: Ownership without future feature dependencies

**Independent result**: Production ownership primitives deny cross-teacher, admin and invalid parent access through fixture HTTP routes.

- [x] T012 [US2] Write `tests/Feature/V3/Access/OwnershipPolicyTest.php` for O01, O02, O04, O05, O07, O09 and O10, covering all four policy abilities, pagination/count isolation and immutable owner. Register fixture model policy explicitly in test setup (FR-005, FR-007, FR-009).
- [x] T013 [US2] Implement `app/Contracts/V3/V3OwnedResource.php`, `app/Models/Concerns/HasV3Owner.php` and `app/Policies/V3/TeacherResourcePolicy.php` according to the contract; pass T012. No admin `before` bypass, legacy TeacherPermission lookup or unscoped fallback (FR-005, FR-007).
- [x] T014 [US2] Write `tests/Feature/V3/Access/BoundaryHttpTest.php` for O03, O06, O08 and O11 using test-only read/write/review/download routes. Assert equal missing/foreign responses, actual parent membership, zero streamed foreign bytes and no persisted owner tampering (FR-005, FR-006, FR-007).
- [x] T015 [US2] Wire the production policy into `tests/Support/V3/FixtureRoutes.php` and implement V3-scoped error rendering/no-store behavior in `app/Http/Middleware/V3/V3PrivateResponse.php` and `bootstrap/app.php`. Complete T014 and verify errors from middleware and policies receive the same safe response contract; do not register production content routes (FR-006, FR-016).

**Checkpoint**: All O cases except production inventory O12 pass. Later real-resource integration remains an explicit downstream obligation, not hidden unfinished work in 004.

## Phase 4 — US3: Closed operational and account projections

**Independent result**: Synthetic operational records cannot leak extra fields; real admin workspace is content-free.

- [x] T016 [US3] Write `tests/Feature/V3/Access/AdminProjectionTest.php` for M01–M03: exact key set, recursive forbidden sentinel absence, unknown-code mapping and malformed scalar rejection (FR-008, FR-009).
- [x] T017 [US3] Implement `app/Data/V3/OperationSummaryData.php` and `app/Http/Resources/V3/OperationSummaryResource.php` with the explicit field/code allowlists; pass T016. Never spread a model array or accept arbitrary metadata (FR-008).
- [x] T018 [US3] Verify `app/Http/Controllers/V3/Admin/WorkspaceController.php` and `resources/views/v3/admin/workspace.blade.php` do not fetch legacy content or nonexistent cases. Complete M04 and document that 007/014 will provide real summaries (FR-009).

## Phase 5 — US4: Transactional account lifecycle and legacy closure

**Independent result**: Admin changes are validated, current, audited and resistant to alternate-route bypasses.

- [x] T019 [US4] Write `tests/Feature/V3/Access/AccountLifecycleTest.php` for L03–L07, including exact state table transitions, current no-op, stale version, no implicit restoration, self-lockout and final-admin guard (FR-007, FR-010, FR-012, FR-013).
- [x] T020 [US4] Write `tests/Integration/V3/Access/AccountConcurrencyTest.php` and `tests/Support/V3/ConcurrentAccountWorker.php` for L08/L09 on an isolated temporary SQLite file with independent workers. Assert winner/loser outcomes and final invariant; do not substitute sequential in-memory tests (FR-010, FR-012).
- [x] T021 [US4] Implement `app/Services/V3/Access/ChangeAccountAccess.php`: transaction first-write singleton lock, actor reload, expected-role/version checks, transitions, version increment, last-admin/self guards and atomic audit. Pass T019, T020 and pending L14 in T005; test failure rollback (FR-010, FR-012, FR-013, FR-015).
- [x] T022 [US4] Write `tests/Feature/V3/Access/AccountEndpointTest.php` for M05/M06/L06 and browser/JSON success/conflict/validation behavior, exact accepted input fields and pagination (FR-009, FR-010, FR-016).
- [x] T023 [US4] Implement `app/Http/Requests/V3/UpdateAccountAccessRequest.php`, `app/Http/Controllers/V3/Admin/AccountController.php`, account routes in `routes/v3.php`, and `resources/views/v3/admin/accounts/index.blade.php`. Use T021 service and separate identity projection; pass T022 (FR-009, FR-010).
- [x] T024 [US4] Write `tests/Feature/V3/Access/LegacyAccountBoundaryTest.php` for L10, L12, L13, L18 and L19 through actual `/users` and `/profile` routes. Test suspended admin, retained demoted teacher, stale hidden version, ordinary student deletion and admin email/password takeover attempts (FR-011, FR-014, FR-017).
- [x] T025 [US4] Integrate T021 into `app/Http/Controllers/UserController.php` store/update and add version/role hidden inputs to `resources/views/users/edit.blade.php`; validate in `app/Http/Requests/UserRequest.php`. In `ChangeAccountAccess.php`, reject administrative email/password replacements for any enrolled target; reflect this in the edit form. Implement `app/Services/V3/Access/RetainV3Account.php` before deletion/logout in `UserController.php` and `ProfileController.php`. Pass T024 without changing unrelated identity behavior (FR-011, FR-014, FR-017).

## Phase 6 — Complete boundaries, regression and evidence

- [x] T026 Write final `tests/Feature/V3/Access/RouteBoundaryTest.php` for O12 and extend `BoundaryHttpTest.php` for A13. Inspect real route middleware, prove test routes absent outside testing, enable actual CSRF checking for its negative test and verify no-store on allowed/denied responses (FR-009, FR-016).
- [x] T027 Finish route/error/cache/CSRF wiring exposed by T026 in `routes/v3.php`, `bootstrap/app.php` and `app/Http/Middleware/V3/V3PrivateResponse.php`; rerun affected cases. Do not disable middleware to obtain passing tests (FR-016).
- [x] T028 Execute every command and scenario in `quickstart.md`, including separate concurrency tests, migration/backfill tests and relevant existing auth/profile/Section navigation regressions. Map all acceptance IDs to test names/results in `specs/004-v3-access-ownership/implementation-report.md` (SC-001–SC-005).
- [x] T029 Inspect the final diff and route inventory against `contracts/integration-obligations.md`; confirm no future business models/routes, no V2/KOS changes, and no public V3 artifact paths. Run formatter in check mode on changed PHP files and `git diff --check`; record results (FR-005, FR-009, FR-016).
- [x] T030 Complete `specs/004-v3-access-ownership/implementation-report.md` with changed files, requirement/case evidence, baseline versus new failures, incomplete task IDs and final complete/partial/blocked status. Mark only completed tasks; explain any unresolved mandatory check. Do not start 005 or commit (SC-001–SC-005).

## Dependency order and safe parallelism

T001–T006 → US1 T007–T011 → US2 T012–T015 → US3 T016–T018 → US4 T019–T025 → T026–T030. T005 deliberately defines the fault case later fulfilled by T021; this is recorded, not forgotten.

No tasks carry `[P]`: these tasks touch common middleware, routes, schema and access state, so the default execution is sequential. If multiple implementers are explicitly assigned later, only independent test-file authoring may overlap after fixture contracts are stable; changes to shared production files remain serialized.

## Requirement-to-task coverage

| Requirement | Implementation tasks | Primary evidence |
|---|---|---|
| FR-001 | T004, T009 | T003, T007 |
| FR-002 | T011 | T011 |
| FR-003 | T009–T010 | T007 |
| FR-004 | T009, T011 | T008, T011 |
| FR-005 | T013, T015 | T012, T014 |
| FR-006 | T015 | T014 |
| FR-007 | T013, T021 | T008, T012, T014, T019 |
| FR-008 | T017 | T016 |
| FR-009 | T010, T018, T023 | T007, T012, T016, T022, T026, T029 |
| FR-010 | T004, T021, T023 | T003, T019–T020, T022 |
| FR-011 | T011, T025 | T011, T024 |
| FR-012 | T021 | T019–T020 |
| FR-013 | T004, T006, T021 | T003, T005, T019 |
| FR-014 | T004, T025 | T003, T024 |
| FR-015 | T009, T021 | T005, T008 |
| FR-016 | T015, T023, T027 | T022, T026, T028–T029 |
| FR-017 | T025 | T024 |
