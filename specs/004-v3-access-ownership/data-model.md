# Data Model: V3 Access and Ownership

**Authority**: [spec](spec.md), [plan](plan.md). These are exact 004 tables and interfaces, not optional alternatives.

## Existing `users`

Reuse bigint `id`, `name`, unique `email`, hashed `password`, enum `role`, remember token and timestamps. Role remains authoritative; do not copy it into another mutable role column. Do not add an email-verification requirement or an active flag to `users` for this spec.

## `v3_account_access`

Model: `App\Models\V3\V3AccountAccess`; explicitly set table and primary key.

| Column | Type / constraints | Meaning |
|---|---|---|
| `user_id` | unsigned bigint PK and FK to users; restrict deletion | One retained access record per enrolled user |
| `is_active` | boolean, not null, default true | V3 permission switch, independent of shared identity |
| `version` | unsigned integer, not null, initial 1 | Compare-and-swap access revision |
| `provisioned_at` | timestamp, not null | First grant, never rewritten |
| `disabled_at` | nullable timestamp | Set on suspension/demotion; cleared on restoration |
| `created_at`, `updated_at` | timestamps | Record lifecycle |

Casts: boolean, integer and datetime as appropriate. `user_id` cannot change. Role student requires `is_active=false` if a record exists. Effective access is current role in {teacher, admin} AND existing record AND active. Missing record is ineligible. Increment version only on an effective state or role change. No-op with a current version leaves it unchanged.

## `v3_access_events`

Model: `App\Models\V3\V3AccessEvent`. Append-only in application code; no update/delete route.

| Column | Type / constraints | Meaning |
|---|---|---|
| `id` | UUID primary key | Server-generated event identity |
| `request_id` | UUID, not null | Server-generated request/command correlation |
| `event_type` | string(24), not null | `bootstrap`, `account_changed`, `access_denied` |
| `actor_user_id` | nullable bigint FK, null on deletion | Null for guest/bootstrap; unrelated actor deletion must not delete event |
| `target_user_id` | nullable bigint FK, null on deletion | Only account operations; no content resource IDs |
| `route_name` | nullable string(100) | Known route name, never raw URI/query |
| `reason_code` | string(40), not null | Fixed contract code |
| `before_state`, `after_state` | nullable JSON | Only role, active and version keys; no names/emails/content |
| `occurred_at` | timestamp, not null | Event time |

Unique `(event_type, request_id)` prevents duplicate audit records for the same outcome. Index `(target_user_id, occurred_at)` and `(actor_user_id, occurred_at)`. Do not add unbounded metadata, raw exception or request-payload columns.

For denied requests, states and target are null. If a route returns a denial, one component owns its audit write; middleware and policy must not both append duplicates.

## `v3_access_control_locks`

Columns: integer PK `id`; unsigned bigint `revision`, default 0. Seed exactly one row `{id:1, revision:0}`. Every account access/role mutation first increments this row inside its transaction, before reading actor, target or active-admin count. The write serializes operations on SQLite as well as databases with row locking. Never delete the singleton during normal operation.

## Migration and operator bootstrap

Migration `2026_10_01_000004_create_v3_access_tables.php` creates tables, singleton and active access rows for existing teacher/admin accounts, with a bootstrap event per row. Students are untouched. Use stable query-builder operations in the migration, not evolving application models. Down migration drops only these tables in FK-safe order; it does not delete or rewrite users.

For a fresh database seeded after migration, provide `php artisan v3:bootstrap-access`. This explicit local operator command inserts only missing teacher/admin access rows and bootstrap events. It never changes an existing row, reactivates a suspended user or creates credentials. No HTTP route calls it. Repeating it is a no-op for previously provisioned accounts.

## Invariants and transitions

| Current | Requested change | Result |
|---|---|---|
| No access row, teacher/admin | Explicit grant | Active row version 1 |
| No access row, student | Keep student | No access row |
| No access row, student | Promote and grant | Change role and create row version 1 atomically |
| Active teacher/admin | Suspend | Inactive, disabled timestamp, version +1 |
| Inactive teacher/admin | Explicit restore | Active, clear disabled timestamp, version +1 |
| Any enrolled account | Demote to student | Retain row inactive, version +1 if changed |
| Enrolled student | Promote through role-only legacy update | Retain inactive state; restoration requires explicit action |
| Any current state | Same desired values, current version | No-change response; no new audit mutation |
| Any current state | Stale expected version/role | 409; no change |
| Actor targets self for demotion/suspension | Any | 409; no change |
| Last active admin would be removed | Any | 409; no change |
| Account has any retained access row | Hard delete through application | 409; retain account |

## Future ownership interface (no production resource table here)

`App\Contracts\V3\V3OwnedResource::v3OwnerId(): int` returns the original teacher's stored ID. `HasV3Owner` uses `owner_user_id` and provides an owner-filtered query scope plus update immutability. Future tables reference users with restrictive deletion. Original ownership is not changed when a user's access/role changes.

The fixture table in tests may use UUID ID, owner ID, parent ID and sentinel content/path. It is test setup only and must not enter production migrations or route registration.

## Operational projection

`OperationSummaryData` is a value object, not a table. Its field/code allowlist is in [access.md](contracts/access.md). It has no dependency on the future ProcessingCase model. Account-list identity data uses a separate serializer.
