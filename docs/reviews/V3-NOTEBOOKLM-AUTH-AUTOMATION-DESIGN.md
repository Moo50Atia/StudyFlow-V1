# NotebookLM authentication automation design

**Implementation update (2026-10-07):** See [implemented corrections and test results](V3-NOTEBOOKLM-AUTH-FIXES-2026-10-07.md). The historical findings below describe the pre-fix state. Live access remains unverified because the saved session is absent.

**2026-10-07 correction:** The extension-first recommendation below is superseded by the [upstream authentication re-review](V3-NOTEBOOKLM-UPSTREAM-AUTH-REVIEW.md). The MCP already provides CDP capture and a persistent dedicated profile. First fix local error masking and investigate upstream origin/listing compatibility under a no-browser runtime policy. The extension sections and NA tasks are a deferred optional design for existing-profile capture, not required installation instructions or the current execution plan.

Date: 2026-10-06. Status: researched implementation proposal, not an implemented or live-verified replacement. This document addresses NotebookLM setup only. Keep the application pipeline stopped until the owner resumes it. Do not open login windows automatically. Gemini configuration and the JEV demo waiver are separate.

**The account decision**

Choose the browser profile containing the Google account that should own this project's NotebookLM notebooks. A Chrome/Brave profile and a Google account are different things: one browser profile can contain several signed-in Google accounts. The NotebookLM account selected inside the tab matters as well as the browser profile.

For the current manual prompt, the required value is the full Cookie request-header value from a successful NotebookLM request in that intended tab. It is not a cookie from any convenient profile. Even that value alone cannot prove which account the MCP will subsequently use. The current script does not solve that problem.

The replacement should work with any profile the operator deliberately selects, then retain that selection. It must never try all four profiles until one works or silently change accounts after a failure. One local provider account is sufficient for this pilot; this proposal does not introduce application multitenancy.

**Deferred optional extension flow**

Use a small browser extension installed in the chosen existing profile, connected to a local Python credential service through native messaging. The browser supplies the selected session without a new browser window, profile copying, or manual cookie transcription. This is a proposed integration, not an existing capability of the selected MCP.

1. Once, install the local extension and native host. Open NotebookLM yourself in the intended existing profile and select the intended Google account.
2. Click “Use this NotebookLM account” in the extension. Confirm the visible account and choose an existing private notebook owned by it as the account-continuity marker. If none exists, create a small empty private marker notebook in that account yourself during setup.
3. The extension captures authentication fields from a successful request made by that selected tab. It sends them directly to the Python service. No terminal cookie prompt is needed.
4. Python stages the session and launches the patched, pinned MCP with browser launching disabled. It validates a real notebook-list response and retrieves the selected owned marker notebook.
5. Only successful provider checks publish the session as `ready`. The screen displays the selected profile label, account label, verification time, and “NotebookLM connected.” Labels are operator-confirmed metadata, not credentials or provider-verified identity claims.
6. Later runs use that saved session. When refresh is necessary, the service requests a new capture from the same paired extension and existing selected tab. If that browser/tab/session is unavailable or Google requires login, return a specific failure. No window opens and no different account is tried.

This automates credential capture and reuse while an authenticated browser session exists. It cannot guarantee indefinite unattended Google login after Google revokes the session or requires a challenge. Under the owner's rule, that condition is a failed authentication stage, never an automatic browser-login fallback.

**Why Python plus a special curl command is insufficient by itself**

