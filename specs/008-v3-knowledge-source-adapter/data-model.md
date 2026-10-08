# Data Model: 008 Source Activation, Extraction and Binding

All tables use the 006 Laravel connection and restrict deletes while a case exists. IDs are server-created UUIDs. Original ownership comes only from `v3_processing_cases.owner_user_id`; no mutable second owner column exists. `case_id` is unique in each table, so `process_again` has an independent source run. Never store source text, PDF bytes, MCP answers, cookie/session material or private paths in an admin projection or Temporal memo.

## `v3_source_activations` — `App\Models\V3\SourceActivation`

| Column | Type/constraint | Meaning |
|---|---|---|
| `id` | char(36) PK, unique | Stable `activation_id` sent to the 007 workflow |
| `case_id` | char(36) unique FK `v3_processing_cases.id` restrict | One activation per case |
| `workflow_id` | varchar(64) not null | Must equal `v3-case-{case_id}` and existing 007 link |
| `state` | varchar(24) not null | `pending`, `signaling`, `uncertain`, `accepted`, `blocked` |
| `lease_until`, `signal_attempted_at`, `signal_accepted_at`, `next_check_at` | nullable timestamps | CAS claim and uncertain signal reconciliation |
| `signal_attempts` | unsigned integer default 0 | Number of actual bridge signal POSTs |
| `error_code` | nullable varchar(32) | 004 safe code only |
| `last_alert_code`, `last_alerted_at` | nullable varchar(32), nullable timestamp | Suppress repeated warning for 24 hours |
| `created_at`, `updated_at` | timestamps | Audit/recovery |

An accepted signal is not proof that work executed. Re-sending the same ID is safe at the workflow handler; never make a second activation ID. A `blocked` row cannot be retried with a new ID in 008. A stale lease is reclaimed by the minute reconciler. Only a verified matching 007 workflow/link may be signaled.

Allowed activation transitions are `pending→signaling`, `signaling→accepted|uncertain`, `uncertain→signaling|accepted`, and `pending|signaling|uncertain|accepted→blocked` only on a deterministic terminal source failure. The scheduler may re-claim an expired signaling lease and resend the **same** ID; an `accepted` row is not signaled again. No transition creates a new case/workflow ID. The source-status view derives later `extracting|ingesting|bound` from 007 events and extraction/binding rows, not extra activation states.

## `v3_extracted_sources` — `App\Models\V3\ExtractedSource`

| Column | Type/constraint | Meaning |
|---|---|---|
| `id` | char(36) PK | Opaque `extracted_source_ref` |
| `case_id` | char(36) unique FK case restrict | One immutable extraction per case |
| `activation_id` | char(36) unique FK activation restrict | Proven worker handoff |
| `document_id` | char(36) not null | Equals immutable 006 case Document; same-owner relation verified by 006 reader |
| `settings_version_id` | unsigned bigint FK captured 005 version restrict | No active-pointer substitution |
| `status` | varchar(16) | `ready` or `blocked` |
| `artifact_path` | varchar(255) nullable | `extracted/{case_id}/{digest}.txt` on 006 `v3_private`; null if no text |
| `text_sha256` | char(64) nullable | Digest of canonical UTF-8 artifact bytes |
| `text_bytes` | unsigned bigint not null default 0 | Exact artifact byte count, at most 1,048,576 |
| `page_count`, `ocr_page_count` | unsigned integers | Page total equals 006 Document count; OCR count ≤ captured limit |
| `page_report` | JSON not null | Ordered page 1..N `{page,method:"text"|"ocr"|"none",chars,ocr_confidence:null|decimal_0_to_100,gap_code:null|"unreadable"|"visual_unrepresented"|"structure_unrepresented"|"parser_error"|"size_limit"}`; confidence displayed to one decimal, no text |
| `error_code` | nullable varchar(32) | 004 safe `validation|provider_unavailable|internal` only |
| `extractor_version` | varchar(64) not null | Pinned Python extractor build/version |
| `created_at`, `updated_at` | timestamps | Immutable evidence time |

