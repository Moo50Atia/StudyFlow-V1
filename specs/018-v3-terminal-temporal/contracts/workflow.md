# Workflow, Activity and Recovery Contract

## Durable interfaces

Namespace `v3-terminal`. Workflow types `TerminalSupervisorWorkflow` and `TerminalSubjectWorkflow`; stable IDs are specified in [plan](../plan.md). No Workflow RetryPolicy. Child parent-close policy is REQUEST_CANCEL. Worker stop is not workflow cancel.

- Supervisor Update `submit_v1`: submission ID/digest/manifest ref plus bounded safe subject refs. Validator is side-effect-free; handler performs idempotent local registration before exposing accepted receipt and enqueues atomically. New queue admission <=64 and is closed during drain. Matching accepted submission returns its recorded receipt even during drain; conflicts reject. Duplicate registration never creates a second child. Main loop starts children, not asynchronous Update handlers.
- Supervisor Update `retry_scenes_v1`: original generation-root ref, selected safe scene refs, action, command/digest and recovery ID. Validate retained final-report/close proof, selected artifact eligibility and reserve one recovery owner through a local Activity. Enqueue a new linked subject under normal capacity, or reject without consuming a reservation if queue/drain conflicts. Reservation publication and its durable queue admission are recoverable through the index, not a fire-and-forget child start. Build-only recovery may target validated corrupt/missing output in a completed run without API credentials or a provider client.
- Subject Update `control_v1`: command ID, digest, action, expected version, selected operation/scene refs and duplicate-risk consent where permitted. Look up duplicates before version validation. Serialize asynchronous handlers at state mutation boundaries, wait for them before rotation, and persist receipts through Activities. Return requested/applied/rejected plus command/state version/safe result refs. SDK Update completion is not a substitute for the application receipt.
- Supervisor Update `cancel_queued_v1`: queued run ID, expected version and command identity; atomically persists cancelled_before_start registry state, applied cancellation receipt and cancelled report, then removes the queue entry idempotently without starting a child. This state has no child close proof and is excluded from bootstrap permanently. Launch reservation/cancellation use the same queued-state transaction fence; if launch already won, cancellation conflicts and targets the admitted subject afterward. CLI routes cancel here if no subject was admitted; pause/resume on an unstarted subject return control_conflict.
- Query `progress_v1`: bounded current projection; subject query `command_v1(command_id)` returns known command receipt. CLI addresses subjects through stable Workflow ID, not Run ID or a parent child handle. Offline fallback is specified in [CLI](cli.md).

Supervisor retains an occupied slot until final child-chain result (not first Run ID closure). One failed/partial child does not fail sibling chains. Blocked/paused children retain their slot. At 8,000 history events or SDK suggestion it drains, then continues with queue refs/index ref only after children and handlers finish. During drain rejected new requests do not create accepted subjects; status/subject controls remain available. Duplicate traffic may still extend history: at 12,000 events reject supervisor Update admission in its side-effect-free validator, preserve already accepted handlers, and report scheduler_draining. Already accepted duplicate receipt can then be read from the index/query. Do not forcibly rotate across open children; rejection in validation does not accept another Update into history.

Before child start, persist launch_pending in the subject registry; after confirmed start persist started; after final chain result/Describe persist settled and close proof. On unexpected supervisor closure, bootstrap loads that registry. Settled records with close proof need no Describe after history expiry. Describe only unresolved launch_pending/started chains; existing live chains count toward capacity and must settle through requested cancellation before fresh admission; unavailable/expired status without retained proof blocks bootstrap. Reconstruct queued subjects only, preserving IDs and acceptance_seq. Never reinterpret not_found as unstarted or blindly restart a previously started child. Administrative UI termination is outside normal controls and must be reported as a recovery blocker.

## Activity boundaries

Every Activity accepts `{schema_version,subject_run_id,run_ref,operation_ref,owner_epoch}` plus safe ordinal/scene ref when needed. Return `{outcome,safe_code,receipt_ref,result_ref,counts}` with nullable fields. Allowed outcomes: `complete`,`pending`,`blocked`,`failed`,`uncertain`; private payloads are not returned. Source readiness deadline/timer and Lesson/scene cursors live in the workflow.

| Activity | Effect and result |
|---|---|
| initialize_terminal_run | Validate immutable snapshots/settings, establish owner marker and initial projection; no external sends. |
| register_terminal_child / settle_terminal_child | Persist launch/start/terminal chain evidence in subject registry; settlement includes real close confirmation, not a fabricated report inference. |
| verify_notebook_session | Open isolated SDK copy, nonbrowser CSRF bootstrap, explicit proof ownership; save safe verification record. |
| create_terminal_notebook | Unique title contains full subject UUID; create once and retain binding receipt. |
| upload_terminal_source | Upload exactly one captured PDF/framework snapshot; save source receipt. |
| poll_terminal_sources | Read inventory/status for captured source refs; immutable ID/MIME/inventory validation; title alias change alone is not corruption. |
| configure_terminal_chat | Apply existing combined response-format instruction unchanged; read it back; reconcile uncertainty by exact settings equality. |
| send_terminal_turn | One Divide/Execution/Lesson request with captured source refs/history anchor; save exact answer/citations before return. |
| capture_terminal_turn | Parse map or best-effort export saved receipt, update local checkpoint idempotently; never asks a model. |
| freeze_terminal_handoffs | Validate every mapped ordinal captured, snapshot handoffs and initialize 017 scene inventory/config. |
| generate_terminal_scene | One resume-aware 017 provider attempt, raw receipt saved before normalization. |
| materialize_terminal_scene | Restore/parse retained receipt/source and save package; no generation request. |
| build_terminal_scene | Build retained source with 017 scaffold, own subprocess group and saved build evidence. |
| reconcile_terminal_operation | Read retained receipt and, for NotebookLM, inventory/anchored history; no mutation or resend. |
| record_terminal_projection / finalize_terminal_run | Idempotent local journal/status/final-report publication; summaries are computed from verified artifacts. |

