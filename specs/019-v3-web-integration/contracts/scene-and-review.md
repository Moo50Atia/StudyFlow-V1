# Scenes, Eligibility, Preview and Review

## Input and materialization

Static source JSON: schema_version1; files array containing exactly {path,content} for index.html,styles.css,script.js. No extra files/keys, duplicates, traversal, symlinks, NULs, invalid UTF-8 or empty content. Each decoded file<=1 MiB,total<=4 MiB; uploaded JSON<=4 MiB. Arbitrary scene_type and section count do not affect eligibility. The prompt remains exact original description; manual export includes approved static-system-template bytes plus description as separate source fields so users can reproduce request.

ScenePackageV2 manifest: schema_version2,profile static_three_file_v1,case_id,section_id,prompt_version_id,prompt_sha256,origin manual_import/provider_generated,provenance_ref UUID,source_manifest_sha256,files[{path,mime,bytes,sha256}],entrypoint index.html,created_at. Files/private manifest checked before publication. Static materialization preserves filename/content, rewrites nothing, publishes a separate runnable manifest/pointer atomically. Existing V1 package checks remain unchanged. Browser adapter resolves files by verified manifest, not source directory names or untrusted paths. No external network/dependencies or application cookies in preview/check container.

Manual imports use genuine import receipt; report provider/model as user-reported, usage/response ID NULL, API requests0. API candidates retain actual request/attempt/provider response/usage/duration and captured provider/model/template hashes, not requested model as returned model. Select Gemini/GLM/DeepSeek/Qwen through existing adapters; new Laravel catalog Qwen identifier qwen_api maps to Python qwen and captured approved HTTPS endpoint, never arbitrary browser URLs. Credentials by server alias only. Capture Gemini budget24576/output65536 unless an explicit later command captures different supported values; never force thinkingBudget0. Verify prerequisites/config before send. No default probe or model fallback.

Retained receipt restoration verifies provider-body hash, metadata complete/truncated flags and normalized response equality; measured duration is preserved, not recomputed as NULL. Healthy artifact skip uses verify_current_build, not invented status fields. Build-only recovery loads original captured inventory, hashes, scaffold/toolchain and lockfile, reserves attempt ordinal durably and never requests provider work.

## Qualification gates

EligiblePromptResolver selects actual passed current prompt (legacy/real mode) or exact current new-path demo prompt gate. Never weaken QualifiedPromptResolver for old cases. SceneCandidateResolver separates artifact verification from gate resolution. Shared typed eligibility result: basis,prompt_version,candidate/version/manifest,check_run_id,real_qualification_id nullable,gate_id nullable,missing_fields.

Demo API reservation also requires the authoring-request schema amendment in data-model.md: eligibility_basis=owner_waived_demo, qualification_id=NULL and matching prompt_gate_id. ClaimAuthoringRequest must validate the same case/section/current prompt/owner/hash/policy before reserving a real CodingAttempt. Missing gate is not eligibility; manual import must not create an authoring request to satisfy candidate FKs. Real/legacy requests retain genuine passing qualification references.

For real_jev: existing direct typed Choice/Score/Noul policy applies; Noul probability counts as yes-confidence under the agreed policy, no extra model judges a successful result. Missing required First context blocks, not fake-fill. Existing retry/revision limits come from captured005/010/012 settings. No capture-time waiver switch on provider error.

For owner_waived_demo: actual prompt gate and scene gate record original owner acknowledgment, exact prompt/candidate hashes and missing context. No JEV call/pass/score/attempt. Candidate becomes demo_review_ready only after actual seven browser checks pass: integrity,entrypoint,assets,runtime,network,interaction,accessibility. Require complete report schema, actual capture bytes/digest, report identity and measured duration (unknown represented NULL, never constant1). A failing check prevents review; successful build alone is insufficient.

All of EnsureReviewReady, ReviewContext, StartReviewPreview, SubmitReviewDecision and EvidenceProjector consume same basis; mutations recheck under locks. Legacy actual-qualified rows stay qualified; demo gate is not accepted by legacy endpoints lacking explicit new protocol.

## Review and replacement

Existing preview isolation/frame/action/session APIs remain. Original active owner only; first_frame_at required for exact candidate. Controls are visible on existing review page. Approve/request_changes requests retain existing IDs/version fences/feedback kind/notes, with qualification basis projected. Duplicate/stale/concurrent decisions conflict; predecessor candidates are superseded/stale and cannot be approved. Approval carries demo basis visibly in UI/download/evidence, never exported as production-qualified.

Visual correction preserves current prompt and creates successor candidate. Factual correction uses captured active PDF/source and revises prompt through existing source-grounded correction ports, then creates successor and repeats checks/gates/review. Manual mode exports correction instructions/prompt and awaits replacement import, with no paid scene API; a factual correction may require NotebookLM, which remains a distinct durable explicit source operation. API mode generates only the selected successor under captured settings. Preserve prior approval/decision/evidence and clear current approval only through existing versioned correction effect.

Review outbox start_correction and signal_decision effects must execute real registered workflows; exact consumed receipt governs delivery. Missing workflow starts through defined shared admission, not fabricated accepted202. Prompt/candidate changes require new preview and gate. Closed-run corrections use a linked bounded ApplicationSceneWorkflow child through the shared scheduler; no placeholder repair/correction result is success.

## Evidence and retention

Evidence graph branches source→lesson/raw bundle→prompt→manual import OR real provider attempt→source/runnable manifest→actual browser check→real JEV OR demo gate→preview→decision/approval. Missing generation/JEV evidence in manual/demo mode is intentional absence, not fabricated chain gap. Preserve provider time/cost unknown versus zero-call manual execution distinctly. Admin aggregates separate real-qualified, demo-reviewed and generated/imported counts. Existing015 deletion assessment must traverse new gate/import/link ancestry and unresolved effects.
