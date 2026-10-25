# Feature Specification: Hosted Platform with an Outbound Local Processing Worker

**Feature**: 021-hosted-local-worker  
**Created**: 2026-10-17  
**Status**: Specified; implementation has not started  
**Target source checkout**: `/home/moahmmed/college_project`  
**Baseline**: `cae7313` plus the existing uncommitted Laravel, Inertia, Docker and preparation changes. A commit ID alone does not identify that source snapshot.  
**Input**: The founder wants teachers to submit materials to a hosted platform, have the server inspect and prepare them, and have an authorized worker on the founder's laptop automatically collect work, process it with locally retained provider sessions, and return real prompts and/or generated scenes through a defined protocol.

## Document map and authority

- This file defines required outcomes, scope and acceptance requirements.
- [Protocol v1](contracts/local-worker-v1.md) defines the new transport, ownership, recovery, manifests and result acceptance.
- [Implementation guide](implementation-guide.md) maps those requirements to the actual repository and records unsafe reuse paths.
- [Tasks](tasks.md) define dependency-ordered implementation work.
- [Acceptance and evidence](acceptance.md) defines what constitutes demonstrated behavior.
- [Research and baseline](research-and-baseline.md) separates observed code, recorded runs, current research and design assumptions.
- [Implementation prompt](implementation-prompt.md) is the instruction to give the implementer.

MUST, MUST NOT, SHOULD and MAY are normative. The user's explicit request authorizes creation of this technical specification now. Historical workshop instructions requiring the literal word “Generate” do not add an approval step to this request. The technical contract is explicitly requested; it is intentionally separate from the user-outcome specification. The repository constitution is an unfilled template, not a ratified set of extra requirements.

For this feature, the current preparation policy v2 (>50 pages; every final part <=50 pages) is authoritative for new submissions. Spec 020 contains older v1 descriptions. Existing captured policy-v1 packages MUST keep their captured rules and bytes; this feature MUST NOT rewrite them. Existing ownership, qualification, preview, review and publication rules remain governing constraints. Conflicts other than this explicit v2 clarification MUST be reported before a conflicting implementation change.

## Intended business delivery

The first release is an institution-facing managed service. A teacher submits materials and sees truthful progress and results. Processing can wait for an authorized laptop to become available. A local operator can perform generation manually through an account's supported interface, then return the actual files. The product MUST disclose that delivery is asynchronous and that an unavailable worker delays work; it MUST NOT imply instantaneous or entirely automated generation where human steps remain.

This feature does not assert zero operating cost, 100% profit, provider permission to automate consumer websites, or production readiness merely because a local demo works.

## User Scenarios & Testing

### Story 1 — Upload once and receive verified prompts (P1)

An authorized teacher uploads an ordered set of PDFs. The hosted service inspects and prepares them, queues processing, and eventually exposes the actual saved lessons and scene prompts without requiring teacher access to NotebookLM or the operator's laptop.

**Independent acceptance**: A real hosted application and separate worker process exchange one prepared PDF and retained provider-fixture answers through the complete network boundary. Provider behavior is explicitly doubled in this acceptance tier.

1. Given a permitted teacher and valid PDFs, when intake finishes, then originals, prepared bytes, page lineage, real accepted document/case/lecture/binding associations, owner, settings, protocol and production instructions are retained before an executable task is sealed. A provider-free hosted admission coordinator finishes this work independently of laptop availability.
2. Given no available worker, when the teacher opens progress, then the task is visibly waiting for processing and no result is fabricated.
3. Given an authorized available worker, when it claims the task, then it downloads the exact prepared parts and instruction snapshots and processes them without accessing the hosted database or filesystem.
4. Given complete valid authoring results, when the server commits them, then the teacher can read all accepted lessons and export every saved prompt in stable PDF/lesson/scene order.
5. Given a missing raw answer, broken lineage or blank expected prompt, when submission is validated, then it is held/rejected with a safe explanation; missing content is not synthesized.

### Story 2 — Operate a laptop without making it a public server (P1)

The platform owner enrolls a device with explicit processing authority. The operator starts/stops a daemon; it polls automatically and retains local account credentials.

**Independent acceptance**: Pair one device, deny another tenant, revoke the device and confirm further operations fail. The laptop needs outbound connectivity only.

