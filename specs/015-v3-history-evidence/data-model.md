# Data Model: 015 Evidence Projection and Export Snapshots

## Existing authoritative records

015 adds **no** `LineageEdge` or `UsageRecord` table. A second edge/usage ledger would duplicate 006–014 immutable FKs and could disagree with an approved Section pointer. The projector verifies the existing identities at read time. The [record inventory](contracts/evidence.md) is the exact source list; if implementation differs, stop and reconcile the prerequisite rather than fabricate an edge. No V2 `sections`, `lectures`, `dynamic_views` or `TeacherPermission` relationship enters this graph.

The projection is a value object, not an Eloquent model: `EvidenceGraphV1 {case_ref,as_of,integrity:"complete"|"incomplete",nodes:[EvidenceNodeV1],links:[EvidenceLinkV1],gaps:[EvidenceGapV1],current_approval_refs:[UUID],historical_approval_refs:[UUID]}`. Nodes carry `kind`, opaque `ref`, fixed `state`, UTC `recorded_at` and `content_state:"available"|"missing_or_corrupt"|"not_applicable"`; owner responses may add owner-route `content_ref` only where an existing authorized route can serve that type. Links carry exact `from_kind/from_ref/to_kind/to_ref/relation`; relation comes from an actual FK or immutable pointer, not temporal proximity. Gaps carry fixed `missing_row|parent_mismatch|missing_file|digest_mismatch|approval_chain_invalid|invalid_time|count_mismatch` and the nearest already-authorized parent ref. Never return a path, hash, provider conversation ID, teacher identity or raw error. A missing file is not an intentional-deletion tombstone.

`MetricReportV1` is also derived. Its facts carry `{value:null|nonnegative_number,unit,provenance:"provider_reported"|"derived"|"unknown",source_kind,source_ref,definition}` for usage/time and `{amount_minor:null|nonnegative_integer,currency:null|ISO_4217,status:"actual"|"estimated"|"unknown",basis:null|fixed_string,source_kind,source_ref}` for money. If source rows disagree or the exact raw response digest fails, status is unknown and the report records a fixed gap. Known subtotals sum nonnull amounts **per currency**; mixed currencies never auto-convert. `full_cost` is null unless *every* charge-bearing operation in the case (008/009 NotebookLM, 010/012 JEV, 010/013 feedback/correction, 011 coding, 012 checker infrastructure and human review labor) has an explicit same-currency cost or an approved versioned estimation basis. A claim marked sent/uncertain counts as a possible charge; an operation conclusively never sent does not. Report unknown-component count, not a fake zero. No active estimate basis exists under `pilot-retain-v1`.

## New `v3_evidence_exports` — `App\Models\V3\EvidenceExport`

Create in `database/migrations/2026_10_02_000015_create_v3_evidence_exports.php` after all 014 migrations. IDs are server-created UUID `char(36)` and all FKs restrict deletion. The row is insert-only except `file_removed_at`, set once by expiry cleanup; no mutable scope, owner, digest or expiry. Use guarded model fields, no mass assignment from request.

| Column | Type/constraint | Meaning |
|---|---|---|
| `id` | char(36) PK | Opaque export ref |
| `actor_user_id` | unsigned bigint FK `users.id` restrict | Requesting teacher/admin; download bound to this exact actor |
| `actor_role` | varchar(8) `teacher|admin` | Captured role; current role rechecked on replay/download |
| `idempotency_key` | char(36) not null | UUID, unique `(actor_user_id,idempotency_key)` |
| `request_sha256` | char(64) lowercase hex | Canonical validated role/scope/case/approval/period body digest |
| `scope` | varchar(16) `owner_case|owner_approval|admin_period` | Selects one closed serializer |
| `case_id` | char(36) nullable FK case restrict | Required for owner scope, null for admin |
| `approval_id` | char(36) nullable FK `v3_approved_scenes.id` restrict | Only owner-approval scope; same case checked transactionally |
| `period_start`,`period_end` | nullable UTC timestamps | Only admin scope; half-open `[start,end)` |
| `serializer_version` | varchar(24) fixed `evidence-json-v1` | Immutable format |
| `as_of` | UTC timestamp not null | DB snapshot timestamp, also report cutoff |
| `snapshot_path` | varchar(255) not null | Internal `evidence-exports/{actor_user_id}/{id}/{sha256}.json` on `v3_private`; never in response |
| `snapshot_sha256` | char(64) lowercase hex not null | SHA-256 of exact serialized bytes |
| `snapshot_bytes` | unsigned integer 1..10485760 | 10 MiB maximum |
| `expires_at` | UTC timestamp not null | `created_at + 15 minutes` |
| `file_removed_at` | nullable UTC timestamp | Expiry cleanup observation only |
| `created_at` | UTC timestamp not null | Generation commit time |

Check constraints/service guards enforce exactly one scope shape. Owner export's case owner must equal `actor_user_id` at generation; same immutable owner and fresh 004 access are rechecked on download. An admin export never stores a case or approval target. A replay under the same actor/key with changed digest is 409; across actors keys are independent. A snapshot has no public/signed storage URL. Digest, path and file size are verified before any bytes are returned. If 10 MiB is exceeded, return fixed 413 `evidence_too_large` with no row; the owner may export one approval chain, and an admin may reduce the period. No silent truncation.

## New `v3_evidence_export_audits` — `App\Models\V3\EvidenceExportAudit`

`id` UUID PK; `export_id` UUID FK restrict; `actor_user_id` bigint FK; `event` `generated|download_attempt`; `occurred_at` UTC timestamp; `result_code` `ready|stream_started|expired|file_unavailable`; `snapshot_sha256` char(64) only for `generated`; unique `(export_id,event)` only for `generated` (enforce with a service or partial unique index where supported). A generation audit inserts atomically with the export. A download audit records the decision before the response streams; `stream_started` never means the client received all bytes. Exact create replay emits no second `generated` event. Denied role/owner requests use 004's existing denial audit and create no export audit. Never record raw URL, query, request body, filename, teacher content, hash of source/scene/note, private path or IP here.

## Snapshot, concurrency and cleanup

Use one stable transactional DB snapshot; require production DB repeatable-read behavior and prove it in the integration gate. For owner scope lock the case row before traversal to serialize with prerequisite case-locked mutations; use `as_of` for event cutoffs. For admin period, take one repeatable-read snapshot, filter cases by `created_at ∈ [start,end)` and include facts committed in that snapshot through `as_of`. Generate canonical UTF-8 JSON with sorted field keys and deterministic `created_at,id` row ordering; serialize only closed DTOs. Stage a mode-0600 private file, fsync, hash, exclusive rename, then insert export plus `generated` audit and commit. No partial file is served. A failed DB commit leaves an unreferenced file; cleanup removes it after 24 hours only after DB reference lookup. Concurrent same-key requests serialize on `(actor_user_id,idempotency_key)`; losing request returns committed same-digest row or 409, never a second snapshot. Do not keep a DB transaction open across a provider/bridge call.

`php artisan v3:purge-expired-evidence-exports` runs hourly. It removes only a verified 015 export path at least 24 hours after `expires_at`, after checking row/path/hash and that no active generation references it; it sets `file_removed_at` once. Failure leaves the row/file for retry and emits a fixed safe alert. It never traverses or deletes 006–014 artifact paths. Do not add a 014 assessment consumer that purges educational bytes under this policy.
