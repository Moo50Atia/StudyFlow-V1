# Future Implementer Verification: 007

These commands are **instructions for the future implementer after application work**, not results of this documentation review. Run from `/home/moahmmed/college_project` with implemented 004–006, disposable Laravel DB, local Temporal development server, Python 3.12 environment, private bridge/worker callback keys and isolated ports. Never point smoke tests at production cases.

```sh
php -v
python3 --version
php artisan --version
temporal --version
php artisan route:list --path=v3
php artisan route:list --path=api/internal/v1
php artisan schedule:list
php artisan migrate:status
```

Expect the three 007 progress routes behind 004's role gates, one HMAC-only API callback, the one-minute `v3:dispatch-workflows` schedule and 007 migration. No teacher/admin review signal or source-content route exists. If Temporal CLI is missing, provision it before claiming live integration.

```sh
python3 -m pip install -e 'v3_engine[dev]'
php artisan test tests/Feature/V3/Workflow/WorkflowSchemaTest.php
php artisan test tests/Feature/V3/Workflow/WorkerBoundaryTest.php tests/Feature/V3/Workflow/EventProjectionTest.php
php artisan test tests/Feature/V3/Workflow/WorkflowStartTest.php tests/Integration/V3/Workflow/WorkflowStartConcurrencyTest.php
php artisan test tests/Integration/V3/Workflow/EventConcurrencyTest.php
php artisan test tests/Feature/V3/Workflow/ProgressAccessTest.php tests/Feature/V3/Workflow/PrivacyTest.php tests/Feature/V3/Workflow/HandoffContractTest.php
python3 -m pytest v3_engine/tests/workflow/test_start_bridge.py v3_engine/tests/workflow/test_retry_policy.py v3_engine/tests/workflow/test_case_workflow.py v3_engine/tests/workflow/test_review_gate.py
php artisan test tests/Feature/V3/Workflow tests/Integration/V3/Workflow
php artisan test tests/Feature/V3/Access tests/Feature/V3/Settings tests/Feature/V3/Intake tests/Integration/V3/Settings tests/Integration/V3/Workflow
```

Expect all applicable tests green. Concurrency tests must report actual overlapping independent processes against shared DB; the Python tests must use a real Temporal test server for start/replay and time skipping for review wait. A skipped/download-blocked Temporal CLI or mocked bridge is an incomplete live gate, not a passing integration.

For a live disposable smoke, run the development server with a disposable persistent DB in one terminal, then configure and verify the pilot namespace retention from another terminal. The commands below assume `V3_TEMPORAL_NAMESPACE=default`; substitute the configured namespace otherwise. This retention setting is a reconciliation precondition, not evidence that history actually survives an outage.

```sh
temporal server start-dev --db-filename /tmp/v3-temporal-test.db
temporal operator namespace update --namespace default --retention 30d
temporal operator namespace describe --namespace default
```

With `V3_TEMPORAL_ADDRESS`, `V3_TEMPORAL_NAMESPACE`, `V3_BRIDGE_HMAC_KEY`, `V3_CALLBACK_HMAC_KEY`, `V3_BRIDGE_URL` and the Laravel disposable DB configured, run these in separate terminals:

```sh
python3 -m uvicorn v3_engine.bridge:app --host 127.0.0.1 --port 8765
python3 -m v3_engine.worker
php artisan queue:work v3_workflow --queue=v3_workflow --timeout=30 --tries=1
```

Then create a disposable valid 006 case, and run:

```sh
php artisan v3:dispatch-workflows
php artisan v3:dispatch-workflows
php artisan test tests/Integration/V3/Workflow/LiveBridgeTest.php
```

Expect one `v3_workflow_links` row, one Temporal workflow ID `v3-case-{case_id}`, one `workflow_started` event with sequence 2, case state `queued`/version 2, and no real source/prompt/scene events. Kill/restart the Python worker and repeat reconciliation; the case stays the same and the open workflow resumes. Force lost start/callback acknowledgements in the integration test; one link/event remains. A merely stopped worker shows delayed progress after five minutes, while a verified closed failure produces one safe terminal event. Compare teacher own progress, foreign/missing 404, admin exact closed DTO and no-store headers through the real HTTP tests. Record Temporal namespace retention (minimum 30 days for pilot reconciliation) and live bridge/queue/scheduler configuration in the implementation report. The synthetic review gate does not prove 013 approval.
