# Hosted / Local Worker Protocol v1

**Status**: Normative design, not an implemented or verified API.  
**Version**: `local-worker.v1`  
**Boundary**: Hosted Laravel control plane ↔ outbound local worker. All payload examples below are structural examples; UUID/digest placeholders and counts are not execution evidence.

## 1. Roles and trust boundary

The hosted service owns access, intake, immutable run/settings snapshots, ordered PDF preparation, queues, assignments, budgets, authoritative artifacts, import, review and publication. It may run private CPU preparation/checking services. It does not hold NotebookLM sessions.

The laptop owns provider session state, its private journal/checkpoints, real provider interaction and optional local checking. It can return evidence and candidate content only for assigned work. It cannot select a different destination teacher, mutate captured inputs, publish, approve, change its own grants or submit arbitrary server commands.

All laptop traffic is initiated outbound to a configured HTTPS API origin. No inbound laptop listener, VPN, SSH tunnel, public bridge, shared volume, remote DB mount or public Temporal endpoint is part of this contract. HTTP loopback is permitted only in explicitly labeled local acceptance environments.

A teacher submission captures `execution_mode=local_worker_v1`; the existing same-host bridge dispatcher and all generic source/map/lesson dispatchers/reconcilers MUST skip authoring for that run and its derived cases. Before executable task sealing, a provider-free hosted admission coordinator MUST idempotently complete real intake via `AcceptRunItem`/`IntakeCoordinator` and binding-shell preparation via `DirectSourceCoordinator::prepare`. It MUST retain accepted bytes outside expiring upload quarantine, recover lost intake acknowledgments and fail closed on missing associations. These server-only domain operations do not call Google. Preserve current staged/accepted gating so generic discovery cannot launch a second workflow. Existing modes remain unchanged. The worker MUST NOT use legacy internal callback endpoints or global HMAC secrets directly. Add a narrow external façade and server-side domain services.

## 2. Identity, enrollment and scope

1. The platform owner, with explicit `manage_processing_devices` permission, creates a pairing invitation scoped to permitted tenant/owner IDs and server-approved capability IDs.
2. Pairing material is random >=256 bits, single-use, expires after 10 minutes, shown once and stored hashed server-side. It is not placed in a URL or logs.
3. `POST /enrollments/redeem` exchanges it for a device ID and opaque device token. Failed/expired/reused invitation is rejected. Redemption is rate-limited and transactional.
4. Device tokens have >=256 bits entropy, a default 30-day expiry, hash-only server storage, explicit revocation and rotation. CLI secret entry MUST be masked; no token in process arguments, examples, browser URLs or reports. Local storage uses OS credential storage or a non-symlink owner-only file.
Enrollment acknowledgment loss is a deliberate exception to replaying a credential-bearing success: the server never retains plaintext device tokens for replay. Repeated redemption reports the invitation as consumed without issuing another credential. If the client did not durably retain the returned token, the owner revokes that orphan device/invitation and creates a fresh invitation. Never silently create a second device or reissue a secret under the consumed token.

5. Each request authenticates the device and revalidates current scopes, capability enablement and expiry. Rotation/revocation cannot refresh an assignment epoch or authorize a new tenant automatically.
6. `execute_processing` is a separate content-bearing permission, scoped to assigned jobs. The current platform-owner predicate is centralized by `LearningAccess`; do not broaden all admin roles. Owner/operator actions, authorization changes and affected job IDs are audited without content.
7. Pairing grants the operator access to selected teachers' submitted materials for processing. Teacher intake/organization authorization must disclose and permit that delivery mode. A task cannot be claimed without this server-side grant.
8. Local provider aliases map to an operator-configured allowlist. A hostname, filesystem path, profile name from an untrusted task, or server-supplied shell command is never executed.

## 3. Common envelope and error rules

Base prefix: `/api/v3/local-worker/v1`. This is a proposed new route group. It is distinct from `/api/internal/...`.

