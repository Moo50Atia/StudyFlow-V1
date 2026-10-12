# Spec 019 Task & Completion Audit (T117)

Date: 2026-10-12
Governing References: `specs/019-v3-web-integration/acceptance-matrix.md`, `specs/019-v3-web-integration/repair-acceptance-v2.md`, `specs/019-v3-web-integration/tasks.md`

## 1. Executive Summary & Evidence Classification

The previous implementation pass reported 180 PHP test passes (1 skipped) and 110 Python test passes (2 skipped). A rigorous review of the codebase and test harness reveals:
1. **Component-Level Only**: These passes reflect isolated unit, schema, and mocked integration tests. They successfully validate local data structures, database migrations, and component invariants, but do **not** constitute end-to-end joined website acceptance.
2. **Mocking & Faking Boundaries**:
   - `ConnectedWebPipelineTest.php` faked the bridge HTTP responses via `Http::fake()` and manually invoked application service layers rather than receiving real worker-initiated signed callbacks.
   - Python tests in `v3_engine/tests/web_pipeline/test_notebook_workflow.py` and `test_scene_execution.py` used injected mock activity outputs or executed in isolation from the Laravel HTTP and database processes.
3. **Historical Archive Gaps**:
   - `TerminalArchiveReader` read top-level `prompts` in JSON manifests; the actual reference export (`storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root`, run `09a59afe-ed26-4567-906b-e42d87d53362`) stores entries under `lessons[].scenes[]` across 8 PDF directories, with 63 lessons and 89 nested scene prompts.
   - Previous tests asserted dry-run execution or small 2-PDF fixtures without materializing the full 8-PDF reference graph (63 lessons, 89 prompts) into canonical database entities.
4. **Scene Dispatch Disconnect**:
   - `ApplicationSubjectWorkflow` reached a terminal state in `selected_api` mode without initiating `ApplicationSceneWorkflow` or dispatching actual authoring workers.
5. **Live Gate State**:
   - Prior live tests (`LiveApplicationGateTest.php`, `test_live_web_pipeline.py`) only checked environment flags and web staging without executing the real provider pipeline.
   - Tasks T105, T106, T108, T109, T110, T111, and T112 were appropriately **reopened** in `tasks.md`.

---

## 2. Comprehensive Audit & Mapping: T001–T065 to A01–A28

