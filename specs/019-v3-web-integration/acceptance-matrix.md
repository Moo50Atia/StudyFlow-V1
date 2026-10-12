# Acceptance, Requirement and Test Coverage

All paths below are proposed tests, not existing passing evidence. PHP paths use tests/Feature/V3/WebPipeline/ unless Integration is explicit; Python paths use v3_engine/tests/web_pipeline/. Every scenario is stable and applies to the outcome in spec.md. T060 connected coverage supplements focused tests; it does not replace actual concurrency/browser/replay evidence.

| Case | Requirements / success criteria | Governing tests | Implementation tasks | Expected evidence |
|---|---|---|---|---|
| A01 | FR-001,FR-025,FR-026 / SC-001 | TerminalImportTest.php, ImportedLectureViewTest.php | T012,T014,T016,T019–T021,T064 |8 lectures,63 lessons,89 exact prompts;0 provider calls/scenes for reference |
| A02 | FR-019,FR-025 / SC-005 | TerminalImportTest.php, test_import_workflow.py | T010,T012,T014,T016–T019 |same IDs/digests resume per item; changed destination conflict |
| A03 | FR-025 / SC-005 | TerminalImportTest.php, test_import_workflow.py | T012,T014,T017–T019 |corruption/symlink/escape rejection; dry-run no effects |
| A04 | FR-007,FR-026 | ImportedProjectionTest.php, ImportedLectureViewTest.php, test_projection.py | T005,T013,T015,T016,T020,T021 |original raw/missing-aware eleven fields and historical labels |
| A05 | FR-001,FR-003,FR-004 / SC-002 | SubjectRunIntakeTest.php | T004,T022,T024,T029,T034 |private exact accepted bytes; one lecture/case per accepted PDF |
| A06 | FR-005 / SC-002 | test_notebook_workflow.py | T005,T027–T029 |one framework, separate maps, active sources only |
| A07 | FR-006 / SC-002,SC-005 | test_notebook_workflow.py, Integration/BundleCommitTest.php | T027–T031 |durable turns/cursor/archive/reset before next PDF |
| A08 | FR-007,FR-008 / SC-002 | ImportedProjectionTest.php, test_projection.py, PromptExportTest.php | T013,T015,T027,T030–T033 |variable labels/counts and NULL warnings; unknown map blocked |
| A09 | FR-009 | test_notebook_workflow.py | T027–T029,T033 |settled local fail continues safely; auth/quota/uncertainty pauses |
| A10 | FR-010 / SC-003 | PromptExportTest.php,test_exports.py | T032–T034 |all/scoped exact descriptions, no generation calls |
| A11 | FR-012 / SC-003,SC-004 | ManualSceneImportTest.php | T035,T037,T038,T044 |exact current prompt/manual lineage, no fictitious attempt |
| A12 | FR-012,FR-019 / SC-005 | ManualSceneImportTest.php | T035,T037,T044 |idempotent same bytes; changed/stale conflicts |
| A13 | FR-004,FR-011,FR-015 / SC-003 | EligibleAuthoringRequestTest.php,test_scene_execution.py | T006,T035,T036,T038–T041,T044,T062,T063 |genuine request with real pass or explicit matching demo gate; only selected actual API request; captured thinking/model, no fallback |
| A14 | FR-013,FR-014 / SC-005 | test_scene_execution.py,test_notebook_workflow.py | T027,T029,T036,T040,T041 |verified restore/no automatic duplicate send; explicit consent |
| A15 | FR-013 / SC-005 | test_scene_execution.py | T036,T037,T040,T041 |retained local recovery, all-build unique evidence, tamper rejected |
| A16 | FR-015,FR-016 / SC-004 | Integration/StaticSceneChecksTest.php,test_browser_checks.py | T006,T035,T038,T042,T043 |actual complete7 checks/capture; waiver distinct from pass |
| A17 | FR-002,FR-016 / SC-004,SC-006 | SceneReviewTest.php | T045,T047,T048,T063 |owner-only real isolated preview/first-frame/current gate |
| A18 | FR-017 / SC-005 | Integration/ReviewRaceTest.php,Integration/CorrectionDeliveryTest.php,test_review_effects.py | T045–T049 |one winning concurrent decision; exact consumption |
| A19 | FR-018 | Integration/CorrectionDeliveryTest.php,test_review_effects.py | T046,T049 |manual waits replacement; API correction real successor/check/review |
| A20 | FR-015,FR-024,FR-028 / SC-004 | EvidenceLineageTest.php | T048,T050,T051 |source→actual ancestry→approval, retained deletion blockers |
| A21 | FR-019,FR-020 / SC-005 | Integration/AdmissionTest.php,test_admission.py,test_controls_capacity.py | T010,T023,T025,T026,T049,T053,T056 |fresh startup/admission/ack-loss stable IDs |
| A22 | FR-022 | test_controls_capacity.py | T028,T053,T055,T059 |pause/cancel applied at settled boundary; paused cancel wakes |
| A23 | FR-021 | test_admission.py,test_controls_capacity.py | T011,T023,T026,T028,T041,T053,T056 |shared4/4/2 mixed capacity, waits release slots |
| A24 | FR-019,FR-020,FR-024 / SC-005 | Integration/CommandRaceTest.php,Integration/BundleCommitTest.php,test_controls_capacity.py | T008,T010,T026,T030,T031,T053,T055,T056,T058 |actual contention/restart/dedup/stable version/effects |
| A25 | FR-023 / SC-007 | ProgressProjectionTest.php | T033,T052,T057,T059 |real counters/null totals/version/offline age |
| A26 | FR-002,FR-027 / SC-006 | LinkedContentAccessTest.php,SceneReviewTest.php,PrivateMetadataTest.php | T008,T009,T020,T045,T047,T054,T057 |legacy/new listings/download/bulk/CRUD/review deny unauthorized |
| A27 | FR-024,FR-026,FR-028 / SC-006,SC-007 | PrivateMetadataTest.php,EvidenceLineageTest.php,test_privacy_history.py | T011,T017,T031,T050–T052,T054,T057,T058 |actual histories/no secrets or content; safe historical/retention graph |
| A28 | FR-027 / SC-005 | Integration/MigrationUpgradeTest.php,Integration/RolloutTest.php,test_replay.py | T001,T005,T007,T009,T024,T061,T062,T064 |unchanged populated data/defaults and true legacy replay |

## Requirement index

FR-001:A01,A05; FR-002:A17,A26; FR-003:A05; FR-004:A05,A13; FR-005:A06; FR-006:A07; FR-007:A04,A08; FR-008:A08; FR-009:A09; FR-010:A10; FR-011:A13; FR-012:A11,A12; FR-013:A14,A15; FR-014:A14; FR-015:A16,A20; FR-016:A16,A17; FR-017:A18; FR-018:A19; FR-019:A02,A12,A21,A24; FR-020:A21; FR-021:A23; FR-022:A22; FR-023:A25; FR-024:A20,A24,A27; FR-025:A01–A03; FR-026:A01,A04,A27; FR-027:A26,A28; FR-028:A20,A27.

## Evidence boundaries

No comparison of generated lesson counts establishes a quantitative quality multiplier. Offline external doubles prove deterministic application integration; actual browser runs prove local rendering isolation/checks; real Temporal workers/replay prove durable scheduling; live credentials/proof notebook plus real selected API calls prove only that dedicated live run. All four categories must be reported separately. Missing live prerequisites must leave live gates open, not alter statuses or synthesize qualifications.
