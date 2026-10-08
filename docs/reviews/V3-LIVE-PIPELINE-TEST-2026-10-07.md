# Live local pipeline test, 2026-10-07

Status: attempted, blocked. The full PDF-to-scene/review journey has not passed.
The owner authorized external test-PDF processing, approved the local templates,
allowed browser authentication again, and requested JEV Choice=pass, Score=100,
Noul=1 demo responses until the real JEV credential is available.

## Local preparation

The database was snapshotted to `/tmp/v3-full-pipeline-20261007/before-run.sqlite`.
Existing settings version 1 was preserved. Version 2 was published through the
application's PublishSettings service using the existing local admin. It permits
64 MiB, 150 PDF pages and 150 OCR pages, with two coding attempts, one prompt revision,
one scene repair and one technical retry. Existing approved templates/providers
were retained.

The local Laravel site is running at `http://127.0.0.1:8000`. Temporal, its Python
worker, the bridge, the named Laravel V3 intake/workflow queues and scheduler were
started. The existing loopback JEV demo responder runs at port 8764. Its typed
responses are demo data, not real TypeSafe evaluations. No unrelated Docker
containers were started. The existing scene checker/preview images are available.
Logs and run metadata are in `/tmp/v3-full-pipeline-20261007/`.

## Unit3 result

Source: `/home/moahmmed/Downloads/Documents/Math prep3/ilovepdf_split-range-math-prep3/Unit3.pdf`.
Size: 56,937,625 bytes; 119 pages.
SHA-256: `e34593f96812bb3c0e83cf813d7fd55f26a6d3862d25a3e02c32bbce3e3da621`.

- qpdf reported no syntax or stream encoding errors.
- clamdscan reported OK, with zero infected files.
- Real authenticated multipart upload was accepted by the teacher upload route.
  Attempt: `16027c22-fdc0-4e28-b9ba-701bbaff77c3`.
  Document: `f8bd12e0-1764-4ddb-af04-b0bfda79ae91`.
- Page 1 OCR confidence was 73.7, but the existing residual-visual gate returned
  `visual_unrepresented`, `validation`, and no usable canonical text.
- A full local OCR preflight was started, then interrupted after this independent
  page-1 diagnostic proved the document cannot pass the current source contract.
  This is not a completed report for all 119 pages. The first-page evidence is saved
  in `unit3-first-page-extraction.json` in the private run directory.

Unit3 was not submitted to NotebookLM and no processing case was created for it.
Intake acceptance does not imply extractability. Processing it requires a supported
way to represent its educational visual material; bypassing the gate or omitting
pages would lose source content and would not establish a successful Unit3 run.

## Separate small-PDF result

A clearly separate synthetic one-page pendulum teaching PDF was submitted to the
real website route to exercise downstream processing. It is not a substitute result
for Unit3.

- PDF size: 2,439 bytes; intake accepted.
- Upload attempt: `d63a093b-57bf-402a-9c6d-be39b595f764`.
- Processing case: `ce69f03f-6ad9-4b3c-803e-922c948b5f94`.
- Temporal workflow started; real extraction completed.
- NotebookLM source ingestion stopped on authentication failure. The notebook-create
  ledger entry remains `uncertain`; it must be reconciled before any resend.
- The workflow correctly recorded `source:blocked:sourcing`, rather than claiming
  source completion. Mapping, prompt generation, Gemini scene authoring, scene
  checks and teacher review were not reached in this live attempt.

The previously copied NotebookLM session succeeded for library/detail reads earlier,
then stopped authenticating during this run. A cookie-only landing refresh also
failed. This is a session-lifetime failure; initial successful listing was not proof
of durable source ingestion.

## Corrections and pending login

Authentication errors containing `Authentication expired` now retain the correct
safe category. Explicit `NOTEBOOKLM_ALLOW_BROWSER_REFRESH=1` permits the local MCP
and Python client to use browser refresh, matching the owner's revised permission;
without that setting the existing no-browser default remains. The local environment
now selects `/usr/bin/brave-browser` and the dedicated retained `brave-profile`.
The browser setup command allows ten minutes for login rather than two. Regression
coverage was added for the explicit refresh setting and authentication category.

