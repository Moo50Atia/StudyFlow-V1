# Implementation Plan: V3 Web Pipeline Integration

**Branch**: existing main; unchanged | **Date**: 2026-10-10 | **Spec**: [spec.md](spec.md)

## Summary

Connect existing subject/lecture pages to a versioned subject-run aggregate. Reuse private intake, current per-PDF NotebookLM services,017 scene services, shared Temporal scheduling and isolated review. Deliver archive-only import first, then web processing, manual/API scenes and checked original-teacher review. This package is documentation, not implementation evidence.

## Technical Context

**Language/Version**: PHP^8.2/Laravel12; existing Python venv/pinned Temporal SDK and notebooklm-py0.8.4; existing browser JavaScript tools.

**Primary Dependencies**: Blade/Breeze, V3 access/settings/intake, qpdf/ClamAV, NotebookLM ports, HTTPX, Temporal, existing artifact/builder/browser services.

**Storage**: current application SQLite, private local disk, terminal registry/journal, persistent Temporal namespace v3-terminal.

**Testing**: Pest/PHPUnit, pytest, Temporal test server/replay, actual isolated browser runner.

**Target Platform**: local Linux pilot; Laravel, signed Python bridge and existing three-queue workers.

**Project Type**: existing web application plus Python services; no new frontend framework.

**Performance Goals**: combined web/terminal max4 active subjects,4 IO and2 artifact/build operations;64 queued;100-row event/scene pages.

**Constraints**: Temporal serialized payload<=32 KiB, opaque refs only; no runtime login browser; one remote mutation attempt before reconciliation; zero default generation requests.

**Scale/Scope**: batches1–20 PDFs, existing100 MiB/1000-page per-file limit,1 GiB staged batch bytes; enforce during receiving. Existing24-hour intake staging expiry. No fixed lesson/scene count. Reference eight-PDF archive plus disposable two-PDF web sample.

## Constitution Check

Pre-research and post-design: constitution.md is an unfilled template; no ratified rules exist to validate. Do not claim a constitution pass or invent principles. Actual repository instructions and approved owner boundaries govern. Missing update-agent-context script is a tooling limitation recorded in research and execution notes. Neither is runtime evidence.

## Project Structure

### Documentation (this feature)

spec.md, plan.md, research.md, data-model.md, acceptance-matrix.md, contracts/{web-api,worker-orchestration,archive-import,scene-and-review}.md, tasks.md, tasks-note.md, task-execution-plan.md, quickstart.md, checklists/requirements.md.

### Source Code (repository root; proposed deliverables)

```text
app/Models/V3/{SubjectRun,SubjectRunItem,NotebookInstance}.php
app/Services/V3/WebPipeline/                 # run, outbox, projection and import services
app/Services/V3/Authoring/                   # manual provenance and neutral artifact writer
app/Services/V3/SceneQualification/          # eligibility gates and resolver extension
app/Http/Controllers/V3/Teacher/SubjectRunController.php
app/Http/Controllers/V3/Internal/WebPipelineController.php
app/Console/Commands/V3/ImportTerminalRun.php
database/migrations/2026_10_10_000019_create_v3_web_pipeline.php
database/migrations/2026_10_10_000020_extend_v3_projection_provenance.php
database/migrations/2026_10_10_000021_extend_v3_candidate_review_gates.php
resources/views/v3/teacher/subject-runs/
v3_engine/web_pipeline/                     # ref-only workflows, activities, signed bridge
 tests/Feature/V3/WebPipeline/
 tests/Integration/V3/WebPipeline/
v3_engine/tests/web_pipeline/
```

**Structure Decision**: Extend real Laravel UI/controllers and existing Python ports. Proposed paths are additions, not assertions that files exist. No new scene engine or production fixtures.

## Architecture and ordered phases

1. Baseline/test-only fixtures, additive migrations, missing-aware context, package/gate invariants, fresh access and outbox primitives.
2. US1 archive import: verified immutable captures, per-item commits, historical labels and owner-scoped lecture/lesson/prompt navigation.
3. US2 web intake/captured run: signed admission to shared supervisor, reference-only ApplicationSubjectWorkflow, serial per-PDF loop/archive/reset, local projection and export.
4. US3 manual/API scenes: exact prompt version, explicit provenance, retained response/source recovery, actual artifacts and checks.
5. US4 review/correction: shared typed eligibility across every consumer, isolated preview, exact decision consumption, manual replacement or explicitly selected paid corrections.
6. US5 controls/operations: progress, admin metadata, safe events, shared capacity, replay, retention; core outbox/access/fences are foundations, not postponed.
7. Connected boundary, two-process race, populated migration, replay and honest live gates.

Use [data-model.md](data-model.md), [contracts/web-api.md](contracts/web-api.md), [contracts/worker-orchestration.md](contracts/worker-orchestration.md), [contracts/archive-import.md](contracts/archive-import.md), [contracts/scene-and-review.md](contracts/scene-and-review.md), [acceptance-matrix.md](acceptance-matrix.md), and [task-execution-plan.md](task-execution-plan.md).

## Reuse and extensions

Reuse V3AccessResolver, IntakeCoordinator/PdfInspector, settings/framework/template catalogs, NotebookTerminalService, SceneGenerationService, package/browser/hash checks, isolated preview and review evidence. Extend aggregate linkage, binding resolution, section/context projection, manual provenance, qualification gate consumers, legacy linked-content guards and trusted supervisor workflow routing. Add signed application bridge and workflows. New cases must not also enqueue StartWorkflow/CaseWorkflow.

## Completion criteria

All acceptance cases and selected regressions pass at real application boundaries; actual races and browser preview execute. Live credentials/spending are dedicated and explicit; skipped gates prevent live-readiness claims. Reference63/89 counts are retained-data evidence, not proof of sixfold quality or expected counts for new outputs.

## Complexity Tracking

No enforceable constitutional violation is identified. Run aggregate, shared notebook parent and explicit gate are necessary for subject execution and truthful manual/demo lineage. A duplicated engine or fabricated FK records are rejected alternatives.
