# Spec 012 Documentation Review Report

**Scope:** `specs/012-v3-scene-qualification/` only. This is a review of a proposed design, not evidence that the feature runs. The prior Spec Kit selector remained `specs/004-v3-access-ownership`; it was not changed for this review.

## Authority and repository evidence

I read [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), the [V3 map](../V3-spec-map.md), all pre-edit 012 artifacts, the applicable repository instructions, 004's access contract, 005's settings contract, 007's workflow contract, 009's Section contract/model, 010's typed JEV contract, 011's candidate contract/model and 013–015 downstream contracts. I also inspected current Laravel routes, controllers/models, migrations, tests, `composer.json`, `package.json`, `phpunit.xml` and filesystem config. The working application is V2 Laravel; V3 004–011 code, Python worker, Playwright checker and 012 migrations/routes/tests are not present. The 011 documentation supplies a private immutable candidate but only an `initial` authoring origin. The V2 `DynamicViewModel` stores mutable HTML, current V2 routes expose Sections/lectures, and the configured local/public disks have URL serving behavior; none is a safe V3 scene execution or delivery path. Routine PHPUnit uses SQLite memory, which cannot prove a two-process repair race.

I used the `speckit-analyze` read-only consistency method before remediation, including its prerequisite script and a semantic pass over the 012 artifacts. Its current feature selector pointed to 004, so the pass targeted 012 directly without changing the selector. No extension hook file was present. The owner explicitly authorized fixing findings within 012.

## Initial findings

| ID | Severity | Finding and repository evidence | Resolution |
|---|---|---|---|
| F01 | Critical | Original 012 repair prose assumed a new candidate could be requested, but 011's `v3_authoring_requests.origin` and signed request contract allowed only `initial`; no repair lineage or creation transaction existed. | Defined `repair_1`…`repair_10`, a bounded predecessor ledger, an extension of 011's guarded service/adapter and one transaction that links the repair row to its new authoring request. |
| F02 | High | “Isolated browser” was not a security boundary: there was no runtime policy for sandbox, egress, mounts, credentials, resources, local assets or hostile behavior. No Playwright/container dependency exists in current `package.json` or worker tree. | Specified a separate pinned Chromium container, nonroot sandbox, OCI restrictions, local-only static serving, seven deterministic checks, bounded report/PNG and a real hostile-container gate. |
| F03 | High | Scene JEV questions/evidence and the direct Choice/Score/Noul rule were not a reproducible contract; complete 009 field coverage and the later Noul yes-probability correction could be lost. | Defined all eleven ordered fields, exact private evidence/locator hints, fixed named questions, no silent truncation and Laravel's inclusive unrounded typed rule. A valid low answer remains a verdict; malformed wire is technical. |
| F04 | High | Schema, signed operations, states, replay, claim races, storage/DB partial failure and technical versus scene failure were left to an implementer. | Added exact table fields/constraints, routes/payloads/errors, attempt and definitive-result separation, locks, digests, immutable evidence, bounded retries, recovery and state transitions. |
| F05 | High | The owner/admin boundary and 013 review handoff lacked a proven gate; a candidate or stale pass could have been treated as review authority, including through existing V2/public paths. | Added 004 owner-scoped safe status, 007 HMAC routes, current-tip passing eligibility, V2/public/admin denial cases and proof-backed milestones. 013 still owns viewer, original-teacher decision and ReviewGate signal. |
| F06 | High | Original tasks did not put meaningful behavior tests before the checker/schema/rule work and did not distinguish mocked from real browser/JEV or SQLite from shared-DB concurrency evidence. | Reordered T001–T018 into behavioral red/green groups, with actual container, selected live JEV, HMAC boundary, two-process DB race, replay and prerequisite regressions. |
| F07 | Medium | The execution notes, quickstart and checklist did not state exact stop conditions, planned commands, known V2 traps, report contents or which evidence was still unproved. | Wrote the direct execution plan, repository notes, future-only verification commands and documentation-versus-runtime checklist. |

## Files edited and why

