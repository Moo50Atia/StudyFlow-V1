# Direct Execution Plan for the Implementation Agent

Read [spec](spec.md), [plan](plan.md), [research](research.md), [data model](data-model.md), all three contracts, [acceptance matrix](acceptance-matrix.md), [tasks-note](tasks-note.md), [implementation-notes](implementation-notes.md), [quickstart](quickstart.md) and [tasks](tasks.md), in that order. Follow ordered tasks, not a newly generated alternative plan.

## Entry conditions

1. Confirm working directory `/home/moahmmed/college_project`, applicable AGENTS instructions, selector018 and actual current branch; no reset/branch/commit/deploy.
2. Capture pre-existing status/file hashes including tracked and untracked source. Existing changes are not owned by this implementation. Never replace/remove them as cleanup.
3. Inspect current standalone NotebookLM runner, SDK ports/auth,017 source/build/receipts and its latest report. Run T001 baseline rather than trusting checked tasks/reported metrics.
4. Verify Python environment/installed SDKs and Node/npm pins from017. Missing required tools blocks dependent build/server lanes, not independent pure tests.
5. Remote credentials/provider account are needed only for a requested live gate/operator run. Fake transport tests need no browser/login. No full application stack, Laravel or JEV API is an entry prerequisite.

## Ordered task groups

| Group | Tasks | Before | After/checkpoint |
|---|---|---|---|
| Baseline/types | T001–T003 | Quickstart baseline command; snapshot workspace | `test_manifest.py test_contracts.py test_privacy.py` offline: input bytes/config/ref serialization ready. |
| Receipts/services | T004–T009 | Rerun preceding tests; read service contracts | `test_store.py test_journal.py test_credentials.py test_scene_steps.py` plus017/SDK baseline regressions: no blind send and no master-cookie writer. |
| Automatic run | T010–T015 | Service foundation green; explicit Temporal opt-in | `test_notebook_steps.py test_legacy_compatibility.py test_workflow.py test_scheduler.py test_cli.py`: all mapped Lessons and barrier reached through registered Activities. |
| Recovery/controls | T016–T023 | Automatic fixture run green; preserve its history/artifacts | `test_recovery.py test_controls.py test_concurrency.py test_retry.py`: actual overlap/crash evidence, requested vs applied, no paid rebuild. |
| Monitoring/lifecycle | T024–T027 | Controls stable; inspect safe serialized payloads | `test_observability.py test_privacy.py test_restart.py test_rotation.py test_replay.py`: journal complete, stale fallback accurate, same DB restart and deterministic replay. |
| Saved handoffs | T028–T029 | Prior lifecycle cases green; disable NotebookLM client | `test_saved_results.py` plus CLI: supported formats and partial/no-prompt outcomes truthful, zero NotebookLM calls. |
| Gates/report | T030–T032 | All focused groups green | Quickstart full offline/Temporal/integration/browser lanes, UI observation, optional G-LIVE, protected diff/report. |

Run targeted pytest commands using `-c v3_engine/pyproject.toml`, relevant files and lane flags from quickstart. Test authoring precedes production edits inside each group. New tests must collect and fail on actual boundary behavior; missing imports/syntax only are setup errors. Existing passing behavior is regression, not forced red. Record red assertion, green result and refactor evidence without attaching secrets.

## Baseline failures and workspace collisions

- Distinguish failures existing at T001 from regressions introduced here. Rerun only to investigate a concrete risk; no unrelated repairs.
- If another agent is editing a needed shared file, coordinate ownership before modification. Do not overwrite a newer017 implementation based on this documentation snapshot. Adapt extension points within the fixed contracts and record inspected differences.
- Tests/build artifacts use temporary/private roots; no fixtures or package caches enter root application assets. Only stop processes this execution started; no account-wide notebook cleanup.

## Real blockers and safe progress

Stop dependent external work for wrong/unverified account, auth/rate limit, missing API credentials, uncertain delivery without proof, history conflict, changed immutable inputs/settings, required storage failure or incompatible retained017 artifact contract. Report exact safe code, affected IDs/artifact refs, evidence and available action. Continue independent offline review/tests; no fake passing result or invented content.

Uncertain chat is reconcile-only in the existing conversation. Creation/upload/generation uncertain resend requires explicit duplicate-risk consent under the CLI contract and lock ownership. No library/Activity default grants that consent. Required real server/build/browser gates failing means local completion is incomplete. Optional G-LIVE missing credentials means live verification remains open; it does not convert local fixtures into live proof.

## Final completion criteria

- All 32 deliverable tasks satisfied by evidence and all 28 cases covered; requirement-to-test/task matrix reconciled.
- Mandatory local lanes (offline, real Temporal scheduling/controls/restart/rotation/replay, actual concurrent locks/build cancellation, eight-PDF connected fixture and real build/browser gate) pass with exact commands recorded.
- Native UI shows safe stages/Activities; local logs correlate all steps; raw source/prompt/cookie/body excluded from serialized histories/errors.
- Final report retains input/template/provider/model hashes, Lesson/scene counts, receipts/source/dist refs, demo waiver and truthful complete/partial state.
- Existing standalone commands/legacy histories pass focused regressions; no app qualification/review state or other numbered spec edits, commits/deployment or unrelated process shutdown.
- Live G-LIVE is passed only with real evidence. If open, explicitly state local implementation verified and live workflow unverified; do not say all runtime integration is finished.

## Required implementation report

Write `implementation-report.md` in this directory with baseline/prerequisites, protected workspace inventory, concrete changes/rationale, task and scenario evidence, red/green failures, exact commands/results/durations, fixture vs real-server/build/browser vs live separation, API usage/cost unknowns, result artifact refs/counts/hashes, replay/concurrency/restart proof, remaining gates/blockers and every owned process created/reaped. Update task checkboxes only after its completion condition is met; the G-LIVE outcome is recorded separately from authoring its test.
