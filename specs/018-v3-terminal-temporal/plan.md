# Implementation Plan: Temporal Terminal Pipeline

**Branch:** `main` | **Date:** 2026-10-09 | **Spec:** [spec.md](spec.md)

**Input:** `specs/018-v3-terminal-temporal/spec.md`; follow [task-execution-plan](task-execution-plan.md) when implementing.

## Summary

Add a local durable coordinator over the existing multi-PDF NotebookLM and Spec 017 services. One supervisor admits four subject execution chains; each subject uploads unchanged sources, runs the exact serial chat protocol, captures all Lessons, then generates/builds scenes. Activity receipts and an operational journal protect recovery. Native Temporal UI and terminal logs expose actual work. This feature neither implements Laravel integration nor changes production qualification/review behavior.

## Technical Context

**Language/Version:** Python 3.12 on Linux. Generated scenes use the Spec 017 `plain-html-css-js-v1` source contract and require no Node/npm build toolchain.

**Primary Dependencies:** installed temporalio 1.34.0, notebooklm-py 0.8.4, httpx 0.28.1; stdlib sqlite3/flock; existing scene providers/build scaffold. No new orchestration, tracing or browser-auth package.

**Storage:** Persistent Temporal dev-server SQLite at `storage/app/private/v3/temporal/server.sqlite`; local journal/index and immutable artifacts described in [data-model](data-model.md). No Laravel migrations.

**Testing:** Existing pytest/asyncio and external opt-in markers, actual registered Activities with fake external providers, local Temporal CLI server, real fixture compilation/browser lane, opt-in live sample and Replayer.

**Target Platform:** One local Linux host; shared private artifact filesystem; one terminal worker process.

**Project Type:** CLI plus library services and durable workflows.

**Performance Goals:** Four admitted subject chains, four external Activities, two build Activities; source poll every 10 seconds using workflow timers; no sleep-based worker occupancy for source processing; progress from recorded counters.

**Constraints:** Serial per-notebook chat; no automatic mutating retry/fallback; exact prompts/PDF bytes; no runtime browser/external auth-refresh command; no educational bodies or secrets in histories/logs; fixed captured model/template/toolchain; original standalone compatibility.

**Scale/Scope:** Multiple submissions, up to 64 queued subjects, large Lesson inventories via references/pagination; no unfinished legacy checkpoint adoption, remote workers or production teacher review.

## Constitution Check

The repository constitution contains unfilled template placeholders and supplies no ratified gates. Pre-research and post-design checks instead apply explicit owner decisions: source-grounded NotebookLM mapping, preserved prompts, variable scene outputs, local truthful waiver, compatible legacy app boundaries, meaningful test-first implementation and protected workspace changes. All design artifacts follow those decisions. `.agents/AGENTS.md` authoring guidance does not override the user's explicit request to create these documents now. No extension hooks are configured.

## Project Structure

### Documentation (this feature)

`specs/018-v3-terminal-temporal/` contains spec, research, plan, data model, CLI/workflow/service contracts, acceptance matrix, tasks, tasks-note, implementation-notes, execution plan, quickstart and requirements checklist. The implementer creates `implementation-report.md` from actual evidence.

### Source Code (repository root)

```text
scripts/v3/run_temporal_pipeline.py                 # new CLI
v3_engine/terminal_pipeline/
  contracts.py settings.py manifest.py store.py journal.py
  supervisor.py subject_workflow.py activities.py controls.py
  status.py cli.py worker.py
v3_engine/notebooklm/terminal_service.py            # new extracted step service
v3_engine/notebooklm/ports.py sdk_provider.py       # extend adapter capabilities
v3_engine/scene_generation/service.py              # expose steps; preserve facade
v3_engine/scene_generation/artifact_store.py        # managed ownership fence
v3_engine/scene_generation/builder.py               # cancellable build boundary
scripts/v3/run_multi_pdf_notebooklm.py              # compatible library facade
v3_engine/scene_generation/cli.py                  # standalone ownership enforcement
v3_engine/tests/terminal_pipeline/                 # new tests/fixtures only
```

**Structure Decision:** Keep vendor calls behind current interfaces. No service receives a Temporal client; workflow wrappers call injected library operations. No production placeholder from future web features. Existing `v3_engine/worker.py`, bridge, `CaseWorkflow` and Laravel routes remain outside the implementation edit scope.

## Architecture and execution