Authenticated requests use `Authorization: Bearer <device-token>`. Task writes additionally include `X-Assignment-ID`, integer `X-Assignment-Epoch`, and the task's `input_manifest_sha256`. Task-state changes include an expected `control_version`. Every mutation carries `Idempotency-Key: <UUID>`; the exact raw body SHA-256 and resource/operation identity are part of its stored digest.

Every JSON control/result envelope has integer `schema_version: 1`. Raw input/output streams are explicitly exempt: they retain exact content bytes, use the reserved media type and expected size/digest metadata, and MUST NOT be wrapped or rewritten to add an envelope. IDs are opaque server-issued UUIDs except locally generated mutation IDs. Timestamps are RFC3339 UTC. Byte counts are nonnegative bounded integers; content artifacts required to be nonempty use strictly positive bounds. SHA-256 is lowercase 64-hex. Strings, arrays and JSON depth have explicit server bounds. Unknown or duplicate JSON keys, invalid UTF-8 and unsupported schema versions are rejected rather than coerced.

- For post-enrollment task mutations, an identical retry returns the retained receipt/status without reapplying state. A reused key with a different digest returns `409 idempotency_conflict`.
- Idempotency lookup must still require valid device identity and job authorization. A completed write can return its old receipt after lease expiry to the same authorized device; no new writes are authorized thereby.
- `401`: missing/expired/invalid credential; `403`: revoked/denied capability. Inaccessible task/artifact identities return the same non-enumerating `404` as absent ones.
- `409`: epoch/control conflict, changed input, pending reconciliation, wrong current prompt or immutable artifact conflict.
- `410`: an intentionally expired staged upload; never silently reinterpret a known accepted receipt as missing.
- `413`: bounds exceeded; `422`: structurally valid but unacceptable result; `429`: rate limited with `Retry-After`; `503`: dependency unavailable.
- Safe error codes include `authentication_required`, `provider_rate_limited`, `provider_unavailable`, `input_changed`, `artifact_mismatch`, `wrong_topic`, `incomplete_result`, `lease_lost`, `control_changed`, `reconciliation_required` and `unsupported_capability`.
- No raw provider exception, account identity, notebook ID, source text, filename, prompt or secret in routine error bodies. Detailed evidence is private and separately authorized.
- Read requests and idempotent transport mutations may retry with exponential backoff/jitter capped at 60 seconds; respect `Retry-After`. This does not authorize replay of an external provider mutation.
- Default control-plane timeout is 10 seconds. Claim long-poll waits at most 25 seconds; otherwise idle polling uses <=25-second intervals with jitter. A claim request timeout must exceed its long-poll allowance.

## 4. Stage tasks, claims and leases

A **job** belongs to one existing teacher run. Its output scope is `prompts_only` or `prompts_and_scenes`. Stage tasks use:

- `author_prompts`: automatic download → real NotebookLM processing → exact authoring artifacts and prompt commit.
- `return_scenes`: manual operator-generated packages returned against server-issued current scene/prompt IDs. This does not run authoring again. A later approved generation adapter may consume the same stage contract, with a distinct declared capability.

After an authoring commit, `prompts_only` finishes at the application's existing prompt-export outcome. For `prompts_and_scenes`, prompts are available and the job is explicitly `awaiting_generation`; it is not fully delivered. The authoring lease closes rather than staying open while a human generates files. New scene-return assignments get their own immutable stage manifest and epoch. Per-scene return may be partial; completeness derives from the captured required scene set and accepted candidates. A technically returned candidate is distinct from approved/published educational content.

### Endpoint inventory

