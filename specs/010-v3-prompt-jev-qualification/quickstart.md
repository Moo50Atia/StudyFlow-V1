# Future Implementer Verification: 010

These are **instructions for the future implementer**, not commands passed during this documentation review. Run from repository root after 004–009 and 010 code exist. Capture actual command, exit code, counts and skips in the implementation report. Do not count a mock or skipped live test as provider proof.

## Baseline and targeted checks

```sh
git status --short
php artisan test
python3 -m pytest -q v3_engine/tests/lessons v3_engine/tests/source
```

Expected: baseline recorded before T001 changes; existing predecessor tests retain their prior result. If a predecessor path differs from its implemented suite, use the actual path and record it rather than declaring a nonexistent suite passed.

After each task group, run the exact targeted files from [task-execution-plan](task-execution-plan.md). Final routine suite:

```sh
python3 -m pytest -q v3_engine/tests/prompts -m 'not live'
php artisan test tests/Feature/V3/Prompts
php artisan test tests/Feature/V3/Lessons tests/Feature/V3/Workflow tests/Feature/V3/Access
```

Expected: P01–P10, P12–P17 behavior passes; exact 009 Execution/Second/Third digest tests pass; no new prerequisite regression. An import/syntax error does not establish test-first behavioral red. The routine suite does not prove external integration or concurrent claims.

## Real concurrency gate

Provision a disposable **shared** PostgreSQL database and set `V3_TEST_DB_URL` in the shell to its connection URL. The test must spawn two processes/connections and synchronize at the claim barrier. Never run this gate against `:memory:` SQLite.

```sh
DB_CONNECTION=pgsql DB_URL="$V3_TEST_DB_URL" php artisan test tests/Integration/V3/Prompts/PromptRaceTest.php
```

Expected: P11 passes with one committed `send_now`, `send_count=1`, an exact duplicate that reconciles, and a changed contender that receives 409. Record DB driver and process evidence; a sequential fake is not a pass.

## Live selected-provider gate

Provision disposable accepted PDF/source and captured settings with an operator-imported approved feedback template; set real NotebookLM MCP session, TypeSafe `TYPESAFE_API_KEY`/`JEV_MODEL`, and the captured feedback credential in the worker's secret environment. Do not put secret values in CLI arguments, logs or the report. Run the live marker against disposable content:

```sh
V3_PROMPT_LIVE=1 python3 -m pytest -q -m live v3_engine/tests/prompts/test_live_prompt_cycle.py
```

Expected: P18 proves the selected MCP's bound-source conversation, exact first Execution/Second/Third or verified Execution reuse, next `Continue` with reuse or staged behavior, actual typed Choice/Score/Noul including 101-level Score rubric, and one real feedback revision/reevaluation. Report model/version, adapter version, template ID/checksum and safe response shape, never source/prompt bodies. An unavailable account, skipped test, fixture-only adapter or rejected rubric is **incomplete**, not success.

## Final review commands

```sh
python3 -m pytest -q v3_engine/tests/prompts
php artisan test tests/Feature/V3/Prompts
php artisan test
git diff --check
git status --short
```

Expected: P01–P18 and relevant 004–009/011 contract regressions pass, no new failures or whitespace errors, no unexpected path changes. Keep evidence of any preexisting baseline failure separate. Do not certify scene generation, scene JEV or teacher review from 010 tests.
