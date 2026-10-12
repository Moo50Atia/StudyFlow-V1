# Quickstart: Future Implementation Verification

**All commands here are instructions for the future implementer. No application tests, services or live providers were run while authoring this spec.** Start with [execution plan](task-execution-plan.md) and record outputs, prerequisites/skips and process cleanup.

## 1. Baseline and offline lane

```bash
cd /home/moahmmed/college_project
git status --short
.venv/bin/python -m pytest -c v3_engine/pyproject.toml \
  v3_engine/tests/scene_generation v3_engine/tests/notebooklm/test_sdk_contract.py \
  v3_engine/tests/notebooklm/test_lesson_archive.py v3_engine/tests/test_local_env.py \
  -m 'not live and not temporal and not integration and not browser_ui' -q
```

Expected: independently recorded baseline; preserve pre-existing failures rather than assuming the017 report proves this checkout. After new tests exist:

```bash
.venv/bin/python -m pytest -c v3_engine/pyproject.toml \
  v3_engine/tests/terminal_pipeline \
  -m 'not live and not temporal and not integration and not browser_ui' -q
```

Expected: input/config/account adapter tests, journal/receipt/ownership tests and safe-error checks pass without external server, browser or provider. Missing new module imports do not qualify as meaningful red evidence.

## 2. Temporal/connected test lanes

```bash
.venv/bin/python -m pytest -c v3_engine/pyproject.toml \
  v3_engine/tests/terminal_pipeline \
  -m 'temporal and not live and not integration and not browser_ui' --run-temporal -q
.venv/bin/python -m pytest -c v3_engine/pyproject.toml \
  v3_engine/tests/terminal_pipeline \
  --run-temporal --run-integration --run-browser-ui -m 'integration and not live' -q
```

Fixtures start/reap their own local server from the installed Temporal CLI, temporary persistent DB and free ports. No implicit server download, connection to the operator namespace, long-lived server or external provider. Mark actual connected/race/process-server tests integration (and temporal/browser_ui where used) so the second command includes them. The connected test substitutes vendor transports only; production Activities/services execute. Expect genuine5-subject overlap, restart/rotation/replay and8-PDF fixture progression, static HTML/CSS/JavaScript materialization and real browser interaction. Browser absence is a reported gate, not a pass. No Node/npm/Vite stage is part of the current scene contract.

## 3. Persistent local operator installation

Use the installed CLI. If7233/8233 are occupied, identify the existing instance and verify its DB persistence/namespace; do not kill it or attach tests to its workflows. Start a new persistent instance only when its configured ports are free. In a dedicated terminal:

```bash
mkdir -p storage/app/private/v3/temporal
/home/moahmmed/.local/bin/temporal server start-dev \
  --ip 127.0.0.1 --port 7233 --ui-port 8233 \
  --db-filename /home/moahmmed/college_project/storage/app/private/v3/temporal/server.sqlite \
  --namespace v3-terminal
```

In another terminal:

```bash
/home/moahmmed/.local/bin/temporal operator namespace update \
  --namespace v3-terminal --retention 30d
/home/moahmmed/.local/bin/temporal operator search-attribute create --namespace v3-terminal --name TerminalSubmissionId --type Keyword
/home/moahmmed/.local/bin/temporal operator search-attribute create --namespace v3-terminal --name TerminalSubjectRunId --type Keyword
/home/moahmmed/.local/bin/temporal operator search-attribute create --namespace v3-terminal --name TerminalPhase --type Keyword
/home/moahmmed/.local/bin/temporal operator search-attribute create --namespace v3-terminal --name TerminalOutcome --type Keyword
/home/moahmmed/.local/bin/temporal operator search-attribute create --namespace v3-terminal --name TerminalLessonsSaved --type Int
/home/moahmmed/.local/bin/temporal operator search-attribute create --namespace v3-terminal --name TerminalScenesGenerated --type Int
.venv/bin/python -u scripts/v3/run_temporal_pipeline.py worker
```

For an existing namespace/attribute, inspect and reuse only an identical type/value; do not silently reinterpret a create error. Worker preflight checks namespace, journal root, settings and exclusive lock before admitting work. Expected:3 worker queues, local UI at http://127.0.0.1:8233, correct namespace30d and no runtime browser/auth command. SIGINT stops this worker; stopping the server retains its DB and leaves runs recoverable.

## 4. Operator run

Author a private version-1 manifest using the exact [CLI example](contracts/cli.md), with approved local PDF/framework/proof notebook and explicit selected provider/model. Give it a new UUID for a new run; preserve the same UUID/file for acknowledgement recovery. It may contain multiple subjects; saved mode uses handoff instead of PDFs. Example file location: `storage/app/private/v3/terminal-pipeline/operator-submission.json`.

```bash
.venv/bin/python -u scripts/v3/run_temporal_pipeline.py submit \
  --manifest storage/app/private/v3/terminal-pipeline/operator-submission.json --json
```

Use the returned subject run UUID:

```bash
V3_DEMO_RUN_ID='paste-the-returned-subject-run-uuid'
.venv/bin/python scripts/v3/run_temporal_pipeline.py status --run-id "$V3_DEMO_RUN_ID" --json
.venv/bin/python -u scripts/v3/run_temporal_pipeline.py logs --run-id "$V3_DEMO_RUN_ID" --follow --json
```

Expected: new notebook (proof notebook never reused), source readiness, Divide/Execution/Start/Continue sequence, all Lesson exports, frozen handoff then scene generation/build. Arbitrary scene types/counts do not stop Lesson capture. A malformed optional output retains warnings/raw bytes; no usable prompts means partial. Native UI shows Activities; journal shows detailed metadata. Ctrl+C on logs does not cancel the workflow.

For controls, obtain current state_version from status and generate a command UUID once (for example `uuidgen`); repeat that same command ID after lost acknowledgement. Apply flags from [CLI contract](contracts/cli.md). Uncertain chat cannot be force-resubmitted; retry only reconciles or starts a fresh submission after cancellation.

## 5. Optional live evidence and final verification

```bash
V3_TERMINAL_LIVE=1 .venv/bin/python -m pytest -c v3_engine/pyproject.toml \
  v3_engine/tests/terminal_pipeline/test_live_pipeline.py \
  --run-live --run-temporal --run-integration -m live -rs
```

Live test requires an approved owner-proof notebook/credential file, explicit selected scene provider/model, disposable source authorization and installed local tools. It uses a small fixture, not the mechanics directory/Unit3 automatically. Missing prerequisites truthfully skip and remain an open live gate; no browser prompt. Successful evidence includes actual NotebookLM responses, provider receipt, generated source/build, hashes/counts and waiver. Other providers remain fixture-supported unless separately tested.

Finally rerun focused baselines plus new lanes, inspect local UI, export/replay the recorded test histories and verify protected scope with `git diff --stat`/`git status --short`. Report exact commands/results, real versus substituted integration, remaining gates, costs/usage only if provided and every process started/stopped. Never infer production JEV, teacher approval or full app readiness from these local checks.