| Task | Scope / Description | Mapped Acceptance ID(s) | Historical Status | Reassessed Evidence & Joined Gap Analysis |
|---|---|---|---|---|
| **T001** | Baseline hashes, migrations, versions | A28 | Verified | Clean component record. Real DB preserved; 558 dirty files cataloged. |
| **T002** | Test fixtures, 2-PDF archives, malformed data | A01, A03, A11, A16 | Verified | Fixtures in `WebPipelineFixtures.php` and `conftest.py` valid, but did not match real 8-PDF nested schema. |
| **T003** | Boundary & schema contract tests | A04, A11, A13, A27 | Verified | 6 PHP tests (36 assertions), 10 Python tests pass. Verified 3-file package and 32KiB invariants. |
| **T004** | Web pipeline tables & models | A05, A21, A24 | Verified | Migration `2026_10_10_000019` creates core tables; model casts and immutable rules verified. |
| **T005** | Projection provenance & legacy links | A04, A06, A08, A28 | Verified | Migration `2026_10_10_000020` adds links and nullable eleven fields. Real DB upgraded cleanly in disposable tests. |
| **T006** | Manual import & qualification gate models | A13, A16, A20 | Verified | Migration `2026_10_10_000021` adds qualification gates and manual scene imports. |
| **T007** | Migration upgrade tests on cloned DB | A28 | Verified | `MigrationUpgradeTest.php` passes (3 tests, 40 assertions) on disposable DB copy. |
| **T008** | Legacy route leakage & command race tests | A24, A26 | Verified | `CommandRaceTest.php` (2 passed); red baseline established for access guards in `LinkedContentAccessTest.php`. |
| **T009** | Enforce teacher ownership on legacy routes | A26, A28 | Verified | `TeacherAccessMiddleware` and controller guards updated to block unauthorized access to linked entities. |
| **T010** | Pipeline command & outbox models/repositories | A02, A21, A24 | Verified | Command deduplication and outbox transition logic verified at unit level. |
| **T011** | Admission & capacity throttles | A21, A23, A27 | Verified | Shared 4-subject / 4-IO / 2-build limits verified at service level. |
| **T012** | Terminal import reader & schema parsing | A01, A02, A03 | Incomplete / Gapped | Read top-level prompts; failed to parse real reference archive's nested `lessons[].scenes[]` structure. |
| **T013** | First parser eleven canonical fields | A04, A08 | Verified | `ProjectLessonBundle` and `first_parser.py` parse 11 fields, but missing-aware null vs empty handling needed hardening. |
| **T014** | Import item & lecture materialization | A01, A02, A03 | Incomplete / Gapped | Created legacy sections and lecture links, but stopped short of full canonical lesson/section/prompt graph. |
| **T015** | Imported projection service | A04, A08 | Verified | `LectureProjection` and blade views show imported lectures. |
| **T016** | Import dry-run & conflict detection | A01, A02, A04 | Verified | Dry-run creates no writes; checksum mismatches detect conflicts at unit level. |
| **T017** | Private artifact retention & symlink security | A02, A03, A27 | Verified | Root containment checks and private storage paths verified. |
| **T018** | Queued import workflow in Temporal | A02, A03 | Partial | Workflow defined, but bridge dispatch and status propagation lacked end-to-end joined verification. |
| **T019** | CLI import command (`v3:import-terminal-run`) | A01, A02, A03 | Partial | CLI command works, but depends on `TerminalArchiveReader` which had schema parsing bugs on 8-PDF run. |
| **T020** | Imported lecture teacher view | A01, A04, A26 | Verified | `ImportedLectureViewTest.php` tests teacher viewing imported content. |
| **T021** | Imported lecture access denial for other users | A01, A04, A26 | Verified | Foreign teachers and admins blocked from viewing teacher-owned lectures. |
| **T022** | Web PDF upload controller & validation | A05 | Verified | Controller accepts ordered PDFs (1–20), rejects malformed/oversize files and CSRF absence. |
| **T023** | Durable intake admission & outbox | A05, A21, A23 | Verified | SubjectRun created with pending outbox entries. |
| **T024** | Private PDF storage & digest hashing | A05, A28 | Verified | PDFs stored in private disk; SHA-256 digests recorded. |
| **T025** | Outbox dispatcher command (`v3:dispatch-web-pipeline`) | A21 | Verified | Dispatches outbox entries to FastAPI bridge with HMAC signatures. |
| **T026** | FastAPI bridge dispatch & Temporal client | A21, A23, A24 | Verified | Bridge receives signed requests and starts workflows in Temporal. |
| **T027** | NotebookLM workflow turn orchestration | A06, A07, A08, A09, A14 | Partial | Workflow coordinates turns; activity tests mocked responses instead of running through real service. |
| **T028** | Multi-PDF divide & lesson sequencing | A06, A07, A09, A22, A23 | Partial | Sequences PDFs; needs joined verification with variable lesson counts (2 vs 3). |
| **T029** | NotebookLM service integration activities | A05, A06, A07, A09, A14 | Partial | Activity connects to `NotebookTerminalService`; tested with mock responses. |
| **T030** | Bundle commit callback handler | A07, A08, A24 | Partial | Internal callback commits bundles; needs replay fence and exact cursor verification. |
| **T031** | Prompt materialization from committed bundles | A07, A08, A24, A27 | Verified | Canonical prompts created with TeacherSection associations. |
| **T032** | Prompt export service (JSON & text) | A08, A10 | Verified | `PromptExportTest.php` validates whole-run, item, and scene prompt downloads. |
| **T033** | Run progress projection service | A08, A09, A25 | Verified | `RunProgress.php` projects counts; null totals rendered correctly during initial phases. |
| **T034** | Teacher subject run show view | A05, A10, A25 | Verified | Blade views display status, item list, and export buttons. |
| **T035** | Manual scene package validation & storage | A11, A12, A13, A16 | Verified | `ManualSceneImportTest.php` validates 3-file package (`index.html`, `styles.css`, `script.js`). |
| **T036** | Eligible authoring request resolution | A13, A14, A15 | Verified | Resolves prompt eligibility; verifies provider override persistence. |
| **T037** | Manual scene command & candidate creation | A11, A12, A15 | Verified | Manual import creates candidate with `manual` provenance. |
| **T038** | Scene generation mode selection | A11, A13, A16 | Verified | Supports `export_manual`, `api_generate`, and legacy alias `selected_api`. |
| **T039** | Authoring provider adapters (Gemini, Qwen) | A13 | Verified | Adapters defined with immutable settings and wire tests. |
| **T040** | Scene authoring Temporal workflow | A13, A14, A15 | Disconnected | Workflow was not triggered by `ApplicationSubjectWorkflow` when mode was `selected_api`. |
| **T041** | Scene authoring activities & candidate callback | A13, A14, A15, A23 | Partial | Activity calls provider adapter; candidate callback needs joined verification. |
| **T042** | Isolated Chromium 7-point scene checks | A16 | Partial | Checks implemented in `check_activities.py`; tested in isolation, not wired into joined flow. |
| **T043** | Scene qualification callback & gate recording | A16 | Verified | Qualification gates recorded; pass vs waiver distinguished. |
| **T044** | Teacher scene management UI | A11, A12, A13 | Verified | Teacher scenes Blade view displays candidates, status, and manual upload form. |
| **T045** | Review item resolver & context | A17, A18, A26 | Verified | Resolves review context for owner teacher. |
| **T046** | Review decision submission service | A18, A19 | Verified | Submits approve / request_changes decisions with version checks. |
| **T047** | Isolated preview viewer session & frame capture | A17, A26 | Partial | View renders preview iframe; first-frame gating needs real browser verification. |
| **T048** | Teacher review Blade view | A17, A18, A20 | Verified | Review UI displays scene, frame, decision buttons, and correction options. |
| **T049** | Review effects dispatch & Temporal signals | A18, A19, A21 | Partial | Signals workflow; lacks joined verification of exact worker decision consumption. |
| **T050** | Evidence lineage projection service | A20, A27 | Verified | `EvidenceProjector.php` traces source -> lesson -> prompt -> candidate -> checks -> review. |
| **T051** | Teacher evidence view & download routes | A20, A27 | Verified | Evidence Blade views render full lineage. |
| **T052** | Admin run summary controller & view | A25, A27 | Verified | Admin view provides operational metadata without leaking educational content. |
| **T053** | Pipeline run controls (pause, resume, cancel) | A21, A22, A23, A24 | Verified | Controls transition state at settled boundaries in unit tests. |
| **T054** | Privacy sanitization & metadata bounding | A26, A27 | Verified | Metadata payloads <= 32 KiB; sentinels scrubbed from logs and admin responses. |
| **T055** | Concurrency & command contention handling | A22, A24 | Verified | Versioning and optimistic locking reject concurrent duplicate commands with 409. |
| **T056** | Worker queue registration & capacity pools | A21, A23, A24 | Partial | Worker registers queues; needs joined multi-worker verification under load. |
| **T057** | Offline resilience & last-known projection | A25, A26, A27 | Verified | UI displays last-known age and status during orchestration downtime. |
| **T058** | Event store persistence & audit trail | A24, A27 | Verified | Pipeline events persisted durably before state mutations. |
| **T059** | Real-time status polling / SSE endpoint | A22, A25 | Verified | Status polling returns JSON status summary. |
| **T060** | Connected pipeline integration test | SC-001–SC-007 | Reopened | **REOPENED**: Faked bridge HTTP and called services manually; no joined runtime. |
| **T061** | Temporal history replay verification | A28 | Verified | `test_replay.py` verifies replay of captured histories. |
| **T062** | Production deployment docs & operator guide | A13, A28 | Partial | Updated in T113; requires final reconciliation in T153. |
| **T063** | Dedicated live application gate test | A13, A17 | Reopened | **REOPENED**: Only checked environment flags without executing live chain. |
| **T064** | Final regression run & acceptance matrix | A01, A28 | Reopened | **REOPENED**: Full test suites and reference archive materialization not executed. |
| **T065** | Final review & selector restoration | All | Reopened | Reopened; requires full T116–T155 execution. |

