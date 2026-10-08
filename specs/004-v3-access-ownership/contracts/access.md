# Contract: V3 Access and Ownership

**Version**: 004-r2 · **Actor source**: Laravel `web` session, never a client owner/role field.

## Effective permission

`activeV3(user) = stored user exists AND stored role ∈ {teacher, admin} AND access row exists AND is_active`.

`ownsContent(user, resource) = activeV3(user) AND stored role=teacher AND user.id=resource.v3OwnerId()`.

Check current database state on each request and at account mutation transaction time. Existing `TeacherPermission` rows confer no V3 content access. Admin is never a substitute for teacher ownership or worker identity.

## Production routes delivered by 004

All routes are loaded once under Laravel's web middleware from `routes/v3.php`.

| Method/path | Name | Actor | Result |
|---|---|---|---|
| GET `/v3` | `v3.entry` | Any active V3 actor | 302 to correct role workspace |
| GET `/v3/teacher` | `v3.teacher.workspace` | Active teacher | 200 teacher empty workspace |
| GET `/v3/admin` | `v3.admin.workspace` | Active admin | 200 empty operations workspace |
| GET `/v3/admin/accounts` | `v3.admin.accounts.index` | Active admin | 200 paginated account identity/access list |
| PATCH `/v3/admin/accounts/{user}/access` | `v3.admin.accounts.access.update` | Active admin | Apply validated access change; response below |

The two workspace routes are HTML pages. JSON contract tests concern errors and the account mutation, not an invented workspace API. Existing login/logout/password-reset routes remain the shared identity interface. No real document/scene/download endpoints are registered in 004.

## Account list and mutation

List fields: `id`, `name`, `email`, `role`, `v3_is_active` (false for absent row), `v3_version` (0 if absent). Pagination: 20 per page, stable user-ID ordering. No password/hash/token/teacherPermissions/educational relationships in the projection. Account names and emails are permitted here solely for admin identity management.

PATCH body accepts exactly:

```json
{
  "role": "teacher",
  "is_active": true,
  "expected_version": 2,
  "expected_role": "teacher",
  "reason_code": "restore_access"
}
```

- `role`: admin, teacher or student only.
- `is_active`: strict boolean after form normalization (`0`/`1` from HTML permitted); active student is invalid.
- `expected_version`: nonnegative integer; 0 only for absent access row.
- `expected_role`: one of existing role values, compared to freshly loaded role.
- `reason_code`: `grant_access`, `suspend_access`, `restore_access`, `change_role`; must match the requested effective change. For a combined role/state change, use `change_role`.
- Reject unexpected application fields such as owner IDs, nested access records or credentials. `_token` and `_method` are framework fields, not business input.

Success JSON (200) contains only `{data:{id,role,is_active,version,changed}}`; `changed=false` denotes a current-version no-op. Browser success redirects 303 to the V3 account list with a generic success/no-change message. State-changing audit events are atomic with writes. Repeated stale requests return 409; idempotent mutation by replay is not falsely claimed.

Legacy `/users` create/update keeps its identity form and uses the same access service for role/access effects. Add expected-version and expected-role hidden fields to every edit form (0 for no access record); reject updates without them with 422. Creation does not require these tokens. A role-only legacy change never restores an inactive record. Student-only identity operations never provision V3 access.

For any target with a retained V3 access row, admin updates cannot change email or supply a nonempty replacement password. Return 403 `access_denied`, preserve credentials and record a safe denial. An unchanged submitted email is allowed. This restriction persists after demotion and applies even if the admin targets their own enrolled account; use the authenticated account-holder flows for credentials.

Validate reason-code membership for all requests; validate its semantic match only when there is an effective change. A same-state/current-version request remains a no-op with any recognized reason code.

## Exact failure precedence and responses

Check authentication → active V3 state → required role → resource visibility → request validation/version → mutation. CSRF is enforced by the web stack before the controller and may reject earlier. Resource identifiers must not affect role failure responses.

