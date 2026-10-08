# Feature Specification: V3 Knowledge Source Adapter

**Reviewed:** 2026-10-02 · **Status:** documentation design · **Prerequisites:** implemented 004–007 contracts and a provisioned selected NotebookLM MCP account.

## Purpose and boundary

008 turns one committed 006 case's accepted private PDF into an ordered, privately retained text source and sends that text, never the PDF, to NotebookLM through the selected `m4yk3ldev/notebooklm-mcp` adapter. Laravel owns case/Document/settings lineage, worker authorization, artifact/binding/transfer records and teacher access. Temporal owns durable activation, extraction and ingestion execution. Python performs local extraction/OCR and MCP calls. The original teacher remains owner; admin sees only 004's closed operational summary. One case gets one isolated notebook and one text source; a deliberate `process_again` case gets its own notebook even for the same Document. 009 owns NotebookLM-grounded Chapter/Mini Chapter/Lesson mapping and canonical prompts. 008 does not invent learning units, Sections, JEV decisions, scenes or review.

The selected community MCP currently exposes `notebook_create`, `notebook_get`, `notebook_list`, `notebook_add_text`, `source_get_content` and `notebook_query`. Its `notebook_add_text` returns a message, **not** a source ID; 008 must resolve the source from notebook inventory/content before confirming a binding. Installed tool schemas and live round-trip behavior are mandatory gates. The V2 `Interactive-Seens-Material/Generating` extractor is reference-only. KOS/KIE, Google Drive and multitenancy are outside the pilot.

## Stories and stable acceptance cases

### US1 — Activate one source run and read the owned PDF

- **K01** An eligible 007 case receives one durable 008 activation ID and one `source_activation_v1` signal despite concurrent scheduler/jobs or lost signal acknowledgement. The existing workflow ID, case ID and captured settings remain unchanged.
- **K02** A signed worker can stream only that case's same-owner ready Document through 006 `AcceptedPdfReader::openForCase`; unknown/foreign/stale activation, mismatched Document and missing/corrupt accepted bytes fail before extraction. A browser/admin/legacy route cannot obtain that stream.
- **K03** A case with a suspended original teacher after workflow start retains ownership and may finish already authorized automated source work; 004 denies their HTTP view immediately. Admin cannot activate another teacher's content manually in 008.

### US2 — Extract faithfully or block before transfer

- **K04** A text PDF yields exactly one page record per 006 page in ascending order and one canonical UTF-8 text artifact with explicit page markers. Known fixture headings, table cells and equation text survive in reading order; no summaries replace source text.
- **K05** A page with no usable text runs local OCR only while the captured `max_ocr_pages` budget remains. OCR language/tool unavailability, budget exhaustion or remaining unreadable page records a page gap and prevents NotebookLM transfer.
- **K06** An image, diagram, table or formula that the extractor cannot represent in text is recorded as a structural gap and prevents a claim of complete extraction. Low-confidence OCR and parser anomalies are treated as gaps; no silent page drop or text truncation is allowed.
- **K07** A duplicate extraction POST with the same digest returns the existing artifact; changed bytes/digest for the same case conflict. A crash between private file rename and DB insert leaves only a detectable orphan, never a ready row with missing bytes.
- **K08** The active original teacher can see an owner-scoped page-gap summary and safe blocked reason. A foreign/missing case has the same 404 response; admin's progress remains the 004 closed DTO.

### US3 — Bind an isolated NotebookLM source without false idempotency

- **K09** A successful `notebook_create` is bound to the case; a lost response triggers inventory reconciliation by deterministic title, not a blind second create. Zero/multiple ambiguous matches remain uncertain for operator resolution.
- **K10** `notebook_add_text` receives only the exact canonical extraction text through its inline `content` field and a deterministic opaque title. Its message response alone never confirms a source ID; the adapter obtains one source ID from `notebook_get` and verifies content through `source_get_content` before confirming the binding.
- **K11** A timeout after create/add, stale listing, duplicate title, content mismatch or session/tool schema change remains `uncertain` or safely blocked. The non-idempotent operation is not resent automatically. A confirmed matching source is adopted, with one binding and a recorded transfer digest/time.
- **K12** Transient read/query rate limits and outages retry under the captured 005 technical policy; create/add each have one automatic send at most. Exhaustion or an unusable MCP schema gives a safe operational failure without fabricated source identity.
- **K13** Two different teachers, and two cases for the same teacher/Document, never share a notebook/source binding. Client-provided notebook/source IDs cannot select another case's content.

