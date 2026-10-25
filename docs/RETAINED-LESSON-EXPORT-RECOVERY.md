# Retained lesson export recovery

The original local run completed all 19 NotebookLM lesson captures and archived PDF 1. Export then returned `input_invalid`: lesson 9 had an empty derived `scenes` list. Its unchanged raw answer actually contains the complete two scene prompts, but repeats the requested artifact delimiters in a quoted instruction/preamble. `raw_artifacts` selected that first quoted block instead of the final complete response envelope.

The parser now selects the last complete ordered FIRST/SECOND/THIRD/END envelope. The handoff loader can derive missing scenes from the retained raw answer only for raw handoffs with matching SHA-256 and matching lesson headers. It does not modify the source handoff or archive; provenance points to the retained raw answer. A hash mismatch cannot use this fallback. The exporter still rejects missing/empty prompts and requires all contiguous lesson ordinals.

The workflow previously raised after the failed export Activity, causing repeated failed workflow tasks and making `progress_v1` unavailable. Export validation failures now enter the same versioned, queryable pause used for other recoverable holds. After owner-authorized continuation, it retries only local export. Validated export counts supersede the earlier incomplete frozen scene index, while the lesson count must still match the workflow's saved lesson count. No NotebookLM turn is resent.

Validation:

- Actual unchanged retained archive: 19 lessons, 39 scene prompts, zero inventory issues.
- Isolated export of those retained inputs: `complete / ok`, one PDF, 19 lessons, 39 scenes. Output existed only in a temporary directory; production output and workflow were not manually advanced.
- Real Temporal history replay against the fixed workflow: passed, with no Activities executed.
- Broad terminal/bridge/archive/parser suite: 136 passed, 22 gated tests skipped. Additional validation coverage: 22 passed, 4 gated tests skipped.
- Background terminal and outbound workers restarted to load the fix. The private Temporal server was still backing off the prior failed workflow task immediately afterward. A targeted sticky-task-queue cache reset was requested; the workflow was not reset, forked, resumed, or replayed against providers. Queryability must be verified before owner recovery is presented as ready.

At 2026-10-23 01:00:08 UTC the server's old failed-task backoff completed. Live status returned `freshness=realtime`, `status=paused`, `phase=held_uncertain`, `state_version=10`, `lessons_saved=19`, and `pdfs_completed=1/1`. The workflow is now queryable at its export validation hold and can provide fresh reconciliation evidence. No workflow reset or provider resend was performed.
