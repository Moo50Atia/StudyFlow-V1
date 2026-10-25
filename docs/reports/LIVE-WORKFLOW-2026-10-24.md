# Full workflow live verification — 2026-10-24

Status: blocked at NotebookLM file-upload finalization. No full-workflow pass.

The observer launched real Laravel HTTP, the outbound local worker, the terminal
CLI and Temporal against disposable database/storage copies. The source was the
existing 114-page PDF split into pages 1–28, 29–57, 58–85 and 86–114. The worker
claimed tasks and made provider calls itself. Named placeholder HTML remained the
authorized scene-generation substitute; that stage was not reached.

| Stage | Observation |
| --- | --- |
| Ordered input download | All four prepared PDF files matched the captured SHA-256 hashes and ordinal/page bindings in every attempt. |
| Laravel / worker / Temporal | Started and dispatched the real terminal workflow automatically. |
| NotebookLM owner verification | Passed on the configured `notebook.google.com` origin. |
| New notebook creation | Succeeded automatically. |
| Source registration | Succeeded, leaving sources in `PREPARING`. |
| File bytes / finalize | Rejected by NotebookLM with HTTP 401 at `upload_finalize`. |
| Prompt generation and canonical storage | Not reached. |
| Placeholder scene return | Not reached. No paid scene generation occurred. |

The ordinary HTTP transport's rejected request targeted `notebook.google.com`
and included both product-cookie and account-cookie names. A separate attempt
with the SDK-supported `curl_cffi` transport also returned HTTP 401. This rules
out the tested transport change as a remedy. The observations do not establish
why Google rejected the upload or prove that refreshing the cookie will fix it.
The successful read-only ownership check proves access to the proof notebook;
file-upload authorization still needs successful live verification.

## Recovery correction

An upload exception previously reconciled as complete when a uniquely titled
source row existed, even if it had no processed content. Recovery now requires
the source to be ready before settling an ambiguous upload. An unfinished source
retains its dispatch fence and holds without resending the mutation.

Safe failure evidence now retains the provider error category, exception class,
upload stage, HTTP status, trusted app hostname and cookie-presence booleans.
Request URLs, cookie values and response bodies are excluded.

Validation: 106 focused Python tests passed, including source-registration-only
recovery, duplicate-send prevention, safe diagnostics, ownership classifications,
CLI output and worker health reporting.

## Evidence

Each attempt has an `observer-evidence.json` and `verification.json` under:

- `/home/moahmmed/.local_worker/verification/live-114-auth-fixed-20261024-001`
- `/home/moahmmed/.local_worker/verification/live-114-upload-verified-20261024-002`
- `/home/moahmmed/.local_worker/verification/live-114-upload-host-20261024-003`
- `/home/moahmmed/.local_worker/verification/live-114-browser-transport-20261024-004`

The first attempt was stopped after finding the false upload-recovery behavior.
The next attempts held automatically on the failed upload. Their isolated
processes shut down, and their task/provider journals remain available. Existing
notebooks were not deleted, and no task was manually advanced or committed.

Next gate: obtain a session that can complete a browser file upload, import it
through `./bin/product refresh-auth`, then start a new full workflow attempt.
