# Local V3 provider credential setup

Updated 2026-10-07. Manual mode imports cookies without opening a browser. Explicit
`--browser-notebooklm` mode opens Brave using a persistent dedicated profile and
captures credentials through the MCP's CDP flow. It selects Brave directly, bypassing
the desktop default (Chrome on this machine) and the normal Brave profile chooser.
The application uses the locally patched MCP described in
[its README](../../integrations/notebooklm-mcp/README.md).

Live diagnosis on this machine confirmed that the imported cookies authenticate on
`https://notebook.google.com`, while `https://notebooklm.google.com` redirects to Google
sign-in. The working origin is now saved in `.env`; the checker reads it by default.
The full-library parser also now accepts a null notebook title as `Untitled`.
The checker successfully listed 20 entries. This verifies provider access, not the
entire PDF pipeline or the account's email identity.

## Build the MCP once

From `/home/moahmmed/college_project`:

```sh
npm ci --ignore-scripts --prefix integrations/notebooklm-mcp
npm run build --prefix integrations/notebooklm-mcp
```

## Automatic Brave setup

Your current imported session works, so another login is not needed now. For the
next explicit browser authentication, run:

```sh
.venv/bin/python scripts/v3/setup_local_credentials.py --refresh-notebooklm --browser-notebooklm
```

The script defaults to `brave-browser`, uses `~/.notebooklm-mcp/brave-profile` with
`--profile-directory=Default`, and retains this profile after capture. It does not
use, clear or merge your ordinary Brave profiles. The first run needs a Google login
in this one dedicated profile; later explicit setup runs reuse it. Select only the
intended NotebookLM account there. No manual cookie copying is needed. To specify an
executable explicitly, add `--notebooklm-browser /usr/bin/brave-browser`. To change the
origin explicitly, use `--notebooklm-origin https://notebook.google.com`.

## Import a session explicitly

Use an existing browser profile with the intended Google account selected inside
NotebookLM. From a successful NotebookLM request in DevTools Network, copy the full
Cookie request-header value. A browser profile may contain several Google accounts;
cookies from an arbitrary profile do not establish the intended account.

In your own terminal:

```sh
.venv/bin/python scripts/v3/setup_local_credentials.py --refresh-notebooklm --manual-notebooklm
```

Paste into the hidden prompt. Do not paste cookies into chat. The importer rejects
incomplete headers, writes an owner-only cache atomically, and preserves old cache
bytes on invalid input. It leaves existing browser profiles untouched. It does not
create a browser refresh profile from imported cookies. Without explicit manual
import, missing credentials cause a failure and instructions; no login window opens.
Gemini's prompt also prints a URL without automatically opening a browser.

Existing Gemini configuration is reused. The script maintains ignored `.env` with
mode `0600` and removes legacy NotebookLM cookie overrides. Its final message reports
cache structure, not live authentication. JEV's demo waiver remains separate.

## Verify live access without writes or browser windows

```sh
.venv/bin/python scripts/v3/check_notebooklm.py
.venv/bin/python scripts/v3/check_notebooklm.py --origin https://notebook.google.com
```

These are separate explicit origin checks; no automatic hostname switching occurs.
The checker prefers the native cache over stale shell cookie overrides. It prints
safe status/counts only and exits 2 for missing authentication or transport failures.
To verify a specific known notebook, add `--notebook-id YOUR_EXISTING_NOTEBOOK_ID`.
A successful list does not prove identity; verify the intended account's known
notebook before writes. No check creates a notebook or uploads source text.

If the alternative origin is the one verified, set `NOTEBOOKLM_ORIGIN` to that exact
value in the worker process environment before starting it. Both known origins are
allowlisted; arbitrary origins are rejected. The default remains the upstream origin
until live evidence establishes a different choice for this session.

The MCP's `serve` mode disables browser authentication by default. Explicit
`NOTEBOOKLM_ALLOW_BROWSER_REFRESH=1` permits refresh through the dedicated retained
Brave profile. Without this setting an expired session returns an error. No
extension is required. Use the explicit Brave setup command for an initial login.

After the owner imported cookies, live NotebookLM library access succeeded on the
verified alternative hostname. The source-stage Unit3 test and the full pipeline
were not run during this authentication fix. Do not treat local
fixture tests as proof of provider access or the JEV waiver as a real JEV evaluation.

Background: [upstream re-review](V3-NOTEBOOKLM-UPSTREAM-AUTH-REVIEW.md).

## Live pipeline follow-up

The later [live pipeline test](V3-LIVE-PIPELINE-TEST-2026-10-07.md) found that the
manual-cookie session stopped authenticating after its initial successful reads.
The owner now authorizes browser refresh. Explicit browser setup persists
`NOTEBOOKLM_ALLOW_BROWSER_REFRESH=1`, the Brave executable and its retained profile
for the worker; it allows ten minutes for login. Restart the worker after successful
capture. Without this opt-in, runtime still fails rather than launching a browser.
Unit3 also has a separate confirmed visual extraction gap on page 1.

Capture now waits for the authenticated application's CSRF and session tokens and
reads only URL-scoped cookies. After saving it closes the dedicated browser and
releases the launcher's stderr pipe. A real retained-profile authentication run
finished successfully in 7.7 seconds without another login prompt.

`invalid_response` is not synonymous with expired credentials: this account's
library now exceeds the first 20-row response and exposes an unimplemented paging
shape. The list check fails clearly instead of claiming a complete list. Use a
known notebook ID for a scoped read; absence-based reconciliation must not rely on
a partial library. The active pipeline's source-scoped queries are independent
of listing.

The local pipeline uses `NOTEBOOKLM_QUERY_TIMEOUT_MS=240000`, passed to the MCP
CLI. Restart Python workers after changing that value. A stateful turn with an
uncertain receipt must not be automatically resent because its timeout increased.
