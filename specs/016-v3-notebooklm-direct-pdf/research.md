# Research and fixed decisions

**Inspected**: 2026-10-07. This is documentation research, not a provider upload or application test. See the preceding [adapter comparison](../../docs/reviews/V3-NOTEBOOKLM-ADAPTER-COMPARISON-2026-10-07.md).

## Provider selection

Use the Python SDK `notebooklm-py==0.8.4`, rather than its optional MCP interface, for direct-case file and chat operations. The SDK exposes file upload and source readiness; the optional MCP surface introduces another server contract. Pin the version and validate installed signatures before implementation. [SDK Python API](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/docs/python-api.md), [MCP guide](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/docs/mcp-guide.md).

The upload implementation registers and streams files through a resumable pipeline. Registration failures may preserve a source ID; that ID is useful recovery evidence. A successful call alone does not establish readiness or original-byte echo verification. The application therefore records input hashes and independently polls known IDs. [Pinned upload implementation](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/src/notebooklm/_web/sources/upload.py).

The SDK storage-state format and base-URL configuration differ from our MCP flat auth cache and `NOTEBOOKLM_ORIGIN`. Preserve domain/path cookie metadata and map the approved origin explicitly. Do not point the SDK at the old JSON file or rely on a different default origin. [SDK configuration](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/docs/configuration.md).

The adapter owns managed async client lifetime and captures server-recorded conversation IDs. Read history for recovery; never treat repeated question text alone as a turn ID. Configure writes the complete settings block, so explicitly supply goal, response length and custom prompt, then verify read-back. [Pinned chat implementation](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/src/notebooklm/_chat.py).

Compatibility remains a mandatory implementation check. The package uses internal Google transport, not a supported official public API; dependency resolution, no-retry behavior and live output grammar cannot be certified by documentation research. [Stability statement](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/docs/stability.md).

## Repository evidence and consequences

| Evidence | Finding / fixed decision |
|---|---|
| `integrations/notebooklm-mcp/src/tools/source.ts`; `v3_engine/sources/mcp_client.py` | Current selected source operations cover text/URL/Drive, not PDF upload. Keep this integration for legacy cases; do not pretend `notebook_add_text` accepts binary PDF. |
| `scripts/v3/setup_local_credentials.py`, `check_notebooklm.py`, integration auth modules | Existing dedicated Brave capture can supply session material, but SDK-compatible storage requires a bridge. Explicit setup may open a browser; runtime never does. |
| `v3_engine/pyproject.toml` | Python>=3.12, httpx>=0.27, SDK absent. SDK range narrows httpx; install/check in isolated environment first. No resolution was run during authoring. |
| `StartProcessingCase.php`, `AcceptedPdfReader.php` | Existing intake captures settings and validates the private file. Extend same transaction/stream; preserve process/process_again idempotency and active owner checks. |
| `2026_10_02_000008_create_v3_source_tables.php` | Binding requires extracted-source FK and single source ID. Add nullable mode-dependent extraction fields plus two-source manifest; a dummy extraction row would falsely claim OCR/evidence. |
| `ActivateSource.php`, `MapContextController.php`, `AcceptLessonMap.php` | Existing discovery/page count depend on extraction. Direct mode uses sourcing activation and accepted Document page count. Coverage query is omitted explicitly, not represented as passed. |
| `map_parser.py` | Separate counters for direct Lessons and Minis can collide in `position_path`; new grammar uses one sibling counter. Never retrofit old persisted identities. |
| `first_parser.py`, `prompts/parser.py`, `prompts/cycle.py` | Existing First expects `Lecture: Wn | path`; root framework uses Lecture name. Existing staged sends are incompatible with the new fixed combined loop. New protocol parser must be versioned rather than weakening legacy parsers globally. |
| `RecordPromptTurn.php`, `PromptReceiptSender.php`, `LessonCursorService.php` | Existing separate transactions expose intermediate states. New coordinator owns one atomic transaction and actual cursor versions; reuse digest/evidence helpers, not HTTP calls to three partial commit endpoints. |
| `case_workflow.py`, `worker.py`, `ProjectCaseEvent.php` | Captured workflow branch plus replay gate required. New source path skips extraction; recoverable uncertainty requires a real signal/timed recheck. Per-work event identity must prevent duplicate-key suppression across Lessons. |
| `CaseOperationGuard.php`, evidence projection and review services | New claims/commits must obey existing controls and owner gates. Source lineage changes; JEV/scene/review ownership does not. |

## Owner decisions and alternatives

1. **Original PDF**, replacing text extraction for new cases. Keep qpdf/ClamAV as intake checks; they are not OCR. No local fallback text upload.
2. **Current root steps.txt unchanged**, explicitly selected by owner. Preserve file digest and immutable private snapshot. Do not concatenate shorter009 bodies into it.
3. **All three outputs per Lesson**, explicitly selected by owner. A separately pinned response-format profile makes the combined envelope concrete. Do not edit the canonical files or infer missing First content.
4. **One provider for upload/chat**, with reuse of credential capture only. A dual-MCP hybrid would add conversation/account/state drift without solving application ledger duties.
5. **No automatic stateful retries**. SDK capability must be tested at HTTP level; listing absence and timeout are not nonacceptance evidence. Unknown outcomes remain recoverable uncertainty, not a false success.
6. **No fabricated page coverage**. The direct map records structural parsing only; owner-reviewed source fidelity/live fixtures provide evidence without pretending to prove every fact.
7. **Reuse downstream services**, with real integration gates. The 2026-10-04 review and 2026-10-07 live report show why passing isolated suites cannot establish the full pipeline. No stage completion shortcut or production test-only helper is permitted.

No unresolved owner design choice remains. Provider compatibility, dependency resolution, original-PDF live ingestion and combined-output adherence remain execution prerequisites to prove, not facts established here.
