# NotebookLM authentication corrections

## Implemented

- `scripts/v3/setup_local_credentials.py`: explicit manual import parses the full
  cookie header locally, checks required cookies and duplicate names, atomically
  writes the owner-only native cache, and preserves existing profile directories.
  Removed the throwaway HOME/browser flow. Missing credentials fail with an explicit
  import instruction. Gemini setup prints its URL without automatically opening it.
- `v3_engine/sources/mcp_client.py`: uses the built local MCP, forces its no-browser
  flag, handles MCP error envelopes and malformed results, and reports sanitized
  error categories. Invalid notebook responses cannot invent a notebook identity.
- `integrations/notebooklm-mcp/`: pinned upstream 0.3.1 source with MIT attribution,
  npm lockfile and local changes. `serve` enforces no-browser operation. Both browser
  authentication paths reject under that policy. HTTP authentication failures and
  invalid landing pages fail clearly. No credential-bearing redirects are followed.
  An exact two-origin allowlist supports explicit hostname selection. Full-library
  listing uses the reported `fprTef` contract and its distinct parser; it does not
  invent ownership information.
- `v3_engine/workflows/case_workflow.py`: source outcomes other than `bound` block
  the new execution path. The `source-binding-confirmed-v1` Temporal patch marker
  preserves earlier decisions when replaying histories without that marker.
- `scripts/v3/check_notebooklm.py`: bounded read-only checker, explicit origin and
  optional known-notebook selection, safe output and no provider mutations.
- Updated local setup documentation and added the local MCP build/provenance README.

The npm cache, existing secrets, browser profiles, numbered specs and application
PHP were not changed. Existing workspace edits were retained. No commit or deployment
was performed. No browser window was opened and no PDF was uploaded during this fix.

## Verification

Meaningful failing tests were captured before fixes: MCP errors being returned as
success, malformed lists and fabricated notebook responses, browser-refresh behavior,
missing page tokens, incorrect list RPC/shape, and uncertain ingestion advancing.
Tests were then rerun against the corrected implementation.

- Built local MCP successfully with `npm run build --prefix integrations/notebooklm-mcp`.
- TypeScript check: `integrations/notebooklm-mcp/node_modules/.bin/tsc --noEmit -p integrations/notebooklm-mcp/tsconfig.json` passed.
- Full MCP suite: **301 passed**, zero failures. Provider calls are mocked, so this
  is not live Google integration evidence.
- Python source/setup suite: **46 passed, 3 deselected** (live/Temporal exclusions),
  with one existing Starlette/httpx deprecation warning. The new workflow branch
  tests were rerun after adding the Temporal patch marker: **3 passed**.
- Python tests include actual built-MCP stdio handshake/tool discovery and CLI auth
  rejection under the no-browser policy, plus malformed-response/import regressions.
- Source workflow branch tests use activity doubles. They are not a claim of a live
  Temporal run or production-history replay verification. Existing running histories
  must still undergo the deployment's replay checks before a worker rollout.
- The checker returned `authentication_required` because the native auth cache was
  absent. It made no authenticated provider request.

## Remaining live gate

`/home/moahmmed/.notebooklm-mcp/auth.json` was absent at verification time. No live
account, origin or full-library RPC check could be completed. These code fixes do
not recreate a missing Google session. After explicit local import, run the checker
against the intended origin and a known notebook. If the other allowlisted origin
works, configure it explicitly in the worker environment; there is no automatic
account/origin switching. The full-list RPC contract is supported by upstream issue
7 and fixture tests, not a live result in this session.

The Unit3 source-stage test and full pipeline remain unrun. No real JEV evaluation
was claimed. No custom extension is required by these corrections.
