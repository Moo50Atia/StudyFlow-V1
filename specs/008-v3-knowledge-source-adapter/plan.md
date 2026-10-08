# Implementation Plan: 008 Knowledge Source Adapter

**Reviewed:** 2026-10-02 · [spec](spec.md) · [contract](contracts/knowledge-source-adapter.md) · [model](data-model.md) · [matrix](contracts/acceptance-matrix.md)

## Repository fit and entry

Implement 004–007 first. The inspected Laravel 12/PHP ^8.2 checkout has no V3 models/routes/migrations, `v3_engine`, Temporal SDK or selected MCP installation. 006 promises `AcceptedPdfReader::openForCase` and private `v3_private` disk; 007 promises `CaseWorkflow`, HMAC bridge/callback middleware, `v3_workflow` queue and signed safe event projector. 008 extends these surfaces rather than duplicating identity, storage or workflow start. Existing V2 `Interactive-Seens-Material/Generating/Extraction/pdf_extractor.py` and `OCR/ocr_processor.py` use pdfplumber/Tesseract concepts but accept arbitrary paths, save adjacent files and log raw exceptions; use as read-only reference, not production import. `pdftoppm` is on PATH in this checkout; Tesseract, Temporal and `notebooklm-mcp` are absent.

## File and service inventory

| Layer | Exact deliverables |
|---|---|
| Laravel schema/models | `database/migrations/2026_10_02_000008_create_v3_source_tables.php`, `app/Models/V3/{SourceActivation,ExtractedSource,KnowledgeSourceBinding,SourceEffect}.php` per [model](data-model.md). |
| Laravel orchestration | `app/Console/Commands/V3/DispatchSourcesCommand.php`, `app/Jobs/V3/ActivateCaseSourceJob.php`, `app/Services/V3/Source/{ActivateSource,ReconcileSource,SourceArtifactStore,SourceEffectLedger}.php`; append schedule to `routes/console.php`, use 007 after-commit queue. |
| Laravel internal boundary | Append HMAC routes to 007 `routes/api.php`; `app/Http/Controllers/V3/Internal/{SourceInputController,SourceExtractionController,SourceEffectController,SourceBindingController}.php`; reuse 007 signature middleware and 006 accepted reader, never a browser session. |
| Teacher route | Append `GET /v3/teacher/cases/{case}/source-status` to 004/007 `routes/v3.php`; `app/Http/Controllers/V3/Teacher/CaseSourceStatusController.php`, `resources/views/v3/teacher/cases/source-status.blade.php`; link from 007 progress page. |
| Python workflow/extraction | Extend `v3_engine/bridge.py`, `v3_engine/workflows/case_workflow.py`, `v3_engine/worker.py`; add `v3_engine/activities/{extract_case_source,ingest_case_source}.py`, `v3_engine/sources/{contracts,extract_pdf,notebooklm_adapter,mcp_client}.py`; pin Python package and OCR/MCP versions in `v3_engine/pyproject.toml`. |
| Tests | `tests/Feature/V3/Sources/*.php`, `tests/Integration/V3/Sources/*.php`, `v3_engine/tests/sources/*.py` and disposable PDF fixtures as named in [matrix](contracts/acceptance-matrix.md). |

## Ordered architecture

1. Laravel's minute discovery locks eligible 007 cases, verifies 005 captured settings and same-owner 006 Document, creates one activation row and queues the signal after commit. The signed 007 bridge gets `source_activation_v1`; the 008 signal handler accepts its case/activation once. A timeout resends the same ID only. Replay compatibility is proven against a 007 history before rollout.
2. Python activity calls the signed Laravel PDF stream. Laravel uses 006's verified reader, not a storage path or teacher download URL. Python spools to mode-0600 temporary storage, validates size/hash/pages, extracts local text/structure and optional OCR under captured limits, then posts the complete text and page report to Laravel. The artifact store writes atomically and the DB row is the authoritative ready state. Unreadable/visual gaps produce a blocked row/event before MCP.
3. For ready extraction, Python calls the Laravel effect ledger before each non-idempotent MCP operation. The ledger commits one send claim. Create/add use no automatic second send; ambiguous outcomes invoke read-only inventory/content reconciliation. A successful add message is not a confirmed binding. Read-only MCP calls use the captured 007 retry helper.
4. Laravel commits a confirmed case-local binding and transfer proof after source ID/content verification. The workflow emits only 007-safe extraction/sourcing milestones and parks for 009. `KnowledgeSourceAdapter.query` is activity-local, source-scoped and returns its raw answer only to a 009-owned private persistence caller; 008 does not run mapping.
5. Teacher source-status joins by case with 004 active original-owner scope. Admin stays on the 004 closed DTO. Internal HMAC endpoints and legacy route inventory are tested as actual security boundaries. Logs and Temporal memo/events carry safe IDs/codes only.

## Concurrency, failure and evidence

Use `v3_source_activations.case_id` uniqueness, the 007 fixed workflow ID, two-minute CAS lease and after-commit dispatch; do not hold a DB transaction over Temporal/MCP/stream I/O. One `v3_source_effects(case_id,operation)` row with `send_count≤1` is the external side-effect guard. Crash after claim but before tool call remains uncertain because the ledger cannot prove whether the call happened. `notebook_list`/`notebook_get` or `source_get_content` must prove the same opaque title and exact content; zero/multiple results never permit a blind resend. DB/file partial failure is handled by atomic rename, immutable row, verified reads and orphan cleanup. A provider outage leaves the case running/delayed with private uncertainty; a deterministic extraction gap uses 008's `source_blocked` extension with safe `validation`.

The 008 proof gate requires independent-process activation/effect races, actual signed routes and 004 owner denial, a live 007 history replayed by the 008 worker, real Poppler/Tesseract fixtures, and an installed pinned MCP against a disposable account. A fake MCP is necessary for lost-ack fault injection but does not establish real source IDs, NotebookLM content round-trip or query grounding. If installed `source_get_content` normalizes content so exact proof is impossible, stop the live completion claim and report the capability mismatch; do not turn a success message into proof.

## Stop boundaries

Do not implement 009 mapping, canonical prompts or Section tables; 010 JEV, 011–012 scenes, 013 review, 014 admin commands, 015 export or future KOS/KIE. Never make `file_path` MCP ingestion or raw PDF upload a fallback. No owner transfer or active-settings substitution. If 004–007, private TLS/loopback transport, Tesseract/MCP credentials, or live Temporal replay cannot be exercised, report the specific mandatory gate rather than claim the feature complete.
