# Feature Specification: V3 Admin Operations

**Feature:** `014-v3-admin-operations`
**Status:** Revised design; prerequisite implementation and live operation gates outstanding
**Authority:** [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), [V3 map](../V3-spec-map.md), [004 access](../004-v3-access-ownership/contracts/access.md), [007 workflow](../007-v3-workflow-progress/contracts/workflow.md), [013 review](../013-v3-teacher-review/contracts/review.md)

## Outcome and boundaries

An active pilot admin can see cross-case operational metadata, investigate delayed work and request bounded operational actions. Laravel authorizes and audits the request; Temporal controls a live workflow, while existing Laravel effect ledgers remain the authority for what may be reconciled. The admin cannot read teacher source, Section fields, prompts, scenes, previews, provider responses or review notes; cannot approve or reassign a review; and cannot make an uncertain provider send happen a second time. The active original teacher remains the only content owner and reviewer. 005 owns settings publication, 004 owns account role/access transitions, and 015 owns detailed history, export and physical retention execution.

### User stories

1. **Observe (P1):** An active admin filters current cases by fixed status/stage/error and age, sees 004's closed summary plus bounded safe operational counts, and checks a separately labeled live Temporal bridge probe. A teacher/student/inactive admin cannot use these routes. No educational content enters a response, log or UI.
2. **Intervene (P1):** An admin records a reason-coded hold, resume, cancellation or same-operation reconciliation request with expected versions and a case-scoped idempotency key. Concurrent teacher decisions, workflow signals and provider results cannot bypass a committed hold/cancel fence. An in-flight external call may finish; its known result remains private evidence and never silently restarts cancelled work.
3. **Remind and manage access (P2):** An admin can place one in-app reminder on a current dormant 013 review for its original teacher. Pilot account creation/access change continues through 004's existing service and `/users` identity form; disabling a teacher preserves their reviews and ownership. No admin can decide the review.
4. **Retention handoff (P2):** An admin can request a tracked case deletion assessment only under a fixed no-auto-delete pilot policy. Active review, unresolved effect, open workflow and retained evidence block physical deletion; 015 consumes the request and owns any later execution/export behavior. The request itself never deletes bytes or evidence.

## Functional requirements

| ID | Required behavior |
|---|---|
| FR-001 | Build an explicit, bounded admin operations projection from 004 ClosedSummary plus fixed 013 dormant/uncertain counts and 014 control state; filter and paginate without joining content tables into serialized models. A separate health response distinguishes a real Temporal bridge probe from backlog counts and unknown provider health. |
| FR-002 | Apply 004 active-admin authorization, no-store and fixed safe errors to every new route. Keep content, original filename, source/provider IDs, hashes, paths, raw errors and review notes out of admin responses, views, logs and later 015 export input. Protect existing owner/V2 routes from use as an alternate V3 content path. |
| FR-003 | Accept only exact `hold`, `resume`, `cancel` and `retry` operational actions under the state/version matrix in the [contract](contracts/admin-operations.md). A hold/cancel fence stops new effects and owner review mutations under the case lock; an already claimed external send remains uncertain or records only its known evidence. No command rewrites owner, captured settings or a typed JEV result. |
| FR-004 | Persist command, normalized request digest, fixed reason, actor, fence/outbox and safe audit atomically before any Temporal call. Same-key/same-body replay returns the same command; changed replay and stale/forbidden state conflict. Reconcile ambiguous delivery using the fixed command ID and workflow query/proof, never an untracked second workflow or provider send. |
| FR-005 | Define `retry` as reviewed reconciliation of an existing recoverable operation only: 007 fixed-ID start, 008 read-only source proof, 012 bounded read-only check/JEV attempt or 013 decision/correction outbox. A terminal blocked/failed/approved/cancelled case, exhausted budget, ambiguous 010/011 generator send and missing history have no generic retry. Return an explicit safe conflict with no state change. |
| FR-006 | Record an in-app reminder only for the Section-current dormant 013 item and deliver it to the original teacher's owner workspace after authorization. No email/impersonation or review reassignment. Repeated reminder for the same item is idempotent; account suspension does not erase it. |
| FR-007 | Use 004's `ChangeAccountAccess` and existing legacy `/users` creation integration for pilot teacher provisioning, disablement and restoration. Add regression coverage for inactive-admin `/users` bypass, original-owner persistence, self/last-admin guards and no credential rewrite; do not introduce a second account mutation service. |
| FR-008 | Keep a no-automatic-deletion pilot policy and record a deletion assessment/request with actor, case, eligibility snapshot and reason. Do not delete content, private evidence, workflow links or review decisions in 014. Active/unresolved cases are ineligible; 015 must recheck policy/lineage before any later physical deletion. |

## Acceptance and exclusions

[O01–O24](contracts/acceptance-matrix.md) map these requirements to planned executable tests and outcomes. **SC-001:** O01–O06 prove the admin metadata/privacy boundary on actual routes. **SC-002:** O07–O16 prove command state, racing, idempotency, delivery and same-effect reconciliation. **SC-003:** O17–O21 prove reminders and 004 account integration. **SC-004:** O22–O24 prove retention handoff and live Temporal/worker behavior. Fixture-only tests do not certify cancellation of a real in-flight activity or a live bridge.

This spec depends on implemented 004–007 and 013, with 008–012 transitively required by 013. It does not add a teacher-content route, admin export, new settings editor, student delivery, KOS/KIE, multitenancy or a replacement run for a terminal fixed 007 workflow. V2 remains a reference and an alternate-route regression target.
