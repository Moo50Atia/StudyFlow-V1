# Direct Implementation Execution Plan: 012

Follow [T001–T018](tasks.md) in order with the [contract](contracts/scene-qualification.md), [model](data-model.md) and [S01–S23 matrix](contracts/acceptance-matrix.md). This is a future agent runbook; this documentation review performed no application implementation or test run.

## Entry and workspace

004–011 must actually exist and meet their own gates, especially 007 HMAC/Temporal, 008 `v3_private`, 009 eleven fields, 010 `QualifiedPromptResolver`/typed JEV transport and 011 candidate resolver/authoring ledger. At T001 map planned service/path names to the actual implemented prerequisite and record any difference without silently weakening its contract. Require an OCI runtime able to launch nonroot sandboxed Chromium with `--network none`, and configured selected JEV model/credential for the live gate. Record `git status --short`, `git diff --name-only`, route/migration inventory, dependency versions and baseline test outcomes before touching code. Preserve unrelated workspace changes; never reset/overwrite them. A reproduced unrelated baseline failure is reported with before/after evidence, not “fixed” by changing another spec. No commit or deploy.

## Groups, tests and checkpoints

| Group | Tasks | Tests before / after group | Exit checkpoint |
|---|---|---|---|
| 0 Baseline | T001 | Before: `php artisan test`, `python3 -m pytest v3_engine/tests` if installed. After: baseline recorded. | 010/011 contracts and OCI capability verified; stop dependent work if absent. |
| 1 Real checker | T002–T003 | Before/after: `python3 -m pytest v3_engine/tests/qualification/test_container.py v3_engine/tests/qualification/test_browser.py -m container`. | A hostile scene cannot reach host/external listener, browser sandbox remains enabled and good/broken/control fixtures differ. |
| 2 Claim/store | T004–T005 | Before/after: `php artisan test tests/Feature/V3/SceneQualification/ClaimTest.php tests/Feature/V3/SceneQualification/CheckResultTest.php tests/Feature/V3/SceneQualification/EvidenceTest.php`. | Same-case current gate, private result and no-false-pass storage/DB fault behavior. |
| 3 Evidence wire | T006–T007 | Before/after: `python3 -m pytest v3_engine/tests/qualification/test_jev.py` plus `php artisan test tests/Feature/V3/SceneQualification/EvidenceTest.php`. | Exact eleven fields/questions/digests; official typed fixtures; no silent truncation. |
| 4 JEV rule | T008–T009 | Before/after: `php artisan test tests/Feature/V3/SceneQualification/JevRuleTest.php`. | Direct inclusive 005 rule, invalid response technical, immutable answer/qualification. |
| 5 Repair ledger | T010–T011 | Before/after: `php artisan test tests/Feature/V3/SceneQualification/RepairTest.php tests/Feature/V3/SceneQualification/ConcurrencyTest.php` on a shared DB for race. | One ordinal/root, exact settings, no branch, cap 0–10. |
| 6 Repair worker | T012–T013 | Before/after: `python3 -m pytest v3_engine/tests/qualification/test_repair.py`. | Previous candidate/files/fixed findings enter same 011 coding adapter; old candidate preserved. |
| 7 Security/handoff | T014–T015 | Before/after: `php artisan test tests/Feature/V3/SceneQualification/AccessTest.php tests/Feature/V3/SceneQualification/EligibilityTest.php tests/Feature/V3/SceneQualification/MilestoneTest.php`. | Actual HMAC/owner/V2/public boundaries and 013 eligibility/007 provenance. |
| 8 Workflow | T016–T017 | Before/after: `python3 -m pytest v3_engine/tests/qualification/test_workflow.py`. | Replay-compatible 011→012 loop; no teacher review signal or content in history. |
| 9 Final | T018 | Run [quickstart](quickstart.md), full predecessors/regressions, real container and live JEV. | S01–S23 executable evidence and implementation report, or exact unmet gate. |

For each red phase, compile/import the test and use the smallest deny-by-default current-feature seam necessary to reach a **failed behavior assertion**. A missing import, absent image/download, migration syntax error or inherited green behavior is not red evidence. Keep existing passing prerequisite behavior under regression. Container safety requires a real process/egress test, HMAC/owner requires actual routes, and concurrency requires two processes plus one shared transactional DB. A mock JEV or browser only proves local logic.

## Recovery and stop rules

If 010/011 actual implementation conflicts with the signed handoff, a required OCI sandbox control cannot run, exact 009/JEV evidence exceeds the provider/runtime capacity, or selected JEV credentials/model are absent for the live gate, stop the dependent step and report the precise interface or limit. Do not run untrusted code in the host browser, allow network, substitute another model, truncate source, use active settings, fabricate a pass, consume a repair slot on technical outage or create a 013 review row. Continue independent documentation/tests when possible. A 011 coding send whose outcome is uncertain remains under 011/014 recovery, not an automatic 012 retry. If a known result POST loses acknowledgement, read back and replay only the exact retained body; changed replay conflicts.

## Completion and report

Complete only when every applicable T001–T018 deliverable and S01–S23 assertion passes, the real restricted browser hostile-scene gate, shared-DB race, selected live JEV call, workflow replay, prerequisite regressions and no-public/owner/admin boundary all have recorded evidence. 012 still does **not** prove teacher approval or visual correctness; 013 must implement its own review/viewer. The implementation report must list: scope/files; preserved workspace baseline and failures; TDD red/green results by group; commands/test names for each scenario; actual browser image/package version/sandbox flags and hostile egress observation; question/evidence/answer digests and model (no bodies/secrets); repair lineage/budget/race results; HMAC/owner/V2/admin privacy evidence; live vs fixture results; cost/timing where known; remaining errors/uncertainties; and the exact 013 eligibility handoff. If anything mandatory is unavailable, mark partial/blocked with task IDs and reasons rather than asserting completion.