The first dedicated login timed out. The same retained Brave profile was reopened
with the longer allowance, and the owner was asked to finish Google login. New
worker processes must reload the configured refresh settings after successful capture.
The new capture initially reported success prematurely: it saved cookies while
NotebookLM was still initializing, with empty CSRF/session/build tokens. Live
landing requests redirected to Google login. Inspection of the owner's retained
Brave window after loading showed real application tokens and URL-scoped cookies;
those cookies produced HTTP 200. Capture now waits for nonempty application tokens
and uses `Network.getCookies` for the configured NotebookLM URL instead of taking
every cookie in browser storage. A regression first reproduced the premature save,
then passed after the fix. Brave processes are recognized during dedicated profile
lock cleanup. A fresh live library read succeeded with 20 entries.

Local setup/client/env tests
passed 29 cases before the final environment persistence; the final checks are
recorded in this turn's tool output. The updated local MCP was built successfully.

## Follow-up source ingestion evidence

- A second normal case, `2f312230-3de8-46b6-a2ac-6aa1ac880fe0`, created a real
  NotebookLM notebook and added the pendulum text. Verification exposed another
  production contract mismatch: `source_get_content` requires both notebook and
  source IDs and returns `{text: ...}`. Python now supplies the notebook scope and
  normalizes that envelope to its internal content field.
- The current source wire response groups all text runs. The upstream parser read
  only the third run (48 characters). It now concatenates each ordered run without
  adding separators. The complete result exactly matches the 1,328-character
  canonical extracted source, including existing line breaks. Regression coverage
  first demonstrated truncation, then passed after correction.
- The second case's existing effects were reconciled through the signed application
  client to a bound source; no repeat create/add was sent. Its workflow had already
  ended as blocked, so this reconciliation is not reported as a resumed workflow.
- A third case, `453d6935-e67d-42d0-bd00-3affae7c52a8`, was started using the
  authenticated teacher's normal `process_again` route. Both real source effects
  are confirmed, and its Temporal workflow has entered NotebookLM mapping. Further
  stage results remain pending.

Verification so far: 303 MCP tests passed after the authentication change; 94
focused wire/client tests passed after the text parser change; 26 Python MCP/adapter
tests passed. These tests complement the live source checks and do not establish
full pipeline completion.

## Live query contract correction

The third case reached mapping but its Divide receipt was ambiguous. Temporal
activity timestamps show completion in approximately 1.16 seconds, rather than a
provider timeout. Separate diagnostic MCP questions returned HTTP 400. The owner's
authenticated Brave page successfully sent a separate diagnostic question and
received HTTP 200. Comparing that captured request established these differences:

- Source IDs require `[[[source_id]]]` overall, not one extra nested array.
- Initial history is null and the current browser configuration has an extended
  final configuration tuple.
- Current successful response chunks do not supply the old field-10 conversation
  identifier. Initial attempts to retain a sent UUID or a streamed UUID were wrong:
  live continuity probes failed. These are query identifiers, not recorded threads.

The MCP transport now matches that observed request shape. A regression test first
failed on extra source nesting and subsequently passed. A real source-scoped MCP
diagnostic produced a 175-character answer with a conversation ID. The full MCP
suite then passed 305 tests, and TypeScript checking passed. The earlier Divide is
not blindly resent: its original query ledger and waiting workflow are preserved.
The normal teacher `process_again` route created case
`9cad4413-e95e-4a2e-a8ef-7e9de665ed9a` for the next full-path attempt.

That case completed real Divide and coverage turns, producing a ready map with 13
work items. Divide took approximately 109 seconds and coverage 63 seconds. Its
First turn reached the MCP's 120-second query deadline and remains ambiguous; the
workflow waits and the ledger is not replayed. Query timeout is now configurable
through `NOTEBOOKLM_QUERY_TIMEOUT_MS`, validated before launch and passed to the
existing MCP CLI `--query-timeout` option. The local value is 240000 (four minutes).
This does not change what qualifies as a result or permit repeating uncertain
turns. MCP/adapter/local environment tests passed 36 cases after this change.
The configured Gemini model availability request returned HTTP 200; this is an
availability check, not proof of successful scene authoring.
Case `f7b10602-cec0-4038-b26b-9c2f21703d5e` completed mapping with 13 work items,
then blocked on First response validation. Its earlier query IDs were also not
durable conversation IDs. These attempts prove individual source-grounded responses,
not preserved multi-turn context or valid Sections.

## Durable conversation and shutdown fixes