| Method/path | Required semantics |
|---|---|
| `POST /enrollments/redeem` | Single-use pairing exchange. |
| `GET /device` | Identity, enabled capability versions, scopes and safe server time; no secret echo. |
| `POST /claims` | Atomic eligible task claim; request contains claim UUID, protocol/capability versions, available slot count and optionally an authorized task ID for scene return. |
| `GET /claims/{claim_id}` | Recover a lost claim acknowledgement for this device. |
| `GET /tasks/{task}/assignment` | Return only this device's assignment/control status or its retained terminal receipt. |
| `POST /tasks/{task}/heartbeats` | Renew an active lease and acknowledge observed control version. No content-bearing progress. |
| `GET /tasks/{task}/input-manifest` | Stream exact sealed manifest bytes and digest. |
| `GET /tasks/{task}/inputs/{artifact_id}` | Stream a manifest-listed input, with authorized range support. |
| `POST /tasks/{task}/effects/reserve` | Create/replay a logical provider-effect intent; no external call authorized yet. |
| `POST /tasks/{task}/effects/{effect}/dispatch` | Commit the pre-send dispatch fence and return explicit permission for the same active assignment. |
| `POST /tasks/{task}/effects/{effect}/outcome` | Submit actual outcome/evidence refs; a receipt alone does not establish result acceptance. |
| `GET /tasks/{task}/effects/{effect}` | Read retained effect receipt, dispatch history and permitted recovery state. |
| `POST /tasks/{task}/uploads` | Create/replay bounded immutable artifact staging slot with expected digest, size, purpose and type. |
| `PUT /tasks/{task}/uploads/{upload}` | Stream bytes to the scoped staging slot; duplicate exact bytes succeed, changed bytes conflict. |
| `GET /tasks/{task}/uploads/{upload}` | Recover durable received/verified status. |
| `POST /tasks/{task}/results` | Validate and commit a result or partial scene-return batch atomically and retain receipt. |
| `GET /tasks/{task}/results/{result_id}` | Recover a lost result acknowledgement. |
| `POST /tasks/{task}/release` | Release only if no potential external effect exists, or finish a server-verified safe boundary. |
| `POST /tasks/{task}/recoveries` | Submit reconciliation evidence; never mint a fresh effect ID as a retry shortcut. Risk-bearing authorization is an owner action on a separate authenticated application endpoint. |

Owner pause/resume/cancel/revoke/reassign UI calls use existing web user authentication and dedicated permissions. Device tokens cannot approve duplicate-risk overrides, enrollment grants, teacher review or publication.

### Claim response

A successful claim returns `task_id`, `job_id`, `run_id`, `task_kind`, `assignment_id`, `assignment_epoch`, `control_version`, `lease_expires_at`, `server_time`, `input_manifest_sha256`, allowed artifact IDs and captured resource budgets. No work returns `204`; it is not a failure or a fabricated completed task.

Claim idempotency is durable: the same claim ID cannot acquire a second task after a lost response. New claims need new IDs only after the old claim receipt is known. Database-atomic compare-and-set/locking enforces one active stage assignment; cache entries are never the ownership authority.

Default heartbeat: every 15 seconds; default lease: 120 seconds. The worker must obtain a successful heartbeat within the last 30 seconds and have >=30 seconds lease headroom before requesting new dispatch permission. Server time is authoritative. Each dispatch permit additionally expires within 10 seconds (and before lease expiry), binds a worker process incarnation and attempt, and is checked again immediately before sending as specified in section 6. A provider request can outlast a lease: the worker must continue independent heartbeat/control communication, not block the heartbeat loop on inference.

### State transitions and recovery

| State | Meaning / permitted next step |
|---|---|
| `queued` | Inputs sealed; eligible scoped worker can claim. |
| `leased` | Claimed, downloads/preflight may run. |
| `running` | Provider effects and checkpoints may proceed under current fence. |
| `paused` | No new external mutations; preserve accepted outputs and pending evidence. |
| `auth_required` | Explicit local session refresh required; no automatic login/profile harvesting. |
| `rate_limited` | Hold until allowed retry time; no account switching to evade quota. |
| `disconnected` | Heartbeat/lease lost; new sends prohibited. Classify pending effects before assignment. |
| `reconciliation_required` | A provider effect may have occurred; cannot safely replay/reassign automatically. |
| `committing` | Verify stage result; stage completion not yet a teacher approval. |
| `completed` | Required stage result committed; parent delivery scope may still await scenes/review. |
| `failed` | Known safe failure; any new attempt must respect prior effect fences. |
| `cancelled` | No new processing/authoritative results; retain immutable prior evidence. |

