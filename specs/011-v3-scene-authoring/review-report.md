# Review Report: V3 Spec 011 Scene Authoring

**Review type:** documentation and specification only. **Selected directory:** `specs/011-v3-scene-authoring/`. **Result:** contracts and execution plan strengthened; application implementation and mandatory runtime gates remain outstanding.

## Scope and authority inspected

Read the repository instructions (`.agents/AGENTS.md`, scoped to that directory), [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), [V3 map](../V3-spec-map.md), every original 011 artifact, 004 as an analysis-depth reference, 005/007/008/009/010 prerequisite contracts, and 012/013/014/015 directly affected contracts. The `speckit-analyze` skill was applied as a **read-only semantic pass** before remediation. Its check-prerequisites script pointed to Spec 004 through the preexisting `.specify/feature.json`; the selector was not changed, and the analysis was performed directly against 011. There was no `.specify/extensions.yml` hook.

Repository checks covered `routes/web.php`, `SectionController`, `DynamicViewModel`, current migrations/models/tests, `config/filesystems.php`, `package.json`, existing auth/route setup and the absence of `v3_engine/`, `routes/api.php`, V3 models/migrations/tests. The current application is V2 Laravel: `/sections` is a V2 route, `DynamicViewModel` has mutable `html_content`, Laravel `local` has `serve => true`, and `package.json` has no Three.js/V3 runtime package. These are **not** reusable V3 authoring foundations. 004–010 are documented prerequisites, not application code present at review time.

## Initial read-only findings

| ID / severity | Finding and evidence | Why it mattered / resolution |
|---|---|---|
| F01 Critical | Original 011 plan/research proposed a possible CLI coding agent, while [005 settings](../005-v3-versioned-settings/contracts/settings.md) permits only `gemini_api`, `glm_api`, `deepseek_api` in captured `coding_order`. | A production agent could invent a fourth provider, credentials and policy. 011 now uses only the captured API descriptors and a separate live gate. |
| F02 High | Original `author(...)` accepted opaque refs with no exact case/Section/current-pass/settings/template checks. [010 handoff](../010-v3-prompt-jev-qualification/contracts/integration-obligations.md) explicitly requires 011 to bind and recheck its resolver. | Added transaction, signed routes, current-pass rechecks, fixed identifiers, response/error contracts and stale behavior. |
| F03 High | “HTML/assets” and “unsafe output” had no package schema, limits or division between collection and 012. [012 contract](../012-v3-scene-qualification/contracts/scene-qualification.md) owns executable/safety/JEV gates. | Defined bounded `ScenePackageV1`, private manifest and structural validation; collection remains untrusted until 012. |
| F04 High | Original fallback/retry prose lacked reservation, duplicate, concurrent, post-send uncertainty and file/DB failure behavior. 005 caps total attempts; 007 warns Temporal replay is not external-effect idempotency. | Added ordered one-send-per-provider policy, locked ledger, exact-byte replay, uncertain stop, five-minute reconciliation and storage recovery. |
| F05 High | Original artifacts did not name signed routes, owner status or V2/public bypass checks. `routes/web.php` exposes V2 `/sections`; `DynamicViewModel` and public-serving disks cannot hold private candidate code. | Added 007 HMAC operations, 004 active-original-teacher status route, admin closed DTO counts, no browser delivery, V2 route regression tests. |
| F06 High | Original tasks created schema before meaningful red tests and ended with generic “inspect privacy” integration. | Replaced with T001–T014 ordered test-before-code work, real two-process race, fault injection, proof-backed milestones and live selected API gate. |
| F07 Medium | Original checklist marked requirements covered despite no scenario-to-test matrix or downstream handoff. | Added A01–A17 with test paths/outcomes and explicit 012–015 obligations. |
| F08 Medium | Original quickstart could be read as evidence of success and did not distinguish fixtures from live provider access. | Labeled future commands, added shared-DB race/live test instructions and no-runtime-certification boundary. |

## Files edited and purpose

