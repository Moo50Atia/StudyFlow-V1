# Implementation Notes: 004

This file explains the repository traps and fixed choices. The execution order is in [task-execution-plan.md](task-execution-plan.md); it should not be replaced with a newly generated plan.

## Read in this order

1. [spec.md](spec.md) for business scope and FRs.
2. [research.md](research.md) for actual Laravel assets and incompatibilities.
3. [plan.md](plan.md), [data-model.md](data-model.md), [access contract](contracts/access.md).
4. [acceptance matrix](contracts/acceptance-matrix.md) and [integration obligations](contracts/integration-obligations.md).
5. [tasks.md](tasks.md), execution plan and [quickstart.md](quickstart.md).

## Decisions already made

- Reuse `users.role` and existing session identity; add a separate V3 access table. Missing access row is denial, not automatic enrollment.
- V3 suspension does not delete the identity or disable password recovery. It denies the next V3 request even for an existing login.
- V3 does not require email verification. The existing code and some tests disagree about verification fields; record baseline behavior instead of expanding 004 into an identity rewrite.
- Admin has operational/account-management rights only. Do not reuse `TeacherAccessMiddleware`, its admin bypass, or its permissive GET behavior.
- Case/document/scene entities do not exist yet. Use test-only fixtures to exercise the real ownership primitives; no production placeholders are permitted.
- Existing user-edit and self-delete routes are part of this feature's security boundary. Securing new routes alone is incomplete.
- Backfill current teachers/admins once. For accounts seeded later, operator bootstrap inserts only missing rows and never reenables a suspended row.
- The original owner remains unchanged on disablement, restoration, promotion or demotion. A retained V3 access row blocks account deletion permanently for this pilot.

## Common implementation errors to avoid

| Error | Required behavior |
|---|---|
| Cache role in session or an eager-loaded relationship | Resolve current stored role/access each request |
| Use role-only middleware on a mutation service | Recheck active admin inside its transaction, including legacy callers |
| Count admins before starting transaction | First-write singleton lock, then reload actor/target/count |
| Use only `lockForUpdate` and assume SQLite locking | Follow the first-write lock design and prove it with file-backed concurrent workers |
| Call `$model->toArray()` for admin output | Construct the closed DTO with explicit scalars and code mapping |
| Authorize owner but forget nested membership | Query child through the authorized parent relation |
| Set owner from request or allow owner in fillable update | Use trusted actor/parent and prohibit client assignment; guard persisted owner |
| Add a global `Gate::before` admin allow | Keep V3 teacher-content policy free of admin bypass |
| Delete account before checking V3 history | Run retention guard before delete and before profile logout |
| Let admin replace an enrolled teacher's recovery email/password | Deny in the common service even after demotion; owner maintains credentials through authenticated/recovery flows |
| Mark a 419 test passed with Laravel's testing bypass | Enable real CSRF behavior in that test |
| Catch audit failure and report mutation success | Roll back role/access/version and return unavailable |
| Claim future artifact endpoints were secured | State fixture coverage now and downstream wiring obligations separately |

## Safe handling of a mismatch

Inspect the named files before editing. If they differ materially from this reviewed baseline, resolve small path/refactor changes with the same contract. If a difference requires a new business rule or weakens ownership/security, record the exact blocker and finish independent tasks; do not silently change the spec, skip its tests or improvise a broader permission.

Keep unrelated changes, canonical prompt text, V2 and KOS/KIE untouched. 004 has no external-service prerequisite; fake NotebookLM/JEV clients do not belong here.