Lease loss with no authorized/dispatched effect and a verified clean local boundary may be safely requeued. Once a potential external mutation exists, lease expiry does NOT make the work safe to reassign. Default recovery is the same device and retained journal after fresh authorization. The owner uses an authenticated application `begin-reconciliation` command to create a **reconciliation-only assignment** for the intended scoped device, with a fresh epoch and the same immutable task/effect/checkpoint identities. A device can request recovery but cannot grant itself that assignment. This assignment permits private evidence upload/receipt lookup and read-only provider reconciliation; dispatch endpoints reject it. Once evidence has resolved every pending effect and the owner explicitly resumes, the coordinator may issue a new execution assignment/epoch while retaining the ledger. An unresolved unknown effect stays held unless an explicitly risk-authorized recovery attempt is created. A new epoch never clears send history.

A stale worker may capture in-flight output locally. V1 does not permit stale-epoch uploads or commits. After successful controlled recovery under a fresh assignment, it can upload those retained bytes as reconciliation evidence. Revoked devices must use owner-mediated offline evidence recovery; an expired/revoked token is not allowed to upload to bypass revocation.

Pause/cancel/revocation races are resolved transactionally against dispatch authorization and result commit. Already authorized external calls cannot be recalled; outcome may be retained privately, but new canonical progress is subject to current controls. Cancelled work is not automatically resumed or deleted from the provider.

## 5. Sealed input manifest

The server stores the exact UTF-8 JSON bytes once, calculates SHA-256 over those bytes, and returns that digest through the authenticated claim. No JSON parse/re-serialization is used to verify a digest. The digest is outside the bytes it hashes, avoiding self-reference.

Required fields:

| Field | Contract |
|---|---|
| `schema_version`, `kind` | `1`, `local_worker_input_manifest`. |
| `task_id`, `job_id`, `run_id`, `task_kind` | Server-issued immutable stage associations. |
| `execution_mode`, `output_scope` | `local_worker_v1`; captured scope. |
| `protocol_id`, `protocol_version` | Exact source-processing protocol contract, including supported mapped-lesson request behavior. Unsupported version blocks before provider sends. |
| `settings_snapshot_id`, `settings_sha256` | Existing immutable version association. `settings_sha256` binds the original server snapshot; a separate manifest-listed `runtime_settings` artifact contains only explicitly allowlisted non-secret fields with its own digest. The worker verifies that portable artifact, not hidden settings bytes it has not received. Never serialize an entire settings record containing provider keys. |
| `preparation` | Policy version, trigger/max sizes, inventory artifact ID/digest and ordered original→part lineage. |
| `items[]` | `item_id`, original identity/hash/byte count/page count, upload ordinal, source/part ordinals, inclusive original page range, prepared artifact ID/hash/bytes/pages. |
| `instruction_artifacts[]` | Framework, exact model system/authoring templates, output contract and instruction version refs with hashes. |
| `artifacts[]` | Opaque artifact ID, purpose, size, SHA-256, media type and a safe manifest-relative logical name. No local absolute storage path or arbitrary URL. |
| `required_outputs`, `resource_budget` | Stage contract/profile, maximum provider effects/output sizes and allowed scope; server captured, not chosen by worker. |
| `capabilities` | Versioned required capability IDs mapped by installed local allowlist, not plugin code or arbitrary configuration paths. |