| File | Substantive change and necessity |
|---|---|
| [spec.md](spec.md) | Recast requirements FR-001–009 around current pass, API-only selection, bounded candidate, uncertainty, privacy and proof-backed events; removes ambiguous scope. |
| [plan.md](plan.md) | Maps design to actual Laravel/Temporal split and exact future paths; rejects V2 storage/model and an invented runtime dependency. |
| [research.md](research.md) | Records evidence-based reuse/extension/new decisions and the API/template/runtime limits behind them. |
| [data-model.md](data-model.md) | Specifies three tables, fields/FKs/uniqueness/state, immutable lineage, private paths, partial commit and recovery. |
| [contracts/scene-authoring.md](contracts/scene-authoring.md) | Fixes model input/output, package schema, signed routes/bodies/errors, state machine, fallback, manifest, concurrency, HMAC/owner/admin boundary and retention. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | Adds A01–A17 with requirement mapping, planned executable test paths and expected results. |
| [contracts/integration-obligations.md](contracts/integration-obligations.md) | Distinguishes 011 collection proof from 012 browser/JEV/repair, 013 review, 014 recovery and 015 retention. |
| [tasks.md](tasks.md) | Replaces vague T001–T014 with paths, dependencies, FR/scenario IDs, observable completion and tests before governed code. |
| [task-execution-plan.md](task-execution-plan.md) | Direct runbook for entry prerequisites, baseline changes/failures, group tests/checkpoints, stop conditions and report contents. |
| [implementation-notes.md](implementation-notes.md) | Captures repo-specific V2/public-disk traps, 005/010 owner corrections, Temporal retry limit and 012 boundary. |
| [quickstart.md](quickstart.md) | Gives future implementer commands and expected evidence, including live API and shared-DB race; does not claim a pass now. |
| [checklists/requirements.md](checklists/requirements.md) | Separates completed documentation checks from unperformed prerequisite/runtime/012–013 gates. |
| [review-report.md](review-report.md) | Records the audit, rationale, coverage, final document checks and remaining risks. |

## Design decisions and coverage

API-only provider choice follows the immutable 005 payload. The exact approved scene template remains a system instruction; 010's current NotebookLM-produced qualified prompt is the only teacher content sent to the coding model. The First-step Section fields remain preserved by 009, not copied into 011's vendor request. Each provider is tried at most once in captured order, so `coding_attempts` is an upper bound, and 012 repair is a later new request. A response is a static UTF-8 text package; 011 neither executes code nor scores it. 012's restricted browser must reject external runtime access. Generated files and raw provider evidence stay on `v3_private`, with no teacher preview in 011 and no admin content route. Temporal carries safe IDs/status; Laravel owns identity, current-pass check, attempts, files and owner/admin projection. These choices are proportional to the existing contracts and repository.

| Requirements | Acceptance cases | Implementation task groups |
|---|---|---|
| FR-001–003 | A01–A05 | T002–T005, T012–T013 |
| FR-004 | A10–A13 | T006–T007, T012–T013 |
| FR-005–006 | A05–A09, A13–A14 | T004–T005, T008–T009 |
| FR-007 | A03, A09, A12, A14 | T006–T009 |
| FR-008 | A02, A04, A08, A15–A17 | T002–T003, T010–T011 |
| FR-009 | A11, A17 | T010–T013 |

Each A01–A17 row names a planned test file and expected result. T001 is the prerequisite/baseline gate; T014 is final executable/live evidence. The new feature tests are specified to fail on behavior before code, with predecessor regression coverage, real HMAC/owner boundaries and a two-process shared-DB race. Fixture providers cannot prove the live API gate.

## Final documentation review

After edits, a fresh read-only cross-artifact check found consistent FR-001–009, A01–A17 and T001–T014 identifiers; all nine requirements have acceptance and task coverage. State terms `pending|authoring|candidate_ready|blocked|uncertain|stale`, API provider IDs, `ScenePackageV1`, signed route paths and 012 trust boundary are aligned across spec/plan/model/contract/tasks/notes. Local Markdown links resolve; no unresolved placeholder or open choice instruction remains. The task sequence places relevant tests before implementation and the direct runbook follows that sequence. The `.specify/feature.json` selector remains 004 as found. This review wrote only 011 documentation; preexisting tracked changes to `.specify/feature.json`, `package.json` and `package-lock.json` and preexisting untracked other specs were left untouched. `git diff --name-only` for tracked files still lists only those preexisting paths, so no other numbered spec or application code was changed by this review.

## Open prerequisites and limits

- 004–010 implementation, migrations, signed routes, private disk and current-pass resolver are absent in the inspected application and must be verified before 011 implementation. Their documented paths may need mapping to actual implemented names at T001, without changing the approved contracts silently.
- The 005 approved scene-template catalog may be empty. A trusted operator must import an approved template compatible with `ScenePackageV1`; publication alone does not prove vendor/model availability. A compatible local runtime asset is not present in `package.json`. 012 must enforce its own offline/restricted execution rule.
- A configured live API call, real private candidate, shared-DB concurrent claim, workflow history replay and all A01–A17 security/fault tests are mandatory implementation evidence and have **not** been run here. 012 scene qualification and 013 teacher review remain downstream features.
- No unresolved business decision requiring owner input was identified during this review; if a chosen approved scene template requires a different file/runtime contract, pause at that concrete compatibility conflict rather than silently loosening 011 or 012.

**Application implementation and application tests were not performed during this documentation review.** No runtime behavior or implementation readiness is certified by the document checks above.
