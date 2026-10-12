# Spec 019 Repair Baseline Audit (T116)

Date: 2026-10-12
Host OS: Linux x86_64
Repository Root: `/home/moahmmed/college_project`
Git Commit: `89dfa1a46c4ad4d2ce231aa7f6d1f4a404a707ce` (branch: `main`)

## 1. Feature Selector Tracking
- **Current Active Value**: `{"feature_directory":"specs/019-v3-web-integration"}`
- **Original Entry Selector (Restoration Target for T155)**: `{"feature_directory":"specs/020-local-pdf-splitting"}`
- **Integrity Rule**: `.specify/feature.json` must be restored to `specs/020-local-pdf-splitting` during T155 cleanup.

## 2. Runtime Environment & Toolchain
- **PHP**: 8.5.0 (cli) (Zend Engine v4.5.0, OPcache v8.5.0)
- **Composer**: 2.8.12 (2025-09-19)
- **Python**: 3.12.3 (via `.venv/bin/python`)
- **Pytest**: 9.1.1 (via `.venv/bin/python -m pytest`)
- **Temporal CLI**: 1.9.1 (Server 1.32.0, UI 2.54.1) at `/home/moahmmed/.local/bin/temporal`
- **Node.js**: v24.20.0

## 3. Network Ports & Existing Process Audit
- **Port 8000 Status**: LISTENING.
  - Command: `python3 -m http.server 8000` (PID 234227, user `moahmmed`, started Oct 10).
  - **CRITICAL**: Port 8000 is **NOT** Laravel. The harness must NOT attempt to bind to 8000 or terminate PID 234227.
- **Test Harness Port Allocation**:
  - Laravel HTTP Server: Dynamic or allocated high port (e.g., 8018) with identity validation via `/up` or health check.
  - FastAPI Outbox/Bridge: Dynamic or allocated high port (e.g., 8019).
  - Temporal Server: Dedicated ephemeral or persistent local port (default 7233 or ephemeral test cluster).

## 4. Protected Storage & Database Roots
- **Production Database**:
  - Path: `database/database.sqlite`
  - Size: 2,019,328 bytes
  - SHA-256: `c5235cd3ec600fc4f3750ff95051cf915a0165574cffbdcd7b8e6f4b9d24424b`
  - **Constraint**: Absolute prohibition against `migrate:fresh`, schema mutation, or direct writing by test runs.
- **Reference Historical Archive**:
  - Root: `storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root`
  - Reference Run: `09a59afe-ed26-4567-906b-e42d87d53362`
  - Status: Intact, 8 PDF directories, 63 lesson handoffs, 89 scene prompts.
  - **Constraint**: Read-only source. Must never be mutated or used as output destination.
- **Disposable Test Database Root**:
  - Root: `storage/testing/` (allocated per test harness run with discrete `.sqlite` files).
- **Private Artifact Root**:
  - Root: `storage/app/private/v3/` (subdirectories isolated per test run).

## 5. Prerequisite Names (Credentials & Keys - Values Redacted)
- `NOTEBOOKLM_SESSION_COOKIES` (Authentication for NotebookLM browser/session)
- `NOTEBOOKLM_AUTH_TOKEN` (API authorization token)
- `GEMINI_API_KEY` (Gemini scene authoring provider key)
- `QWEN_API_KEY` (Qwen scene authoring provider key)
- `TEMPORAL_CLI` (Path to Temporal binary for workflow orchestration)

## 6. Process Ownership & Teardown Protocol
- **Owned Processes**: Only processes spawned directly by `WebPipelineRuntime` or pytest harnesses (tracked by subprocess PID: PHP artisan serve, uvicorn FastAPI, temporal test server/worker) are subject to termination.
- **Unowned / Foreign Processes**: Existing processes including PID 234227 (`http.server`), IDE daemons (`agy`, `codex`), and system services are strictly protected.
- **Cleanup Guarantee**: All owned processes are cleanly terminated on test teardown via SIGTERM/SIGKILL hooks; temporary sqlite databases and scratch directories are removed or scoped to test lifecycles.

## 7. Dirty & Untracked Files Catalog (558 items)

