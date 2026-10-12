# Data Model, Invariants and States

All new UUIDs are opaque UUIDv4 unless deterministic import IDs are specified. UUID references use char36 compatible with existing V3 tables. Timestamps use UTC. Digests are lowercase64-character SHA-256 of exact bytes or canonical JSON (sorted keys, UTF-8, compact separators, no ASCII escaping). Foreign keys restrict deletion except existing assessed deletion cascades. Ownership comes from active application identity, never imported provider metadata.

## New aggregate tables — migration000019

### v3_subject_runs / SubjectRun

Fields: id UUID PK; owner_user_id FK users; subject_id FK subjects; origin `web|terminal_import`; protocol `notebooklm_per_pdf_web_v1`; settings_version_id bigint FK v3_settings_versions.id; framework_version_id UUID FK v3_notebook_framework_versions.id; template_version_id nullable varchar64 FK v3_approved_templates.id (required for API generation); mode `export_manual|api_generate`; qualification_mode `real_jev|owner_waived_demo`; captured_settings_json text; capture_sha256; submission_key UUID; request_sha256; state varchar40; state_version integer>=1; workflow_id nullable varchar200; temporal_run_id nullable varchar200; namespace varchar100 default v3-terminal; generation_phase varchar40; total_pdfs integer; total_lessons/total_scenes nullable integer; counters_json text; last_event_sequence integer default0; last_updated_at; created_at; finished_at nullable.

Unique(owner_user_id,submission_key); unique(id,owner_user_id); index(subject_id,owner_user_id,created_at). Capture includes ordered immutable input refs, account alias/proof ref, absolute private root identifier, provider/model/endpoint/credential alias (never credential values), timeout, framework/template hashes, Gemini thinking/output settings and policy version. Secret/proof remote IDs are in private config artifact, not DB operational JSON. Mode/settings cannot be edited in-place. New generation selections create immutable commands under this run.

### v3_subject_run_items / SubjectRunItem

Fields: id UUID; run_id FK; owner_user_id FK; pdf_ordinal integer1..20; display_title varchar255 private content; upload_attempt_id nullable FK; document_id nullable FK; lecture_id nullable FK lectures unique; case_id nullable FK processing cases unique; original_filename varchar255 private; input_sha256 nullable; input_bytes nullable; accepted_input_ref nullable; state varchar40; outcome nullable `completed|failed|blocked|cancelled`; safe_code nullable; work_count nullable; saved_lesson_count integer0; prompt_count integer0; archive_ref nullable; archive_sha256 nullable; state_version integer>=1; created_at; updated_at. Unique(run_id,pdf_ordinal), composite FK(run_id,owner_user_id), composite document/case ownership FKs where existing keys permit. Rejected attempts retain null document/case/lecture. Do not reuse one lecture for different PDFs or overwrite a manual lecture.

A new accepted document may reuse the same owner's existing document hash. Reprocessing creates a new case and lecture item; ownership of identical other-owner bytes remains separate. Same bytes twice inside a submitted batch return422 duplicate_batch_pdf before admission. Later explicit process-again is a new submission.

### v3_notebook_instances / NotebookInstance

id UUID PK; run_id unique FK; owner_user_id FK; adapter_kind notebooklm_py; provider_notebook_id nullable varchar200; private_lineage_ref; credential_ref opaque; account_alias mo50atiapro; state `pending|verified|created|blocked|closed`; created_at; updated_at. Unique(adapter_kind,provider_notebook_id) for nonnull actual notebook. Parent ownership must equal run owner. No provider ID in admin/Temporal projections.

Extend knowledge-source binding with notebook_instance_id nullable FK, subject_run_item_id nullable unique FK. New bindings: extracted_source_id=NULL, old notebook_id=NULL, own PDF source_id retained, parent required. Keep legacy notebook/source uniqueness and legacy resolver behavior. Shared framework source is in each binding's role manifest, not reused as its primary source_id.

### v3_pipeline_commands

