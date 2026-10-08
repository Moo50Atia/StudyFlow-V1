# Repository Research and Decisions: 004

**Reviewed**: 2026-10-01. Findings below are from repository code, not assumptions about a stock Laravel installation. No application tests were run during this documentation revision.

## Asset inventory for this feature

| Existing asset | Evidence | Reuse / change |
|---|---|---|
| Identity and roles | `app/Models/User.php`; `database/migrations/0001_01_01_000000_create_users_table.php` | Reuse user ID and enum role; add separate V3 eligibility table |
| Login/logout | `app/Http/Controllers/Auth/AuthenticatedSessionController.php` regenerates session on login and invalidates on logout | Reuse; preserve intended redirect; add V3 navigation |
| Login rate limiter | `app/Http/Requests/Auth/LoginRequest.php` checks five attempts per throttle key | Preserve and regression-test |
| Registration | `RegisteredUserController` supplies name/email/password, relies on default student role | Keep student default; test forged role/access fields |
| Password recovery | `routes/auth.php`, password reset controllers | Reuse; verify it never changes V3 access |
| Legacy role middleware | `RoleMiddleware` checks only authenticated role | Insufficient for V3 active/enrollment state |
| Legacy content middleware | `TeacherAccessMiddleware` gives admin full access and allows teacher GETs without ownership | Do not use for V3; keep legacy content isolated |
| Current route wiring | `bootstrap/app.php` registers role/teacher.access aliases; `routes/web.php` combines legacy content and admin routes | Add separate V3 routes/aliases with normal web stack |
| User management | `UserController::store/update/destroy`, `UserRequest` | Role mutation/deletion bypasses must call new service/guard |
| Profile | `ProfileController::destroy` logs out then deletes; update permits name/email only | Add enrolled-account retention check before logout/delete; test role injection |
| Email verification | User's `MustVerifyEmail` import is commented; migration omits `email_verified_at`; factory has no `unverified()` state, while existing tests assume it | No new verification gate; record unrelated baseline failures rather than silently repairing scope |
| Storage | `config/filesystems.php`: local private root with serve=true; public disk linked to public/storage | Future V3 artifact disk must not expose direct file URLs; no production artifact delivery in 004 |
| Tests | Pest feature tests use RefreshDatabase; phpunit.xml sets SQLite :memory:, array sessions/mail/cache | Reuse for feature tests; use separate file/connection for concurrency |
| Spec Kit | Constitution is all placeholders; no extensions.yml; current selector may target another spec | No inherited governance invented; use explicit feature selection for review |

## D1 — Separate V3 eligibility from shared identity

Decision: `v3_account_access` stores active state and revision, while `users.role` remains authoritative. Existing teacher/admin users are provisioned once; students are not. Missing row denies.

Reason: adding an undefined global active flag would change legacy behavior and still leave provisioning ambiguous. The V3 record also provides a durable deletion guard after demotion.

Rejected alternatives: a second identity store; automatic enrollment on every request; letting legacy teacher permissions establish V3 ownership.

## D2 — Reuse credentials without silently enabling email verification

Decision: valid existing session plus V3 eligibility is sufficient. Keep password recovery, session regeneration and logout. Interpret the business flow's identity verification as successful authentication for this pilot.

Reason: the code does not currently support the email-verification setup assumed by some generated tests. Turning it on would introduce a new account-verification feature and migration beyond the agreed V3 access scope.

## D3 — Close existing account mutation and deletion paths

Decision: new V3 and existing `/users` role changes call one transaction service. Both account-delete controllers block users with a retained V3 record. The FK also restricts parent deletion.

Reason: protecting only `/v3` would let a suspended admin use the existing role-only admin routes to restore privileges or delete owners. Disabling or demoting a teacher must not destroy or transfer future reviews.

## D4 — Make admin lockout protection concurrency-safe

Decision: first-write singleton transaction lock, fresh actor/target reads, expected role/version, self-lockout and active-admin invariants. Use a file-backed SQLite integration test with independent workers.

Reason: a preflight count outside a transaction or in-memory sequential test does not protect against two admins removing each other concurrently. SQLite is the repository default, so a design relying only on `SELECT FOR UPDATE` would be insufficient.

## D5 — Prove ownership before business models exist

Decision: reusable policy/interface/query scope plus test-only resource and nested-route fixtures. No production case/document/scene table in 004. Future specs must wire their own models and HTTP routes to the same contracts.

Reason: the old quickstart required nonexistent resources and would either block 004 or tempt the implementer to build later specs early. Fixture evidence is explicitly labeled and must not be represented as live artifact coverage.

## D6 — Explicit projection and error contracts

Decision: exact operational field/code allowlist, separate account identity projection, exact 401/403/404/409/422/503 behavior and private no-store headers. Use generic missing/foreign responses.

Reason: “safe metadata” and “404/403” did not tell the implementer what could be exposed or how to avoid existence leaks.

## D7 — Audit atomicity and controlled bootstrap

Decision: account state and required audit event commit together; denial remains denied if audit storage fails. Initial migration and an explicit idempotent local bootstrap command enroll existing teacher/admin accounts without creating credentials or reactivating old rows.

Reason: the business flow says failed history persistence must not be reported as a completed change. A fresh database seeded after migrations also needs a defined first-admin route into V3.

## Scope decision register

## D8 — Restrict administrative credential replacement after enrollment

Decision: once a V3 access record exists, admin user-edit routes cannot replace that account's email or password, even after demotion. The account holder retains ordinary authenticated/recovery credential maintenance.

Reason: `UserController::update` currently accepts both fields. Without this restriction, metadata-only administration would still expose a direct account-takeover shortcut. Initial provisioning and existing credential custody remain trusted identity assumptions; database/server operators and stolen credentials are outside this application-role boundary.

## Scope decision register (review limits)

The choices above are implementation decisions made to complete the already-approved V3 behavior, not new business modules. 005–015 are read for interface context only and are unchanged. The older flow-4 text still says local unit discovery/usable-JEV checks; later owner corrections supersede it, and it does not affect 004. This review does not certify the readiness of those later specs.