---

## 3. Reassessment of T066–T115 (Phase 16 & 17 Reopened Tasks)

| Task | Scope | Status | Reassessment Findings |
|---|---|---|---|
| **T105** | Linked runtime scenario | **REOPENED** | Prior test `ConnectedWebPipelineTest.php` faked bridge and manually dispatched callbacks. Needs real subprocess harness (T119). |
| **T106** | Runtime integration gaps | **REOPENED** | `ApplicationSubjectWorkflow` stopped at `selected_api` without launching `ApplicationSceneWorkflow`. Queued archive import materialization incomplete. |
| **T107** | Captured-history replay | Completed (component) | `test_replay.py` replayed existing histories successfully; retained for regression in T145/T150. |
| **T108** | Reference archive materialization | **REOPENED** | Real 8-PDF reference archive was only dry-run on 2-PDF samples; 63 lessons and 89 scene prompts were not materialized into canonical entities. (Addressed in T128). |
| **T109** | Connected manual & selected API scenes | **REOPENED** | Wiring between subject workflow and scene workflow was disconnected; browser qualification not executed in joined flow. (Addressed in T138). |
| **T110** | Dedicated live gate implementation | **REOPENED** | Gate merely checked environment variables rather than executing actual end-to-end pipeline. (Addressed in T149). |
| **T111** | Regression suite execution | **REOPENED** | Broad legacy suites and frontend builds were omitted from final verification. (Addressed in T150). |
| **T112** | Live execution | **OPEN** | Dependent on external credentials/prerequisites; remains explicitly open as an unprovisioned live gate (T152). |
| **T113** | Operator docs & quickstart | Completed | Updated operator guide; will be finalized in T153. |
| **T114** | Evidence mapping reconciliation | Completed | Historical mapping rebuilt; superseded by T117 and T154. |
| **T115** | Final review & cleanup | Reopened | Needs execution after T116–T154 completion. |

