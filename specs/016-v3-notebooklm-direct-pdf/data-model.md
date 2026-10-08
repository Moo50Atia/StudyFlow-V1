# Data Model: Direct PDF protocol

Apply one **new** migration, `database/migrations/2026_10_07_000016_add_v3_notebooklm_direct_protocol.php`; never edit already-applied migrations. UUIDs are application-generated, strings below use the stated maximum lengths, hashes are lowercase SHA-256 of exact bytes. Foreign keys restrict deletion. Dates are UTC.

## Captured configuration

Extend `v3_processing_cases`:

| Field | Type / rule |
|---|---|
| `pipeline_version` | varchar(32), not null; `legacy_staged_v1` backfill/default or `notebooklm_combined_v1` |
| `notebook_framework_version_id` | nullable UUID FK to framework catalog; required only for direct cases |
| `notebook_policy` | nullable JSON; direct closed object `{adapter_kind:"notebooklm_py",adapter_version:"0.8.4",credential_profile:"v3-local",response_format_version:"combined-envelope-v1",readiness_poll_seconds:10,readiness_attempt_seconds:300,query_timeout_seconds:600,max_answer_bytes:1048576}` |

Capture with selected 005 settings in the existing case-creation transaction, after original actor/document validation. Identical existing idempotency key returns that case even if defaults/framework changed. For a new key, capture current direct framework before inserting. Default switch uses `V3_NOTEBOOK_PROTOCOL`; only the two values above are allowed. Direct without a valid active framework returns 503 `framework_unavailable`, with no case/workflow/provider effect. Do not add these fields to the closed admin DTO. Existing settings technical retry/delay policy still controls read-only/local callback retries.

Create `v3_notebook_framework_versions`: `id` UUID PK; `profile` varchar(32), initially `expanded-root-v1`; `body_path` varchar(255); `body_sha256` char(64) unique; `body_bytes` unsigned bigint; `first_sha256`, `second_sha256`, `third_sha256` char(64); `response_format_sha256` char(64); `approved_by_user_id` FK users; `approved_at`, `created_at` timestamps. Rows/artifacts are immutable. Initial approved hash is in spec.md. The trusted import command requires an active admin plus exact expected hash, writes/verifies private bytes and records approval transactionally; no UI free-text editing. A single `v3_notebook_framework_pointer` row (`id=1`, nullable `version_id` FK) selects future cases, locked after the 005 pointer in intake. Duplicate import of identical bytes returns existing version; conflicting profile/body requires a new version and explicit selection. First/Second/Third digests hash exact slices beginning at each root `FIRST —`, `SECOND —`, `THIRD —` heading through the byte before the next heading, including original whitespace. Do not strip headings or rewrite newlines. The full-file hash remains authoritative.

## Binding and sources

Extend `v3_knowledge_source_bindings`: `source_mode` varchar(24) default `extracted_text`; direct value `original_pdf`; nullable `document_id` UUID FK and `framework_version_id` UUID FK; nullable `response_format_sha256` char(64); `chat_configuration_state` varchar(16) default `pending` (`pending|ready|uncertain|blocked`). Make `extracted_source_id`, `text_sha256`, `text_bytes` nullable. Existing `source_id` stays the legacy single-source field and is **null for direct cases**. Existing `adapter_kind/version` capture direct `notebooklm_py/0.8.4`. Direct requires document/framework FKs and rejects an extraction ref; legacy requires its unchanged extraction/text fields and rejects direct manifest use. Service validation and migration-compatible checks enforce both modes; add database checks where supported and test the deployed database. Do not weaken legacy invariants merely because columns become nullable.

Create `v3_knowledge_source_files`:

| Field | Type / invariant |
|---|---|
| `id`, `binding_id` | UUID PK/FK; binding implies case/owner |
| `role` | varchar(24): `educational_pdf|framework_steps`; unique `(binding_id,role)` |
| `title` | varchar(128): `v3-pdf-{case_uuid}-{sha16}` or exactly `steps.txt` |
| `artifact_sha256`, `artifact_bytes` | char(64), unsigned bigint; verified local upload input, **not provider byte echo proof** |
| `mime_type` | varchar(32): `application/pdf|text/plain` |
| `provider_source_id` | nullable varchar(255); distinct within binding, unique `(binding_id,provider_source_id)` |
| `state` | varchar(16): `pending|processing|ready|uncertain|blocked` |
| `provider_type` | nullable varchar(32), normalized verified `pdf|text`; unknown type blocks acceptance |
| `registered_at`, `ready_at`, `last_checked_at` | nullable timestamps |
| `error_code` | nullable varchar(32), closed contract codes |

`bound` requires confirmed notebook, exactly two ready manifest rows, configured response format and source IDs not shared with another case binding under that adapter. Binding `transfer_status=confirmed` means the verified local file was sent and its corresponding provider source became ready; never assert provider byte equality. Unexpected extra sources make the case conflicted; do not select all sources.

`ActivateSource` invokes `DirectSourceCoordinator.prepare` under the case/control transaction to create the binding shell and two pending manifest rows from captured Document/framework metadata before dispatching the activation signal. It performs no provider call. Thus existing worker context GETs remain read-only and can resolve the shell before the first create claim.

Extend existing source-effect operation allowlist with `notebook_upload_pdf`, `notebook_upload_steps`, `chat_configure` alongside `notebook_create`. Existing `(case_id,operation)` uniqueness and send-count fields suffice. States are `prepared|sending|confirmed|uncertain|blocked`; committed transition to sending authorizes at most one call. `provider_ref` stores the known notebook/source ID if available even on uncertain outcomes. Lease expiry does not authorize a resend. Use existing fields for attempt/check timestamps. `chat_configure` confirms only by reading back exact goal/length/custom prompt.

