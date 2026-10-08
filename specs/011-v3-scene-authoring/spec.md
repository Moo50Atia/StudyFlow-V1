# Feature Specification: V3 Scene Authoring

**Feature**: `011-v3-scene-authoring`

**Status**: Reviewed design; implementation and live provider verification remain outstanding
**Authority**: [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), [V3 map](../V3-spec-map.md), [010 handoff](../010-v3-prompt-jev-qualification/contracts/integration-obligations.md)

## Boundary and user outcomes

011 turns one **current, passing** 010 scene prompt into a privately stored, immutable, **untrusted** scene candidate. The captured 005 scene template and API coding order govern authoring. 012 owns browser execution, safety/interaction checks, JEV scene qualification and repair; 013 owns original-teacher review. Candidate collection is never a scene pass or a teacher-visible preview. Original teacher ownership follows `v3_processing_cases.owner_user_id`; admin sees only the 004 closed operational projection.

### Stories

1. **Qualified handoff (P1)**: The original teacher's current qualified Section prompt is the only authoring input. Missing, foreign, failed and superseded versions cannot start a provider call.
2. **Private candidate (P1)**: A completed API response produces a bounded file package, immutable manifest and lineage on `v3_private`, without executing generated code.
3. **Bounded fallback (P1)**: A known failed provider may yield to the next captured provider. Unknown post-send outcomes stop automatic calls and await 014 reviewed recovery.
4. **Operational privacy (P1)**: Teacher status is owner scoped; admin counts remain closed scalars. Neither V2 content routes nor a public disk expose candidates.

## Requirements

| ID | Requirement |
|---|---|
| FR-001 | Under a case/Section transaction, accept only 010's same-case, current, passing prompt and immutable qualification; recheck before provider send and candidate commit. |
| FR-002 | Bind request to case, Section, prompt version/body digest, qualification, captured settings version/checksum, approved scene template ID/digest and fixed authoring key. Do not send raw PDF/eleven Section fields to the coding provider. |
| FR-003 | Use only 005's captured `providers.coding_order` API descriptors. Send exact approved scene template bytes as system instruction and the exact qualified prompt plus fixed output contract as one user JSON message. No production CLI/subscription adapter. |
| FR-004 | Permit at most `min(limits.coding_attempts, count(coding_order))` distinct provider attempt reservations for an initial request, in captured order; a missing credential consumes its reserved slot but makes no HTTP call. A provider is never automatically resent after reservation. Fallback follows only a proved known failure; an uncertain outcome halts the request. |
| FR-005 | Accept only a bounded UTF-8 `ScenePackageV1` with `index.html` and declared relative text assets; reject path escape, symlinks, duplicate paths, missing/empty entrypoint and oversize output before any candidate is committed. Do not execute or certify the package in 011. |
| FR-006 | Persist append-only attempt evidence and an immutable candidate manifest with file digests, prompt/Section/attempt lineage and private storage path. One request creates at most one candidate; a retry may replay only the exact result. |
| FR-007 | Keep uncertain sends and partial storage/DB failures recoverable without fabricating a candidate or issuing a second provider send. 014 owns reviewed resolution of truly unknown outcomes. |
| FR-008 | Restrict signed internal operations to 007 HMAC and case scope; restrict teacher status to the active original owner via 004; admin receives only safe stage/status/error and counts. No public scene delivery in 011. |
| FR-009 | Emit only proof-backed 007 `scene_generation` milestones. A blocked Section does not fail a case with other pending/ready Sections; no event claims scene qualification or review. |

## Acceptance and completion

The [acceptance matrix](contracts/acceptance-matrix.md) defines A01–A17, expected results and executable test files. **SC-001**: invalid prompt handoffs and stale races cause zero new provider sends/candidates (A01–A04). **SC-002**: every collected candidate has a verified private manifest and exact lineage, and 012 sees only an untrusted candidate (A05–A09). **SC-003**: fallback, budget, duplicate, uncertain and partial failure outcomes are deterministic (A10–A14). **SC-004**: actual signed/owner/admin/V2 boundaries hold (A15–A17). Fixture adapter tests prove the contract only; a deployment gate must call the configured live API model and collect a real candidate before claiming live integration.

## Scope and prerequisites

004–005, 006–010 and their migrations/routes must be implemented, especially 005 captured settings/template repository, 007 signed bridge/workflow, 008 `v3_private`, 009 Section ownership and 010 `QualifiedPromptResolver`. [Downstream obligations](contracts/integration-obligations.md) distinguish this collection proof from 012 scene checks/repair and 013 teacher review; they are not prerequisites. V2 material is read-only reference; KOS/KIE and multitenancy are outside this feature. This documentation review does not assert that prerequisites or runtime behavior are present today.
