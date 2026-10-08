# Brave and NotebookLM authentication diagnosis

The owner's current instruction permits browser setup. This supersedes the earlier
prohibition for explicit authentication work.

## Observed cause

The desktop default browser is `google-chrome.desktop`, so generic URL opening uses
Chrome. Brave is installed at `/usr/bin/brave-browser`. Reusing Brave's ordinary data
directory can show its existing profiles. Explicit executable selection plus a
separate data directory avoids both behaviors.

The copied credentials were valid: the same stored cookie header returned a 302 to
Google sign-in on `notebooklm.google.com`, and a 200 authenticated landing page with
CSRF/session fields on `notebook.google.com`. A read-only full-library request on the
latter also returned 200. These probes did not create notebooks or upload a PDF.
Only response status and structural information were printed, not cookies/content.

The subsequent MCP call exposed another concrete parser bug: one full-library row
had a null title. The parser now maps null to `Untitled` while rejecting other invalid
shapes. The built MCP checker then succeeded with 20 notebook entries. `.env` now stores
`NOTEBOOKLM_ORIGIN=https://notebook.google.com`, and the checker reads it by default.

## Changes

- `src/browser-auth.ts`: explicit browser executable and profile directory; Default
  profile and startup profile-picker suppression; browser version check uses argv.
- `src/cli.ts`: browser-only auth operation without generic/manual fallback.
- `scripts/v3/setup_local_credentials.py`: optional explicit Brave browser setup;
  retains `~/.notebooklm-mcp/brave-profile`, preserves the native auth cache location,
  does not repurpose HOME, and optionally persists the chosen origin.
- `src/client.ts`: nullable full-library title handling, covered with the observed
  structural case using a synthetic notebook identity.
- `v3_engine/local_env.py`: allows persisting the NotebookLM origin.
- `scripts/v3/check_notebooklm.py`: defaults to the configured origin.
- Updated local setup/MCP documentation and added regression tests.

## Evidence and limits

303 local MCP tests passed, including browser-selection and null-title regressions.
20 Python setup/client tests passed; 9 local-env/setup tests passed. These selections
overlap. TypeScript checks and the local MCP build passed. Real library verification
passed using the owner's existing imported session. Browser-selection tests use
process doubles; a new Brave login was not launched because the current session
already authenticated. An existing notebook detail check was also run, with its
safe outcome recorded in the accompanying turn; no notebook title/content was printed.

Run `.venv/bin/python scripts/v3/setup_local_credentials.py --refresh-notebooklm --browser-notebooklm`
when explicit browser setup is needed. This opens only the dedicated Brave data
profile; the first use needs the intended account's login. The regular verification
command now uses the working hostname automatically.

This does not certify the full Unit3/PDF pipeline or real JEV evaluation. Numbered
specifications, PHP application code and ordinary browser profiles were not edited.
