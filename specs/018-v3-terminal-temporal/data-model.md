# Data Model: Terminal Execution and Evidence

Logical records below are local operational/artifact records, not Laravel entities. `schema_version=1` is required in persisted JSON and public CLI JSON. UUIDs are lowercase canonical UUID strings; hashes are lowercase SHA-256 hex; ordinals/bytes/counts are nonnegative integers excluding booleans (Lesson/scene/source ordinals start at 1). Timestamps are UTC RFC3339. Optional/unknown measurements are null, never invented zero.

## Storage layout and references

Default root: `storage/app/private/v3/terminal-pipeline/`.

```text
index.sqlite                              # submission/control/recovery index + event journal
worker.lock                               # one local worker installation
submissions/{submission_id}/manifest.json # immutable captured manifest
submissions/{submission_id}/inputs/...    # immutable exact-byte input snapshots
runs/{subject_run_id}/run.json            # captured configuration, safe local bindings
runs/{subject_run_id}/.execution-owner.json
runs/{subject_run_id}/.run.lock
runs/{subject_run_id}/operations/{operation_id}/attempt-{n}/...
runs/{subject_run_id}/notebook/checkpoint.json
runs/{subject_run_id}/notebook/lesson_handoffs/lesson-{ordinal:04d}.json
runs/{subject_run_id}/scene_generation/... # unchanged 017 artifacts/attempt/build layout
runs/{subject_run_id}/status.json          # replaceable last-known projection
runs/{subject_run_id}/report.json          # immutable final summary per execution
```

An `ArtifactRef` is `{path,sha256,bytes}` relative to the terminal root; no absolute user path, `..`, symlink escape or secret is allowed in a Temporal reference. The full local manifest may retain original paths/titles and remote IDs privately. Resolve refs under root and verify hashes before use. The only replaceable refs are explicitly named status projections, which have a projection version; operation evidence and final reports are immutable.

Credentials are outside the artifact tree: default private directory `~/.notebooklm/v3-terminal-sessions/{subject_run_id}/storage_state.json`, directories 0700/files 0600. Existing `V3_NOTEBOOKLM_STORAGE_STATE` is the master input. Snapshot metadata stores a nonsecret revision UUID, not cookie values/digests. SDK writes only the subject copy. Resume renews the copy under lock after closing all old clients. Runtime refresh command settings are disabled. No credential copy enters exports or build environments.

## SubmissionManifest

Fields: `schema_version`, `submission_id`, `created_at`, `subjects[]`; each subject has unique `subject_key`, generated `subject_run_id`, `mode` (`new`/`saved`), `display_title`, ordered input snapshots, selected framework/template refs, provider alias/ID/model/endpoint/timeout/toolchain, and `account_alias`/proof-notebook binding for new mode. These are captured before submission; keys/credentials are never serialized. `manifest_sha256` hashes canonical JSON (UTF-8, sorted keys, compact separators, no NaN) excluding the digest field. UUID/time/snapshot file names are retained on replay of the same submission ID rather than regenerated.

New mode requires at least one PDF, unique paths and unique PDF byte hashes within the subject, and one framework. Explicit file-array order is authoritative; directory mode sorts `.pdf` names by Unicode filename order. Duplicate hashes within different subjects are allowed. Snapshot all selected bytes without transformations, compare size/hash before/after copy, and recheck before upload. Source titles use `PDF {ordinal:04d} - {filename}` privately. Saved mode captures existing supported handoffs with their original notebook/conversation identities; unreadable/no usable input is rejected before provider dispatch.

## Local index and journal

Use stdlib SQLite, WAL, `synchronous=FULL`, foreign keys enabled, 5-second busy timeout. Transactions are short; never hold a DB transaction across provider/network/build calls. Initialize a versioned schema once with `PRAGMA user_version=1`; unexpected newer schema is a configuration blocker.

| Table | Required fields and constraints |
|---|---|
| submissions | `submission_id` PK, manifest digest/ref, state (`staged`,`accepted`,`rejected`), accepted receipt JSON/ref, timestamps. Same ID/different digest conflicts. Rejected queue/drain admission may be resubmitted with the same manifest after capacity returns; accepted subjects never reenter the queue. |
| subjects | `subject_run_id` PK, submission FK, unique `acceptance_seq`, stable workflow_id, manifest/run refs, generation-root ref, lifecycle (`queued`,`launch_pending`,`started`,`settled`,`cancelled_before_start`), launch parent/ref, started_run_id nullable, final report ref nullable, close-confirmation ref nullable, cancellation receipt ref nullable, timestamps. Queue order uses acceptance_seq. launch_pending is committed before child start; started records confirmed start; settled requires actual terminal chain confirmation, not final JSON alone. |
| controls | composite PK `(subject_run_id,command_id)`, command digest/action, expected_version, state (`requested`,`applied`,`rejected`), result ref, timestamps. Duplicate lookup precedes stale-version check. |
| recoveries | `recovery_id` PK, original generation-run ref, recovery subject ID, selected scene keys/mode, command digest, state; partial unique index permits one accepted active recovery per generation root. |
| events | `seq` INTEGER PK AUTOINCREMENT, `event_id` UNIQUE, `occurred_at`, submission/subject/workflow/run IDs, operation ID, attempt, phase, kind, outcome, duration_ms nullable, safe_code nullable, HTTP status nullable, artifact ref nullable, allowlisted counts JSON. No free-text/provider bodies. |