1. CLI captures immutable submission manifests and content snapshots, then Update-With-Start submits opaque references/IDs to supervisor `v3-terminal-supervisor` in namespace `v3-terminal`. Subject Workflow IDs are `v3-terminal-subject-{subject_run_id}`. A recovery execution gets a new subject_run_id linked to the original generation run.
2. Supervisor validates duplicate/conflicting submissions through Activities and durable index. At most four child chains remain admitted until their final result. Paused/blocked children retain slots. Queue holds at most 64 subjects; new admission while draining is rejected before dispatch. Set REQUEST_CANCEL explicitly; no automatic workflow retries. A subject failure is collected, not propagated into cancelling unrelated siblings.
3. New worker runs three Worker instances: `v3-terminal-control` for workflows/local metadata Activities, `v3-terminal-io` max four external Activities, `v3-terminal-build` max two builds. Exclusive process lock prevents a second instance expanding limits. Worker shutdown stops pollers cooperatively; it does not cancel workflows.
4. Subject phase graph: preflight → create → upload each source → poll → configure response format/readback → divide → execution → lesson/send+capture loop → freeze → generate/parse/build scene loop → finalize. Sources, raw turns, receipts and large inventory stay private; Activities return bounded refs/counts. See [workflow contract](contracts/workflow.md).
5. SDK sessions are isolated and reopen through captured subject credential copies. Proof verifies explicit ownership of the configured owner-approved notebook; credentials are refreshed from master only at bootstrap/resume under a master-read lock and after owner verification. Disable external refresh commands globally in this worker before importing/bootstraping SDK. Do not alter global Brave/MCP setup or master cookies during runtime.
6. Expose single-operation APIs in the existing NotebookLM/scene libraries. Preserve standalone CLI flags/results through compatible facades and regression tests. Add a persistent ownership marker plus OS lock/epoch checks so a standalone invocation cannot interleave writes between Activities.
7. Replay/history contain only safe refs; every real Activity boundary records journal events. Final snapshot/report is persisted before declaring a subject complete. Completed/partial scene recovery is a new linked child over the existing store; completed notebook work is not rerun.

## Recovery, retries and limits

- Intent and dispatch records precede external sends; receipts precede parsing/export. Never make paid generation contingent on later export succeeding.
- Mutations use `maximum_attempts=1`; recovery is a separate read-only Activity. Idempotent local/read operations use at most four attempts, delay 5 seconds, coefficient 2, maximum delay 60 seconds. Permanent input/auth/ownership errors do not retry. Build compiler failures require explicit rebuild; transient local I/O may retry before any build dispatch.
- Request/Activity limits: read 30/60 seconds; upload 600/660 seconds; chat/generation 300/360 seconds (captured custom provider timeout plus 60 if overridden); build overall 1500 seconds, individual commands retain 017's 300-second budget. External/build Activities heartbeat every 5 seconds with 30-second heartbeat timeout. Heartbeat details contain safe refs, not bodies.
- After all upload receipts, source-ready deadline is workflow time +900 seconds; poll all source refs every 10 seconds. Resume after explicit timeout retry starts a new readiness deadline without reuploading. No automatic retry of a source in error state.
- SDK auth/rate/transport classifications remain typed; add compatible rate-limit metadata without converting existing error consumers to unrelated enums. Rate limit waits for explicit resume/retry; no unlimited repeated requests.
- Before dispatch, check current epoch/control fence under the operation lock. If old sender holds the lock after timeout, reconciliation/control reports pending/busy; no replacement sender is scheduled.
- Rotate subjects when SDK suggests or 8,000 history events accrue, after a completed operation and all handlers finish. Supervisor enters drain at the same threshold, rejects new submissions, retains queued refs and dedup index refs, and rotates with no active children. Paused children may delay drain; cancellation or completion is required, not forced termination.

## Delivery and evidence

Foundation receipt/account/build tests precede real workflow wiring. Story phases in [tasks](tasks.md) establish automatic execution, recovery controls, monitoring and saved-results mode. The full product is not complete after the first successful mocked run. Real service restart, five-subject concurrency, history rotation/replay, a connected eight-PDF fixture with real build, and privacy/ownership races are mandatory local gates. The live sample is separately opt-in; absence is honestly recorded and does not invalidate local test evidence.

## Agent context and post-design gate

The skill's requested agent-context updater script is not installed. Do not invent or modify a root AGENTS file; implementation context is supplied by [tasks-note](tasks-note.md) and [implementation-notes](implementation-notes.md). This is an authoring-tool limitation, not an unresolved runtime design. Post-design invariants and coverage are checked in [requirements checklist](checklists/requirements.md). No dependency/library upgrades, application implementation or runtime certification were performed during planning.
