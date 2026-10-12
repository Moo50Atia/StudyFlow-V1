# Durable Orchestration and Service Boundary

## Identity, root and payload

Capture one absolute V3_TERMINAL_ROOT for bridge/control/worker/services; default new application storage is storage/app/private/v3/terminal resolved at startup. settings.py currently has a different default: reconcile it without repinning existing captured roots. Archive import sources are separately supplied read-only roots. Namespace V3_TERMINAL_TEMPORAL_NAMESPACE default v3-terminal. Legacy bridge namespace remains separate.

Admission schema1: submission_id UUID,submission_digest SHA,run_id UUID,manifest_ref opaque UUID,workflow_kind web_subject_v1 or web_import_v1. No class name from caller. Encoded payload<=32 KiB before any Temporal call. Ref resolves private immutable artifact via trusted store; same rule applies to Update/Signal/Query/Activity results/search attributes. No titles, paths, account/proof IDs, notebook/source/conversation IDs, framework text, responses, prompt bodies, screenshots or keys. Only run/item/section/operation/artifact UUIDs and safe enums/counts. Page inventories100 refs at a time; never return complete scene list serialized as a string.

New Python endpoints in existing bridge: POST /internal/v1/application-pipeline/submissions, POST /internal/v1/application-pipeline/commands, GET /internal/v1/application-pipeline/receipts/{command}, GET /internal/v1/application-pipeline/runs/{run}/status. HMAC same production signing convention; bounded JSON and sanitized safe errors. Submission calls supervisor Update-With-Start, USE_EXISTING conflict policy, stable Update ID, then returns durable accepted receipt, not a speculative202. Fresh supervisor/bootstrap queries retained active child chains and counts unresolved ones before admitting. Same identity/different digest409, quota queued capacity64 overflow409 queue_full. Bridge502/503 uncertainty is retained and queried under original ID.

## Shared scheduling and workflow types

Extend existing terminal supervisor with explicit allowlist terminal_subject_v1/web_subject_v1/web_import_v1; terminal existing captures/histories keep their old workflow type through versioned patches. Register ApplicationSubjectWorkflow/ApplicationImportWorkflow on v3-terminal-control; IO on v3-terminal-io max4; artifact/check/build on v3-terminal-build max2. One worker process/lock; no second independent scheduler. Supervisor active4 across terminal and web, queued64. Persist admission/queue/cancel/launch/reservation receipts before acknowledging. Release slots only on parent-observed actual child-chain closure, not an Activity claiming its own workflow is closed. Recover unknown chains conservatively. Rotation at8000 events/hard12000 continues as new with bounded active/queued refs; settled dedup receipts stay in registry. Drain for rotation differs from explicit shutdown.

Application processing chunks close at prompts_exported/scene-ready. Manual/review waiting stays in Laravel durable state; selected new generation/build/correction commands spawn bounded recovery children through the same supervisor. These waits never hold generation slots. Do not launch legacy CaseWorkflow for linked cases.

## Ordered processing

1. Resolve immutable manifest and approved account proof privately; bootstrap isolated per-run credential copy before verify. No runtime browser launch. Distinguish authentication_required/account_mismatch/provider_rate_limit/provider_unavailable.
2. Intake Activity claims existing staged attempt, runs qpdf/ClamAV, commits accepted unchanged private document/case/lecture. It exclusively owns new-path attempt; do not also enqueue existing queue intake dispatcher for that attempt. Rejects remain attempts only.
3. Verify source hashes and capture steps; create one notebook and upload framework once. Durable remote dispatch fence precedes every create/upload/configure/chat/reset.
4. For each accepted PDF ordinal: upload alone, poll ready bounded60*5s using read-only retries; set request source_ids for that PDF, excluding framework during Divide; save receipt then parse map. Execution/lesson turns use that PDF+framework.
5. Exact approved Divide/Execution/Start/Continue/combined format bytes are obtained from current approved artifacts. No count hint, summary shortcut, three-scene instruction or source blending. System Ready mismatch is captured warning for this permissive demo, not a prompt rewrite. Unknown/empty structural map blocks that PDF with map_unreadable after safely settling its turn.
6. Every lesson op ID includes run,item,lesson ordinal,turn kind and attempt. Save raw answer and receipt before parsing/projecting. Parse all outputs permissively, preserve exact Third prompts and eleven First fields/null warnings. Accept bundle/advance cursor atomically only once under query digest/current cursor fence.
7. Archive verified map/turn/lesson counts before reset. Reset receipt proves isolation before next PDF; do not delete original PDFs. Request source selection, not browser checkboxes, is authoritative.
8. Select successful archives using outcome manifest; failed items must not make scoped_inventories demand nonexistent archive. Export whole-run and per-lecture descriptions. API mode generates only selected captured scene IDs; manual mode stops at export with no key requirement.
9. Persist final counts/events/report from verified records. All later scene check/review/correction commands are distinct durable chunks, not automatic approvals.

