# Tasks: V3 Web Pipeline Integration

**Input**: [spec.md](spec.md), [plan.md](plan.md), [data-model.md](data-model.md), [research.md](research.md), contracts and [acceptance-matrix.md](acceptance-matrix.md).  
**Execution**: Follow [task-execution-plan.md](task-execution-plan.md) and [tasks-note.md](tasks-note.md). The earlier agent marked T001–T065 complete; the 2026-10-11 review found unsupported completion claims and missing integration. Those historical checkmarks are not current acceptance evidence. Execute the open repair tasks T066–T115 below using [repair-agent-prompt.md](repair-agent-prompt.md); preserve the original requirement, scenario and task identities.

Tests are mandatory here: behavioral red evidence precedes governed new implementation; passing existing behavior receives regression coverage. Dependencies below are explicit. A test task completes when tests collect/run and exhibit the intended missing behavior, or documented existing behavior passes; missing imports/syntax are not red evidence. All other completion conditions include their governing tests passing.

## Phase 1 — Setup

- [X] T001 Record dirty workspace hashes, selected dependency baselines, tool versions, feature resolution and migration status in specs/019-v3-web-integration/implementation-report.md; dependencies:none; FR-027/A28. Done: unrelated changes are enumerated/protected and baseline failures separated from new failures.
- [X] T002 Build test-only two-owner/admin/inactive fixtures, two-PDF archives and malformed/missing-field/static-scene examples in tests/Support/V3/WebPipelineFixtures.php and v3_engine/tests/web_pipeline/conftest.py; dependencies:T001; FR-002,FR-007,FR-025/A03,A04,A26. Done: fixtures are deterministic, contain no credentials and create no production placeholders.
- [X] T003 Add boundary/schema contract tests in tests/Feature/V3/WebPipeline/SchemaContractTest.php and v3_engine/tests/web_pipeline/test_contracts.py; dependencies:T002; FR-004,FR-015,FR-024,FR-027/A24,A27,A28. Done: new invariants, null-vs-empty context and32KiB/private-ref rejection are behaviorally asserted before schema/contracts.

## Phase 2 — Foundations (blocks all stories)

- [X] T004 Implement aggregate/notebook/command/outbox/event/import tables in database/migrations/2026_10_10_000019_create_v3_web_pipeline.php and app/Models/V3/{SubjectRun,SubjectRunItem,NotebookInstance}.php; dependencies:T003; FR-001,FR-004,FR-019,FR-020,FR-025/A02,A05,A21. Done: constraints/ownership/immutable captures match data-model and T003 passes for these tables.
- [X] T005 Implement section/binding/case/import-lineage extensions and legacy links in database/migrations/2026_10_10_000020_extend_v3_projection_provenance.php; dependencies:T004; FR-005,FR-007,FR-026,FR-027/A04,A06,A28. Done: shared notebook bindings coexist with legacy uniqueness; missing fields remain NULL; old populated rows unchanged.
- [X] T006 Implement manual-import/gate/authoring-request/candidate/review provenance extensions in database/migrations/2026_10_10_000021_extend_v3_candidate_review_gates.php and app/Models/V3/{ManualSceneImport,QualificationGate}.php; dependencies:T005; FR-012,FR-015,FR-016,FR-017/A11,A13,A16,A17. Done: authoring_requests.qualification_id is nullable with preserved FK, eligibility_basis defaults real_jev, prompt_gate_id is a real FK, and strict branch invariants preserve populated legacy requests while enabling acknowledged demo API requests without fake qualifications; T003 schema checks pass.
- [X] T007 Add populated-upgrade/fresh-migration/regression tests in tests/Integration/V3/WebPipeline/MigrationUpgradeTest.php; dependencies:T006; FR-027/A28. Done: disposable cloned DB and fresh SQLite migrate with intact existing hashes/FKs/approvals; no real DB reset.
- [X] T008 Add fresh-access, legacy-route leakage and concurrent command tests in tests/Feature/V3/WebPipeline/LinkedContentAccessTest.php and tests/Integration/V3/WebPipeline/CommandRaceTest.php; dependencies:T002,T007; FR-002,FR-019,FR-027/A24,A26,A28. Done: real routes test listings/counts/next links/CRUD/bulk/download and two processes race identical/stale commands.
- [X] T009 Implement linked-record ownership resolver and guards in app/Services/V3/WebPipeline/LinkedContentAccess.php, app/Http/Middleware/TeacherAccessMiddleware.php, app/Http/Controllers/{SubjectController,LectureController,SectionController,TeacherStudyFlowController,StudyFlowController,APIfilterController,QuestionController,ExamQuestionController,MohammedTestController}.php and app/Http/Resources/LectureResource.php; dependencies:T008; FR-002,FR-027/A26,A28. Done: T008 denies linked bypasses while unlinked regressions pass; no public path populated.
- [ ] T010 [REOPENED: Audit finding R04; closing through T072,T073] Implement canonical request digests, command/version reservations and outbox/event transactions in app/Services/V3/WebPipeline/{CommandLedger,RunEventStore,RunOutbox}.php; dependencies:T008; FR-019,FR-020,FR-024/A21,A24,A27.
- [X] T011 Implement bounded opaque contracts/root configuration in v3_engine/web_pipeline/{contracts,artifact_refs,settings}.py and config/services.php; dependencies:T003,T010; FR-004,FR-021,FR-024/A23,A27. Done: single captured absolute root, unknown-key/size/symlink rejection, sanitized outcomes and no secret/content payloads.

## Phase 3 — US1: Verified historical import (P1)

**Independent value**: Existing retained material becomes owner-visible with every provider disabled.

- [X] T012 [P] [US1] Add importer verification/dry-run/dedup/partial recovery tests in tests/Feature/V3/WebPipeline/TerminalImportTest.php; dependencies:T011; FR-025,FR-026,FR-019/A01,A02,A03,A04. Done: corrupt root-relative refs and immutable identity conflicts are exercised without providers.
- [X] T013 [P] [US1] Add raw First projection/missing-context tests in tests/Feature/V3/WebPipeline/ImportedProjectionTest.php and v3_engine/tests/web_pipeline/test_projection.py; dependencies:T011; FR-007,FR-008,FR-026/A04,A08. Done: eleven-field completeness/NULL distinction/arbitrary labels/multiple scenes/unchanged prompts asserted.
- [X] T014 [US1] Implement verified archive reader/copy planner in app/Services/V3/WebPipeline/{TerminalArchiveReader,ImportCapture}.php; dependencies:T012; FR-025,FR-026/A01,A02,A03. Done: roots/digests/same-read snapshots validated; credentials excluded; dry-run has no effects.
- [X] T015 [US1] Implement missing-aware lesson projection in v3_engine/web_pipeline/projection.py, app/Services/V3/WebPipeline/ProjectLessonBundle.php and affected app/Services/V3/Prompts/PromptContextReader.php consumers; dependencies:T013,T014; FR-007,FR-008/A04,A08. Done: preserve raw outputs, exact prompts, null-aware context digest and strict legacy complete behavior.
- [X] T016 [US1] Implement per-PDF deterministic import materialization in app/Services/V3/WebPipeline/MaterializeTerminalImport.php; dependencies:T015; FR-001,FR-025,FR-026/A01,A02,A04. Done: one lecture/case per PDF, genuine imported map/query/section/prompt lineage, atomic receipts and no invented scene/API/JEV records.
- [ ] T017 [US1] [REOPENED: Audit finding R09; closing through T075] Add local import-workflow tests in v3_engine/tests/web_pipeline/test_import_workflow.py; dependencies:T016; FR-020,FR-024,FR-025,FR-026/A02,A03,A27.
- [ ] T018 [US1] [REOPENED: Audit finding R03,R09; closing through T076,T077] Implement ApplicationImportWorkflow and local wrappers in v3_engine/web_pipeline/{import_workflow,import_activities}.py; dependencies:T017; FR-020,FR-025,FR-026/A01,A02,A04.
- [ ] T019 [US1] [REOPENED: Audit finding R09; closing through T078] Implement CLI in app/Console/Commands/V3/ImportTerminalRun.php and register in routes/console.php; dependencies:T018; FR-025,FR-026/A01,A02,A03.
- [X] T020 [US1] Add imported owner navigation tests in tests/Feature/V3/WebPipeline/ImportedLectureViewTest.php; dependencies:T019; FR-001,FR-002,FR-007,FR-010/A01,A04,A26. Done: routes render nested63/89-style fixture associations, missing badges and no admin content.
- [X] T021 [US1] Implement canonical imported content views/services in app/Services/V3/WebPipeline/LectureProjection.php, resources/views/{subjects/show,lectures/show}.blade.php and resources/views/v3/teacher/subject-runs/show.blade.php; dependencies:T020; FR-001,FR-007,FR-026/A01,A04. Done: all accepted PDFs/lessons/sections navigable, exact prompt downloads linkable, historical label visible, no DynamicViewModel fabrication.

## Phase 4 — US2: Web PDF processing (P1)

**Independent value**: Teacher uploads two PDFs and receives saved separately mapped lessons/prompts without scene APIs.

- [X] T022 [P] [US2] Add ordered intake/duplicates/permission/private-byte tests in tests/Feature/V3/WebPipeline/SubjectRunIntakeTest.php; dependencies:T021; FR-001,FR-003,FR-004/A05. Done: limits, per-owner hash reuse, rejected attempts and no public PDF path tested.
- [ ] T023 [P] [US2] [REOPENED: Audit finding R01,R06; closing through T069] Add real signed bridge startup/outbox/ack-loss tests in tests/Integration/V3/WebPipeline/AdmissionTest.php and v3_engine/tests/web_pipeline/test_admission.py; dependencies:T021; FR-019,FR-020,FR-021/A21,A23,A24.
- [ ] T024 [US2] [REOPENED: Audit finding R01; closing through T070,T071] Implement run intake/capture in app/Services/V3/WebPipeline/{SubmitSubjectRun,AcceptRunItem}.php and app/Http/Controllers/V3/Teacher/SubjectRunController.php; dependencies:T022; FR-001,FR-003,FR-004,FR-027/A05,A28.
- [ ] T025 [US2] [REOPENED: Audit finding R01; closing through T071,T074] Implement signed application bridge/outbox dispatcher in app/Services/V3/WebPipeline/{TerminalBridgeClient,DispatchRunOutbox}.php, v3_engine/web_pipeline/bridge.py and v3_engine/bridge.py, app/Console/Commands/V3/DispatchWebPipeline.php and routes/console.php; dependencies:T023,T024; FR-019,FR-020/A21,A24.
- [ ] T026 [US2] [REOPENED: Audit finding R01,R03; closing through T074,T082] Extend trusted shared scheduling and worker registrations in v3_engine/terminal_pipeline/{supervisor,worker,settings,store}.py; dependencies:T025; FR-020,FR-021,FR-024/A21,A23,A24.
- [ ] T027 [US2] [REOPENED: Audit finding R02,R06; closing through T080,T087] Add per-PDF scope/turn/archive/reset/auth/failure tests in v3_engine/tests/web_pipeline/test_notebook_workflow.py; dependencies:T026; FR-005,FR-006,FR-008,FR-009,FR-014/A06,A07,A08,A09,A14.
- [ ] T028 [US2] [REOPENED: Audit finding R02,R03,R05; closing through T082] Implement ApplicationSubjectWorkflow in v3_engine/web_pipeline/subject_workflow.py; dependencies:T027; FR-005,FR-006,FR-009,FR-021,FR-022/A06,A07,A09,A22,A23.
- [ ] T029 [US2] [REOPENED: Audit finding R02,R04; closing through T083,T084,T086] Implement private service-context/remote-operation wrappers in v3_engine/web_pipeline/{activities,operation_store}.py and reusable v3_engine/notebooklm/terminal_service.py extensions; dependencies:T028; FR-003,FR-005,FR-006,FR-009,FR-014/A05,A06,A07,A09,A14.
- [ ] T030 [US2] [REOPENED: Audit finding R02,R04; closing through T085] Add real callback/atomic cursor/concurrent bundle tests in tests/Integration/V3/WebPipeline/BundleCommitTest.php; dependencies:T029; FR-006,FR-007,FR-019/A07,A08,A24.
- [ ] T031 [US2] [REOPENED: Audit finding R01,R02,R04; closing through T070,T073,T085] Implement signed internal claims/contexts/artifact/results controller and callback projection in app/Http/Controllers/V3/Internal/WebPipelineController.php, app/Services/V3/WebPipeline/{ClaimOperation,CommitOperation,AcceptWebLesson}.php and routes/api.php; dependencies:T030; FR-006,FR-007,FR-008,FR-024/A07,A08,A24,A27.
- [ ] T032 [US2] [REOPENED: Audit finding R02,R05; closing through T088] Add complete/scoped export/no-paid-call tests in tests/Feature/V3/WebPipeline/PromptExportTest.php and v3_engine/tests/web_pipeline/test_exports.py; dependencies:T031; FR-010,FR-008/A08,A10.
- [ ] T033 [US2] [REOPENED: Audit finding R02,R05; closing through T088] Implement prompt export/finalization in app/Services/V3/WebPipeline/ExportScenePrompts.php and v3_engine/web_pipeline/finalize.py; dependencies:T032; FR-010,FR-023,FR-024/A10,A25.
- [X] T034 [US2] Implement subject upload/run UI and private routes in resources/views/v3/teacher/subject-runs/{create,show}.blade.php, resources/views/subjects/show.blade.php and routes/v3.php; dependencies:T033; FR-001,FR-003,FR-004,FR-010/A05,A10. Done: ordered titles/PDFs, captured mode/waiver visible, error/partial states and export buttons work at real routes.

