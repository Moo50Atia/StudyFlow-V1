# Spec 019 Contract Decisions & Drift Resolution (T118)

Date: 2026-10-12
Governing Specifications: `specs/019-v3-web-integration/contracts/*.md`, `specs/019-v3-web-integration/data-model.md`, `specs/019-v3-web-integration/repair-acceptance-v2.md`

## 1. Route Inventory & Service Matrix

The registered Laravel routing table provides 80 routes under the `v3` namespace across four functional families:

### 1.1 Teacher Website Routes (UI & Form Actions)
- `GET /v3/teacher`: Workspace entry dashboard (`v3.teacher.workspace`).
- `GET /v3/teacher/subjects/{subject}/runs/create`: Intake form for ordering and uploading 1–20 PDFs (`v3.teacher.subject-runs.create`).
- `POST /v3/teacher/subjects/{subject}/runs`: Submit PDFs for durable admission (`v3.teacher.subject-runs.store`).
- `GET /v3/teacher/subject-runs/{run}`: Run progress and status dashboard (`v3.teacher.subject-runs.show`).
- `POST /v3/teacher/subject-runs/{run}/commands`: Run control mutations (pause, resume, cancel, retry) (`v3.teacher.subject-runs.commands.store`).
- `GET /v3/teacher/subject-runs/{run}/events`: Run event stream / audit query (`v3.teacher.subject-runs.events.index`).
- `POST /v3/teacher/subject-runs/{run}/generation-mode`: Update generation mode (`v3.teacher.subject-runs.generation-mode.store`).
- `GET /v3/teacher/subject-runs/{run}/prompt-export`: Scoped and whole-run prompt exports (`v3.teacher.subject-runs.prompt-export`).
- `GET /v3/teacher/subject-runs/{run}/scenes`: Scene management and candidate listing (`v3.teacher.subject-runs.scenes.index`).
- `POST /v3/teacher/subject-runs/{run}/scenes/{scene}/authoring-request`: Explicit scene API authoring dispatch (`v3.teacher.subject-runs.scenes.authoring-request.store`).
- `POST /v3/teacher/subject-runs/{run}/scenes/{scene}/imports`: Manual 3-file scene package upload (`v3.teacher.subject-runs.scenes.imports.store`).
- `GET /v3/teacher/cases/{case}/reviews/{review}`: Teacher scene review & preview page (`v3.teacher.cases.reviews.show`).
- `POST /v3/teacher/cases/{case}/reviews/{review}/decisions`: Submit review decision (approve / request_changes) (`v3.teacher.cases.reviews.decisions.store`).
- `POST /v3/teacher/cases/{case}/reviews/{review}/viewer-sessions`: Start isolated viewer session (`v3.teacher.cases.reviews.viewer.store`).
- `GET /v3/teacher/cases/{case}/reviews/{review}/viewer-sessions/{viewer}`: Render preview frame HTML (`v3.teacher.cases.reviews.viewer.show`).
- `GET /v3/teacher/cases/{case}/reviews/{review}/viewer-sessions/{viewer}/frames/{frame_no}`: Fetch captured rendered first frame (`v3.teacher.cases.reviews.viewer.frame`).
- `POST /v3/teacher/cases/{case}/reviews/{review}/viewer-sessions/{viewer}/actions`: Record user interaction on preview scene (`v3.teacher.cases.reviews.viewer.action`).
- `DELETE /v3/teacher/cases/{case}/reviews/{review}/viewer-sessions/{viewer}`: Close viewer session (`v3.teacher.cases.reviews.viewer.close`).
- `GET /v3/teacher/cases/{case}/evidence`: Lineage evidence projection (`v3.teacher.evidence.show`).
- `GET /v3/teacher/cases/{case}/evidence-exports/{export}/download`: Download evidence export (`v3.teacher.evidence-exports.download`).
- `POST /v3/teacher/cases/{case}/approved-scenes/{approval}/corrections`: Submit post-approval visual or factual correction (`v3.teacher.cases.approvals.corrections.store`).

### 1.2 Admin Telemetry Routes
- `GET /v3/admin`: Admin workspace (`v3.admin.workspace`).
- `GET /v3/admin/subject-runs`: Aggregated subject run index (`v3.admin.subject-runs.index`).
- `GET /v3/admin/subject-runs/{run}/summary`: Operational summary without leaking student/teacher content (`v3.admin.subject-runs.summary`).
- `POST /v3/admin/subject-runs/{run}/commands`: Admin emergency run controls (`v3.admin.subject-runs.commands.store`).
- `GET /v3/admin/operations/health`: Orchestration queue health check (`v3.admin.operations.health`).
- `GET /v3/admin/settings`: Global provider settings inspection (`v3.admin.settings.show`).

### 1.3 Internal Signed Pipeline Routes (`/api/v3/internal/web-pipeline/*`)
- `POST claims`: Worker lease claims with signature verification.
- `POST results`: Callback for NotebookLM turn / bundle completion.
- `POST events`: Durable pipeline event ingestion.
- `GET manifests/{ref}`: Retrieve immutable input / output manifest.
- `GET items/{item}/input`: Retrieve PDF binary / hash for worker intake.
- `POST items/{item}/archive`: Durable item archive callback.
- `POST items/{item}/reset-verified`: Reset verification fence before next PDF.
- `POST imports/verify`: Terminal archive pre-flight verification.
- `POST imports/materialize`: Execute historical import materialization.

