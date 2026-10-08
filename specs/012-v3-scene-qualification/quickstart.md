# Future Implementer Verification: 012

These are **proposed commands for the future implementation agent**, not results of this documentation review. Run from `/home/moahmmed/college_project` after 004–011 are implemented. Use an isolated shared PostgreSQL test DB for the concurrency command and a safe deployment with a current 011 candidate and selected JEV credentials for the live command.

```bash
git status --short
php artisan route:list --path=api/internal/v1/cases
php artisan route:list --path=v3/teacher/cases
docker build -f v3_engine/qualification/Dockerfile -t v3-scene-checker:012-v1 .
python3 -m pytest v3_engine/tests/qualification/test_container.py v3_engine/tests/qualification/test_browser.py -m container
php artisan test tests/Feature/V3/SceneQualification
python3 -m pytest v3_engine/tests/qualification -m "not live"
DB_CONNECTION=pgsql DB_URL="$V3_TEST_DB_URL" php artisan test tests/Feature/V3/SceneQualification/ConcurrencyTest.php
php artisan test
python3 -m pytest v3_engine/tests
V3_SCENE_QUAL_LIVE=1 python3 -m pytest v3_engine/tests/qualification/test_live_gate.py -m live
```

Provision `V3_TEST_DB_URL` as an isolated shared PostgreSQL test database URL and the selected TypeSafe `TYPESAFE_API_KEY`/`JEV_MODEL` through the worker's protected environment, never command arguments. The full Python suite may skip the opt-in 012 live test when `V3_SCENE_QUAL_LIVE` is unset; the final explicit live command must run it without skip. Expected: real container shows nonroot sandbox/no host mount or credential and blocks hostile external/host requests; valid local scene loads and has observable pointer and keyboard behavior; broken/unsafe scene cannot call JEV or become review eligible. Laravel HMAC and 004 owner routes deny foreign/admin/V2/public bypass, qualification uses exact typed values/thresholds, and a real two-process DB race yields one repair slot. The live test must obtain an actual selected TypeSafe response and persist private answer/qualification evidence for a representative current candidate. A container mock, JSON fixture or live JEV call without the browser gate does not satisfy the final gate. If the OCI policy, candidate evidence size or selected model prevents the live test, record the failed prerequisite and leave completion unclaimed. Do not print prompt, files, Section fields, screenshot, answer or credentials in test output/report.