### US4 — Downstream query and privacy boundary

- **K14** `KnowledgeSourceAdapter.query` uses the confirmed case-local binding and sends `notebook_query` with only its bound source ID. It returns answer/conversation IDs in memory for a later 009-owned private persistence activity; 008 neither maps learning units nor places answer/source text in Temporal workflow history.
- **K15** The worker path signs every Laravel request with 007's callback key; user routes use 004 active original-owner checks. Admin/browser/legacy routes and ordinary logs/Temporal memo/events contain no PDF bytes, extracted text, notebook/source IDs, query answer or private path.
- **K16** 008 records the exact digest/byte count and attempted transfer time, retained privately with case/settings/Document lineage. A confirmed transfer also records proof time; uncertain transfers are labeled uncertain rather than counted as confirmed, and no automatic deletion loses unresolved evidence.
- **K17** A real disposable PDF-to-MCP-to-NotebookLM run proves the installed tool schema, authenticated account, source discovery/content check and source-scoped query. Fake MCP contract tests alone do not prove live ingestion or grounding.
- **K18** A live 007 workflow parked at extraction resumes through a compatible 008 worker, reaches a confirmed source binding, publishes safe `extraction`/`sourcing` milestones and then waits for 009; it does not synthesize a map, Section or prompt.

## Functional requirements

- **FR-001** Activate each eligible 007 case once through a durable, authenticated and replay-compatible source handoff without changing case identity or ownership.
- **FR-002** Stream the 006 verified accepted PDF to the Python worker through a case/activation-scoped internal boundary and apply the captured 005 extraction limit.
- **FR-003** Produce an ordered, bounded, privately stored text/page/gap artifact; block on unreadable or unrepresented educational content before external transfer.
- **FR-004** Create one case-isolated NotebookLM notebook and add exactly the canonical text through the selected MCP tool, then prove one source binding from provider inventory/content.
- **FR-005** Persist start/create/add/reconciliation and transfer evidence so duplicate, concurrent, timed-out and partial operations never silently create or adopt the wrong source.
- **FR-006** Provide a typed `KnowledgeSourceAdapter` ingest/binding/query contract that exposes case-local refs to 009 while hiding NotebookLM IDs and raw answer from Temporal history and user/admin APIs.
- **FR-007** Enforce 004/006 ownership on teacher and worker surfaces, the 004 admin allowlist, safe errors/logging and private evidence retention.
- **FR-008** Distinguish fake-adapter tests from a live installed MCP, OCR and Temporal handoff proof; report external schema/capability gates honestly.

## Success criteria and proof limits

- **SC-001** K01–K08 and K18 pass with real 006 file storage, signed Laravel/Python transport, a real Temporal worker and fixture PDFs; concurrent tests use separate processes/connections.
- **SC-002** K09–K13 and K17 pass with the pinned selected MCP against a disposable NotebookLM account; forced lost responses leave one proven source or a visible uncertain stop. Tests record actual tool schemas and source-content round trip.
- **SC-003** K14–K16 pass through real 004 route/policy boundaries and adapter contract tests; 009 separately proves mapping, conversation persistence and all eleven Section fields.

## Entry and exclusions

004–007 are currently specs rather than application code in this checkout. The selected MCP CLI, Tesseract and Temporal CLI are not installed here; live gates require provisioning. No application code or runtime tests are changed in this documentation review. The source page gate is deliberately conservative: an unsupported visual/structural page blocks instead of claiming complete text. The live provider may normalize source content; if exact content verification is impossible with the selected installed MCP, mark the integration blocked and resolve the provider capability rather than weaken the binding rule silently.
