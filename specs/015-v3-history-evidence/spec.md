# Feature Specification: V3 History, Evidence and Pilot Metrics

**Feature Branch**: `015-v3-history-evidence`  
**Status**: Reviewed design; application implementation has not begun  
**Authority**: [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), later owner corrections recorded in the [V3 map](../V3-spec-map.md), and the 004–014 contracts.

## Scope and user outcomes

015 is the read and export layer over the immutable records produced by 004–014. The original active teacher can trace their own case, including failed branches, historical approvals and the current approval. An active admin can see only operational case IDs, fixed statuses and aggregate timing/usage/cost facts. Pilot metrics must answer how long an approved scene took and what processing cost is *known*, while exposing gaps instead of inventing precision. No route in 015 approves, regenerates, publishes or deletes an educational artifact.

The pilot retains all referenced source, prompt, scene, note and evidence bytes under `pilot-retain-v1`. 014 deletion assessments remain requests for a later approved policy; they do not authorize purge. Only 015's temporary export snapshots expire. Student delivery, investor presentation, KOS/KIE, multitenancy and V2 data migration are outside scope.

## Requirements

| ID | Requirement |
|---|---|
| FR-001 | Project a case's exact existing 006→014 record graph, including settings, document, 008 extraction/binding, 009 map/Section, 010 prompt/JEV, 011 coding, 012 checks/JEV/repairs, 013 review/current and historical approval, 007 events and 014 operations. A link is valid only if all rows belong to the same immutable case and required parent identities match. Do not infer a link by timestamps, titles or latest-version lookup. |
| FR-002 | Include committed failed, blocked, uncertain, superseded and revised branches without overwriting evidence. Mark missing or corrupt required referenced rows/files as a typed gap. A chain with a gap is `incomplete`; it cannot be reported as certified approval evidence. Existing 013 approval state is not silently revoked by a reporting failure. |
| FR-003 | Expose owner-only case history and an approval-specific trace through fresh 004 original-owner checks. Scope parent case before child lookup. Existing 006 document, 009 Section, 013 review and legacy V2 routes must not become alternate ways to read another teacher's V3 content. |
| FR-004 | Derive period and case metrics from committed DB times/attempts: case elapsed, 007 stage residence, measured operation intervals, 013 review wait, technical retries, revisions, current/historical approval counts and outcomes. Label each duration's definition; an absent boundary is unknown. Do not call review wait active human work or stage residence provider execution time. |
| FR-005 | Report usage and monetary cost per attempt and per case with provenance `provider_reported|derived|unknown`; monetary values are `actual|estimated|unknown` with currency and basis. A missing required provider charge makes full case cost and cost per current approved scene unknown, while showing a known subtotal and missing-component count. A numeric zero requires an explicit observed zero. The pilot does not generate price estimates without an approved versioned rate basis. |
| FR-006 | Export owner case evidence and admin metrics as separately allowlisted, private JSON snapshots. Every creation and download rechecks current 004 access; a teacher export is bound to its original owner, an admin export to the requesting admin. Admin bytes contain no teacher identity, filename, hash, notebook/source ID, Section/prompt/scene/review-note body or private path. Teacher snapshots contain references and safe decisions, not embedded PDF, prompt, scene, provider answer or note bytes. |
| FR-007 | Make export creation idempotent by actor/key/request digest, retain an immutable generation/download audit with fixed codes, expire snapshot downloads after 15 minutes and purge expired export files after 24 hours. A partial file, failed audit/DB commit, corrupt download or changed replay never produces a successful export. A successful generation audit proves generation, not receipt by the client. |
| FR-008 | Apply `pilot-retain-v1`: retain all referenced educational/evidence artifacts and unresolved effects; do not add a physical deletion path. History and exports distinguish `available`, `missing_or_corrupt` and `not_applicable` content references. They never claim an artifact was intentionally deleted merely because a file is missing. Preserve 014 deletion-assessment status as operational history only. |

## Success criteria and acceptance

The executable acceptance matrix is [H01–H20](contracts/acceptance-matrix.md). **SC-001**: H01–H07 prove complete and broken lineage, historical branches and owner isolation through real HTTP routes. **SC-002**: H08–H13 prove exact metric boundaries, unknown versus zero, denominator and duplicate/replay behavior against prerequisite rows. **SC-003**: H14–H20 prove generated export bytes, redaction, expiry, authorization at both steps and retention behavior. These are implementation targets, not test results from this documentation review.

## Fixed interpretation

- A case created by `process_again` is a separate cost/time unit even if it reuses the same 006 Document. Do not charge or count a shared document's earlier case work again.
- `current_approved_scene_count` is the number of 013 Section current-approval pointers at the report snapshot, not the count of all immutable approval rows. Historical approvals remain traceable after correction or 014 cancellation.
- `cost_per_current_approved_scene` is a clearly labeled *case allocation*: complete known case monetary total divided by current approvals for that case. The period-wide pilot value is the sum of full costs of **all cases created in the period, including failed/cancelled cases**, divided by the period's current approvals, only when every included case has a complete same-currency cost. This captures unsuccessful work spent to obtain approved scenes. It is not an invoice or a marginal per-Section charge. If the denominator is zero or any included required cost is unknown, the result is null with reason; known subtotals remain separate.
- The canonical 009 prompts and all eleven First-step Section fields remain untouched. NotebookLM is the source-grounded mapping/research provider; successful typed JEV Choice, Score and Noul decisions are consumed directly. 015 reads their evidence only.

## Material open policy

The owner has not approved a physical retention duration, deletion scope, evidence hold procedure or monetary estimate tariff. `pilot-retain-v1` therefore has no artifact purge; cost estimates remain unavailable unless a future approved versioned basis is supplied. These choices are recorded in the [review report](review-report.md) and do not authorize an implementer to invent them.
