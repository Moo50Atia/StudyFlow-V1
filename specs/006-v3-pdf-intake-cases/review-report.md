# Review Report: 006 V3 PDF Intake and Processing Cases

**Review date:** 2026-10-01 · **Scope:** documentation in `specs/006-v3-pdf-intake-cases/` only · **Result:** contract and task design strengthened; runtime implementation remains gated by prerequisites.

## Initial read-only findings

The speckit-analyze semantic passes were applied directly to the selected directory before editing. Its standard prerequisite script follows `.specify/feature.json`, which already selected 004; running it would analyze the wrong feature. The selector was not changed. The repository constitution is an unratified template, so no ratified principle was inferred from it. Findings below came from the original 006 artifacts, business authority, dependency contracts and code inspection; severity reflects implementation risk, not document length.

| ID / severity | Initial finding and repository evidence | Resolution |
|---|---|---|
| A1 / High | Original `plan.md` deferred PDF parser and scanner choice; original `contracts/intake.md` had no process/exit/status contract. `pdfinfo`/`pdftotext` existed, but qpdf/ClamAV and Composer alternatives did not. | Fixed qpdf JSON v2 object shape and ClamAV daemon/client gates, supported PDF profile, bounded outputs, deterministic versus retryable outcomes and live-tool prerequisite. |
| A2 / High | Original `spec.md` said private storage without a disk/route boundary. Existing `LectureController.php:31,70` stores PDFs on `public`; `config/filesystems.php:36,77` has direct local serving/public symlink; legacy teacher middleware is not a V3 owner check. | Separate nonserved V3 disk, verified owner-authorized stream, exact route set, a protected teacher browser flow and real legacy/public bypass tests. |
| A3 / High | Original data model omitted concrete columns, FKs, unique constraints and immutable lineage. Existing `Lecture` model/migration cannot represent quarantine, owner-scoped exact duplicates or separate processing runs. | Three exact tables/models, composite owner FKs, owner/hash and owner/key uniqueness, state and immutability rules. |
| A4 / High | Original contract omitted HTTP inputs/outputs, error precedence, attempt states, case choice semantics and settings publication race behavior. 004/005 contracts require active actor, same-owner access and transactional settings capture. | Explicit JSON routes/envelopes, fixed safe codes, post-resource validation order, 004→Document→005 lock order, `process`/`process_again`, original-version idempotent replay and current-limit recheck. |
| A5 / High | Original plan said to reconcile file/DB failures but gave no durable pre-case worker, crash points, lease, retry, expiry or orphan rule. 007 Temporal is downstream; `config/queue.php:44` has `after_commit=false`. | 006 intake queue/scheduler, attempt and pending-Document state machines, after-commit dispatch plus lost-dispatch recovery, a fixed three-retry pre-case policy distinct from 007 settings, and 24-hour quarantine cleanup. |
| A6 / High | Original tasks built schema/services before meaningful behavior tests and finished with one broad test command. No real process contention or live scanner distinction was specified; default test DB is SQLite memory. | 21 dependent tasks with behavior-red/green checkpoints, independent-process concurrency, real HTTP security and a live qpdf/ClamAV gate. |
| A7 / Medium | Original success criteria and checklist asserted testability without requirement-to-scenario-to-file mapping or proof limits; original quickstart only listed examples. | Stable I01–I18 matrix, FR trace, concrete future commands and explicit limits of 006 versus 007/008 evidence. |
| A8 / Medium | Original docs did not tell 007/008 how to use a ready Document and case without exposing teacher source or creating a fake workflow; no concrete retention/privacy evidence. | Internal verified reader, durable `awaiting_workflow` contract, safe rows/log fields, pilot retention, downstream integration obligations. |

No existing 006 acceptance case required altering canonical prompts, all eleven First-step Section fields, NotebookLM mapping, JEV typed decisions, Temporal ownership, or the teacher/admin boundary. Those decisions remain fixed and later-spec work remains excluded.

## Files edited and why