1. Enrollment requires the platform owner's dedicated permission, one-time pairing and explicit tenant/owner scope.
2. Ordinary operational admins do not acquire teaching-content access from this feature.
3. NotebookLM cookies, Brave profiles and generation API keys remain on the local device and never enter hosted task manifests, archives, telemetry or logs.
4. Closing the daemon, going offline, reaching a provider quota or losing authentication produces an accurate state and recoverable evidence rather than a successful result.

### Story 3 — Resume safely after interruption (P1)

A laptop or network failure must not cause the same uncertain provider request to be blindly sent again, or allow two devices to author one run at the same time.

**Independent acceptance**: Controlled failures at claim, provider dispatch, response persistence, upload and commit boundaries preserve one authoritative result and truthful uncertainty.

1. A lost claim or commit response is recovered by querying/replaying the same idempotency identity.
2. A lost lease prevents new provider mutations and canonical writes. An already in-flight response may be retained locally for reconciliation.
3. Lease expiry alone never proves a provider call did not occur. Potentially dispatched work moves to reconciliation rather than automatic reassignment.
4. A second worker or stale epoch cannot overwrite current outputs or trigger an unfenced replay.
5. Pause/cancel/revocation are applied at explicit operation boundaries; they do not erase prior work or pretend an in-flight external call was rolled back.

### Story 4 — Return genuinely generated scenes (P1)

An operator receives exact frozen prompts, generates scene packages through a supported method and returns the real files. The server associates them with the correct current prompts and applies existing checks and review gates.

**Independent acceptance**: Return an operator-created three-file package through the worker, validate it, run the actual browser checks, and prove student access remains unavailable until authorized review and publication.

1. The mandatory release supports automatic authoring-to-prompts plus manual scene return. It does not require invented automation of subscription websites.
2. Manual provider/model labels are operator-reported; API request IDs, returned model names, tokens and cost remain unknown unless actually measured by an API adapter.
3. A package for a wrong run, owner, scene, prompt version or digest is rejected.
4. Import, browser execution, educational qualification, teacher approval and publication remain distinct states.
5. An unavailable qualification service remains unavailable. An existing explicit demo waiver can only be used under its captured policy and must remain visibly a demo gate.

### Story 5 — Monitor operations and control capacity (P2)

The teacher sees useful progress for their work; the platform owner manages devices and safe operational metadata without widening other admins' privileges.

**Independent acceptance**: Role-based checks verify teacher isolation, device scope and privacy of ordinary admin summaries.

1. A teacher sees queued/offline/auth-required/reconciliation/awaiting-generation states, accepted counts and safe failures for their own runs.
2. The owner can disable assignment, revoke a device and inspect safe task state. Content access requires the separate explicit processing permission.
3. Events cannot contain PDFs, prompts, answers, filenames, provider notebook IDs, cookie values or local paths. Content-bearing evidence has private authorized access.
4. Costs and token counts distinguish measured, reported, estimated and not-recorded values.

### Story 6 — Compare a future API execution path (P2, evaluation deliverable)

Preserve a clean execution/provider boundary so a later approved API adapter can use the same inputs and validators without laptop account dependence.

**Independent acceptance**: A written evaluation recipe and adapter contract exist. A provider double proves contract portability; live cost or quality is not claimed without a real run.

An API replacement experiment, managed retrieval, parallel lesson generation or self-hosted model is a separate opted-in implementation slice. A fully hosted replacement, Custom RAG, KOS/KE ecosystem and LLM training are not mandatory implementation deliverables of Feature 021.

### Edge cases

- Worker closes before/after a provider call; server restarts during a claim or result commit.
- Claim, heartbeat, effect authorization, file upload or commit response is lost.
- Pairing token is expired/reused; credential revoked; teacher permission removed after admission.
- Duplicate submissions, two workers claiming at once, same mutation ID with changed bytes.
- Expired assignment with local unfinished output; notebook history is absent or mismatched.
- Provider throttles, requires login, changes response format, returns the wrong lesson or produces incomplete prompts.
- PDF warnings/encryption, unsupported input, corrupted download, wrong part ordering, truncated uploads.
- Cross-tenant file ID, archive traversal, absolute path, symlink, oversized content, active HTML and external network requests.
- Result supplied after cancellation; prompt changes before manual scene return; one scene fails while others succeed.
- No teacher sign-off; browser checks unavailable; full reviewed scene set not yet delivered.

## Requirements

### Functional Requirements