| File | Substantive edit / need |
|---|---|
| [spec.md](spec.md) | Reframed the 012 boundary, nine testable FRs, four user stories and four evidence-backed success criteria so 013 review is not implied. |
| [plan.md](plan.md) | Mapped new versus extended paths to the actual V2 repository and prerequisite contracts; defined Laravel, Python and Temporal ownership plus real gates. |
| [research.md](research.md) | Recorded inspected code/contract evidence and [official Playwright Docker guidance](https://playwright.dev/python/docs/docker), with deployment assumptions explicitly unproved. |
| [data-model.md](data-model.md) | Specified candidate/check/JEV/qualification/repair tables, immutable lineage, FK/unique constraints, state, private artifacts and a creation transaction that avoids a repair/request FK cycle. |
| [contracts/scene-qualification.md](contracts/scene-qualification.md) | Defined browser policy/check report, evidence and questions, direct typed rule, signed routes, error/replay/technical recovery, repair criteria/authoring extension, owner status and 013 eligibility. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | Added S01–S23 with requirements, planned test files and observable expected outcomes. |
| [contracts/integration-obligations.md](contracts/integration-obligations.md) | Made 013–015 consumers and their unproved responsibilities explicit; 012 supplies an opaque signed proof only. |
| [tasks.md](tasks.md) | Added T001–T018 with paths, dependencies, FR/scenario mapping and concrete completion checks, including meaningful red phases. |
| [task-execution-plan.md](task-execution-plan.md) | Made the ordered task groups, before/after commands, checkpoints, baseline handling, stop rules and required implementation report executable. |
| [implementation-notes.md](implementation-notes.md) | Captured repository-specific V2/public-storage, 011-origin, JEV and 013 boundary traps without repeating the runbook. |
| [quickstart.md](quickstart.md) | Labeled future commands, shared PostgreSQL and opt-in live JEV conditions, expected observations and no-skip rule; no command is represented as run here. |
| [checklists/requirements.md](checklists/requirements.md) | Separated completed documentation checks from open application/live and downstream gates. |
| [review-report.md](review-report.md) | Records findings, decisions, coverage, final checks and remaining risks. |

## Design choices and rationale

- **Two conjunctive gates:** 011 collection is untrusted; a definitive browser fail never calls JEV. A technical checker/JEV outage has no qualification or repair-slot effect. This follows Conversation 23's safe execution then JEV then original-teacher sequence.
- **Direct typed JEV:** The 005 captured scene thresholds apply to Choice, Score and Noul exactly as in 010. Noul `≥0.5` means yes and its probability is yes confidence; no additional model judges a successful response. The full eleven-field textual evidence and fixed questions remain private.
- **Repair accounting:** Reserve a unique linear `repair_N` slot before 011 generation, without automatic recycling after a known or uncertain authoring result. This caps the number of repaired candidates under `scene_repairs`, avoids duplicate sends and preserves a reviewable predecessor chain. The nullable repair authoring FK exists only inside the transaction that creates both rows; no incomplete repair is committed.
- **Browser trust boundary:** A dedicated container runs one attempt at a time with local-only assets and no network/host secrets. Playwright routing reports attempts; OCI isolation enforces denial. These are planned controls requiring real container evidence, not a claim about the current machine.
- **013 proof boundary:** A passing current candidate is eligible for original-teacher review only after 013 independently checks ownership and supplies its restricted viewer/decision. 012 creates no review rows, notes, approval or signal. Admin receives only 004-safe operational metadata.

## Coverage and final read-only checks

| Coverage | Result |
|---|---|
| Requirements | FR-001–FR-009 each have at least one S01–S23 case and at least one T001–T018 task. |
| Acceptance cases | S01–S23 each name a planned PHP or Python test file and expected outcome; the task table references all 23 cases. |
| Buildable success criteria | SC-001–SC-004 map to the browser/JEV, repair/concurrency, route security and live evidence gates. |
| Ordering | T001–T018 are sequential with stated dependencies; each new behavior's test task precedes its implementation task. Existing behavior receives regression coverage. |
| Contracts | Check claim returns an attempt ref; definitive result creates the check ref. A valid typed JEV answer creates the single qualification; malformed/transport failure does not. Repair criteria, request origin and current-tip handoff match the model and tasks. |
| Links/placeholders | All local Markdown links in 012 resolved; no unresolved placeholder or trailing whitespace remained in the final read-only pass. |
| Scope/selector | Other numbered specs and application code were not edited. The preexisting Spec Kit feature selector remained 004. Preexisting unrelated workspace modifications were preserved. |

The final pass was a **documentation consistency check**, including semantic route/state/field/test review and read-only coverage/link checks. It did not compile or execute application code, run PHPUnit/pytest, start a browser container or contact JEV. No runtime behavior is certified.

## Outstanding prerequisites and risks

1. The documented 004–011 prerequisites must actually be implemented and verified before T001 can proceed. Today's repository lacks those V3 runtime paths. If their delivered names or contracts differ, the implementer must reconcile that difference without weakening the approved boundary.
2. The target OCI environment must start Chromium under all specified sandbox and isolation controls. Official Playwright documentation warns that its default root image disables the Chromium sandbox; container tests must show the effective runtime policy and blocked hostile egress. If it cannot, 012 remains incomplete.
3. A captured 005 configuration, compatible approved scene template, current 011 candidate and selected live TypeSafe credentials/model are needed for S23. A fixture is not live proof. Complete textual evidence might exceed the 6 MiB cap or actual model input capacity; stop with `evidence_limit` and record the observed blocker rather than truncate or substitute a model.
4. 013 original-teacher review/viewer, 014 reviewed operator recovery and 015 deletion/export remain downstream work. Their absence does not authorize production placeholders in 012; it also means automated 012 qualification alone cannot be called teacher approval.

No unresolved owner business choice was identified for 012 from the inspected authority. **Application implementation and tests were not performed during this documentation review.** Spec 012 is a concrete proposed execution plan, with mandatory prerequisite and live verification gates still open.
