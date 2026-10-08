# NotebookLM integration comparison

Date: 2026-10-07. Scope: repository inspection and primary-source research.
No application implementation, package installation, credential migration, browser
login, provider upload or live notebooklm-py test was performed for this comparison.

## Recommendation

Adopt notebooklm-py behind one application-owned NotebookLM interface for the new
direct-PDF workflow, subject to the live gates below. Initially preserve the proven
Brave authentication setup. Prefer its Python API inside Temporal activities rather
than requiring the Python worker to spawn another MCP process.

A temporary hybrid is possible: the current MCP handles queries and notebooklm-py
handles file upload. It requires one account, one notebook identity, explicit source
IDs, one conversation writer and a shared application effect ledger. It should be a
transition strategy, not automatic failover after uncertain requests. The preferred
endpoint is one provider implementation for uploads and chat, with authentication
kept behind a separate boundary.

This is an engineering recommendation from inspected capabilities, not a claim
that switching packages makes the full application work.

## What was compared

- Installed application integration: the local adaptation of
  `m4yk3ldev/notebooklm-mcp`, package `@m4ykeldev/notebooklm-mcp` 0.3.1, upstream
  commit `477203f7a9485f8f2ff1df88e25c7cf2f9e38a6e`. The local copy has additional
  authentication, source parsing and conversation fixes.
