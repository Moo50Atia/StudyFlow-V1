# Review Report: V3 Spec 005 Versioned Settings

**Date**: 2026-10-01 · **Review type**: specification and documentation only · **Scope**: `specs/005-v3-versioned-settings/`

## Authority and inspection

Read Conversation 23, V3 map, every original 005 artifact, 004 access/data/plan/tasks contracts, and affected 006–015 contracts/data/plan where settings are consumed. Inspected actual Laravel `routes/web.php`, `bootstrap/app.php`, `app/Models/User.php`, legacy role/teacher middleware and account/profile controllers, users migration, `config/services.php`, `config/filesystems.php`, `.env.example`, `composer.json`, `phpunit.xml`, Pest setup and existing tests. No applicable AGENTS.md exists in the repository or its ancestor path. The checked-in Spec Kit constitution is an unratified placeholder. The `speckit-analyze` prerequisite script reported the preexisting selector for 004; this review applied its read-only semantic checks directly to 005 and did not alter the selector.

## Initial findings before remediation

| ID | Severity | Evidence | Finding and impact | Resolution |
|---|---|---|---|---|
| F01 | High | Original `spec.md` FR-001; `contracts/settings.md` said only “typed rules” | No exact fields/ranges or provider/credential vocabulary; implementer would invent policy. | Complete v1 payload, validation and fixed pilot identifiers in contract. |
| F02 | High | Original `plan.md`/`tasks.md` referenced case capture but 006 owns ProcessingCase; current `app/Models` has no V3 case | T009 would require implementing a later spec or claim fixture as real case evidence. | Transaction-bound selector plus explicit 006 handoff and proof limit. |
| F03 | High | Original publish contract lacked routes, response codes and lock order; repository uses SQLite for tests | Two-admin conflict/atomicity was aspirational, not executable. | Exact POST/GET routes, first-write locks, expected version, event/pointer transaction and two-worker test. |
| F04 | High | Original history/secret wording lacked storage shape; `config/services.php` has no V3 credentials | Credential value could leak via payload, logs, history or flashed invalid input. | Strict reference labels, value-free event, explicit serializers, no request logging/old-input flash and sentinel tests. |
| F05 | High | 004 `contracts/access.md`; current legacy `TeacherAccessMiddleware` grants admin content access; `RoleMiddleware` checks role only | A naive new settings route could bypass V3 enrollment or leak via legacy routes. | Require 004 fresh active-admin middleware, route inventory and real boundary tests. |
| F06 | Medium | Original `data-model.md` only named entities; no FK/delete/version/checksum rules | Row immutability, audit retention and corrupt capture behavior undefined. | Exact three tables, FKs, canonical checksum, retention and fail-closed lookup. |
| F07 | High | Original `tasks.md` created schema/model before story tests and gave no completion conditions | No meaningful test-first path or evidence for security/concurrency. | Reordered T001–T015 with task paths, IDs, dependencies, red/green conditions and checkpoints. |
| F08 | Medium | Original `quickstart.md` listed manual scenarios only | No executable final commands or distinction between fixture and live integration. | Commands, expected outcomes and downstream evidence obligations. |
| F09 | High | Conversation 23 §7 mentions admin prompt-template management; 009 freezes canonical prompts; 010/011 own noncanonical instruction use | Exact noncanonical template management ownership was not settled by the original 005 artifacts. | Owner selected immutable approved-template choice; 005 now defines trusted import, catalog, admin selection and captured-byte lookup. |

## Files edited and reasons

