# Implementation Agent Notes — Spec019

Read this before tasks. It complements [task-execution-plan.md](task-execution-plan.md), not an alternative plan. Implement the existing task IDs; do not regenerate specifications or mark tasks complete from mocks/files alone.

## 1. Required reading sequence

1. Applicable `.agents/AGENTS.md`; Conversation23; V3 spec map. Later approved owner corrections in019 and docs/PER-PDF-NOTEBOOKLM.md supersede older text-extraction/joint-map/React wording for new captured cases.
2. Every019 document: spec/plan/research/data-model/acceptance-matrix, all contracts, tasks, this file, execution plan, quickstart/checklist.
3. Dependency contracts004–015 covering access, settings, intake, workflow, NotebookLM source, map/Section, prompt/JEV, authoring, qualification, review, admin/evidence. Read016 protocol and017/018 downstream boundaries. Do not edit these specs merely to make019 appear consistent.
4. Actual models, controllers/routes/middleware, migrations, V3 services, provider ports/workflows/worker registrations and existing tests named in tasks. Existing workspace contains significant uncommitted work; reread actual methods before extending them.

## 2. Scope and fixed defaults

One subject run = one notebook; each PDF = one application lecture/case with its own map and lesson loop. Source ordering is immutable. Export/manual default performs zero generation API calls, including no check-chat. API mode is explicit and selected; approved static template produces HTML/CSS/JS, not React. Scene types/counts are unrestricted metadata; there is no three-scenes rule. This does not remove identity, artifact, receipt, browser or ownership checks.

Approved account alias mo50atiapro, Brave Profile1. MCP authentication bootstrap remains; SDK handles uploads/chats. No runtime browser login, no reading another profile automatically. Proof notebook verifies ownership, not a processing input. Authentication success for one storage-state path does not prove a run's isolated copy is current; retain exact credential ref and require explicit refresh of that isolated copy if needed.

## 3. Reuse real services, not subprocess CLI

NotebookTerminalService in v3_engine/notebooklm/terminal_service.py owns provider operations. SceneGenerationService in v3_engine/scene_generation/service.py owns selected generation/materialization/build/recovery. Web Activity wrappers resolve refs, persist fences/receipts and call those methods. Vendor SDK stays in sdk_provider.py. Keep current terminal commands working.

Existing Laravel BridgeClient starts legacy CaseWorkflow in a different namespace. New TerminalBridgeClient uses explicit terminal namespace/routes and existing BridgeRequestSigner exact-body convention. Never spawn shell scripts from HTTP requests or enqueue both legacy CaseWorkflow and new ApplicationSubjectWorkflow for the same effect.

settings.py default root currently differs from activities/CLI defaults. Capture one absolute root for bridge/worker/journal/store. Do not replace archived source roots with the new default. A file ref must pass no-symlink checks BEFORE path resolution and containment checks AFTER; resolving first loses symlink evidence.

## 4. Per-PDF loop details

Upload framework once. PDFi Divide selects ONLY PDFi; Execution/lesson turns select PDFi+framework. PDF1 archived and conversation reset confirmed before PDF2. Prior PDFs may remain uploaded; request source IDs exclude them. UI checkboxes are not service scope evidence. Preserve approved Divide/Execution/framework/format text bytes. Archive distinct receipts by run+PDF+lesson+turn+attempt; identical Continue strings never share a receipt key.

Current018 workflow uses ordinary RuntimeError in some failure branches. Add explicit outcome handling so Workflow Tasks do not retry forever. Unknown map or definite source error can fail one PDF only after safely settling chat/reset. Auth, quota, history mismatch, storage failure or uncertain reset cannot be treated as local skip. Partial-run export must select successful outcome archives; current helpers can require every PDF archived, which must be extended compatibly for the new path.

Do not confuse generation stage completion (prompts_exported) with application approval completion. Manual/review waits close processing chunks and release supervisor slots; Laravel retains waiting states and later explicit commands admit bounded new children.

## 5. Migrations and projection traps

Current SQLite is supported; historical migrations have forward FKs. Never rename/rewrite applied historical migrations or migrate:fresh the real DB. Use additive table rebuilds preserving constraints and test a populated copy. Verify both before/after schema counts and retained-record digests.

Subject/Lecture/Section have no original-teacher owner field today. New links supply it without changing unrelated legacy subject ownership. New PDF uses private Document storage; leave public lecture.pdf_path NULL. Each canonical TeacherSection retains eleven fields; legacy Section is navigation link, not another educational copy. Nullable First fields must preserve NULL vs supplied empty laws. Update current context readers/hashers only on019 path; old strict complete records remain strict.

Bindings globally uniquely index notebook IDs. New shared NotebookInstance resolves remote notebook privately; new bindings keep legacy notebook_id NULL. Their primary source_id remains their own PDF. Framework reuse goes through per-binding role manifests. A shared notebook never authorizes another teacher's case.

Raw imported handoffs often have raw_first/raw_second/raw_third, not first_output objects. Parse them deterministically, retain missing warnings and raw answer; do not fabricate model fields or fake live query/send evidence. Import-specific lineage columns permit historical records honestly. Check every live-only FK whose validation assumes actual query/attempt before treating import as complete.

## 6. Manual and waiver lineage

