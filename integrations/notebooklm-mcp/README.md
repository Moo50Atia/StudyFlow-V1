# V3 local NotebookLM MCP

Based on `@m4ykeldev/notebooklm-mcp` 0.3.1, upstream commit
`477203f7a9485f8f2ff1df88e25c7cf2f9e38a6e` from
https://github.com/m4yk3ldev/notebooklm-mcp. Upstream MIT LICENSE is retained.
This is a source-controlled local adaptation, not an npm-cache edit or upstream release.

From the project root:

```sh
npm ci --ignore-scripts --prefix integrations/notebooklm-mcp
npm run build --prefix integrations/notebooklm-mcp
npm test --prefix integrations/notebooklm-mcp
```

The Python client launches this build explicitly with `node .../dist/cli.js serve`.
It no longer downloads/runs an unpatched npx package. Build artifacts and dependencies
are ignored; rebuild after checkout or source changes. Use the npm package-lock for
reproducible installation. The retained pnpm lock records the upstream dependency baseline.

## Local changes

- `serve` defaults to `NOTEBOOKLM_NO_BROWSER=1`. The owner-authorized local setting
  `NOTEBOOKLM_ALLOW_BROWSER_REFRESH=1` permits refresh using the dedicated retained
  browser profile. Without this explicit opt-in, expired credentials return an error.
- `NOTEBOOKLM_ORIGIN` selects exactly `https://notebooklm.google.com` (default) or
  `https://notebook.google.com`. Landing, RPC and query calls use the same origin.
  No automatic origin switching or cross-origin credential redirects occur.
- Landing-page failure/missing CSRF or session tokens fails authentication. A session
  ID can no longer substitute for CSRF. HTTP 401/403 produce authentication errors.
- Full listing uses the `fprTef` request/response contract reported in upstream issue 7.
  It accepts a direct row array or one wrapper array, rejects malformed rows, and
  does not infer ownership/source details from list entries. `notebook_get` provides
  details. Empty listing does not establish account identity or absence of a write.
- Existing upstream list fixtures and missing-token expectations were updated to
  the corrected behavior; local regression tests are `src/__tests__/v3-*.test.ts`.

Live verification on 2026-10-07 established library access, source creation and
exact source text readback. These results do not establish complete pipeline success.

## Explicit Brave authentication

Local setup now supports `--browser-notebooklm`. It passes
`NOTEBOOKLM_BROWSER_PATH` and `NOTEBOOKLM_BROWSER_PROFILE_DIR` to the local MCP
`auth --browser-only` command. These select Brave and a single retained profile
without invoking Chrome or the desktop default. Runtime `serve` starts from cached
credentials and may refresh only with the explicit opt-in above. Capture waits for
nonempty application tokens and reads URL-scoped cookies. Browser shutdown closes
the dedicated browser and releases the launcher pipe so Brave cannot keep the CLI
alive after successful capture.

Live verification on 2026-10-07 established that `notebook.google.com` accepts this
user's imported session and returned 20 full-library entries. The old hostname
redirected to sign-in. A null title observed in the response now becomes `Untitled`
rather than rejecting the library. The working origin is stored in project `.env`.

## Current query contract

Source IDs use the current provider nesting. A first turn sends a null conversation
ID; streamed query IDs are not durable conversation IDs. After a successful first
turn the client resolves the recorded ID through `hPTbtc`. A new client restores
the complete conversation through `khqZz` before sending a follow-up. It rejects
incomplete history rather than silently truncating it. A live two-client continuity
probe passed on 2026-10-07. Current streaming answers require the final-answer
markers; longer reasoning chunks are not treated as answers.

Python supplies `NOTEBOOKLM_QUERY_TIMEOUT_MS` through the CLI `--query-timeout`
option (1000–600000 ms). The local full-pipeline test uses 240000 ms; a timeout
does not authorize replaying an uncertain stateful turn.