| File | Substantive change | Reason |
|---|---|---|
| `spec.md` | Bounded 005 scope; FR-001–FR-009; S01–S15; honest success proof | Make behavior and acceptance testable without 006 entities. |
| `plan.md` | Actual Laravel baseline, exact target paths, locking and recovery sequence | Align architecture with repository and dependency order. |
| `research.md` | Repository evidence and decisions D1–D7, primary JEV references | Explain why schema, provider and service choices are supported. |
| `data-model.md` | Version, pointer, publication and approved-template tables/constraints; 006 FK handoff | Remove schema, template and retention guesses. |
| `contracts/settings.md` | Complete payload including template refs, routes, trusted import, errors and transaction contract | Give implementer exact API and policy semantics. |
| `contracts/acceptance-matrix.md` | Stable scenario → requirement → test → outcome map | Expose missing acceptance/test coverage. |
| `contracts/integration-obligations.md` | Concrete 006–015 consumption and proof ownership | Avoid false completion claims and active-settings drift. |
| `tasks.md` | T001–T015 with files, order, scenarios and conditions | Make implementation executable and test first. |
| `task-execution-plan.md` | Entry gate, group tests/checkpoints, baseline handling, stop/report criteria | Give the agent one direct execution plan. |
| `implementation-notes.md` | Laravel route/access/SQLite/secret traps | Preserve repository-specific constraints without repeating the plan. |
| `quickstart.md` | Future verification commands and expected observations | Make verification repeatable without claiming tests ran. |
| `checklists/requirements.md` | Documented quality and pending implementation/template gates | Avoid treating a documentation checklist as runtime proof. |
| `review-report.md` | This record of findings, edits and limits | Provide an auditable review outcome. |

## Key design rationale

No default active version is seeded because Conversation 23 leaves operating values configurable; an admin must publish the first version. The publish body is a full replacement with known safe bounds, and provider `model` names are syntax-checked without pretending a live adapter exists. The owner chose admin selection from separately approved immutable templates. A trusted operator imports the approved feedback/scene bytes and digest into a catalog; settings stores selected IDs/digests and the implementation agent does not invent template prose. The singleton pointer's first write serializes SQLite publishers and case selection, while the 004 access lock protects a fresh admin eligibility check. Publication event and pointer commit atomically. Captured reads verify exact settings/template checksums and never switch to current settings. 005 retains settings history, while 006 owns the real case FK and idempotency. These choices keep 005's code independently implementable after 004; live first publication waits for approved template imports.

## Coverage and final review

| Requirement | Acceptance IDs | Delivery/test tasks |
|---|---|---|
| FR-001 | S04, S13 | T004–T005, T014 |
| FR-002 | S01, S03 | T002–T005 |
| FR-003 | S01–S02, S05–S07 | T002–T003, T006–T009 |
| FR-004 | S08–S10 | T010–T011 |
| FR-005 | S10–S11 | T010–T011 |
| FR-006 | S04, S12 | T012–T014 |
| FR-007 | S03, S06, S13 | T004, T006–T007, T012–T013 |
| FR-008 | S03, S10, S14 | T004, T010–T011, T014 |
| FR-009 | S01, S15 | T002–T005, T010–T014 |

SC-001–SC-005 map to acceptance IDs in the matrix. Final read-only pass checked 005's requirement/scenario/task links, schema names, route names, state/error terms, task order, local document links, absence of unresolved design placeholders, and agreement between tasks, notes, execution plan and quickstart. A read-only ID/link/whitespace check returned 13 documents, 9 requirements, 15 scenarios, 15 ordered tasks and zero errors; `git diff --check` emitted no errors for tracked files. Other numbered specs and application code were not edited. `.specify/feature.json` remained at its pre-review 004 selection.

## Remaining decisions, risks and prerequisites

- The owner resolved the template choice: select approved versions and require trusted operator import before first publication. No approved production feedback/scene file paths or digests were supplied. This is a live-operation prerequisite, not an unresolved implementation design: 005 code and tests use isolated fixtures, and live publication remains blocked until approved files are imported. `Google-Ai-studio-System-prompts` was inspected but not assumed approved.
- 004 is documented but not implemented in the inspected code. Implement it before 005. No V3 provider credentials/adapters are configured, so publish validation must not claim live availability. 006 must prove real case capture; 007/010–013 must prove runtime consumption and typed decisions. These are explicit prerequisites/handoffs, not 005 test failures.
- TypeSafe's installed wire schema/model access and any provider-specific coding automation remain later integration checks. 005's normalized policy is precise, but no live JEV call was made here.

**Application implementation and tests were not performed during this documentation review.** No commit, deploy or application code edit was made. Documentation checks do not certify runtime behavior.
