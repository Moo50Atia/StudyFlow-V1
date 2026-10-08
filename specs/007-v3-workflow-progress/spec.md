# Feature Specification: V3 Workflow Start and Progress

**Reviewed:** 2026-10-02 · **Status:** documentation design · **Prerequisites:** implemented 004 access, 005 captured settings and 006 ready Document/ProcessingCase contracts.

## Purpose and boundary

007 connects a committed 006 `awaiting_workflow` case to one Temporal workflow identity and projects safe ordered milestones into Laravel. Laravel owns the case, original teacher, status, authorization and review records; Temporal owns durable execution and waits. A case may exist while Temporal is unavailable and must remain visible as delayed, never be replaced with another case. 007 supplies a real start/callback path, progress pages, a captured-settings retry policy and a reusable durable review gate. It does not perform PDF extraction, NotebookLM calls, mapping, prompt/JEV qualification, scene generation or a teacher decision. Those producers are 008–013. The initially started workflow truthfully waits for the 008 source integration; synthetic later-stage tests do not prove a live content pipeline.

Teacher and admin are the pilot roles. The original teacher owns case detail and eventual final-scene review. Admin receives only 004's closed operational metadata projection. No worker credential is a teacher/admin session. V2 is reference-only; KOS/KIE and multitenancy are future work. Canonical 009 prompts, all eleven First-step Section fields and direct use of successful typed JEV Choice/Score/Noul decisions are untouched.

## Stories and stable acceptance cases

### US1 — A committed case starts once and stays visible

- **W01** A 006 case remains `awaiting_workflow` until the real worker's `workflow_started` callback commits, even if Temporal has already acknowledged the deterministic `v3-case-{case_id}` identity. Repeated discovery, queued jobs and concurrent starters link one execution and never create a second case.
- **W02** A start timeout, lost response or crash after remote acceptance enters `uncertain`; reconciliation describes that same workflow ID, verifies case/settings memo and adopts its run ID. A verified not-found result may retry start with reject-duplicate policy. An unverified result never becomes a new workflow ID.
- **W03** Temporal/bridge outage leaves an honest `awaiting_workflow` case and a safe delayed indication after five minutes. Recovery retries without changing owner/settings; a 24-hour outage raises a fixed operator alert but never silently discards the case.
- **W04** The real Python worker publishes a `workflow_started` callback before waiting for 008. Laravel atomically stores one event and changes case state/version from `awaiting_workflow`/1 to `queued`/2. Replayed callback or late start acknowledgement is a no-op.
- **W05** A case whose original teacher is inactive before remote start is deferred without transferring ownership or starting external work; restoration permits the same case/workflow ID to start. 004 still denies the teacher's HTTP access while inactive.

### US2 — Progress is ordered, private and accurate

- **W06** Owning active teacher gets the case's ordered safe milestones and current stage/status through the real progress route; another teacher receives the same 404 body as a missing case, and inactive/student/admin actors cannot use that teacher route.
- **W07** Active admin can list and inspect only 004's closed metadata DTO, including an `awaiting_workflow` case. No owner name/email, filename/hash/path, source, notebook ID, prompt, scene, review notes, provider output or arbitrary nested metadata appears in HTTP responses/logs.
- **W08** A signed worker event with expected case version and unique event key commits case projection and immutable event atomically. Exact replay returns the original result; same key with changed payload is 409, stale version is 409, unknown code/schema or wrong workflow/case/run is rejected with no state change.
- **W09** Real concurrent distinct events with one expected version yield one commit and one stale conflict. Pagination is owner-scoped before counting and stable by sequence. No percentage or total scenes are invented before later records exist.
- **W10** Callback authentication uses a separate worker key and API route, not web/admin cookies. Missing, stale or bad signature fails before model lookup; no public/legacy route or signed storage URL reveals progress/content.

### US3 — Durable retry and review foundations