For `return_scenes`, the manifest instead/additionally binds each expected `scene_id`, `prompt_version_id`, exact prompt SHA-256, body artifact, template artifact/hash and package profile. The worker exports exactly this content for manual generation. A current-prompt change closes the old immutable return task to new commits. Accepted receipts/candidates for unaffected scene/prompt bindings remain retained and current under existing domain rules. The coordinator issues a new stage task for only the remaining current required bindings, retaining old receipts as lineage; changed scenes do not inherit old approval. A mixed batch containing one stale binding is rejected atomically; unaffected unaccepted submissions can be repackaged against the new task without regeneration. Never silently rebind bytes to a new prompt.

Inputs preserve original and prepared source bytes and page order. Worker verifies the existing inventory, including no missing/duplicate ranges, and MUST NOT feed prepared parts into the old CLI's automatic preparation a second time. NotebookLM source-selection scope must use the active prepared part and the captured framework as required by the protocol.

Originals may be retained server-side without being downloaded when the task only requires prepared parts; any optional original download remains authorized and hashed. Server preparation/antivirus failures never become executable tasks.

## 6. Provider effect journal and dispatch barrier

Server leases fence app state; a local provider ledger fences sends. Neither guarantees globally exactly-once inference by a third party.

The server derives a unique `logical_effect_key` from job/stage/operation kind/PDF and expected lesson or scene identity/captured protocol and content versions. Reserving the same logical key under a fresh client UUID MUST return the same logical effect or conflict, never bypass its fence. The server-issued `effect_id` is stable across transport retries and recovery epochs. It binds job/stage, operation kind, PDF ordinal, lesson/scene ordinal where applicable, protocol/instruction hashes, exact request body digest, source identities and intended provider profile alias. Effects include create notebook, upload framework/PDF, configure mutable provider state, ask each mapped lesson, clear history and approved generation calls. Read-only polling is separate.

Each logical effect has server-issued `attempt_id`, monotonically increasing attempt number, optional `previous_attempt_id` and authorization origin. There is at most one dispatched attempt without a settled outcome. Attempt states are `reserved -> dispatching -> succeeded|failed_no_effect|unknown`; auth/quota holds classify the attempt evidence rather than erase it. An `unknown` attempt cannot be overwritten by a later success under a different UUID. In v1, any new mutation attempt after a settled failure requires an explicit owner/operator retry command; transport/read/local-parse retries are different. An unknown-effect retry additionally requires the recorded duplicate-risk authorization.

Required sequence:

1. Persist and fsync local intent and exact non-secret request bytes before network dispatch.
2. Reserve/recover the same effect ID server-side. Changed digest conflicts.
3. Ask the server to enter `dispatching` under the current lease and control version. The server commits that fence before returning an explicit permit containing `permit_id`, `effect_id`, `attempt_id`, device/task/assignment/epoch, worker process-incarnation ID, `issued_at` and `send_not_after` (at most 10 seconds after issue and before lease expiry). Lost/retried control-plane requests are idempotent, but a returned `dispatching` record alone never tells a restarted process that it should send again.
4. Immediately before the external send, verify the same uninterrupted process incarnation, fresh heartbeat, valid current lease and unexpired permit. Derive a conservative monotonic deadline from the authenticated server-time/round-trip estimate; ambiguity, suspension, changed boot ID or late permit means no send and reconciliation. Then atomically/fsync mark the attempt `may_have_been_sent`, hold the local exclusive lock, and issue at most one provider call from that path. A restart or lost permit acknowledgment never reuses the permit to send.
5. Save the actual response or known failure locally durably before attempting outcome upload. Save exact response bytes and real provider metadata if present.
6. Submit outcome evidence. Server checks the ledger and current task authority. Completion of an effect does not bypass result validation.

If a crash/acknowledgement loss occurs after the dispatch fence but before the exact outcome is durable, treat it as potentially sent. Do not infer “not sent” from a timeout, missing chat message, missing usage record, expired lease or absent local file.

