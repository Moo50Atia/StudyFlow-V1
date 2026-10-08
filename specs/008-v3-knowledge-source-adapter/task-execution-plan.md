# Agent Task Execution Plan: 008

This is the direct implementation plan. Execute [tasks.md](tasks.md) T001–T024 in order with [contract](contracts/knowledge-source-adapter.md), [model](data-model.md) and [matrix](contracts/acceptance-matrix.md). Keep 009+ fixtures in tests; do not build their production models/routes to make 008 look complete.

## Entry conditions and baseline

1. Confirm 004–007 application code and passing prerequisite suites: active original-owner policy and closed admin DTO, checksum-verified captured 005 settings, 006 ready same-owner PDF/`AcceptedPdfReader` and private disk, 007 fixed workflow/HMAC bridge/safe event projector. If absent, record the missing contract and stop dependent runtime implementation; do not clone these services inside 008.
2. Record `git status --short` and preserve all pre-existing changes. Record baseline `php -v`, `python3 --version`, `node --version`, `pdftoppm -v`, `tesseract --version`, `temporal --version`, pinned MCP version/tool inventory, route list, migration state and actual 004–007 test commands/results. Attribute pre-existing failures to their owner; continue only independent work while a gate is blocked.
3. Provision disposable shared DB/private disk, local Temporal server with persisted history, 007 bridge/callback keys/queue/scheduler, Poppler/Tesseract OCR languages and a dedicated disposable NotebookLM account/session. Pin the selected MCP package, inspect `tools/list` and do not put cookies in tracked config or logs. Use loopback or TLS across private hosts.

## Ordered red/green groups

| Group | Tasks | Before implementation | After/checkpoint |
|---|---|---|---|
| Schema | T001–T004 | T002 makes table/package harness runnable; T003 asserts actual FK/unique/immutable constraints | Run `SourceSchemaTest.php` on SQLite and deployment-equivalent DB; four tables, no owner/provider-content leak |
| Activation | T005–T006 | Run `ActivationTest.php` and true overlapping `ActivationRaceTest.php` after command skeleton | Re-run green; one activation ID, after-commit job, same-ID uncertain signal resend |
| Temporal handoff | T007–T008 | Replay a persisted real 007-started history in `test_workflow_handoff.py` with test-only extraction/ingestion activity fixtures; assert missing 008 signal/replay behavior | Re-run green after 008 worker; one fixture activity chain, duplicate/foreign signal no-op, safe stages, wait at 009. This proves workflow control only; T020–T021 prove real extraction and ingestion. |
| PDF boundary | T009–T010 | Mount HMAC route skeleton and run `WorkerSourceBoundaryTest.php` through real middleware/006 reader | Re-run green; bad signature fails before lookup, only matching activation receives verified stream |
| Extraction | T011–T012 | Run fixture `test_extraction.py` for page order, table/equation, OCR cap and structural gaps | Re-run green with installed binaries; inspect exact canonical text/digest/report and temp cleanup |
| Private artifact | T013–T014 | Run `ExtractionStoreTest.php` with crash and changed replay | Re-run green; DB/file recovery, private verified text read, orphan cleanup and no MCP before ready |
| External effects | T015–T017 | Run `SourceEffectTest.php` and fake `test_notebooklm_adapter.py` with lost response after provider effect | Re-run green; one committed claim, max one create/add send, source ID from inventory/content only |
| Query contract | T018–T019 | Run `test_adapter_contract.py` for source scope/conversation/raw answer handling | Re-run green; 009 receives a method contract, no production 009 answer model or workflow content |
| Live provider | T020–T021 | Run live MCP/Temporal tests, including source-scoped query, against disposable account; record behavioral red or capability gate | Re-run green; inspect pinned `tools/list`, notebook/source IDs/content proof, exact transfer record, scoped query and replayed 007 history |
| Security/views | T022–T023 | Add a runnable source-status route/controller skeleton, then run real 004 HTTP/legacy/admin/privacy assertions and independent-case isolation | Re-run green; equal foreign/missing 404, private/no-store, safe closed DTO/log/route inventory |
| Final | T024 | Re-run known baseline failures independently | Run [quickstart](quickstart.md), all affected 004–008 suites and live smoke; write implementation report |

Missing imports, missing tool binaries, unsigned route skeletons or a skipped live service are not meaningful failing assertions. Make the harness runnable first. Existing passing 004–007 behavior needs regressions, not manufactured red. Activation/isolation contention uses two separate processes/connections with a barrier and a shared disposable DB; sequential requests and SQLite `:memory:` cannot prove races. Fake MCP responses may prove local decisions but cannot prove provider source identity, content round trip, rate limits or true grounding.

## Failure/stop conditions

- A lost create/add response leaves `send_count=1` and state uncertain. Reconcile by read-only MCP inventory/content; never send again merely because a list is empty or unavailable. No source ID from a success message alone.
- A source gap, cap overrun, corrupt accepted PDF or missing captured settings stops before external transfer. Do not truncate/summarize source or substitute current 005 settings. A deterministic gap yields a safe terminal `source_blocked` event; provider uncertainty stays running/delayed with an operator warning.
- A mismatch between case/activation/Document/settings, an HMAC bypass, a foreign-owner leak, divergent private file/digest, provider title collision or exact-content mismatch stops rollout. Capture only safe IDs/codes, not teacher content.
- If Tesseract/required language, selected MCP account/tool schema, source round-trip, private transport or real Temporal history replay cannot be demonstrated, report the specific mandatory gate. Continue isolated tests where useful but do not claim SC-001/SC-002 live proof.
- 009 mapping and answer persistence, 010 JEV, scenes, review and admin operational retries are later-spec work. 008 ends at one bound source and an honest 009 wait.

## Completion and implementation report

Complete 008 only when K01–K18 have meaningful evidence at their stated proof level, all 004–007 affected regressions pass, real OCR and signed PDF handoff pass, independent-process races pass, and live selected MCP/Temporal integration proves a source-content match and scoped query. A fake adapter or documentation check alone does not satisfy these criteria.

The implementation report must list task IDs/files, pre-existing workspace state, baseline/final commands/results, behavioral red/green checkpoints, separate-process race setup, pinned Python/OCR/MCP versions and installed tool schemas, disposable account/namespace and private transport configuration, DB/artifact/Temporal/provider ID comparison, forced lost-response outcomes, transfer proof, owner/admin/legacy privacy checks, deviations, unresolved blockers and 009–015 handoff obligations. Do not commit or deploy without separate direction.
