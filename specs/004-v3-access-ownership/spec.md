# Feature Specification: V3 Access and Ownership

**ID**: 004-v3-access-ownership  
**Status**: Revised design; implementation pending  
**Created / reviewed**: 2026-10-01  
**Business authority**: [V3 context and flows](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), especially flows 1, 6, 7 and 8. Later owner corrections in the conversation take precedence over older charts.

## Purpose and completion boundary

Establish the access foundation for the teacher/admin V3 pilot. A teacher enters a private workspace and remains the sole owner and reviewer of their content. An admin manages access and reads operational metadata without educational content access.

004 delivers working V3 entry pages, access management for existing accounts, reusable ownership enforcement, an operational serializer, and executable authorization tests. It does not require PDF, case, scene or review tables from later specs. Future-resource behavior is demonstrated using test-only fixtures; production content endpoints arrive with their owning specs.

## User Scenarios & Testing

### User Story 1 — Enter the correct V3 workspace (Priority: P1)

As a provisioned teacher or admin, I want my existing sign-in to open the permitted V3 area and deny unavailable access immediately.

**Why this priority**: Every subsequent V3 operation depends on a trustworthy actor and current permission.

**Independent Test**: The real login and V3 entry routes work using teacher, admin, student, disabled and unprovisioned accounts; no content feature is installed.

**Acceptance Scenarios**:

1. **Given** an active provisioned teacher, **When** they visit V3, **Then** they reach the teacher workspace; an active provisioned admin reaches the admin workspace.
2. **Given** no session, **When** a browser requests V3, **Then** it is sent to sign-in; a JSON request receives an unauthenticated response.
3. **Given** a student, unprovisioned user or disabled V3 account, **When** they request a V3 area, **Then** the request is denied without data, even if credentials are valid.
4. **Given** public registration or password recovery, **When** completed, **Then** neither operation grants or restores V3 permission.
5. **Given** a role or active-state change, **When** an existing session makes its next V3 request, **Then** the new state applies without requiring the user to log out first.

### User Story 2 — Preserve original teacher ownership (Priority: P1)

As the original teacher, I want my content and decisions protected on reads and writes, including nested resources and downloads.

**Why this priority**: Session authentication alone does not protect one teacher from another.

**Independent Test**: A test-only owned-resource model and routes exercise the production ownership policy, query scope and error handling. This does not create future business tables or public placeholder routes.

**Acceptance Scenarios**:

1. **Given** teacher A owns a resource, **When** A views, changes, reviews or downloads it, **Then** the ownership check passes; teacher B receives the same unavailable result as a nonexistent resource.
2. **Given** a child under another parent, **When** a caller substitutes either identifier, **Then** the relationship check denies the request, including when both parents belong to that caller.
3. **Given** an admin or delegated legacy teacher permission, **When** that actor tries to read or approve another teacher's V3 content, **Then** there is no privileged ownership bypass.
4. **Given** a client supplies an owner identifier or changes an existing owner, **When** submitted, **Then** the server rejects the attempted ownership assignment and the persisted owner is unchanged.
5. **Given** the original teacher is disabled or changes role, **When** another person requests the pending review, **Then** the review remains with its original owner. Restoration permits the original teacher to return.

### User Story 3 — Expose only safe admin operations data (Priority: P1)

As an admin, I want a useful operational view without access to source material, prompts, scenes or teacher notes.

**Why this priority**: Existing Laravel admin content permissions are broader than V3 permits.

**Independent Test**: Serialize a synthetic operational record containing forbidden sentinel fields; only the exact allowed fields survive. The actual 004 admin page has an honest empty state until case processing exists.

**Acceptance Scenarios**:

1. **Given** an operational record, **When** serialized, **Then** only the fields in the access contract appear; unknown keys and nested content are absent.
2. **Given** a raw provider error, filename, hash, private URL or note, **When** an admin response is built, **Then** none can enter the response through a generic metadata field.
3. **Given** account management, **When** an admin opens its screen, **Then** the allowed account identity fields are available; this is not permission to inspect teacher educational content.

### User Story 4 — Manage access without bypasses or loss of ownership (Priority: P1)

As an active admin, I want audited grants, suspensions, restorations and role changes, with safeguards against stale edits and administrative lockout.

**Why this priority**: New access rules would be ineffective if existing user-edit/delete routes bypassed them.

**Independent Test**: Real account-management routes and the existing user/profile routes invoke the same access service. Concurrent and failed updates preserve a usable admin and an audit trail.

**Acceptance Scenarios**:

1. **Given** an eligible existing account, **When** an active admin submits a valid access change, **Then** the change and its audit event commit together.
2. **Given** a stale version or repeated submission, **When** submitted, **Then** no second mutation is made; the admin refreshes current state.
3. **Given** self-demotion, self-disablement or removal of the last active V3 admin, **When** attempted, **Then** the action is rejected, including concurrent attempts by different admins.
4. **Given** an account with a V3 access history, **When** either legacy admin deletion or self-deletion is attempted, **Then** the account and history remain; suspension is the permitted alternative.
5. **Given** failure to persist the required audit event, **When** a state change is attempted, **Then** the whole change rolls back and is not reported as successful.