The dispatch guarantee applies to mutations performed by the worker or an approved machine adapter. Operator web generation is performed externally after prompt export, possibly without an active scene-return assignment. It has no machine effect/permit receipt and no invented API metadata; later upload is authorized and fenced by the scene-return assignment. This explicit boundary is required for the mandatory manual-generation path.

Possible outcomes: `succeeded`, `failed_no_effect` (only with positive evidence), `unknown`, `authentication_required`, `rate_limited`. A 5xx/timeout is ordinarily `unknown` for a mutation. Disable opaque SDK mutation retries and test actual outgoing send counts with a transport double.

Reconciliation is read-first, comparing source scope, exact question/request digest, expected history prefix/index and retained provider response. A unique source title is association evidence, NOT proof of uploaded bytes. Multiple matches or insufficient evidence stay blocked. Reconciliation does not reset `send_count`.

For destructive provider history reset, v1 follows only the captured protocol, never an improvised alternative. If that protocol requires resets between parts, hosted admission MUST require an explicit owner-configured `allow_history_reset_after_verified_archive` authorization before admitting a multi-part job. Without it, readiness rejects that incompatible mode before any processing, or the owner selects a separately specified non-destructive protocol. A single-part job needs no between-part reset. Every permitted reset requires a verified durable archive and the full pre-send reset journal. Never silently delete history just to make a failing parse pass. This feature's planning request is not permission to execute a live destructive reset during implementation acceptance.

An owner-authorized risk recovery records actual prior effect IDs, reason, evidence and explicit duplicate-risk acknowledgment; the old owner must be stopped/revoked as appropriate. Any genuinely authorized resend is a new attempt linked to the old logical effect, not an erased old attempt. Implementers must not use that override automatically.

## 7. Artifacts, transfers and bounds

Do not tar/zip a full terminal root: it contains credentials. V1 prefers manifest-listed individual artifact streams; any optional portable archive must have a strict allowlist and equivalent bounds, no symlinks/hardlinks/devices/absolute paths/traversal, no duplicate names and an enforced expanded-size cap.

- Server-issued input streams support bounded range download. Resume checks total digest before use.
- Output metadata is reserved first. The stream carries exact expected bytes and SHA-256; the server computes its own digest and rejects mismatch/truncation. No arbitrary server path is accepted.
- V1 may retry a failed output stream from byte zero. Chunk/resume uploads are optional; claims of resumability require implementation and tests. Partial staging is never a canonical artifact.
- Download URLs are derived from the configured API origin and opaque IDs. Cross-origin redirects are refused; tokens never follow untrusted redirects.
- Artifacts are private, immutable and content-referenced. Uploading the same artifact again with the same binding/bytes returns its original receipt; replacing an accepted blob requires a new artifact ID/version.
- Default control JSON cap: 256 KiB. A result manifest cap: 2 MiB; educational payloads are separate streams. Bounded raw-turn artifact cap: 16 MiB, prompt-export artifact cap: 64 MiB, total authoring output budget: 512 MiB per task. Server MUST reject admission or hold before exceeding the captured budget; never truncate educational text into a successful artifact. Larger supported limits require an explicit versioned policy, not worker discretion.
- Current input bounds remain <=20 originals, <=100 MiB/original, <=1,000 pages/original, <=640 final parts. These are limits, not demonstrated throughput promises. Download storage quota is checked before claim and rechecked as artifacts grow.
- Staging uploads expire after 24 hours by default. Uploaded staging MUST NOT be pruned while referenced by an in-progress result validation transaction; acceptance atomically promotes/retains it. Abandoned staging cleanup is distinct from deleting accepted source/evidence. Accepted artifacts follow the existing retention policy; V1 adds no destructive purge automation.

## 8. Authoring result contract and atomic acceptance

A result manifest contains `schema_version=1`, `kind=local_worker_authoring_result`, `result_id`, task/job/run IDs, input-manifest digest, exact assignment identity, protocol/settings/preparation hashes, artifact references and ordered PDF/lesson/scene records.

Required typed artifacts:

