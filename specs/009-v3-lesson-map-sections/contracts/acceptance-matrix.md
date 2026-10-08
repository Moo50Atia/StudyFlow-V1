# 009 Acceptance Matrix

L01–L13 are stable cases in [spec](../spec.md). Paths below are future implementation tests, not tests run in this documentation review. Fake 010 receipts prove 009's gate mechanics only; installed NotebookLM and persisted Temporal history are separate live gates.

| Case | Requirements | Planned test files | Expected executable outcome |
|---|---|---|---|
| L01 | FR-001 | `tests/Integration/V3/Lessons/MapActivationRaceTest.php`, `v3_engine/tests/lessons/test_workflow_handoff.py`, `tests/Feature/V3/Lessons/MapEventProjectionTest.php` | Separate discoverers create one activation; stale binding rejected; same-ID signal replays old workflow; unproven map block rejected. |
| L02 | FR-002,008 | `v3_engine/tests/lessons/test_map_parser.py`, `v3_engine/tests/lessons/test_live_mapping.py` | Exact Divide digest and source-scoped live NotebookLM structured/heading-poor map; no local unit finder. |
| L03 | FR-002 | `v3_engine/tests/lessons/test_map_parser.py`, `tests/Feature/V3/Lessons/MapAcceptanceTest.php` | Duplicate titles remain distinct; empty Mini promoted; empty Chapter/orphan/invalid nesting blocks. |
| L04 | FR-003,008 | `v3_engine/tests/lessons/test_coverage_parser.py`, `v3_engine/tests/lessons/test_live_mapping.py` | Separate coverage reply covers every 008 page and every W; GAP/unknown/missing page or over-16,384-byte complete request blocks without truncation. |
| L05 | FR-003,005 | `tests/Feature/V3/Lessons/MapAcceptanceTest.php`, `tests/Integration/V3/Lessons/MapAcceptanceRaceTest.php` | Equivalent replay returns one immutable map; changed answer/overlap conflicts; no partial cursor. |
| L06 | FR-004 | `v3_engine/tests/lessons/test_first_parser.py`, `tests/Feature/V3/Lessons/FirstSectionTest.php`, `v3_engine/tests/lessons/test_live_mapping.py` | Scoped exact First body yields complete ordered Sections/raw artifact for W1. |
| L07 | FR-004 | `v3_engine/tests/lessons/test_first_parser.py`, `tests/Feature/V3/Lessons/FirstSectionTest.php` | Missing/duplicate/reordered label, wrong identity, empty required value block; explicit empty laws retained. |
| L08 | FR-006 | `tests/Feature/V3/Lessons/LessonAccessTest.php`, `tests/Integration/V3/Lessons/LessonIsolationTest.php` | Owner sees all fields; foreign/missing 404 equal; inactive/admin/legacy paths cannot read V3 content. |
| L09 | FR-005,007 | `tests/Feature/V3/Lessons/LessonCursorTest.php`, `tests/Integration/V3/Lessons/ContinueRaceTest.php`, `v3_engine/tests/lessons/test_cursor.py` | Signed full-set test receipt gates one literal Continue claim; duplicate/concurrent calls send at most once. |
| L10 | FR-005 | `tests/Feature/V3/Lessons/LessonCursorTest.php`, `v3_engine/tests/lessons/test_query_once.py`, `v3_engine/tests/lessons/test_cursor.py` | Lost answer/conversation/outage or wrong next ordinal leaves old cursor uncertain/blocked, no second send. |
| L11 | FR-004,005 | `tests/Feature/V3/Lessons/LessonCursorTest.php`, `tests/Feature/V3/Lessons/FirstSectionTest.php`, `v3_engine/tests/lessons/test_cursor.py` | Next raw answer/Sections/cursor commit atomically; final completes without Continue; restart reads durable version. |
| L12 | FR-006,007 | `tests/Feature/V3/Lessons/LessonPrivacyTest.php`, `tests/Feature/V3/Lessons/WorkerLessonBoundaryTest.php` | HMAC before lookup; no answer/prompt/IDs in admin/Temporal/log/errors; private evidence distinguishes uncertain. |
| L13 | FR-001,002,003,004,008 | `tests/Integration/V3/Lessons/LiveLessonWorkflowTest.php`, `v3_engine/tests/lessons/test_live_mapping.py` | Real 007/008 history reaches bound-source map, coverage and First Sections, then waits for 010; no mock certifies grounding. |

## Requirement-to-task trace

| Requirement | Tasks |
|---|---|
| FR-001 | T001,T005–T008,T023 |
| FR-002 | T002,T009–T010,T013–T014,T019–T020,T023 |
| FR-003 | T009–T014,T019–T020,T023 |
| FR-004 | T002–T004,T009–T010,T015–T016,T019–T020,T023 |
| FR-005 | T003–T004,T011–T018,T023 |
| FR-006 | T003–T004,T011–T012,T021–T023 |
| FR-007 | T002,T017–T018,T023 |
| FR-008 | T001,T007–T008,T019–T020,T023 |

`MapActivationRaceTest.php`, `MapAcceptanceRaceTest.php` and `ContinueRaceTest.php` must use two real processes/connections, a barrier and shared disposable DB. SQLite `:memory:`/sequential requests do not prove the races. `LiveLessonWorkflowTest.php` uses real Laravel, bridge, Temporal worker and disposable NotebookLM source. An absent account/server or skipped test is a mandatory incomplete gate.
