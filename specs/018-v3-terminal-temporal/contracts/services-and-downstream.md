# Service Integration and Future Consumers

## NotebookLM ports

Extend `v3_engine/notebooklm/ports.py`/`sdk_provider.py` compatibly with:

- `list_sources(notebook_id)` returning typed inventory receipts, including stable ID/title/status/MIME.
- `current_conversation_id(notebook_id)` returning ID or null with explicit error on ambiguous discovery; existing `history` continues to return ordered complete pairs or an explicit incomplete-history outcome.
- `verify_owner(proof_notebook_id)` returning `owner|not_owner|unknown` from explicit SDK owner role (never default `is_owner`). Preserve existing NotebookRef constructor compatibility.
- Typed provider rate-limit retry-after and service/transport distinctions without leaking raw details. Existing consumers retaining provider_unavailable behavior must remain compatible.

New `NotebookTerminalService` in `v3_engine/notebooklm/terminal_service.py` receives injected SessionProvider, local operation store, credential source and event sink. Methods: `verify_session`, `create_notebook`, `upload_source`, `poll_sources`, `configure_chat`, `send_turn`, `reconcile`, `capture_turn`, `freeze_handoffs`; arguments are local run/operation refs and captured settings. These return local result objects used by Activity wrappers, not Temporal clients. Keep `Run`/old CLI flags/output formats as compatible facades and regression-test existing behavior. Remove direct `.client` accesses from the extracted business flow; vendor SDK stays in its adapter.

Keep Divide/Execution constants, selected framework and existing COMBINED_RESPONSE_FORMAT_TEXT unchanged. New Temporal run always uses permissive capture: raw answer saved, optional parse warnings retained, arbitrary types and variable counts; unknown map count is still a blocker. A missing/incorrect System Ready produces a warning in this demo rather than silently editing the execution prompt. No new model supplies missing content or judges successful output.

## Scene service

Extend `SceneGenerationService` with `initialize_run`, `generate_one`, `restore_receipt`, `materialize_source`, `build_one`, `summarize`; use injected provider/store/builder as today. Use the existing response/source/build schemas and scene-key identity. A method operates on one captured scene/attempt and records evidence before returning; restored complete receipts never trigger a provider request. `execute_run` and current CLI remain facades over these steps, preserving retry/limit behavior and arbitrary type support.

Extend artifact store ownership marker/epoch checking with optional non-Temporal owner context; standalone code has no Temporal import. Standalone invocation rejects managed roots, including between Activities. Existing ordinary standalone roots remain usable. Build wrapper uses managed subprocess groups and asynchronous heartbeat support; extend SceneBuilder compatibly with an optional cancellation/progress interface, retaining existing command/scaffold/environment allowlist and no provider credentials in builds.

## Operational projection

SQLite journal fields are in [data model](../data-model.md). Kinds: submission_staged, submission_accepted, subject_queued, subject_started, phase_changed, operation_prepared, operation_dispatched, receipt_saved, operation_completed, operation_failed, operation_uncertain, source_polled, lesson_saved, scene_discovered, scene_generated, build_started, build_completed, build_failed, control_requested, control_applied, control_rejected, reconciliation_started, reconciliation_completed, subject_finished. Required terminal event failures block subsequent dispatch. Stable IDs deduplicate replay and local retries; actual distinct attempts/polls are not collapsed.

Native UI indexes `TerminalSubmissionId`, `TerminalSubjectRunId`, `TerminalPhase`, `TerminalOutcome` as Keyword, and `TerminalLessonsSaved`, `TerminalScenesGenerated` as Int; register only in the terminal namespace. Display title/prompt/content is not indexed. Workflows update these at recorded boundaries. Stage strings and counts match CLI/report; unknown totals are null in projection and unindexed. Structured workflow logger is safe and replay-aware; logs CLI exports the journal, not raw SDK stdout. Npm/TS/raw provider diagnostics stay private build/receipt artifacts; safe error codes/refs appear in operational logs.

## What this feature supplies and what later features must do

| Consumer | Proved here | Later responsibility |
|---|---|---|
| Terminal NotebookLM | New multi-PDF notebook, serial map/execution/Lesson loop, saved handoffs and reconciled attempts | App case/document association and capture of approved processing settings |
| 017 scene generator | Immutable handoff/provider/template provenance, retained response/source/builds, selected retry and truthful waiver | Production candidate transport is separate from its local React build manifest |
| Laravel integration | Opaque stable run/operation/artifact identities and safe progress projection contract | Real authenticated routes, teacher ownership, durable DB projection/outbox and protected alternate paths; do not derive identity from local operator |
| 010/012/013 | No production qualification or approval is proved | Actual typed JEV, browser safety/interaction checks, repair and original-teacher review; demo waiver is never equivalent |
| 015 evidence/admin operations | Safe journal metadata and private linked artifacts | Owner-scoped DB evidence, production retention/access and admin metadata-only projections |

No future-feature fixtures enter production; no callbacks, empty application rows or fake approval are created to make the local feature appear complete. Existing worker/workflow histories remain unchanged. This is a new local terminal path, not a claim that every old application route is now observable through it.