| ID | Requirement |
|---|---|
| FR-001 | The server MUST remain authoritative for teacher/subject access, captured settings, material identities, budgets, task assignment and canonical result state. |
| FR-002 | Hosted intake MUST reuse real inspection/preparation and idempotently materialize real accepted document/case/lecture/binding associations before task sealing. Queue waiting MUST NOT rely on expiring intake quarantine. This coordinator MUST need no provider session. |
| FR-003 | New preparation MUST use captured policy v2; the worker MUST NOT silently re-split, OCR-replace or re-render prepared input. |
| FR-004 | Admission MUST be idempotent and bind immutable execution mode `local_worker_v1`, output scope and protocol version to the existing run. |
| FR-005 | Local-worker runs and their derived cases MUST be excluded from all legacy source/map/lesson authoring dispatchers and recovery discovery paths; one run MUST NOT start through both routes. |
| FR-006 | The worker MUST require outbound HTTPS only and MUST NOT receive application secrets, hosted DB credentials, global internal HMAC keys, Docker socket access to the hosted machine or public Temporal access. |
| FR-007 | Device enrollment, scope changes, rotation and revocation MUST use explicit owner-controlled permissions; ordinary admins MUST retain operational-only visibility. |
| FR-008 | The device MUST use a dedicated revocable scoped identity; every task operation MUST revalidate device, tenant/run scope, assignment and control state. |
| FR-009 | Claims MUST atomically enforce worker capacity, device scope, one active assignment per phase, stable claim replay and monotonically increasing assignment epochs. |
| FR-010 | Every input MUST be obtained from a server-allowlisted endpoint and verified against exact byte size and digest before use. |
| FR-011 | All prompts, framework instructions, output contracts and selected configuration MUST be frozen as exact content artifacts, not reconstructed from whichever files are latest on the laptop. |
| FR-012 | The worker MUST use locally configured approved credential/profile aliases; no task may supply an executable command, arbitrary URL, secret path or provider credential. |
| FR-013 | A local durable journal and exclusive local process lock MUST survive process restarts; same-host locks MUST NOT be represented as multi-device fencing. |
| FR-014 | Every machine-executed provider mutation MUST have a unique logical effect key, linked attempt identity, exact request digest, durable pre-send fence and bounded server dispatch permit. Operator-performed external web generation is explicitly reported provenance, outside this machine-send guarantee. |
| FR-015 | Lease/control checks MUST precede each new external mutation. Lost authorization MUST stop fresh sends while permitting local capture of a response already in flight. |
| FR-016 | Uncertain provider effects MUST remain blocked until evidence-based reconciliation or explicit risk-aware recovery; a fresh lease MUST NOT reset send history. |
| FR-017 | Notebook creation, source upload, lesson requests, scene generation and conversation reset MUST follow the same uncertainty rules. Archive verification MUST precede any permitted history reset. |
| FR-018 | The worker MUST preserve raw provider responses and exact derived prompt text separately, with complete map, source and lesson/scene provenance. |
| FR-019 | Response parsing MUST reject detectable source/map/ordinal/title/marker association mismatches and malformed, incomplete or unsupported evidence. Semantic accuracy/coverage requires qualified review; structural matches MUST NOT be presented as a semantic correctness detector. |
| FR-020 | Result transfer MUST whitelist typed artifacts and exclude entire run-directory copies, credentials, browser state and arbitrary executable packages. |
| FR-021 | Artifact upload MUST be scoped, immutable, size-bounded and server-rehashed in quarantine before canonical acceptance. |
| FR-022 | Commit MUST validate all required references and captured associations and atomically record one authoritative result plus its receipt and durable follow-up work. |
| FR-023 | Same mutation ID and same bytes MUST return the original receipt; changed content under that ID MUST conflict. |
| FR-024 | Local paths or provider identifiers MUST NOT be used as server storage paths or accepted as proof of a provider-side file hash. |
| FR-025 | Teacher export MUST include every committed nonempty prompt in deterministic order; raw-accurate JSON and readable text must be available. |
| FR-026 | Manual scene return MUST bind actual files to current server-issued scene/prompt identities and preserve operator-reported provenance. |
| FR-027 | New scene candidates MUST enter existing validation/check/review gates, with no automatic approval, fabricated check evidence or automatic publication. |
| FR-028 | Submitted manifests, hash checks, a browser pass and educational correctness MUST be treated as different claims. |
| FR-029 | Pause, cancel, retry, revocation and reassignment MUST preserve prior immutable artifacts, effects and receipts. |
| FR-030 | Revoked teacher access, changed ownership or cancelled work MUST prevent new claims and canonical result acceptance even if an old device token still exists. |
| FR-031 | Progress counts MUST derive from server-accepted artifacts, not worker-supplied percentages or expected fixture totals. |
| FR-032 | Requests, bundles, pending tasks, disk usage and retries MUST have captured policy bounds. Provider quota exhaustion MUST hold work without account rotation intended to evade limits. |
| FR-033 | Unknown usage, provider identity, cost and timing MUST remain null/not-recorded; estimates MUST name their assumptions. |
| FR-034 | Hosted deployment MUST start without NotebookLM cookies or a Brave profile and MUST prepare PDFs without calling the laptop's provider bridge. |
| FR-035 | Local deployment MUST have separate configuration and persisted private working storage; it MUST not require copying hosted `.env` or mounting the hosted database. |
| FR-036 | Existing same-host mode and already captured runs MUST retain their behavior unless a separate explicit migration is implemented. |
| FR-037 | Model/API/managed-retrieval adapters MUST remain replaceable behind explicit contracts; unsupported adapters MUST report unsupported, never a simulated success. |
| FR-038 | Implementation MUST deliver the evidence matrix and distinguish code-complete, boundary-verified, provider-live-verified and production-ready states. |

