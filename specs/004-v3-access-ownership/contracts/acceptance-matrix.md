# Acceptance Matrix: 004

Every case below needs an executable assertion and a reported result. Fixtures use distinct sentinel content for teachers A and B. Do not replace route tests with assertions that merely check a policy's return type.

Test paths in the tables are relative to `tests/Feature/V3/Access/` unless stated otherwise. Each test name must include its case ID so the final evidence can be traced.

## Identity, route and session cases

| ID | Given / action | Required assertion | Test file | Requirements |
|---|---|---|---|---|
| A01 | Guest HTML GET `/v3/teacher` | Redirect login; intended local URL retained; no content | RoleAccessTest.php | FR-001, FR-003 |
| A02 | Guest JSON GET `/v3/admin` | 401; no operation data | RoleAccessTest.php | FR-001, FR-003 |
| A03 | Active enrolled teacher/admin GET `/v3` | 302 to correct role area; destination 200 | RoleAccessTest.php | FR-001, FR-003 |
| A04 | Student, missing access row or unsupported role requests V3 | 403 for each; no controller payload | RoleAccessTest.php | FR-001 |
| A05 | Teacher requests admin area; admin requests teacher area | 403 regardless supplied resource identifier | RoleAccessTest.php | FR-003, FR-005 |
| A06 | Existing session then another actor suspends account | Next GET and mutation 403; user row retained | SessionAccessTest.php | FR-004 |
| A07 | Existing teacher session promoted to admin | Teacher area now denied; admin area allowed; owner fixture unchanged | SessionAccessTest.php | FR-004, FR-007 |
| A08 | Suspended account restored explicitly | Next request allowed without owner change | SessionAccessTest.php | FR-004, FR-007 |
| A09 | POST registration with forged role/access/owner fields | Stored student, no V3 access row; no privilege grant | AuthCompatibilityTest.php | FR-002, FR-011 |
| A10 | Password reset for suspended account; logout/relogin | Credential flow works; V3 remains denied; logout invalidates session | AuthCompatibilityTest.php | FR-002, FR-004 |
| A11 | Invalid credentials / throttled repeated login | Existing denial/throttle behavior preserved; no V3 grant | AuthCompatibilityTest.php | FR-002 |
| A12 | Authorizer database exception | 503 safe response; protected controller never executes | SessionAccessTest.php | FR-015 |
| A13 | V3 GET/error and mutation without CSRF | All V3 responses no-store; mutation 419 with no write when real CSRF check enabled | BoundaryHttpTest.php | FR-016 |

## Ownership and resource cases (test-only routes)

| ID | Given / action | Required assertion | Test file | Requirements |
|---|---|---|---|---|
| O01 | A reads A resource | 200 and A sentinel only | OwnershipPolicyTest.php | FR-005 |
| O02 | B reads or changes A resource | 404 and no A bytes; write count and stored value unchanged | OwnershipPolicyTest.php | FR-005, FR-006 |
| O03 | B requests absent ID versus A's existing ID | Same status and safe body; no existence leak | BoundaryHttpTest.php | FR-006 |
| O04 | Admin attempts view/update/review/download | Policy denies all four abilities; route denies before content delivery | OwnershipPolicyTest.php | FR-005 |
| O05 | Teacher has legacy TeacherPermission on another resource | No V3 ownership privilege is gained | OwnershipPolicyTest.php | FR-005, FR-009 |
| O06 | Caller substitutes parent or child ID | Denied even when both parents belong to caller; relation verified | BoundaryHttpTest.php | FR-006 |
| O07 | List has A and B records then paginate/count | Only A rows and A count; no global count leak | OwnershipPolicyTest.php | FR-005 |
| O08 | Client POST/PATCH includes owner_user_id | 422 on fixture request; owner remains trusted actor/stored owner | BoundaryHttpTest.php | FR-007 |
| O09 | Direct Eloquent owner change | Immutable-owner guard rejects and stored owner remains unchanged | OwnershipPolicyTest.php | FR-007 |
| O10 | Owner suspended/demoted then restored | Stored owner remains identical; policy denies until active teacher again | OwnershipPolicyTest.php | FR-004, FR-007 |
| O11 | Foreign download, malformed UUID, or reused download URL | No bytes streamed; same unavailable behavior for missing/foreign IDs | BoundaryHttpTest.php | FR-005, FR-006 |
| O12 | Fixture/public paths inspected outside tests | Fixture routes absent from production route list; no production case/scene models created | RouteBoundaryTest.php | FR-009, FR-016 |

## Admin projection cases

