# NotebookLM MCP authentication: upstream re-review

**Implementation update (2026-10-07):** See [implemented corrections and test results](V3-NOTEBOOKLM-AUTH-FIXES-2026-10-07.md). The historical findings below describe the pre-fix state. Live access remains unverified because the saved session is absent.

Date: 2026-10-07. Research and documentation only; no browser opened, credentials read, authenticated provider calls made, or application code changed in this review.

## Conclusion

The selected MCP already automates authentication. An extension is not required for its supported setup. The earlier extension-first recommendation was premature. It remains an optional, unimplemented approach specifically for capture from an existing ordinary browser profile; it is not the next prerequisite for diagnosing this installation.

Upstream reviewed: `m4yk3ldev/notebooklm-mcp`, commit `477203f7a9485f8f2ff1df88e25c7cf2f9e38a6e`, package `@m4ykeldev/notebooklm-mcp` version `0.3.1`, matching our pinned version. Repository inventory, README authentication/client instructions, changelog, authentication modules, RPC transport/client, auth tools and relevant test paths were inspected, along with upstream issues 6 and 7. This is an authentication review, not certification of every unrelated notebook/studio tool.

## How normal users authenticate

The [upstream README](https://github.com/m4yk3ldev/notebooklm-mcp#readme) prescribes CLI `auth`: a dedicated Chrome window opens, the user signs into Google and reaches NotebookLM, then CDP automatically captures cookies and page tokens. It saves `~/.notebooklm-mcp/auth.json` and retains `~/.notebooklm-mcp/chrome-profile`. Subsequent `serve` processes load the saved session; the refresh implementation reuses that dedicated profile. Environment credentials take precedence over the cache.

This is its own persistent profile, not a guest session and not automatic discovery of the user's four ordinary browser profiles. The account chosen within it supplies the MCP session. Cookie import and environment variables are alternative supported inputs, but importing cookies does not create a logged-in browser profile for future refresh.

## Findings and evidence

| Finding | Evidence | Consequence |
| --- | --- | --- |
| Our wrapper discards upstream's persistent profile | `scripts/v3/setup_local_credentials.py::_run_notebooklm_auth` changes the child HOME to a TemporaryDirectory, copies only staged `auth.json`, then deletes the directory | Saved API cookies survive, but that login's browser profile cannot be reused for refresh. This is a local integration defect; it does not by itself establish the cause of every 401. |
| Upstream refresh is not guaranteed invisible | `src/browser-auth.ts::refreshCookiesHeadless` calls `launchChrome(false)`; `src/rpc/auth-state.ts::refreshOnce` falls back to `runBrowserAuthFlow` | The stock version violates the owner's no-automatic-window policy. Its function name and README background-refresh language do not prove headless behavior. |
| HTTP and framed authentication failures take different paths | `src/rpc/transport.ts::callBatchexecute` throws generic Error for HTTP 401; `src/client.ts::execute` refreshes only AuthenticationError | Some failures never use the advertised automatic refresh. |
| Import is only structural | `src/auth.ts::runFileImport` parses cookie text and checks cookie names; our `_notebooklm_auth_cache_is_valid` also checks structure | A saved session is not a successful authenticated request. README's JSON-bundle wording does not match the raw Cookie-header importer. |
| Our Python client masks tool failures | `v3_engine/sources/mcp_client.py::call_tool` ignores `isError` and decoded `status:error`; `notebook_list` falls back to `[]` | A failed request can look like an empty library. Fix this before trusting readiness checks. |
| Host compatibility may explain valid-cookie failures | [Open issue 6](https://github.com/m4yk3ldev/notebooklm-mcp/issues/6) reports same-session success on `notebook.google.com` and 401 on `notebooklm.google.com` | Reported against our version. Not reproduced with this user's session; do not claim changing hostname guarantees recovery. Source still hardcodes the latter in constants and login detection. |
| Listing may not enumerate the full library | [Open issue 7](https://github.com/m4yk3ldev/notebooklm-mcp/issues/7) reports `wXbhsf` is recently viewed, with `fprTef` serving full listing and a different response shape | Source confirms current `LIST_NOTEBOOKS` is `wXbhsf`. The proposed replacement needs live validation and parser changes; never change only the identifier. Empty listing cannot prove an account lacks notebooks. |

The issue reports are external observations, not test results from this workstation. Issue 6 also reports that the session later failed on both hosts; it does not promise permanent authentication. The upstream auth-state and integration tests mock browser authentication, while browser tests mock process/CDP interactions. These tests establish code behavior, not current Google compatibility.

## Revised implementation order

1. **Expose actual errors first.** Add regression coverage for JSON-RPC failures, MCP `isError`, decoded error status, malformed success schemas, valid empty results, and successful notebook responses. Then correct `v3_engine/sources/mcp_client.py`. Preserve ambiguous write outcomes; do not retry notebook creation to diagnose login.
2. **Enforce no browser launches at runtime.** Use a reviewed, pinned adapter change rather than editing the npm cache. Cover automatic refresh and explicit auth tools: missing/expired credentials must return an authentication-required error without invoking Chrome, xdg-open or an interactive fallback. Cached-session requests remain usable. Headless browser launches also require a separately agreed policy; do not infer permission from the absence of a visible window.
3. **Check current transport compatibility using the existing session.** Only after step 2, perform bounded, read-only tests against the two explicitly allowlisted NotebookLM origins. Record status, final origin, required-token presence and RPC outcome without logging cookies, tokens or response bodies. Reject sign-in redirects as failed authentication. Validate a known intended-account notebook directly; do not use list length as identity evidence. Compare the issue-7 listing contract and response parser before adopting it. Do not follow redirects with credentials to arbitrary origins.
4. **Repair setup persistence without silently switching accounts.** If explicit interactive dedicated-profile setup is selected later, retain a stable MCP-specific profile and cache rather than a throwaway HOME. Separate explicit account reset from ordinary credential reuse. Do not run this mode now: the owner has prohibited automatic login windows. For current imported cookies, preserve them and report authentication-required if live checks fail. A cookie import cannot restore a deleted browser profile.
5. **Consider ordinary-profile capture only if still required.** If the owner requires continuous capture from one of the four already-open profiles, the extension/native-host proposal is an optional separate integration. Upstream has no inspected CLI option that provides that exact selection mechanism. Do not require a new extension merely to use this MCP.
6. **Prove the source stage afterward.** Correct account access, no-window failure handling and a valid provider response are entry gates. Then execute the authorized Unit3 source-stage test with real extraction and configured intake limits. Source ingestion must be confirmed bound, not uncertain. Do not resume the rest of the pipeline or claim real JEV qualification from a demo waiver.

## Completion evidence still required

- Existing-session read succeeds for the intended notebook/account, with typed error handling and verified transport origin.
- A deliberately invalid session fails clearly and opens zero browser processes/windows.
- Restart reuses the saved session; no hidden re-import or account change occurs.
- Full-list versus recent-list semantics are explicitly tested if listing is used.
- Setup retains its dedicated profile if that optional mode is later authorized; normal runs never clear it.
- Unit3 ingestion/query are real provider results before declaring the NotebookLM stage complete.

No live readiness claim follows from this review. The immediate investigation should focus on error masking and upstream transport compatibility, not another demand to copy cookies or install an extension.