### Key Entities

- **Device**: owner-issued identity, permitted scopes/capabilities, status, credential expiry, last seen; no provider credentials.
- **Processing job**: existing run association, immutable owner, requested output scope, execution mode, policy budgets and phase progress.
- **Stage task**: `author_prompts` or `return_scenes`, immutable task/input-manifest identity and required output contract.
- **Assignment**: device, phase, assignment ID, epoch, lease expiry and control version.
- **Provider effect**: stable logical operation, exact request digest, dispatch history, durable outcome and reconciliation references.
- **Artifact**: exact immutable bytes, SHA-256, byte count, content type, purpose, job/phase ownership and private retention state.
- **Result receipt**: idempotent authoritative commit, accepted output identifiers and actual derived counts.
- **Operator scene submission**: current prompt binding, real package, operator/provider-reported metadata and check/review lineage.

## Success Criteria

- **SC-001**: A teacher submits once while the worker is offline, retains the original and exact prepared inventory, and receives results after the worker reconnects without re-uploading.
- **SC-002**: An idle authorized worker discovers an eligible task within 30 seconds under healthy connectivity and an empty queue. Provider execution time is excluded.
- **SC-003**: At every tested interruption boundary, the system yields either one canonical result or a visible blocked/uncertain state; it never silently duplicates provider sends.
- **SC-004**: All transferred input/output artifacts match their captured byte count and SHA-256; corrupted, cross-owner and stale-version inputs are rejected.
- **SC-005**: A returned prompt set is nonempty, matches retained map/provenance, and exports exactly its accepted count; test counts are derived rather than hard-coded.
- **SC-006**: Revocation/lease loss prevents new dispatch authorizations immediately server-side and stops the worker at its next boundary; no claim is made to retract an external request already sent.
- **SC-007**: Every imported scene remains unavailable to students until the existing applicable qualification, human review and publication gates actually pass.
- **SC-008**: Hosted deployment contains no local NotebookLM session and the laptop cannot read the hosted database or impersonate a teacher.
- **SC-009**: Evidence labels identify every provider double, skipped live gate and unresolved failure. No unexecuted test or invented provider result is recorded as passing.

## Assumptions and release boundaries

- Pilot device concurrency defaults to one authoring task and one provider mutation at a time. More devices are supported by the protocol, not required for throughput claims.
- A working local provider session exists. Session refresh is an explicit local operator action; authentication cannot be guaranteed indefinitely.
- Local NotebookLM SDK use is a documented unofficial integration dependency, not a claim of official commercial API support.
- Teacher content processing rights and the chosen service's audience/data conditions must be settled for the pilot. Uploading via an account does not establish unlimited commercial automation rights.
- Pricing, cache TTL, measured usage, API spending and delivery promises are captured operational decisions, not hard-coded “free” assumptions.
- Existing <=20-original/100-MiB-per-original/1,000-pages-per-original/<=640-prepared-parts runtime limits are starting constraints; implementation must reconcile and consistently enforce actual current limits rather than widening them silently.
- The hosted/local protocol and mandatory manual-generation path are in scope. Building a new LLM, arbitrary consumer-site automation, immediate API migration, public deployment, paid benchmark execution and KOS/KE are not.