id UUID PK (client command ID); run_id FK; actor_user_id FK; action enum `submit|pause|resume|cancel|generate|retry|manual_import|review_effect|import`; request_sha256; expected_version integer; private_payload_ref; state `pending|accepted|applied|rejected|uncertain`; safe_code nullable; result_ref nullable; created_at; accepted_at nullable; applied_at nullable. Unique(run_id,id). Digest covers action, run, expected_version, sorted selected local scene IDs, retry phase, consent and captured settings hash. Exact duplicate returns original receipt regardless of current version; changed duplicate conflicts before version check. Review effect uses existing decision identity as stable command source.

### v3_pipeline_outbox and v3_pipeline_events

Outbox: id UUID; command_id FK unique; destination `application_bridge`; state `pending|dispatching|acknowledged|consumed|failed`; attempt_count; lease_token nullable UUID; lease_expires_at nullable; next_attempt_at; safe_code; created_at/updated_at. Transport acceptance only sets acknowledged. Application effect consumption verified by exact command/decision receipt sets consumed; recover acknowledgment uncertainty by querying same identity.

Event: id UUID; run_id FK; sequence integer; operation_id nullable UUID; kind/phase/outcome/safe_code bounded enum strings; attempt integer; state_version integer; counts_json; occurred_at; historical boolean defaultfalse; artifact_ref nullable opaque. Unique(run_id,sequence), unique(run_id,id). Sequence assigned inside run-version transaction, monotonic across replay. Historical import events reference retained prior metadata and do not claim fresh remote calls. Counts include PDFs accepted/failed, lessons saved, prompts saved, sources generated, runnable scenes, checked scenes, real-qualified scenes, demo-review-ready scenes, reviews pending and approved separately.

### v3_terminal_imports

id UUID PK (import-id); actor_admin_id FK; owner_user_id FK; subject_id FK; source_run_id UUID; archive_sha256; destination_run_id FK; request_sha256; state `validated|queued|importing|partial|completed|failed`; committed_item_count; private_manifest_ref; created_at/updated_at. Unique(id). Item receipt: import_id+pdf_ordinal PK, request_sha256, destination_item_id, committed_at. IDs for item/case/lesson/section/prompt/import events are UUIDv5(namespace=import UUID, stable role/PDF/local ordinal). Transaction retries reuse them. Changing destination or archive for an import-id returns import_conflict.

## Projection/lineage extensions — migration000020

Add `v3_lecture_pipeline_links`: lecture_id PK FK; item_id unique FK; owner_user_id; protocol. Add `v3_section_pipeline_links`: section_id legacy integer PK FK; teacher_section_id UUID unique FK; item_id FK; owner_user_id. Section.title uses source identity/title; other legacy educational columns remain NULL. Views use canonical TeacherSection, never copy eleven fields into six legacy columns. Do not create a DynamicViewModel from an unreviewed candidate.

Extend ProcessingCase with subject_run_item_id nullable unique FK. New creation bypasses legacy job dispatch using explicit application workflow ownership flag in captured protocol, not an environment override.

Extend TeacherSection with origin `pipeline|terminal_import` default pipeline, projection_status `complete|incomplete`, missing_fields_json default[], raw_bundle_ref nullable, subject_run_item_id nullable. The eleven existing fields become nullable in additive table rebuild preserving indices/FKs/data; old complete-source service requires nonnull valid fields. New path allows NULL only when that exact field name appears in missing_fields_json. `laws_if_any=""` is a supplied permitted value, distinct from missing NULL. Context digest uses fixed ordered eleven keys with exact strings or JSON null; never coerce null to empty. Real qualification refuses incomplete required context with `context_incomplete`.

### Exact imported/live projection provenance

Add source_origin `live|terminal_import` default live and private_evidence_ref nullable UUID to v3_lesson_maps, v3_lesson_queries, v3_prompt_work_items, v3_prompt_turns and v3_notebook_lesson_bundles. For live019 records origin remains live with actual operation receipt. Imported records require private_evidence_ref to the verified original map/turn/bundle; no new dispatch is claimed.

