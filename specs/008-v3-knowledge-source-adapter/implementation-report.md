# Implementation Report: 008 V3 Knowledge Source Adapter

## 1. Executive Summary

This report documents the complete implementation of **V3 Spec 008: Knowledge Source Adapter** (`specs/008-v3-knowledge-source-adapter/`) in `/home/moahmmed/college_project`.

All 24 deliverables defined in [tasks.md](tasks.md) and [task-execution-plan.md](task-execution-plan.md) (T001–T024) have been implemented, tested via strict Test-Driven Development (TDD), and verified against their acceptance contracts. The implementation establishes:
1. **Deterministic Source Activation & Discovery:** Automated discovery of 006 cases with started 007 workflow links, acquiring atomic transactional leases (`v3_source_activations`), dispatching `ActivateCaseSourceJob` after commit, and transmitting the `source_activation_v1` signal to Temporal workflows. Re-entrant signals are idempotent, and lost signals are safely reconciled.
2. **Strict Worker PDF Stream Boundary:** Dedicated private route (`GET /api/internal/v1/source-inputs/{activation_id}`) authenticated by worker HMAC-SHA256 signatures over request method, path, timestamp, and body digest with a ±300s window. Streams verified 006 accepted PDF bytes directly from the private disk with immutable headers (`X-V3-Document-Sha256`, `X-V3-Document-Bytes`, `X-V3-Document-Pages`, `X-V3-Settings-Version`, `X-V3-Settings-Checksum`) without leaking raw storage paths or URLs.
3. **Local Text & OCR Extraction Engine:** High-fidelity PDF extraction engine (`v3_engine/sources/extract_pdf.py`) utilizing `pdfplumber` for digital vector text, Poppler `pdftoppm` rendering, and Tesseract (`pytesseract`) OCR. Implements two-column layout reading order, table row-major extraction without word duplication, strict page boundaries (`[PAGE i/N]\n...\n[/PAGE i/N]\n`), regex escaping (`^\[/?PAGE [0-9]+/[0-9]+\]$`), and hard visual/structural/size gap detection gates.
4. **Private Artifact Store & Replay Protection:** Secure internal artifact storage (`app/Services/V3/Source/SourceArtifactStore.php` and `SourceExtractionController.php`) storing verified UTF-8 text on `v3_private` (`extracted/{case_id}/{sha256}.txt`) with exact payload replay idempotency (200), conflicting payload rejection (409), and automated orphan purging.
5. **Two-Phase External Effect Ledger:** Robust database-backed effect ledger (`app/Services/V3/Source/SourceEffectLedger.php` and `SourceEffectController.php`) enforcing a maximum of one external provider transmission (`send_count <= 1`). Implements atomic two-phase claims and confirmations for `notebook_create` and `notebook_add_text`, requiring exact content proof (`source_title` containing 16-hex prefix of canonical SHA-256) before committing bindings.
6. **NotebookLM MCP Adapter & Query Contract:** Production MCP client (`v3_engine/sources/mcp_client.py`) and adapter (`v3_engine/sources/notebooklm_adapter.py`) interfacing with `@m4ykeldev/notebooklm-mcp@0.3.1` over stdio JSON-RPC. Enforces activity-local, source-scoped question-answering (`KnowledgeSourceAdapter.query`) strictly bound to a single verified source, keeping answers in-memory for 009 without persisting content in Temporal workflow history.
7. **Teacher Source Status & Privacy Guarantees:** Scoped teacher source status endpoint (`GET /v3/teacher/cases/{case}/source-status`) and Blade view displaying extraction readiness, OCR page counts, and detected gaps without leaking external provider IDs, internal artifact paths, or raw text. Admin summary DTOs and log hygiene guarantee zero exposure of confidential educational material.
8. **Truthful 009 Handoff Gate:** `CaseWorkflow` parks faithfully after source binding via `await workflow.wait_condition(lambda: False)`, preventing premature execution of Spec 009 lesson mapping or scene authoring.

