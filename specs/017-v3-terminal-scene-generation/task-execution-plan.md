# Direct Execution Plan for the Implementation Agent

Implement only 017. [Tasks](tasks.md) are the work ledger; [tasks-note.md](tasks-note.md) explains traps. This document is the plan to follow, not evidence of work already done.

## Entry conditions and baseline

1. Read every file in this feature directory. Read the actual files listed in research/tasks-note, 011's authoring contract, and 016's downstream contract as context. Preserve owner decisions and the exact current scene template.
2. Capture `git status --short`, diffs/hashes for already modified files, `.specify/feature.json`, Python/Node/npm versions, and availability of the configured model. Do not reset/stash/clean unrelated changes. Use the active feature 017; no branch/commit is required.
3. Run the focused baseline below before production changes. Record exact failures/skips and distinguish prior failures from your changes. Missing key affects only a live gate; missing Node/npm affects the required build gate. Neither is permission to alter production architecture or synthesize pass evidence.

```bash
.venv/bin/python -m pytest -c v3_engine/pyproject.toml \
  v3_engine/tests/authoring/test_provider_wire.py \
  v3_engine/tests/authoring/test_package.py \
  v3_engine/tests/notebooklm/test_lesson_archive.py \
  v3_engine/tests/test_local_env.py -m 'not live' -q
sha256sum Google-Ai-studio-System-prompts
node --version
npm --version
```

Expected template SHA is in the CLI contract. Test fixtures must be tiny invented cases, not copied Subject data. Do not run an external provider or `npm install` as part of unit tests; real-build tests are explicitly separate.

## Ordered groups and checkpoints

Use `.venv/bin/python -m pytest -c v3_engine/pyproject.toml` for every test command below. All commands are future implementer instructions. Record meaningful behavioral red before implementing new behavior; missing imports/syntax alone do not count. If baseline/previous behavior already passes, record regression rather than forcing red.

| Group | Tasks | Before implementation | After implementation / checkpoint |
|---|---|---|---|
| Foundations | T001–T003 | Baseline plus `test_config.py test_handoff.py` | Same tests green. Three handoff kinds, variable counts, exact prompts/config/digests and zero-call input errors proven. |
| Provider boundary/check | T004–T007 | `test_providers.py test_cli.py -m 'not live'` | Green transport/check tests; real `--help` and check argument errors return correct codes; fixtures cannot be reported as live. |
| Source and receipts | T008–T009 | `test_source_package.py test_store.py` | Green limits/path/atomicity/receipt tests; no overwrites, raw body retained before parse and output lock obtained. |
| Local compilation | T010–T011 | `test_builder.py` and write `test_local_build.py` real-build assertions | Both fixture renderers compile through actual npm/tsc/Vite. Save dependency closure/lock and manifest evidence; missing tools cannot be a passing checkpoint. |
| Service/terminal run | T012–T015 | `test_service.py test_cli.py -m 'not live'` | Green all; two-Lesson/different-count batch, item failure continuation, exact waiver/progress, no JEV/web call and valid generated artifacts. |
| Resume | T016–T017 | `test_resume.py test_store.py test_cli.py -m 'not live'` | Every crash boundary and drift case passes; completed/received work has zero extra provider sends, explicit retry appends. |
| Concurrency | T018–T019 | `test_concurrency.py` | Two overlapping processes with shared barrier/server prove one sender and exit 3 loser; kill-owner recovery; no manufactured red if existing lock already works. |
| Browser/local/live | T020–T021 | Review fixture-vs-live scope and install only missing test prerequisites | Real locally served fixture control works; live opted-in model builds one sample; skips keep gates open. Record independent evidence, not JEV/teacher approval. |
| Completion | T022 | Run final commands in quickstart and review matrix | Evidence report, task ledger, output lineage, diff and cleanup agree. No missing mandatory gate hidden behind test totals. |

After each group run all new unit tests available so far with `-m 'not live and not browser_ui'`, excluding `test_local_build.py` unless specifically running the real-build gate. Re-run the focused baseline only after changes to shared configuration/import boundaries or at final completion; do not broaden into unrelated V3 work without a concrete regression.

## Stop and recovery rules

- Stop dependent work if the approved template digest differs, a prerequisite wire/input shape contradicts the documented contract, or the current workspace contains conflicting edits to your planned files. Record the exact file/decision needed. Do not weaken the React requirement or silently edit the template.
- Fix failures introduced by your changes. Record unrelated baseline failures and continue independent work; do not start fixing another spec.
- Missing keys/region/model keep live gate open. Never print/read secrets into the report or use a different provider to claim the selected one worked. Qwen must use the configured Alibaba endpoint.
- Missing build/browser tooling leaves those mandatory gates open. Provision only required local tooling in the documented paths; do not change root Laravel dependencies or start unnecessary services.
- During actual runs, item failures continue. Global bookkeeping corruption/unwritability and lock conflict stop according to CLI contract. Uncertain requests are never automatically repeated.
- No mutation of application settings/qualifications/approval is authorized by this local feature. Do not commit/deploy or launch the Laravel/Temporal stack to demonstrate the command.

## Final completion and implementation report

T022 requires all FR-001–FR-013 and A01–A20 evidence, focused regressions, actual race/build/browser gates, and at least one actually tested selected-provider live sample. Four adapter fixture passes do not imply four live providers passed. If any mandatory gate is unavailable, document usable completed functionality and exact open gates, leave their task checkboxes open and avoid claiming complete/live-ready.

Create `implementation-report.md` containing:

- Scope and concrete changed paths; preserved existing workspace changes/selector and no mutation outside 017's declared implementation/test/configuration deliverables or of other numbered specs.
- Baseline failures and exact red/green commands/results, tests skipped and why.
- Fixture, actual local build/browser, concurrency and live evidence in separate rows.
- Run/input/Lesson/Scene counts; generated/failed/uncertain/queued/input issues and exit statuses; safe output/manifest references and digests.
- Selected provider/requested/returned model/template/toolchain/lock hashes, response ID and measured duration/usage; monetary cost only if actually known.
- Crash/retry/lock/path/privacy results and explicit waived JEV provenance; no assertion of educational qualification/teacher review.
- Remaining blockers and downstream amendment needs, cleanup of owned processes, and accurate task checkbox state.
