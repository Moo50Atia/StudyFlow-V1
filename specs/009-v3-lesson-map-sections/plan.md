# Implementation Plan: 009 Lesson Map and Teacher Sections

**Reviewed:** 2026-10-02 · [spec](spec.md) · [contract](contracts/lesson-structure.md) · [model](data-model.md) · [matrix](contracts/acceptance-matrix.md)

## Repository fit and boundaries

The current Laravel 12/PHP ^8.2 application has only V2 `Lecture`/`Section` routes and tables. Its `sections` table holds six partial content fields and `SectionController` is under legacy `teacher.access`, which admits admin. There is no V3 code, `v3_engine`, Temporal or selected MCP installed in this checkout. 009 therefore depends on actual 004–008 implementations: original-owner policy and closed admin DTO, captured 005 settings, 006 case/private disk, 007 HMAC/Temporal/event projector, and 008 `bound` source/query adapter. Do not migrate V2 Section rows or copy its authorization pattern.

| Layer | Exact implementation files |
|---|---|
| Schema/models | `database/migrations/2026_10_02_000009_create_v3_lesson_tables.php`; `app/Models/V3/{MapActivation,LessonMap,LessonNode,LessonQuery,TeacherSection,LessonCursor,LessonCompletion}.php` per [model](data-model.md). |
| Laravel services | `app/Services/V3/Lessons/{ActivateMap,LessonQueryLedger,LessonEvidenceStore,AcceptLessonMap,AcceptFirstSections,LessonCursorService,ReconcileLessons}.php`; `app/Jobs/V3/{ActivateMapJob,NotifyLessonCompletionJob}.php`; `app/Console/Commands/V3/{DispatchMapsCommand,ReconcileLessonsCommand}.php`; schedule in `routes/console.php`. |
| Signed boundary | Append to 007 `routes/api.php`; `app/Http/Controllers/V3/Internal/{MapContextController,LessonQueryController,LessonCompletionController}.php`, reusing 007 HMAC before lookup; extend `app/Services/V3/Workflow/ProjectCaseEvent.php` for `map_blocked`. |
| Teacher view | Append to 004 `routes/v3.php`; `app/Http/Controllers/V3/Teacher/{LessonMapController,TeacherSectionController}.php`; `resources/views/v3/teacher/lessons/{map,section}.blade.php`; add owner-only link from 007 progress. |
| Python | `v3_engine/lessons/{canonical_prompts,map_parser,coverage_parser,first_parser,map_service,lesson_processor,cursor}.py`; extend `v3_engine/sources/notebooklm_adapter.py` with `query_once`; extend `v3_engine/{bridge,worker}.py`, `v3_engine/workflows/case_workflow.py`; add `v3_engine/activities/{map_case,prepare_lesson}.py`. |
| Tests | `tests/Feature/V3/Lessons/*.php`, `tests/Integration/V3/Lessons/*.php`, `v3_engine/tests/lessons/*.py` and fixtures named in [matrix](contracts/acceptance-matrix.md). |

## Ordered design

1. Pin runtime strings byte-for-byte to [canonical blocks](contracts/canonical-prompts.md); compare five SHA-256 digests in CI. 009 sends Divide/First, leaves Execution/Second/Third untouched for 010. A fixed envelope scopes First to a map work item. A fixed coverage query is separately versioned and cannot replace Divide.
2. Laravel discovers one 008 `bound` case, commits one activation and signals the existing 007/008 workflow after commit. The 009 worker accepts the first matching signal in a replay-compatible handler, then checks the Laravel activation/binding through a signed route. No source/provider ID enters Temporal history.
3. Query claims are committed before each stateful NotebookLM turn. `query_once` sends through the same 008 case-local `source_ids` boundary exactly once. Known replies are stored atomically in 006/008 private storage and parsed; ambiguous sent calls remain uncertain. No automatic `Continue` retry under generic 008 technical retry policy.
4. Parse the Divide answer into distinct hierarchical nodes, promote empty Mini Chapters, query NotebookLM again for page coverage, then accept one immutable map/cursor transaction. No PDF heading heuristic or second model judges map usability. Known fixture assertions and live provider run bound confidence to observable coverage, not semantic completeness of arbitrary documents.
5. Prepare the first work item with exact First body and scoped envelope. Accept only contiguous Sections with all eleven labels/values; store raw answer, fields and cursor `awaiting_010` in one transaction. Publish safe `mapping` milestones and park the workflow. 009 does not send the canonical three-step Execution protocol or generate ScenePromptVersion/JEV records.
6. Expose a signed 010 completion receipt operation. It checks current map/work/version, exact Section set, one opaque Third-output ref per Section and final conversation ID, then conditionally makes the cursor ready and dispatches an after-commit signed `lesson_ready_v1` signal. It records an **attestation**; 010 must prove its refs exist and Third outputs are persisted before calling. For nonfinal work, 009's one-send literal `Continue` advances only with a correctly identified next response and complete First fields; final work completes without a provider turn.
7. Teacher routes resolve active original-owner case before map/child, render all fields escaped and return only safe projection. Admin stays on 004's closed DTO. Signed worker, legacy route inventory, private artifact, logs and retention are tested at actual boundaries.

## Failure and completion gates

Use uniqueness and case-row transactions for activation, map, query and cursor. Never hold a DB transaction across MCP/Temporal I/O. A lost answer after a committed send cannot be reconstructed from a reply string or guessed conversation; stop uncertain. An exact duplicate accepted response is idempotent; a changed response or stale cursor version is 409. A deterministic parse/coverage/field gap blocks with safe `validation`. A missing referenced artifact/settings fails closed with `internal`. A pre-send provider outage may use captured technical retries; after a query send claim, do not resend automatically. Races require independent connections/processes on a shared disposable DB, not SQLite `:memory:`.

008's current `query` has automatic technical retries and the selected provider's live continuation shape is unproven. Completion needs live selected-MCP map/coverage/First evidence and actual 007/008 history replay. Full multi-Lesson Third-output-to-Continue production integration is a 010 obligation and must be reported separately; 009's test-only completion fixture is not proof that 010 persisted prompts. See [downstream contract](contracts/integration-obligations.md).