## Map, queries and cursor

Extend `v3_lesson_maps.coverage_mode` varchar(32) default `verified_text_markers`, direct `not_requested_direct_pdf`. Direct `page_count` comes from accepted Document, `coverage_query_id/coverage_prompt_sha256` and node `coverage_pages` remain null. A valid Divide makes the map `ready`; do not manufacture a Coverage row.

Use `v3_lesson_queries`, adding `binding_id` nullable UUID FK, `expected_cursor_version` nullable unsigned bigint, `source_scope` nullable varchar(16) (`pdf|pdf_and_steps`), `response_format_sha256` nullable char(64), `history_length_before` nullable unsigned integer and `history_anchor_sha256` nullable char(64). Anchor hashes compact JSON array `[previous_question,previous_answer]` in UTF-8/unescaped Unicode; no preceding turn uses null. Legacy nulls remain valid. Direct kinds: `divide` ordinal0, `execution` ordinal0, `lesson_start` ordinal1, `lesson_continue` ordinal2..N. Existing unique `(case_id,kind,target_work_ordinal)` applies. Query state `prepared→sending→recorded|uncertain|blocked`; recovered exact response may change uncertain to recorded. `send_count<=1`; request/conversation/raw-answer evidence uses existing columns.

Use existing cursor. On map commit: `current_work_ordinal=1`, phase `awaiting_execution`, version1. Exact Execution acknowledgement advances to `ready_to_start`, version2. Claim start/Continue changes phase to `processing_bundle` and increments version once; the returned claimed version is the result fence. Successful atomic bundle commit increments again and sets `ready_to_continue` or `complete`; it retains completed work ordinal, final conversation and completion link. A claim for next work W+1 explicitly moves the cursor ordinal while preserving `last_completion_id`. Uncertain/blocked query leaves the claimed ordinal/version and does not permit advancement. Phase becomes `uncertain` or `blocked`; read-only recovery of the **same** turn retains its claimed version and can commit. Do not derive versions with legacy `3*ordinal` arithmetic. `complete` here means all NotebookLM bundles persisted, not overall case approved.

Create `v3_notebook_lesson_bundles`: UUID `id` PK; `case_id`, `query_id`, `work_node_id`, `work_item_id`, `completion_id` UUID FKs; unsigned `work_ordinal`; `framework_version_id` UUID FK; `answer_sha256`, `projection_sha256` char(64); unsigned bigint `committed_cursor_version`; timestamp `created_at`. Unique `query_id`, `(case_id,work_ordinal)`, `completion_id`. Full raw answer stays in query's private artifact. Second preparation is retained in its logical prompt turn's `parsed_mappings` with per-Section exact text, not discarded.

## Projection into existing 009/010 tables

Within the coordinator's transaction create eleven-field `v3_teacher_sections`; work item `state=receipt_committed` and `section_query_id=bundle query`; three logical prompt turns (`execution|second|third`) with new origin `combined_reuse`, `send_count=0`, recorded state. Execution links to global execution query; Second/Third link to bundle query, retain exact sliced answer text privately and parsed mappings. Work item points to these turns. Actual provider sends exist only in lesson-query ledger. New direct origin is never accepted by legacy routes.

Section `first_prompt_sha256` is the captured framework First-slice digest; Section `answer_sha256` is the full raw bundle query digest. For logical turns, request digest is `sha256("016-logical-turn-v1\n" + work_item_id + "\n" + step + "\n" + origin_query_id + "\n" + actual_instruction_sha256)`; Execution instruction digest is unchanged canonical Execution, Second/Third use captured framework slices. Each turn answer digest/path references its exact response slice, with the full original linked through origin query. Section-field digest uses existing `PromptContextReader::computeSectionFieldsSha256` in its eleven-field order. Bundle projection digest hashes compact JSON array of ordered `{ordinal,fields,second_text,third_text,scene_type}` objects, fields in canonical order, UTF-8/unescaped Unicode/slashes, no spaces; it is independent of newly generated UUIDs.

One version1 `v3_scene_prompt_versions` per Section retains `origin=third`, Third-turn FK, exact body digest, scene type and eleven-field digest; `canonical_third_sha256` is the captured framework Third-slice digest **for direct cases**, not the legacy constant. Add nullable `framework_version_id` FK to prompt versions; required direct, null legacy. Existing qualifications/resolvers must validate digest according to the captured protocol. Create matching Section states `awaiting_qualification`. Existing completion records use ordered Section/version refs, real claimed cursor version, final conversation and the unchanged `009-completion-v1` receipt algorithm. Add bundle row and advance cursor only after all artifacts verify. No nested service may independently commit partial First or Third data.

## Transactions, artifacts and retention

Lock order for direct mutations: existing access/control fence where applicable → case → workflow/control rows → binding/source effect → cursor → query → work node → work item/child rows. Framework selection locks follow the existing intake settings-pointer order; never invert intake locks. No provider/network/file-stream call inside a DB transaction. Stage immutable answer/slice/prompt artifacts on `v3_private`, verify digest/size before referencing them; failed DB commit leaves only an orphan. Purge unreferenced files after24h only if no active/uncertain claim references them. Keep all referenced/unresolved evidence under 015 pilot retention; this spec adds no provider delete operation. Exact duplicate result must match answer, final conversation and projection digest, not just raw text.
