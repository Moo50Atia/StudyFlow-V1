# Spec 007 documentation review report

**Date:** 2026-10-02. **Scope:** `specs/007-v3-workflow-progress/` only. This report records a documentation design and consistency review, not an application implementation.

## Authority and baseline

I read Conversation 23, the V3 spec map, every original 007 artifact, applicable 004–006 prerequisite contracts and directly affected 008–015 integration contracts. I inspected Laravel routes, bootstrap, middleware, models, migrations, tests, queue/database configuration and the existing PHP/Python tooling. No applicable `AGENTS.md` was present in the repository or ancestor path. Spec 004 supplied a depth reference, not assumed validated text. The read-only `speckit-analyze` method was used semantically before edits; its selector helper targets `.specify/feature.json`, which already selected 004, so I left that pre-existing selector untouched and reviewed 007 directly.

The inspected repository is Laravel 12/PHP ^8.2 with legacy teacher/admin routes, SQLite-memory/sync-queue test defaults and no implemented 004–006 V3 routes/services/tables, `v3_engine`, Temporal SDK/server binary or 007 callback route. `bootstrap/app.php` does not load `routes/api.php`; `config/queue.php` has database `after_commit=false`; `routes/console.php` has no V3 dispatcher. Legacy `TeacherAccessMiddleware` allows admin to reach legacy educational content and cannot enforce V3's closed admin projection. These are implementation prerequisites, not changes made in this review.

## Initial read-only findings

| Severity | Finding before remediation | Repository/contract evidence | Resolution in 007 |
|---|---|---|---|
| Critical | The plan expected durable Temporal work without specifying a usable Laravel↔Temporal client/worker transport, signed callback boundary, workflow ID reuse policy or deployment dependencies. | No Python V3 package or Temporal dependency; `bootstrap/app.php` loads web/console only; 006 commits an `awaiting_workflow` case and does not call Temporal. | Private Python SDK bridge, distinct HMAC directions, deterministic ID/memo, exact internal operations and provisioning gates. |
| Critical | A timeout after remote acceptance could create a second start or strand a case; link-before-HTTP, duplicate discovery and expired history were not reconciled. | 006 `state_version=1` and committed case handoff; default queue `after_commit=false`; Temporal SDK's closed-ID reuse default needs explicit override. | Link PK/lease, after-commit job, minute discovery, `REJECT_DUPLICATE`, describe/memo adoption, fixed 30-day history stop and alerts. |
| High | Event ordering and progress lacked an atomic version/duplicate contract; a replay or overlapping callbacks could regress state. | 006 owns case row/version; no 007 event table or transaction; 004 publishes only safe summary fields. | Exact `WorkerEventV1`, event key/digest/sequence uniqueness, duplicate-first CAS transaction, transition table and independent-process contention tests. |
| High | Retry claims implicitly covered later provider activities, although 007 has no extraction, NotebookLM, JEV or scene effect. | 005 captures `technical_retries`/`retry_delay_seconds`; 008–012 own those later integrations. | Retry only the real 007 milestone callback using the captured 005 snapshot; require later feature-specific effect idempotency. |
| High | Review signaling was blurred with 013's owner decision and 012's candidate qualification, inviting a production fake review record or authorization bypass. | 012/013 contracts own persisted qualification and original-teacher review; 004 denies admin content/review access. | Test-only gate proof, exact candidate/version/decision behavior, no 007 review HTTP route/model; 013 must add authorized transactional decision/outbox. |
| High | Teacher/admin progress and legacy bypass were not tied to exact current-owner routes, response fields, errors and no-store behavior. | 004 closed `OperationSummaryData` keys/codes; legacy middleware grants admin broad old content; 006 owner is immutable. | Exact V3 teacher/admin routes, scoped pagination, closed DTO, identical foreign/missing 404, HMAC-only callback and route-inventory tests. |
| Medium | Task order and checks could report a green build from missing-import failures, sequential pseudo-concurrency or mocked Temporal. | `phpunit.xml` uses SQLite `:memory:`/sync queue; no Temporal binary in PATH. | Runnable scaffolds before behavioral red, real overlapping process tests, separate live bridge/worker/Temporal gates and explicit proof limits. |
| Medium | Retention, log redaction and downstream handoff were too vague to keep uncertain cases and teacher content safe. | 004 admin allowlist, 006 private PDF contract, 015 later history ownership. | Minimal link/event retention, fixed operational codes, content/secret exclusion, 30-day namespace condition and versioned 008 activation handoff. |

## Files edited and why