- Candidate: `teng-lin/notebooklm-py` release **0.8.4**, confirmed against
  [PyPI metadata](https://pypi.org/pypi/notebooklm-py/json), release-tag source and
  current documentation. Pin a reviewed release rather than tracking `main`.
- notebooklm-py is a Python library with a CLI and an optional MCP server.
  Its MCP interface is explicitly experimental and outside its library API
  compatibility guarantees. Earlier wording that treated it as only a library
  was incomplete. [Release MCP guide](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/docs/mcp-guide.md)

## Capability comparison

| App requirement | Current local MCP | notebooklm-py 0.8.4 | Consequence |
|---|---|---|---|
| Upload original PDF bytes | No local file-upload tool or transport | File registration and resumable byte upload | Candidate directly addresses the new source flow. |
| Upload framework text | `notebook_add_text` accepts content or a UTF-8 file | Text ingestion and file ingestion | Keep the exact approved `steps.txt` bytes and source identity. |
| Wait for PDF processing | No direct PDF readiness flow | Source polling with ready, failure and timeout outcomes | Upload acceptance must precede a separate ready check. |
| Source-scoped questions | Explicit source ID and conversation ID | `chat.ask` supports source IDs and conversation ID | Divide selects the PDF; execution can select PDF plus framework. |
| Continue across client restarts | Local code now restores server history; a live probe passed | Server conversation resolution and history restoration are implemented | Candidate has the protocol support we previously adapted locally. |
| Authentication | Our dedicated Brave profile and native MCP cache were live-tested | Stored profiles, cookie state and managed client authentication | Reuse the working account, but adapt and test the credential format. |
| Full-library reconciliation | Adapted `fprTef`; pagination currently fails clearly | Web listing uses recently viewed notebooks | Neither can currently be assumed to prove absence in the complete library. |
| Application integration | Python -> JSON-RPC -> Node -> provider | Python -> SDK -> provider; MCP is optional | Direct SDK removes a subprocess/protocol boundary. |
| Durable case execution | Laravel ledgers and Temporal own it | Library does not replace these application responsibilities | Retain case identity, send claims, state and recovery fences. |

PDF upload registration, streamed transfer and readiness polling are implemented
in the candidate's [release upload pipeline](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/src/notebooklm/_web/sources/upload.py).
Its MCP also exposes file ingestion through `source_add` with a file source type,
path or bytes input; this is genuinely supported rather than treating binary files
as text. [Release source tools](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/src/notebooklm/mcp/tools/sources.py)

The candidate resolves recorded conversation IDs and hydrates prior turns through
its [chat implementation](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/src/notebooklm/_chat.py).
Its [notebook listing implementation](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/src/notebooklm/_web/notebooks.py)
explicitly describes recently viewed ordering and says complete owned-notebook
coverage is not independently confirmed. Do not turn that list into absence proof.

## Local evidence and boundaries

Inspected local files:

- `integrations/notebooklm-mcp/src/tools/source.ts`: text, URL and Drive ingestion;
  `file_path` reads UTF-8 text, not PDF bytes.
- `integrations/notebooklm-mcp/src/client.ts`: source methods, recorded conversation
  lookup and history restoration.
- `integrations/notebooklm-mcp/src/rpc/auth-state.ts`, `rpc/transport.ts`,
  `browser-auth.ts`: separate session, HTTP and browser-capture responsibilities.
- `v3_engine/sources/mcp_client.py`: stdio process, MCP handshake and envelope parsing.
- `v3_engine/sources/notebooklm_adapter.py`: ingestion orchestration also owns
  Laravel effect calls; its queries select a single bound text source.
- `app/Services/V3/Source/SourceEffectLedger.php`: operations are currently limited
  to notebook creation and text addition; bindings depend on extracted text.
- `v3_engine/lessons/map_service.py`, `lesson_processor.py`, `prompts/cycle.py`,
  `workflows/case_workflow.py`: existing mapping and separate framework turns.
- `v3_engine/pyproject.toml`: Python >=3.12 and httpx >=0.27.

The candidate requires Python >=3.10 and httpx >=0.27,<0.29, so the declared ranges
overlap. Dependency resolution and installation compatibility were not tested.
The base SDK is enough for application calls; the optional MCP extra adds FastMCP.
[Release package metadata](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/pyproject.toml)

Existing local live evidence is recorded in
[the pipeline report](V3-LIVE-PIPELINE-TEST-2026-10-07.md): source ingestion and
conversation continuity passed; the latest case recorded First, Execution and
Second but rejected Third formatting. No full scene/review success follows.
The new PDF-plus-framework flow supersedes the local-extraction design for future
implementation, but has not been implemented or tested.

## Proposed application interface

These names describe a proposed boundary, not existing methods:

| Component | Operations | Responsibility |
|---|---|---|
| `NotebookSessionProvider` | `load_session`, `refresh_session` | One account and credential authority; no educational flow rules. |
| `NotebookSourceUploader` | `create_notebook`, `upload_pdf`, `upload_framework`, `wait_until_ready`, `get_source` | Provider sources, exact local hashes, transfer state and readiness. |
| `NotebookConversationClient` | `ask`, `read_history` | Explicit notebook/source/conversation IDs and a typed answer receipt. |
| `NotebookLessonRunner` | `divide`, `initialize_framework`, `start_first_lesson`, `continue_lesson` | Owner-approved prompt order, map count, parsing and completion. |

The application owns these interfaces; provider classes implement them. Sending
and receiving remain one correlated `ask` operation rather than independent
classes that can lose the request/answer association. SDK calls belong in Temporal
activities, not deterministic workflow code. Laravel retains ownership, private
artifacts, accepted state and review.

The current credential cache is a name/value cookie map; the candidate supports
storage state and domain/path-aware cookie jars. Do not rename the JSON file and
assume compatibility. First try a tested bridge from the existing session; preserve
domain/path information during future capture. The candidate supports managed
storage loading and also direct token construction, but the flat view loses cookie
siblings. [Python authentication contract](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/docs/python-api.md)

Map our `NOTEBOOKLM_ORIGIN` explicitly to the candidate's `NOTEBOOKLM_BASE_URL`.
The latter supports the working personal hostname; do not carry over the wrong
environment variable and assume it controls both clients.
[Release configuration](https://github.com/teng-lin/notebooklm-py/blob/v0.8.4/docs/configuration.md)

## Migration controls

1. Keep the current provider and active case histories intact. Store provider/flow
   version on new cases; do not silently change the meaning of an existing binding.
2. Prove the SDK in an isolated integration harness before changing the live app.
   Use one explicit stored account and known notebook for the first read.
3. Use one application send ledger even during a hybrid. A lost upload or query
   response is uncertain; never resend it through the other implementation as fallback.
   Review SDK retry behavior against the application's single-send contract.
4. Add distinct persisted PDF and framework source roles. The map must use only the
   PDF. Framework execution may use both; it must not turn framework instructions
   into educational Lessons.
5. Pin the exact approved framework checksum. Root `steps.txt` is longer than the
   existing canonical copies; do not silently merge or rewrite those versions.
6. Keep raw answers private. The candidate's chat failure path can log answer
   previews; adapt logging before app use so operational logs do not disclose
   educational content. This is visible in the release chat source linked above.
7. Direct PDF ingestion changes source provenance, activation prerequisites and
   the Lesson protocol. Decoupling contains provider changes; it cannot eliminate
   these real cross-layer contract changes.

## Live gates before selecting the new provider

- The existing intended account performs a known-notebook read after adapter startup.
- Upload a separate small PDF without local OCR/extraction, then confirm its exact
  source ID becomes ready. Add the exact framework and confirm its source separately.
- Divide returns only textbook structure. Persist its processable Lesson count.
- Exact Execution -> `System Ready` -> `Start with First lesson` -> `Continue`
  works with both sources available, preserving all eleven First fields and each
  Section's Second/Third association. Record actual provider replies, not fixtures.
- A client restart continues the same recorded conversation; no Lesson is duplicated
  or skipped. A failed receive does not trigger an automatic resend.
- Exercise upload processing failure and uncertain send recovery before production use.
- Test the full Unit3 PDF separately, checking representative mathematics and visual
  content. Successful byte upload alone does not prove complete source interpretation.
- Run the application handoff through real Gemini and original-teacher review.
  JEV demo responses remain explicitly distinct from real evaluation.

Neither an adapter switch nor a mixed implementation fixes response formatting,
Section parsing, application recovery or scene/review integrations automatically.
Both selected Web integrations depend on Google's internal interfaces; compatibility
must be established through live gates, not inferred from repository feature lists.
The candidate explicitly describes its unofficial API status in its
[upstream overview](https://github.com/teng-lin/notebooklm-py/tree/v0.8.4).
