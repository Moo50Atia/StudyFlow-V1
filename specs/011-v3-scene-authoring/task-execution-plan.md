# Direct Implementation Execution Plan: 011

This is the implementation agent's runbook for [tasks T001–T014](tasks.md). It is a **future plan**; this documentation review did not run application tests. Use [contract](contracts/scene-authoring.md), [model](data-model.md) and [acceptance matrix](contracts/acceptance-matrix.md) as the fixed design.

## Entry and workspace rules

Implement only after 004–010 required migrations, 005 captured settings/template repository, 007 signed routes/workflow, 008 `v3_private`, 009 Section chain and 010 current-pass resolver actually exist and their own gates pass. Inspect the real files at T001; if their concrete names differ, map equivalent implemented services and record the mapping before editing. Do not fabricate a prerequisite, 012 repair model or production fixture to make 011 appear complete. Preserve all preexisting workspace changes; record `git status --short`, `git diff --name-only`, predecessor test results and relevant migration/route list before changing anything. If baseline tests fail, isolate their cause and record it; fix only an 011-caused regression within 011 scope. Do not reset, delete or overwrite user changes. No commit/deploy without request.

## Ordered groups and checkpoints

| Group | Tasks | Before / after tests | Checkpoint |
|---|---|---|---|
| 0 Evidence | T001 | Before: `php artisan test` and `python3 -m pytest v3_engine/tests` where installed. After: same baseline recorded. | Real predecessor services, signing, DB, private disk and owner boundary identified; stop if missing. |
| 1 Handoff | T002–T003 | Before implementation: run `php artisan test tests/Feature/V3/Authoring/HandoffTest.php tests/Feature/V3/Authoring/TemplateTest.php` and capture assertion red. After: same files green. | No send for unqualified/stale; exact captured template and settings bound. |
| 2 Adapter | T004–T005 | Before: `python3 -m pytest v3_engine/tests/authoring/test_provider_wire.py v3_engine/tests/authoring/test_package.py` red. After: green. | All three vendor shapes accepted/rejected deterministically; no live claim. |
| 3 Attempts | T006–T007 | Before: `php artisan test tests/Feature/V3/Authoring/AttemptPolicyTest.php` and `python3 -m pytest v3_engine/tests/authoring/test_fallback.py` red. After: green, including two **separate processes** on shared DB. | One reservation/send per ordinal; unknown call cannot fall back. |
| 4 Artifact | T008–T009 | Before/after: `php artisan test tests/Feature/V3/Authoring/CandidateStoreTest.php`. | Private immutable files, digest check, exact replay and fault recovery. |
| 5 Boundary | T010–T011 | Before/after: `php artisan test tests/Feature/V3/Authoring/AccessTest.php tests/Feature/V3/Authoring/MilestoneTest.php`. | Real HMAC, owner/admin/V2 routes and 007 event proof guarded. |
| 6 Workflow | T012–T013 | Before/after: `python3 -m pytest v3_engine/tests/authoring/test_workflow.py`. | Replay-compatible 010→011 continuation; Temporal payload only opaque refs. |
| 7 Final | T014 | Run [quickstart](quickstart.md) plus full predecessor regression. | A01–A17 mapped to passing evidence, shared-DB race and live configured API candidate recorded separately. |

The new tests must fail on a behavior assertion, never just import/syntax. Existing passing predecessor behavior receives regression tests, not invented failure. Fake provider and HMAC tests demonstrate code behavior only; they do not establish vendor/API health. Prefer the test DB driver used by the repository for routine tests; use a separate shared transactional DB for the true race and clean it without affecting user data.

## Failure and stop conditions

Stop dependent work and report exact blocker if 010 cannot prove a current pass, 005 has no approved compatible scene template, provider credential/model is unavailable for required live gate, 007 cannot replay existing histories, the private disk is publicly served, or a real concurrent claim cannot be tested. A post-send timeout/5xx stays uncertain and is not overcome by changing provider, new request, or Temporal retry; record it for 014. An unrelated baseline failure does not authorize modifying other numbered specs. If a documented contract conflicts with actual implemented prerequisite, stop at that interface and report precise file/API and impact rather than silently inventing it.

## Final completion and report

All A01–A17 assertions, predecessor regressions, workflow replay, real concurrent claim, private artifact recovery, security route checks, and the selected configured **live API** call must pass for an implementation-ready claim. 012's browser/JEV and 013's review still remain downstream. In `specs/011-v3-scene-authoring/implementation-report.md`, record implementation diff/paths, baseline, red and green commands/results, live provider/model/template ID (never credentials or bodies), candidate manifest digest and private storage verification, race setup/result, no-public-access proof, known failures/uncertainties, remaining risks and 012 handoff. Do not put raw prompt/HTML/source in the report.
