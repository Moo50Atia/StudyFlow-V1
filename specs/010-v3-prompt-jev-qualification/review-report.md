# Review Report: V3 Spec 010

**Review scope:** `specs/010-v3-prompt-jev-qualification/` only. **Date:** 2026-10-02. This was a documentation review and edit; no application implementation, migration, application test, provider call, commit or deployment was performed.

## Initial read-only findings

The initial `speckit-analyze` prerequisite inspection found `.specify/feature.json` still selecting 004. I kept that selector unchanged and performed the skill's cross-artifact checks explicitly against 010. Findings below were identified before remediation from the original 010 artifacts, 005/007–009/011 contracts and the inspected repository.

| Severity | Finding and evidence | Resolution in 010 |
|---|---|---|
| Critical | Original `spec.md`/`plan.md` only sent Second/Third. [009's integration obligation](../009-v3-lesson-map-sections/contracts/integration-obligations.md) explicitly leaves immutable Execution to 010 and warns that `Continue` may already contain all three steps. Repeating them can corrupt a stateful conversation. | Defined once-claimed Execution after first First, accepted ready/complete combined forms, combined Continue reuse, partial-response block, exact digests and live gate. |
| High | Original 010 had no private way to read 009's eleven Section fields or stored raw Continue; 009's worker context returns only Section refs/digests. | Added signed case/current-work `prompt-context` operation with fields, path, query ref and verified raw Continue when applicable. |
| High | Original 010 asserted prompt generation and JEV pass but had no durable Third-output identity, exact 009 completion receipt, private artifact proof, or lost-ack recovery. | Added work/turn/version schema, atomic private writes, exact ordered version-1 refs/009 digest, stable receipt and exact replay. |
| High | 005's older `providers.prompt` downstream description conflicted with 010's NotebookLM Third prompt. The owner explicitly selected NotebookLM conversation during review. | Original final prompt is NotebookLM Third only; captured `providers.prompt` remains a 005 payload field but makes no 010 call. Feedback uses captured `providers.feedback`. |
| High | 005 demanded confidence for all typed JEV answers, but [official TypeSafe OpenAPI](https://api.typesafe.ai/openapi.json) Noul has only a yes probability; original 010 did not resolve threshold math. The owner selected Noul probability as yes/confidence. | Specified Noul yes ≥0.5, `100*noul≥min_confidence`, 0–100 Score question, inclusive unrounded comparisons, named typed wire and no second judge. |
| High | Original `data-model.md` was three sketches and `prompt-qualification.md` was three method signatures. No routes, keys, locks, replay, stale, partial failure, status or error shapes were executable. | Added exact 010 tables/states/constraints, signed worker and owner status routes, stable digests, idempotency/uncertain rules, safe errors and current-pass resolver. |
| High | 005 permits three feedback provider IDs, but original 010 did not specify a provider adapter or accepted text response. The reviewed checkout has no model client to reuse. | Added one internal adapter interface, fixed official Gemini/Z.AI/DeepSeek nonstreaming wire mappings, private response evidence and three adapter fixture tests. |
| High | Existing Laravel `routes/web.php` has V2 admin/teacher, `/sections`, `/lectures`, `/api/lectures` and `teacher-study-flow`; original 010 did not test alternate-route privacy or 004 admin metadata boundary. | Added actual-route/owner/HMAC regression cases; no admin or teacher prompt-content route. Added original-owner safe per-Section status only. |
| Medium | Original tasks placed schema before tests, used vague deliverables, and had no real concurrency, live integration or meaningful completion evidence. `v3_engine` does not yet exist in the reviewed checkout. | Reordered T001–T018 with exact future paths, dependencies, FR/P coverage, behavioral red, two-process race and mandatory real-provider P18. |
| Medium | Original execution guide/quickstart were short command lists without baseline handling, stop conditions, or report evidence. Original checklist claimed no unresolved clarifications without checking actual wire. | Added direct execution groups/checkpoints, baseline policy, technical stop conditions, future verification commands, and a checklist that leaves runtime/prerequisite gates unchecked. |

## Evidence and design decisions

- Read Conversation 23, V3 map, all original 010 files, 004 access depth reference, 005 settings, 007 workflow, 008 source adapter, 009 prompt/cursor/data/integration contracts, and 011 authoring contract. 004's revision was used for depth, not presumed fully validated. Applicable instructions were checked; `.agents/AGENTS.md` is scoped to `.agents`, not this spec.
- Inspected `composer.json`, `phpunit.xml`, `config/database.php`, relevant routes/controllers/middleware/models/migrations/tests and configuration. The checkout has Laravel 12/PHP ^8.2 and V2 data/routes, but no implemented 004–010 V3 application files or Python `v3_engine`; paths in the plan are future deliverables. SQLite `:memory:` routine tests cannot prove concurrent claims.
- The owner answered two review clarifications: NotebookLM's bound conversation produces final Third prompts; Noul yes probability is its yes confidence. These override older wording without altering 005/009 files. JEV Choice/Score/Noul successful typed values are used directly, with only wire-shape validation and deterministic captured policy.
- 010 owns Execution/Second/Third, prompt persistence, JEV and feedback; 009 owns map/First/cursor/Continue; 011 must bind the 010 current-pass ref to its own authoring request. This keeps 010 independently testable after prerequisites without creating later-feature production placeholders.
- The feedback adapter maps `glm_api` to the official Z.AI chat-completions endpoint; a different GLM-account host is a deployment mismatch to report, not an implicit alternate. Google and DeepSeek use their official text generation/chat endpoints. All three IDs have fixture contract tests, while the captured selected provider additionally requires a live run.
- Stateful NotebookLM/feedback sends are claimed once and left uncertain after possible delivery. Pure read-only JEV can retry the same request digest; the first committed valid answer wins. Original Third refs are persisted before 009 receipt. A blocked Section does not stop sibling Sections or future 009 Lessons. Private artifacts remain for pilot history; 014/015 own reviewed repair/retention.

## Files edited and why

| 010 file | Substantive change / necessity |
|---|---|
| [spec.md](spec.md) | Replaced broad stories with FR-001–008, precise scope, states, owner corrections, success evidence and dependency boundary. |
| [plan.md](plan.md) | Matched actual Laravel/V2-only checkout, named new/extended paths and ordered generation, qualification, revision, status and recovery architecture. |
| [research.md](research.md) | Recorded repository/official JEV evidence, owner decisions, alternatives and live prerequisites. |
| [data-model.md](data-model.md) | Defined work items, turns, immutable prompt versions, JEV attempts/decisions, feedback effects and per-Section state with keys/locks/artifact retention. |
| [contracts/prompt-qualification.md](contracts/prompt-qualification.md) | Specified exact canonical/combined parsing, signed and owner HTTP operations, fields/errors/digests, typed policy, feedback vendor adapters, state effects and safe projection. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | Added stable P01–P18 requirement→test→outcome coverage, including live selected services. |
| [contracts/integration-obligations.md](contracts/integration-obligations.md) | Separated what 010 proves from 009, 011–015 obligations; prevents a prompt pass being mistaken for a scene/review pass. |
| [tasks.md](tasks.md) | Replaced vague T001–T014 with ordered T001–T018, exact deliverables, dependency and acceptance IDs, behavioral red/green and completion conditions. |
| [task-execution-plan.md](task-execution-plan.md) | Made this the direct implementer guide with entry checks, group tests/checkpoints, workspace/baseline handling, stop conditions and report contents. |
| [implementation-notes.md](implementation-notes.md) | Captured repository traps and fixed decisions without duplicating the task schedule. |
| [quickstart.md](quickstart.md) | Added future implementer commands and expected results for routine, real concurrency, real provider and final regression gates. |
| [checklists/requirements.md](checklists/requirements.md) | Replaced unsupported all-green claims with cross-artifact checks and explicit unchecked runtime/prerequisite gates. |
| [review-report.md](review-report.md) | Documents initial findings, decisions, file changes, coverage, final checks and remaining risks. |

## Coverage and final read-only review

FR-001–FR-008 each have at least one P01–P18 acceptance case and implementation task. Every P case names a planned test file and expected outcome. T001–T018 are unique and dependency ordered; the core behavior/security tests are authored before the implementation they govern. P11 requires two real processes and shared DB; P18 requires real selected NotebookLM, TypeSafe and feedback services. The 010 current-pass resolver is tested now; 011's actual authoring request remains its own later obligation.

After edits, a fresh read-only cross-artifact pass checked local Markdown links, FR/P/T references, schema/route/state terminology, task dependencies, Execution/Second/Third digests, owner/admin/V2 boundaries, and notes/quickstart against the execution plan. Local links resolved and no unfinished design marker remained. `git diff --check` was clean for tracked changes; 010 is currently untracked, so a separate whitespace/link scan was used for its files. `.specify/feature.json` remains on 004 as found; it was not switched. No commands wrote another numbered spec or application code. The repository already had modified `.specify/feature.json`, `package.json`, `package-lock.json` and multiple untracked spec directories before this review; those were preserved.

## Remaining prerequisites and limits

No owner business decision is pending. Full implementation remains dependent on working 004–009 production contracts, a trusted operator-imported approved feedback template, selected NotebookLM MCP/source access, TypeSafe model/key and feedback provider credential. The actual Execution reply, combined/staged Continue behavior and TypeSafe 101-level Score support must be proven live. If their real contracts differ, the future implementer must stop and report a specific correction rather than edit canonical prompts or infer a new rule. 014 operator recovery and 015 retention are later work. **Application implementation and application tests were not performed in this documentation-only review; runtime behavior is unverified.**
