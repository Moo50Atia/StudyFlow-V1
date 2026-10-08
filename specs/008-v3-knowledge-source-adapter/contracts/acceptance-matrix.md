# 008 Acceptance Matrix

K01–K18 are stable scenarios in [spec](../spec.md). These paths are planned implementation tests, not tests run in this documentation review. A fake MCP proves ledger decisions; only a live installed MCP proves real provider behavior.

| Case | Requirements | Planned test file(s) | Expected executable outcome |
|---|---|---|---|
| K01 | FR-001,005 | `tests/Integration/V3/Sources/ActivationRaceTest.php`, `v3_engine/tests/sources/test_workflow_handoff.py` | Two independent discoverers create one activation; lost signal ack resends same ID; old 007 history replays into 008 worker. |
| K02 | FR-002,007 | `tests/Feature/V3/Sources/WorkerSourceBoundaryTest.php` | Signed matching activation streams verified own PDF; stale/foreign/bad signature/browser/admin/legacy attempts cannot fetch bytes. |
| K03 | FR-001,007 | `tests/Feature/V3/Sources/SourceAccessTest.php`, `ActivationRaceTest.php` | Suspended owner loses HTTP access; already started automated work retains immutable owner, no admin activation shortcut. |
| K04 | FR-003 | `v3_engine/tests/sources/test_extraction.py` | Page markers/order, marker-shaped source line escaping and known heading/table/equation fixture tokens preserved; one report row/page. |
| K05 | FR-002,003 | `v3_engine/tests/sources/test_extraction.py` | OCR succeeds within captured budget; zero/exceeded budget, missing language/tool or low confidence blocks before MCP. |
| K06 | FR-003 | `v3_engine/tests/sources/test_extraction.py` | Text-layer image/vector regions and OCR residual-ink diagram/formula fixtures block as visual gaps; structure/parse gaps block; no skipped/truncated page. |
| K07 | FR-003,005 | `tests/Feature/V3/Sources/ExtractionStoreTest.php` | Exact POST replay returns same ref; changed data 409; crash/orphan and missing artifact cannot look ready. |
| K08 | FR-003,007 | `tests/Feature/V3/Sources/SourceAccessTest.php` | Owner sees only gap codes/page numbers; foreign/missing equal 404; admin closed DTO unchanged. |
| K09 | FR-004,005 | `v3_engine/tests/sources/test_notebooklm_adapter.py`, `tests/Feature/V3/Sources/SourceEffectTest.php` | One notebook per case; lost create response reconciles one exact empty notebook or stays uncertain; no second create. |
| K10 | FR-004 | `v3_engine/tests/sources/test_notebooklm_adapter.py`, `tests/Integration/V3/Sources/LiveNotebookLMTest.php` | Inline canonical text only; success message alone is not binding; inventory/content gives one proven source ID. |
| K11 | FR-004,005 | `v3_engine/tests/sources/test_notebooklm_adapter.py`, `SourceEffectTest.php` | Timeout/zero/multiple/mismatch/schema drift leave uncertain/blocked; `send_count≤1` even under retry. |
| K12 | FR-005,008 | `v3_engine/tests/sources/test_notebooklm_adapter.py`, `LiveNotebookLMTest.php` | Read/query retry budget captured; create/add not resent; missing live schema blocks completion. |
| K13 | FR-004,007 | `tests/Integration/V3/Sources/SourceIsolationTest.php`, `test_notebooklm_adapter.py` | Same PDF in distinct cases/owners has distinct notebook/source rows; caller ID cannot select foreign binding. |
| K14 | FR-006 | `v3_engine/tests/sources/test_adapter_contract.py` | Bound source ID is sole `source_ids` filter; conversation passed; raw answer remains activity-local, no mapping claim. |
| K15 | FR-002,007 | `WorkerSourceBoundaryTest.php`, `tests/Feature/V3/Sources/PrivacyTest.php` | HMAC precedes lookup, web/admin/legacy bypass absent, no content/provider IDs in DTO/log/Temporal payload inventory. |
| K16 | FR-005,007 | `ExtractionStoreTest.php`, `SourceEffectTest.php`, `PrivacyTest.php` | Private exact digest/byte/attempt/proof lineage; uncertain is not counted confirmed; no delete route. |
| K17 | FR-004,006,008 | `tests/Integration/V3/Sources/LiveNotebookLMTest.php`, `v3_engine/tests/sources/test_live_mcp.py` | Real pinned MCP creates isolated notebook, adds text, resolves exact source content and answers a source-scoped question about a unique fixture fact. |
| K18 | FR-001,003,004,008 | `v3_engine/tests/sources/test_workflow_handoff.py`, `tests/Integration/V3/Sources/LiveSourceWorkflowTest.php` | Persisted 007 history replays with test-only activity fixtures; separately, live PDF/MCP workflow resumes, records safe extraction/sourcing events and parks before 009 with one proven bound source. |

## Requirement-to-task trace

| Requirement | Tasks |
|---|---|
| FR-001 | T001, T005–T008, T024 |
| FR-002 | T001, T009–T012, T024 |
| FR-003 | T003–T004, T011–T014, T024 |
| FR-004 | T015–T017, T020–T021, T024 |
| FR-005 | T003–T006, T013–T017, T020–T021, T024 |
| FR-006 | T015, T018–T021, T024 |
| FR-007 | T003–T004, T009–T010, T013–T014, T022–T024 |
| FR-008 | T001, T007, T020–T021, T024 |

`ActivationRaceTest.php` and `SourceIsolationTest.php` use separate processes/connections on a shared disposable DB with a barrier; sequential requests or SQLite `:memory:` do not prove concurrency. `LiveSourceWorkflowTest.php` uses a real Laravel server, 007 bridge, Temporal worker and private PDF; `LiveNotebookLMTest.php`/`test_live_mcp.py` use the installed selected MCP and a disposable account. Skipped live tests are mandatory incomplete gates, not passing evidence.
