# Implementation Guide and Repository Handoff

This is the implementer's companion to [spec.md](spec.md). It documents observed integration points, required decisions and prohibited shortcuts. It is not proof that Feature 021 exists.

## 1. Work against the correct source

The actual application recently run in Docker is `/home/moahmmed/college_project`. HEAD was `cae7313` during the 2026-10-17 audit, with substantial uncommitted changes, including Docker, Inertia pages, prepared-PDF intake and protocol fixes. Preserve these changes. Do not reset/stash/clean them or overwrite them with older files from `/home/moahmmed/.codex/worktrees/116d/college_project`.

Before edits, record the actual checkout/commit, `git status`, tracked diff digest and digests of relevant untracked source files. Those record an evidence snapshot; do not include `.env`, cookies or credentials in a report/archive. Re-read relevant files: line numbers below are audit pointers, not stable APIs.

No platform or presentation service should be restarted simply to read this package. The user explicitly stopped both. Application changes are a separate implementation task; do not present these documents as deployed behavior.

## 2. Existing behavior versus required extension

| Area | Actual source / current behavior | Required change |
|---|---|---|
| Intake | `app/Services/V3/WebPipeline/SubmitSubjectRun.php`, `PrepareWebPdfs.php`; PDF inspection, private originals, ordered v2 preparation and immutable run capture already exist in the actual checkout. | Add captured execution mode/output scope, owner consent/processing scope and durable local-stage queue admission. Reuse preparation. Add idempotent provider-free accepted-item materialization before task sealing so offline waiting does not depend on 24-hour upload quarantine. |
| Python preparation | `v3_engine/web_pipeline/pdf_preparation.py`, `terminal_pipeline/pdf_preparation.py`. Current policy v2 is >50 trigger, <=50 terminal parts; actual web limit includes <=640 prepared parts. | Run this CPU-only capability with server-local storage. It must not require local Google credentials, laptop bridge or shared laptop paths. |
| Submission dispatcher | `DispatchRunOutbox.php`, `TerminalBridgeClient.php`, `routes/console.php`; currently calls `application_bridge` and assumes same-host topology. | Branch on immutable execution mode in run outbox and every generic case/source/map/lesson dispatcher/reconciler; local jobs never also dispatch old authoring. Preserve staged-case/accepted-activation gates. Keep unrelated server-side preparation/check jobs functional. |
| Settings and capture | `SelectCaseSettings.php`, `CaptureSubjectRunManifest.php`, `app/Models/V3/SubjectRun.php`. | Extend capture additively; retain old snapshots. Include exact generation instruction/template/output-contract bytes in portable stage input. |
| Access | `app/Services/LearningAccess.php`, `Access/V3AccessResolver.php`; one centralized platform-owner identity; ordinary admins have operational visibility. | Add explicit device-management and scoped processing authority; do not redefine all admins as content owners. Resolve access fresh at admission/claim/dispatch/commit. |
| Frontend | `resources/js/pages/Teacher/RunCreate.jsx`, `RunShow.jsx`, `RunScenes.jsx`; `Admin/Workspace.jsx`, `RunIndex.jsx`, `Settings.jsx`. | Extend real Inertia pages; do not build disconnected Blade mock screens or introduce infrastructure controls into student flows. |
| Prompt export | `WebPipeline/ExportScenePrompts.php`, `Teacher/SubjectRunController.php`. | Use verified JSON/body bytes, reject missing expected durable prompts; text formatter is for humans and currently trims text. |
| Notebook processing | `v3_engine/notebooklm/{ports,sdk_provider,terminal_service,credentials}.py`. | Reuse provider-only logic behind a remote task-context/application-client adapter. Do not rewrite the model protocol from memory. |
| Local orchestration | `terminal_pipeline/{worker,subject_workflow,activities,controls,store,pdf_scope}.py`; local process locks/Temporal activities exist. | Add standalone remote-worker CLI/journal with server lease/epoch; do not give a laptop hosted DB or unrestricted Temporal credentials. |
| Existing internal callbacks | `routes/api.php`, `Internal/WebPipelineController.php`, `web_pipeline/application_client.py`. | New external device API façade with bounded task access. Internal global HMAC middleware is not a device enrollment system. |
| Static scenes | `Authoring/StaticScenePackage.php`, `ImportManualScene.php`, `StoreSceneArtifacts.php`; Python `scene_generation/source_package.py`. | Keep exact three-file contract. Add explicitly authorized worker result command rather than impersonating a teacher. Stage and rehash before acceptance. |
| Checks/review | `SceneQualification/RecordSceneCheck.php`, `SceneReviewEligibility.php`, `Review/EnsureReviewReady.php`, `SubmitReviewDecision.php`. | Preserve real checks, eligibility basis, current prompt/candidate, preview proof and human decisions. Worker assertions do not replace them. |
| Publication | `WebPipeline/LessonPublication.php`, `StudentSceneController.php`. | Keep distinct lesson/scene publication and student sandbox validation. No auto-publish shortcut. |
| Historical import | `TerminalArchiveReader.php`, `MaterializeTerminalImport.php`, Python `web_pipeline/import_*`. | Reuse validators where useful, but never label a fresh queued job `historical_import` to force a shortcut. |
| Docker lifecycle | `bin/product`, `compose.yaml`, `compose.dev.yaml`, `docker/`. | Add documented hosted/local configurations. Existing same-host mode remains valid but is not remote-safe by itself. |