LessonQuery.request_sha256 becomes nullable only for terminal_import when the original archive does not retain request bytes/hash. send_count=0 for imported recording; original remote counts/timestamps stay in private historical evidence. answer_path/hash/bytes use exact retained answer and recorded_at is actual import. Kind retains divide/first/query distinctions from current query ledger; one case per PDF preserves unique(case,kind,target_work_ordinal). Map activation workflow_id refers actual new import workflow, state recorded imported; no legacy activation signal is sent. Map.divide_query_id references actual imported Divide evidence query, coverage_query_id remains NULL if no coverage call occurred. page_count comes from verified accepted PDF inspection, not a guess.

PromptTurn.origin adds terminal_import; conversation_in becomes nullable for imported records only if absent in verified original evidence, never a fabricated ID. canonical_sha256 references the actual captured framework step slice. Imported Second/Third turns may share the original combined answer evidence without inventing separate sends (send_count0), with origin_query_id linking the imported combined lesson query. PromptWorkItem.section_query_id/TeacherSection.query_id reference that real historical-evidence query row. No need to null these required relationship FKs. Work item records all actual section refs, expected_cursor_version and committed completion from verified lesson ordinal.

ScenePromptVersion.origin adds terminal_import; private_evidence_ref nullable UUID links actual Third extraction; prompt/body hashes remain exact; canonical_third_sha256 uses captured framework Third slice. Existing fields/state/pointers remain except new-path PromptSectionState awaiting_manual for export mode or awaiting_qualification for real mode. Owner demo gates make prompts usable only through EligiblePromptResolver, never QualifiedPromptResolver.

NotebookLessonBundle.query_id/work_item_id remain required and point to imported evidence-backed query/work item. Retain raw_answer, First/Second/Third bodies, warnings and PDF/lesson identities in private_evidence_ref. Imported timestamps/digests and work count are verified before commit; all per-PDF cursor/bundle uniqueness stays case+local work ordinal. Remote conversation ID alone is never the identity. Cases with incomplete First fields still have explicit section anchors for every extracted Third prompt; if section matching is ambiguous, retain raw and mark projection_incomplete rather than assigning a description to a guessed section.

## Candidate and eligibility extensions — migration000021

### v3_manual_scene_imports

id UUID; case_id/section_id/prompt_version_id FKs; actor_user_id FK owner; command_id FK unique; package_sha256; private_source_ref; user_reported_provider nullable varchar80; user_reported_model nullable varchar120; candidate_id nullable unique FK filled after candidate commit; created_at. Same command/body deduplicates; stale current prompt rejects before candidate creation. No CodingAttempt, tokens, API response ID or purchased duration is invented.

### v3_qualification_gates

id UUID; case_id/section_id/prompt_version_id required FKs; candidate_id nullable FK (NULL for prompt gate); gate_stage `prompt|scene`; basis `real_jev|owner_waived_demo`; real_qualification_id nullable UUID reference validated against stage-specific existing table; waived_by_owner_id nullable users FK; policy_version; policy_sha256; prompt_sha256; candidate_manifest_sha256 nullable; missing_fields_json; acknowledgment_sha256 nullable; command_id FK; created_at. Unique(command_id,gate_stage,candidate_id) supplemented with application-level unique prompt command key when candidate NULL. Never create/modify a JEV result for a waiver. Real gate has actual pass ID and no waived actor; demo gate has original owner and no real ID. Scene waiver requires current exact candidate and prompt, passing actual browser check, and explicit owner acknowledgment from captured policy/command. Successor candidate needs a new scene gate.

### v3_authoring_requests — explicit prerequisite for demo API authoring

Migration000021 MUST also extend this table, not just candidates: make qualification_id nullable while preserving its FK to v3_prompt_qualifications; add eligibility_basis varchar24 default real_jev and prompt_gate_id nullable UUID FK v3_qualification_gates. Keep existing request identity, prompt_version_id, settings/template capture, request uniqueness, attempt budget and version fences unchanged.

