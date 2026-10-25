# Docker verification status

**The current 114-page OOP run completed manual export processing; educational coverage is not verified.** It was recursively split into four ordered PDFs and produced 61 persisted lesson bundles and 96 persisted scene prompts. Completion confirms that processing finished, not that every concept, example, or page was covered at the intended lesson granularity. Paid scene generation and classroom publication are separate steps and were not part of this manual-export run.

**Ordered recursive PDF preparation is deployed (policy v2).** New web submissions inspect originals with QPDF and streamed ClamAV, retain original bytes, and call signed local bridge preparation before run capture. PDFs over 50 pages are recursively halved until every part is at most 50 pages. Parts have padded source/page filenames and immutable range lineage; the worker verifies the captured inventory before provider calls. New terminal preparations use the same policy. Existing v1 inventories and older run captures retain their hashes.

The rebuilt stack accepted the actual 114-page OOP PDF through authenticated multipart HTTP. The earlier split run `f32107aa-4a3c-46a7-8ff9-81ce9210ac5d` contains exactly four ordered ranges: **1–28, 29–57, 58–85, 86–114**. Its first attempt exposed `LecW1` labels and title citations; it is safely held after retry exposed recovery defects. It is not the successful acceptance run.

Recovery fixes retain the immutable manifest's previous context, reuse committed completed intake, and hold invalid lesson replies before deleting provider history or advancing to another PDF. The earlier failure had already cleared provider conversations; retained artifacts and results remain intact.

Corrected manual-export run `bd6ec553-4f22-4a05-90cf-86cf7729aa50` completed with four parts. Its initial retained map used Markdown structural headings/bold labels; PHP/Python parse those labels without altering node order or the original answer. The same retained map was revalidated (19 work items, identical SHA, Divide send count remains 1) and a fenced retry was applied. The completed run has 61 lesson bundles, 96 saved prompt versions, four archived/reset parts, and no reported failures.

Teacher dashboard statuses now use the live progress state with persisted state as fallback and refresh every 15 seconds. The authenticated `/v3/teacher/workspace` URL opens the existing workspace, and its new-run buttons open subject selection. A real browser verified failed/completed/held statuses, automatic partial refresh, Workspace Hub navigation, and subject selection.

The earlier explicit-topic OOP run `507abfe8-6df3-4862-924a-e54e8da52a7a` completed with nine mapped lessons, 23 teacher sections, and 23 scene prompts. All eight persistent services remain healthy. Paid scene generation was not enabled or exercised.

## Latest live checks

- The authenticated multipart submission was accepted, split into four ordered ranges, and delivered by the scheduler after PDF inspection.
- NotebookLM processing completed across the four parts; the run finished with 61 durable lesson bundles, 96 saved scene prompt versions, and no reported failures. Every part was archived and its provider conversation reset.
- Authenticated progress for run `bd6ec553-4f22-4a05-90cf-86cf7729aa50` reports `completed` and returns the 61 lesson titles in PDF order.
- JSON prompt export returned HTTP 200 as `application/json` with attachment filename `run-bd6ec553-4f22-4a05-90cf-86cf7729aa50-prompts.json`; it contains 96 prompt records. Text export returned HTTP 200 as `text/plain` with an attachment `.txt` filename.
- The run page now presents ten persisted lesson bundles per page, with previous/next navigation.
- The original uploaded PDF and its four immutable part ranges remain in private storage. No paid scene generation was enabled or exercised.

## Fixes and compatibility

The pinned SDK 0.8.4 builds follow-up request context from a local conversation cache. Reading server history does not populate that cache. Fresh activity clients now restore the exact server conversation before each explicit follow-up, replacing stale cached context. Missing, malformed, or oversized history prevents a send. Transport tests verify the actual outgoing HTTP history across fresh clients and retain single-send behavior.

History restoration alone did not prevent provider topic drift. With user approval, new web submissions now capture `notebooklm_per_pdf_web_v2`. Laravel generates each exact request from the saved ready map, including the work ordinal, total work count, topic, three required artifacts, and completion marker. Python receives these questions through the signed binding response and uses them for both sending and history recovery. The query ledger rejects altered questions/hashes. Missing mapped questions cannot fall back to bare `Continue`. Existing v1 runs retain their original questions, captured manifests, and hashes.

