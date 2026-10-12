# Research: Temporal Terminal Pipeline

**Date:** 2026-10-09. Read-only repository/installed-SDK inspection and official documentation; no application tests or live calls were performed for this spec. Business authority is [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), qualified by later explicit owner decisions and [017's integration boundary](../017-v3-terminal-scene-generation/contracts/downstream-integration.md).

## R01 — Existing execution boundaries

**Evidence:** `v3_engine/worker.py` registers `CaseWorkflow`, `CorrectionWorkflow` and Laravel-facing Activities. `v3_engine/settings.py` defaults to namespace `default` and queue `v3-cases`. `scripts/v3/run_multi_pdf_notebooklm.py` implements `Run.execute`, upload/poll loops and `prompts` using JSON checkpoints; it is not scheduled by that worker. Spec 017's report now says complete, but that report is evidence attributed to its implementer, not independently rerun here.

**Decision:** New namespace, supervisor/subject workflows and separate terminal worker; reuse services, not existing case IDs/callbacks. Keep standalone commands compatible and existing workflow code unchanged.

**Rationale:** Multi-subject local artifacts and an owner waiver are distinct from the production application lifecycle.

**Alternatives considered:** Extending `CaseWorkflow` would conflate ownership/review and legacy histories; putting a subprocess around the CLI would hide useful activity boundaries.

## R02 — Durable scheduling and bounded history

**Decision:** Singleton supervisor, four child execution chains globally, FIFO pending queue, explicit REQUEST_CANCEL parent-close policy, no Workflow RetryPolicy. A child slot remains occupied across child Continue-As-New. Subject controls target the subject Workflow ID directly. Supervisor history rotation drains children/handlers before continuing; reject new admission while draining and bound queued subjects to 64. Subject history rotates at safe completed operation boundaries.

**Rationale:** Per-submission supervisors could exceed four subjects. A default terminating parent policy would bypass cooperative cleanup. Continue-As-New retains Workflow ID but changes Run ID, so external effect IDs cannot depend on Run ID. [Child workflows](https://docs.temporal.io/develop/python/workflows/child-workflows), [Continue-As-New](https://docs.temporal.io/develop/python/workflows/continue-as-new).

**Alternatives considered:** Multiple batch schedulers, unbounded submissions while draining, abandoning active children during rotation.

## R03 — External effects and uncertainty

**Evidence:** Runner `ask_or_recover` compares turn count/last question; repeated Continue requires stronger correspondence. It directly calls `session.client.sources.list` and SDK conversation discovery, bypassing its ports. Existing `NotebookFiles` lacks full source inventory and explicit owner-role evidence.

**Decision:** Extract a provider-neutral step service and add those capabilities. Write operation intent/pre-send inventory/history anchors, then a dispatch record, then response receipt before parsing. Mutating Activities get one automatic attempt. A separate read-only reconciliation may prove the effect; no proof means uncertain, not resend.

**Rationale:** A worker may die after a remote effect but before acknowledgement. Local receipts and provider evidence reduce duplication; they cannot establish exactly-once behavior where the vendor offers no such guarantee. [Activity error handling](https://docs.temporal.io/develop/python/best-practices/error-handling).

**Alternatives considered:** Default unlimited retries, treating a timeout as failed delivery, counting identical Continue strings without an anchored prefix.

## R04 — Authentication actually selects storage, not a label

**Evidence:** `SdkSessionProvider.open(profile_ref)` loads the storage path without using `profile_ref`. `NotebookRef` retains only ID/title. Installed notebooklm-py 0.8.4 has explicit notebook owner role; `is_owner` can default optimistically. Its refresh/close code writes cookies. `_auth/refresh.py` also permits `NOTEBOOKLM_REFRESH_CMD` independently of headless browser recovery.

**Decision:** Approved alias mo50atiapro maps to the existing credential file plus an owner-approved proof notebook. Require explicit `SharePermission.OWNER` evidence for that notebook; membership/shared visibility or missing role is insufficient. Create a designated private per-subject credential copy, never write SDK updates to the master cache, refresh the copy only at bootstrap/explicit resume and reverify owner proof. New worker disables all external refresh-command settings before SDK bootstrap and uses `allow_headless=False`. Ordinary nonbrowser token/CSRF bootstrap remains enabled.

**Rationale:** Four client sessions must not overwrite the master cache or silently use another account. Ownership evidence binds the operator-selected proof notebook to the authenticated account; it is not proof of subscription tier or unlimited quota.

**Alternatives considered:** Trusting a Brave profile label, checking visibility only, racing four SDK writers on the master file, runtime browser refresh.

## R05 — Scene operations must become individually recoverable

**Evidence:** `SceneGenerationService.execute_run` handles the whole inventory and starts provider attempts; `SceneArtifactStore` locks a directory for an invocation and retains immutable receipts/attempts/builds. `SceneBuilder` invokes synchronous subprocesses. Its current passing tests do not prove safe Temporal operation boundaries.

**Decision:** Extend the injected service with initialize/load, generate-one, restore-receipt, materialize-source, build-one and summarize operations; keep `execute_run` as the compatible facade. Persist receipt/source before succeeding to the next Activity. Execute builds outside the event loop with owned process-group cancellation and independent heartbeats. Add durable Temporal ownership across Activity locks and enforce it in both standalone CLIs.

**Rationale:** A receipt must recover without another paid request. A compiler must not block every subject's heartbeat. Released per-Activity locks alone do not protect managed output between Activities.

**Alternatives considered:** One giant async Activity, treating a saved response as queued generation, changing the approved React template to static HTML.

## R06 — History and detailed logs are separate

**Decision:** Persistent local Temporal dev server, namespace `v3-terminal`, standard replay-aware workflow logging and a SQLite operational journal/JSONL CLI export. No metrics/tracing stack or custom dashboard in this feature. Store artifact references rather than content in Temporal. Native UI exposes history and safe stage/count metadata. [Python observability](https://docs.temporal.io/develop/python/platform/observability), [persistent dev-server options](https://docs.temporal.io/cli/command-reference/server).

**Rationale:** Durable workflow events do not capture arbitrary application progress or stdout. A local journal provides ordered evidence from concurrent Activities and supports offline last-known status without making it the scheduler.

**Alternatives considered:** Raw provider-body logs, educational bodies in search attributes, Prometheus/Grafana/OpenTelemetry infrastructure, JSON checkpoints pretending to be durable scheduling.

## R07 — Commands and service status

**Decision:** Client Update-With-Start joins the singleton supervisor with USE_EXISTING. Application command IDs/digests persist across rotation. Child controls are acknowledged Updates with expected state version. Query failure yields persisted last-known snapshot plus Describe status, not fabricated current progress. [Message passing](https://docs.temporal.io/develop/python/workflows/message-passing).

**Alternatives considered:** Fire-and-forget controls, considering workflow completion proof that an arbitrary command was consumed, parent proxying child Update acknowledgement.

## R08 — Tooling and gates

**Evidence:** Installed Python 3.12, temporalio 1.34.0, notebooklm-py 0.8.4, httpx 0.28.1 and local Temporal CLI; existing pytest external-lane flags include `--run-temporal`, `--run-integration`, `--run-live`, `--run-browser-ui`. Constitution is an unratified template. No extension hook file exists. `.specify/scripts/bash/update-agent-context.sh` is absent, and no replacement agent-context updater was found in the supplied script directory.

**Decision:** Reuse installed dependencies/opt-in guards; do not run or claim provider tests while authoring docs. Missing agent-context automation is recorded rather than creating a global instruction file; [tasks-note](tasks-note.md) supplies the implementation context. Do not invent binding constitution principles or alter it.

**Alternatives considered:** Downloading another server implicitly, importing fixtures into production, regenerating unrelated agent instructions, claiming Spec 017's reported tests as tests performed here.
