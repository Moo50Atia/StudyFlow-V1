# Future Implementer Verification Commands

These are instructions for the future implementer. They have not been run as part of this documentation update. Run them from `/home/moahmmed/college_project`.

## Prerequisites

- Existing `.venv` with the V3 Python requirements and test dependencies.
- One selected provider key and model in the local environment or `.env`.
- A saved NotebookLM handoff directory. NotebookLM, Temporal, Node, npm, and JEV are not needed for the standalone scene command.

## Offline checks

```bash
.venv/bin/python -m pytest -q v3_engine/tests/scene_generation
.venv/bin/python -m pytest -q v3_engine/tests/terminal_pipeline
git diff --check
```

Expected: offline tests pass; live-provider/browser tests may skip only with an explicit missing-prerequisite reason. The scene tests cover the three-file contract, exact scene selection, retained receipts, local materialization, integrity manifests, recovery, and actual concurrent writer exclusion. They do not prove educational correctness or a live API result.

## Check a configured provider only when needed

```bash
.venv/bin/python scripts/v3/generate_scenes.py check --provider gemini
```

Expected: one small provider request, a safe pass/fail message, and no run directory. This check is optional and generation does not call it automatically.

## Generate one selected scene from a saved handoff

Get the exact scene key from the handoff inventory or its recorded manifest. Then run:

```bash
.venv/bin/python -u scripts/v3/generate_scenes.py run \
  --input path/to/lesson_handoffs \
  --output-dir storage/app/private/v3/scene-generation/one-scene-run \
  --provider gemini --model gemini-2.5-flash \
  --scene-key '<notebook-id>:<conversation-id>:W<lesson>:S<scene>'
```

Expected: exactly one selected prompt is sent, one immutable attempt retains the provider receipt and the three source files, and one `dist/index.html`, `dist/styles.css`, and `dist/script.js` set is hashed and published. No npm install, TypeScript check, Vite build, or calls for unselected scenes occur. Exit 0 means files were materialized; it does not certify browser behavior, educational quality, qualification, or teacher review.

If a selected scene is already failed/uncertain in an existing matching run, the explicit provider retry is:

```bash
.venv/bin/python -u scripts/v3/generate_scenes.py run \
  --input path/to/the/same/handoff \
  --output-dir path/to/the/same/output \
  --provider gemini --model gemini-2.5-flash \
  --retry-scene '<exact-scene-key>'
```

This may repeat a paid request. Do not use it for a retained-source/materialization failure; use local build recovery instead:

```bash
.venv/bin/python scripts/v3/generate_scenes.py build \
  --output-dir path/to/the/same/output \
  --scene-key '<exact-scene-key>'
```

Expected: local recovery makes zero provider requests and writes a new build manifest/pointer only after the retained source verifies. If the run's input/template identity differs, use a new output folder and the selected-scene command above.

## Temporal integration

For a durable Temporal-managed subject, use the Spec 018 `submit`, `worker`, `status`, `logs`, and `retry` commands in [its CLI contract](../018-v3-terminal-temporal/contracts/cli.md). A live subject retry requires that subject's persistent Temporal history and current `state_version`; a standalone scene-generation run is not retroactively attached to a Temporal workflow.
