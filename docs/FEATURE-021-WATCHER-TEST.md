# Feature 021 worker workflow observation — 2026-10-22

The complete four-stage transport workflow passed twice in separate fresh runs with the production outbound daemon, real terminal CLI subprocesses, real isolated Temporal, and real Laravel HTTP. NotebookLM responses were replayed from the existing 114-page OOP run, and scene generation was replaced by 96 explicitly labeled placeholder HTML files. No Google request or paid scene-generation request was made.

The test starts with the existing accepted split PDFs. It does not claim a new multipart upload, new PDF splitting, new NotebookLM processing, educational qualification, or student publication. All run/results/storage changes are isolated from the product database. The product Laravel stack is started separately at `http://127.0.0.1:8766`.

## What the observer and worker did

Before execution, the harness clones database/storage into a new private directory, creates a fresh run referencing the accepted uploads, lets normal admission create new case/lecture/binding/activation associations, and enrolls a test device. A separate Temporal worker replaces only the external provider session with retained responses. The production daemon runs without `--offline-double`, claims its own tasks, verifies/downloads inputs, invokes the real CLI, waits for Temporal, uploads retained authoring artifacts, and commits its own results.

After daemon launch, the observer only reads evidence, creates the authorized named HTML placeholders at the destinations in the worker's `run.json`, and requests Pull through authenticated teacher HTTP. It does not call `TaskRunner`, claim work, submit results, signal workflow progress, edit state, or recover the successful run manually. Normal graceful shutdown follows the result.

| Stage | Observed result |
| --- | --- |
| 1. Ordered PDF retrieval | Four exact input hashes; pages 1–28, 29–57, 58–85, 86–114; provider-boundary upload trace preserves this order. |
| 2. Terminal and Temporal | Production CLI submission; actual Temporal subject workflow completed with 995 history events, five source uploads, 69 sends/captures, and four PDF archives. The 69 responses are 4 maps + 4 execution responses + 61 lessons. |
| 3. Ordered prompt storage | 61 lessons and 96 exact prompt texts; worker handoff and teacher JSON export agree in PDF → lesson → scene order. Local-worker lesson/scene ordinals are zero-based; terminal display labels are one-based. |
| 4. Scene return | Teacher Pull queues return work; the daemon claims it and uploads all 96 HTML placeholders. Every hosted file hash and scene/prompt binding matches. Teacher scene-list JSON contains all candidates in the original order. |

The placeholders contain only a numbered scene title and a test label. They remain unqualified scene candidates; this test does not approve or publish them as educational material.

## Defect fixed

A real captured settings snapshot exposed a PHP/Python wire mismatch: `providers.coding_order` contains ordered provider descriptor objects (`id`, `model`, `credential_ref`), but the Python validator accepted only strings. The validator now accepts these descriptors while rejecting unknown/secret fields, and retains compatibility with identifier-only captures. Laravel filters descriptors to the portable field allowlist. The joined HTTP regression now uses populated published settings instead of an empty settings fixture.

Earlier attempt failures are retained. Attempt 01 was a harness return-shape error; attempt 02 lacked a preparation inventory under its new submission key; attempt 03 exposed the real settings defect; attempt 04 lacked test-case map activations; attempt 05 compared different ordinal bases. Attempt 07 added a persisted-lesson check against the wrong table: local-worker lessons are stored as prompt work items, rather than NotebookLM lesson bundles. That harness assertion was corrected before a fresh successful confirmation. These are distinguished from product failures. Each correction was followed by a new isolated attempt, rather than advancing the failed run by hand.

Product startup also encountered a stale verification server on port 8766 and a disconnected Docker web endpoint after the failed bind. The stale test runtime was stopped and the endpoint reattached before normal startup succeeded.

## Evidence and reproduction

Successful attempt 06: hosted run `894a25d3-c839-4624-9b2c-52ae574269e8`, terminal run `7b47d25f-36fa-573b-9744-de5ef49f176a`.

Successful confirmation attempt 08: hosted run `0ac9ef31-1e3f-4ea3-b224-eb711d8b3afa`, terminal run `7edea906-0319-5f40-accd-facf99460776`. This pass additionally counts 61 durable lesson work items and checks all 61 retained raw-response hashes. Both authoring and return tasks finished at assignment epoch 1.

Private confirmation evidence is in `storage/app/private/v3/local-worker-verification/watcher-114-attempt-08/`. First-pass evidence directory: `storage/app/private/v3/local-worker-verification/watcher-114-attempt-06/`. Inspect `verification.json`, `temporal-evidence.json`, `provider-trace.jsonl`, `outbound-daemon.log`, `dashboard-prompts.json`, `placeholder-inventory.json`, and `dashboard-scenes.json`. Device credentials and database copies stay private; do not publish the runtime directory.

Run a new attempt with a new, unused output directory:

```bash
./bin/product up
.venv/bin/python scripts/v3/verify_worker_workflow.py \
  --output storage/app/private/v3/local-worker-verification/watcher-114-NEW
```

The harness stops its isolated services after success or failure and retains evidence. The existing source run and its retained response bytes are read only. Successful test services were shut down cleanly; their private evidence remains on disk.

## Regression results

- Local-worker Python suite after the fix: **49 passed, 1 gated live test skipped**.
- Terminal/preparation Python suite: **132 passed, 22 gated/resource tests skipped**.
- Local-worker PHP, unit, prompt export and manual import suites after the fix: **81 passed, 541 assertions**.
- Focused populated-settings HTTP/protocol regression: **12 passed, 1 gated live test skipped** (also included in the local-worker suite).

Skipped tests are not counted as passes. Python reports an existing unknown `import_mode` configuration warning. A repository-wide `git diff --check` also reports an existing trailing blank line in `routes/api.php`; the changed tracked files pass their scoped whitespace check.

## Final product state

The rebuilt product stack finished startup successfully with all eight persistent services healthy. `/up` and `/login` returned HTTP 200 at `http://127.0.0.1:8766`. The running PHP and Python images contain the corrected admission/validator files, verified against host SHA-256 values. Rebuilding waited through the configured 320-second graceful worker stop; that restart latency is separate from the successful isolated workflow tests.
