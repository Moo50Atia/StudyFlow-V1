# NotebookLM readable-name experiment

Result: changing the notebook title and actual PDF filename to readable names
did not remove the observed upload failure. The experiment does not support
UUID-style names as the sole cause of this failure.

| Field | Prior control run | Readable-name run |
| --- | --- | --- |
| Notebook title | `V3 Mechanics 7550ed8a-d851-5d1d-95b5-0ef1b9738885` | `Mechanics Study Notes` |
| Actual PDF upload basename | `hosted-pdf-0001.pdf` | `Mechanics Part 1.pdf` |
| Framework title | Captured internal steps filename | `Teaching Instructions.txt` |
| Framework creation | Succeeded through native text API | Succeeded through the same API |
| First PDF transfer | HTTP 401 at `upload_finalize` | HTTP 401 at `upload_finalize` |

Both runs used the same prepared PDF bytes, account, Laravel admission, local
worker, Temporal workflow, SDK and upload protocol. The first part's SHA-256 is
`91e19792296c3bdf078a071d0d6bcf9942c4bcb02a02edb7d67cfc45ddc80547`.

The isolated worker naming profile changed the real basename passed to the
upload SDK before dispatch. It did not merely rename a source after uploading.
The worker verified each alias against the captured input hash and retained the
normal mutation fence and operation journal. The observer did not perform
provider calls, claim tasks, submit results or manually advance the workflow.

The worker created the notebook and framework, then held automatically after the
PDF upload rejection. Prompt generation and placeholder scene return were not
reached. No paid scene generation occurred.

Notebook: [Mechanics Study Notes](https://notebook.google.com/notebook/ab3d7dec-f4d7-4c62-bc82-50d550f05607).

Evidence directory:
`/home/moahmmed/.local_worker/verification/live-114-readable-names-20261024-008`.
It contains the captured `naming-profile.json`, `verification.json`, safe operation
failures and `naming-experiment-result.json` with the direct provider readback.

Control directory:
`/home/moahmmed/.local_worker/verification/live-114-native-framework-20261024-007`.

Two focused checks passed for the test profile: readable aliases preserve the
exact sealed PDF bytes, and changed bytes are rejected before provider dispatch.
