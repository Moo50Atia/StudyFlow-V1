# Implementation Plan: 007 Workflow Start and Progress

**Reviewed:** 2026-10-02 · [spec](spec.md) · [contract](contracts/workflow.md) · [data model](data-model.md) · [acceptance matrix](contracts/acceptance-matrix.md)

## Entry and repository fit

004, 005 and 006 must exist as application code with passing prerequisites. Today they are docs only: `routes/v3.php`, V3 case/settings/access models and `v3_engine/` are absent. Laravel 12/PHP ^8.2, Pest, SQLite-memory tests and a sync test queue exist. `routes/web.php` has legacy `teacher.access` routes; `bootstrap/app.php` does not load an API route file; `config/queue.php` has `after_commit=false`; `routes/console.php` has no reconciler. 007 extends the 004 V3 web route/policy surface, adds a separate signed API callback and introduces the Python SDK worker/bridge. It does not alter legacy content behavior or add 008–013 production placeholders.

## Change inventory

| Layer | Specific files and operation |
|---|---|
| Schema/models | `database/migrations/2026_10_02_000007_create_v3_workflow_tables.php`, `app/Models/V3/{WorkflowLink,CaseEvent}.php`; exact [schema](data-model.md). Do not add a ReviewSignal table. |
| Discovery/start | `app/Console/Commands/V3/DispatchWorkflowsCommand.php`, `app/Jobs/V3/StartCaseWorkflowJob.php`, `app/Services/V3/Workflow/{DiscoverCases,StartWorkflow,ReconcileWorkflowStart,BridgeClient}.php`, `routes/console.php`, `config/queue.php` dedicated `v3_workflow` connection (`after_commit=true`, timeout 30, retry_after 90). |
| Internal callback | `routes/api.php`, `bootstrap/app.php` API route loader, `app/Http/Middleware/V3/VerifyWorkerSignature.php`, `app/Http/Controllers/V3/Internal/CaseEventController.php`, `app/Services/V3/Workflow/ProjectCaseEvent.php`, `config/services.php` bridge/callback config. No session role grants this route. |
| Teacher/admin progress | Append routes to `routes/v3.php`; `app/Http/Controllers/V3/{Teacher/CaseProgressController,Admin/CaseSummaryController}.php`; `app/Http/Resources/V3/{CaseProgressResource,ClosedCaseSummary}.php`; `resources/views/v3/{teacher/cases/progress,admin/cases/index,admin/cases/summary}.blade.php`; link from 006 `resources/views/v3/teacher/cases/show.blade.php` and 004 admin workspace. |
| Python boundary | `v3_engine/pyproject.toml`, `v3_engine/{bridge.py,client.py,contracts.py,settings.py,worker.py}`, `v3_engine/workflows/{case_workflow,review_gate}.py`, `v3_engine/activities/publish_milestone.py`. Use Python 3.12, `temporalio`, `fastapi`, `uvicorn`, `httpx`, `pytest`, `pytest-asyncio`; lock compatible versions in implementation and record them. |
| Tests | `tests/Feature/V3/Workflow/*.php`, `tests/Integration/V3/Workflow/*.php`, `v3_engine/tests/workflow/*.py`; exact cases in the matrix. Future candidate/source fixtures stay in test files. |

## Execution architecture

1. Every minute, discover 006 cases with `state=awaiting_workflow`; under a case lock, insert one link containing fixed workflow ID and captured settings ID, then dispatch after commit. The scheduler also retries due/expired claims. A 006 HTTP case creation remains independent from Temporal availability.
2. Laravel job rechecks current owner access before first remote start, writes its lease/attempt, and calls a private signed Python bridge outside the transaction. Laravel first verifies captured 005 settings and passes only retry fields/checksum; bridge starts using reject-duplicate policy or describes an existing ID and verifies memo. Timeout is uncertain. Python worker runs the real case workflow, publishes initial safe milestone through a retryable activity, then waits at the 008 source handoff; no extraction activity is fabricated.
3. Python activity sends signed callback to Laravel's API route. Projector validates identity/key/version, atomically inserts safe event, updates 006 case state/version and link summary. Worker retries same event key if acknowledgement is lost. The start response and callback may arrive in either order.
4. Teacher progress uses 004 owner scope on real case routes. Admin list/detail uses exactly 004 closed DTO fields, built from scalars. No Temporal history, Eloquent relation or teacher content is returned directly. Browser pages use existing V3 layout and refresh link; 013 later adds review actions.
5. A reusable Temporal review gate is tested with a test-only synthetic workflow and time skipping. 012 later supplies qualified candidate identity and 013 later commits/authorizes decision plus durable signal delivery. 007 ships no production review endpoint, decision model or fake candidate.

## Concurrency, failure and proof

Use link PK/unique workflow ID, a two-minute claim lease and 90-second queue retry horizon to serialize duplicate discovery/start. Never hold a DB transaction across network I/O. `WorkflowIDReusePolicy.REJECT_DUPLICATE` closes the remote race after a lost response. Reconciliation distinguishes confirmed not-found within the 30-day retention window, expired-history not-found, uncertain/unavailable, found open and found closed. Wrong memo/run ID is a stop and safe alert, not a new start. For event writes, one case transaction and unique key/sequence constraints are final race guards. Real contention tests use independent processes/connections on a shared file-backed SQLite with `PRAGMA busy_timeout=5000` or a production-equivalent DB. Normal in-memory Pest tests cannot prove this.

Captured 005 policy governs the 007 callback activity (`maximum_attempts=technical_retries+1`, exponential delay from `retry_delay_seconds` capped at 3600 seconds). This tests actual 007 retry/idempotency behavior; later NotebookLM/JEV/provider activities need their own effect ledgers/reconciliation. A stopped worker leaves an open Temporal execution delayed; only a verified closed failure produces `failed`. The live gate uses a local Temporal dev server, actual bridge, Laravel callback, worker stop/restart and DB inspection. Mocks prove isolated decisions only.

## Stop gates

If 004–006 implementations are absent, do not create parallel auth/settings/case services. If Temporal CLI/server, Python SDK, private bridge keys/network, Laravel queue worker or scheduler are absent, record the environmental gate and do not claim live completion. If memo mismatch, ownership leak, callback signature bypass, version regression or divergent case/event state appears, stop rollout and keep a safe diagnostic without content. 008–015 must prove their own live integrations; 007 is complete only for its stated foundation and evidence limits.
