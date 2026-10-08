# Agent execution plan: 016

Follow this plan directly. Implement only this feature and the precisely identified prerequisite wiring needed for its gates. Do not regenerate the task list, certify mocks as live behavior or promise zero errors.

## Entry conditions and required reads

1. Read applicable `AGENTS.md` files, Conversation23, `specs/V3-spec-map.md`, every016 artifact, and prerequisite/downstream contracts listed in [implementation notes](implementation-notes.md). Verify actual code remains consistent with the inspected evidence.
2. Select `specs/016-v3-notebooklm-direct-pdf` explicitly through `SPECIFY_FEATURE_DIRECTORY` or temporary `.specify/feature.json`; save prior selector bytes and restore after implementation if selection was temporary. Never infer the feature from Git branch `main`.
3. Record Git status and hashes/diff of existing work before edits. This workspace contains substantial uncommitted implementation; preserve it. Do not reset, stash indiscriminately or delete caches/profiles.
4. Provision a disposable DB/private disk/worker environment; record baseline PHP/Python results from quickstart. No production DB migration or live case mutation while establishing baseline. Identify failures by exact test/assertion before repair.
5. T002 must prove SDK/dependency signatures/retry semantics; current installed MCP and login success do not prove SDK compatibility. Approvals already cover the exact root framework and test PDF provider upload. Explicit setup may require user login; pipeline/runtime may not open a browser.
6. Create `implementation-report.md` and maintain evidence as tasks finish. Existing review reports are documentation/baseline evidence, not replacement implementation results.

## Ordered groups and checkpoints

Run the named behavioral tests **before** implementation to establish meaningful red where behavior is missing, then the same tests after implementation. A missing import/syntax error alone does not satisfy the before checkpoint. Existing correct behavior stays green with regression tests.

| Group | Task order | Before / after commands (future instructions) | Exit checkpoint |
|---|---|---|---|
| A Baseline | T001→T002 | Baseline/version/dependency commands in quickstart; clean isolated install + pip check | Protected workspace and named failures; SDK0.8.4 signature/range evidence |
| B Capture | T003→T004 | `php artisan test tests/Feature/V3/NotebookLM/ProtocolCaptureTest.php tests/Feature/V3/NotebookLM/FrameworkCatalogTest.php` | Additive migration, exact framework import, immutable direct capture/legacy replay |
| C Ports/auth | T005→T006 | `.venv/bin/python -m pytest v3_engine/tests/notebooklm/test_credentials.py v3_engine/tests/notebooklm/test_sdk_contract.py v3_engine/tests/notebooklm/test_privacy.py` | Lifecycle/cache bridge, no runtime login, actual HTTP single-send verified; legacy auth regressions green |
| D Source | T007→T008 | `php artisan test tests/Feature/V3/NotebookLM/DirectIntakeTest.php tests/Feature/V3/NotebookLM/SourceManifestTest.php`; pytest `test_source_files.py test_source_recovery.py` under same Python folder | Exact original files, two independently reconciled roles, ready/configured bound state, no OCR |
| E Sequence | T009→T010 | pytest `test_protocol.py test_bundle_parser.py`; `php artisan test tests/Feature/V3/NotebookLM/DirectMapTest.php` | Correct map IDs/scopes/transcript, no extra step/coverage questions, invalid replies rejected |
| F Projection | T011→T012 | `php artisan test tests/Feature/V3/NotebookLM/CombinedLessonCommitTest.php` plus bundle parser tests | All-or-none rows/artifacts/receipt; exact duplicate refs, field preservation, real cursor versions |
| G Recovery | T013→T014 | `php artisan test tests/Integration/V3/NotebookLM/DirectClaimConcurrencyTest.php tests/Integration/V3/NotebookLM/DirectControlRaceTest.php tests/Feature/V3/NotebookLM/DirectRecoveryTest.php`; pytest `test_turn_recovery.py` | Overlapping-process evidence, fences, no uncertain resend, anchored recovery and idempotent schedule |
| H Workflow | T015→T016 | pytest `test_workflow_replay.py test_workflow_progression.py`; `php artisan test tests/Integration/V3/NotebookLM/ConnectedDirectPipelineTest.php` | Saved legacy histories replay; three work items drive registered activities/receipts and real review boundary |
| I Evidence | T017→T018 | `php artisan test tests/Feature/V3/NotebookLM/DirectAccessBoundaryTest.php tests/Feature/V3/NotebookLM/DirectEvidenceTest.php`; privacy tests | Actual owner/admin/legacy HTTP boundaries; truthful direct chain; no secret/content logs |
| J Completion | T019→T020 | All focused/regression/live commands in quickstart; final diff/routes/schedule/coverage review | Live PDF/full lesson loop and connected scene/review proof; waiver and blockers labelled; rollout decision justified |

Shorthand Python filenames above always resolve under `v3_engine/tests/notebooklm/`. Re-run impacted groups after later fixes. T015 tests use actual registered production activities with transport doubles only where documented, not fake `ready` activities. Replay histories must cover legacy start, source, lesson and review waits. Do not trust a patch name without replay evidence.

## Recovery and workspace rules

- Read actual committed state before trusting a checked task or retrying work. Duplicate callback/signal delivery uses the same digest/ref, never a new claim/send identity.
- If existing prerequisite service is broken, reproduce it and name the smallest affected path. Fix only a necessary integration defect, with its regression evidence. A large unrelated remediation is a separate task, not silently part of016.
- During provider tests, record private effect/query refs before sending. A timeout ends new sends for that effect; polling/history and reposting known results may continue. Never make a fresh notebook because a recent-list query found nothing.
- Preserve dedicated auth profile. Do not print cookies/API keys or allow raw SDK errors in ordinary logs. Setup credentials and runtime credentials are separate lifecycle responsibilities.
- After a hold/cancel/suspension, retain in-flight results privately, reload guard state under lock and refuse advancement. Do not bypass a downstream review gate to make the next Continue appear successful.

## Stop conditions

Stop dependent implementation/live work if: approved file bytes changed; SDK repeats an uncertain mutation phase or cannot correctly scope sources/conversation; source ID/turn identity is uncertain; PHP/Python grammar differs; deployed DB cannot enforce claims atomically; legacy histories do not replay; downstream current-pass/review contract unavailable; required credentials/provider/browser/Temporal runtime unavailable. Record exact evidence and continue unaffected tasks. No production placeholder, auto-pass, guessed provider ID, weakened ownership or modified prompt can resolve these blockers.

Live provider nonadherence to combined format is a real gate failure. Preserve the raw private reply and exact failing grammar; propose a versioned format correction for owner review without changing canonical files or silently accepting missing data.

## Final completion and required report

Complete only when T001–T020 and N01–N24 have meaningful evidence, all affected legacy regressions are green or precisely demonstrated unchanged baseline failures, actual concurrent claims and legacy replay pass, and live source/lesson plus connected qualification/scene/review gates pass. A demo-waived JEV run is demo completion only; full production pipeline remains incomplete until real JEV passes. No direct-default rollout on partial results.

Report: outcome complete/partial/blocked; baseline and preserved changes; file/migration/dependency/approval capture; per-group meaningful red/green; requirement/case/test/command/result table; actual HTTP retry/concurrency/replay evidence; live source ready and transcript counts, source fidelity spot checks; JEV live versus waiver, browser and original-teacher decision evidence; security/retention results; failed/skipped/unrun tasks and exact blockers; rollout/default state; selector restoration. Do not include credentials or private educational responses in the public report. Never commit, deploy or start another spec without instruction.
