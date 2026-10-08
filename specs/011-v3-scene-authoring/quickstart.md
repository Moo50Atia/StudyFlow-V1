# Future Implementer Verification: 011

These commands are **instructions for the future implementation agent**. They were not run or passed during this documentation-only review. Run from `/home/moahmmed/college_project` after 004–010 are installed.

```bash
git status --short
php artisan route:list --path=api/internal/v1/cases
php artisan route:list --path=v3/teacher/cases
php artisan test tests/Feature/V3/Authoring
python3 -m pytest v3_engine/tests/authoring
DB_CONNECTION=pgsql php artisan test tests/Feature/V3/Authoring/AttemptPolicyTest.php --filter=concurrent_claim
php artisan test
python3 -m pytest v3_engine/tests
python3 -m pytest v3_engine/tests/authoring/test_live_provider.py -m live
```

Expected: the signed 011 claim/context/attempt/result/readback/candidate routes are present; A01–A17 and predecessor regressions pass. Confirm the HMAC and teacher/admin tests call the **actual** routes. The two-process `AttemptPolicyTest.php` must use one shared transactional DB and observe one reservation/provider ordinal. A SQLite `:memory:` test alone is not concurrency evidence.

Run the PostgreSQL race command against an isolated, configured shared test database; routine SQLite `:memory:` does not prove concurrency. For the live command, T014 creates `test_live_provider.py` using a selected API/model in a safe deployment with real 005 credentials and a 010 current pass. A trusted operator must first import an owner-approved compatible scene template using 005's `php artisan v3:register-approved-template scene "$V3_SCENE_TEMPLATE_ID" "$V3_SCENE_TEMPLATE_PATH" "$V3_SCENE_TEMPLATE_SHA256"`, then publish settings selecting it through the 005 admin route. Expected: one actual API response produces a private `v3_scene_candidates` row and rehashable manifest/files, with no public URL and a successful 011 signed candidate-resolver read for later 012 use. Record provider/model, template ID/digest, status, cost if supplied and artifact digest in the implementation report; omit code, prompt, secrets and raw provider payload. Fixture provider tests alone do **not** satisfy this live gate. If credentials/template/predecessors are unavailable, report the missing gate and do not claim implementation complete.