Event IDs are stable tuples of logical operation, attempt, kind and poll/build ordinal, encoded as a hash. One physical dispatch per logical attempt; duplicate event insert must compare payload and reject inconsistent reuse. Recovery/read-only retries have their own attempt numbers. Export follows `seq`, which is insertion order rather than a claim of simultaneous wall-clock ordering. Journal append and matching replaceable status update are done through an idempotent local Activity/store operation. Persist dispatch event before sending; disk failure stops dispatch.

Close confirmation records stable workflow_id, terminal Run ID, actual Temporal terminal status, confirmation timestamp and report ref if available. The supervisor records it through a settlement Activity after observing final child-chain result/Describe. Final-report write alone does not prove workflow closure. Bootstrap skips settled records using their retained close proof even after history expiry; queued records alone may be newly launched. launch_pending/started without close proof require current Describe/reconciliation and remain blocked if status is unavailable or expired without retained proof. Never reinterpret not_found as unstarted or restart such a child.

Queued cancellation atomically changes queued → cancelled_before_start, persists its applied command/cancellation receipt and cancelled report, then removes it from the workflow queue idempotently. It needs no child close proof because no child started. Bootstrap permanently excludes cancelled_before_start. Launch reservation compares/changes queued → launch_pending under the same DB transaction fence; cancellation racing launch_pending is rejected and must target the admitted subject afterward. No cancelled_before_start record may become launch_pending.

## Subject state

Workflow state fields: `subject_run_id`, `submission_id`, manifest/run ref, `phase`, `status`, `state_version`, source/Lesson/scene cursor, expected_work_count nullable, inventory_ref nullable, active operation_ref nullable, counters, pause/cancel flags, last applied command ref, persistent command-index ref. All large/private state is referenced.

Statuses: `queued`, `running`, `paused`, `blocked`, `completed`, `partial`, `failed`, `cancelled`. `phase` is one of `preflight`, `create`, `upload`, `poll`, `configure`, `divide`, `execution`, `lesson`, `freeze`, `generate`, `parse`, `build`, `finalize`. Phase survives paused/blocked status. Queued state_version starts at0. A normal phase/status transition, accepted control request and its later applied/rejected transition each increments state_version; duplicate receipts and plain queries do not. Only one unresolved control request is allowed per subject: another command conflicts until the first settles, even with the latest version. This is tested by overlapping Updates, not a sequential simulation.

- queued → running after admission; running → paused at the next settled boundary; resume → running after required preflight/reconciliation.
- Unknown NotebookLM delivery, auth/owner/history conflict, unknown map count or storage failure → blocked with safe reason and retained slot. Resume alone cannot authorize an uncertain resend.
- A blocking source error/deadline may be retried read-only or cancelled; no automatic delete/reupload.
- Scene attempt failed/uncertain → record its status and continue subsequent scenes unless an account/provider-wide auth/rate condition pauses the subject. Finish partial if any unresolved scene/input issue exists or a captured Lesson has no usable scene prompt; never treat zero scenes as fully generated.
- completed requires all mapped Lessons captured and all eligible scenes built, with zero unresolved input/scene issues; partial retains outputs; failed is an unrecoverable local input/config condition with no path to this captured run; cancelled requires applied cleanup, not just request acceptance.

Status projection fields additionally include `as_of`, `freshness` (`current`,`last_known`), Temporal Describe status nullable, final report ref nullable and blocker/action metadata. Distinct counts: PDFs uploaded/ready, Lessons captured/expected, scenes discovered/queued/generated/failed/uncertain, input_issues. Do not infer API usage/cost; retain supplied usage privately and expose null when absent.

## OperationAttempt

Identity `operation_id = artifact_run_id:phase:item_identity`; artifact_run_id is the original retained run UUID, including linked recoveries. Source/Lesson item_identity is its ordinal; scene item_identity is SHA-256 of the existing017 scene_key, not that raw notebook/conversation-bearing key. Retry ordinal is separate and stable across Temporal Run IDs; the current subject execution ID identifies who owns this attempt. Local intent record captures request digest, phase, input refs, epoch, credential revision, provider receipt ref and pre-send evidence refs. States: `prepared` → `dispatched` → `receipt_saved` → `complete`; failure after dispatch without proof → `uncertain`; definite local failure before dispatch → `failed`. `dispatched` intentionally precedes the network call, so a crash in that tiny gap may conservatively become uncertain. Never claim no send solely from absent receipt.

Raw NotebookLM receipt retains exact answer, conversation/turn identity and citations supplied by the adapter. Scene raw-body receipt stays in 017's format. Parser/source/build outputs are separate refs; failure there does not erase the receipt. Unique complete records cannot be overwritten by later attempts.

## Ownership and retained output

Marker fields: schema_version, original run ID, generation-root ref, current owner Workflow ID, owner epoch, active recovery ID nullable, status. Epoch increments only under OS lock when a previous owner is proven finished and no process holds the lock; timeout alone is insufficient. A standalone CLI rejects a managed marker even if no Activity currently holds `.run.lock`. CLI `--force` bypass is not supplied. Activities acquire OS lock, validate marker epoch and current state fence before mutations, and preserve receipts produced by their own active attempt.

Spec 017 scene keys remain `{notebook_id}:{conversation_id}:W{lesson_ordinal}:S{scene_ordinal}`; do not substitute subject IDs for their existing contract. Save Lesson title precedence and arbitrary scene labels unchanged. All final results retain `qualification.mode=owner_waived_terminal_demo`, `jev_called=false` and no synthetic scores.

## Retention

Temporal namespace retains closed histories for 30 days. Local journal, final reports and artifacts have no automatic deletion in v1. Operator deletion while a workflow/recovery owns the run is a blocker, not a request to silently regenerate it. Credential cleanup is a separate operator setup action; it never removes retained educational artifacts.
