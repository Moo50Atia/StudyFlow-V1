# Feature Specification: V3 Scene Qualification and Repair

**Feature:** `012-v3-scene-qualification`
**Status:** Reviewed design; application and live browser/JEV gates outstanding
**Authority:** [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), [V3 map](../V3-spec-map.md), [011 handoff](../011-v3-scene-authoring/contracts/integration-obligations.md)

## Outcome and boundary

011 supplies a private, immutable **untrusted** candidate. 012 rechecks its current 010 passing prompt, runs its files in a restricted browser, records deterministic evidence, then applies successful typed JEV Choice/Score/Noul answers directly under the case-captured 005 scene rule. A deterministic failure never becomes a JEV pass. A valid nonpass can produce a new 011 authoring request within the captured repair budget; prior candidates remain immutable. Only a current candidate with both gates passed is eligible for 013's **original-teacher** review. 012 does not create a 013 review item, expose a browser scene to the teacher, approve content, or add an admin content route.

### User stories

1. **Executable check (P1):** A teacher's candidate runs with no application credentials or external network access, and unsafe/broken/noninteractive output cannot reach review.
2. **Source-grounded decision (P1):** The exact qualified prompt, all eleven preserved Section fields, candidate files and textual browser observations form a private evidence bundle for JEV. Its three typed answers determine the scene rule with no extra suitability model.
3. **Bounded correction (P1):** A deterministic or correctable JEV failure can create one new candidate linked to its predecessor, under `limits.scene_repairs`; every new candidate starts both checks from the beginning.
4. **Private handoff (P1):** The owner sees safe status, admin sees only 004 operational metadata, and 013 can resolve a current passing qualification without treating a candidate ref as authority.

## Functional requirements

| ID | Required behavior |
|---|---|
| FR-001 | Claim only a same-case 011 candidate whose manifest/files rehash, 010 prompt remains current and passing, and settings version equals the case's captured 005 version. Recheck before results, repair and 013 eligibility. |
| FR-002 | Execute candidate only in a nonroot, resource-limited, network-isolated browser container without app secrets, host mounts or Docker socket. Fail closed if this boundary is unavailable. |
| FR-003 | Record versioned deterministic results for entrypoint/asset load, browser/runtime errors, prohibited network/popup/download behavior, observable control response and keyboard/accessibility basics. Separate a scene failure from checker infrastructure failure. |
| FR-004 | Build private evidence for all eleven exact 009 Section fields and the current qualified prompt without silent truncation; submit fixed Choice, Score and Noul questions through the 010 JEV transport and apply 005's captured scene thresholds directly, including Noul yes-probability as its yes confidence. |
| FR-005 | Produce at most one immutable overall qualification per candidate. A pass requires both deterministic pass and successful typed JEV pass; valid nonpass, technical unavailable and stale are distinct. No second model judges a successful JEV answer. |
| FR-006 | For a repairable failure, reserve at most `limits.scene_repairs` ordinal slots per initial candidate chain; create a new 011 repair-origin request using the **same** prompt/settings/template and previous candidate plus fixed failed criteria. Never overwrite or branch a candidate; Choice=`block` is terminal. |
| FR-007 | Lock claims/results/repair slots against duplicates and real concurrent requests. Retry only idempotent reads/results or read-only technical JEV/browser activity under captured 007 policy; never repeat an uncertain 011 coding send or consume a repair slot for infrastructure outage. Recover storage/DB partial failures without false pass. |
| FR-008 | Require 007 HMAC on internal routes and 004 active original-owner checks on status. Keep prompt, Section fields, candidate code, browser traces, screenshot, JEV answers and review notes out of admin DTOs, public/V2 routes, logs and Temporal history. Retain referenced/uncertain evidence for pilot and 015. |
| FR-009 | Emit 007 `scene_qualification` milestones only with persisted proof. Supply a current passing eligibility resolver to 013; do not create a review item/signal or change `approved_scene_count` in 012. A blocked Section must not fail another active Section. |

## Acceptance and success

[S01–S23](contracts/acceptance-matrix.md) specify expected outcomes and planned test files. **SC-001:** S01–S12 prove no stale, unsafe, failed or unavailable candidate is eligible for review. **SC-002:** S09–S13 and S21 prove each eligible candidate has exact lineage, private browser evidence and direct typed JEV values. **SC-003:** S14–S19 prove one linear, bounded repair chain and safe duplicate/concurrent/fault handling. **SC-004:** S20–S23 prove real route boundaries, event provenance and a container plus selected live JEV gate. Fixture doubles alone do not certify live integration or a safe container.

## Dependencies and exclusions

Requires implemented 004–005, 007–011, especially 008 `v3_private`, 009 eleven-field Section, 010 current-pass resolver/JEV transport and 011 signed private candidate resolver/authoring ledger. 013–015 are consumers, not prerequisites; their [obligations](contracts/integration-obligations.md) are separate. V2 scenes are read-only references. KOS/KIE, multitenancy and student delivery remain future work. This documentation review does not assert any V3 application code exists today.