| Condition | Browser | JSON (`Accept: application/json`) | Persistence |
|---|---|---|---|
| Missing/expired session | 302 login for GET; existing auth behavior for other methods | 401 `unauthenticated` | No business mutation |
| No access row / inactive / student or unknown role | 403 generic access-denied page | 403 `access_denied` | One safe denial event |
| Eligible actor, wrong area role | 403 generic access-denied page | 403 `access_denied` | One safe denial event |
| Foreign/missing owned resource or parent mismatch | 404 generic unavailable page | 404 `resource_unavailable` | No content data; one denial event |
| Missing account target for authorized admin | 404 generic unavailable page | 404 `resource_unavailable` | No mutation |
| Invalid account input | Standard 302 form redirect/errors | 422 validation error | No mutation |
| Stale version or expected role | 409 conflict page with reload action | 409 `access_conflict` | No mutation |
| Self-lockout / final-admin removal / enrolled-user deletion | 409 generic policy conflict | 409 `access_conflict` | No mutation |
| Authorization DB unavailable or mutation audit failure | 503 temporary-unavailable page | 503 `access_unavailable` | No controller action / transaction rollback |
| Missing/invalid CSRF token | 419 framework behavior | 419 framework behavior | No mutation |

Custom JSON error shape: `{ "error": { "code": "access_denied", "message": "V3 access is unavailable." } }`. Use a fixed message per code; no model class, IDs, raw error or ownership explanation. Missing and foreign resource bodies must match after excluding request correlation, if present. Standard framework validation/auth/CSRF formats can remain for their existing routes; do not globally rewrite legacy error handling.

Every V3 success/error response has `Cache-Control: private, no-store`. Test this for middleware-denied and policy-denied responses, not only controller successes. No signed URL bypass or public `/storage/` content delivery is permitted for later V3 resources.

## Ownership API and downstream behavior

- `TeacherResourcePolicy`: `view`, `update`, `review`, `download` return allowed only for current active original teacher; denies are rendered according to the route-level rules above.
- `HasV3Owner::scopeOwnedBy($query, User $actor)`: applies server-derived owner condition before pagination/counts; ineligible caller is denied, not an unrestricted query.
- `HasV3Owner`: rejects modifying stored owner on update. Future request validators prohibit client owner fields. Future creation sets owner explicitly from actor or trusted parent.
- Parent resolver obtains child through authorized parent's relation, including when both requested resources have the same owner.
- A valid resource ID or signed link never authorizes access by itself.

## Closed operational summary

Exactly these top-level keys are allowed; no extra `metadata`, model relation or nested payload:

| Field | Value |
|---|---|
| `case_id` | Opaque case UUID |
| `stage_code` | `intake`, `extraction`, `sourcing`, `mapping`, `prompting`, `prompt_qualification`, `scene_generation`, `scene_qualification`, `teacher_review`, `complete`, `unknown` |
| `status_code` | `queued`, `running`, `awaiting_review`, `blocked`, `failed`, `cancelled`, `approved`, `unknown` |
| `error_code` | null or `validation`, `provider_unavailable`, `timeout`, `policy_denied`, `internal`, `unknown` |
| `created_at`, `updated_at` | ISO-8601 timestamps |
| `attempt_count`, `scene_count`, `approved_scene_count` | Nonnegative integers |

Later 007/014 map their states to this projection and may explicitly version/extend known codes. They may not pass arbitrary source/error text as codes. Omit all owner names/emails, original filenames, hashes, URLs/paths, notebook IDs, source/Section/prompt/HTML/note bodies and loaded relations. Construct the DTO using explicit scalars; reject structurally invalid values rather than serializing raw input.

## Audit contract

Account event snapshots contain only `{role,is_active,version}`; actor/target IDs and timestamp are allowed. Reasons use the four request reason codes plus internal `bootstrap` and `legacy_role_change`.

Denial reasons: `unauthenticated`, `not_enrolled`, `disabled`, `wrong_role`, `not_owner`, `parent_mismatch`, `access_conflict`, `retained_account`, `credential_change_denied`, `access_unavailable`. HTTP responses may intentionally collapse several reasons to one safe code. No request payload, cookies, token, raw URL/query or educational content is recorded. Denial audit failure retains the original denial and emits a fixed emergency log message; mutation audit failure rolls back. Framework CSRF/validation failures retain framework behavior and are not counted as permission-denial audit events in this spec.
