# Repository-Specific Implementation Notes

## Evidence and dependency status

The current017 report claims completion with fixture, real build/browser and selected Gemini evidence. This spec's author did not rerun those tests. T001 independently establishes the implementer's baseline. Installed versions inspected were temporalio1.34.0/notebooklm-py0.8.4/httpx0.28.1; no upgrade is required. Legacy namespace/queue come from `v3_engine/settings.py`; new terminal settings must not mutate those defaults or import its Laravel callback auth unnecessarily.

Existing root pytest conftest provides explicit server/live/integration/browser guards. Keep new tests under `v3_engine/tests/terminal_pipeline/` and reuse those flags. `WorkflowEnvironment.start_local(dev_server_existing_path=...)` already appears in tests. Use the installed CLI, free ports and temporary persistent DB; avoid hidden downloads and accidental user-namespace tests.

## Concrete reuse/extension decisions

| Inspected path | Required treatment |
|---|---|
| scripts/v3/run_multi_pdf_notebooklm.py | Extract reusable steps/exports/checkpoint operations; preserve old flags and fresh/resume semantics, with managed-root fence. New manifest runner derives input count rather than inheriting default8. |
| v3_engine/notebooklm/ports.py / sdk_provider.py | Add complete inventory/discovery and explicit owner evidence compatibly. Preserve exact source IDs and optional title aliases. Disable SDK mutating retries and external refresh command in the new worker only. |
| v3_engine/notebooklm/lesson_archive.py | Reuse raw/best-effort Lesson export where applicable; retain all received bodies and Lesson-title linkage. No new educational-output acceptance gate. |
| v3_engine/notebooklm/protocol.py | Reuse unchanged Divide/Execution/combined format constants and source scopes. No alternate prompt fabricated for orchestration. |
| v3_engine/scene_generation/service.py / artifact_store.py | Add resume-aware individual steps and managed-owner context without importing Temporal into service. Existing CLI facade remains tested. |
| v3_engine/scene_generation/builder.py / scaffold | Reuse approved template/build environment and dependency pins; add responsive cancellation/heartbeat wrapper, not arbitrary model-generated build scripts. |
| v3_engine/worker.py / workflows/case_workflow.py | Read for compatible concepts/replay, do not change/register terminal paths here. |

## Lifecycle details

- A subject's workflow owns scheduling; local status JSON is a projection, not a second scheduler. Its checkpoint/receipt files retain vendor effects needed for recovery.
- Control versions are returned by status and increment for phase/status transitions, accepted control requests and their applied/rejected transitions. A duplicate command must be found before testing a now-stale version. Offline projections are explicitly stale.
- Queued cancellation persists `cancelled_before_start` under the same transaction fence as launch reservation. Bootstrap excludes it permanently; a cancelled report alone cannot stand in for that registry transition. Already launched chains require actual terminal closure proof before their capacity is released.
- The supervisor's child handle follows its Continue-As-New chain; parent drain waits for final result. Paused/blocked children hold slots. Drain has no forced timeout because terminating an in-flight request would not prove rollback. Root validator rejects new admission before unchecked history growth.
- One worker process runs three Worker instances. Use distinct Activity registrations/queues rather than moving sync build work onto the same async IO loop. A second process cannot expand max IO/build counts by polling the same queues.
- Source-ready timeout begins after all upload receipts, not at subject submission. Resume of that timeout is read-only polling with new deadline, not duplicate upload. Missing/malformed source status is an operational provider failure; title change with stableID/manifest is handled by known aliases.
- Build failure is not JEV failure. Successful compilation is not production runtime qualification. The separate browser fixture gate proves only its actual observed interaction.
- Do not trust a workflow timeout to release an OS lock or abandon an old provider request. Ownership transfer requires actual lock availability and predecessor final-state proof.
- Do not reuse a free-tier/other-account credential cache just because it opens the proof notebook via sharing. Unknown owner role blocks; it is not guessed from subscription/name.

## Failure/report hygiene

Catch SDK/provider exceptions at Activity/service boundary and create safe operational failures without chained raw causes. Retain raw receipts/build diagnostics only in private artifacts. TypeSafe/JEV keys are not prerequisites here. Provider endpoint/model/template/toolchain comes from captured017 config; environment credential rotation may change the key, never the captured request policy.

No automatic root artifact cleanup is added. Schema mismatch, disk errors, missing manifest/receipt hash or changed bytes stop mutations with a referenceable blocker. Status should remain readable from intact journal/report metadata. A damaged final artifact may be locally rebuilt; previous source/build directories remain immutable.

The root selector intentionally points to018 after this creation. The skill's setup-plan script returned an empty BRANCH field even though git reports main; document git truth, not a fabricated feature branch. Missing updater tooling is recorded in [research](research.md); never treat the placeholder constitution as ratified policy.
