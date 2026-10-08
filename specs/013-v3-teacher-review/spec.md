# Feature Specification: V3 Original-Teacher Scene Review

**Feature:** `013-v3-teacher-review`
**Status:** Reviewed design; prerequisite implementation and live viewer/workflow gates outstanding
**Authority:** [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), [V3 map](../V3-spec-map.md), [012 handoff](../012-v3-scene-qualification/contracts/integration-obligations.md)

## Outcome and boundary

For each 009 Section, 013 creates one current review item only after 012 proves that its current 011 candidate passed browser and typed JEV gates. The active original teacher views the **actual interactive scene** beside the exact current qualified prompt and all eleven Section fields, then approves that candidate version or requests changes. Laravel owns owner checks, private notes, immutable decisions, current approval pointers and a durable signal outbox. Temporal waits without occupying an activity worker and routes committed decisions; neither its history nor admin metadata contains content or notes. A qualified scene is not an approved scene. Approval is version-specific and is not student publication.

### User stories

1. **Review (P1):** The original active teacher can open each current qualified Section review and interact with the scene through an isolated preview. Missing, foreign, stale, unqualified and other-role requests reveal no content.
2. **Decide (P1):** The teacher can approve after a successful preview or submit bounded private notes with an explicit feedback category. Duplicate submission has one decision, one approval or route and one eventual Temporal consumption; stale submission cannot approve an old candidate.
3. **Revise (P1):** Visual/interaction feedback spends the case-captured 012 scene-repair budget and retains the exact qualified prompt. Factual/source/mixed feedback spends the case-captured 010 prompt-revision budget, creates a new prompt version, reruns prompt JEV, then 011–012 and teacher review. The teacher never approves an intermediate prompt.
4. **Return (P1):** A teacher who is away sees a durable pending/dormant review on return. A later correction request on an approved scene starts a new version while preserving the earlier approval as history, including after the initial case workflow has closed.

## Functional requirements

| ID | Required behavior |
|---|---|
| FR-001 | Create a per-Section review item only from 012's current passing eligibility proof, preserve candidate/version/qualification and recheck current proof at open and decision. Reconcile a lost 012 handoff without duplicate items. |
| FR-002 | Apply 004 active original-owner authorization to list, prompt/Section context, preview frames/actions, decisions and later corrections. Keep generated HTML/JS out of the authenticated browser and all V2/public/admin content routes. |
| FR-003 | Provide an actual interactive preview in an ephemeral restricted browser container using 011's rehashed package and 012's no-network/no-secret policy; return only PNG and escaped control/text observations to the owner. An unavailable preview cannot be approved. |
| FR-004 | Record one immutable decision per review item using candidate/version, expected state version and a case-scoped idempotency key. Approval requires a successful preview, atomically records an immutable approval and updates only that Section's current approval pointer. |
| FR-005 | Require nonempty private notes and an explicit `visual_interaction|factual_source|mixed` category for change requests; `mixed` follows the factual path. No model classifies notes. Route only from a committed decision and keep notes out of admin DTOs, events and Temporal history. |
| FR-006 | Extend 012's guarded linear `repair_N` claim for a teacher visual route, within the same captured scene-repair budget. Reuse the previous candidate, exact prompt/settings/template and note as untrusted repair data; re-run 012 gates and create a new review item before any approval. |
| FR-007 | Extend 010 with a teacher-correction effect for a factual/mixed route, within the same captured prompt-revision budget. Reuse the captured approved feedback template/provider and all eleven Section fields, retain the note privately, create a new prompt version only from one known provider response, and require fresh 010 JEV→011→012→review. No uncertain generator send is repeated automatically. |
| FR-008 | Persist a decision/effect outbox before any Temporal call; deliver exactly one logical decision to the matching per-Section 007 ReviewGate, reconcile lost acknowledgements and reject stale/mismatched signals. Every post-approval correction starts a separate deterministic Temporal workflow, including when the original run is closed. |
| FR-009 | Mark pending reviews dormant after captured `review_dormancy_days` without auto-approval or owner reassignment. Keep per-Section approved/current counts and proof-backed 007 case events correct across multiple Sections and later corrections. |
| FR-010 | Retain immutable review, note, preview-session outcome, routing, provider-effect, approval and signal evidence privately for pilot/015; expose only 004-safe operational status/count/error to admin and fixed redacted audit/log records. |

## Acceptance and completion

[R01–R26](contracts/acceptance-matrix.md) map every requirement to planned executable tests and expected results. **SC-001:** R01–R10 prove that only the active original owner can view and decide on a current 012 pass and that the preview does not execute scene code in the application browser. **SC-002:** R11–R16 prove version-bound, idempotent decisions, private notes and one current approval per Section. **SC-003:** R17–R22 prove visual/factual/mixed budgeted loops and renewal through qualification before re-review. **SC-004:** R23–R26 prove durable signal/reconciliation, dormancy, post-approval correction and real preview/Temporal behavior. Fixture-only tests do not certify the live container or Temporal handoff.

## Dependencies and exclusions

Requires implemented 004–005, 006–012: original case owner, captured settings, Section fields, current prompt, immutable candidate, 012 eligibility, 007 HMAC/ReviewGate and `v3_private`. 013 adds narrowly scoped extension points in 010/011/012 and 007, identified in the [contract](contracts/review.md); it does not edit their documentation here. 014 owns later admin reminders/cancellation and reviewed recovery; 015 owns history/export/retention deletion. V2 material is reference-only; KOS/KIE, multitenancy and student delivery remain future work. This documentation review does not assert any V3 application code exists today.
