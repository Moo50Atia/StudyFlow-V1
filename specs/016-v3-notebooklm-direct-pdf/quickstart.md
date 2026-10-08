# Future implementer verification: 016

These are **proposed commands and expected results after implementation**. They were not run as application tests during specification authoring. Run from `/home/moahmmed/college_project`; use disposable DB/private disk/runtime for mutations. Never run `migrate:fresh` against the existing pilot database.

## Inventory and baseline

```sh
git status --short
php -v
php artisan --version
.venv/bin/python --version
qpdf --version
clamdscan --version
php artisan migrate:status
php artisan route:list --path=internal/v1
php artisan route:list --path=v3
php artisan schedule:list
php artisan test tests/Feature/V3
.venv/bin/python -m pytest v3_engine/tests -m 'not live and not container and not temporal and not browser_ui and not integration and not deployment'
```

Record failures/skips and installed versions; do not infer green results from older reports. The existing full PHP suite can contain connected checks; provision it or report required environment precisely. New source/combined routes must remain signed; public surfaces remain owner-gated.

## Dependency verification in isolated environment

```sh
python3 -m venv /tmp/v3-notebooklm-016-venv
/tmp/v3-notebooklm-016-venv/bin/python -m pip install -e './v3_engine[dev]' 'notebooklm-py==0.8.4'
/tmp/v3-notebooklm-016-venv/bin/python -m pip check
/tmp/v3-notebooklm-016-venv/bin/python -m pip freeze
```

Expect SDK0.8.4, compatible Python/httpx and no broken requirements. T002 captures the resolved set in `v3_engine/requirements.lock`; repeat clean installation from that file, then install local package `--no-deps`, and rerun pip check. Do not upgrade global or currently running worker environments before resolution succeeds.

## Migration, framework and runtime setup

In a shell already configured for the **disposable test DB/private disk**, run:

```sh
php artisan migrate --force
sha256sum steps.txt
php artisan v3:import-notebook-framework --path=steps.txt --expected-sha256=96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec --admin-user=1
.venv/bin/python scripts/v3/check_notebooklm.py --adapter notebooklm_py
php artisan v3:reconcile-notebook-protocol
php artisan v3:reconcile-notebook-protocol
```

`--admin-user=1` is an example: replace1 with the actual active approved admin fixture ID; never create an unauthorized bypass. Expect additive migration/backfill, exact immutable framework import, fixed verified/auth-required result, and idempotent read-only reconciliation. No runtime browser opens. Explicit credential refresh uses the existing setup script plus implemented SDK bridge; configure `V3_NOTEBOOKLM_STORAGE_STATE` and approved `NOTEBOOKLM_BASE_URL`. Keep direct default disabled until final gates; tests capture direct mode explicitly. Restart only feature-owned Laravel/worker processes when changing env so they reload; never kill unrelated services.

## Focused and connected tests

```sh
php artisan test tests/Feature/V3/NotebookLM
php artisan test tests/Integration/V3/NotebookLM
.venv/bin/python -m pytest v3_engine/tests/notebooklm -m 'not live'
php artisan test tests/Feature/V3/Intake tests/Feature/V3/Sources tests/Feature/V3/Lessons tests/Feature/V3/Prompts
.venv/bin/python -m pytest v3_engine/tests/sources v3_engine/tests/lessons v3_engine/tests/prompts v3_engine/tests/workflow -m 'not live and not container and not temporal and not browser_ui and not integration and not deployment'
git diff --check
```

The listed regression directories exist in the inspected repository; verify baseline paths before running. New016 test paths are mandatory planned deliverables. Integration tests need running Laravel/bridge/Temporal/registered worker and a real shared disposable DB; they must refuse skipped concurrency/replay evidence as a pass. Follow per-group commands in task-execution-plan.md before/after implementation. Expect N01–N23 behavioral results, actual concurrent sends<=1 and legacy replay success; no false completion from transport doubles.

## Live gate

Implement the live test with environment inputs `V3_LIVE_DIRECT_CASE_REF` (fresh owner-created direct case for the small fixture), `V3_LIVE_UNIT3_CASE_REF` (fresh direct case for the accepted original Unit3 PDF), and `V3_LIVE_JEV_MODE=live|owner_waived_demo`. These values are opaque case IDs/mode only; credentials come from protected config. Use the existing teacher intake UI for both files and captured direct-mode case creation; do not inject fabricated bindings/completions.

```sh
.venv/bin/python -m pytest v3_engine/tests/notebooklm/test_live_direct_pipeline.py -m live -v
```

Expect known-case notebook + PDF/steps roles ready, exact format read-back, one Divide, one Execution/System Ready, one first start and N−1 literal Continue sends, all11 fields/preparation/prompts committed, full multi-work trace and no local OCR. The inspected real file is `/home/moahmmed/Downloads/Documents/Math prep3/ilovepdf_split-range-math-prep3/Unit3.pdf`; verify intake filename/metadata and original-byte digest, never assume another file is equivalent. Remote limit/format/auth failure is an incomplete live gate, not permission to split or manufacture data.

For full pipeline completion, run real JEV, coding provider, browser/container checks and original-teacher review through existing services. A test with demo JEV100 reports only demo evidence, keeping browser checks and teacher decision real. Preserve private refs/timestamps/digests in protected evidence; public report includes counts/status/commands without PDF text, cookies or provider IDs. Enable direct future-case default only after all mandatory gates; rollback never repins an existing case.