| Status | File Path | SHA-256 Digest |
|---|---|---|
| M | `.env.example` | `661e7e9ac99a9e50df369a6c4fbd8a36cc94f0d9b0df174aa22d1913dfb9bd56` |
| M | `.gitignore` | `b1af5118f1c7587372b67e533cfbb7fe7c2b2b1297a26c29b8e829938fe07670` |
| M | `.specify/feature.json` | `ec10f03e4bbc44e0f8968377e9dc4adbe8ce50792de72a54cfb298b543d5fd18` |
| M | `Google-Ai-studio-System-prompts` | `5849d8e423ef5a2e0e16dfb61eec517011f992e136cdbcc12cef684e8a25e302` |
| M | `app/Http/Controllers/APIfilterController.php` | `a4d2ee13b3f4508d4b739bde5a244e8bfd01a0b0d47731a8d8c6eae04397e6bd` |
| M | `app/Http/Controllers/ExamQuestionController.php` | `fd06ebdb38a098ce5553bd3968bc6c0fdcfed543eb236766e2ab4db2dfa4b7b5` |
| M | `app/Http/Controllers/LectureController.php` | `1c64c403df066b43e3aebf30ebe42ce845aadd513b34c1891d31ae7fa125c82a` |
| M | `app/Http/Controllers/MohammedTestController.php` | `4af90949baa9f110042c6764ba845af7614b015111d8acc3494bc73a1d9d8bca` |
| M | `app/Http/Controllers/QuestionController.php` | `9ef6d721a6fba3597b86476be611057dbbd3be60e89775fef1e99cf7870ff00a` |
| M | `app/Http/Controllers/SectionController.php` | `a69c51950e7bacb424a1daad6f5095112f233d6fed26b6ac144c305084198b02` |
| M | `app/Http/Controllers/StudyFlowController.php` | `d9e4910130056681e18de258646808b2388ea9e477982be0261be5d0586dd5be` |
| M | `app/Http/Controllers/SubjectController.php` | `08b2e4cd0ce307550fd6b811f626e8a64c75bc20759df9cd2802cc5a668aab53` |
| M | `app/Http/Controllers/TeacherStudyFlowController.php` | `1acc162a6c2109b8d2b0b53b9558a5f82656b750f2c1b43499a587e71a34b00e` |
| M | `app/Http/Controllers/V3/Internal/LessonQueryController.php` | `3a141d47ceef0f55fe120c79a25c5c628fc1132d49a96ef11d0275f6e3a45546` |
| M | `app/Http/Controllers/V3/Internal/SourceBindingController.php` | `904e1e52b4a448d8e33dfebc1ffa956cdc8b2c5aa4f83c5be157599d7258ef81` |
| M | `app/Http/Middleware/TeacherAccessMiddleware.php` | `509bb0ece456601103b03364291ce65cce1f66fb841c3d409d65602e1dcfa732` |
| M | `app/Http/Middleware/V3/VerifyWorkerSignature.php` | `48916a95637229fcc64c424b870cbf5d7e8fff083cd34e118738e7fb5839639c` |
| M | `app/Http/Resources/LectureResource.php` | `013589da49db4d10ab3805570ceb5144cfe6f1ed0b0ea65f531ef8e31e7835f6` |
| M | `app/Models/Lecture.php` | `71937bd62eefeff0c9965ee7c1dc59784b7f6911035de05f5d2fd9fd0d138f0d` |
| M | `app/Models/Section.php` | `532a1a43a0dd3c28d3de21d2f59213f42409758f0c5f86b319a5c61c22ad7d94` |
| M | `app/Models/V3/ApprovedScene.php` | `a8f894460bb75a4b870bab02ab93efea4a2e3b6555a7c5b0252d0c114012f1a2` |
| M | `app/Models/V3/AuthoringRequest.php` | `802470c42710b2f5634715d88acac0d2f6d4c4e5e952f7097cc05a28ba09b107` |
| M | `app/Models/V3/SceneCandidate.php` | `9333c2edd69e3fd9e0a18adb4f3f00c0d87429b6c96d6675ff9e2fd404b3b1a0` |
| M | `app/Models/V3/TeacherReviewDecision.php` | `9f9e86a69c642a53e350d48a0d95d1ba1be0ad8cf12ecc2c594ec395f8ff6482` |
| M | `app/Models/V3/TeacherReviewItem.php` | `9c704c12063441e668783f94a40b1638376ded00afc324f438ca53fd25b5ff42` |
| M | `app/Services/V3/Admin/AssessDeletion.php` | `f94ed6b4970286492b6de0ff6c2bf6dfd25cd5eeb231bba7173a045cbcbf01e5` |
| M | `app/Services/V3/Authoring/ClaimAuthoringRequest.php` | `716647b49c3e38c4080366e5e5935f2628276ea549ae13d04ee0442cbaa4cfa9` |
| M | `app/Services/V3/Authoring/ClaimCodingAttempt.php` | `20715fc28e1e1eb8b4e33d39be70d11d55295a9dc86ed43a4e6fc3ff1a6472d3` |
| M | `app/Services/V3/Authoring/RecordAuthoringResult.php` | `2bf025f93804d7db5da71fac3beafe041270437f7e639c0a02e743a335541e17` |
| M | `app/Services/V3/Authoring/SceneCandidateResolver.php` | `a4c0e8d537422b3e59d5ec93486c95dbee99ebe218a66d3f0e1e363f8b4ec617` |
| M | `app/Services/V3/Evidence/EvidenceProjector.php` | `d2d4b2b5b0b818cf36eb45a69b260715d33d8733ef57549ce28bccca78c3b076` |
| M | `app/Services/V3/Evidence/MetricProjector.php` | `3d94af3dd00324f4c3171bd51f1568379a7b1e689a9e6f5b3e33c9bfb773c5c8` |
| M | `app/Services/V3/NotebookLM/AcceptCombinedLesson.php` | `b1bdd9c99ce8e9ffc709dfc7c01167a34329510b86a8b2b0f7addf91af0c5b7d` |
| M | `app/Services/V3/NotebookLM/CombinedLessonValidator.php` | `276c1bfdc17a03534a6dd995c97efc58914b36196ebf4e46454b27ea24f37472` |
| M | `app/Services/V3/NotebookLM/DirectMapParser.php` | `00451a322b68fb305798aaad1bd7af04cfda611669f6a21ed130e82c98f1912d` |
| M | `app/Services/V3/Prompts/PromptContextReader.php` | `80b8f7ba8a6dc5e33ababd2b87a2577a7f106329576a755875fd1113686a66ce` |
| M | `app/Services/V3/Review/EnsureReviewReady.php` | `9f0b7c50282c260d8dd33c248aef0b659922f44d9dbbea185dcee9a6d91deed8` |
| M | `app/Services/V3/Review/ReviewContext.php` | `59c9afd03ce264bb3c4cbda88a9ed1482b079b4534d03d9cb1ac44f26c0b61b8` |
| M | `app/Services/V3/Review/StartReviewPreview.php` | `409e35d9b3a803b19d11077b0ab5861e24e32f09c56896944617b73901ae1e6d` |
| M | `app/Services/V3/Review/SubmitReviewDecision.php` | `75eb33e04a54f7343b0fa4d0d813d9ab02a709329d0a7581899198519cdd3de4` |
| M | `app/Services/V3/SceneQualification/ClaimSceneCheck.php` | `868e0c0476d4420d10b2cb3dbac2af25b38a3ec32ccacd467fb9687f63af2863` |
| M | `app/Services/V3/SceneQualification/RecordSceneCheck.php` | `4f5ef4b4ead1062b62eec81cdeb15573e7d8ce6f93783ee2a54a83f5159c9d0b` |
| M | `app/Services/V3/SceneQualification/SceneReviewEligibility.php` | `9bf30e5811c6d9576c1faa19f5924fb6658c0bb6a5d817658bdf4bdb0a564b22` |
| M | `app/Services/V3/Settings/SettingsSchema.php` | `57cb95780ecc79c77ec502f7b1a545dc980bd6673a2add62e87cc44d7aa36037` |
| M | `app/Services/V3/Workflow/BridgeRequestSigner.php` | `4c83e6f5619fcc2268d93f0126f57401a92bc4eab08488cb25e8aa65c31dc1a4` |
| M | `config/services.php` | `41ffe751f4806954d48ec8b9dd3ae89d1a9a913af27f35c95f8816226519a4f6` |
| M | `database/migrations/2026_10_02_000010_create_v3_prompt_tables.php` | `d4349f05d07fb128a9096e9e19cd550c0d62e491e66b8c5edc12c0d9ef3320ca` |
| M | `integrations/notebooklm-mcp/src/__tests__/v3-brave.test.ts` | `079eb2ca660e270b00cf9c9d4151bef456e046315b36dbc7f8aa077a0d083669` |
| M | `integrations/notebooklm-mcp/src/browser-auth.ts` | `787d10a8110e708359c59a60efc29e9236ea996f2c3fb1a7a3d04d4541956eb2` |
| M | `resources/views/lectures/show.blade.php` | `d018525ee224e78ef6a7309cdf996f4b873dceb2f7cbfb343646284fe86d56eb` |

*(Showing 50 of 558 dirty files; full ledger cataloged in `specs/019-v3-web-integration/repair-evidence/dirty_files.json`)*
