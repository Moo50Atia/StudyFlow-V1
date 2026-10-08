# Data Model: 006 Intake, Documents and Cases

Create the tables in order Document → UploadAttempt → ProcessingCase so composite owner FKs resolve. All tables use the application's existing relational connection (SQLite memory in routine tests, production database per deployment). IDs are server-created UUID strings (`char(36)`); `owner_user_id` is the original teacher's `users.id`, never an HTTP input. Foreign keys restrict deletion. Use explicit model table names, casts and non-mass-assignable owner/state/path fields. Models implement 004 `V3OwnedResource` through `HasV3Owner`; register production policies in `AppServiceProvider`. Database operators remain outside the application-role boundary.

## `v3_upload_attempts` — `App\Models\V3\UploadAttempt`

| Column | Type/constraint | Purpose |
|---|---|---|
| `id` | char(36) PK | Opaque poll identifier |
| `owner_user_id` | unsigned bigint not null FK `users.id` restrict | Original teacher |
| `upload_settings_version_id` | unsigned bigint nullable FK `v3_settings_versions.id` restrict | Active 005 version selected before reading bytes; null only for `settings_unavailable` block |
| `document_id` | char(36) nullable | Accepted Document; composite FK `(document_id,owner_user_id)` to Document `(id,owner_user_id)` |
| `state` | varchar(24) not null | `receiving`, `quarantined`, `validating`, `retry_pending`, `promoting`, `cleanup_pending`, `accepted`, `rejected`, `blocked` |
| `reason_code` | varchar(32) nullable | Allowlist in [intake](contracts/intake.md); no raw tool output |
| `cleanup_target` | varchar(8) nullable | `rejected` or `blocked` only during `cleanup_pending` |
| `staging_path` | varchar(128) nullable | Internal relative `quarantine/{id}.pdf`; never returned |
| `byte_size` | unsigned bigint nullable | Fully received bytes; no client-supplied size trusted |
| `scan_attempts` | unsigned smallint not null default 0 | Number of actual tool-validation tries, incremented at claim |
| `technical_failures` | unsigned smallint not null default 0 | Retryable inspection/promotion failures; 1–3 schedule retries, 4 blocks after cleanup |
| `duplicate` | boolean nullable | Set only on accepted state |
| `next_retry_at`, `lease_until`, `expires_at`, `finished_at` | nullable timestamps except `expires_at` not null | Retry, 5-minute claim lease, 24-hour quarantine deadline, terminal time |
| `created_at`, `updated_at` | timestamps not null | Safe operational evidence |

Indexes `(owner_user_id,created_at,id)`, `(state,next_retry_at)`, `(state,lease_until)`, `(expires_at,state)`; add unique `(id,owner_user_id)` if required for composite references. No original filename, declared MIME, malware signature, parser output, extracted text or request body is persisted. `staging_path` must be null once terminal cleanup/acceptance succeeds. Attempt transitions follow the contract and are enforced by a service, never arbitrary model saves. A terminal row is retained for pilot evidence.

## `v3_documents` — `App\Models\V3\Document`

| Column | Type/constraint | Purpose |
|---|---|---|
| `id` | char(36) PK | Opaque public document identifier |
| `owner_user_id` | unsigned bigint not null FK `users.id` restrict | Original teacher |
| `sha256` | char(64) lowercase hex not null | Internal exact-byte digest |
| `byte_size` | unsigned bigint not null, positive | Verified file size |
| `page_count` | unsigned smallint not null, positive | qpdf count at acceptance |
| `state` | varchar(16) not null | `pending_move` or `ready` |
| `storage_path` | varchar(128) not null | `accepted/{id}.pdf`, internal only |
| `accepted_at` | timestamp nullable | Non-null only when ready |
| `created_at`, `updated_at` | timestamps not null | Recovery evidence |

Unique `(owner_user_id,sha256)` and `(id,owner_user_id)`; index `(owner_user_id,state,id)`. The digest and storage path never appear in teacher/admin JSON. A pending row is not accessible for download/case start. After ready, digest/size/pages/path/owner cannot be changed by application code. One document per same-owner exact digest; across owners both row and file are distinct. `accepted_at` marks verified promotion, not initial staging.

## `v3_processing_cases` — `App\Models\V3\ProcessingCase`

| Column | Type/constraint | Purpose |
|---|---|---|
| `id` | char(36) PK | Opaque run identifier |
| `owner_user_id` | unsigned bigint not null FK `users.id` restrict | Original teacher, immutable |
| `document_id` | char(36) not null | Composite FK `(document_id,owner_user_id)` to Document `(id,owner_user_id)` restrict |
| `settings_version_id` | unsigned bigint not null FK `v3_settings_versions.id` restrict | Immutable 005 version captured in insertion transaction |
| `idempotency_key` | char(36) not null | Client-generated UUID, scoped to owner |
| `action` | varchar(16) not null | `process` or `process_again` |
| `state` | varchar(32) not null | Initial `awaiting_workflow`; 007 extends later |
| `state_version` | unsigned bigint not null default 1 | Initial transition version; 007 owns later increments |
| `created_at`, `updated_at` | timestamps not null | Receipt/ordering |

Unique `(owner_user_id,idempotency_key)` and indexes `(owner_user_id,document_id,created_at,id)`, `(state,created_at,id)` for 007 discovery. Do **not** add unique `(owner_user_id,document_id)` because deliberate `process_again` creates multiple cases. Under the locked Document row, an initial `process` checks absence of any existing case. Document, owner, action, idempotency key and settings never change after insertion. 006 does not insert a workflow ID, stage event, source or scene. 007 can extend state and add its own link/event tables without migrating away the 006 identity.

## Cross-row/file invariants

- `accepted` attempt implies a same-owner `ready` Document and no staging file; `duplicate` is true for a reused same-owner Document and false for a newly promoted one.
- `ready` implies the private file exists and matches `byte_size` and `sha256` at promotion; reader rechecks before use. `pending_move` is never served.
- `cleanup_pending` retains the quarantine path only until deletion succeeds. An expired quarantine is blocked and removed when storage is available; failed deletion stays `cleanup_pending` with a fixed overdue alert until it succeeds; an unreferenced accepted file older than 24 hours is an orphan and is removed, but a referenced ready accepted file is retained.
- Each new case points to a ready, same-owner Document and one committed 005 settings row. Its `settings_version_id` is the result of `SelectCaseSettings` in that very transaction; an idempotent replay returns its stored version.
- No cascade deletes from users, settings or ready documents. A stranded `pending_move` Document with no verified source/destination and no accepted attempt/case is deleted under its row lock before blocking the promoting attempt; this releases the owner/hash unique key for a fresh upload. 015 may later define longer-term retention; 006 has no teacher/admin deletion API.
