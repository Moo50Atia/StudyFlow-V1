# Future Implementer Verification: 013

These are **proposed commands for the future implementation agent**, not results of this documentation review. Run from `/home/moahmmed/college_project` after 004–012 exist and their gates pass. Provision `V3_TEST_DB_URL` as an isolated shared PostgreSQL test database; the race test must spawn two processes/connections. Provision a disposable 012-qualified multi-file scene, a real Temporal test/deployment worker and the case-selected 005 approved feedback template/provider credentials for the live gate. Never place secrets or teacher content in CLI arguments/output.

```bash
git status --short
php artisan route:list --path=v3/teacher/cases
php artisan route:list --path=api/internal/v1/cases
php artisan route:list --path=internal/v1/workflows
docker build -f v3_engine/qualification/Dockerfile -t v3-scene-checker:013-review .
python3 -m pytest v3_engine/tests/review/test_preview_container.py -m container
php artisan test tests/Feature/V3/Review
python3 -m pytest v3_engine/tests/review -m "not live"
DB_CONNECTION=pgsql DB_URL="$V3_TEST_DB_URL" php artisan test tests/Feature/V3/Review/ReviewConcurrencyTest.php
php artisan test
python3 -m pytest v3_engine/tests
V3_REVIEW_LIVE=1 python3 -m pytest v3_engine/tests/review/test_live_gate.py -m live
```

Expected: real container blocks a hostile scene's host/external access, runs nonroot Chromium sandbox, renders a valid multi-file candidate and changes a frame after pointer/keyboard input; Laravel sends only PNG/escaped controls to the original owner. Actual owner/CSRF/V2/admin tests deny content bypass; the shared-DB race records one decision/approval/outbox; multiple Sections approve independently and only all current Sections complete the case. The live test must consume a committed decision in Temporal and invoke the **selected** captured feedback provider for a factual correction, then observe fresh 010 JEV and 011/012 handoff. Record counts, exit codes and skips. Mock PNG, fake Temporal and fixture provider tests are useful but do not satisfy the final gate. If a required container, provider, approved template, candidate or Temporal service is unavailable, report the exact blocker and leave completion unclaimed. Do not print prompt, Section values, scene files, note, frame, raw provider answer or credentials.
