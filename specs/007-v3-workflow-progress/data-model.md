# Data Model: 007 Workflow Link and Safe Case Events

Use the same Laravel connection and existing 006 `v3_processing_cases` table. IDs are server-created UUIDs except the deterministic Temporal workflow ID. Create these two tables after 004–006 migrations. Foreign keys restrict deletion; no cascade removes pilot evidence. Do not create `ReviewSignal`, Candidate, Section, scene, notebook or provider tables in 007.

## `v3_workflow_links` — `App\Models\V3\WorkflowLink`

| Column | Type/constraint | Meaning |
|---|---|---|
| `case_id` | char(36) PK, FK to `v3_processing_cases.id` restrict | Exactly one link per case; link creation precedes remote start |
| `workflow_id` | varchar(64) unique not null | Exact `v3-case-{case_id}`; immutable |
| `run_id` | varchar(64) nullable | Temporal first run ID once confirmed; immutable after set |
| `settings_version_id` | unsigned bigint not null FK `v3_settings_versions.id` restrict | Copy of 006 captured ID for bridge/memo comparison; must equal case row, never active pointer |
| `settings_checksum` | char(64) nullable | Verified captured row digest; null only before first valid start preparation, immutable after set |
| `start_state` | varchar(24) not null | `pending`, `starting`, `uncertain`, `started`, `closed`, `identity_conflict`, `history_missing` |
| `stage_code` | varchar(32) not null default `intake` | 004 safe stage; `extraction` after first callback |
| `status_code` | varchar(24) not null default `queued` | 004 safe status; 007 worker route permits `queued|running|blocked|failed` |
| `error_code` | varchar(32) nullable | 004 safe error; no raw exception |
| `attempt_count`, `scene_count`, `approved_scene_count` | unsigned integers not null default 0 | Counts of persisted stage attempts/scenes/approvals; zero before producers exist, never a forecast |
| `start_attempts` | unsigned integer not null default 0 | Number of actual bridge POST attempts, internal only |
| `lease_until`, `next_retry_at`, `start_attempted_at`, `started_at`, `last_reconciled_at`, `last_event_at` | nullable timestamps | Claim, retry, uncertain-start, reconciliation and delay evidence |
| `last_alert_code`, `last_alerted_at` | nullable varchar(32), nullable timestamp | Suppress same-condition operational warning for 24 hours; changed code emits immediately |
| `created_at`, `updated_at` | timestamps not null | Operational evidence |

Indexes `(start_state,next_retry_at)`, `(lease_until,start_state)`, `(status_code,last_event_at)`. A database check or service guard enforces the deterministic `workflow_id`; service rejects changes to case/settings/workflow/run once assigned. `start_state=started` may precede the first `workflow_started` callback, so case can remain `awaiting_workflow` while link is started. `start_state=closed` never authorizes a replacement run. `identity_conflict` or `history_missing` is a manual-resolution stop, not a new ID. The owner is read through the linked 006 case; no second mutable owner column exists.

## `v3_case_events` — `App\Models\V3\CaseEvent`

| Column | Type/constraint | Meaning |
|---|---|---|
| `id` | char(36) PK | Server-created event ID |
| `case_id` | char(36) not null FK to case restrict | Owner inherited through case |
| `event_key` | varchar(128) not null | Stable worker idempotency key; unique `(case_id,event_key)` |
| `payload_digest` | char(64) lowercase hex not null | Hash of normalized allowlisted event fields; same-key conflict proof |
| `sequence` | unsigned bigint not null | Equals case `state_version` after commit; unique `(case_id,sequence)` |
| `workflow_id`, `run_id` | varchar(64) not null | Confirmed Temporal identity, no external content |
| `event_type` | varchar(32) not null | 007 codes in [contract](contracts/workflow.md) |
| `stage_code`, `status_code`, `error_code` | varchar(32), varchar(24), nullable varchar(32) | 004 allowlists only |
| `recorded_at` | timestamp not null | Laravel server time; worker timestamp is not authoritative |

Index `(case_id,sequence)` serves ordered owner history. No update/delete route or model mutator for committed event fields. Compute `payload_digest` over these normalized fields in fixed order separated by a single LF with null error represented by `-`: `schema_version,event_key,case_id,workflow_id,run_id,expected_state_version,event_type,stage_code,status_code,error_code`. Each field's grammar excludes LF. Hash the UTF-8 bytes with SHA-256. An exact duplicate request therefore returns the original event even if its expected version is now stale; a reused key with changed content is 409. Reconciler-created terminal events use the same service and deterministic key.

## Case projection and invariants

006 keeps immutable `owner_user_id`, `document_id`, `settings_version_id`, `action`, `idempotency_key`. 007 changes only `state`, `state_version`, `updated_at` in the case transaction that inserts an event and updates its link. Initial 006 version is 1; there is deliberately no sequence-1 event. First `workflow_started` event has `sequence=2`. Each later accepted event increments by exactly one, with no gap after 2 or duplicate. No event exists merely because a bridge start returned 200. For a case with no link, teacher/admin status is derived as `intake/queued/null` and counts zero; the 006 case row remains the durable source of existence. For a link with no event, the same initial projection applies. The count columns stay zero in 007 because WorkerEventV1 contains no attempt/scene count operation; later producer specs must version the event/projection contract before updating them.

`CaseEvent` is safe operational evidence, not full content or review history. 013 persists the real private review decision and its own durable signal delivery; 014 adds audited commands; 015 owns export/retention rules. 007 retains link/event rows for the pilot with no deletion endpoint. A later retention job may not remove an event referenced by active case state or an unresolved Temporal handoff.
