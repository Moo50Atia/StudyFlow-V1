# Implementation Plan: V3 PDF Intake and Processing Cases

**Reviewed:** 2026-10-01 · [Specification](spec.md) · [API/state contract](contracts/intake.md) · [data model](data-model.md) · [acceptance matrix](contracts/acceptance-matrix.md)

## Entry boundary and repository evidence

Implement 006 only after 004's actual V3 access/ownership and 005's immutable settings/selection services exist and pass their own required tests. At review time they are documentation, not application code. Current Laravel code has `routes/web.php`, `LectureController`/`LectureRequest`, `TeacherAccessMiddleware`, `config/filesystems.php`, `config/queue.php`, `routes/console.php`, `AppServiceProvider`, and SQLite-memory/Pest tests. The legacy lecture upload uses the `public` disk and a 10 MiB MIME check; neither storage path nor legacy middleware is a V3 access boundary. 004's `routes/v3.php` is a prerequisite extension point, not a file already present. qpdf and ClamAV CLI/daemon are deployment prerequisites, not Composer packages already installed here.

## Technical design and change inventory

| Layer | Exact change | Reason |
|---|---|---|
| Database | `database/migrations/2026_10_01_000006_create_v3_intake_tables.php`; `app/Models/V3/{UploadAttempt,Document,ProcessingCase}.php` | Owner-restricted identity, settings lineage, duplicate/idempotency uniqueness and durable recovery states; schema in [data-model](data-model.md) |
| Authorization | `app/Policies/V3/{UploadAttemptPolicy,DocumentPolicy,ProcessingCasePolicy}.php`, `app/Providers/AppServiceProvider.php`, 004 `HasV3Owner` | Register real policies and derive ownership from current authenticated teacher; no admin content access |
| HTTP | `routes/v3.php`, `app/Http/Controllers/V3/Teacher/{PdfUploadController,DocumentController,ProcessingCaseController}.php`, `app/Services/V3/Intake/{ValidateUploadInput,ValidateCaseInput}.php` | Exact teacher routes, validation after attempt creation or owned-Document resolution, private/no-store response, owner-scoped lists/read/download; extend `resources/views/v3/teacher/workspace.blade.php` and add `pdf-uploads/{index,show}.blade.php`, `cases/show.blade.php` under that teacher view directory |
| Storage | `config/filesystems.php` `v3_private` local disk rooted at `storage/app/v3`, `serve=false`, `throw=true`; `app/Services/V3/Intake/{PrivatePdfStore,AcceptedPdfReader}.php` | Quarantine and accepted bytes outside public symlink; verified owner-authorized stream |
| Inspection | `app/Services/V3/Intake/{PdfInspector,QpdfAdapter,ClamAvAdapter}.php` | Explicit supported-PDF profile and fail-closed process results; tool args never shell-interpolated |
| Recovery | `app/Jobs/V3/ValidatePdfUpload.php`, `app/Services/V3/Intake/{IntakeCoordinator,ReconcileIntake}.php`, `app/Console/Commands/V3/ReconcileIntakeCommand.php`, `config/queue.php`, `routes/console.php` | After-commit dispatch, bounded retry, partial-move repair and orphan cleanup without 007 |
| Case start | `app/Services/V3/Intake/StartProcessingCase.php` | 004 active teacher, locked Document, 005 atomic version capture, duplicate choice, current limits and idempotency |
| Tests | `tests/Feature/V3/Intake/*.php`, `tests/Fixtures/V3/Pdf/` | Cases in acceptance matrix, real boundary and contention, live tool gate |

Class/file naming can follow existing PSR-4 namespace conventions, but any deviation must preserve the concrete contract and be recorded in the implementation report. Do not add a new PDF upload path to `LectureController` or give legacy admin middleware V3 read access. Test the legacy `/lectures` and `/storage` paths for absence of V3 bytes, without changing V2 behavior except a necessary V3 bypass fix.

## Sequence and transaction rules

1. Establish private disk, schema, policies and strict route inventory. Create attempt before application validation, select active 005 upload version, stage bounded bytes, then dispatch after commit. 413 before controller remains an infrastructure limit.
2. Job claims a quarantined/due attempt once. Inspect exact staged bytes with qpdf and ClamAV. Determinate rejection proceeds to cleanup; technical failure schedules captured-policy retry. The scheduler reconciles missed dispatch, expired leases, pending moves and cleanup. A 24-hour deadline caps all quarantine retention.
3. Owner/hash unique key resolves exact duplicates. For new bytes, commit pending row first; atomic same-disk rename then verify and mark ready. No pending document may be downloaded or used in a case. A duplicate attempt gets its own row, links to one own Document, and purges its staged copy.
4. Case start locks 004 access singleton, rechecks teacher, locks same-owner ready Document, resolves idempotency/choice, calls 005 `SelectCaseSettings` on the same DB connection, checks captured current limits, inserts initial case and commits. It does not invoke Temporal. 007 consumes durable `awaiting_workflow` rows.
5. Stream only a verified ready file via owner-authorized controller or same-owner internal `AcceptedPdfReader::openForCase(ProcessingCase $case)`; 008 owns worker transport. No raw path, signed URL, hash or binary content enters admin projections.

Use the contract's compare-and-set transitions, unique keys and bounded transaction retries as final race guards. Atomic move is on one disk only; if filesystem and database differ, committed pending state gives reconciler a durable recovery point. For unavailable qpdf/ClamAV, accepted-file storage or 005 state, fail closed with fixed codes rather than skipping a gate.

## Test strategy and proof boundaries

Follow [tasks.md](tasks.md): first write behavior tests against stable route/service contracts, then code. Existing 004/005 behavior gets regression coverage; do not manufacture failing tests by missing imports. Use fixtures for 007 consumers only inside tests. HTTP security tests traverse real middleware, policies and download controller. Concurrency tests use independent processes/connections. Tool unit doubles prove decision logic; an environment with qpdf/ClamAV must pass the real adapter/infected fixture gate before claiming live acceptance. Routine SQLite tests do not prove production queue/scheduler setup, and no 006 test proves Temporal, NotebookLM, JEV or scene review. The implementation report must separate these results.

## Deployment prerequisites and stop gates

Provision qpdf 11+ JSON v2, `clamdscan` with reachable local daemon/socket and scan definitions, intake queue worker/scheduler, private writable disk and web/PHP upload ceilings over 100 MiB plus overhead. Verify qpdf/ClamAV command semantics in the deployment image. If missing, the implementer can complete isolated code/test work, but must stop before calling live PDF acceptance complete. If 004/005 implementation or contract compatibility is absent, stop dependent 006 runtime work rather than adding production substitutes. No canonical prompt, NotebookLM, JEV, KOS/KIE or multitenant work belongs to this plan.