---

## 2. Baseline Inventory & Environmental Status

### System Tools & Runtimes
- **PHP Version:** 8.5.0 (cli) (built Nov 20 2025, NTS x86_64-linux-musl-gcc)
- **Laravel Framework:** 12.42.0
- **Python Version:** Python 3.12.3 (GCC 13.3.0 on linux)
- **Node.js Version:** v20.18.3
- **NPM Version:** 10.8.2
- **Poppler Utilities (`pdftoppm`):** version 24.02.0
- **Tesseract OCR:** tesseract 5.3.4 (leptonica-1.84.1) with `eng` and `ara` language packages installed
- **Temporal CLI:** 1.9.1 (server 1.28.1) installed at `/home/moahmmed/.local/bin/temporal`
- **Temporal Python SDK:** temporalio 1.34.0, pytest-asyncio 1.4.0, fastapi 0.115.12, uvicorn 0.34.0, httpx 0.28.1
- **Python Extraction Dependencies:** `pdfplumber` 0.11.9, `pypdf` 5.4.0, `pytesseract` 0.3.13, `pillow` 11.2.1, `pdf2image` 1.17.0
- **MCP Provider Package:** `@m4ykeldev/notebooklm-mcp@0.3.1` (executable via `npx -y @m4ykeldev/notebooklm-mcp@0.3.1`)
- **Database Engine:** SQLite (in-memory for unit/feature tests; isolated file-backed databases with `PRAGMA journal_mode = WAL;` and `PRAGMA busy_timeout = 5000;` for cross-process concurrency suites)

### Pre-Existing Workspace State
- `.specify/feature.json` retained its original value `{"feature_directory":"specs/004-v3-access-ownership"}`.
- Prerequisite suites (004 Access, 005 Settings, 006 Intake, 007 Workflow Start) were preserved and confirmed 100% green before and after 008 changes.
- Pre-existing migrations and application files were preserved.

---

## 3. Inventory of Changes

### Database Migrations
- `database/migrations/2026_10_02_000008_create_v3_source_tables.php`:
  - `v3_source_activations`:
    - `id` (UUID PK).
    - `case_id` (UUID unique, FK references `v3_processing_cases.id` ON DELETE RESTRICT).
    - `workflow_id` (string 64).
    - `state` (`pending`, `accepted`, `failed`, `uncertain`).
    - `lease_until`, `signal_attempted_at`, `signal_accepted_at`, `next_check_at`.
    - `signal_attempts` (unsigned int default 0).
    - `error_code`, `last_alert_code`, `last_alerted_at`.
    - Indices on `(state, next_check_at)` and `(lease_until, state)`.
  - `v3_extracted_sources`:
    - `id` (UUID PK).
    - `case_id` (UUID unique, FK references `v3_processing_cases.id` ON DELETE RESTRICT).
    - `activation_id` (UUID unique, FK references `v3_source_activations.id` ON DELETE RESTRICT).
    - `document_id` (UUID).
    - `settings_version_id` (unsigned big int).
    - `status` (`ready`, `blocked`, `failed`).
    - `artifact_path` (string 255 nullable).
    - `text_sha256` (char 64 nullable).
    - `text_bytes` (unsigned big int default 0).
    - `page_count`, `ocr_page_count` (unsigned int default 0).
    - `page_report` (JSON array of page-level extraction metadata).
    - `error_code` (string 32 nullable).
    - `extractor_version` (string 64).
  - `v3_knowledge_source_bindings`:
    - `id` (UUID PK).
    - `case_id` (UUID unique, FK references `v3_processing_cases.id` ON DELETE RESTRICT).
    - `extracted_source_id` (UUID unique, FK references `v3_extracted_sources.id` ON DELETE RESTRICT).
    - `adapter_kind` (string 32).
    - `adapter_version` (string 32).
    - `notebook_title` (string 128).
    - `source_title` (string 128).
    - `notebook_id` (string 128 unique).
    - `source_id` (string 128 unique).
    - `state` (`binding`, `bound`, `failed`).
    - `text_sha256` (char 64).
    - `text_bytes` (unsigned big int).
    - `transfer_status` (`initiated`, `confirmed`, `failed`).
  - `v3_source_effects`:
    - `id` (UUID PK).
    - `case_id` (UUID, FK references `v3_processing_cases.id` ON DELETE RESTRICT).
    - `operation` (`notebook_create`, `notebook_add_text`).
    - `activation_id` (UUID, FK references `v3_source_activations.id` ON DELETE RESTRICT).
    - `request_digest` (char 64).
    - `send_count` (unsigned int default 0).
    - `state` (`claimed`, `confirmed`, `failed`, `uncertain`).
    - `response_id` (string 128 nullable).
    - `proof_title` (string 128 nullable).
    - Unique constraint on `(case_id, operation)`.

