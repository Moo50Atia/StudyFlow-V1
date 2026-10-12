# Research and Repository Decisions

**Date**: 2026-10-10. Design research only; no application tests or live provider calls.

## Authority and evidence

Read `.agents/AGENTS.md`, Conversation 23, V3 spec map, Spec Kit skills/templates, 004 execution guidance, 017/018 downstream contracts and `docs/PER-PDF-NOTEBOOKLM.md`. Later owner corrections select direct PDF upload, separate per-PDF maps in one notebook, arbitrary scene labels/counts, HTML/CSS/JS, export/manual default and explicit demo JEV waiver.

Inspected Subject/Lecture/Section/TeacherPermission models; web/V3/API routes, bootstrap middleware, subject/lecture controllers/views; V3 intake/case/source/lesson/prompt/authoring/check/review/evidence services and migrations; current NotebookLM SDK ports/services, terminal supervisor/workflow/activities/worker, scene services/source packages/builder, tests and configuration. Read-only research agents examined scheduling and candidate/review assumptions. They made no edits.

## D01 — Existing UI and original teacher

**Decision**: Add subject-run controls to existing pages and explicit owner-scoped links to generated lectures/sections. Subject permission never transfers another teacher's content.

**Rationale**: TeacherAccessMiddleware grants broad GET/admin access, APIfilterController exposes identities, and LectureController uses public PDF storage. New-route-only protection leaves actual bypasses.

**Alternatives considered**: Detached workspace misses the requested integration; converting every legacy subject into an owned subject changes unrelated behavior. Only linked records receive new strict boundaries.

## D02 — Shared notebook, separate PDF scope

**Decision**: Capture `notebooklm_per_pdf_web_v1`; add a shared notebook parent. New bindings retain legacy unique `(adapter_kind,notebook_id)` by setting the old notebook_id to NULL and resolving the parent FK. Each case keeps its own PDF source. Operations include PDF/lesson/attempt identity, not just conversation ID.

**Rationale**: Current per-PDF service already archives/resets. Several case bindings cannot insert the same legacy globally unique notebook ID; remote conversation IDs can be reused after reset.

**Alternatives considered**: One notebook per lecture consumes more notebooks; joint maps change requested granularity; globally removing the legacy unique index weakens existing cases.

## D03 — Versioned application workflow around existing services

**Decision**: ApplicationSubjectWorkflow uses reference-only inputs and the existing supervisor/queues. Admission accepts a trusted workflow type enum, not arbitrary browser-supplied class names. Reuse NotebookTerminalService and SceneGenerationService; do not call CLI processes from Laravel.

**Rationale**: Current terminal payloads still carry configuration and remote IDs. The legacy bridge starts CaseWorkflow in another namespace. Current per-PDF runtime exceptions do not implement definite-failure continuation. A new wrapper addresses those gaps without rewriting old histories.

**Alternatives considered**: A second scheduler doubles account concurrency; blindly reusing legacy CaseWorkflow gives duplicate ownership of processing effects.

Update-With-Start provides first submission startup. Queries do not write event history and signal acknowledgment does not prove decision consumption. See [Temporal message passing](https://docs.temporal.io/develop/python/workflows/message-passing) and [WithStartWorkflowOperation](https://python.temporal.io/temporalio.client.WithStartWorkflowOperation.html). Shared application capacity and outbox design are decisions inferred for this repository.

## D04 — Truthful manual and demo eligibility

**Decision**: Immutable gate evidence uses real_jev or owner_waived_demo. Manual imports have actual import receipts, not fake CodingAttempts. Demo candidate state is demo_review_ready, not qualified; shared resolvers check actual browser evidence throughout preview/review/approval.

**Rationale**: Current candidate request/attempt/prompt qualification and review/approval scene qualification FKs are nonnullable. SceneCandidateResolver, SceneReviewEligibility, EnsureReviewReady, ReviewContext, StartReviewPreview and SubmitReviewDecision all assume machine lineage. One Boolean cannot fix them.

**Alternatives considered**: Fake 100% scores violate evidence rules; skipping browser checks/review violates business authority; preview-only support omits requested decisions.

## D05 — Explicit static package profile

**Decision**: ScenePackageV2 profile static_three_file_v1: exactly index.html, styles.css, script.js, nonempty UTF-8, <=1 MiB/file and <=4 MiB total. Preserve existing V1 <=256 KiB/file and <=1 MiB total. Add an explicit V2 adapter to the same isolated browser boundary.

**Rationale**: Current017 static collector and011 application package differ. Silent casting loses limits and provenance. JSON multipart payload cap is4 MiB excluding transport overhead; decoded file bytes remain independently checked.

**Alternatives considered**: React contradicts current approved template; broad arbitrary files add scope; unversioned relaxation changes old candidates.

## D06 — Missing-aware projection

**Decision**: New sections retain nullable absent First fields, missing-field list and raw response. Legacy strict validation is unchanged. Exact valid Third prompts may be exported despite incomplete First; real qualification blocks missing required context while demo gates record incompleteness explicitly.

**Rationale**: Archive handoffs preserve raw First/Second/Third but not necessarily parsed eleven-field objects. Existing columns/context hashers require nonnull values. Filling strings would invent educational evidence.

**Alternatives considered**: Rejecting capture loses paid results; another model supplying missing fields adds invented evidence and cost.

## D07 — Import without remote work

**Decision**: Admin CLI verifies/copies archives into selected active teacher/subject. A new import workflow logs only actual import steps. Dry-run writes nothing. Never import cookies. Apply015 existing retention/deletion eligibility, blocking deletion for unresolved sends, reviews and retained approval evidence; add no arbitrary timed purge.

**Rationale**: Reference run has8 PDFs,63 lessons,89 prompts and zero generated scenes. Artifact paths are run-relative; accepted input references are terminal-root-relative. Correct roots and historical labels matter.

**Alternatives considered**: Reprocessing buys work again; manufacturing old Temporal events falsifies history; admin educational ownership contradicts authority.

## D08 — Short transactions and immutable effects

**Decision**: Local transactions reserve versions/effects and outbox/events. Network and file verification run outside transactions, followed by digest/version compare-and-swap. SQLite contention retries are local and bounded; remote mutations are not repeated on transaction retries.

**Rationale**: [Laravel transactions](https://laravel.com/framework/docs/12.x/database) protect database writes, not remote calls. Outbox/reconciliation is necessary at that boundary.

**Alternatives considered**: Holding locks during NotebookLM blocks users; retrying a transaction containing remote calls duplicates spending.

## Tooling and governance limitations

No extensions.yml exists: all hooks skip. setup-plan/setup-tasks/prerequisite scripts exist. The required update-agent-context.sh is absent; tasks-note.md supplies explicit feature agent context, without overwriting global instructions. Constitution is an unfilled placeholder template: no ratified rules can be inferred and no constitution pass is claimed. Historical migrations contain forward FKs; support current SQLite with additive upgrades and cloned-data tests, not renaming historical files. Existing dirty workspace changes must be protected.