The client now sends a null conversation ID on the first turn and resolves the
recorded thread through `hPTbtc`. It ignores streamed query IDs and restores the
complete `khqZz` history when a new MCP process continues that thread. Truncated or
malformed history fails validation. The provider's marked final answer is selected;
longer reasoning chunks are not used as the answer. Transport requests also carry
the notebook source path and Referer.

These decisions follow the upstream protocol analysis in
[notebooklm-py's chat stream parser](https://github.com/teng-lin/notebooklm-py/blob/main/src/notebooklm/_web/rows/chat_stream.py)
and were checked against this account's live request/response shape. A fresh live
probe confirmed a unique context label in the initial response and remembered it
after closing the client and starting another MCP process. Both assertions passed.
This is live conversation-continuity evidence, not full pipeline evidence.

Successful browser capture also exposed a launcher shutdown hang: Brave retained
the stderr pipe after tokens were saved, so the setup subprocess eventually timed
out. Capture now requests `Browser.close`, destroys the launcher's stderr and
unrefs the child during cleanup. The regression first failed on missing cleanup,
then passed. A real retained-profile authentication command exited 0 in 7.7 seconds
without asking the owner to log in again. The full MCP suite passed 307 tests;
TypeScript checking and the local build passed.

The corrected full-path attempt is
`6b9b155c-0029-41c3-af24-b563b822cacd`, created through the authenticated teacher's
normal `process_again` route using the unchanged synthetic document. Its results
completed Divide and coverage under the same recorded thread, then blocked on
First-format validation. Read-only history retrieval recovered all three turns;
the 7,512-character First answer has two Sections and eleven headings each, but
uses horizontal rules, Markdown Section titles and colon-free field headings.
The exact parser error is `Unexpected content before Section 1: '---'`.
It is not an authentication or JEV failure. No Sections were accepted.

The noncanonical target-work transport envelope now explicitly requests the
existing plain-text Section/colon-label contract. The canonical First body remains
byte-for-byte unchanged, and the parser remains strict. A regression first failed
because the format instructions were absent, then passed after the change; the
lesson suite passed 42 tests with three live/environment skips and one warning.
This envelope extends the documented 009 prefix for transport formatting; it does
not alter educational fields or their meaning. No numbered spec was edited.
The worker was restarted and a new normal case,
`c13a7c23-b98b-4a40-bc4c-f7d409b7c10c`, was dispatched to test the instruction live.
That live attempt produced a ready map with 15 work items, recorded Divide and
coverage under one thread, and accepted First at 11:30:57 UTC. Two teacher Sections
with all eleven fields were persisted. Execution and Second were subsequently
recorded. Third returned a 2,509-character answer but its blocks begin with
`Scene Type: Comparison` rather than the required scene type word. Python rejected
the structure and created no original prompt versions or completion receipt.
Read-only history recovery confirmed all six turns and saved the exact Third
response privately; no repeated Third was sent. The owner was asked whether
deterministic removal of that explicit formatting label is acceptable while
retaining the raw response and rejecting unsupported types. That acceptance-format
decision is pending. Gemini authoring, scene qualification and review were not reached.
The current notebook's scoped read also passed with exit 0 without a browser login.
Prior ledgers remain preserved. The failed First query still has a `sending` ledger
state despite the case being blocked; durable retention of invalid known answers
is a separate recovery gap, so its private diagnostic copy is not represented as
an application-accepted result.

The library now has enough notebooks for `fprTef` to return a row-list plus a
72-character continuation field. The list parser rejects this unimplemented paging
shape as `invalid_response`; this is not an authentication failure. The later
read-only check therefore did not pass. Resolving full-library pagination remains
necessary before absence-based reconciliation can rely on this account's listing.
No partial listing has been accepted as evidence that a notebook does not exist.

## Next actions

1. Resolve the Third formatting decision; retain and reconcile the exact received
   response rather than resending it. Repair the recorded-turn resume path so a
   recovered completion can advance the durable workflow.
2. Preserve prior uncertain turns and the separate invalid First evidence.
3. Continue the separate small-PDF journey with real NotebookLM/Gemini and the explicit
   JEV demo transport, ending at the original teacher's review.
4. Resolve Unit3's visual-source representation separately before claiming a full
   Unit3 PDF-to-scene result.

No commit, deployment, numbered-spec edit, real JEV evaluation or automatic teacher
approval was performed. The goal remains incomplete.
