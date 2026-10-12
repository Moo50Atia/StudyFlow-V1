# Specification Report: Temporal Terminal Pipeline

**Feature:** 018-v3-terminal-temporal  
**Date:** 2026-10-09  
**Scope:** Specification, design and implementation tasks only. Application implementation, runtime tests, provider calls and service startup were not performed during this authoring task.

## Outcome and fixed owner decisions

Created a separate terminal orchestration feature using the requested `speckit-specify`, `speckit-plan` and `speckit-tasks` workflows. Temporal schedules work durably and exposes execution history through its native UI. A local operational journal supplies detailed chronological metadata for actual steps, attempts, controls and recovery.

The approved design admits four subjects globally, creates one multi-PDF notebook per subject, serializes each conversation and finishes all mapped Lessons before scene generation. It retains the existing approved prompts/framework, account mo50atiapro, notebooklm-py upload/chat, selected scene provider/model and React/TypeScript/Vite build contract. New runs and saved handoffs are supported. Unfinished legacy checkpoint adoption and Laravel integration are outside this feature.

The JEV waiver remains `owner_waived_terminal_demo` with `jev_called=false`; it does not create scores, qualification passes or application approval. Private content remains in artifacts referenced by safe workflow payloads. No new business decision remains marked for clarification.

## Repository and documentation evidence

Inspected the business authority in Conversation 23, later owner decisions, V3 spec map, prerequisite 016/017 artifacts and contracts, current terminal runner, NotebookLM adapter/ports/authentication, scene-generation services/store/builders/providers, Temporal worker/settings, pytest configuration/guards and installed SDK/CLI interfaces. Spec 017's implementation report is attributed evidence; it was not treated as a newly verified runtime result.

Official Temporal documentation was consulted for event history, Activities and error handling, child workflow policies, Continue-As-New, message passing, observability and persistent local server options. Sources and design rationale are linked in [research.md](research.md). The checked-in constitution remains an unratified template; no binding principles were invented from its placeholders.

## Initial design gaps and resolutions

| Finding | Severity | Inspected evidence | Resolution and governing work |
|---|---|---|---|
| Existing terminal commands are outside the Laravel-facing durable worker; wrapping the whole command hides recovery boundaries. | High | `scripts/v3/run_multi_pdf_notebooklm.py:228`; `v3_engine/worker.py:45`; `v3_engine/settings.py:13` | Separate terminal namespace/worker, singleton supervisor and subject workflows; step services called by Activities. T010–T015. |
| A profile label does not select or prove the authenticated account; concurrent SDK writers can overwrite the credential cache. Headless disablement alone does not disable external refresh commands. | High | `v3_engine/notebooklm/sdk_provider.py:242`; inspected installed notebooklm-py owner-role, refresh and close behavior | Explicit owner proof, isolated per-subject session copies, master-byte protection and blocked external refresh/browser calls. T006–T007, A13–A14. |
| The runner reaches through `.client`, and repeated Continue recovery lacks a strong history anchor. | High | `scripts/v3/run_multi_pdf_notebooklm.py:307`, `:369`, `:374`; current ports | Extend provider capabilities; retain intent/dispatch/receipt and anchored pre-send history; never blindly resend uncertain chat. T010–T011, T016–T017, A10–A12. |
| A whole-inventory scene service and synchronous builds obscure paid-send/source/build recovery and can block heartbeats. | High | `v3_engine/scene_generation/service.py:42`; `v3_engine/scene_generation/builder.py:146` | Individual generation/receipt/source/build steps, compatible facade, owned subprocess cancellation and responsive heartbeat; no paid request for rebuild. T008–T009, T020–T023. |
| Per-submission capacity, released Activity locks, default parent closure and history rotation could allow conflicting writers or premature slot release. | High | Existing standalone artifact-store locking and installed Temporal child/CAN behavior | Singleton global capacity, durable managed ownership/epochs, explicit REQUEST_CANCEL and final-chain settlement before capacity release. T004–T005, T012–T013, T020–T021, T026–T027. |
| Workflow history alone does not record every application step or provide truthful worker-offline progress. | Medium | Existing workflow/settings and Temporal observability/query contracts | Ordered idempotent SQLite journal, measured/null durations, replay-safe logs, safe indexed metadata and last-known status with freshness. T024–T027, A19–A24. |

## Fresh consistency review findings resolved before completion

A read-only review after design identified the following additional gaps. All were corrected in this spec's artifacts:

1. **High:** Supervisor bootstrap lacked durable launch/start/settlement evidence after history expiry. Added a subject registry, actual close proof and refusal to restart unresolved previously started chains. Final JSON alone cannot prove closure.
2. **Medium:** Completed runs with damaged compiled artifacts had no recovery route. Added build-only recovery for failed/missing/corrupt output while preserving the original report and prior build ordinals.
3. **Medium:** Build-only recovery could depend on provider credentials or original handoffs. Made it use retained source/scaffold/toolchain and captured nonsecret metadata without constructing a provider client.
4. **Medium:** Reconciliation implementation would precede its governing tests. T011 now implements fresh capture only; new pending-attempt reconciliation is governed by T016 before T017. Existing legacy recovery stays compatible.
5. **Medium:** The CLI could return `control_pending` without that code in the error contract. Added it to the safe enum and retained command identity after acknowledgement timeout.
6. **Medium:** Queued cancellation could be relaunched after supervisor bootstrap. Added atomic `queued → cancelled_before_start`, cancellation receipt/report, a transaction fence shared with launch reservation and permanent bootstrap exclusion. A15/T018/T026 cover this behavior.