## Operation results and recovery

Activity result schema1: outcome completed/pending/failed/blocked/uncertain/cancelled, safe_code, receipt_ref nullable UUID,result_ref nullable UUID,counts nullable bounded integers. Branch on outcome; nonthrowing dictionary is not success. Exceptions must be sanitized without raw SDK causes before serialization. Raw diagnostics private only. Ordinary RuntimeError must not leave Workflow Tasks retrying indefinitely.

Safe codes: ok,authentication_required,account_mismatch,provider_rate_limit,provider_unavailable,source_error,map_unreadable,context_incomplete,delivery_uncertain,history_conflict,reset_uncertain,storage_unavailable,receipt_invalid,artifact_missing,artifact_corrupt,scene_package_invalid,build_failed,configuration_invalid,configuration_drift,operation_conflict,control_conflict,stale_version,queue_full,concurrent_update,cancelled.

Remote mutations max_attempts1. Retry safe local metadata up to3 at1/2/4s; read-only transport polling may retry bounded3; failed persistence blocks next dispatch. Never resend after timeout without receipt/history reconciliation. Retained complete receipts verify raw digest/completeness/body/normalized text and preserved measured timing; interrupted partial body is evidence of uncertainty, not successful output. Local response/source/build recovery never needs generation credentials. Each failed/interrupted build reserves a unique immutable ordinal before execution and verifies current pointer/source/scaffold/toolchain.

PDF-local definite source_error/map_unreadable failure records failed, safely resets conversation and continues; reset/history uncertainty blocks run. Shared auth/account/quota/storage failures pause run, preserving last settled operation. No fabricated deadlines/retry-after; quota timers use actual typed retry_after when present, otherwise operator retry. Explicit notebook retry creates distinct attempt after local verification/consent; successful archived PDFs are not reprocessed.

## Controls and exact consumption

Commands carry complete canonical digest, expected state_version, phase/scene selection and consent. Check duplicate identity first; same digest returns same receipt; changed conflicts. Pause/cancel requested while in-flight; apply immediately after durable receipt settles and before every next dispatch. Paused wait predicate includes cancellation. Queued cancel vs launch is a transactional registry race, one winner. Cancelled runs retain evidence and cannot resume; explicit recovery is a separate command/child.

Approval/correction outbox receipt consumed requires exact decision reference matched by new workflow/command handler. Completed workflow, accepted Signal or query exception is never proof. Lost acknowledgment queries exact retained receipt. Report actual requested/applied state/version.

## Events and trust

Reuse OperationalJournal semantic kinds plus import_started/import_item_committed/import_finished, prompt_exported, manual_scene_imported, gate_recorded, preview_started, review_decision_committed. Event ID derives operation/attempt/kind; callback idempotency never overwrites differing evidence. Persist required event before next mutation. Application projection is DB authority; local journal/private receipts aid recovery, not competing scheduler. Replayed workflow logging is replay-aware. Privacy sentinel tests inspect actual serialized histories and exception causes, not just logger strings.