Curl can send supplied cookies and maintain a cookie jar. It does not select a Chrome profile or obtain a Google login merely by requesting headers. A `Set-Cookie` response from an anonymous request is not proof of an authenticated account. See [curl's cookie documentation](https://curl.se/docs/http-cookies.html).

DevTools “Copy as cURL” is useful because it copies an already authenticated browser request. A Python importer can parse that copied request locally as a migration tool. It must treat it as data and build its own allowlisted verification request; it must never execute the pasted shell command. It still requires manual capture after credentials expire, so it is a fallback to the extension design. Chrome documents request copying in the [Network reference](https://developer.chrome.com/docs/devtools/network/reference).

| Approach | Fits the requested behavior? | Decision |
|---|---|---|
| Extension in a chosen existing profile plus Python native host | Captures that profile's live requests and refreshes from its existing tab; no new browser required | Deferred optional integration; investigate upstream first |
| Parse a locally saved “Copy as cURL” request | Retains more account/session context than cookie-only paste; still manual | Explicit diagnostic/import fallback |
| Read/decrypt every profile's Cookies database | Depends on browser/OS storage, permissions, locking and encryption; does not identify the intended account by itself | Do not implement as the normal flow |
| MCP's dedicated browser profile | Existing implementation launches another browser and can request login | Excluded by the owner's requirement |
| Curl without existing browser session credentials | Cannot manufacture an authenticated Google session | Not a solution |

Chrome 136 changed remote debugging so the default Chrome data directory cannot simply be attached using the old debug flags. This is another reason not to promise transparent CDP attachment to arbitrary ordinary profiles. See [Chrome's remote-debugging change](https://developer.chrome.com/blog/remote-debugging-port).

**Evidence from the installed code**

The installed package is `@m4ykeldev/notebooklm-mcp@0.3.1`. Its inspected bundle is `/home/moahmmed/.npm/_npx/870ee1b8f6e912f0/node_modules/@m4ykeldev/notebooklm-mcp/dist/cli.js`. The npm cache location is evidence only; future implementation must not patch a cache file in place. Its upstream repository is [m4yk3ldev/notebooklm-mcp](https://github.com/m4yk3ldev/notebooklm-mcp). The bundled implementation is more specific than the README's authentication claims.

| Inspected location | Actual behavior | Required correction |
|---|---|---|
| `scripts/v3/setup_local_credentials.py`, `_notebooklm_auth_cache_is_valid` | Checks presence/nonempty values of five named cookies | Rename this as a structural check; it cannot mark a session authenticated |
| Same script, `_run_notebooklm_auth` | Browser mode uses a temporary HOME/profile, copies `auth.json`, then deletes the temporary profile | Replace normal setup with selected-profile capture; this profile cannot provide persistent refresh after deletion |
| MCP bundle, `runFileImport` | Parses raw cookie text, checks cookie names, saves empty CSRF/session fields; performs no live verification | Do not treat “Authentication tokens saved successfully” as a provider pass. Despite README wording, this branch is not a general token-JSON importer |
| MCP bundle, `loadTokensFromEnv` / `loadTokensFromCache` | Environment credentials override disk; stored fields include cookies, CSRF token, session ID, optional build token, timestamp | Remove competing overrides from the child environment and pass one explicit session file |
| MCP bundle, `refreshCookiesHeadless` | Calls `launchChrome(false)` | It can open a visible window despite its name |
| MCP bundle, `AuthState.refreshOnce` | Falls back to `runBrowserAuthFlow` | Disable both browser paths in the application runtime, including explicit refresh tools |
| MCP bundle, `RpcTransport.buildUrl`, `buildQueryUrl`, `fetchLandingHtml` | No explicit `authuser` selection; fixed base URL and reconstructed headers | Preserve captured account routing through landing, normal RPC and streamed query requests; prove it live before supporting secondary accounts |
| MCP bundle, `extractCsrfFromPage` | Can fall back to `FdrFJe`, which is also used for session ID | Require correct CSRF extraction; do not substitute the session ID for a missing CSRF token |
| MCP bundle, `callBatchexecute` / `execute` | HTTP 401 is a generic Error; framed code 16 is AuthenticationError | Normalize both as authentication failures without launching Chrome or blindly resending writes |
| `v3_engine/sources/mcp_client.py`, `call_tool` / `notebook_list` | Ignores MCP `isError`; a parsed error object can become an empty list | Raise typed errors and require successful result schemas |
| `v3_engine/sources/notebooklm_adapter.py`, `ingest` | Exceptions are suppressed and effects can become uncertain | Retain uncertainty for sent mutations; preserve a safe error category for diagnosis |
| `v3_engine/workflows/case_workflow.py`, after `ingest_res` | Only `blocked` prevents `source:sourcing:completed`; `uncertain` can reach completion | Only `bound` may complete sourcing and release mapping |

Earlier live attempts returned `NotebookLM RPC wXbhsf returned HTTP 401 Unauthorized`. An apparent notebook count of zero was produced by the client's error handling and was not valid evidence that the account contained no notebooks. Cookies existing on disk never proved live access. The exact cause of those 401s is unproven; expiry, mismatched account routing, or transport incompatibility must be distinguished by the replacement's checks. Do not label all 401s “expired cookies.”

**Browser capture contract**

Proposed directory: `integrations/notebooklm-session-extension/`. Use Manifest V3 with `activeTab`, `storage`, `webRequest`, and `nativeMessaging`, and host permission only for `https://notebooklm.google.com/*`. If a required request has a different initiator and cannot be observed under this permission, report `capture_unsupported`; do not silently broaden access. No browser `debugger` permission or cookie database reading is needed for the primary capture path.

Observe `onBeforeRequest` for form fields and `onBeforeSendHeaders` with `requestHeaders` and `extraHeaders` for cookies. Correlate by request ID, tab ID, and the extension's local profile instance ID. Accept only the selected tab and the exact NotebookLM origin. Keep the capture armed for at most 60 seconds. Wait for completion of the same request; an HTTP 200 alone does not prove provider authentication, so independent MCP verification remains mandatory.

Chrome documents the permissions and `extraHeaders` requirement for observing Cookie headers in [webRequest](https://developer.chrome.com/docs/extensions/reference/api/webRequest). This API is the proposed automatic capture mechanism, not a claim that it has already passed on this machine's browsers.

The extension creates a UUID in local, nonsynchronized storage to identify its installation within that profile. Register a human-readable profile label during pairing. Do not assume the extension can read Chrome's real profile directory name or that its UUID is a Google identity. Disable incognito participation for this pilot.

Extract only `Cookie`, request `at` (CSRF), `f.sid`, `bl`, and the observed account selector (`authuser` and, if present, `X-Goog-AuthUser`). Verify that header/query selectors agree. Preserve selectors as opaque validated decimal strings; they are session-relative indices, not permanent Google account IDs. If no selector is present, label routing `default`, and require the owned notebook continuity check. Never infer that “Profile 2” means Google `authuser=2`.

Do not retain `f.req`, query text, response bodies, unrelated headers, or page content. Parse the form in memory, retain only allowlisted auth fields, and discard the rest. Reject ambiguous duplicate cookie names because the pinned MCP stores cookies in a name-to-value map that cannot represent domain/path duplicates faithfully. Missing fields cause `capture_incomplete`; capture another request from the same selected tab. Do not invent CSRF/build values.

The popup displays the local profile/account labels and marker notebook ID before initial pairing. The operator verifies the NotebookLM account avatar themselves once. This plus successful retrieval of the expected `ownership=mine` marker provides account continuity. It is not a cryptographic email-identity assertion; shared notebook access or a display label alone is insufficient. Secondary-account routing must pass the live two-account test below. Until then, return `account_routing_unverified` rather than claiming support.

**Local Python service and storage contract**

Proposed module: `v3_engine/notebooklm_auth/`. Register native host `com.college_project.notebooklm_session` for a fixed local extension ID, using Chrome/Brave native-host manifests appropriate to the installed browser. The host launcher uses this repository's absolute `.venv/bin/python` path. The installer must detect supported browsers, print the exact manifest locations it will use, and register only the requested browser. Pin a stable extension ID for local development so reloading the extension does not silently invalidate pairing.

Native messaging passes framed JSON over stdin/stdout; it avoids a localhost HTTP endpoint carrying cookies. Send diagnostic events to stderr only. Use a maximum application message size of 128 KiB, reject malformed lengths/JSON, and allow only the installed extension origin. Chrome's [native messaging documentation](https://developer.chrome.com/docs/extensions/develop/concepts/native-messaging) describes registration and framing. These are proposed host behaviors, not features of today's setup script.

Messages use `schema_version=1`, UUID `request_id`, and an enumerated `operation`: `pair`, `capture`, `status`, `request_refresh`, or `disconnect`. A capture additionally includes `profile_instance_id`, locally assigned `account_ref`, `captured_at` (UTC), `tab_origin`, `account_route`, `marker_notebook_id`, and secret `credentials` containing the captured fields. Reject captures older than 60 seconds or more than 5 seconds in the future. Maximum Cookie header length: 64 KiB. Replays of the same request ID and payload digest return the previous result; a changed payload under that ID is a conflict.

Create state under `~/.local/state/college-project/notebooklm/` (directory `0700`, files `0600`). Store credentials in generation-specific `sessions/<uuid>/auth.json`, metadata beside them, and an atomic `active.json` pointer containing only the generation ID and selected account reference. Keep credentials out of `.env`, application tables, command arguments, stdout, logs and Temporal history. The extension never persists credential values in browser storage.

Use an exclusive file lock around validation/publication with a 10-second acquisition timeout. Verify staged credentials before publishing the new pointer. Failure leaves the previous active pointer unchanged and records a safe validation state. Reject a capture from another profile/account after pairing; switching requires an explicit `pair --replace` operation with no in-flight provider mutations or active cases tied to the prior account. A live case must never migrate to another Google account during refresh.

Each MCP subprocess gets a private working copy of one validated generation. Provider Set-Cookie updates may modify that copy. It cannot overwrite the broker's published session. New operations use the latest validated generation after the old operation has finished; do not kill an in-flight write to rotate credentials. Clean working copies on close and reconcile abandoned files at startup under the lock. Keep the active and immediately previous generation while leases reference them; delete unreferenced superseded generations. Do not clear browser profiles during ordinary refresh.

**Required pinned MCP extension**

Maintain a small, reviewable source fork under `integrations/notebooklm-mcp/`, preserving the upstream license. Record the upstream source commit, npm package version, package integrity and patch set in `UPSTREAM.md`; verify the selected source builds to the behavior inspected above. Do not edit the npm cache or silently upgrade packages.

Add `NOTEBOOKLM_AUTH_FILE` and a `serve --auth-refresh=cache-only` mode. Both are proposed additions and do not exist in installed 0.3.1. Under this mode no browser launcher, `xdg-open`, interactive prompt, headless Chromium process, or browser fallback may run, including via MCP authentication tools. Preserve the existing notebook tool interface.

Extend token loading/saving and transport with `account_route` plus the captured `bl`. Apply consistent account routing to landing-page token refresh, all batchexecute calls and streamed queries; never drop it when loading disk state or recording Set-Cookie. Use observed routing, and test the actual Google behavior for both default and secondary accounts. Correct CSRF extraction must distinguish `SNlM0e` from `FdrFJe`. Redirections to Google login, missing authenticated page fields, HTTP 401, and framed auth code 16 are failures. HTTP 403 is `access_denied`, which may involve permissions or account policy, and must not automatically be described as expiry.

Normalize safe error codes into the MCP error envelope. Suppress provider response bodies and tokens in stderr. Retain bounded diagnostics: operation, HTTP status if present, safe category, generation ID and duration. Never enable raw debug payload logging for credential troubleshooting.

**Validation, refresh, and failure behavior**

The session state machine is `unconfigured -> staged -> validating -> ready`. Validation failure produces `auth_rejected`, `account_mismatch`, `capture_incomplete`, `account_routing_unverified`, `provider_unavailable`, or `protocol_error`. An expired ready session becomes `refresh_required`; a successful same-account refresh returns it to `ready`. Authentication failure never becomes “ready with zero notebooks.”

Validation order: check capture structure and origin; start a bounded MCP child with cache-only mode; check initialization and required tool schemas; call `notebook_list`; require `isError != true`, `status=success`, and an array with valid notebook IDs; then `notebook_get` the marker and require its ID and owned status. A legitimately empty list proves only list access, not selected-account continuity; initial pairing remains incomplete until the marker can be verified. Verify via a second fresh MCP subprocess after persistence to prove the saved state is what the runtime consumes.

Read-only validation has a 30-second per-call timeout and a 90-second total budget. A transient transport/5xx failure permits one delayed read retry within that budget. A 401/403 does not trigger browser login, account switching, or repeated polling. UI reports the failed step and safe code.

Normal workers require a `ready` session verified within the previous five minutes before starting a new notebook mutation. If older, recheck list/marker. The five-minute value is a local preflight policy, not a promised Google cookie lifetime. Ongoing provider errors still invalidate readiness immediately. Coalesce concurrent refresh requests per account so only one capture/validation occurs.

For automatic refresh, keep a native-messaging connection while the selected existing tab is available. The Python host exposes a same-user Unix socket at `~/.local/state/college-project/notebooklm/control.sock` with mode `0600` for local `status` and `request_refresh` commands; those commands carry no credentials. The host forwards a refresh request to the paired extension, which rearms capture and reloads only the already selected NotebookLM tab if needed. It must not create a tab/window. If the host/browser/tab is absent, return `profile_unavailable`; if the tab goes to login, return `login_required`. The operator must restore their browser login externally before capture can resume.

Only read-only verification may be retried automatically. If a notebook-create or source-add request was already sent and its outcome is unknown, retain the existing Laravel effect as uncertain. A refreshed credential does not authorize repeating that mutation. Reconcile the exact stored provider identity/title/content according to the source ledger. A failed or malformed list is never evidence of absence. Correct the workflow so only `status=bound` publishes sourcing completion; blocked/uncertain states cannot activate mapping.

**cURL import fallback**

Add a proposed `import-curl --file /absolute/local/file` command for explicit diagnosis or initial migration. Read a mode-`0600` file, cap it at 128 KiB, and parse the supported POSIX “Copy as cURL” format into tokens without a shell. Reject command substitution, command chaining, pipes, redirects, file-reference payloads and unsupported options. Accept only the exact HTTPS NotebookLM host and observed RPC paths. Extract the same allowlisted auth fields as the extension and discard the captured RPC operation/body immediately. Construct new read-only verification operations; never replay the copied request, which might create or modify content. The file is temporary; remove it after import when the operator selected consume mode. It must not become a log artifact.

**Implementation order and deliverables — all proposed**

| Task | Dependencies | Concrete deliverables | Completion evidence |
|---|---|---|---|
| NA-01 | None | Add `v3_engine/tests/sources/test_mcp_client.py` cases using actual JSON-RPC success/error fixtures; add `v3_engine/tests/test_notebooklm_auth_validation.py` for cookie-present/401 and marker mismatch. Fix error parsing in `v3_engine/sources/mcp_client.py` | A 401/error envelope raises a typed error; a true empty success remains distinguishable. Failure demonstrated behaviorally before the fix |
| NA-02 | NA-01 | Add pinned source fork, `integrations/notebooklm-mcp/UPSTREAM.md`, account-route and no-browser tests; implement explicit auth-file and cache-only transport mode | Tests trap every browser spawn path, including framed auth expiry and explicit refresh tools; selectors survive normal RPC, streaming, token reload and refresh |
| NA-03 | NA-02 | Add `v3_engine/notebooklm_auth/{contracts,store,validator,native_host}.py` and `scripts/v3/notebooklm_auth.py`; tests in `v3_engine/tests/notebooklm_auth/` | Real subprocess fake MCP exercises wire parsing; atomic promotion, interrupted writes, duplicate IDs and competing processes are tested. File lock tests use concurrent processes |
| NA-04 | NA-03 | Add extension `manifest.json`, `background.js`, `popup.html`, `popup.js`; add host installer `scripts/v3/install_notebooklm_session_host.py` and `integrations/notebooklm-session-extension/tests/` | Fixture tests precede capture implementation; real browser test proves selected-tab capture, native-host framing and no cross-profile overwrite |
| NA-05 | NA-03 | Add `v3_engine/notebooklm_auth/curl_import.py`; parser/error fixtures in `v3_engine/tests/notebooklm_auth/test_curl_import.py` | Quoting, duplicate headers, wrong hosts and executable shell text are rejected or parsed as specified without executing any command |
| NA-06 | NA-04 | Replace normal NotebookLM setup in `scripts/v3/setup_local_credentials.py`; add worker session resolver and refresh socket integration | Existing Gemini/HMAC values survive; readiness survives a fresh worker/MCP process; profile disappearance produces a clear failure with zero browser launches |
| NA-07 | NA-06 | Tests then fixes in `v3_engine/sources/notebooklm_adapter.py`, `v3_engine/activities/ingest_case_source.py`, `v3_engine/workflows/case_workflow.py`; extend source/workflow tests | Uncertain ingestion cannot emit sourcing-completed or map activation. Expiry after a sent write preserves the ledger and causes no duplicate send. Use Temporal versioning/replay validation before changing behavior of existing histories |
| NA-08 | NA-05–NA-07 | Live verification runner `scripts/v3/verify_notebooklm.py`, operator runbook update, sanitized report `docs/reviews/V3-NOTEBOOKLM-AUTH-VERIFICATION.md` | Live scenarios below pass. Browser extension mocks and fake MCP tests are explicitly separated from Google-backed evidence |

Commands proposed for the future implementation, not commands that passed in this documentation task:

```sh
.venv/bin/python scripts/v3/notebooklm_auth.py status
.venv/bin/python scripts/v3/notebooklm_auth.py verify
.venv/bin/python scripts/v3/notebooklm_auth.py refresh
.venv/bin/python scripts/v3/notebooklm_auth.py import-curl --file /absolute/private/request.txt
.venv/bin/python -m pytest v3_engine/tests/notebooklm_auth v3_engine/tests/sources
npm --prefix integrations/notebooklm-mcp test
```

`status` prints nonsecret readiness metadata; `verify` returns exit 0 only after the actual MCP/account-continuity checks; `refresh` never opens a browser. Define exit 2 for invalid local input, 3 for auth/login required, 4 for wrong/unverified account, 5 for provider/protocol failure, and 6 for busy/unavailable selected profile. A status file alone cannot grant readiness after the live verification age expires.

**Acceptance evidence**

| Scenario | Test/evidence | Required outcome |
|---|---|---|
| NLA-01 Four profiles | Extension fixtures plus live selected-profile capture, NA-04/08 | Choose profile B; A/C/D cannot overwrite its session; labels remain distinct |
| NLA-02 Two Google accounts in one profile | Fork transport tests plus live marker in each account, NA-02/08 | Select secondary account and prove the expected owned marker; no silent default-account fallback |
| NLA-03 Stale cookies with all required names | Validator/MCP wire fixture tests, NA-01/03 | `auth_rejected`; no active-session replacement and no success count of zero |
| NLA-04 Valid cookies, wrong account | Validator fixtures and live different marker, NA-03/08 | `account_mismatch`; no PDF sent |
| NLA-05 Saved session reuse | New native host and new MCP process, NA-06/08 | Read-only checks succeed with persisted credentials and zero new browser processes |
| NLA-06 Browser unavailable or sign-in required | Fork spawn traps and real stopped-browser test, NA-02/06/08 | Explicit failure; no automatic Chrome, login tab or manual prompt |
| NLA-07 Rotated session | Selected-tab recapture and fresh-process verification, NA-06/08 | Same account retained; only verified generation promoted |
| NLA-08 Concurrent refresh | Two real native-host processes competing for lock, NA-03/06 | One publication, deterministic conflict/replay handling, uncorrupted pointer |
| NLA-09 Malformed or executable cURL | Parser tests, NA-05 | No shell execution, network destination substitution or write-request replay |
| NLA-10 Lost create/add response | Source ledger and workflow tests, NA-07 | Uncertain effect retained; no duplicate send and no mapping release |
| NLA-11 Secret handling | Capture logs/stdout/files under fixtures, NA-03–06 | Credentials absent from logs/argv/Temporal; only designated private credential files contain them |
| NLA-12 Unit 3 source stage | Live report with document/extraction hashes and provider IDs, NA-08 | Actual source ingestion and source-grounded answer succeed; authentication-only pass is insufficient |

**Unit 3 final source-stage test**

Use exactly `/home/moahmmed/Downloads/Documents/Math prep3/ilovepdf_split-range-math-prep3/Unit3.pdf`. Previous local inspection reported 119 pages and 56,937,625 bytes. Recheck its hash/size before sending. Previous ClamAV scanning was interrupted, so no clean scan is claimed.

The last inspected local settings permitted only 1,048,576 PDF bytes, five pages and zero OCR pages. After the owner resumes execution, publish a new settings version sufficient for this exact file using the existing settings service; proposed intake limits are 67,108,864 bytes and 150 pages. Determine actual OCR need locally and set an explicit supported OCR budget before creating the case. Do not mutate an existing settings version or assume all pages have usable text.

Use the existing local extractor `v3_engine/sources/extract_pdf.py` and its page report, visual/structure gates and 1 MiB canonical-text limit. The 56.9 MB PDF size does not determine extracted-text size. If it fails to represent mathematical diagrams/equations or exceeds the canonical-text limit, report the actual extraction blocker. Do not truncate, silently omit pages, bypass a quality gate, or report a smaller sample as the complete Unit 3 test.

After authentication is ready and source extraction passes: create one ledger-tracked notebook for the new case, add the verified extracted text through `notebook_add_text`, read back the source and check the existing canonical-content verification, then ask one question whose answer is identifiable in an actual Unit 3 page. Record the question/answer privately as teacher content, the source binding, page evidence and operation statuses. Authentication logs contain only metadata. Stop after this NotebookLM source-stage verification; do not start mapping, prompt generation, Gemini coding, JEV, or review until the owner resumes the larger goal.

**What remains unproven**

No extension, native host, curl importer, patched MCP or replacement auth flow was implemented for this documentation task. No profile cookies were collected and no browser was opened during this review. Live secondary-account routing and browser header capture remain mandatory integration gates; installed 0.3.1 cannot be assumed to support them. The previous 401 has not been repaired by writing this design. Completion requires the tests and live evidence above, including the real Unit 3 source-stage result.
