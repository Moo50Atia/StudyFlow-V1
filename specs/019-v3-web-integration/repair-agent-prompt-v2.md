# Spec 019 implementation agent prompt v2

Use this prompt instead of the earlier T066–T115-only prompt. It targets the current dirty workspace and preserves already working repairs. All implementation and test requirements are explicit in tasks.md T116–T155 and repair-acceptance-v2.md. This file is an instruction artifact, not completion evidence.

Copy the following into the implementation agent from `/home/moahmmed/college_project`:

```text
$speckit-implement

Implement and verify the remaining Spec 019 web integration repairs in this repository. Execute T116–T155 from specs/019-v3-web-integration/tasks.md in dependency order, and satisfy specs/019-v3-web-integration/repair-acceptance-v2.md in full. Reconcile related open and overstated T001–T115 claims using actual evidence. Deliver working code, meaningful tests, real website journeys, and a truthful final report.

You have authorization for these implementation repairs, necessary additive schema changes, documentation/task updates and isolated local verification. Do not stop after a plan, a passing unit suite or a proposed solution. Do not request renewed approval for ordinary reversible changes already within this scope. Complete independent offline work even when live prerequisites are unavailable. This instruction explicitly requests these task/document updates; do not create an unnecessary “Generate” approval round.

1. READ AND SELECT THE CORRECT FEATURE

Read these in order:
- Applicable repository instructions, including .agents/AGENTS.md.
- specs/019-v3-web-integration/spec.md, plan.md, data-model.md and contracts/*.md.
- specs/019-v3-web-integration/repair-acceptance-v2.md.
- The T116–T155 section of specs/019-v3-web-integration/tasks.md, then the original tasks it references.
- acceptance-matrix.md, implementation-report.md (especially independent review), quickstart.md, and docs/V3-WEB-PIPELINE.md.
- Actual production source and tests for each boundary before changing it.

Spec 019 is the target. The workspace selector may point at Spec 020. Record exact .specify/feature.json bytes first. Use SPECIFY_FEATURE_DIRECTORY=/home/moahmmed/college_project/specs/019-v3-web-integration when invoking Spec Kit tools; the local helper persists this override, so restore the original bytes in cleanup even after failure. Do not implement Spec 020 or rewrite the existing terminal engine. If current source differs from a cited path, trace the actual handler and document the equivalent path rather than invent a duplicate service.

2. ESTABLISH FACTS; DO NOT TRUST OLD COMPLETION LABELS

The previous agent's success statement is not acceptance evidence. Current known issues:
- The real archive uses per-PDF work_count/scene_count, per-PDF lesson_handoffs, and scene-prompts.json lessons[].scenes[]. Reading top-level prompts yields zero prompts.
- Import currently does not establish the full canonical lesson/section/prompt graph merely by creating runs, lectures or legacy Sections.
- Isolated PHP tests with fake internal bridge responses and separate Python tests do not prove the full website connection.
- Live gates that only check environment flags or staging do not test processing, generation, browser checks or owner review.

There are useful recent repairs for queued import and selected provider settings. Preserve them where correct and verify them through the real chain. Do not reimplement fixed work merely because an old task mentions it. Prior 180/110 pass totals are historical component results, not your test execution. Recheck additional contract drift: canonical eleven fields, provider list including contracted Qwen, Gemini settings, mode aliases, actual routes and upload limits. Do not silently remove required providers/features, weaken validation, fabricate hashes, fill missing fields, or change expected counts to match broken output.

3. REQUIRED IMPLEMENTATION OUTCOMES

US1 / T122–T129: Verify the complete archive evidence graph, exact hashes/bytes/schema/identities and path containment. Parse raw First with missing-aware canonical fields. Import actual historical maps, lessons, sections, prompts and owner navigation links with honest provenance. Never invent live queries, source text, framework files, qualification records or costs merely to satisfy foreign keys; use an additive schema change if truthful missing provenance requires it. Copy verified evidence to a separate private destination; rehash before publication. Dry-run must make zero writes/copies/workflow/provider calls. Duplicate/resumed import must reuse deterministic IDs and exact receipts.

Reference acceptance uses storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root, run 09a59afe-ed26-4567-906b-e42d87d53362. In a disposable migrated populated DB, prove eight linked lectures/items/cases, 63 lesson records, per-PDF counts [9,3,9,7,5,8,8,14], 89 exact prompt bodies and associations, and zero generated/built/approved scenes or provider calls. Determine section counts from the real source structure. Browse all imported lectures as their teacher; verify every lesson/prompt is reachable. The separate two-PDF archive or a dry-run alone does not satisfy this test.

US2 / T130–T134: Start from the existing subject upload page and process two ordered PDFs through real admission, shared Temporal queues, NotebookTerminalService, signed callbacks and canonical persistence. Use two versus three mapped lessons with unequal scene counts in offline external fixtures. Verify framework upload, PDF-local scopes, exact outputs, callback idempotency, archive/reset boundaries and partial/auth/quota/uncertain states. Default export_manual produces exact scoped exports with zero scene generation calls. Lesson counts must not count sections or scenes.

US3 / T135–T139: Support the contracted manual source package (index.html, styles.css, script.js) and explicit selected API generation. UI selection must survive into the actual provider payload with immutable provider/model/template/endpoint/timeout/thinking/output settings and no fallback. Selecting one scene sends exactly one initial generation request, none for unselected scenes. Build-only/retained-response recovery adds zero generation sends. Unknown send outcome remains uncertain until an explicit consented new attempt. Actual seven-check browser qualification must run; successful artifact creation alone is insufficient.

US4 / T140–T143: Original teacher opens actual isolated preview, receives its first real frame, interacts and submits a current decision. Persist approval only through the normal guards. Prove exact decision ID/digest/version consumption by registered workflow code. Cover races, stale candidates, manual visual/factual corrections, selected API successors and corrections after workflow closure. Every changed candidate repeats checks and preview; history and demo/real qualification distinctions remain truthful.

US5 / T144–T147: Exercise real pause/resume/cancel/retry commands, settled boundaries, queued/paused cancellation, shared 4-subject/4-IO/2-build and 64-queued limits, process restarts and actual history replay. Manual/review waiting releases generation capacity. Pages show correct counts, unknown totals and last-known projection age when orchestration is unavailable. Admin sees only permitted operational metadata. Verify alternate legacy routes cannot disclose or mutate linked educational content outside the owner boundary.

All V01–V12 website/service matrix rows and A01–A28 requirements remain mandatory. Scope includes the existing subject/upload/run/lecture/section/export/scene/check/review/correction/evidence/approved-scene/admin views and their actual endpoints. Fix functional omissions discovered during those journeys; do not spend this repair on an unrelated visual redesign. CLI-only import is tested through CLI and resulting website views; no new admin content-access UI is required.

4. BUILD ONE REAL JOINED TEST ENVIRONMENT

Create the T119 harness with real browser login/session/CSRF, Laravel HTTP server, durable outbox dispatch, signed FastAPI bridge, persistent disposable Temporal namespace, production workflow/activity registrations, actual service classes, signed callbacks, shared on-disk test database, private artifact disk and actual isolated Chromium runner.

Double ONLY external NotebookLM/generation/JEV transports in the offline lane. Seed prerequisite users/permissions/settings/framework/template only. Do not seed expected pipeline outputs, manually mark workflows complete or replace the internals under test. :memory: SQLite cannot share state between your server processes; configure a disposable file DB consistently. Use unused ports and validate server identity. A port serving PDFs through Python http.server is not Laravel. Start/stop only processes owned by your harness.

Forbidden as evidence of joined acceptance: internal Http::fake bridge success, direct workflow.run, hardcoded activity completion, fabricated browser report/screenshot/first-frame timestamp, direct insertion of expected lessons/prompts, or mocked decision-consumed query. Such tests can remain labeled component tests.

Prove test sensitivity: stop bridge, stop worker, reject a callback, use a wrong signature and cause a real browser check failure. Each must prevent the corresponding success milestone. Then restore/recover through real paths. Capture provider transport request counts and stable correlation IDs so fixed fake outputs cannot pass.

5. EXECUTE REQUIRED TESTS AND RETAIN EVIDENCE

For each defect, first add or identify a meaningful failing behavioral test. Capture why it fails. Repair production code and run it again, then affected regressions. Do not rewrite expected values to match current incorrect behavior. If already fixed, show the governing assertion passes and avoid redundant edits.

Follow repair-acceptance-v2.md G01–G06, V01–V12 and command/environment instructions. Required evidence includes exact reference materialization, joined manual and selected-provider journeys, all website actions/denials, seven real browser checks and preview interaction, actual process crash/recovery, concurrency, fetched-history replay, populated additive upgrades, affected legacy/SDK/terminal/scene regression suites and frontend build.

Implement scripts/v3/verify_web_repair_evidence.py and repair-evidence/manifest.json as specified. These are new deliverables, not tools to assume already exist. Record exact commands, exit codes, source snapshot/diff hashes, timestamps, passed/failed/skipped/deselected counts, doubled boundaries, correlation IDs, artifact hashes and behavioral assertions. No credential or educational-body leakage in reports. Retain private raw test artifacts in private fixture storage. Validate the ledger with --require offline and, only after actual live success, --require complete.

6. LIVE GATE RULES

Implement the full live chain before claiming the gate exists. An enabled/provisioned gate must execute real website upload → NotebookLM → saved prompts → one selected generation → actual browser checks → owner preview/decision. Environment-variable checks, login-session reads or upload staging alone cannot pass it.

Use only explicitly enabled dedicated live inputs, the approved account/profile/session, provider/model and authorized spending. Never create/rotate keys, silently switch profiles, launch runtime login or spend from ordinary tests. Browser Google login, NotebookLM authentication and generation API credentials are distinct. Inspect configured prerequisites safely; do not assume all paid provider keys are required for local archive or default manual mode.

If missing prerequisites, name exactly what is missing, keep T112 and T152 unchecked, complete all independent offline repairs/documentation, and report live verification blocked. Request only necessary user input when needed. If provisioned execution starts and fails, record failure; do not catch it and convert it to skip. A live pass for one provider does not establish all other providers live-tested.

7. WORKSPACE AND PROGRESS

Preserve the existing dirty workspace and reference archive. Do not commit, reset, discard changes, deploy, run migrate:fresh on the real DB, or import fixture content into real user subjects. Additive migrations are exercised against disposable populated copies first. Do not print .env, cookies or secret values. Keep protected baseline/source hashes and restore the feature selector.

Give concise progress updates about verified milestones, current defect, and next verification. After each task, record evidence before checking its box. Never bulk-check tasks based on a broad suite total. Preserve original FR/SC/A/T meanings. Every governing open task must either have its assertions proven or remain explicitly open with a precise reason. Do not stop independent work because live credentials are absent.

8. FINAL ACCEPTANCE AND RESPONSE

The feature is fully done only when all required behavior, all website rows, affected regressions, reference import, offline joined chain and dedicated live chain have evidence. A skip is never a pass. A report saying “all tasks executed except…” must not also claim 100% completion.

Finish T153–T155 and report:
- What changed and which concrete defects it fixes, with file links.
- Task IDs completed and still open, mapped to original A01–A28.
- Exact website journeys/endpoint coverage and matching persisted results.
- Reference archive counts and hash/association/no-provider-call evidence.
- Joined manual/API, real browser preview/review, controls/recovery/replay and regression results.
- Commands and durable sanitized evidence locations, including validator outputs.
- Live status, exact missing prerequisite or failure if any, and tested provider scope.
- Workspace/database/archive/selector protection outcome.

Use “offline integration verified; live gate open” if that is the actual result. Do not say “Spec 019 complete” until --require complete passes AND review confirms actual required behavior. Counts, screenshots and a validator are supporting evidence; inspect the production path and governing tests as the final check.
```