1. Original provider response evidence for each completed mapping/execution/lesson operation, with digest, safe linkage to effect receipt and selected source identity. Raw response is stored separately from parsed projections.
2. Ordered lesson map and parsed lesson handoffs, preserving their original raw-answer references.
3. Nonempty exact scene prompts with per-PDF/lesson/scene ordinals, prompt digest and generation-context/template association.
4. Verified per-PDF archive and export inventory. Exports use a versioned typed format; distinguish terminal export `v3_notebooklm_scene_prompt_export` from Laravel `prompts[]` shape. A validated adapter maps the actual fields, not an untyped “find some prompts” parser.
5. Safe actual execution/provenance metadata. Provider IDs and content live only in private evidence, not task event metadata.

Completeness is set-based: require exactly the captured prepared-item set; unique contiguous lesson ordinals matching each retained map; unique `(item_id, lesson_ordinal, scene_ordinal)` tuples; exact raw-response-to-parsed-handoff-to-export prompt byte/digest correspondence under the captured parser; and no extra/missing/duplicate records or contradictory declared totals. If the parser cannot establish this lineage, hold the result. Detectable wrong source/ordinal/title/marker associations fail deterministically. Semantic relevance or missing concepts within otherwise consistent content require a declared qualified-review gate; neither matching hashes nor a model-generated title establishes educational correctness.

The server MUST independently verify downloaded input hashes already captured, every uploaded artifact's bytes/hash, complete non-overlapping source lineage, supported protocol, retained effect links, exact map ordinal/topic relationships, nonempty expected outputs and captured output budgets. Prompt bytes are never repaired or invented to satisfy a count. A structurally valid export alone does not prove full source/map lineage or conceptual coverage.

In one fenced authoritative transaction the server assigns/resolves actual application lesson/section/scene/prompt IDs from the captured teacher/run/item/case associations; writes durable accepted result/receipt and follow-up outbox; derives counts from accepted records; advances the stage. Storage objects must be verified durable before that transaction, with references and immutable manifest bytes protected against substitution. No cached state or worker-supplied `owner_id`, `approved=true` or `status=completed` can override domain checks.

Missing source documents, owners, settings, byte/page counts, bindings or response evidence are errors. Specifically forbid fallback owner `1`, settings `first()/1`, source hash `hash('doc-item')`, byte count `1024`, page count `1` or invented source rows. Current internal acceptance code has such fallbacks; it must be hardened/restricted for this route before it can be reused.

A commit receipt returns result ID, actual authoritative identifiers/counts and stage status. Lost acknowledgment is handled through receipt lookup or identical retry, not reauthoring. Partial authoring checkpoints may persist privately, but they do not mark an entire stage complete. Mandatory V1 commits a complete authoring stage; streaming per-lesson canonical commits are a later explicit contract.

## 9. Scene return and qualification

The worker's scene-return command acquires an authorized `return_scenes` task and submits a result manifest bound to frozen current prompt identities. Existing teacher import authorization is not bypassed by logging the worker in as a teacher; use an explicit service-principal command authorized against the captured owner and task.

The scene result envelope has `schema_version=1`, `kind=local_worker_scene_result`, UUID `result_id`, `task_id`, `job_id`, `run_id`, `assignment_id`, integer `assignment_epoch`, current `control_version`, `input_manifest_sha256` and nonempty `submissions[]`. Each submission contains a stable `submission_id`, `scene_id`, `prompt_version_id`, `prompt_sha256`, `package_artifact_id`, `package_sha256`, and `provenance` with `mode=manual_web|approved_api`, operator/device association, reported provider/model/effort fields or real API receipt refs, and explicit measurement status. Unsupported provenance fields/unknown identities fail. The destination teacher/case is resolved from the task, never selected in the envelope.