### Current code hazards that this feature must close

1. `AcceptWebLesson.php` currently falls back to owner 1, a first/default settings version, synthetic source hash, byte count 1024 and page count 1 when associations are absent; it can create invented source/binding rows. The new route MUST fail closed before any such path, or refactor that service to require authoritative associations. Acceptance includes missing-association cases that previously reached these branches.
2. `Internal/WebPipelineController.php::putArtifact` currently writes bytes by a supplied ref. Do not expose it externally. New staging uses server-issued IDs, scope, expected size/digest, quarantine and immutable commit.
3. `VerifyWorkerSignature.php` uses a global internal HMAC and lacks device-level scope/revocation. The laptop must never receive this secret.
4. Existing `ClaimOperation.php` / `CommitOperation.php` do not constitute full remote lease/epoch fencing. A cache hit is not authority, and replay must validate device identity and current grant.
5. `web_pipeline/activities.py::reset_conversation_activity` lacks the terminal path's separate durable pre-send reset fence. Add/prove equivalent behavior before using this path remotely.
6. Provider create/upload reconciliation may use a unique title. Do not call that a cryptographic confirmation of uploaded PDF bytes.
7. Existing artifact/credential directories include per-run Google sessions. Export from an explicit allowlist, never by copying “everything under private”.
8. A prior run can legitimately be completed with prompts while scenes are absent. Do not invent packages, approval, cost or measured usage just to fill a screen.

`AcceptRunItem.php` and `IntakeCoordinator.php` are essential before local task sealing: current old Python orchestration normally triggers accepted Document/Case/Lecture creation after submission. `DirectSourceCoordinator::prepare` creates a source-binding shell without contacting Google. The new hosted admission coordinator must perform/recover those actual operations independently of laptop availability, promote real uploaded bytes out of expiring quarantine, and never synthesize these associations at result import.

## 3. Component ownership and adapter seams

Implement these logical components in the existing project languages/frameworks:

- **Hosted Device Registry**: invitations, token hashes, current grants, last seen, revoke/rotate/audit.
- **Local Job Coordinator**: stage tasks tied to existing runs, immutable input manifests, claim atomicity, epochs, controls and budgets. The legacy authoring path is mutually exclusive per captured mode.
- **Artifact Gateway**: bounded authorized streams, upload quarantine, digest/size verification and receipt lookup.
- **Result Acceptance Service**: exact protocol validators, source/lesson/prompt mapping, safe domain commands, one authoritative transaction + durable downstream outbox.
- **Worker Agent**: enrollment/configuration, outbound poll/heartbeat, sealed download verification, disk quota and shutdown control.
- **Local Runner**: provider adapter, versioned instruction context, persistent checkpoints and provider-effect journal. It consumes prepared PDFs without calling `capture_manifest`'s preparation again.
- **Scene Return CLI**: verify current prompt assignments, export exact instructions, ingest genuinely created three-file packages and return them through the same artifact/result boundary.
- **Provider Port**: actual provider capabilities, raw response/effect records, token instrumentation when available. Optional API adapters do not change teacher ownership or result validation.

The existing local runner calls internal Laravel services during authoring. Extract/reuse provider-only behavior and add a constrained task-context façade; do not copy the global `ApplicationClient` onto a remote device. Server-side lesson/protocol decisions must use captured task state and the new scoped endpoints. If an existing function requires same-host paths, introduce an explicit resolver for a private downloaded workspace and test relocation to another absolute directory.

Do not run the existing CLI `submit` on the hosted server and separately submit the downloaded PDFs as a second unrelated terminal run. That would create two owners/orchestrations and repeat preparation. Local execution identity must remain bound to the hosted job/stage, with durable parent/child references if local orchestration is reused.

### Proposed CLI surface

These commands are requirements to implement, not existing commands:

```text
local-worker enroll --server <https-origin>             # pairing secret entered masked
local-worker doctor                                   # local config/credential checks, secret-free
local-worker run                                       # daemon: outbound claim/heartbeat/process
local-worker status                                   # actual local/remote stage summary
local-worker stop --drain                              # stop claims/new effects, save in-flight outcome
local-worker export-prompts --job <id> --output <dir>   # exact bytes + prompt bindings
local-worker import-scenes --job <id> --input <dir>     # real packages only, no generation claim
local-worker reconcile --job <id>                      # read-first recovery; cannot approve risk override
```

Name/location can follow repository conventions, but the documented commands must exist and be exercised. Never present a help-only stub as the daemon. Provide bounded JSON output and usable exit codes. Doctor distinguishes local infrastructure readiness from real provider session connectivity; it must not contact Google unless explicitly requested through a live check option.

## 4. UI and operator delivery

- Teacher intake presents the enabled managed-processing option and asynchronous delivery expectation; expose institution-facing choices, not Brave profile names or prompt-engineering settings.
- Teacher progress displays accepted counts and actionable queued/offline/auth-required/reconciliation/awaiting-generation states. Do not display fake percentages, fake completion estimates or device secrets.
- Owner device page shows grant scope/capabilities, online status, active jobs and revocation. Enrollment secrets are one-time display only.
- Operator job view can access assigned private input/output only under explicit processing permission. This is additional authority, not automatic content access for every admin.
- Prompt download is available when authoring has actually committed. For manual generation show which exact prompts still need scene packages; imports bind current versions.
- Teacher scene review and student delivery continue through existing pages. Newly returned files are candidates awaiting actual checks/review.

## 5. Defaults and important decisions

- Default output scope: `prompts_only`; choose `prompts_and_scenes` explicitly for a managed scene-delivery pilot.
- Mandatory generation mode: `manual_web` return, with honest reported provenance. Existing paid API adapters may be a separately enabled mode only with explicit spend policy and evidence. Do not automate subscription-site UI as a hidden dependency.
- Default authoring concurrency: one task/device, one provider mutation at a time. Increase only after independent source-scope/effect tests and live quota measurement.
- Heartbeat/lease/poll defaults are normative in the protocol; they are availability parameters, not provider execution timeouts.
- Local credentials are refreshed explicitly using existing credential setup. Server startup must succeed without Google sessions; laptop startup does not import them silently from arbitrary browser profiles.
- Server credentials and device credentials are different. Each device has narrow app authority, while provider sessions remain separately local.
- Keep a configured resource ceiling and stage-level cost instrumentation. No server GPU is required merely to call a hosted model API.
- Hosted production orchestration must not be claimed from the existing Temporal `start-dev` SQLite topology. Document the selected supported deployment or keep real public release pending.

## 6. Future API evaluation, separated from delivery MVP

Define the provider result port now; do not implement a full new RAG/LLM system as an unrequested prerequisite. Evaluate future paths on the same sealed curriculum sample:

1. Current local NotebookLM path as retained baseline.
2. Official model API with scoped PDF context.
3. Managed file retrieval with a supported model, if appropriate.

Record exact model/version and generation settings, actual input/cache/output/thinking tokens when returned, retries, machine runtime, operator minutes, teacher corrections and accepted scene counts. The metric is cost/time per accepted educational output, not cheapest token or shortest chat alone.

Per-file/lesson scoping, fixed map requests, narrow context and citations should be compared before increasing context size. A large context window is not proof of exhaustive coverage. Cache reads/storage/TTL and inference are distinct charges. Independent prepared lessons or scene prompts may be parallelized only after dependencies and safe retry semantics are preserved. Batch generation is an optional later experiment with a delivery-time trade-off, not a promised speedup for a dependent conversation.

Benchmark material candidates: the retained eight-PDF mechanics run, the 37-page medical-informatics sample and an authorized school-level unit with subject review. Do not fabricate missing inputs, fetch unauthorized private textbooks, submit to providers, run paid calls or publish student content as a consequence of writing the evaluation plan. Use the existing acceptance authorization rules when actually executing it.

## 7. Safe development and completion

Use dedicated temporary test storage/databases/accounts for automated acceptance. Do not reset production SQLite, delete old notebooks, weaken access controls or add synthetic fixture rows to the live app. Preserve existing files and signed/captured artifacts.

An implementation report must map each FR/SC to real evidence, including exact commands/exit codes, source snapshot, fixture use, actual artifact references/digests, skipped gates and unresolved failures. “Not run”, “unsupported”, “blocked” and “not measured” are valid outcomes. They are not passes.

No prompt can guarantee that an agent will behave honestly. This package makes unsupported claims easier to detect by requiring retained artifacts, strict domain invariants, independent joined-boundary checks and a separate live readiness status.
