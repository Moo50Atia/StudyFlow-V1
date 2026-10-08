# Future Implementer Verification: 015

These commands are instructions for a future implementation. **None passed during this documentation-only review.** Run from `/home/moahmmed/college_project` after 004–014 are implemented and their migrations/required live gates pass. Capture baseline results before editing and compare after each task group. Do not use fixture results as evidence of live provider billing or Temporal operation.

```bash
git status --short
php artisan route:list --path=v3
php artisan test tests/Feature/V3/Evidence/LineageTest.php
php artisan test tests/Feature/V3/Evidence/MetricsTest.php
php artisan test tests/Feature/V3/Evidence/ExportTest.php
php artisan test tests/Feature/V3/Evidence/ExportRaceTest.php
php artisan test tests/Feature/V3/Evidence
php artisan test
```

The race test MUST provision one temporary SQLite **file** through its harness, launch two independent PHP clients/connections with an overlap barrier, and fail rather than skip if overlap cannot be observed. Routine SQLite `:memory:` cannot satisfy it. Expected result: one export row/file and one generation audit for same actor/key; an exact duplicate returns its ID, changed body returns 409. The integration gate must also exercise the configured production database's stable snapshot behavior.

Inspect actual generated owner and admin JSON with H14/H15 sentinels, including download responses, error bodies and `Cache-Control: private, no-store`. Owner trace must show the exact current and historical chain with failed branches and explicit gaps. Metrics must show defined UTC elapsed/stage/wait intervals and known subtotal with null full cost when any charged component is unmeasured. Admin response/export must have no teacher identity or educational content. The hourly export purge must leave 006–014 referenced files intact.

For the live gate, use a real case produced by implemented 006–014 with an actual 013 approval. Open its 015 owner trace and admin metric/export routes through authenticated sessions, verify the stored private artifact digests and report which external service usage figures were supplied versus unknown. If that case or a prerequisite service is unavailable, stop T014 and record the missing evidence in the implementation report; do not claim end-to-end proof.
