# Future Implementer Verification: 009

These commands are **instructions after implementation**, not commands passed during this documentation-only review. Run from `/home/moahmmed/college_project` after 004–008 pass. Use disposable shared DB/private disk, persisted Temporal, 007/008 bridge/worker/queue, and a disposable NotebookLM account/source with known page and Section facts. Do not use production teacher PDFs or notebooks.

```sh
php -v
python3 --version
temporal --version
php artisan route:list --path=v3
php artisan route:list --path=api/internal/v1
php artisan schedule:list
php artisan migrate:status
python3 -m pip install -e 'v3_engine[dev]'
```

Expect 004–008 routes plus owner-only lesson-map/Section routes, signed 009 worker routes, one-minute map dispatch/reconcile, seven 009 tables and a runnable Python package. Record actual selected MCP `tools/list` and the five runtime/canonical SHA-256 matches from [contract](contracts/lesson-structure.md). Missing binaries/schema/account are incomplete gates.

```sh
php artisan test tests/Feature/V3/Lessons/LessonSchemaTest.php tests/Feature/V3/Lessons/MapActivationTest.php tests/Feature/V3/Lessons/MapEventProjectionTest.php
php artisan test tests/Integration/V3/Lessons/MapActivationRaceTest.php
python3 -m pytest v3_engine/tests/lessons/test_workflow_handoff.py v3_engine/tests/lessons/test_map_parser.py v3_engine/tests/lessons/test_coverage_parser.py v3_engine/tests/lessons/test_first_parser.py
php artisan test tests/Feature/V3/Lessons/WorkerLessonBoundaryTest.php tests/Feature/V3/Lessons/MapAcceptanceTest.php tests/Integration/V3/Lessons/MapAcceptanceRaceTest.php
python3 -m pytest v3_engine/tests/lessons/test_query_once.py v3_engine/tests/lessons/test_lesson_processor.py
php artisan test tests/Feature/V3/Lessons/FirstSectionTest.php tests/Feature/V3/Lessons/LessonCursorTest.php tests/Integration/V3/Lessons/ContinueRaceTest.php
python3 -m pytest v3_engine/tests/lessons/test_cursor.py
php artisan test tests/Feature/V3/Lessons/LessonAccessTest.php tests/Feature/V3/Lessons/LessonPrivacyTest.php tests/Integration/V3/Lessons/LessonIsolationTest.php
```

Expect all isolated behavior tests green. Race reports must show overlapping independent processes/connections on shared DB. A fake 010 receipt proves gating and duplicate handling, never actual Third-output persistence. A fake NotebookLM answer proves parsing/ledger behavior, never provider grounding.

For live disposable integration, start persisted Temporal and the real 007/008 Laravel bridge/worker/queue in separate terminals using their prerequisite quickstarts, then run:

```sh
temporal server start-dev --db-filename /tmp/v3-temporal-map-test.db
php artisan v3:dispatch-maps
python3 -m pytest v3_engine/tests/lessons/test_live_mapping.py
php artisan test tests/Integration/V3/Lessons/LiveLessonWorkflowTest.php
```

The first command stays running; execute later commands in other terminals. Expect a real 008-bound case and existing workflow ID to yield a known Chapter/Mini/Lesson order, page coverage 1..N, exact First fields, retained private answer and a cursor at `awaiting_010`. Inspect safe mapping events and verify no provider/conversation ID or answer in Temporal/admin/logs. A live provider formatting or conversation mismatch is a blocked gate, not a passing mock result. This smoke does **not** claim the later 010 Third/Continue cycle has run.

```sh
php artisan test tests/Feature/V3/Lessons tests/Integration/V3/Lessons
python3 -m pytest v3_engine/tests/lessons
php artisan test tests/Feature/V3/Access tests/Feature/V3/Settings tests/Feature/V3/Intake tests/Feature/V3/Workflow tests/Feature/V3/Sources
```

Expect affected 004–008 regressions and all 009 tests green, with live versus doubled evidence reported separately. No application implementation or runtime test is claimed to have run in this documentation review.
