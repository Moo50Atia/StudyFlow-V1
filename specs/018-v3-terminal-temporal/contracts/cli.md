# CLI Contract: Local Temporal Pipeline

Proposed entrypoint: `.venv/bin/python -u scripts/v3/run_temporal_pipeline.py`. These commands are future implementation instructions, not currently runnable evidence. All output defaults to concise operational metadata; `--json` returns schema_version 1 JSON. Provider details/sources remain private artifacts.

## Submission

`submit --manifest FILE [--json]` captures/resolves a version-1 local manifest, persists immutable snapshots, and submits through supervisor Update-With-Start. Return submission ID, subject run IDs/Workflow IDs, manifest digest and `accepted` or safe rejection. It does not wait for completion. Repeating the same submission manifest returns the same result; if acknowledgement is lost, rerun with the same submission ID rather than generating another one.

Input example (operator-selected UUIDs; provider/model required):

```json
{
  "schema_version": 1,
  "submission_id": "92407f71-f6c4-4c78-9938-0cfeef937cf2",
  "subjects": [
    {
      "subject_key": "mechanics",
      "mode": "new",
      "title": "Mechanics",
      "pdf_dir": "notebooklm_mechanics_8pdf_test",
      "steps": "steps.txt",
      "account_check_notebook_id": "85b76e80-ea8a-4f86-9311-185634fcd2ed",
      "provider": "gemini",
      "model": "gemini-2.5-flash"
    }
  ]
}
```

Relative paths resolve against project root. New subject requires exactly one of `pdf_dir` or ordered `pdf_files` (nonrecursive `.pdf` directory enumeration), framework defaults to root `steps.txt`, and owner-approved proof notebook ID. Saved subject instead requires `handoff` file/directory and forbids PDF/framework/account fields. Supported handoff kinds are exactly the three existing 017 formats. `provider` is `gemini|glm|deepseek|qwen`, model is explicit nonblank; optional timeout is integer 1–1500 seconds and defaults to 300. Template uses approved 017 resolver, not a new prompt. No API key/cookie/base URL in manifest: endpoint/credentials resolve through existing 017 config. Reject unknown fields, duplicate subject keys, empty inputs, overlap with generated artifact roots, and changed bytes before dispatch. Account alias is fixed mo50atiapro for new runs. The example notebook is proof only; every new subject gets a new notebook.

Queue rejection codes: `queue_full` (>64 pending after proposed admission), `scheduler_draining`; neither creates a subject workflow. Same staged submission may be retried later. Accepted duplicate response succeeds even while draining. Input/config rejection occurs before acceptance. Subjects within accepted manifest are queued in given order; an oversized batch is rejected atomically, not partially admitted.

## Progress and events

- `status --submission-id UUID [--json]` lists all subject states/counts and per-subject freshness.
- `status --run-id UUID [--json]` addresses that stable subject identity across Continue-As-New.
- `logs --run-id UUID [--after-seq N] [--follow] [--json]` exports journal events in sequence order. `--follow` polls locally every second, does not require a worker, and ends on Ctrl+C without cancelling work. JSON mode is one event per line. Events include cursor seq for reconnect; a reconnect supplies the last seen seq.

Online status queries have a 5-second deadline; failure falls back to persisted projection plus server Describe if available, with `freshness=last_known`, as_of and safe `worker_unavailable`/`temporal_unavailable`. No projection yields `status=null` with unknown counts, not zero. Final persisted reports remain readable after history retention expires. Native UI is `http://127.0.0.1:8233`, namespace v3-terminal.

## Controls

`pause|resume|cancel --run-id UUID --command-id UUID --expected-version N [--json]` sends a subject Update. Duplicate same-ID/same-command returns recorded receipt before testing expected_version; conflicting digest or stale current version returns `control_conflict`. Versions come from status. Pause/cancel can return `requested` while the active Activity settles; applied receipt is subsequently visible through status/logs. Controls have a 10-second client deadline: return `control_pending` with the same identity when acknowledgement is unknown; never convert timeout into applied or send a fresh command automatically. Resume verifies credentials if the remaining work uses NotebookLM; it never implicitly retries an uncertain send.

Queued cancellation routes to supervisor cancel_queued_v1. An unstarted subject cannot be paused/resumed and returns control_conflict. Missing Workflow ID is never treated as applied cancellation.

`retry --run-id UUID --command-id UUID --expected-version N --phase notebook|generate|build [--scene-key KEY ...] [--accept-duplicate-risk] [--json]` is explicit recovery:

- notebook: open blocked subject only; reconcile first. Definite read/source-readiness failure may retry without duplicate-risk flag; uncertain creation/upload may resend only with that flag and only after old sender lock is released. Uncertain chat cannot be resent in the same conversation even with consent because it could skip a Lesson; reconcile or start a new submission. History conflicts/unknown map counts/changed inputs are not overridable.
- generate: select failed/uncertain scenes only; reject healthy generated scenes/unknown keys or changed provider/model/template. CLI resolves keys into safe hashed identities/artifact refs before Update; raw notebook/conversation-bearing keys never enter history/logs. Uncertain retry requires duplicate-risk flag.
- build: select scenes with valid retained source and failed/missing/corrupt compiled output, including previously generated scenes with proven damaged manifests/dist. Reject intact healthy output. Reuse captured nonsecret provider/template metadata without invoking credential-dependent provider resolution or constructing an API client; neither API/NotebookLM credentials nor original handoffs are required. Preserve source/previous builds and make zero model calls.
- closed partial subject, or completed subject with validated damaged builds: supervisor admits a linked recovery child over the existing scene store, under four-subject capacity, using retained final report and actual close proof even after history expiry. One active recovery per generation root; same command deduplicates, different concurrent request returns recovery_busy. Return new recovery run ID as the control target. Original reports/attempts remain immutable; ordinary generation retry on a completed run is rejected.
- cancelled/failed input runs are not silently reopened; submit a new run. Cancellation of a recovery preserves the original report.

## Exit codes and worker

0: accepted/applied/duplicate success or readable status/logs; 2: input/config/ownership/digest conflict; 3: lock/control/recovery/queue/drain conflict; 4: server/worker unavailable or acknowledgement pending; 5: requested command rejected due to runtime storage/integrity blocker. Async subject partial outcome is conveyed in status/report, not retroactively in submit exit code.

`worker [--json]` starts the separate three-queue worker with exclusive worker.lock. SIGINT/SIGTERM stops polling and awaits bounded current Activities; force kill is a crash, not a run cancellation. Do not stop unrelated services/processes. Namespace/server setup is [quickstart](../quickstart.md).

Settings: `V3_TERMINAL_TEMPORAL_ADDRESS` defaults to existing `V3_TEMPORAL_ADDRESS` or `127.0.0.1:7233`; `V3_TERMINAL_TEMPORAL_NAMESPACE` defaults to `v3-terminal` and must differ from the legacy namespace; `V3_TERMINAL_ROOT` defaults to the private directory in [data-model](../data-model.md). Fixed production limits 4/4/2 and queue bound 64 are not CLI per-run overrides. Test-only dependency injection may lower rotation thresholds without exposing a production user flag.