### Edge Cases

Exact case IDs and expected responses are in the [acceptance matrix](contracts/acceptance-matrix.md). It includes expired sessions, current-role reload, malformed/foreign IDs, parent mismatch, owner tampering, fail-closed database errors, CSRF, cached responses, legacy account routes, concurrent edits and missing future models.

## Requirements

### Functional Requirements

- **FR-001 (US1)**: V3 access requires a valid session, a provisioned active V3 access record, and the current teacher or admin role. Missing or unsupported values deny access.
- **FR-002 (US1)**: Reuse existing login, logout, password reset and throttling; public registration remains student-only and never provisions V3 access. Email verification is not a new V3 prerequisite in this pilot.
- **FR-003 (US1)**: Provide role-appropriate V3 entry/workspace pages and exact browser/JSON denial behavior defined by the contract.
- **FR-004 (US1)**: Read effective role and active state on each new V3 request; session or cached actor state cannot preserve revoked access. Recovery does not reactivate V3.
- **FR-005 (US2)**: Restrict every V3 content query, read, mutation, review and download to the active original teacher, with no admin or legacy permission bypass.
- **FR-006 (US2)**: Verify parent-child membership as well as ownership and conceal foreign-resource existence using the same response as a missing resource.
- **FR-007 (US2)**: Assign ownership from trusted server context, keep it immutable and retain it through role changes, suspension and restoration.
- **FR-008 (US3)**: Build operational responses from an explicit field and code allowlist; never serialize an entire model, free-form error or arbitrary metadata object.
- **FR-009 (US3)**: Keep V3 resources out of legacy content routes and public storage; account management may expose its explicitly allowed identity fields only.
- **FR-010 (US4)**: Let active admins provision, suspend, restore and change existing accounts through one transaction service, using validated input and expected-version conflict detection.
- **FR-011 (US4)**: Apply the same service and safeguards to legacy account create/update paths; neither profile input nor legacy routes can escalate a caller or reenable a disabled admin.
- **FR-012 (US4)**: Reject self-disablement/self-demotion and any change that removes the final active V3 admin; concurrent operations preserve this invariant.
- **FR-013 (US4)**: Persist an immutable, content-free audit event with every access change and record V3 access denials without request payloads or secrets.
- **FR-014 (US4)**: Prevent application account deletion once a V3 access record exists, including after demotion to student; keep ownership and pending-review references intact.
- **FR-015 (US4)**: Failed authorization data reads deny access; failed mutation/audit writes roll back. No failed change is reported as complete.
- **FR-016 (US1–US4)**: Apply session/CSRF protections and private no-store responses; prove behavior through real HTTP routes plus test-only future-resource fixtures.
- **FR-017 (US4)**: Once an account is enrolled in V3, admin account-edit routes cannot replace its email or password, including after demotion. Credential maintenance uses the account holder's existing authenticated/recovery flow; admin access management must not provide a credential-takeover shortcut.

### Key Entities

- Existing **User**: Identity and authoritative role shared with the application.
- **V3AccountAccess**: Provisioning latch, active state and update version, retained after demotion.
- **V3AccessEvent**: Immutable safe account-change or denial event.
- **V3OwnedResource**: Contract implemented by later resource models; 004 supplies reusable enforcement and fixtures.
- **OperationSummaryData**: Closed operational projection independent of future case tables.

## Success Criteria

- **SC-001**: Every case in the acceptance matrix passes with the stated status, body shape and persistence effect; no skipped authorization case counts as complete.
- **SC-002**: Teacher B and admin cannot obtain teacher A's sentinel content through tested read/write/nested/download paths.
- **SC-003**: An already-authenticated user's next request reflects suspension, restoration or role change.
- **SC-004**: Concurrent account changes retain at least one active admin; an audit failure leaves the access state and version unchanged.
- **SC-005**: 004 can be installed and tested without implementing 005–015, Python, Temporal or external services.

## Scope, assumptions and decisions

- Existing teacher/admin accounts are provisioned once during the new migration; existing students are not. No new default admin credentials are created.
- “Disabled” means V3 access suspended. Shared Laravel identity, logout and account recovery remain usable. Unrelated legacy application access is not globally redesigned.
- Successful credentials satisfy the current identity check. The repository does not implement a functioning email-verification requirement; enabling it is separate work.
- 004 implements basic account access controls and services; 014 may extend their management UI, never duplicate or bypass their rules.
- Future workers receive separate service authorization in their own specs. An admin session is not a worker credential.
- No owner transfer, hard deletion of enrolled accounts, student V3 delivery, multitenancy or production future-resource endpoints are added here.
- Existing identity credentials and initial provisioning are trusted. 004 prevents role-authorized account-edit shortcuts; it does not claim protection against stolen credentials or database/server operators. A separate invitation/onboarding system is not introduced.