---

## 4. Architectural Gaps Resolved by Phase 18–25 (T116–T155)

1. **Joined Runtime Environment (T119–T121)**: Replace `Http::fake` and isolated execution with real Laravel subprocess (on dynamic port), real FastAPI bridge, real Temporal server/workers, and real Chromium runner communicating via a shared on-disk SQLite DB.
2. **True Reference Archive Materialization (T122–T129)**: Update `TerminalArchiveReader` to parse nested `lessons[].scenes[]`, per-PDF work/scene counts, and per-PDF handoffs from the 8-PDF reference root (`09a59afe-ed26-4567-906b-e42d87d53362`), materializing 63 canonical lessons and 89 scene prompts without synthetic provider records.
3. **End-to-End Web-to-Scene Connection (T130–T139)**: Connect `ApplicationSubjectWorkflow` and `ApplicationSceneWorkflow` so that selecting a scene triggers real candidate generation, 7-point browser checks, and owner review.
4. **Machine-Checkable Evidence Ledger (T120, T151)**: `scripts/v3/verify_web_repair_evidence.py` and `manifest.json` programmatically enforcing G01–G06, V01–V12, and A01–A28 criteria.
5. **Truthful Live Gate Separation (T149, T152)**: Maintain T112/T152 as explicitly open when credentials are unprovisioned, without compromising 100% offline integration verification.