A scene-return batch is validated and committed atomically: either all rows are valid/idempotent or no new candidates are accepted. Partial delivery across valid batches is allowed; absent scenes remain pending. Server uniqueness on current scene/prompt/package digest reuses the same candidate receipt even under a new batch/result UUID. Deliberate changed-content replacement requires a new explicit revision command and expected current candidate/version; preserve ancestry and invalidate stale checks/review under existing rules. The stage only completes when every required current binding has an accepted candidate or an explicit authorized terminal disposition; a failed/waived/missing scene is never counted as delivered success.

Mandatory package profile: `static_three_file_v1`, with `schema_version:1` and exactly:

```json
{"schema_version":1,"files":[{"path":"index.html","content":"<actual UTF-8 HTML>"},{"path":"styles.css","content":"<actual CSS>"},{"path":"script.js","content":"<actual JavaScript>"}]}
```

This is a schematic example, not generated scene content. Each file <=1 MiB; package decoded content <=4 MiB; no empty file, NUL, invalid UTF-8, duplicate/disallowed path or unknown package fields. The transport may stream this JSON artifact under its own bounded byte cap. Do not revive the superseded terminal React/TypeScript generation format. A standalone HTML converter, if added, must be deterministic, preserve the original and transformation provenance, and actually pass the current package contract; absence of CSS/JS is not permission to fabricate files.

Manual web output has origin `manual_import` plus actual worker/operator/task provenance. Provider/model/effort values are reported, not independently API-verified. An optional approved API adapter records real returned metadata, usage and attempts; SDK instrumentation must not infer them from the model selected in a UI filename.

Server stages and verifies candidate bytes, then records the real candidate as `awaiting_check`. Qualification must run actual isolated checks; local untrusted “pass” flags are not sufficient. Existing checks cover integrity, entrypoint, assets, runtime, network, interaction and accessibility. Their actual report version, bytes, timestamps and screenshot must satisfy the current checker contract. Browser success is not educational approval. Existing manual/demo gates remain `demo_review_ready` with an explicit `owner_waived_demo` basis; real qualification needs the actual JEV path. If production policy forbids demo waiver, the candidate stays held pending real qualification.

Teacher preview, current candidate/prompt fencing, explicit human review and separate publication remain mandatory. Worker completion can never set publication, write approval rows, invent a reviewer, bypass first-frame proof or set a legacy dynamic scene URL.

## 10. Observability, usage and cost

Operational events are sequence-numbered, bounded and content-free: job/task/assignment opaque IDs, safe state/code and actual accepted counts. Canonical event ordering is server-issued. Progress duplicates are idempotent; out-of-order events cannot roll state back. Worker-reported counters cannot become accepted completion counts without result validation.

Measured usage fields name provider response/request ID when present, returned model, input/cache/output/thinking tokens and measurement origin. Unavailable values are null with `measurement_status=not_recorded`. Manual web generation may report operator elapsed time and subscription context, but cannot claim an API bill of zero. A calculated cost is labeled an estimate and records price source/date, token assumptions and exclusions. No hard-coded 63/89/11 outputs, zero cost, fake delay or random token metrics.

No PDF/provider answer/scene prompt/source filename/notebook ID in ordinary server logs, queue/Temporal history, metrics labels or admin operational summaries. Those systems carry opaque private artifact IDs. Device secrets and Google cookies are excluded even from detailed evidence exports.

## 11. Versioning and release

Unsupported protocol/capability versions fail before sending data to providers. Additive fields require an explicit compatible schema policy; mandatory unknown fields fail. Store the original manifest bytes and selected protocol/schema version for all replays. Do not rewrite historical snapshots during upgrades.

The implementation must provide machine-readable validation schemas derived from these field tables before wiring request handlers; examples are not substitutes for validators. Contract tests must prove PHP/Python agree on UTF-8, duplicate keys, exact-byte hashing, bounds and conflicts.

Hosted and laptop configurations, startup, stop, health, credential refresh, recovery, backup and rollback are separately documented. No service, upload, provider call or public deployment was executed as part of writing this specification.