## Phase 5 — US3: Manual/API scenes (P1)

**Independent value**: One saved prompt becomes a stored checked scene by manual import, without API credentials; API selection separately proves controlled generation.

- [X] T035 [P] [US3] Add manual package/provenance/duplicate/stale tests in tests/Feature/V3/WebPipeline/ManualSceneImportTest.php and authoring-request eligibility tests in tests/Feature/V3/WebPipeline/EligibleAuthoringRequestTest.php; dependencies:T034; FR-012,FR-015,FR-019/A11,A12,A13,A16. Done: exact profile limits, no fake CodingAttempt/JEV, current prompt fences, genuine real-pass requests, acknowledged demo requests and rejection of missing/stale/foreign gates tested through the real claim boundary.
- [X] T036 [P] [US3] Add selected generation/config/prerequisite/receipt recovery tests in v3_engine/tests/web_pipeline/test_scene_execution.py; dependencies:T034; FR-011,FR-013,FR-014/A13,A14,A15. Done: provider call counts, original timing, raw receipt corruption, toolchain/source drift and immutable build attempts tested on actual service.
- [X] T037 [US3] Implement V2 neutral artifact staging/serialization and manual import in app/Services/V3/Authoring/{StaticScenePackage,StoreSceneArtifacts,ImportManualScene}.php and app/Services/V3/WebPipeline/ManualSceneCommand.php; dependencies:T035; FR-012,FR-013/A11,A12,A15. Done: exact three files/current prompt/manual lineage; V1 untouched; immutable candidate/source/pointer hashes.
- [X] T038 [US3] Implement new-path prompt eligibility/waiver recording in app/Services/V3/SceneQualification/{EligiblePromptResolver,RecordQualificationGate}.php and affected app/Services/V3/Authoring/{ClaimAuthoringRequest,SceneCandidateResolver}.php; dependencies:T037; FR-015,FR-016/A11,A13,A16. Done: current demo gate creates a genuine API authoring request with NULL qualification_id and matching prompt_gate_id; actual-pass and legacy branches stay strict; absent/stale/mismatched gates reject; missing context visible, no fabricated JEV rows; governing schema/manual/selected-generation tests cover both eligibility branches.
- [X] T039 [US3] Implement provider catalog mapping/captured selection in app/Services/V3/Settings/SettingsSchema.php, app/Services/V3/WebPipeline/SelectSceneGeneration.php and v3_engine/web_pipeline/scene_context.py; dependencies:T036,T038; FR-004,FR-011/A13. Done: Gemini/GLM/DeepSeek/Qwen mapping, credential aliases, approved endpoints and high-thinking capture; export mode needs no generation key.
- [X] T040 [US3] Resolve retained-response/local-build correctness exposed by T036 in v3_engine/scene_generation/{service,builder,artifact_store}.py; dependencies:T036,T039; FR-013,FR-014/A14,A15. Done: the direct-service recovery subset of T036 passes with verified receipts/timing/real verify_current_build, unique all-build ordinals and original metadata; retain passing existing behavior as regression evidence, with no unrelated017 redesign. New workflow adapter tests remain pending T041.
- [ ] T041 [US3] [REOPENED: Audit finding R03,R05; closing through T090,T091,T092] Implement selected ApplicationSceneWorkflow/service adapters in v3_engine/web_pipeline/{scene_workflow,scene_activities}.py and shared worker/supervisor registration; dependencies:T040; FR-011,FR-013,FR-014,FR-021/A13,A14,A15,A23.
- [X] T042 [US3] Add actual browser/check report/waiver evidence tests in tests/Integration/V3/WebPipeline/StaticSceneChecksTest.php and v3_engine/tests/web_pipeline/test_browser_checks.py; dependencies:T041; FR-015,FR-016/A16. Done: complete seven checks, real screenshot/timing, failed checks block, V1/V2 isolation verified with real container not result doubles.
- [X] T043 [US3] Implement V2 browser transport/record validation and scene gate in app/Services/V3/SceneQualification/{RecordSceneCheck,SceneReviewEligibility}.php and v3_engine/web_pipeline/check_activities.py; dependencies:T042; FR-015,FR-016/A16. Done: actual checks required, demo_review_ready distinct from qualified, empty evidence rejected.
- [X] T044 [US3] Implement manual-import/selected-generation UI/controller actions in app/Http/Controllers/V3/Teacher/SubjectRunController.php and resources/views/v3/teacher/subject-runs/scenes.blade.php; dependencies:T043; FR-010,FR-011,FR-012,FR-015/A11,A12,A13,A16. Done: exact current prompt/candidate status and reported provenance visible; requests/waiver/cost choice explicit.

## Phase 6 — US4: Final teacher review/correction (P1)

**Independent value**: Current checked manual/demo or genuinely qualified scenes can be previewed and decided only by their original teacher.

- [X] T045 [P] [US4] Add basis/owner/preview/stale/concurrent decision tests in tests/Feature/V3/WebPipeline/SceneReviewTest.php and tests/Integration/V3/WebPipeline/ReviewRaceTest.php; dependencies:T044; FR-002,FR-016,FR-017/A17,A18,A26. Done: actual routes and two processes exercise first-frame/current-pointer fences; no admin content.
- [X] T046 [P] [US4] Add correction/no-unexpected-paid-call/exact-consumption tests in tests/Integration/V3/WebPipeline/CorrectionDeliveryTest.php and v3_engine/tests/web_pipeline/test_review_effects.py; dependencies:T044; FR-017,FR-018/A18,A19. Done: signal ack/closed workflow cannot replace exact receipt, manual and API factual/visual branches tested.
- [X] T047 [US4] Extend shared eligibility through app/Services/V3/Review/{EnsureReviewReady,ReviewContext,StartReviewPreview,SubmitReviewDecision}.php and app/Services/V3/Authoring/SceneCandidateResolver.php; dependencies:T045; FR-015,FR-016,FR-017/A16,A17,A18. Done: every consumer rechecks basis/digests/current prompt and actual browser pass, with legacy regression passing.
- [X] T048 [US4] Implement truthful review controls/evidence labels in resources/views/v3/teacher/reviews/show.blade.php and existing teacher review controllers; dependencies:T047; FR-016,FR-017,FR-023/A17,A18,A20. Done: interactive isolated preview and decisions work, demo qualification clearly visible, raw HTML never served at app origin.
- [X] T049 [US4] Implement explicit correction command routing and exact consumed receipts in app/Services/V3/Review/{DispatchReviewEffects,SubmitPostapprovalCorrection}.php and v3_engine/web_pipeline/review_effects.py; dependencies:T046,T048; FR-017,FR-018,FR-020/A18,A19,A21. Done: real linked child starts, manual waits replacement, actual factual revision/visual repair runs for explicitly selected API mode; no fabricated202/completion.
- [X] T050 [US4] Add gate/import/evidence/retention tests in tests/Feature/V3/WebPipeline/EvidenceLineageTest.php; dependencies:T049; FR-015,FR-026,FR-028/A20,A27. Done: manual/demo ancestry has intentional absent API/JEV, actual approval/current and historical distinction, unresolved-effect deletion blocked.
- [X] T051 [US4] Extend evidence/metrics/retention resolution in app/Services/V3/Evidence/EvidenceProjector.php and app/Services/V3/Admin/AssessDeletion.php; dependencies:T050; FR-024,FR-026,FR-028/A20,A27. Done: real/demo/imported metrics separated, costs truthful, gates/receipts/approved artifacts protected; T050 passes.

## Phase 7 — US5: Operations, progress and recovery (P2)

**Independent value**: Operators can observe/control seeded or real runs without educational disclosure.

- [X] T052 [P] [US5] Add progress/offline/version/admin tests in tests/Feature/V3/WebPipeline/ProgressProjectionTest.php; dependencies:T051; FR-023,FR-024/A25,A27. Done: unknown totals NULL, age/control state, event filtering before limit and no private admin fields tested.
- [ ] T053 [P] [US5] [REOPENED: Audit finding R04,R07; closing through T068,T099,T104] Add Temporal control/capacity/queued-cancel/rotation/restart tests in v3_engine/tests/web_pipeline/test_controls_capacity.py; dependencies:T051; FR-019,FR-020,FR-021,FR-022/A21,A22,A23,A24.
- [ ] T054 [P] [US5] [REOPENED: Audit finding R04,R06; closing through T103] Add privacy-history/failure-cause tests in v3_engine/tests/web_pipeline/test_privacy_history.py and tests/Feature/V3/WebPipeline/PrivateMetadataTest.php; dependencies:T051; FR-002,FR-024/A26,A27.
- [ ] T055 [US5] [REOPENED: Audit finding R04,R05; closing through T097,T100] Implement application controls and linked retries in app/Services/V3/WebPipeline/ApplyRunControl.php and v3_engine/web_pipeline/{controls,subject_workflow,scene_workflow}.py; dependencies:T053; FR-019,FR-022/A22,A24.
- [ ] T056 [US5] [REOPENED: Audit finding R03,R04; closing through T101] Complete shared supervisor capacity/bootstrap/rotation changes in v3_engine/terminal_pipeline/supervisor.py and store.py; dependencies:T055; FR-020,FR-021/A21,A23,A24.
- [ ] T057 [US5] [REOPENED: Audit finding R04,R05; closing through T102] Implement progress/events/admin projection services and routes in app/Services/V3/WebPipeline/{RunProgress,AdminRunSummary}.php, app/Http/Controllers/V3/{Teacher/SubjectRunController,Admin/SubjectRunSummaryController}.php and routes/v3.php; dependencies:T052,T056; FR-002,FR-023,FR-024/A25,A26,A27.
- [ ] T058 [US5] [REOPENED: Audit finding R04; closing through T103] Implement safe required-event delivery/sanitization in v3_engine/web_pipeline/{events,activities}.py and app/Services/V3/WebPipeline/RunEventStore.php; dependencies:T054,T057; FR-024/A24,A27.
- [X] T059 [US5] Add web progress/control UI in resources/views/v3/teacher/subject-runs/show.blade.php and resources/views/v3/admin/subject-runs/index.blade.php; dependencies:T058; FR-022,FR-023/A22,A25,A27. Done: useful stage counts, last update/offline age, requested/applied controls and safe actionable codes displayed.

## Phase 8 — Connected verification and rollout

- [ ] T060 [REOPENED: Audit finding R06; closing through T089,T105,T109] Add connected full application chain test in tests/Integration/V3/WebPipeline/ConnectedWebPipelineTest.php and v3_engine/tests/web_pipeline/test_connected_pipeline.py; dependencies:T059; FR-001–FR-026/SC-001–SC-007/A01–A27.
- [ ] T061 [REOPENED: Audit finding R07; closing through T107] Add legacy history replay and populated-upgrade rollout coverage in v3_engine/tests/web_pipeline/test_replay.py and tests/Integration/V3/WebPipeline/RolloutTest.php; dependencies:T060; FR-027/SC-005/A28.
- [ ] T062 [REOPENED: Audit finding R08; closing through T113] Implement captured feature enablement/configuration/readiness diagnostics in config/services.php, .env.example and app/Services/V3/WebPipeline/Readiness.php; dependencies:T061; FR-004,FR-011,FR-027/A13,A28.
- [ ] T063 [REOPENED: Audit finding R07; closing through T110,T112] Add dedicated live two-PDF chain gate in v3_engine/tests/web_pipeline/test_live_web_pipeline.py and tests/Integration/V3/WebPipeline/LiveApplicationGateTest.php; dependencies:T062; FR-001,FR-005,FR-011,FR-016/SC-002,SC-003,SC-004/A05,A06,A13,A17.
- [ ] T064 [REOPENED: Audit finding R08,R09; closing through T108,T111,T114] Run reference archive dry-run/import and connected/race/browser/migration/replay suites; record commands/results and unresolved live gates in specs/019-v3-web-integration/implementation-report.md; dependencies:T063; FR-025,FR-027/SC-001–SC-007/A01–A28.
- [ ] T065 [REOPENED: Audit finding R08; closing through T113,T114,T115] Complete operator docs and implementation report in docs/V3-WEB-PIPELINE.md and specs/019-v3-web-integration/implementation-report.md; dependencies:T064; FR-001–FR-028/SC-001–SC-007.