| ID | Given / action | Required assertion | Test file | Requirements |
|---|---|---|---|---|
| M01 | DTO with valid operational scalars | Serialized key set exactly equals contract allowlist | AdminProjectionTest.php | FR-008 |
| M02 | Input model/array has sentinel source, prompt, HTML, notes, paths and loaded relations | None present anywhere in response; no generic metadata copied | AdminProjectionTest.php | FR-008, FR-009 |
| M03 | Raw provider error or unknown state supplied | Fixed unknown code or validation failure; raw text absent | AdminProjectionTest.php | FR-008 |
| M04 | Admin workspace before cases exist | Honest empty 200 page; no query to nonexistent business tables or legacy lectures | RoleAccessTest.php | FR-009 |
| M05 | Admin account list | Only permitted account identity/access fields; no password, token or content relations | AccountEndpointTest.php | FR-009 |
| M06 | Teacher requests account list/operations | 403 and no account/operation rows returned | AccountEndpointTest.php | FR-001, FR-009 |

## Provisioning, integrity and audit cases

| ID | Given / action | Required assertion | Test file | Requirements |
|---|---|---|---|---|
| L01 | Migration over existing admin/teacher/student | Teacher/admin active rows and bootstrap events; student untouched; no user deletion | AccessSchemaTest.php | FR-001, FR-010 |
| L02 | Seed teacher/admin after migration, run bootstrap twice | Only missing rows/events added once; existing disabled row untouched | AccessSchemaTest.php | FR-010, FR-013 |
| L03 | Admin grants/suspends/restores target | Correct active/timestamp/version transition and one atomic safe event | AccountLifecycleTest.php | FR-010, FR-013 |
| L04 | Demote teacher to student, then promote via legacy role-only update | Row retained inactive; no owner transfer or implicit restoration | AccountLifecycleTest.php | FR-007, FR-010, FR-011 |
| L05 | Same desired state/current version, then stale repeat after mutation | Current no-op keeps version/event count; stale request 409 and no second mutation | AccountLifecycleTest.php | FR-010 |
| L06 | Invalid role, active student, negative version, bad reason or extra fields | 422 and no state/audit mutation | AccountEndpointTest.php | FR-010 |
| L07 | Self-disable/self-demotion/final-admin loss attempted | 409; at least one active admin remains | AccountLifecycleTest.php | FR-012 |
| L08 | Two admins concurrently suspend/demote each other | At most one success; reloaded actor/lock prevents zero active admins | tests/Integration/V3/Access/AccountConcurrencyTest.php (repo-relative) | FR-012 |
| L09 | Two updates use same target version | One success at most; stale loser cannot overwrite winner | tests/Integration/V3/Access/AccountConcurrencyTest.php (repo-relative) | FR-010, FR-012 |
| L10 | Suspended admin submits legacy `/users` role edit/create | Denied; cannot restore self or create a privileged bypass account | LegacyAccountBoundaryTest.php | FR-011 |
| L11 | Profile or registration tries role/access changes | No escalation; persisted role/access unchanged | AuthCompatibilityTest.php | FR-002, FR-011 |
| L12 | Admin DELETE `/users/{id}` and owner DELETE `/profile` for enrolled/demoted account | 409; account/access/history intact; profile denial occurs before logout | LegacyAccountBoundaryTest.php | FR-014 |
| L13 | Never-enrolled student deletes own account | Existing authorized deletion behavior remains | LegacyAccountBoundaryTest.php | FR-014 |
| L14 | Audit insert fails during account change | State/role/version roll back; 503; no success UI | AccessAuditTest.php | FR-013, FR-015 |
| L15 | Denied V3 request contains secret/sentinel URL/body values | One safe audit event; no payload/cookie/query/raw parameter copied | AccessAuditTest.php | FR-013 |
| L16 | Denial audit storage fails | Original denial remains; fixed fallback log contains no payload | AccessAuditTest.php | FR-013, FR-015 |
| L17 | Attempt deleting user directly through FK constraint | Retained access row prevents parent deletion; never cascade content/history | AccessSchemaTest.php | FR-014 |
| L18 | Existing account edit form/controller uses wrong/missing expected version | 422/409; direct legacy mutation cannot bypass service | LegacyAccountBoundaryTest.php | FR-010, FR-011 |
| L19 | Admin changes enrolled/demoted target email or password, including forged hidden-form input | 403; email/password hash unchanged; valid owner recovery remains usable | LegacyAccountBoundaryTest.php | FR-017 |

## Meaningful test setup

- Real login tests use credentials and sessions, not only `actingAs`. Route policy tests may use `actingAs` and then reload current state through the resolver.
- SQLite cannot store an unknown enum if constraints reject it; exercise the unsupported-role resolver branch through a controlled test double, not a production schema change.
- Do not disable authorization middleware globally. Laravel skips CSRF during normal unit tests; A13 must use a test-only subclass of the installed CSRF middleware that returns false from its unit-test bypass, bound only for that case.
- Concurrency tests run independent connections/processes against a disposable SQLite file; sequential calls inside `RefreshDatabase` do not prove L08/L09.
- Fixture downloads stream only synthetic bytes and never execute HTML. They prove access before streaming, not future scene sandbox behavior.