- **W11** Laravel loads the case's stored 005 version and passes its verified retry fields to the 007-owned milestone callback activity at workflow start. `technical_retries` means additional attempts after the first; `retry_delay_seconds` is the initial backoff. Restart/replay does not read the active pointer or duplicate the event.
- **W12** Retryable callback/network failure resumes within the captured budget; typed validation/policy failure does not retry; exhaustion results in a safe failed projection when Temporal reports a closed failure. A worker that is merely offline leaves the workflow open and progress delayed, not falsely failed.
- **W13** A reusable Temporal review gate waits without occupying an activity worker, retains a qualified candidate reference/version supplied later by 012 and accepts at most one decision ID for that version. Duplicate identical signals are no-ops; stale/conflicting versions do not advance.
- **W14** 007 exposes no teacher review/approval endpoint or production ReviewSignal row. 013 must commit and authorize the actual owner decision before signaling the gate; a test-only fixture proves wait behavior but not live 013 authorization or candidate qualification.
- **W15** Review wait persists through worker restart and time skipping. No dormancy timer automatically approves; 013 owns its captured `review_dormancy_days` reminder/cancel behavior and 014 owns admin operational commands.

### US4 — Failure and handoff boundaries

- **W16** If event delivery is uncertain, the activity retries the same event key; Laravel duplicate handling returns the committed version. If Temporal closes unexpectedly, reconciliation records one safe terminal event and does not restart the workflow.
- **W17** The production 007 workflow has no fake extraction/NotebookLM/JEV/coding activity. After its start milestone it stays queued at the extraction handoff until 008 supplies real work; tests use synthetic adapters only inside test files.
- **W18** 007 retains minimal workflow links/events and safe timestamps for pilot history. It neither deletes 006 owner/settings/source nor logs request payloads or secrets; 015 later defines retention/export while preserving referenced evidence.
- **W19** A missing or checksum-corrupt captured 005 version prevents the first remote start, leaves the same case/link pending and emits only `settings_unavailable`; restoring the exact immutable version from trusted backup permits that case to start without reading the active settings pointer or selecting a new version.
- **W20** If an uncertain start's Temporal history is verified absent after the 30-day reconciliation window, 007 marks `history_missing`, alerts an operator and does not issue another start; an unavailable Temporal service never counts as verified absence.

## Functional requirements

- **FR-001** Discover committed 006 cases and establish at most one deterministic Temporal workflow identity per case, including concurrent starts and uncertain-result reconciliation.
- **FR-002** Persist the 006 case's immutable owner/Document/settings lineage and a monotonic Laravel case state/event projection; never derive access from Temporal IDs.
- **FR-003** Expose exact teacher progress and admin closed-metadata routes under 004's current role/owner boundary, including legacy-route bypass checks.
- **FR-004** Authenticate, validate, deduplicate and transactionally apply worker milestones with expected-version concurrency control and safe failure codes.
- **FR-005** Apply 005 captured technical retry policy to 007-owned activities and offer a tested policy helper for later activities without pretending their effects are idempotent today.
- **FR-006** Keep started workflow and Laravel projection recoverable across queue/bridge/worker failure; distinguish delayed open work from terminal failure.
- **FR-007** Provide a durable version-correlated review-wait primitive with duplicate/stale signal handling; reserve actual teacher authorization and review persistence for 013.
- **FR-008** Keep case/event/admin/log/Temporal payloads to allowlisted operational IDs/codes; retain pilot evidence and prohibit teacher content in admin or ordinary worker telemetry.

## Success criteria and proof limit

- **SC-001** W01–W05, W16, W19 and W20 pass against a real local Temporal development server plus real Laravel/Python bridge where applicable, including forced lost acknowledgement and worker restart; captured-settings corruption and 30-day expiry use controlled Laravel clock/database fixtures. Mocked clients alone do not prove durable start.
- **SC-002** W06–W10 and W18 pass through actual 004 routes/policies and database transactions; real independent-process contention proves W09.
- **SC-003** W11–W15 pass with Temporal time-skipping/worker-restart tests and a captured 005 row. Synthetic review candidates prove only the gate, not 012 qualification or 013 owner review.
- **SC-004** W17 is verified by route/worker inventory and one live case remaining queued at the 008 handoff. 008–013 later prove their own content activities and final-scene review.

## Dependencies and exclusions

004/005/006 are currently specified but absent from inspected application code, so 007 runtime implementation waits for them. The repo has Laravel 12/PHP 8.2, Pest and a default SQLite-memory/sync-queue test setup; it has no `v3_engine`, Temporal SDK/server, V3 routes, case tables or worker bridge. A provisioned Temporal namespace/server, Python environment, private bridge transport, Laravel queue worker and scheduler are required for live completion. No application code or runtime tests are changed in this documentation review.