## Dependencies and delivery strategy

Shared foundations T001→T011; import US1 T012→T021; web US2 T022→T034; scenes US3 T035→T044; review US4 T045→T051; operations US5 T052→T059; final T060→T065. Within groups use listed dependencies, not task numbers alone. US1 operational CLI workflow admission also requires T025–T026; this cross-story dependency is intentional. Until then only dry-run/transaction service tests and test-harness import workflow are usable. Do not mark operational US1 complete before its real admission test passes.

Stories remain independently testable using canonical test-only records/archives without future production placeholders. MVP is verified archive dry-run/materialization plus owner navigation; full durable operator import additionally includes shared admission. New web PDFs come next; manual scenes need no paid credentials; review consumes seeded checked candidates only in tests until actual scene production is available.

## Parallel examples

After foundations, T012/T013 have separate tests. After imported navigation, T022/T023 can proceed independently. T035/T036, T045/T046 and T052/T053/T054 are separate test files; all implementation edits to shared routes/controllers/workers must be serialized. No [P] task depends on its sibling.

## Completion

Original task set: 65 tasks; foundations11; US1 ten; US2 thirteen; US3 ten; US4 seven; US5 eight; final six. All requirements/scenarios must have passing meaningful evidence listed in acceptance matrix. A checked checkbox requires its completion condition and governed tests, not merely code existing. Offline test passes never close live credential/provider/review gates. The additional 50 repair tasks below bring the task inventory to 115.

---

## Repair scope — review findings, 2026-10-11

**Status:** 50 repair tasks planned; none implemented or verified by task creation. The previous successful terminal run remains dependency evidence. This repair completes its web adapter and acceptance verification.

**Authority:** Original spec.md, plan.md, data-model.md, contracts, acceptance-matrix.md, tasks-note.md and T001–T065 descriptions retain their meanings. The previous implementation report is disputed evidence, not a source of replacement requirements. A new wrapper workflow is permitted by the plan; it must call the working services and share their scheduler. Do not replace the NotebookLM/scene engines with a second implementation or shell out to their CLI from HTTP handlers.

### Findings to close

| Finding | Confirmed problem | Governing original work | Repair coverage |
| --- | --- | --- | --- |
| R01 | Laravel sends a random manifest reference; Python accepts it without durable supervisor admission | T023–T026; FR-004,FR-019,FR-020; A05,A21,A24 | T069–T074,T081,T105–T106 |
| R02 | Web divide/lesson/reset/export activities return fixed success instead of invoking the working services | T027–T033; FR-005,FR-006,FR-009,FR-010; A06–A10 | T080–T089 |
| R03 | Subject activity queues differ from registration; import symbols/type names do not match | T018,T026,T028,T041; FR-020,FR-021; A21,A23 | T074,T076–T079,T082,T091–T095 |
| R04 | Receipts live in memory; application claims/results also depend on expiring cache; recovery and controls lack durable proof | T010,T029,T053–T058; FR-019–FR-024; A21–A25,A27 | T072–T074,T084–T087,T099–T104 |
| R05 | Failed lesson/reset/export outcomes can advance to successful completion; selected scene work is not connected | T028,T033,T041,T055; FR-009–FR-014,FR-022,FR-023; A09,A13–A15,A22,A25 | T082,T087–T095,T100,T102 |
| R06 | Connected tests fake bridge acceptance or bypass Temporal and call fixed-result activities | T023,T027,T060; SC-002–SC-005 | T068–T069,T080,T089,T105–T106,T109 |
| R07 | Live tests only check prerequisites/staging; web replay constructs Replayer without replaying history; fixture scope prevents Temporal tests running | T053,T061,T063; SC-002–SC-005 | T068,T099,T107,T110,T112 |
| R08 | Report changes task/scenario meanings, claims absent deliverables, and docs contain wrong startup/routes/package shape | T001,T064,T065; all original A01–A28 | T066–T067,T113–T115 |
| R09 | Two-PDF archive dry-run is used as full import evidence; Python import helpers manufacture successful receipts | T017–T019,T064; FR-025,FR-026; SC-001; A01–A04 | T075–T079,T108 |

R04's cache dependency is visible in ClaimOperation.php and CommitOperation.php; tasks must test actual cache expiry/process recreation rather than assume it has already caused an observed production failure.

### Execution and evidence rules

- Run only in the explicitly selected Spec 019 context. Record the original selector, currently Spec 020, before temporarily selecting 019 for tooling; restore it when the repair session ends. Do not modify Spec 020 or its working PDF preparation behavior.
- Protect the dirty workspace, real database, existing archives, approved prompts/framework, credentials and working terminal commands. Use disposable DBs, artifact roots, ports and Temporal namespaces for tests. Schema changes must be additive with populated-clone coverage; never edit applied migrations to make a test pass.
- Tests below are required by the original specification. Add executable behavioral tests before the governed repair. Test tasks record the relevant red result or an existing meaningful pass; corresponding implementation tasks require green governing tests. Missing imports/syntax are not behavioral red. Final acceptance tasks require the complete connected behavior, not just test collection.
- Offline integration may substitute only external NotebookLM/generation/JEV transports. Production routes, signing, bridge, Temporal client, workers, activity/service implementations, leases, database, artifact verification, browser checks and preview/decision boundaries must execute. Keep useful unit tests, but identify their narrower scope.
- Each completed task records exact commands, result counts, evidence category, artifact/history refs and remaining limits in implementation-report.md. A live skip remains an open verification gate; it cannot establish live readiness. T112 stays open if the dedicated live chain is unavailable.
- `[P]` denotes independently editable work within a phase, not authorization to launch agents. Serialize shared worker/controller/schema changes and follow the explicit dependencies.

## Phase 9 — Repair setup and truthful baseline

- [X] T066 Capture the current repair baseline in specs/019-v3-web-integration/implementation-report.md and a private artifact directory; dependencies:none; R08; original T001; FR-027/A28.
  - Record tracked/untracked state, selected tools/interpreters, current selector, effective namespace/root/mode, relevant test baselines and owned processes without printing secrets.
  - Preserve the previous successful terminal run and its final-report/history hashes; separate it from the incomplete web adapter and legacy offline fixture failures.
  - Done: existing failures and repair-owned files/processes are identifiable; no real DB reset, source archive mutation or commit occurs.

- [X] T067 Correct the disputed status and restore original task/scenario traceability in specs/019-v3-web-integration/implementation-report.md and acceptance-matrix.md; dependencies:T066; R08; original T064,T065.
  - Record each affected original T-ID's actual completion condition and verified/partial/unverified status. Do not reassign IDs such as T024, T028, T029 or A01 to different work.
  - Withdraw unsupported complete/readiness claims, list absent claimed modules and distinguish actual service code, unit coverage, real Temporal/browser evidence and live evidence.
  - Keep original task descriptions intact. Reopen demonstrably incomplete original checkboxes and link them to the repair IDs that will close them; already valid work must not be needlessly rebuilt.
  - Done: the report has a faithful initial gap matrix, including SC-001's eight-PDF/63-lesson/89-prompt target and the still-open full web chain.

- [X] T068 Build a managed connected test harness in v3_engine/tests/web_pipeline/conftest.py, v3_engine/tests/conftest.py, tests/Support/V3/WebPipelineFixtures.php and tests/Support/V3/WebPipelineRuntime.php (new if needed); dependencies:T066; R06,R07; original T002,T023,T053,T060.
  - Make temporal_local_env available to web tests with explicit marker opt-in; do not start external services for unselected offline tests.
  - Start real Laravel callback HTTP, signed Python bridge, actual shared workers and disposable Temporal namespace with allocated ports and persistent test roots. Provide external transport doubles through test-only fixtures, not production success branches.
  - Support controlled crashes, callback failures and request counters. Ensure deterministic shutdown and preserve sanitized failure diagnostics/history before cleanup.
  - Done: harness smoke shows actual registered workflow/activity execution and fixture collection; test doubles cannot replace internal bridge/workers/services with accepted/completed values.

## Phase 10 — Foundations: immutable inputs and durable admission

**Independent check:** one signed submission with a real private manifest obtains one durable admission from an empty namespace; lost acknowledgments/restarts reuse it.

- [X] T069 Add failing admission/manifest/command boundary tests in tests/Integration/V3/WebPipeline/AdmissionTest.php and v3_engine/tests/web_pipeline/test_admission.py; dependencies:T068; R01,R04,R06; original T023,T025,T026; FR-004,FR-019,FR-020/A21,A24.
  - Require a real supervisor update/child registration after the HTTP response, not merely HTTP 202. Start from an empty namespace and simulate acceptance-response loss.
  - Reject missing/corrupt/unregistered manifest refs, changed duplicate payloads, unsupported commands, non-UUID refs, stale versions, unknown fields and metadata above 32 KiB, including commands.
  - Test canonical digests including selectors/phase/consent/settings capture; existing signatures and legacy routes remain covered.


- [X] T070 Implement private manifest/context/artifact resolution in app/Services/V3/WebPipeline/CaptureSubjectRunManifest.php (new), app/Http/Controllers/V3/Internal/WebPipelineController.php and v3_engine/web_pipeline/artifact_refs.py; dependencies:T069; R01; original T011,T024,T031; FR-003,FR-004,FR-024/A05,A27.
  - Capture ordered staged/accepted input refs, immutable framework/settings/policy and one absolute configured root. Resolve educational bytes and credential/proof/provider IDs inside service activities, outside serialized workflow payloads.
  - Persist ref ownership/type/length/hash; enforce scoped streamed reads/writes, byte limits, symlink checks before resolution and containment afterward. Recheck the final published bytes before consuming them.
  - Done: UUID refs resolve to real private captured artifacts; an invented ref cannot become an accepted processing manifest, and operational/admin responses expose no educational body.

- [X] T071 Repair captured submission/outbox dispatch in app/Services/V3/WebPipeline/{SubmitSubjectRun,TerminalBridgeClient,DispatchRunOutbox,RunOutbox}.php; dependencies:T070; R01,R04; original T024,T025; FR-004,FR-019,FR-020/A05,A21,A24.
  - Store and reuse the actual manifest_ref, stable submission/update identity and canonical digest. Remove per-delivery random manifest UUID generation.
  - Claim outbox leases through existing durable primitives; network calls stay outside transactions. Recover lost acknowledgments under the original identity before another dispatch; distinguish transport acknowledgment from application consumption.
  - Done: retries/two dispatcher processes deliver one immutable request and cannot mark an unavailable or speculative acceptance as consumed.

- [X] T072 Add cache-expiry/restart/concurrent claim-result-artifact tests in tests/Integration/V3/WebPipeline/BundleCommitTest.php and v3_engine/tests/web_pipeline/test_operation_store.py (new); dependencies:T070; R04; original T010,T029,T030,T031; FR-019,FR-020,FR-024/A24,A27.
  - Exercise independent processes/connections racing same and changed digests; remove/expire cache and recreate worker/client processes between claim and commit.
  - Assert retained receipt identity, current run/item/cursor version, stale lease rejection and exact artifact digest; changed result conflicts before event/count advancement.
  - Done: test failures expose memory/cache-only authority and unsafe deduplication rather than call mocks being invoked.