| File | Substantive change / necessity |
|---|---|
| `spec.md` | Replaced vague stories with FR-001–010, I01–I18, proof-limited success criteria and exact 006/007/008 boundary. |
| `plan.md` | Matched actual Laravel extension points and listed schema, policy, route, post-resource validators, storage, inspection, queue and recovery deliverables. |
| `research.md` | Recorded repository evidence, primary qpdf/ClamAV references and rationale for chosen gates and rejected alternatives. |
| `data-model.md` | Defined fields, types, constraints, indexes, owner FKs, state and file/row invariants. |
| `contracts/intake.md` | Specified routes, browser pages, body/response fields, failure codes, validation precedence, PDF profile, scan outcomes, transactions, retries, duplicates, concurrency and promotion reconciliation. |
| `contracts/acceptance-matrix.md` | Added scenario→FR→test→expected-outcome coverage and FR→task trace. |
| `contracts/integration-obligations.md` | Defined 007/008/009–015 consumption rules and separated present proof from future integration. |
| `tasks.md` | Replaced generic sequence with 21 dependent tasks, exact files, FR/I links, behavior-first tests and completion checks. |
| `task-execution-plan.md` | Made baseline, grouped red/green work, checkpoints, stop gates and implementation-report contents directly executable. |
| `implementation-notes.md` | Captured repository traps and fixed ownership/storage/tool/downstream boundaries without repeating the task sequence. |
| `quickstart.md` | Added future verification commands and expected outcomes, explicitly not claims from this review. |
| `checklists/requirements.md` | Replaced unqualified checks with review evidence and unchecked runtime prerequisites. |
| `review-report.md` | Records findings, resolutions, scope, coverage and remaining gates as requested. |

## Key design choices

- **Two capture times:** upload selects the active 005 settings before bytes; case start selects again in its insertion transaction and rechecks current size/page limits. This preserves immutable policy lineage without letting an older accepted PDF bypass a tightened limit.
- **Separate attempt, Document and case:** attempts preserve each submission, owner/hash uniqueness deduplicates exact bytes only within a teacher, and idempotency keys identify case-start retries while `process_again` permits deliberate new runs.
- **Private fail-closed acceptance:** qpdf structural profile plus live ClamAV clean result are required before a ready Document. Missing tools, warnings, encrypted/active files, scanner errors and promotion uncertainty never produce a downloadable or case-ready file.
- **Durable pre-case recovery:** 006 owns asynchronous inspection and reconciliation because 007 Temporal starts after a case exists. A committed pending Document precedes filesystem rename; no pending row/file is served. The reconciler can finish, safely block or release a failed pending reservation.
- **Role and content boundary:** 004 active original-teacher check and policy govern every V3 route, including download/list/poll; admin receives no PDF or sensitive metadata. 008 gets a verified internal stream and later owns worker transport.

## Coverage and final documentation review

FR-001–FR-010 map to I01–I18 and T001–T021 via [acceptance matrix](contracts/acceptance-matrix.md). Every I scenario has named planned test files and an observable expected outcome. The task order places substantive tests before behavior code, with runnable scaffolding before schema constraint tests. Live tool checks, real HTTP authorization and true concurrent DB operations have separate evidence requirements. The execution plan and quickstart match the revised task IDs and contract. Local links, placeholders, route/state/field terminology and selector/workspace scope were checked after edits; see final check note below.

**Unresolved business decisions:** none identified that required inventing a teacher/admin rule. **Mandatory prerequisites/risks:** 004/005 are not implemented in this workspace; qpdf/ClamAV daemon/client are absent here; deployment must provide worker, scheduler, private disk and upload ceilings. The qpdf supported profile intentionally rejects some PDFs that generic viewers may open; the future live fixture gate must verify actual CLI behavior. 007 must separately implement Temporal handoff, and 008 must implement extraction/NotebookLM ingestion. This documentation review did not implement application code or run application tests, scanner tests, migrations, workers, or Temporal workflows; it does not certify runtime behavior or declare implementation readiness while those gates remain open.

### Final check note

A fresh read-only check after edits found 13 Markdown files, zero broken local links, no unresolved TODO/TBD-style placeholders or trailing whitespace, all 10 FR IDs in the spec and matrix, all 18 I IDs in the spec and matrix, 21 task rows with 21 unique IDs, and every matrix test filename present in quickstart. State/route/field wording and task group ordering were checked against the intake contract; the FormRequest precedence trap and qpdf JSON object-key mistake were corrected during this pass. `git diff --check` reported no tracked-file whitespace error. The Spec Kit selector still reads `specs/004-v3-access-ownership`. Only files under the selected 006 directory were written for this review; other numbered specs and application code were not edited. Pre-existing workspace modifications/untracked files, including the selector and 005 docs from earlier work, were preserved.