For `ready`, every page has a nonempty textual representation, no gap, `artifact_path`/digest exist, and the private file verifies on every read. For `blocked`, no NotebookLM send is permitted; page report explains gaps to the owner without returning text to admin. Canonical text is `[PAGE 1/N]\n...\n[/PAGE 1/N]\n` through N, UTF-8 NFC, LF line endings; an original source line matching `^\[/?PAGE [0-9]+/[0-9]+\]$` is prefixed with `\` before wrapping so it cannot impersonate a marker. Page text retains reading order and headings/table/equation text. No truncation: over 1 MiB blocks before persistence/transfer with safe `validation`; the private report records a fixed `size_limit` diagnostic. A repeated identical POST returns the row; changed digest/report/activation is 409. Write the file atomically before row commit and purge unreferenced files older than 24 hours; a missing/mismatched referenced file blocks use and raises safe `internal`.

## `v3_knowledge_source_bindings` — `App\Models\V3\KnowledgeSourceBinding`

| Column | Type/constraint | Meaning |
|---|---|---|
| `id` | char(36) PK | Opaque `binding_ref` for 009 |
| `case_id` | char(36) unique FK case restrict | One notebook/source pair per case |
| `extracted_source_id` | char(36) unique FK extraction restrict | Exact ready source lineage |
| `adapter_kind`, `adapter_version` | varchar(24), varchar(64) | `notebooklm_mcp`, pinned package version |
| `notebook_title`, `source_title` | varchar(128) | Deterministic opaque titles; no teacher or filename |
| `notebook_id`, `source_id` | nullable varchar(255) | Private provider IDs; non-null only as each is proven |
| `state` | varchar(24) | `pending_notebook`, `notebook_uncertain`, `notebook_ready`, `add_uncertain`, `verifying_source`, `bound`, `blocked` |
| `text_sha256`, `text_bytes` | char(64), unsigned bigint | Exact extracted content intended for transfer |
| `transfer_status` | varchar(16) | `not_sent`, `uncertain`, `confirmed` |
| `send_attempted_at`, `confirmed_at`, `last_checked_at` | nullable timestamps | Attempted external transfer and proof time; attempted does not assert delivery |
| `error_code` | nullable varchar(32) | 004 safe code only |
| `last_alert_code`, `last_alerted_at` | nullable varchar(32), nullable timestamp | Suppress repeated uncertain/schema/artifact warning for 24 hours |
| `created_at`, `updated_at` | timestamps | Case-local evidence |

Unique nullable `(adapter_kind,notebook_id)` and `(adapter_kind,source_id)` prevent accidental reuse; verify case scope before provider lookup. `bound` requires ready extraction, proven notebook/source IDs, exact content verification, `transfer_status=confirmed`, `confirmed_at`. A title is an opaque deterministic marker based on case UUID and digest; title alone is never proof of ownership or content. Provider IDs never enter 007 callback, teacher/admin HTTP or Temporal input/output.

Allowed binding transitions are `pending_notebook→notebook_uncertain|notebook_ready|blocked`, `notebook_uncertain→notebook_ready|blocked`, `notebook_ready→add_uncertain|verifying_source|blocked`, `add_uncertain→verifying_source|bound|blocked`, and `verifying_source→add_uncertain|bound|blocked`. A read-only inventory failure keeps the same state and updates `last_checked_at`; `bound` is immutable and `blocked` requires a fixed terminal reason. No state permits a second create/add send. `send_attempted_at` is set with the add claim; `confirmed_at` only with exact provider content proof.

## `v3_source_effects` — `App\Models\V3\SourceEffect`

| Column | Type/constraint | Meaning |
|---|---|---|
| `id` | char(36) PK | Effect ID |
| `case_id` | char(36) FK case restrict | Case scope |
| `operation` | varchar(24) | `notebook_create` or `notebook_add_text` |
| `request_digest` | char(64) | Digest of canonical safe fields plus content digest, not raw content |
| `state` | varchar(16) | `sending`, `uncertain`, `confirmed`, `blocked` |
| `send_count` | unsigned integer default 0 | Maximum 1 for each non-idempotent operation |
| `provider_ref` | nullable varchar(255) | Notebook/source ID after proof |
| `started_at`, `last_checked_at`, `confirmed_at` | nullable timestamps | Reconciliation timeline |
| `error_code` | nullable varchar(32) | 004 safe code |
| `created_at`, `updated_at` | timestamps | Immutable effect evidence and reconciliation update time |

Unique `(case_id,operation)`. Commit `sending` and `send_count=1` before the MCP call. A crash between ledger commit and call is uncertain; do not resend automatically because whether the call occurred cannot be proved. Reconcile `notebook_create` with `notebook_list`/`notebook_get` and add with `notebook_get`/`source_get_content`. Zero or multiple matches after an uncertain call is an operator stop, not authorization to send again. One `bound` binding and confirmed transfer are committed with effect confirmation under a case-row transaction; duplicate workers read that result.

An effect follows `sending→uncertain|confirmed|blocked` and `uncertain→confirmed|blocked` only. A late `uncertain` result after confirmation is a stale 409 and never regresses the binding/transfer. Enforce `send_count≤1` with a DB check where supported and a locked service guard on every database; test both. `confirmed` requires a non-null provider ref and proof, while `blocked` requires a safe `error_code`. No direct effect-row update endpoint exists outside the HMAC service operation.

## Retention and access

008 has no delete route. Keep the private artifact, page report, activation, binding and effect rows for pilot evidence while 006 case and 007 history exist; 015 may later set a concrete expiry preserving active/unresolved rows. An owner-facing source-status route returns only page numbers/method/gap codes and safe state, never private IDs/text. All internal endpoints require 007 worker HMAC before lookup and use case → activation → Document/settings checks. No admin endpoint exposes these tables.