- [X] T073 Implement authoritative durable operation/receipt/artifact storage in app/Services/V3/WebPipeline/{ClaimOperation,CommitOperation}.php, v3_engine/web_pipeline/operation_store.py and v3_engine/web_pipeline/application_client.py (new); dependencies:T071,T072; R04; original T010,T029,T031; FR-019,FR-020,FR-024/A24,A27.
  - Reuse durable application records/terminal registry where compatible. If necessary, add app/Models/V3/PipelineOperation.php and a new database/migrations/*_create_v3_pipeline_operations.php migration; do not modify previously applied migrations.
  - Cache is optional acceleration, never lease/result authority. Enforce atomic SQLite-safe conditional versions/uniqueness, stable committed receipts, retry-safe local writes, current ownership and result/artifact validation.
  - Keep Laravel application projection authoritative and private terminal receipts for recovery; avoid competing schedulers or contradictory ledgers. Verify populated-clone schema/data preservation.
  - Done: T072 passes after cache expiry and new processes, with one result/event/count commit and no fabricated receipt.

- [X] T074 Connect Python admission/query/control endpoints to durable shared scheduling in v3_engine/web_pipeline/bridge.py and v3_engine/terminal_pipeline/{supervisor,store,settings}.py; dependencies:T071,T073; R01,R03,R04; original T025,T026; FR-019,FR-020,FR-021/A21,A23,A24.
  - Use the installed Temporal SDK's Update-With-Start with USE_EXISTING and stable update IDs, explicit terminal namespace/root and trusted workflow-kind routing. Resolve the manifest privately before admitting; map run/item refs to the supervisor's actual expected input contract.
  - Return acceptance only after the durable update/registry receipt. Replace module dictionary status/receipts with exact retained queries and truthful unavailable/unknown results.
  - Require full command schema/version/digest/size checks and dispatch real controls; receipt consumed/applied flags require exact workflow handling.
  - Done: T069's empty-namespace, duplicate, lost-response and restart cases pass without direct workflow invocation by the test.

## Phase 11 — US1: verified operational archive import

**Independent check:** a provider-disabled import travels through real admission/workers, validates and copies exact retained bytes, commits owner records once, and resumes after interruption.

- [X] T075 [US1] Add real import execution/recovery tests in v3_engine/tests/web_pipeline/test_import_workflow.py and tests/Feature/V3/WebPipeline/TerminalImportTest.php; dependencies:T068,T074; R03,R09; original T012,T017–T019; FR-025,FR-026/A01–A04.
  - Reject random manifest UUIDs, missing destination/artifacts, corrupt bytes, symlinks/escapes and changed destinations. An existing /tmp directory alone is not verification.
  - Exercise dry-run's zero DB/file/workflow/provider effects, crash between committed items, identical resumption and source archive immutability.

- [X] T076 [US1] Replace fabricated import activity outcomes in v3_engine/web_pipeline/import_activities.py using app/Services/V3/WebPipeline/{TerminalArchiveReader,MaterializeTerminalImport}.php and signed worker routes in app/Http/Controllers/V3/Internal/WebPipelineController.php; dependencies:T075; R03,R09; original T014,T016,T018,T031; FR-025,FR-026/A01–A04.
  - Implement actual verify/plan/copy/materialize/finalize operations with scoped refs and durable per-item receipts; reuse existing Laravel verification/materialization instead of duplicating their semantics.
  - Verify the same captured bytes, copy only approved educational evidence and rehash copied artifacts. Compute accepted/failed counts from committed items, never a constant two.
  - Done: every completed receipt identifies actual verified/copied/committed work; interrupted import reuses item commits with all remote provider transports disabled.

- [X] T077 [US1] Repair ApplicationImportWorkflow and its registration in v3_engine/web_pipeline/import_workflow.py and v3_engine/terminal_pipeline/{worker,supervisor}.py; dependencies:T076; R03,R09; original T018,T026; FR-020,FR-025,FR-026/A02,A21. Done: ApplicationImportWorkflow definition name and activity symbols match; activities execute through Temporal; worker registers real import activities without silent broad ImportError pass; all 6 tests in test_import_workflow.py pass.
- [X] T078 [US1] Connect non-dry-run CLI import to durable execution in app/Console/Commands/V3/ImportTerminalRun.php and app/Services/V3/WebPipeline/{RunOutbox,TerminalBridgeClient}.php; dependencies:T077; R09; original T019; FR-019,FR-020,FR-025,FR-026/A01–A03,A21. Done: --queue option enqueues durable command and outbox with exit code 3; validated exits 0, completed exits 0, partial exits 1; all 13 tests in TerminalImportTest.php pass.
- [X] T079 [US1] Verify an end-to-end disposable import and owner navigation through tests/Integration/V3/WebPipeline/ConnectedWebPipelineTest.php and tests/Feature/V3/WebPipeline/ImportedLectureViewTest.php; dependencies:T078; R06,R09; original T020,T021,T060; FR-001,FR-002,FR-025,FR-026/A01–A04,A26. Done: verified synthetic 2-PDF import, owner navigation, non-owner/student access fencing, and no fabricated dynamic view links; 5 tests in ImportedLectureViewTest.php and 13 tests in TerminalImportTest.php pass.

## Phase 12 — US2: connect web PDFs to the working NotebookLM services

**Independent check:** two uploads invoke actual service methods through real workers, save separate variable lesson/prompt outputs in Laravel, verify archive/reset before advancing, and export exact prompts with zero scene-provider sends.

- [x] T080 [US2] Add service-backed per-PDF scope/turn/reset tests in v3_engine/tests/web_pipeline/test_notebook_workflow.py; dependencies:T068,T074; R02,R06; original T027; FR-005,FR-006,FR-008,FR-009,FR-014/A06–A09,A14.
  - Use two actual small PDF fixtures and an external NotebookLM transport double with different maps/lesson/scene counts per PDF. Keep NotebookTerminalService, activities, persistence and Temporal real.
  - Assert actual source selection per request, framework upload once, exact approved prompt/format bytes, distinct Continue identities, raw receipts and archive-before-reset ordering.
  - Reject nonexistent sources; remove assertions that fixed counts or merely invoking an activity named divide prove processing.

- [x] T081 [US2] Resolve captured run/service context and accepted inputs in v3_engine/web_pipeline/{activities,subject_workflow,application_client}.py and app/Services/V3/WebPipeline/AcceptRunItem.php; dependencies:T073,T074,T080; R01,R02; original T024,T029,T031; FR-001,FR-003,FR-004,FR-005/A05,A06.
  - Run actual private intake checks for new staged attempts and preserve unchanged accepted bytes; rejected attempts create no empty case/lecture and do not enter a lesson loop.
  - Load immutable refs privately and resolve actual notebook/source IDs inside service activities. A source_ref UUID is not itself a provider source ID; missing references must fail explicitly.
  - Preserve one owner/item/case/lecture association per accepted PDF and prevent legacy StartWorkflow/CaseWorkflow duplicate ownership.

- [x] T082 [US2] Repair ApplicationSubjectWorkflow scheduling and outcome handling in v3_engine/web_pipeline/subject_workflow.py and v3_engine/terminal_pipeline/worker.py; dependencies:T081; R03,R05; original T028,T026; FR-005,FR-006,FR-009,FR-021,FR-022/A06,A07,A09,A22,A23.
  - Match each activity's actual registered queue. Workflow payloads/results contain only bounded local refs, safe enums/counts; no raw map/body/prompt/config/provider IDs.
  - Branch on every pending/failed/blocked/uncertain/cancelled outcome, including lesson/archive/reset/export, and fence pause/cancel before every subsequent mutation.
  - Generation-stage completion is separate from approval. Manual/review waits persist in Laravel and release capacity after actual child closure; selected scenes are admitted as bounded later work.
  - Done: no failed or omitted operation can increase completed counts or produce a successful final result.

- [x] T083 [US2] Implement account/source setup wrappers in v3_engine/web_pipeline/activities.py using v3_engine/notebooklm/{terminal_service,sdk_provider}.py; dependencies:T082; R02; original T029; FR-003,FR-005,FR-014/A05,A06,A14.
  - Call existing NotebookTerminalService methods for isolated approved-session proof verification, notebook creation, framework upload once, PDF upload/readiness and chat configuration.
  - Persist dispatch fences and actual provider receipts before subsequent work. Credentials stay private; runtime never opens a login browser or changes profiles automatically.
  - Bound read-only polling and preserve typed authentication/account/quota/provider/source errors. A read-only session check cannot replace processing readiness.

- [x] T084 [US2] Implement real Divide/Execution/Start/Continue lesson wrappers in v3_engine/web_pipeline/activities.py and reusable v3_engine/notebooklm/terminal_service.py extensions where needed; dependencies:T083; R02,R04; original T029; FR-005,FR-006,FR-007,FR-008,FR-014/A06–A08,A14.
  - Call existing service operations with the captured approved bytes and correct PDF-only or PDF-plus-framework scopes; preserve current contract's permissive warnings without rewriting provider prompts.
  - Save exact raw responses and complete receipts before parsing. Operation identity includes run/item/lesson/turn/attempt, so identical Continue strings never share a send receipt.
  - Resume verified retained results locally; uncertain mutations require reconciliation and cannot be automatically resent by Temporal retries.

- [x] T085 [US2] Wire actual lesson/artifact callbacks into app/Services/V3/WebPipeline/{AcceptWebLesson,ProjectLessonBundle,CommitOperation}.php, app/Http/Controllers/V3/Internal/WebPipelineController.php and v3_engine/web_pipeline/application_client.py; dependencies:T084; R02,R04; original T030,T031; FR-006,FR-007,FR-008,FR-019,FR-024/A07,A08,A24,A27.
  - Actual workers upload verified raw evidence and commit parsed map/lesson/section/prompt identities through signed boundaries; tests cannot seed the expected lesson by directly calling AcceptWebLesson.
  - Atomically fence the current cursor/query/item/version and preserve eleven supplied fields, explicit missing/null warnings, exact Third prompts and variable scene labels/counts.
  - Done: database associations and counters derive from committed evidence; replayed callbacks are idempotent and divergent/stale receipts conflict.

- [x] T086 [US2] Implement verified per-PDF archive/reset barriers in v3_engine/web_pipeline/activities.py using v3_engine/terminal_pipeline/pdf_scope.py and v3_engine/notebooklm/terminal_service.py; dependencies:T085; R02,R04,R05; original T029; FR-005,FR-006,FR-014/A06,A07,A14.
  - Verify all completed map/turn/lesson/prompt artifacts and hashes before publishing the PDF archive; failure to save/verify blocks reset and advancement.
  - Call the actual conversation reset and verify empty exact history. Retain original sources; allow provider conversation ID reuse while local PDF identity remains distinct.
  - Retain reset intent/receipt and reconcile interruption without blindly deleting or sending again. No placeholder reset receipt is success.

- [x] T087 [US2] Extend failure/uncertainty/persistence tests and repair uncovered branches in v3_engine/tests/web_pipeline/test_notebook_workflow.py and v3_engine/web_pipeline/{activities,subject_workflow}.py; dependencies:T086; R04,R05; original T027–T029; FR-009,FR-014,FR-022/A09,A14,A22.
  - Inject definite PDF-local failures, shared auth/quota, lost remote replies, failed artifact/callback/event persistence, reset uncertainty and failed export. Exercise actual service/worker boundaries with external transport failures only.
  - Later PDFs continue only after the failed local PDF is safely settled/reset; shared/uncertain problems block affected remote work. Lesson/reset/export failure cannot return safe_code ok with completed PDF counts.
  - Cancel during a settled operation and while paused prevents the next send; actual receipts/counts survive restart.

- [x] T088 [US2] Implement evidence-derived finalization and exact prompt exports in v3_engine/web_pipeline/{finalize,activities}.py and app/Services/V3/WebPipeline/{ExportScenePrompts,RunProgress}.php; dependencies:T087; R02,R05; original T032,T033; FR-008,FR-010,FR-023,FR-024/A08,A10,A25.
  - Select verified successful archives/committed prompts, retain failed items as partial and keep unknown totals explicit. Do not demand nonexistent archives for failed PDFs or invent successful zero-prompt output.
  - Publish immutable JSON/text exports with run/PDF/lecture/lesson/section/current prompt identity and exact prompt bytes; scoped/full exports must reconcile.
  - Done: export completion/counts require real artifact references, and default export/manual mode makes zero generation requests without requiring a scene-provider key.

- [x] T089 [US2] Complete the real two-PDF export/manual acceptance scenario in tests/Integration/V3/WebPipeline/ConnectedWebPipelineTest.php and v3_engine/tests/web_pipeline/test_connected_pipeline.py; dependencies:T088; R06; original T060; SC-002,SC-003/A05–A10.
  - Submit through real authenticated web routes, dispatch outbox to actual bridge/supervisor/workers and wait for actual callback records and exported artifacts. Double only external NotebookLM transport.
  - Compare varied fixture counts and exact response/prompt/input hashes, one framework upload, separate maps, saved resets and complete owner associations. Assert no generated-scene requests.
  - Done: disabling bridge admission or returning fixed activity counts makes the connected test fail, not pass through direct workflow execution or manually inserted results.

## Phase 13 — US3: connect selected scene production and actual checks

**Independent check:** one explicitly selected prompt creates one real service-backed candidate/build; retained-result recovery buys no new request; actual browser evidence determines eligibility.

- [x] T090 [US3] Add real selected-scene/command/configuration tests in v3_engine/tests/web_pipeline/test_scene_execution.py and tests/Feature/V3/WebPipeline/TeacherSceneUiTest.php; dependencies:T068,T089; R05,R06; original T036,T041; FR-004,FR-011,FR-013,FR-014/A13–A15.
  - Capture exact selected current prompt IDs and immutable provider/model/template/thinking/timeout/consent settings. Test mode-name drift between contract api_generate and existing selected_api without rewriting saved captures silently.
  - Require actual registered scene child/service/provider-transport call, no unselected sends and prerequisites checked before paid work. Missing context/config cannot produce completed/skipped generation success.

- [x] T091 [US3] Connect ApplicationSceneWorkflow to private prompt context and existing scene services in v3_engine/web_pipeline/{scene_workflow,scene_activities,scene_context}.py and v3_engine/terminal_pipeline/{worker,supervisor}.py; dependencies:T090; R03,R05; original T039,T041; FR-011,FR-013,FR-014,FR-021/A13–A15,A23.
  - Schedule bounded pages of local scene refs; fetch prompts/settings privately in activities and invoke actual SceneGenerationService/provider/build/artifact methods.
  - Retain actual provider response/model/usage/timing, source/build hashes and returned refs. A missing provider config is a failed/blocked prerequisite, not completed skipped generation.
  - Done: one selected scene uses the intended captured settings and actual registered IO/build activities without passing educational bodies through Temporal history.

- [x] T092 [US3] Wire generate/retry commands to bounded scene children in app/Services/V3/WebPipeline/{SelectSceneGeneration,ApplyRunControl,DispatchRunOutbox}.php and v3_engine/web_pipeline/bridge.py; dependencies:T091; R01,R05; original T041,T044,T055; FR-011,FR-014,FR-019,FR-020/A13,A14,A21,A24.
  - Recheck active original owner, current prompt/gate, exact selectors, expected version and duplicate-risk consent; preserve captured run policy and create immutable command settings captures.
  - Link real child/command receipts and release slots only on observed processing closure. Manual mode remains waiting for owner import without implicit API calls.

- [x] T093 [US3] Add retained-response/build-only/uncertain-send tests in v3_engine/tests/web_pipeline/test_scene_execution.py and v3_engine/tests/scene_generation/test_service.py; dependencies:T090; R04,R05; original T036,T040; FR-013,FR-014/A14,A15.
  - Exercise restart after complete provider response, corrupt receipt/source/current build, failed build attempts and changed scaffold/toolchain; use actual services/artifact store, not callback counts alone.
  - Verify original metadata/timing restoration, unique immutable build ordinals, no generation-key requirement for local recovery, zero duplicate sends and explicit distinct consented attempts for uncertain work.

- [x] T094 [US3] Repair only recovery defects exposed by T093 in v3_engine/scene_generation/{service,builder,artifact_store}.py and v3_engine/web_pipeline/scene_activities.py; dependencies:T091,T093; R04,R05; original T040,T041; FR-013,FR-014/A14,A15.
  - Reuse verified response/source and actual verify_current_build/pointer checks; preserve original educational association/config metadata and paid-send fences.
  - Keep current terminal generation/build commands and successful histories compatible; avoid an unrelated scene-engine rewrite.

- [x] T095 [US3] Connect actual browser checks and qualification gates for generated/manual candidates in v3_engine/web_pipeline/check_activities.py, v3_engine/terminal_pipeline/worker.py and app/Services/V3/SceneQualification/{RecordSceneCheck,SceneReviewEligibility}.php; dependencies:T092,T094; R03,R05,R06; original T042,T043; FR-015,FR-016/A16.
  - Execute all seven real checks in the contracted isolated runner, save actual screenshot/report/duration/digests, commit them through signed application boundaries and block on failed/missing evidence.
  - Register/schedule the actual check activity and connect its outcome to candidate/gate/progress; building alone cannot imply eligibility. Demo waiver creates no JEV pass/score/attempt.
  - Test actual manual import and generated candidates, failed checks, stale hashes and existing V1/legacy behavior. Reconcile the earlier eligibility HTTP regression against the original public/internal contract rather than weaken guards.

## Phase 14 — US4: real preview, decisions and correction consumption

**Independent check:** a genuinely checked candidate reaches the original owner's isolated preview; exact current decisions/corrections are executed and consumed by the linked workflow.

- [x] T096 [US4] Add connected preview/decision/correction tests in tests/Feature/V3/WebPipeline/SceneReviewTest.php, tests/Integration/V3/WebPipeline/CorrectionDeliveryTest.php and v3_engine/tests/web_pipeline/test_review_effects.py; dependencies:T095; R05,R06; original T045,T046,T060; FR-002,FR-016,FR-017,FR-018/A17–A20,A26.
  - Use an actual built/checked candidate and isolated preview/frame transport; obtain real first-frame evidence before submitting owner decisions. Do not insert a one-pixel proof as end-to-end preview evidence.
  - Reject foreign/admin/stale/superseded candidates and simulate acknowledgment loss or a closed processing chunk. A mocked consumed query or accepted signal is not workflow consumption proof.

- [x] T097 [US4] Repair linked review/correction delivery in app/Services/V3/Review/{DispatchReviewEffects,SubmitPostapprovalCorrection}.php and v3_engine/web_pipeline/review_effects.py; dependencies:T096; R01,R04,R05; original T049,T055; FR-017,FR-018,FR-020/A18,A19,A21.
  - Route actual review decisions and factual/visual corrections to the correct registered bounded child through shared admission, retaining exact decision/command digest/version receipts.
  - Manual replacement waits without scene API spending; explicit API correction creates the appropriate successor and repeats build/check/review. Factual NotebookLM revision remains a separate explicit durable source operation.
  - Done: outbox consumed/delivered requires exact retained handling, not HTTP 202, signal acknowledgment, finished workflow status or a query exception.

- [x] T098 [US4] Verify truthful review UI/evidence and successor history in resources/views/v3/teacher/reviews/show.blade.php, app/Services/V3/Evidence/EvidenceProjector.php and tests/Feature/V3/WebPipeline/EvidenceLineageTest.php; dependencies:T097; R05,R06; original T048,T050,T051; FR-015,FR-016,FR-018,FR-023,FR-028/A17–A20,A27.
  - Approval counts appear only after actual owner preview/current decision. Preserve previous prompt/candidate/approval ancestry; changed prompt/candidate requires new checks/preview/gate.
  - Distinguish imported/manual/demo/real qualification and intentional absent paid/JEV evidence. Retention still blocks deletion of unresolved effects and retained approvals.

## Phase 15 — US5: controls, capacity, progress and restart recovery

**Independent check:** mixed web/terminal work obeys shared limits; controls apply at settled boundaries; crash/replay preserves exact counts, receipts and private history.

- [x] T099 [P] [US5] Add actual mixed-capacity/control/restart tests in v3_engine/tests/web_pipeline/test_controls_capacity.py; dependencies:T089,T092,T097; R04,R07; original T053; FR-019,FR-020,FR-021,FR-022/A21–A24.
  - Use actual registered subject/scene/import workers with delayed external transports to measure shared four-subject/four-IO/two-build limits and 64 queued capacity.
  - Race queued cancellation against launch, cancel while paused/in-flight, repeat changed/stale commands and recover interrupted reservations. No production workflow success activity may replace the chain under test.

- [x] T100 [US5] Implement durable command validation/fencing/consumption in app/Services/V3/WebPipeline/ApplyRunControl.php and v3_engine/web_pipeline/{bridge,subject_workflow,scene_workflow}.py; dependencies:T099; R04,R05; original T055; FR-019,FR-022/A22,A24.
  - Enforce UUID/digest/schema/selector/consent/version/32-KiB bounds before dispatch. Duplicate identity checks precede current-version checks; changed bodies conflict.
  - Track requested, accepted and applied/consumed separately at every settled operation boundary; paused cancellation wakes the child, prevents the next mutation and retains evidence.
  - Active/closed recovery must actually execute before applied receipts; cancelled work is not silently resumed.

- [x] T101 [US5] Repair supervisor closure/bootstrap/rotation/reservations in v3_engine/terminal_pipeline/{supervisor,store,worker}.py; dependencies:T100; R03,R04; original T026,T056; FR-020,FR-021/A21,A23,A24.
  - Recover retained active/queued chains before admission; release slots from parent-observed real closure, not an activity's fabricated closed flag or timestamp.
  - Keep mixed terminal/web/import/recovery work within shared limits and bounded Continue-As-New history; draining for rotation must not permanently disable admission.
  - Done: T099 passes with real queues/restarts and legacy terminal scheduling/replay remains compatible.

- [x] T102 [P] [US5] Connect truthful committed progress/offline controls in app/Services/V3/WebPipeline/{RunProgress,AdminRunSummary}.php, v3_engine/web_pipeline/bridge.py and resources/views/v3/teacher/subject-runs/show.blade.php; dependencies:T089,T098; R04,R05; original T052,T057,T059; FR-002,FR-023,FR-024/A25–A27.
  - Use actual accepted/failed/lesson/prompt/source/build/check/review records with unknown totals NULL, monotonic control version and real last-update timestamp.
  - Query exact live execution with bounded timeout; on outage display retained projection source/age and unapplied controls, never invented current completion.
  - Test API and rendered pages after callback failure, paused/partial runs and manual/review waits; admin output stays metadata-only and event filtering precedes pagination limits.

- [x] T103 [US5] Connect required safe event delivery and actual history privacy checks in v3_engine/web_pipeline/{events,activities}.py, app/Services/V3/WebPipeline/RunEventStore.php and v3_engine/tests/web_pipeline/test_privacy_history.py; dependencies:T098,T101,T102; R04,R06; original T054,T058; FR-024,FR-026/A24,A27.
  - Require a durable event sink before the next mutation, deterministic operation/attempt/kind identities and divergent-evidence conflict handling. An absent client must not silently count as persisted.
  - Inspect captured serialized Temporal histories, search attributes, error causes, logs and admin output with content/credential/provider-ID sentinels; sanitize exceptions without attaching raw SDK causes.
  - Retain full private diagnostics/educational bodies only in scoped artifacts and distinguish historical imported events from actual new execution.

- [x] T104 [US5] Run controlled end-to-end restart/ack-loss cases in v3_engine/tests/web_pipeline/test_connected_pipeline.py and tests/Integration/V3/WebPipeline/CommandRaceTest.php; dependencies:T101,T102,T103; R04,R06; original T053,T060; SC-005/A21,A24.
  - Stop/recreate actual bridge/worker processes after admission, after a retained remote response before callback, after a committed lesson before archive/reset, and before finalization; use persistent disposable roots.
  - Assert same run/operation/receipt IDs, exact cursor/count recovery and zero duplicate remote requests or application commits. Unproven sends remain uncertain; changed commands/results conflict.
  - Done: recovery is verified by retained DB/artifact/history evidence, not constructing a fresh in-memory store and comparing names.

## Phase 16 — connected acceptance, replay, reference and live gates

- [x] T105 [REOPENED: current PHP test fakes the bridge response and manually calls application services; Python admission and activity tests are separate, and the workflow orchestration test injects activity results. No single linked runtime scenario exists.] Replace overstated connected coverage in tests/Integration/V3/WebPipeline/ConnectedWebPipelineTest.php and v3_engine/tests/web_pipeline/test_connected_pipeline.py with a single linked runtime scenario; dependencies:T079,T104; R06; original T060; SC-001–SC-007.
  - Preserve useful unit/contract checks under accurate names. The acceptance scenario must submit real web PDFs, dispatch actual signed bridge/Temporal workers, receive actual service-backed callbacks, export prompts, import/generate a scene, run real checks, preview/decide and inspect persisted progress/evidence.
  - No internal Http::fake bridge, manual lesson insertion, direct workflow.run, injected success executor, manufactured browser passes/frame proof or mock consumed query may establish connected acceptance.
  - Doubles replace external provider transports only; retain operation counters proving the actual service path and zero sends for default manual generation.

- [x] T106 [REOPENED: actual scene-generation dispatch and queued archive-import materialization remain disconnected or synthetic; see T105/T109 and review report.] Close remaining runtime integration gaps exposed by T105 in app/Services/V3/WebPipeline/, app/Services/V3/Review/, v3_engine/web_pipeline/ and v3_engine/terminal_pipeline/worker.py; dependencies:T105; R01–R06; original T023–T031,T041,T049,T055,T060.
  - Trace each failing boundary back to its original FR/A requirement; repair production wiring rather than weaken assertions or seed missing outputs.
  - Done: T105 passes end to end from one web submission; removing real admission/service/callback/check/review dispatch makes the scenario fail. Record exact evidence for each closed original task.

- [x] T107 Implement and run actual captured-history replay in v3_engine/tests/web_pipeline/test_replay.py with fixtures in v3_engine/tests/web_pipeline/conftest.py; dependencies:T106; R07; original T061; FR-027/SC-005/A28.
  - Fetch histories of completed/recovered web subject/import/selected scene/control paths and invoke the real Replayer replay APIs, not construction assertions.
  - Replay retained legacy terminal histories with current worker/workflow code, including existing version patches. Confirm no duplicate callbacks/provider mutations and inspect serialized history privacy.
  - Done: non-determinism or an incompatible workflow change causes a failing replay test; the three previous web Temporal fixture errors are resolved without skips hiding them.

- [x] T108 [REOPENED: report evidence is a two-PDF dry-run and generic fixtures; the specified eight-PDF/63-lesson/89-prompt archive was not materialized and counted.] Verify the exact original reference archive with app/Console/Commands/V3/ImportTerminalRun.php and tests/Feature/V3/WebPipeline/TerminalImportTest.php; dependencies:T079,T106; R09; original T064; SC-001/A01–A04.
  - Use contracts/archive-import.md's eight-PDF reference root/run, not the separate two-PDF generated live sample. Dry-run first; materialize only into an isolated migrated populated DB/artifact destination under the fixture teacher/subject.
  - Assert eight lectures, 63 lessons, 89 exact prompts and PDF-local associations, zero new provider requests and zero invented generated/approved scenes. Re-run/resume to prove no duplicates and rehash source archive afterward.
  - Done: both actual dry-run and materialization/count/hash evidence are recorded without changing the owner's real database or archive.

- [x] T109 [REOPENED: selected_api currently reaches a terminal return in ApplicationSubjectWorkflow without starting ApplicationSceneWorkflow; existing tests do not prove the required linked scene path.] Run connected default/manual and one-selected-API scene acceptance through tests/Integration/V3/WebPipeline/ConnectedWebPipelineTest.php and v3_engine/tests/web_pipeline/test_scene_execution.py; dependencies:T106,T107; R05,R06; original T060; SC-002–SC-004.
  - Prove default export mode emits zero generation requests. Explicitly select one current prompt and prove one real service/provider-transport request, verified source/build, actual browser checks and real owner preview/decision.
  - Use external transports doubled for this offline lane; record captured settings and source/result lineage. Keep this category distinct from real paid/live provider evidence.

- [x] T110 [REOPENED: both gate implementations only assert environment flags and web staging; enabling them does not execute the advertised live chain.] Implement the full dedicated opt-in live gate in v3_engine/tests/web_pipeline/test_live_web_pipeline.py and tests/Integration/V3/WebPipeline/LiveApplicationGateTest.php; dependencies:T109; R07; original T063; SC-002–SC-004/A05,A06,A13,A17.
  - Mark the lane explicitly live and validate dedicated opt-in/session/proof notebook/two disposable PDFs/provider/model/browser/runtime prerequisites. Reconcile documented V3_WEB_LIVE_* settings with existing RUN_V3_LIVE_APPLICATION_GATE aliases explicitly; no bare default pytest run may spend.
  - When enabled, execute actual web admission, Temporal, approved-account proof, source uploads/turns/reset, saved prompts, one selected scene API request, retained build, actual checks and owner preview/decision. Assert real durable outputs, not flags or staging state.
  - When unavailable, skip with exact prerequisite reasons and leave verification open. Runtime does not launch login or silently switch provider/profile. Capture cleanup/retention policy for disposable remote resources before running.

- [x] T111 [REOPENED: only the focused WebPipeline PHP/Python suites were rerun here; the listed legacy, SDK, scene recovery, frontend build and original-archive checks were not all executed.] Run affected regression/browser/race/migration suites and resolve repair-induced failures; record evidence in specs/019-v3-web-integration/implementation-report.md; dependencies:T107,T108,T109,T110; R03,R04,R07,R08; original T064; FR-027/A28.
  - Run WebPipeline PHP/Python, terminal per-PDF/SDK/scene recovery, real Temporal/browser/control/race/replay and populated-upgrade tests with correct venv/opt-in flags. Run the relevant legacy access/intake/qualification/review/evidence suites and frontend build.
  - Reconcile Spec 019-related eligibility/suspension/guard regressions against original contracts. Record unrelated inherited auth/profile/legacy fixture failures separately; do not rewrite tests simply to obtain green totals.
  - Done: all new/governing and affected legacy regressions are green or a precise unresolved failure remains explicitly open. Overlapping reruns/skips are not summed as unique passes.

- [ ] T112 Execute the dedicated live two-PDF acceptance gate when its authorization/prerequisites are provisioned and record results in specs/019-v3-web-integration/implementation-report.md; dependencies:T110,T111; R07; original T063,T064; SC-002–SC-004.
  - Use only explicitly enabled dedicated live inputs/settings and permitted provider spending; do not create live readiness evidence by reusing a historical terminal report or a session read.
  - Retain actual web/Temporal/application/provider/build/check/preview/decision evidence and exact counts/versions. Stop only repair-owned processes and apply the declared disposable-resource retention policy.
  - Done: actual full chain passes. If authorization/prerequisites are absent or execution fails, keep this checkbox open, document the precise gate, and complete independent offline repairs/documentation without claiming 100% live verification.

## Phase 17 — accurate delivery documentation and independent completion review

- [x] T113 Correct operator setup/routes/contracts in docs/V3-WEB-PIPELINE.md and specs/019-v3-web-integration/quickstart.md; dependencies:T111; R08; original T062,T065.
  - Document actual existing bridge/worker module/CLI startup, namespace/root/config, scheduler/outbox operation, restart/import/export/manual/API/recovery steps and serving-PHP readiness settings.
  - Use actual registered create/control/export/internal/review paths, readiness field ready, current 1–20 batch limits and exact index.html/styles.css/script.js package shape. Explain any compatibility mode aliases without altering captured runs.
  - Recheck commands against real files/routes and distinguish implemented integration, offline verification, local readiness and live gates. Never document an absent worker module as operational startup.

- [x] T114 Rebuild the final original-ID evidence mapping in specs/019-v3-web-integration/{implementation-report,acceptance-matrix}.md and reconcile original T001–T065 checkboxes in tasks.md; dependencies:T111,T113; R08; original T064,T065.
  - Keep FR-001–FR-028, SC-001–SC-007, A01–A28 and T001–T065 meanings unchanged. Add repair IDs/evidence/status alongside them rather than redefine A01 as intake or T028 as UI.
  - Report actual files/symbols, exact commands/counts, unit versus connected/Temporal/browser/live categories, known baseline failures and pending T112 clearly. Link durable sanitized evidence and identify missing original conditions.
  - Done: every checked original/repair task has its own governing evidence; a dry-run, skipped gate, filename or manufactured receipt cannot close execution requirements.

- [x] T115 Perform final repair review and workspace/selector cleanup in specs/019-v3-web-integration/implementation-report.md, tasks.md and .specify/feature.json; dependencies:T114; R01–R09; original T065.
  - Trace web upload → immutable input ref → signed outbox → durable shared admission → actual NotebookLM services → committed lessons/prompts/archive/reset → selected/manual candidate → actual checks → original-owner preview/decision, including controls/restart and exact consumption.
  - Verify no production fixed-count success, silent omitted registration, competing scheduler, expiring-cache receipt authority or bypassed acceptance boundary remains in the repaired paths. Validate legacy working pipeline/archive hashes, isolated DB usage and absence of unrelated edits.
  - Restore the entry selector and stop only owned processes; report changed files, completion/gate status, commands and limitations. An unavailable T112 is an explicit live verification exception, never a blanket Spec 019 complete/live-ready verdict.

### Dependency graph and incremental delivery

1. Setup: T066 → T067; T068 after T066.
2. Foundations: T069 → T070 → T071; T072 after T070; T073 after T071/T072; T074 after T073.
3. US1: T075 → T076 → T077 → T078 → T079.
4. US2: T080 → T081 → T082 → T083 → T084 → T085 → T086 → T087 → T088 → T089.
5. US3: T090 → T091 → T092; T093 after T090; T094 after T091/T093; T095 after T092/T094.
6. US4: T096 → T097 → T098.
7. US5: T099 → T100 → T101; T102 after T089/T098; T103 after T098/T101/T102; T104 after T101/T102/T103.
8. Acceptance: T105 → T106 → T107/T108/T109 → T110 → T111; T112 requires the dedicated live gate; T113 after T111 → T114 → T115.

**Parallel examples:** T069 boundary tests and T072 durability tests can be authored in separate files once their prerequisites are present; do not implement shared controller changes concurrently. T099 and T102 have separate test/control versus projection/view files. T093 recovery tests can be authored after T090 while scene routing proceeds; serialize edits to scene_activities.py. Parallel markers do not permit bypassing dependencies.

**First useful web-processing checkpoint:** T089, after foundational admission and real service wrappers. It proves the web-to-working-pipeline connection and saved exports without paid scene calls. US1 adds independent provider-free import value. T109 proves selected generation/check/review through actual internals with external transports doubled. T112 alone closes the dedicated live gate.

**Repair task counts:** setup3; foundations6; US1 five; US2 ten; US3 six; US4 three; US5 six; acceptance8; documentation/review3 = 50. No repair task is complete merely because it appears in this list.

## Repair addendum v2 — T116–T155 (2026-10-12)

User-authorized detailed repair tasks and required tests. All new tasks start open. Read [repair-acceptance-v2.md](repair-acceptance-v2.md) for mandatory assertions/evidence and [repair-agent-prompt-v2.md](repair-agent-prompt-v2.md) for execution instructions. These tasks refine remaining acceptance; they do not erase original requirements or invalidate useful completed repairs. The current task is documentation only; no implementation pass is claimed.

### Phase 18 — Repair acceptance setup

**Goal / independent acceptance:** Record what exists and define evidence before changing production behavior.

- [x] T116 Capture the repair baseline in `specs/019-v3-web-integration/repair-evidence/baseline.md`. Dependencies: none.

  - Required behavior and completion: Record dirty file hashes, selector bytes, runtime versions, configured endpoint identities, process ownership, database/artifact roots and prerequisite names without values. Recheck current services; do not assume port 8000 is Laravel. Use disposable populated DBs and separate private roots. Document cleanup ownership.

- [x] T117 Audit prior completion claims in `specs/019-v3-web-integration/repair-evidence/task-audit.md`. Dependencies: T116.

  - Required behavior and completion: Map original T001–T115 to unchanged A01–A28 and actual evidence. Reassess T105–T115 and importer/projection claims. Keep useful passing tests; classify mocks and skips precisely. The reported 180 PHP and 110 Python passes are historical component evidence, not new joined acceptance.

- [x] T118 Resolve contract and implementation drift in `specs/019-v3-web-integration/repair-evidence/contract-decisions.md`. Dependencies: T117.

  - Required behavior and completion: Inventory actual routes, package format, mode aliases, provider adapters, upload limits, eleven First fields and historical origins against contracts/*.md and data-model.md. Retain api_generate/selected_api compatibility explicitly. The approved package is index.html/styles.css/script.js. Verify contracted Qwen support; do not silently delete it or add unsupported catalog options. Preserve existing captures; unsupported requirements remain open until implemented or explicitly changed by the user.

### Phase 19 — Real runtime and evidence foundations

**Goal / independent acceptance:** A browser-driven harness executes production internal boundaries and fails if any required boundary is absent.

- [x] T119 Build a disposable joined runtime harness in `v3_engine/tests/web_pipeline/runtime_harness.py; tests/Support/V3/WebPipelineRuntime.php`. Dependencies: T118.

  - Required behavior and completion: Start real Laravel HTTP, queue/outbox dispatch, FastAPI bridge, Temporal service/namespace, registered production workers and real isolated browser runner. Use one shared on-disk test DB accessible by PHP subprocesses; :memory: cannot share process state. Authenticate fixture actors through browser login with session/CSRF. Seed users/settings/framework only, never expected pipeline outputs. Replace external provider transports only and guard injection to test configuration.

- [ ] T120 Implement a machine-checkable evidence ledger in `scripts/v3/verify_web_repair_evidence.py; specs/019-v3-web-integration/repair-evidence/manifest.json`. Dependencies: T119.

  - Required behavior and completion: Implement the schema/status rules in repair-acceptance-v2.md. Record exact command, exit status, revision or dirty-diff hash, timestamps, test identity, doubles, trace IDs, assertions and artifact digests. Validator must reject missing evidence, stale source digest, required skips, conflicting statuses and unresolved acceptance rows. Distinguish offline-ready from complete; do not count test totals as gate evidence.

- [x] T121 Prove the harness detects disconnected boundaries in `v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T119,T120. Evidence: `pytest .../test_joined_runtime.py --run-temporal` passed 10 tests, including boundary failure/recovery cases and the browser-driven joined run.

  - Required behavior and completion: Use runtime transport faults, never edited production success methods: unavailable bridge, wrong signing key, stopped worker, failed callback and failed browser check each prevent the relevant milestone. Restore each boundary and demonstrate safe recovery. Failure assertions must identify the missing stage; tests that pass with internal boundaries disabled cannot establish acceptance.

### Phase 20 — US1: complete historical archive import (P1)

**Goal / independent acceptance:** Independently import verified historical data, open it as its teacher, and preserve exact content and provenance without provider requests.

- [x] T122 [US1] Add current archive-shape regression fixtures in `tests/Feature/V3/WebPipeline/TerminalImportTest.php; tests/Support/V3/WebPipelineFixtures.php`. Dependencies: T118.

  - Required behavior and completion: Use schema-1 per-PDF archives with work_count/scene_count, nested lessons[].scenes[] exports and per-PDF lesson_handoffs. Include two lessons with unequal section/scene counts, non-ASCII text, missing fields, repeated local ordinals, bad hashes, duplicate identities and an unfinished PDF. Capture failing assertions before repairs; count records, not only run counters. A01–A04.

- [ ] T123 [US1] Verify and capture the actual archive graph in `app/Services/V3/WebPipeline/TerminalArchiveReader.php; app/Services/V3/WebPipeline/ImportCapture.php`. Dependencies: T122.

  - Required behavior and completion: Parse supported schema/kind and positive typed ordinals; bind nested scene exports to matching handoffs and PDF-local lessons. Verify every listed file, byte count, raw answer and prompt digest, map/source/framework provenance and root-relative containment including symlink ancestors. Snapshot parsed bytes and include all accepted evidence/destination/profile/import identity in a stable digest. Missing/corrupt PDF evidence cannot be completed; preserve explicit valid partial results. Dry-run writes/copies/sends zero.

- [x] T124 [US1] Implement canonical missing-aware First projection in `app/Services/V3/WebPipeline/ProjectLessonBundle.php; tests/Feature/V3/WebPipeline/ImportedProjectionTest.php`. Dependencies: T122,T123.

  - Required behavior and completion: Parse retained raw First deterministically using the actual eleven canonical TeacherSection field labels. Preserve NULL versus empty text, raw bytes and warnings; never invent replacement educational fields or use an LLM. Map sections/scenes using actual identity; block ambiguous associations. Keep strict live protocol validation where required and explicitly support historical missing data. A04,A08.

- [ ] T125 [US1] Support truthful historical provenance in the schema in `database/migrations/; app/Models/V3/; tests/Integration/V3/WebPipeline/MigrationUpgradeTest.php`. Dependencies: T123,T124.

  - Required behavior and completion: Add an additive migration only if necessary for genuine historical records: source evidence references, nullable unavailable live request/generation fields, and imported origin. Do not fabricate queries, extracted text, framework files, canonical hashes, dispatches or costs to satisfy foreign keys. Reconstruct only records evidenced by retained artifacts; otherwise represent absence explicitly. Verify upgrade on a populated disposable DB and unchanged legacy constraints. A04,A28.

- [ ] T126 [US1] Materialize the full canonical import graph in `app/Services/V3/WebPipeline/MaterializeTerminalImport.php; app/Services/V3/WebPipeline/ImportCapture.php`. Dependencies: T123,T124,T125.

  - Required behavior and completion: Copy only verified evidence into a separate private application root and rehash before publishing. Create deterministic import/item/case/lecture/map/lesson/TeacherSection/prompt identities and legacy navigation links. Preserve raw evidence and section/prompt associations. Use awaiting_manual, historical labels, real import timestamps and retained original timestamps. No synthetic notebook/provider identity or fake approval. Counters derive from committed canonical rows; do not stop at legacy Section creation. A01–A04.

- [ ] T127 [US1] Verify synchronous and queued import recovery in `v3_engine/tests/web_pipeline/test_import_workflow.py; tests/Feature/V3/WebPipeline/TerminalImportTest.php; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T121,T126.

  - Required behavior and completion: Run CLI sync and --queue through the actual signed bridge, registered import workflow and Laravel materializer. Reuse queued placeholder only on matching lineage. Repeat identical import, race duplicates, interrupt after an item commit, lose callback acknowledgment, and change destination/archive bytes. Require stable records/receipts, no duplicates, changed-body conflict and zero external calls; partial status must propagate to CLI/workflow/web. A02,A03.

- [x] T128 [US1] Execute the exact eight-PDF reference acceptance in `v3_engine/tests/web_pipeline/test_reference_archive.py; specs/019-v3-web-integration/repair-evidence/reference-import.json`. Dependencies: T126,T127. Evidence: exact archive test passed with dry-run, 8 PDF items, per-PDF lessons [9,3,9,7,5,8,8,14], 63 lesson nodes, 89 exact prompt bodies and associations, no generated candidates/provider sends, duplicate idempotency, source immutability and archived hashes; see `repair-evidence/reference-import.json`.

  - Required behavior and completion: Use contracts/archive-import.md exact root and run 09a59afe-ed26-4567-906b-e42d87d53362. Dry-run first, then import into a disposable migrated populated DB/root. Assert eight linked lectures/cases/items, per-PDF lesson counts [9,3,9,7,5,8,8,14], 63 lesson records, 89 exact prompt bodies and associations, and zero generated/built/approved scenes/provider calls. Count TeacherSections from actual source section structure, not 63 by assumption. Repeat/restart without duplication; source archive hashes remain identical. A01–A04.

- [x] T129 [US1] Repair and exercise imported teacher navigation in `app/Services/V3/WebPipeline/LectureProjection.php; resources/views/lectures/show.blade.php; tests/Feature/V3/WebPipeline/ImportedLectureViewTest.php; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T128. Evidence: exact archive browser acceptance follows all 8 subject lecture links, lesson maps, all 90 section pages, and verifies 89 prompt links and the exact 89-prompt export; feature tests verify owner and foreign-teacher access. Evidence counts are in `repair-evidence/reference-import.json`.

  - Required behavior and completion: Open all eight imported lectures through the existing subject page as the owning teacher. Follow canonical lesson/section/prompt links, verify exact text/download hashes, historical labels, missing-field display and scene count. Ensure all 63 lessons and 89 prompts are reachable through owner navigation/export, and foreign/admin routes cannot read them. A01,A04,A26.

### Phase 21 — US2: website upload through saved lessons (P1)

**Goal / independent acceptance:** One browser upload produces real service-backed PDF-local results, durable exports and truthful UI states.

- [x] T130 [US2] Specify variable external transport fixtures in `v3_engine/tests/web_pipeline/test_joined_runtime.py; v3_engine/tests/web_pipeline/runtime_harness.py`. Dependencies: T121.

  - Required behavior and completion: Script NotebookLM transport with PDF 1 mapping two lessons and PDF 2 mapping three, with unequal scene counts and arbitrary scene labels. Capture framework uploads, active source IDs, Divide/Execution/Start/Continue requests and reset order at the external boundary. Return fixture content only from external transport; use real parsers/services/workflows. A05–A10.

- [x] T131 [US2] Connect and verify browser intake and sequential service execution in `app/Http/Controllers/V3/Teacher/SubjectRunController.php; v3_engine/web_pipeline/activities.py; v3_engine/web_pipeline/subject_workflow.py; v3_engine/terminal_pipeline/worker.py`. Dependencies: T130.

  - Required behavior and completion: Submit the two PDFs via the actual subject form; verify CSRF, ordered exact private bytes, one lecture/case per accepted input, stable manifest/outbox admission and actual registered queues. Enforce one framework upload, PDF-only Divide scope, PDF+framework lesson scope and durable archive/reset before the next PDF. Retain existing NotebookTerminalService; do not create a parallel engine. A05–A07.

- [x] T132 [US2] Repair canonical callback persistence and replay fences in `app/Services/V3/WebPipeline/AcceptWebLesson.php; app/Http/Controllers/V3/Internal/WebPipelineController.php; tests/Integration/V3/WebPipeline/BundleCommitTest.php`. Dependencies: T124,T125,T131.

  - Required behavior and completion: Trace real signed callbacks to complete canonical fields/prompts/raw evidence and committed cursor/item/run versions. Eliminate placeholder document/source hashes or invented conversation IDs. Same operation/result retry must return the same refs without another section/prompt; divergent digest or stale lease/version must conflict. Multiple sections/scenes in one lesson must not inflate lesson counts. A07,A08,A24.

- [x] T133 [US2] Verify partial failure and uncertainty semantics in `v3_engine/tests/web_pipeline/test_joined_runtime.py; v3_engine/web_pipeline/subject_workflow.py`. Dependencies: T132.

  - Required behavior and completion: Inject a settled PDF-local failure and require safe continuation plus partial aggregate. Inject authentication/quota failure, uncertain remote send, failed persistence, archive or reset and require an explicit hold before unsafe next work. Recovered retained results must not repeat remote sends. Missing map or usable prompt output cannot become completed. A07–A09,A14.

- [x] T134 [US2] Repair upload, progress and export views in `resources/views/subjects/show.blade.php; resources/views/v3/teacher/subject-runs/create.blade.php; resources/views/v3/teacher/subject-runs/show.blade.php; tests/Feature/V3/WebPipeline/PromptExportTest.php`. Dependencies: T132,T133.

  - Required behavior and completion: Show real readiness and upload errors, order, accepted/rejected PDFs and partial/blocked state. Exercise whole-run/item/scene JSON and text downloads from browser, comparing prompt text and approved template separately by hash. Default export_manual must make zero generation requests. Validate 1–20 count and transport/byte boundaries without repeatedly processing huge PDFs. A05,A08,A10,A25.

### Phase 22 — US3: manual and selected API scenes (P1)

**Goal / independent acceptance:** Every candidate comes from its exact prompt and reaches actual checks; selected provider settings and retry behavior are proven.

- [x] T135 [US3] Add package and selection rejection tests in `tests/Feature/V3/WebPipeline/ManualSceneImportTest.php; tests/Feature/V3/WebPipeline/TeacherSceneUiTest.php`. Dependencies: T118,T134.

  - Required behavior and completion: Reject malformed/oversized/extra-file/path-traversal packages, obsolete prompt IDs, changed duplicate commands and foreign ownership. Accept only contracted three-file source with exact bytes. Confirm missing credentials/settings or consent fail before a generation send. Test generation-mode aliases and current prompt selection. A11–A13.

- [x] T136 [US3] Verify immutable selected provider settings end to end in `app/Services/V3/WebPipeline/SelectSceneGeneration.php; app/Services/V3/Authoring/ClaimAuthoringRequest.php; app/Services/V3/Authoring/ClaimCodingAttempt.php; v3_engine/authoring/providers.py; v3_engine/tests/authoring/test_provider_wire.py`. Dependencies: T135.

  - Required behavior and completion: Retain the recent override repair where valid. Trace provider/model/approved endpoint/template/timeout/thinking/output settings from UI through command, reservation and actual transport payload. No fallback, silently ignored setting or restriction invented to match a test. Implement contracted selectable adapters including Qwen if still required; preserve old captures through explicit compatibility. Verify each supported adapter with external transport doubles; one live provider later proves only that provider. A13.

- [x] T137 [US3] Connect manual import to real browser qualification in `app/Services/V3/WebPipeline/ManualSceneCommand.php; v3_engine/web_pipeline/check_activities.py; resources/views/v3/teacher/subject-runs/scenes.blade.php; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T135.

  - Required behavior and completion: Upload one real package through the UI, preserve bytes/manual provenance, schedule the real isolated Chromium checks and retain report/capture/digests. Broken runtime/network/interaction/accessibility prevents eligibility. Exact duplicate reuses the candidate; manual/demo creates zero provider/JEV attempts. A11,A12,A16.

- [x] T138 [US3] Connect selected API command to candidate checks in `v3_engine/web_pipeline/scene_workflow.py; v3_engine/web_pipeline/scene_activities.py; v3_engine/authoring/activity.py; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T136,T137.

  - Required behavior and completion: Click generate for exactly one current saved prompt. Trace the actual authoring/scene workflow selected by production dispatch; do not force an obsolete workflow name. Require one external generation transport request, no unselected requests, genuine retained response/source/build metadata, signed candidate callback and seven actual checks. A run returning scene_processing does not establish dispatch. A13,A16.

- [x] T139 [US3] Prove response, build and uncertain-send recovery in `v3_engine/tests/scene_generation/test_service.py; v3_engine/tests/authoring/test_fallback.py; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T138.

  - Required behavior and completion: Interrupt after retained response and before callback, corrupt a build while retaining valid source, then restart. Require local response/build recovery, new immutable build attempt where appropriate, unchanged prompt/provider association and zero added generation sends. Tampered source/receipt fails closed. Unproven send remains uncertain until an explicit new consented attempt. A14,A15.

### Phase 23 — US4: preview, review and correction (P1)

**Goal / independent acceptance:** Owner actions on the real review page consume exact current decisions and preserve candidate ancestry.

- [x] T140 [US4] Exercise real isolated preview and first-frame gating in `resources/views/v3/teacher/reviews/show.blade.php; app/Services/V3/Review/StartReviewPreview.php; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T137,T138.

  - Required behavior and completion: Open actual candidate preview as original teacher, retrieve rendered first frame, perform an interaction and observe a meaningful response. Require real first_frame_at for exact candidate before approval. No inserted timestamp, one-pixel fixture or application-origin executable code. Failed/stale checks, missing frame and foreign/inactive/admin sessions deny review. A16,A17.

- [x] T141 [US4] Verify exact decision consumption and concurrency in `app/Services/V3/Review/SubmitReviewDecision.php; app/Services/V3/Review/DispatchReviewEffects.php; tests/Integration/V3/WebPipeline/ReviewRaceTest.php; v3_engine/web_pipeline/review_effects.py`. Dependencies: T140.

  - Required behavior and completion: Submit approve/request_changes through UI and concurrently submit stale/conflicting requests. Exactly one current decision wins. Actual registered worker consumes matching decision ID/digest/version; accepted HTTP/signal or closed workflow alone cannot mark delivered. Recover lost acknowledgment without duplicate approval or correction. A18,A24.

- [x] T142 [US4] Execute manual and API correction journeys in `app/Services/V3/Review/SubmitPostapprovalCorrection.php; v3_engine/web_pipeline/review_effects.py; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T139,T141.

  - Required behavior and completion: Exercise visual and factual corrections from the website. Manual mode awaits replacement with zero scene API requests; explicit source revision is a distinct durable NotebookLM operation. API mode creates only the selected successor, followed by fresh checks/preview/decision. Preserve predecessor approval history; stale candidate approval fails. Recover corrections after processing workflow closure through shared admission. A19,A20.

- [x] T143 [US4] Verify evidence, downloads and owner access across views in `app/Services/V3/Evidence/EvidenceProjector.php; tests/Feature/V3/WebPipeline/EvidenceLineageTest.php; tests/Feature/V3/WebPipeline/LinkedContentAccessTest.php`. Dependencies: T129,T142.

  - Required behavior and completion: Trace source→raw lesson→prompt→manual receipt or real attempt→candidate→checks→waiver or real qualification→preview→decision. Exercise owner evidence/download pages and alternate legacy list/bulk/CRUD/API routes. Admin sees operational metadata only; demo/manual labels never imply paid generation or real JEV. Preserve deletion blockers for unresolved effects and approvals. A20,A26,A27.

### Phase 24 — US5: controls, recovery and operations (P2)

**Goal / independent acceptance:** Commands, shared capacity and status remain correct during process failure and recovery.

- [x] T144 [US5] Exercise website controls at actual settled boundaries in `app/Services/V3/WebPipeline/ApplyRunControl.php; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T133,T142.

  - Required behavior and completion: Click pause/resume/cancel during active and paused work and cancel queued work. Requested/accepted/applied remain distinct; no next mutation after applied pause/cancel. Cancel wakes paused work and releases capacity. Duplicate/stale/changed commands follow identity/version rules and admin controls cannot generate/import/review. A21,A22,A24.

- [ ] T145 [US5] Verify mixed capacity, process recovery and history replay in `v3_engine/tests/web_pipeline/test_controls_capacity.py; v3_engine/tests/web_pipeline/test_replay.py; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T127,T139,T144.

  - Reopened 2026-10-12: the current joined run and capacity tests cover shared admission, queued cancellation and some replay behavior; this does not prove process restarts after every required boundary with retained state or the complete four-subject/four-IO/two-build/64-queued production limits. Complete those cases and attach retained-run evidence before closing.
  - Required behavior and completion: Run production registered web/terminal/import/scene work under shared four-subject/four-IO/two-build and 64-queued limits. Manual/review waits release active generation capacity. Restart real bridge/worker processes after admission/response/commit/finalization with retained DB/namespace and prove no duplicate sends/commits. Fetch actual histories and invoke Replayer replay APIs, including existing legacy histories. A21–A24,A28.

- [ ] T146 [US5] Repair teacher/admin progress and offline views in `app/Services/V3/WebPipeline/RunProgress.php; app/Services/V3/WebPipeline/AdminRunSummary.php; resources/views/v3/teacher/subject-runs/show.blade.php; tests/Feature/V3/WebPipeline/ProgressProjectionTest.php`. Dependencies: T143,T144.

  - Required behavior and completion: Count lessons as lessons, prompts as prompts and candidates/checks/approvals from committed rows. Render unknown totals as unknown, last-known source/age during outage and unapplied controls separately. Browser-test queued/running/partial/blocked/manual wait/review wait/cancelled/completed states. Verify event filtering before pagination and restored live status after reconnection. A25–A27.

- [ ] T147 [US5] Inspect actual serialized history and operational privacy in `v3_engine/tests/web_pipeline/test_privacy_history.py; tests/Feature/V3/WebPipeline/PrivateMetadataTest.php`. Dependencies: T145,T146.

  - Reopened 2026-10-12: sanitizer and admin-response tests pass, but current test_privacy_history.py injects sentinels into activity/admission/event values; it does not search actual Temporal histories, search attributes, error causes or process logs. Add retained serialized-history and log inspection before closing.
  - Required behavior and completion: Search actual histories, search attributes, error causes, logs and admin HTTP responses using fixture content/credential/provider-ID sentinels. Enforce <=32 KiB metadata payloads and private opaque refs. Verify signed callbacks reject invalid signatures/replay/oversize/stale scopes. Reports retain sanitized IDs/hashes, not secrets or educational bodies. A26,A27.

### Phase 25 — Final acceptance and truthful delivery

**Goal / independent acceptance:** All required offline journeys and independent live evidence must be accounted for before a completion verdict.

- [ ] T148 Finish the website and endpoint coverage matrix in `specs/019-v3-web-integration/repair-evidence/website-matrix.md; v3_engine/tests/web_pipeline/test_joined_runtime.py`. Dependencies: T129,T134,T139,T143,T147. Reopened 2026-10-12: the matrix truthfully identifies partial views, but the task requires every V01–V12 row to have a successful browser action, durable effect, and denial/error case. V02 state coverage and V09/V11/V12 recovery or full-view coverage remain incomplete.

  - Required behavior and completion: Execute every V01–V12 row in repair-acceptance-v2.md using the actual route inventory. Record successful browser action, HTTP request, durable effect and denied/error case. Screenshots alone and direct service calls cannot close website journeys. Internal/CLI-only services are tested at their real boundary and linked to resulting owner views; do not invent an admin import page.

- [x] T149 Implement a live gate that executes the complete chain in `v3_engine/tests/web_pipeline/test_live_web_pipeline.py; tests/Integration/V3/WebPipeline/LiveApplicationGateTest.php`. Dependencies: T148. Evidence: opt-in full-chain runner and named prerequisite/preflight checks implemented; preflight plus evidence-validator regressions passed (4 tests). Normal invocation skips before provider setup. Full live execution remains T152 and is not claimed.

  - Required behavior and completion: Use the same joined harness without external transport doubles. Reconcile documented opt-in aliases and validate dedicated approved session/proof, two disposable PDFs, selected provider/model, permitted spending, browser and running services. An enabled, provisioned gate must execute upload→NotebookLM→one generated scene→checks→owner preview/decision, with durable evidence. Environment-only assertions or staging success cannot pass; a mid-run failure must fail, never become skip.

- [x] T150 Run targeted and affected regression suites in `specs/019-v3-web-integration/repair-evidence/regressions.md; package.json`. Evidence (2026-10-12): PHP WebPipeline suites 174 passed/1 live skip (1,005 assertions); Python WebPipeline 94 passed/9 skips; live NotebookLM two-PDF browser run 1 passed (330.05 seconds); Temporal-enabled joined runtime 13 passed; capacity/replay 9 passed; evidence validator regression tests 2 passed. Full broad results and exact logs are in repair-evidence/regressions.md and repair-evidence/runtime/. Full unrelated legacy repository suite was not claimed.

  - Required behavior and completion: Run the command families in repair-acceptance-v2.md against disposable environments. Include populated upgrades, real browser/races/Temporal replay, terminal/SDK/scene recovery and affected legacy owner/access/qualification/review/evidence suites plus npm run build. Record exact totals and existing unrelated failures separately. Do not sum overlapping runs or rerun broad suites without a change/failure reason. A28.

- [ ] T151 Execute and validate the complete offline acceptance bundle in `specs/019-v3-web-integration/repair-evidence/manifest.json; scripts/v3/verify_web_repair_evidence.py`. Dependencies: T148,T150.

  - Required behavior and completion: Require joined two-PDF manual and selected-provider lanes, exact reference import, all website rows, recovery/control/replay/privacy and regression evidence. Run validator --require offline and retain its output. Missing mandatory offline dependencies/tests are blockers, not a skipped green result. This milestone permits only offline-verified wording.

- [ ] T152 Execute the dedicated live acceptance or retain an explicit open gate in `specs/019-v3-web-integration/repair-evidence/live.json; specs/019-v3-web-integration/implementation-report.md`. Dependencies: T149,T151.

  - Required behavior and completion: Run only with dedicated authorization/prerequisites; prior browser sign-in alone does not prove SDK/session/provider readiness. Verify the approved account rather than switch profiles. If absent, record exact missing names and keep this task and T112 unchecked; finish independent documentation. When provisioned, run the full real chain and validator --require complete. No paid keys are required for archive import/default manual scene mode; NotebookLM authentication is a separate prerequisite.

- [x] T153 Update actual operator commands and current limitations in `docs/V3-WEB-PIPELINE.md; specs/019-v3-web-integration/quickstart.md; specs/019-v3-web-integration/implementation-report.md`. Current commands, offline test evidence, manifest failure, archive acceptance gap and live prerequisites are documented. Full acceptance remains open under T151/T152.

  - Required behavior and completion: Document validated startup/dispatch/namespace/root commands, readiness diagnostics, configured origin identity, approved auth procedure, manual/API/import/recovery routes, exact package shape and provider availability. Explain failure symptoms with safe codes and recovery action. State live verification status separately and stop only owned processes.

- [ ] T154 Reconcile every original and repair checkbox against evidence in `specs/019-v3-web-integration/tasks.md; specs/019-v3-web-integration/acceptance-matrix.md; specs/019-v3-web-integration/repair-evidence/task-audit.md`. Dependencies: T151,T153.

  - Required behavior and completion: Map all A01–A28 and T001–T155 without renumbering or changing their meaning. Close T105/T106/T108/T109/T110/T111 only with their actual conditions; T112 requires T152. Reassess previously checked importer/projection/documentation tasks. Preserve historical reports with a dated superseding status. A blocked live task must not prevent finishing this truthful reconciliation.

- [ ] T155 Perform final completion review and selector/workspace cleanup in `specs/019-v3-web-integration/repair-evidence/final-review.md; .specify/feature.json`. Dependencies: T153,T154.

  - Required behavior and completion: Review the production path and evidence, not just checkboxes. Confirm no synthetic success, fake FK lineage, weakened tests or unaccounted website rows; compare source/archive/DB protection evidence and restore selector bytes. Record offline validator outcome and complete validator outcome or exact open gates. This review can complete while live remains open, but cannot declare the feature fully complete.

### Dependency and execution notes

Setup T116–T118 precedes the runtime foundation T119–T121. US1 T122–T129 can begin its reader/projection fixtures after T118 while the harness is built; joined import depends on T121. US2 T130–T134 uses that harness and canonical projection. US3 T135–T139 follows saved prompts; US4 T140–T143 follows checked candidates; US5 T144–T147 follows those command paths. Final T148–T151 proves the offline application; T152 is the independent live gate. T153–T155 MUST finish even if T152 remains unprovisioned.

Parallel opportunities (not authorization to spawn agents): after T118, T122 archive fixtures and T119 runtime harness touch different files; after T135, provider-wire work in T136 and manual materialization in T137 can proceed independently if shared controller changes are serialized. Within US4, access-only cases for T143 can be prepared while T140 preview runs, but completion waits for its dependencies. US5 history analysis must wait for actual runs; do not fabricate histories to parallelize it. Other story tasks are intentionally sequential because they share artifacts and IDs.

First independently useful checkpoint: US1 T129, proving the real reference archive appears correctly on the website. It is not whole-feature completion. Next checkpoints: T134 web-to-saved-prompts; T143 scenes-to-owner-review; T151 complete offline acceptance. Full completion additionally requires T152 and the final review.

Task counts: setup 3; foundations 3; US1 8; US2 5; US3 5; US4 4; US5 4; final acceptance/delivery 8 = **40 new tasks**. A task checkbox means its own stated assertions have evidence, not merely that implementation code exists.