Metadata Activities use control queue; external/session/poll/reconcile operations use IO queue; builds use build queue. Capture/parsing is local control work. Worker configuration is one owner process with global max4 IO/max2 build. No filesystem/network/random/time access inside workflow logic: generate identities before start or through Activities; use workflow clocks/timers and recorded Activity values.

## Send/receipt protocol

1. Under OS run lock and owner epoch, load captured operation inputs, check controls/ownership, compare hashes and look for a complete receipt.
2. If a complete receipt exists, return it without remote work. If a dispatched attempt exists without a receipt, return uncertain and schedule reconciliation instead. A mere prepared attempt is sendable only if its dispatch record was never committed.
3. Persist intent and pre-send inventory/history evidence; persist dispatch event/state; then call the provider once. Hold the applicable lock through response capture. Persist full raw receipt atomically and fsync it before recording result/returning.
4. If later parser/export fails, retain receipt and retry only local processing. If receipt cannot be persisted after remote success, remain uncertain and block future dependent dispatch.
5. A timed-out Activity may still run. No recovery owner may dispatch while its lock is held; no expiry-only lease takeover. Cancellation waits for bounded requests to settle, records whatever complete evidence arrives and prevents future operations. If request outcome remains unverifiable, cancelled report retains uncertainty.

Mutation retry policy is one attempt. Read/local transient operations use max4 (5s exponential coefficient2 capped60s); use typed auth/ownership/input errors as nonretryable. SDK transport/server automatic retries remain zero. Read-only failure exhaustion preserves phase/reason. Deadline and heartbeat defaults are authoritative in [plan](../plan.md).

## Reconciliation proofs

- **Create:** exact full-UUID title, captured account proof and exactly one matching notebook; zero matches after prior dispatch does not prove no effect. Multiple matches block.
- **Upload:** captured pre-send inventory plus exactly one new eligible source with expected MIME/title-or-filename alias; all known IDs unchanged and no unexpected extras. Same title elsewhere or unclear inventory is insufficient. The vendor cannot prove uploaded byte hash; record local byte hash and provider binding separately, not a fabricated remote hash check.
- **Format:** captured expected settings equal readback; otherwise remain uncertain until explicit retry of this idempotent setting operation, not an unrelated conversation change.
- **Chat:** retain conversation identity, ordered full question/answer/citation data or available stable turn IDs, count and hash of canonical pre-send history. Retrieval must provide the complete anchored prefix plus exactly one new pair matching expected question/source context and known conversation. For initial Divide, discover the created conversation via provider port; require the clean notebook's pre-send conversation baseline to make discovery unique. A truncated prefix, manual extra turn, changed prefix, multiple candidate conversations or unmatched response blocks. Never rely only on turn count/last Continue string.
- **Scene API:** only a complete retained 017 raw receipt/normalized result can prove a recoverable local response; absence has no remote query guarantee. Explicit uncertain generation retry appends an attempt and requires consent.
- **Build:** verify source and complete build manifest/hashes. Healthy output skips; retained source rebuilds in a new build ordinal. Corruption is recorded, not overwritten silently.

Uncertain NotebookLM chat is never resent in the same conversation, even with duplicate-risk consent: it could skip a Lesson. Retry reconciles read-only; if proof stays unavailable, cancel/start a new submission. Explicit uncertain create/upload retry can append a new attempt with consent after old sender release, retaining all prior IDs/evidence. Exact conflicting history or changed input is not overrideable.

## Operator state behavior

Pause/cancel records requested intent immediately but applies at a settled operation boundary; for builds, cooperatively cancel the owned process group (SIGTERM, then SIGKILL after5s if necessary). Await process reaping and save cancellation evidence. API calls are not treated as physically rolled back. `resume` reboots isolated credential state for needed NotebookLM phases and rechecks captured account/inputs/settings; pending dispatched work still reconciles first.

Generation auth/rate failures block the subject with safe code; explicit resume continues unattempted scenes and leaves the failed attempt untouched unless selected for retry. Scene-specific request/package/build errors continue later scenes and finish partial. New submissions cannot change settings of a captured run. Partial-run generation recovery, or build-only recovery of validated damaged output from a partial/completed run, creates a new Workflow ID/report over retained inputs/source and appends 017 attempt/build ordinals under transferred ownership epoch. Build-only recovery creates no provider client and requires no API/NotebookLM credentials or original handoffs; valid retained source/scaffold/toolchain are required. Original report remains immutable.

## History, data and errors

Activity inputs/results, errors, memo and indexed fields contain only safe logical identifiers/refs/counts. Maximum serialized input/result is32KiB; page large source/scene inventories through artifact refs. SDK errors are normalized before Activity failure; do not serialize raw_detail, chained SDK exceptions, HTTP bodies or provider text. Native history is execution evidence; detailed journal events are separate. Workflow logging uses replay-aware logger; journal writes occur only in idempotent Activities.

Safe codes: ok, input_invalid, input_changed, ownership_conflict, lock_busy, authentication_required, account_mismatch, provider_rate_limit, provider_unavailable, delivery_uncertain, history_conflict, source_error, source_timeout, map_unreadable, storage_unavailable, receipt_invalid, artifact_missing, provider_request, source_package_invalid, build_failed, build_cancelled, config_drift, control_conflict, control_pending, recovery_busy, queue_full, scheduler_draining, worker_unavailable, temporal_unavailable, supervisor_recovery_required. Carry retry-after only as a nullable numeric field; do not use error-string searches to decide retry.