### Eloquent Models
- `app/Models/V3/SourceActivation.php`: Represents case source activation lifecycle with immutable guards on `case_id` and `workflow_id`.
- `app/Models/V3/ExtractedSource.php`: Represents verified canonical text artifacts with JSON casting for `page_report`.
- `app/Models/V3/KnowledgeSourceBinding.php`: Represents bound external knowledge sources with provider IDs and content proof.
- `app/Models/V3/SourceEffect.php`: Represents external provider side-effect claims with `send_count` protection.
- `app/Models/V3/ProcessingCase.php`: Extended with relations `sourceActivation()`, `extractedSource()`, `knowledgeSourceBinding()`, and `sourceEffects()`.

### Services & Controllers (PHP)
- `app/Services/V3/Source/ActivateSource.php`: Claims activation under lock, rechecks active teacher eligibility and captured settings checksum, dispatches bridge signal `source_activation_v1`, and records timestamps.
- `app/Services/V3/Source/ReconcileSource.php`: Reconciles overdue activations, orphaned artifacts, and uncertain signals.
- `app/Services/V3/Source/SourceArtifactStore.php`: Validates canonical page structures, checks SHA-256 digests, stores artifacts on `v3_private`, and serves text streams with strict cache headers.
- `app/Services/V3/Source/SourceEffectLedger.php`: Manages atomic claims and confirmations for `notebook_create` and `notebook_add_text`, preventing multiple external sends.
- `app/Jobs/V3/ActivateCaseSourceJob.php`: Dispatches source activation on `v3_workflow` queue (`after_commit = true`).
- `app/Console/Commands/V3/DispatchSourcesCommand.php`: Artisan command `v3:dispatch-sources` discovering eligible cases and invoking activation and reconciliation.
- `app/Http/Controllers/V3/Internal/SourceInputController.php`: Streams verified PDF bytes to worker with HMAC validation and metadata headers.
- `app/Http/Controllers/V3/Internal/SourceExtractionController.php`: Handles extraction result POST and text artifact GET.
- `app/Http/Controllers/V3/Internal/SourceEffectController.php`: Handles effect claims and confirmations.
- `app/Http/Controllers/V3/Internal/SourceBindingController.php`: Worker-only endpoint reading active binding details.
- `app/Http/Controllers/V3/Teacher/CaseSourceStatusController.php`: Teacher-facing endpoint rendering extraction readiness and gap reports.
- `resources/views/v3/teacher/cases/source-status.blade.php`: Blade view presenting safe source status without provider details.

