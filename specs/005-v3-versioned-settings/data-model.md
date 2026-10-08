# Data Model: V3 Versioned Settings

Migration: `database/migrations/2026_10_01_000005_create_v3_settings_tables.php`, after 004's `000004` migration. Use existing configured relational connection; SQLite memory is the test default. Do not add a ProcessingCase table here.

## `v3_approved_templates`

Model `App\Models\V3\ApprovedTemplate`, explicit table. `id` string(64) primary key (`[a-z][a-z0-9_-]{2,63}`); `kind` string(16) non-null enum in application (`feedback`, `scene`); `body` longText non-null, approved UTF-8 bytes of length 1–65536; `checksum` char(64) lowercase SHA-256 of exact body bytes; `registered_at` timestamp non-null. No updated timestamp, owner ID, editable status or HTTP mutation. Trusted local command inserts once after verifying caller-supplied digest; application model rejects update/delete. Migration seeds no body. An ID is never reused for different bytes; references remain valid for old cases. Settings publish verifies both selected kind and digest in the same transaction. Internal lookup verifies bytes again and fails closed on missing/corrupt entry. The catalog is retained for pilot history.

## `v3_settings_versions`

Model `App\Models\V3\SettingsVersion`, explicit table name. Columns: `id` unsigned bigint primary key; `version` unsigned bigint unique, starting at 1; `schema_version` unsigned small integer fixed at 1; `payload` JSON non-null containing exactly the canonical [settings v1](contracts/settings.md) payload; `checksum` char(64) non-null lowercase SHA-256 of canonical UTF-8 JSON bytes; `published_by_user_id` unsigned bigint not null FK to `users.id` with restrict deletion; `published_at` timestamp not null. Index `(published_at,id)`. No `updated_at` or mutable status. Canonicalization recursively sorts object keys, preserves provider-order array order, normalizes integers, and encodes without whitespace using PHP `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`. Checksum is not unique: publishing identical rules is still a new version. Only `PublishSettings` inserts; application model rejects save on an existing row and delete. No edit/delete route or bulk update in application code. DB operators are outside the application-role boundary.

## `v3_active_settings`

One seeded row: `key` string(16) primary key with only value `pilot`; `active_settings_version_id` nullable unsigned bigint FK to versions with restrict deletion; `revision` unsigned bigint not null initially 0; `updated_at` timestamp not null. Migration inserts this one row, but no active version. No other key, tenant ID or settings copy. Both publication and 006 case selection increment `revision` as their first settings-table write inside a transaction; the counter is a lock token, not a settings version. A rollback restores its previous value. Missing singleton is `SettingsUnavailable`, never silently recreated on a request.

## `v3_settings_publications`

Append-only publication audit. `id` UUID primary key; `settings_version_id` unsigned bigint unique FK restrict; `publisher_user_id` unsigned bigint FK restrict; `previous_version_id` nullable unsigned bigint FK restrict; `changed_paths` JSON non-null array of sorted leaf paths, no values; `published_at` timestamp not null. Every successful version has exactly one event within the same transaction. No request body, IP, original content, arbitrary metadata or credential value columns. Publication failure writes no event. 004's access audit remains the authority for access denials and is not repurposed to hold settings payloads.

## Relationships, integrity and retention

`v3_active_settings.active_settings_version_id` points to a real version or null before first publish. Successful publish moves it to the row whose `version` is one above the previous active version; previous versions/events remain. `published_by_user_id` is the current eligible admin at commit-time transaction check. Prior version rows, selected template rows and publication events are retained for the pilot; 005 has no purge or deletion endpoint. 015 may define evidence retention later but cannot remove a version/template still referenced by a case. There is no credential table: `credential_ref` is a restricted label in JSON. Runtime secret values live in deployment configuration, never DB. Log only fixed event codes, version ID and request correlation, not payload/secret/template body.

## 006-owned relationship

006 adds `processing_cases.settings_version_id` as a non-null restrictive FK to `v3_settings_versions.id`, immutable after insert; exactly one captured version per case. This column is not created in 005. 006 calls `SelectCaseSettings` inside the same transaction as its case insert, then tests duplicate idempotency keys return the existing case with its existing version rather than selecting a new one. 007/010–013 load the captured row by ID; no retry/review action reads `v3_active_settings`.
