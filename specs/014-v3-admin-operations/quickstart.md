# Future Implementer Verification: 014

These are **proposed commands for the future implementation agent**, not results of this documentation-only review. Run from `/home/moahmmed/college_project` after 004–013 and their required gates exist. `V3_TEST_DB_URL` must name an isolated shared PostgreSQL test database for the two-process race. The live gate also needs a real Temporal worker/bridge with test credentials and a disposable case; do not print teacher content, provider answers or secrets.

```bash
git status --short
php artisan route:list --path=v3/admin
php artisan route:list --path=api/internal/v1/admin-control-results
php artisan test tests/Feature/V3/Admin
DB_CONNECTION=pgsql DB_URL="$V3_TEST_DB_URL" php artisan test tests/Feature/V3/Admin/AdminCommandRaceTest.php
python3 -m pytest v3_engine/tests/admin -m "not live"
php artisan test
python3 -m pytest v3_engine/tests
V3_ADMIN_LIVE=1 python3 -m pytest v3_engine/tests/admin/test_live_gate.py -m live
```

Expected: active admin sees only the fixed operations wrapper and safe health/backlog values; guest/teacher/student/inactive admin and real V2/013 alternate routes cannot reveal V3 content. A shared-DB race yields one serialized hold-versus-claim order. Command replay gives one audit/fence/signal; a lost bridge acknowledgement reconciles by the same ID/version; an in-flight known result stays private and cannot continue a cancelled case. The live test must exercise a real signed bridge and Temporal worker, including hold/resume/cancel, callback proof and workflow closure. Account tests must exercise 004/V2 routes, and deletion assessment must remove zero files/rows. Record commands, exit codes, skips and timing. A fake bridge or skipped live gate does not establish completion.