Also corrected operation identities to hash private scene keys before entering workflow history/logs. The final independent read-only review confirmed these gaps were resolved and found no remaining material cross-artifact contradiction. This is document evidence, not execution evidence.

## Files created and substantive content

| File | Purpose and necessary decisions |
|---|---|
| [spec.md](spec.md) | Four stories, 24 requirements, 28 acceptance cases and eight measurable outcomes; fixed scope and owner choices. |
| [plan.md](plan.md) | Evidence-based reuse/extension/new-file decisions, separate orchestration topology, queues, durable scheduling and implementation boundaries. |
| [research.md](research.md) | Repository/installed-SDK evidence, official sources, alternatives, rationale and tooling limitations. |
| [data-model.md](data-model.md) | Exact identifiers, private artifact layout, SQLite tables, lifecycle/control/ownership constraints and retention. |
| [contracts/cli.md](contracts/cli.md) | Manifest fields, commands, validation, acknowledgements, retry consent, exit codes and offline status. |
| [contracts/workflow.md](contracts/workflow.md) | Workflow/Activity operations, phase/state transitions, limits, timeouts, retries, controls, history rotation and safe errors. |
| [contracts/services-and-downstream.md](contracts/services-and-downstream.md) | Provider/service extension interfaces, receipt recovery and boundaries for future web integration. |
| [acceptance-matrix.md](acceptance-matrix.md) | Requirement/case/test/task mapping and separate fixture/server/build/browser/live evidence lanes. |
| [tasks.md](tasks.md) | 32 unchecked, dependency-ordered tasks with concrete files, coverage and completion conditions. |
| [tasks-note.md](tasks-note.md) | Owner choices, repository traps and implementation guidance requested by the user. |
| [implementation-notes.md](implementation-notes.md) | Concrete repository extension points, lifecycle pitfalls and fixed boundaries. |
| [task-execution-plan.md](task-execution-plan.md) | Entry conditions, direct ordered execution, pre/post checks, workspace/baseline handling, blockers and final report requirements. |
| [quickstart.md](quickstart.md) | Future verification/setup/operator commands and expected results, explicitly not claims of execution. |
| [checklists/requirements.md](checklists/requirements.md) | Completed specification quality checks and pending runtime gates. |
| This report | Authoring scope, evidence, corrections, coverage and final checks. |

Shared metadata was updated only as needed to register the new feature: `.specify/feature.json` now selects 018 and `specs/V3-spec-map.md` adds the 018 entry/integration boundary. The selector intentionally remains on 018. No other numbered spec or application file was edited by this task.

## Skill execution and tooling limits

- Resolved the local Spec Kit templates and completed their required specification/plan sections.
- Ran `setup-plan.sh --json`, `setup-tasks.sh --json` and `check-prerequisites.sh --json --require-tasks --include-tasks` for the selected feature. Tasks prerequisites were rechecked after quickstart existed.
- `.specify/extensions.yml` is absent; corresponding extension hooks were skipped.
- `.specify/scripts/bash/update-agent-context.sh` and a replacement updater are absent. Agent-context automation was therefore not run. Repository-specific guidance is supplied by tasks-note and implementation-notes; no root instruction file was invented.
- The plan script's BRANCH field was empty while git reported `main`; the documents record git truth and no feature branch was created/switched.
- Applied a fresh `speckit-analyze` read-only consistency review after task generation, followed by authoring corrections and final validation.

## Coverage and execution order

All FR-001–FR-024, A01–A28 and SC-001–SC-008 have planned test/task coverage in the acceptance matrix. Tests govern the actual service/client/workflow boundaries. Race cases require overlapping operations/processes; restart uses the same persistent DB; replay consumes recorded histories; connected tests substitute vendor transports only. Doubles are never evidence of live providers.

The 32 tasks consist of setup 3, foundations 6, US1 6, US2 8, US3 4, US4 2 and final gates/report 3. Dependencies point backward; only independent test authoring T006/T008 is marked parallel. Shared production edits remain sequential. Every task specifies deliverables/files, dependencies, requirement/case coverage and completion conditions.

## Final authoring checks

- Requirement/scenario/success-criterion counts and references checked; no unknown task references or forward task dependencies.
- Task checkbox/ID format and per-task dependency/coverage/completion fields checked.
- Local Markdown links and unresolved/template placeholders checked after the report was added.
- State, field, command, error and lifecycle terminology reviewed across contracts/tasks/notes, including queued cancellation and damaged-build recovery.
- `git diff --check` completed without whitespace errors.
- Protected workspace inventory of **8,853** existing tracked/nonignored files remained byte-identical to the authoring baseline. Aggregate SHA-256: `d2e22de48d7e77355806c6e539cc15e78ee891ec7a6b54413f1a8d4f66429df0`. The inventory excludes the new feature, its two authorized shared metadata files, `.env`, private storage and the operator input/output test folder; those exclusions do not authorize edits to them. No application/other-spec edits were made by this task.

## Remaining prerequisites and limits

Implementation has not started; every task remains unchecked. The future implementer must establish the current 017/legacy baseline, inspect any concurrent workspace changes and execute the specified local behavior/server/concurrency/restart/replay/build/browser gates. These checks cannot be certified from documents.

Live G-LIVE additionally requires the approved verified account/session, selected provider/model and credentials, authorized disposable inputs and local tools. A skipped live gate remains open and prevents a fully live-verified claim. Existing credentials, account tier and previous run artifacts do not prove future quota or access. No live quota guarantee is assumed.

No commits, deployment, application implementation/tests, provider calls, browser login or background service startup occurred during this specification task.