The first v2 attempt exposed a map-format issue: NotebookLM supplied subtopic branches beneath named Lessons. Both parsers now keep those details in the retained raw map without creating extra work items. Ambiguous unlabelled siblings, orphan nodes, and prose remain rejected. Unindented Mini Chapter headings retain their tree Lesson children correctly. The map was recovered into nine work items using its unchanged original bytes and hash, verified against provider history, then accepted through the signed callback. Divide was not resent. Blocked-map revalidation rejects changed answer bytes or conversation IDs. The normal authenticated Retry control resumed this same run.

Earlier tests also corrected hierarchical lesson-number labels (`Lesson 1.1:`) during exact topic comparison, and made closed auth-blocked runs explicitly retryable through the normal fenced control path. Wrong-topic responses remain rejected. Earlier failed runs and their successfully saved lessons/prompts remain intact; no uncertain send fences were cleared.

## Regression results

- Latest focused Python SDK, protocol, web preparation/resolution, contracts, artifact bounds, and application-client suites: **86 passed** before adding the final expanded-manifest bound test (the artifact suite then passed all 5 tests). Terminal pipeline regression suite: **111 passed, 22 skipped**.
- Latest Laravel NotebookLM, subject intake, ordered preparation integration, and progress suites: **90 passed, 735 assertions**. Integration runs real Python/QPDF preparation through the signed PHP client with only malware/provider HTTP transport doubled; verifies order, originals, replay, and tamper rejection.
- Ordered Python preparation/resolver checks: **16 passed**; exact page content/rotation/order, policy compatibility, corruption rejection, and opaque Temporal references verified. Terminal per-PDF checks: **16 passed, 4 skipped** after updating the SDK test double to support the pinned SDK conversation cache.
- Latest Laravel intake/preparation/Notebook/progress/workspace checks: **98 passed, 827 assertions**. Recovery/ordered preparation/workflow/bundle checks: **39 passed** before the final LecW parser parameter was added; the parser suite then passed all **14 tests**.
- After the Markdown map adjustment: **58 Python recovery/protocol/preparation/workflow tests passed**, and **48 Laravel map/bundle/intake tests passed (410 assertions)**. Privacy/contract checks: **24 passed**. Existing access boundary checks: **5 passed, 49 assertions**.
- The rebuilt worker separately passed **26 protocol/recovery tests** before the final map-parser extension. The final parser extension passed the host suites above and the complete live run.
- Earlier real isolated checker/preview verification passed, including path/digest rejection and exact manifest preservation. The final targeted qualification/credential/worker suite had 19 passing tests.
- Earlier Laravel broad suite: 307 passed, one risky test. Earlier Python broad workflow suite: 202 passed, seven failing browser-login harness tests that assume the previous frontend login behavior. Those harness failures remain unresolved.
- This pass reconfirmed four joined browser harness login failures before interrupting the broad suite; the reference archive dry-run/materialization reached its browser check and failed at login too. Those frontend harness gates remain unresolved; the real authenticated Docker upload and processing check above succeeded.

## Previously verified infrastructure and preservation

`./bin/product up` starts product mode from a stopped stack and rebuilds/recreates after changes. Product mode renders built React assets without Vite. All eight persistent services have passed health checks; initialization and checker verification exit successfully.

A real 4 MiB multipart PDF upload passed QPDF and streamed ClamAV inspection without manual dispatch. Its private download preserved exact bytes. Oversized uploads returned application form errors. The scanner accepted a valid PDF and rejected EICAR. Queue timeout, scheduler heartbeat, and recent Temporal polling were verified.

Original accounts, PDFs, subject runs, lessons, sections, unexpired sessions, and Temporal history survived migration and stop/start. Original PDF hashes and environment secrets were preserved. Historical absolute checkout paths resolve through compatibility aliases without rewriting signed artifacts. Ports remain loopback-only, and Docker socket access is restricted to bridge/worker. Checker/preview containers retain their isolation.

## Remaining gates

- Development-mode live HMR remains unverified. Dependency reuse was implemented after a slow first attempt; a subsequent development build hit a Docker extraction error. Product builds/startup later succeeded.
- The revised ordered restart sequence still needs its dedicated verification. The initial simultaneous restart held the worker through its graceful timeout while Temporal restarted.
- Live interruption during an outstanding NotebookLM send remains unverified. Callback replay, recovery regression tests, and single-send records passed; no claim is made that all uncertain external sends can be resumed automatically.
- The latest explicit auth check returned exit 0 and read 52 library entries. This did not verify account identity. The full manual-export workflow is now verified by the completed live run above; paid generation remains untested.