| File | Substantive change and reason |
|---|---|
| [spec.md](spec.md) | Defined 8 requirements, 20 stable W cases, 4 success criteria, ownership and 007 proof boundary; makes business acceptance observable. |
| [plan.md](plan.md) | Mapped real repository layers and exact files, launch/projection architecture, concurrency and stop gates; removes assumptions about existing V3 infrastructure. |
| [research.md](research.md) | Recorded inspected code/contracts and primary Temporal SDK/CLI evidence for bridge, reuse policy, retry, waits and namespace retention; explains design choices. |
| [data-model.md](data-model.md) | Fixed two Laravel tables, fields, keys, immutability, case version and event sequence/count invariants; prevents schema invention. |
| [contracts/workflow.md](contracts/workflow.md) | Defined signed wire operations, inputs/outputs/errors, start/reconciliation algorithm, state transitions, private routes, retry policy, review gate and audit rules; resolves cross-process behavior. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | Mapped W01–W20 to FR IDs, specific test files and outcomes plus FR-to-task trace; exposes coverage and live-proof limits. |
| [contracts/integration-obligations.md](contracts/integration-obligations.md) | Specifies the foundations later 008–015 consumers must use and what they alone must prove, especially source activation and authorized review. |
| [tasks.md](tasks.md) | Ordered T001–T021 with paths, dependencies, FR/W IDs and completion conditions; makes behavioral tests precede their implementations. |
| [task-execution-plan.md](task-execution-plan.md) | Gives the future agent entry gates, red/green groups, before/after commands, checkpoints, baseline-change handling, blockers and report requirements. |
| [implementation-notes.md](implementation-notes.md) | Calls out repo traps: absent API loader, legacy admin access, sync/in-memory tests, captured settings, Temporal replay and later-feature boundaries. |
| [quickstart.md](quickstart.md) | Gives future verification and live smoke commands with expected results; labels all as future instructions. |
| [checklists/requirements.md](checklists/requirements.md) | Records documentation checks separately from unperformed runtime prerequisites. |
| [review-report.md](review-report.md) | Records initial findings, evidence, design rationale, coverage, limits and final review result as requested. |

## Fixed design choices and rationale

- Laravel remains authority for case/owner/state and 004 access; Python uses Temporal SDK behind a private signed bridge because the repository has no Laravel Temporal client. The two request directions have separate HMAC keys and fixed payload allowlists.
- The 006 case ID determines `v3-case-{case_id}`. Laravel commits one link before HTTP; a timeout is uncertain until a signed describe confirms same memo or verified absence. A closed or expired-history ID is never silently replaced.
- The bridge's 200 acknowledgement does not advance the 006 case. The real worker callback atomically records sequence 2 and changes `awaiting_workflow`/1 to `queued`/2; exact replay is harmless and concurrent distinct events conflict.
- Laravel verifies the immutable captured 005 settings row and passes only checksum and technical retry fields to the workflow. A missing/corrupt row stops before remote start. No active-pointer fallback is permitted.
- The 007 workflow publishes its real start milestone, then parks at a deterministic extraction handoff. 008 must deploy replay-compatible extraction code, persist/authorize `source_activation_v1` and send it through its own authenticated path. 007 includes no fake content work, source handler or production sender.
- The review gate is reusable deterministic Temporal code tested with synthetic candidate/decision fixtures. 012 and 013 retain qualification, original-teacher authorization, persisted review/outbox and actual signal delivery responsibility.
- Admin progress serializes only the exact 004 closed summary; teacher detail uses stored original-owner scope. Link/event rows carry safe IDs/codes only, with no 007 deletion endpoint.

## Coverage and proof boundary

`contracts/acceptance-matrix.md` maps every FR-001–FR-008 requirement and W01–W20 scenario to T001–T021 and planned test files. W01–W05/W16/W20 require live Temporal/bridge evidence for start and recovery; W06–W10/W18 require real HTTP/policy/DB boundaries; W11–W12 require captured settings and live callback retry; W13–W15 prove only the test-only gate; W17 proves honest waiting, not source processing; W19 uses controlled missing/corrupt settings fixtures. Independent-process tests must actually overlap on a shared DB. Test doubles cannot stand in for live start, callback delivery, worker restart or Temporal history.

## Final documentation review and remaining gates

The final fresh read-only cross-artifact check found 13 Markdown artifacts, 8/8 requirements represented in the matrix and tasks, 20/20 scenarios represented in the matrix and tasks, and 21 ordered tasks with valid dependencies. Every matrix test filename appears in the future quickstart; all local Markdown links resolve; no unresolved placeholder markers or trailing whitespace remain. I checked the named state, route, field and actor boundaries across the spec, workflow contract, model, matrix, tasks, notes and execution plan. The selector still reads `specs/004-v3-access-ownership`; it was never switched for this review. Other numbered spec directories retain their pre-existing untracked state and were not edited; application code was not edited. This check is documentation consistency only.

The required 004–006 application foundations are not present in the inspected workspace, and the Temporal CLI/server/worker/bridge are not present or running here; 007 cannot be called runtime implementation-ready in this checkout. The future implementer must provision them and pass the live gates. The 30-day namespace retention and history-expiry behavior must be observed/configured in its environment. There is no unresolved 007 business-rule choice identified from the authority reviewed; 008–015 remain separate implementations.

No application code or application tests were implemented or run during this documentation review. No other numbered spec was edited, and no commit or deployment was made.
