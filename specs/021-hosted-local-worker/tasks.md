# Dependency-Ordered Implementation Tasks

**Status**: All implementation tasks below are unstarted. Documentation creation is not feature implementation.  
**Source of truth**: [spec](spec.md), [protocol](contracts/local-worker-v1.md), [guide](implementation-guide.md).

## A. Establish baseline and contracts

- [X] T01 Record the actual source snapshot, tracked diff and relevant untracked-file digests; read actual current routes/services, retain all existing changes and identify prior unrelated test failures.
- [X] T02 Map each FR/SC to files, validators and acceptance cases. Record additive execution/role/state decisions and old/new dispatch exclusivity before edits.
- [X] T03 Implement strict versioned PHP/Python request/manifest/result schemas from the protocol; test duplicate/unknown keys, UTF-8, numeric bounds, exact-byte hashes, source lineage and unsupported versions.
- [X] T04 Define additive device/job/stage/assignment/effect/artifact/result receipt storage. Prove unique constraints and atomic fencing with the production DB engine and disposable databases; no fresh-database assumption.

## B. Hosted authority and preparation

- [X] T05 Add owner-only device enrollment, scoped grants, masked one-time pairing, hashed expiring tokens, rotation, revocation, rate limiting and safe audit.
- [X] T06 Add immutable `local_worker_v1` execution/output scope and capture exact protocol/settings/framework/templates/output contract; ensure old run/source/map/lesson dispatchers and recovery discovery cannot also start the run or its cases.
- [X] T07 Reuse current server PDF inspection/v2 preparation in a provider-free hosted capability. Prove the hosted configuration needs no Google session or laptop path. Preserve v1 historical captures and byte/page lineage. Complete/recover idempotent `AcceptRunItem`/`IntakeCoordinator`/provider-free `DirectSourceCoordinator::prepare` associations before task sealing, outside expiring upload quarantine.
- [X] T08 Implement durable stage queue, atomic claim replay, single active assignment/epoch, capacity and live authorization checks.
- [X] T09 Implement heartbeat, lease/control reads and pause/cancel/revoke synchronization, with correct claim long-poll timeout and disconnected/reconciliation classification.
- [X] T10 Implement scoped streaming inputs and staged immutable output uploads with server rehash, bounds, cross-tenant denial, replay receipts and cleanup isolation.

## C. Local runner and effects

- [X] T11 Implement remote-worker config/enrollment/doctor/daemon/status/drain commands, exclusive process locking, private durable workspace, disk bounds and outgoing HTTPS allowlist.
- [X] T12 Implement download verification and stage context resolver; consume server-prepared PDF bytes without re-splitting or a new unrelated terminal submission.
- [X] T13 Refactor/reuse provider-only NotebookLM logic and add a scoped task client. Do not copy hosted `.env`, private HMACs, DB files or provider cookies between sides.
- [X] T14 Implement durable local effect journal + server reserve/dispatch/outcome receipts for every mutation, including history reset. Disable hidden mutation retries. Test actual outgoing sends around every crash window.
- [X] T15 Implement read-first reconciliation and authorization-renewed recovery retaining effect IDs/digests. No automatic unknown-call replay or automatic lease-expiry reassignment. Duplicate-risk override is owner-controlled and audited.
- [X] T16 Prove local provider auth failure/quota holds, session refresh instructions, graceful drain and restarted-worker recovery without counterfeit completion.

## D. Authoring outputs and manual scenes

- [X] T17 Implement complete typed raw-answer/map/handoff/prompt/archive transfer and validation. Detect missing expected content, wrong topic, malformed replies and broken provenance.
- [X] T18 Implement authoritative result commit and server-issued associations/counts with immutable receipt lookup. Harden/exclude synthetic `AcceptWebLesson` fallbacks. Enqueue follow-up work through the durable outbox.
- [X] T19 Add raw-accurate JSON and readable text prompt export; show only accepted output. Preserve the generation system/template/output-contract snapshot required for manual generation.
- [X] T20 Implement `return_scenes` stage, exact prompt bindings and real manual package import. Use dedicated service-principal authorization; preserve reported-model provenance and unknown usage values.
- [X] T21 Reuse strict three-file validation and actual isolated browser checks; prove missing JEV is not labeled qualified, no worker review/approval and no automatic student publication.

## E. Product surfaces and deployment

- [X] T22 Extend actual Inertia teacher intake/progress/prompts/scenes UI with truthful managed-processing states and accepted counts.
- [X] T23 Add owner device/operator scope UI while preserving ordinary admin operational privacy and teacher isolation.
- [X] T24 Add content-free operational events and measured/reported/estimated/not-recorded usage semantics; no fake timing or unit-economics claims.
- [X] T25 Provide separate hosted/local startup/stop/health/refresh/recovery/backup/rollback documentation and safe configuration examples. Preserve current same-host mode. Keep internal bridge/Temporal/DB private.

## F. Acceptance and report

- [X] T26 Complete offline domain/contract/concurrency/security cases from [acceptance](acceptance.md), recording real command results and doubles.
- [X] T27 Execute joined Laravel-server/separate-worker HTTP acceptance with disposable state; then actual scene build/check/teacher preview/review/publication checks as separate evidence tiers.
- [ ] T28 If and only if live provider use is authorized and credentials are present, execute the live NotebookLM gate using a real authorized PDF and no paid scene-generation calls; otherwise report the gate `not_run` or `blocked`.
- [ ] T29 If genuinely produced manual scenes are available, execute manual return gate. Do not synthesize “AI-generated” files to fill this gate.
- [X] T30 Produce the FR/SC evidence matrix, command transcript, test counts, actual artifact hashes, unresolved findings, rollout/rollback notes and separate implementation/live/production readiness statuses.
- [X] T31 Deliver the bounded API replacement evaluation recipe and adapter seam. Any actual API benchmark, managed RAG replacement, paid calls or public deployment requires its own explicit execution scope.

Tasks are only checked after their corresponding real work/evidence exists. An offline provider fixture can satisfy a transport test, not T28/T29. No checkbox is auto-completed from this document's existence.