Current candidates require authoring request/attempt/prompt pass. Reviews/decisions/approvals require scene qualification. New origin/gate fields and nullable machine-only FKs are necessary, with strict branch invariants. Manual candidate requires actual import row/current prompt, no made-up CodingAttempt. API demo still has a genuine paid attempt, but its prompt gate is a waiver rather than fake pass.

The authoring request itself also has mandatory qualification_id today. T006 must make it nullable with preserved FK and add eligibility_basis/prompt_gate_id; T038 must enforce real-versus-demo branch invariants on ClaimAuthoringRequest. Changing only SceneCandidate leaves API demo authoring blocked before candidate creation. Missing gate never means approval, and old protocols keep their genuine-pass requirement.

Execute T040's direct-service receipt/build recovery checks and fixes before T041's workflow adapters. T036 contains both direct-service and adapter coverage: only its recovery subset must pass at T040; all coverage must pass at T041. Passing existing recovery behavior needs regression evidence, not an artificial failure or unnecessary rewrite.

Never weaken QualifiedPromptResolver globally. Add EligiblePromptResolver for new protocol, separate byte verification from qualification in SceneCandidateResolver, and extend every ReviewContext/EnsureReviewReady/StartReviewPreview/SubmitReviewDecision consumer. Demo state is demo_review_ready, not qualified. Exact candidate/prompt digests and actual passing seven-check browser evidence remain mandatory. Successor candidate needs a new gate/preview.

Package profiles differ: V1 remains256KiB/file,1MiB total; new V2 exactly3 files,1MiB/file,4MiB total. API/manual generated JSON may have transport overhead or escaped content; validate upload bytes and decoded file lengths independently. Browser runner must inspect V2 safely; no inline execution at Laravel origin or cookie propagation. Screenshots must be actual PNG evidence, not placeholder bytes. Duration is measured or NULL, never fabricated.

Manual provider/model is user-reported, API usage NULL/not incurred. Actual API returned model/response ID/usage/duration come from committed provider evidence. Source-manifest digest must be real; rebuild restores original titles/prompt hashes. Verify pointer/files with verify_current_build, not a nonexistent status field. All builds, including failed/interrupted ones, reserve unique ordinals and retain immutable logs.

## 7. Durable messages and private history

Update-With-Start prevents first-submit workflow-not-found. Durable admission/command receipt precedes acknowledgment. Supervisor existing arbitrary workflow_type branch must be restricted for web admission to trusted enums; do not expose class names from forms. Full command digest covers selectors/phase/consent, not just action. Lost acknowledgment retries same identity; duplicate-risk paid resend is a distinct consented attempt.

Temporal is durable orchestration history, not a repository for educational logs. Payloads/search attributes contain only local opaque refs, safe state/counts. Service Activities fetch bodies/config privately. Enforce<=32KiB on every boundary and page inventories. SDK exceptions can serialize raw __cause__; sanitize without attaching it, with diagnostics only in private artifacts.

Shared supervisor must count real active child chains after restart, not fake closure timestamps from an Activity inside the child. Queue cancellation/launch share a durable registry fence. Release reservations after real settlement. Continue-As-New carries bounded active/queued refs; settled receipts live in registry. Rotation must not permanently drain or close the admission service.

## 8. Legacy route protection and races

TeacherAccessMiddleware currently allows wide GET/admin access; protecting only v3 routes fails. Inspect subject/lecture/section CRUD, APIfilterController, next lecture, bulk sections, question/exam/dynamic-view ancestry and public storage links. Filter before counts/navigation, not after fetching hidden IDs. Fresh access is checked on every write and download/preview/decision. Linked legacy mutations are pipeline_managed; unlinked behavior stays covered by regression tests.

Run/item/case/section/current candidate/review pointer fences share documented lock order. SQLite does not supply a meaningful row-level SELECT FOR UPDATE guarantee; use transaction/CAS strategy and real two-process tests. Network/remote calls outside transactions. Race tests use independent connections/processes and barriers, not two sequential requests called concurrency.

## 9. Historical import

Source archive paths resolve against two different roots: file listings relative to run; accepted PDF/steps refs relative to terminal root. Exact captured bytes are verified, copied and rehashed; source archive remains untouched. Dry-run must not enqueue Temporal or mutate DB/files. Real import workflow records actual import events only. Admin chooses active owner/subject and sees only operational result counts. The reference eight-PDF run has63 lessons/89 prompts, zero generated scenes; never report it as generated/approved content.

## 10. Tests and reporting

Behavioral red must exercise missing required behavior, not missing import/syntax. Existing passing behavior is regression coverage. External provider doubles are allowed for offline integration; actual route/signature/worker/service/persistence/check/preview boundaries cannot be stubbed into success. Two-process races and actual Temporal replay/browser checks are separate gates. Dedicated live chain uses ownership proof notebook, real uploads/prompts/generation/storage and actual teacher review; manufactured qualification dictionaries are not evidence.

Record commands, actual counts, failure reasons, sanitized artifact refs, workspace preservation, remaining live gates and limits in implementation-report.md. No runtime tests were performed while writing these specification documents. Constitution placeholder and absent agent-context updater are documented tooling limitations; this file is the explicit agent-context artifact, not a claimed execution of the missing script.