### 1.4 Canonical Lecture & Legacy Owner Routes
- `GET /lectures/{lecture}`: Teacher lecture view (`lectures.show`) — extended to show imported and newly created V3 lectures and sections.
- `GET /subjects/{subject}`: Subject dashboard with lecture links and V3 run history.

---

## 2. Static Scene Package Format

Per `contracts/scene-and-review.md`:
1. **Schema Version**: `schema_version: 1`.
2. **Files Array**: Exactly three files must be present:
   - `index.html` (text/html)
   - `styles.css` (text/css)
   - `script.js` (application/javascript)
3. **Validation Constraints**:
   - Decoded size per file: `<= 1,048,576 bytes` (1 MiB).
   - Total package payload size: `<= 4,194,304 bytes` (4 MiB).
   - No path traversal (`../`), no subdirectories, no NUL bytes, valid UTF-8.
   - Extra files (e.g. `preview.png`, `README.md`) are strictly rejected.
4. **Internal Package Manifest (ScenePackageV2)**:
   - Generated by the application during intake; never uploaded directly by users.
   - Profile: `static_three_file_v1`.

---

## 3. Generation Mode & Aliases

To resolve contract drift across earlier documentation and tests:
- **`export_manual`**:
  - The default generation mode for all new runs.
  - Automatically exports structured prompt JSON/text for manual scene construction.
  - Makes **zero** scene generation API requests (`external_generation_sends = 0`).
- **`api_generate`**:
  - The canonical mode for automated candidate generation.
  - Generates exactly one candidate for an explicitly selected prompt version.
- **`selected_api`**:
  - Maintained as an explicit backwards-compatibility alias for `api_generate`.
  - When received via the UI or API (`generation-mode`), it is normalized to `api_generate`.
  - Both names are supported in tests and controllers.

---

## 4. Provider Adapters & Immutable Settings

The system supports four contracted authoring providers:
1. **Google Gemini (`gemini`)**:
   - Endpoint: `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`
   - Default Thinking Budget: `24576` (configurable 0–24576, never forced to 0).
   - Header: `x-goog-api-key`.
2. **Z.AI GLM (`glm`)**:
   - Endpoint: `https://open.bigmodel.cn/api/paas/v4/chat/completions`
   - Model: `glm-4-plus` (or captured setting).
   - Header: `Authorization: Bearer {api_key}`.
3. **DeepSeek (`deepseek`)**:
   - Endpoint: `https://api.deepseek.com/chat/completions`
   - Model: `deepseek-coder` / `deepseek-chat`.
   - Header: `Authorization: Bearer {api_key}`.
4. **Alibaba Qwen (`qwen` / Laravel identifier `qwen_api`)**:
   - Endpoint: `https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions`
   - Model: `qwen-plus` / `qwen-turbo` / `qwen-max`.
   - Header: `Authorization: Bearer {api_key}`.
   - **Status**: Required by `contracts/scene-and-review.md`. Must be implemented in `v3_engine/authoring/providers.py` and covered with wire tests.

**Immutability Guarantee**: Provider, model, template, endpoint, and thinking budget settings chosen at dispatch time are captured in the command payload and remain immutable throughout execution. No silent fallback to another model or provider is permitted.

---

## 5. Intake Limits & Validation

1. **Batch Size**: 1 to 20 PDF files per subject run. Runs with 0 or >20 PDFs are rejected with validation error 422.
2. **File Size Limits**:
   - Max 32 MiB per single PDF.
   - Max 100 MiB total batch upload size.
3. **MIME Verification**: Strictly `application/pdf` with valid PDF magic bytes (`%PDF-`).
4. **Authentication & Authorization**: Only the owning teacher of the subject can initiate a run. Admins and foreign teachers receive 403 Forbidden. CSRF verification is mandatory on all web form submissions.

---

## 6. Canonical Eleven First Fields (`TeacherSection`)

The eleven pedagogical fields defined in Spec 016/019 data models:
1. `concept_title` (string)
2. `duration_minutes` (integer)
3. `teaching_strategy` (string)
4. `pedagogical_purpose` (string)
5. `student_activity` (string)
6. `key_takeaways` (string/array)
7. `scripture_reference` (string)
8. `theological_insight` (string)
9. `discussion_questions` (string/array)
10. `practical_application` (string)
11. `assessment_check` (string)

### Invariants:
- **Missing-Aware Handling**: In historical imports or incomplete parses, missing fields MUST be preserved as `NULL`. Empty strings `""` are treated as distinct from `NULL` for hashing and context hashing.
- **No LLM Backfill**: Missing pedagogical fields must never be hallucinated or filled with generic placeholder text.

---

## 7. Historical Provenance vs Live Records

1. **Source Origin**: Historical runs are recorded with `source_origin = 'terminal_import'`. Live website runs use `source_origin = 'web_upload'`.
2. **Foreign Key Integrity**: Historical imports do NOT synthesize live NotebookLM conversation IDs, live JEV qualification passes, or fake Stripe/cost ledger entries. Unavailable live attributes are nullable in the schema.
3. **Dry-Run Guarantee**: Dry-run invocations of the import command make zero database writes, zero file copies, zero Temporal workflow dispatches, and zero external API calls.