Real eligibility requires a genuine current passing qualification_id for the same case/section/prompt; prompt_gate_id may additionally reference matching real gate evidence. Demo eligibility requires qualification_id=NULL and a valid prompt_gate_id whose stage is prompt, basis is owner_waived_demo, candidate_id is NULL, and case/section/prompt/hash/owner/captured policy match the request. Demo requests are permitted only for notebooklm_per_pdf_web_v1 with captured demo mode and original-owner acknowledgment. Neither nullable field permits a request without either valid eligibility branch. Existing rows retain real_jev and their qualification IDs; old protocols cannot use the demo branch.

ClaimAuthoringRequest MUST resolve and recheck this branch under its existing current-prompt/control reservation fence before creating a genuine CodingAttempt. Demo API calls still create real authoring requests/attempts with actual provider evidence. Manual scene imports create neither. Candidate provenance MUST agree with its request's basis, prompt gate and prompt version. Schema/claim tests must prove demo reservation succeeds with no JEV row, real/legacy requests without a pass fail, mismatched/stale/foreign gates fail, and populated real requests retain their original FK values.

Extend SceneCandidate: origin `provider_generated|manual_import`; package_profile `legacy_v1|static_three_file_v1`; manual_import_id nullable unique FK; prompt_gate_id nullable FK. request_id,attempt_id,prompt qualification_id become nullable; prompt_version_id remains REQUIRED. Existing generated real branch still requires genuine request/attempt/pass; new API demo branch requires actual request/attempt and the same prompt demo gate as its authoring request; manual requires import row and no attempt. Candidate state adds demo_review_ready without treating it as qualified.

Review items/decisions/approved scenes: eligibility_basis default real_jev; qualification_gate_id nullable FK; scene qualification_id nullable. Real old rows keep existing pass reference; demo rows require exact scene gate, no pass ID. Every decision and approval must repeat the item's basis and exact candidate version. Same-case/section ownership and evidence validity checked inside mutations. Existing real-qualified records are not downgraded or converted.

## State transitions and failure rules

Run: staging → queued → running → prompts_ready → awaiting_manual/review_pending → completed; running can become paused_auth, paused_quota, uncertain, paused, partial, failed or cancelled. API mode runs generating/checking after prompts_ready; export_manual moves awaiting_manual. `completed` means all selected run scenes approved, never merely exported. generation_phase separately records notebook_complete/prompts_exported/scenes_ready, so a useful export is successful stage completion without fabricated run completion. A run with failed PDF/scene retains partial and still allows successful content review. No overall percentage until totals are known.

Item: staging → accepted → queued → source_pending → mapping → lesson_loop → archived → prompts_ready → scene_processing → review_pending → completed. Rejected, failed, blocked, cancelled are explicit. PDF-local failure is terminal for that item until explicit retry, allowing next item only after safe source-scope/archive/reset settlement. Authentication/quota/delivery uncertainty pause run; no skip beyond unproven conversation boundary.

Commands: pending→accepted→applied or rejected; uncertain transport stays pending/uncertain with stable identity. Pause/cancel acknowledged as requested until current operation settles; applied fence prevents next dispatch. Cancel wakes paused waits. Retry saved response/build is local; retry unproven send requires consent. Closed processing chunks get a linked bounded recovery child, not resurrection of old workflow history.

## Atomicity, concurrency and retention

Write state/version, command/outbox and event in one short transaction. Lock/check order: fresh access → run → item/case control → section pointer → candidate/gate → review pointer. SQLite uses BEGIN IMMEDIATE or equivalent transaction plus conditional version update; retry SQLITE_BUSY at50/100/200 ms, then409 concurrent_update. Do not retry provider sends. Stage large artifacts outside transaction; final lock rechecks digest/current pointer before committing references. Atomic temp+flush+rename publishes artifact pointers; never overwrite prior attempts.

Reuse015 deletion assessments/retention settings; add aggregate/link/gate/import ancestry to blockers. Admin never reads private educational artifact to perform metadata deletion assessment. Dry-run importer performs no DB writes, filesystem copies, workflow calls or remote provider calls.