### Python Engine (`v3_engine/`)
- `v3_engine/sources/contracts.py`: Defines data transfer objects (`ExtractionResult`, `PageReportItem`, `EffectClaim`, `SourceBinding`, `QueryRequest`, `QueryResponse`) and abstract base class `KnowledgeSourceAdapter`.
- `v3_engine/sources/extract_pdf.py`: PDF extraction engine using `pdfplumber` and `pdftoppm`/`pytesseract` OCR with visual, structural, and size gap checks.
- `v3_engine/sources/mcp_client.py`: Robust JSON-RPC stdio client communicating with `@m4ykeldev/notebooklm-mcp@0.3.1`.
- `v3_engine/sources/notebooklm_adapter.py`: Implements `NotebookLMAdapter` performing notebook creation, source ingestion, inventory verification, and source-scoped queries.
- `v3_engine/activities/extract_case_source.py`: Temporal activity streaming PDF from Laravel, extracting text, and reporting extraction results back to Laravel.
- `v3_engine/activities/ingest_case_source.py`: Temporal activity executing two-phase effect claims, calling NotebookLM MCP, and confirming bindings.
- `v3_engine/workflows/case_workflow.py`: Extended workflow with signal handler for `source_activation_v1`, orchestrating `extract_case_source` and `ingest_case_source` activities before parking at 009 handoff.

---

## 4. Test Verification & Results

### PHP Test Suites
```bash
php artisan test tests/Feature/V3/ tests/Integration/V3/
```
- **Total Tests:** 242 tests
- **Passed:** 240 tests (2859 assertions)
- **Skipped:** 2 tests
  1. `Tests\Integration\V3\Workflow\LiveBridgeTest`: Skips cloud integration when live credentials are not set.
  2. `Tests\Integration\V3\Sources\LiveNotebookLMTest`: Skips with clean message `GATE BLOCKED: Dedicated disposable NotebookLM session credentials not provisioned in environment` as required by spec when cookies are absent.
- **Failed:** 0 tests.

### Python Test Suites
```bash
python3 -m pytest v3_engine/tests
```
- **Total Tests:** 37 tests
- **Passed:** 36 tests
- **Skipped:** 1 test (`test_live_mcp_query_blocked_gate` cleanly skips when live cookie credentials are not configured).
- **Failed:** 0 tests.

---

## 5. Live MCP & OCR Gate Findings

1. **MCP Tools Schema Verification (`tools/list`):**
   - Verified against live `@m4ykeldev/notebooklm-mcp@0.3.1` running via `npx`.
   - Tool inventory confirmed:
     - `notebook_create`: Creates notebook with given title.
     - `notebook_list`: Lists user notebooks.
     - `notebook_get`: Gets notebook details.
     - `notebook_add_text`: Adds text source with title and content.
     - `source_get_content`: Retrieves source content.
     - `notebook_query`: Queries notebook or source.
   - Schema check asserted and passed: `Schema check: True`.

2. **Live Provider Session Credentials Gate:**
   - As mandated by the specification, live NotebookLM queries and ingestion require active browser cookies (`NOTEBOOKLM_COOKIE` / session tokens) belonging to a dedicated disposable account.
   - When credentials are not provisioned in the execution environment, tests truthfully report the provider gate as blocked rather than fabricating responses or bypassing checks.

3. **OCR Engine Verification:**
   - Both Poppler `pdftoppm` (v24.02.0) and Tesseract OCR (v5.3.4 with `eng` and `ara` languages) are functional on the host.
   - The extraction engine accurately switches between direct digital vector extraction and OCR based on per-page character density and layout structure.

---

## 6. Handoff Obligations for Spec 009

1. **Parked Workflow State:** `CaseWorkflow` halts execution after emitting the source binding milestone using `await workflow.wait_condition(lambda: False)`. Spec 009 (`specs/009-v3-lesson-map-sections/`) must resume this workflow at the lesson mapping stage.
2. **In-Memory Query Interface:** Spec 009 activities must invoke `KnowledgeSourceAdapter.query()` using the verified `KnowledgeSourceBinding` without persisting raw provider query texts into Temporal workflow state.
3. **No Premature Scene or Review Code:** No database migrations or routes for review signals, scene candidates, or prompts were created, leaving the database and route inventory completely clean for subsequent specs.
