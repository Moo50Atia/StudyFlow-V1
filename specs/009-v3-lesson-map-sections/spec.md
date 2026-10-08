# Feature Specification: V3 Lesson Map and Teacher Sections

**Reviewed:** 2026-10-02 · **Status:** documentation design · **Prerequisites:** implemented 004–008, including one `bound` 008 source and a replayable 007/008 workflow.

## Purpose and boundary

NotebookLM, through the selected 008 MCP adapter, maps the bound source into ordered Chapters, optional Mini Chapters and Lessons. 009 preserves the source-grounded map, produces the First-step Sections for one work item at a time and shows all eleven fields to the original teacher. Laravel owns case/map/Section identity, private evidence and ownership; Temporal owns the durable mapping/lesson wait. Admin receives only 004's closed operational summary. A Mini Chapter with no Lesson is one work item. An empty Chapter is a mapping gap, not an invented Lesson.

The five bodies in [canonical-prompts](contracts/canonical-prompts.md) are immutable. 009 sends the exact Divide body and exact First body inside a versioned transport envelope. It stores all five digests for downstream use. The canonical Execution body describes a complete three-step cycle, so 010 owns sending that body and the Second/Third bodies and persisting their outputs. 009 supplies a gated `Continue` operation after 010 has durably completed the previous Lesson's Third outputs. In a 009-only deployment that gate stays closed; test-only 010 completion fixtures verify its behavior. No production 010 prompt/qualification model is created here. The original teacher formally reviews only the final scene in 013.

## Stable acceptance cases

- **L01** A `bound` case resumes the existing 008 workflow through one authenticated `mapping_activation_v1` signal. Concurrent discovery and lost acknowledgement reuse its activation ID; stale/foreign binding cannot activate.
- **L02** The exact Divide prompt is sent with only the case's bound source ID. A structured and a heading-poor fixture produce NotebookLM maps in source order; no local source heading inference or extra model supplies units.
- **L03** Duplicate labels remain distinct by parent/ordinal. A Mini Chapter without Lessons becomes one work item; a Chapter without a processable child, orphan, invalid nesting or empty map blocks acceptance.
- **L04** A separate fixed NotebookLM coverage query maps every 008 page marker to a work item. Unknown/missing pages or work items with no source page block map acceptance; this is a conservative coverage check, not proof of semantic completeness.
- **L05** A ready map is immutable, case/binding-scoped and has exactly one ordered work-item sequence. Duplicate equivalent callbacks return the same map; changed answer/digest or concurrent acceptance conflicts without cursor movement.
- **L06** The first work item receives the exact First prompt with a scope envelope naming that map path. Its answer creates ordered Sections with the eleven canonical field labels and values, preserving the original response privately.
- **L07** Missing, duplicate, reordered or conflated fields, wrong work-item identity, empty required content or missing Section blocks First acceptance. Only the optional laws value may be explicitly empty; its label and presence are retained.
- **L08** The original active teacher can list the map and open a Section with all eleven fields. Missing/foreign case and child IDs have the same 404; inactive teachers and admins cannot read map, raw response or Section content through new or legacy routes.
- **L09** One `Continue` may be claimed only after 010's completion proof covers all current Section IDs and Third outputs. It sends the literal `Continue` once through a source-scoped query; duplicate request returns its recorded outcome and never advances twice.
- **L10** A lost query response, missing conversation ID, provider outage or mismatched next work item leaves a visible `uncertain`/blocked cursor at the old ordinal. No automatic second `Continue` or guessed conversation/ordinal is allowed.
- **L11** A successful next response stores its raw answer and First Sections atomically with cursor movement. A final work item completes without sending an extra `Continue`; worker restart preserves map, Sections and cursor.
- **L12** Source text, prompts, answers, conversation/provider IDs and Section bodies stay in private Laravel storage; Temporal history, admin DTO, logs and errors contain only opaque refs/safe codes. Retained attempts distinguish sent, uncertain and accepted work.
- **L13** A live disposable NotebookLM/Temporal run proves installed query schema, a source-scoped map, coverage answer, First Sections, and 007/008-history replay into the 009 worker. Fake query responses alone do not prove grounding.

## Functional requirements

- **FR-001** Resume only a current bound 008 case with an authenticated, replay-compatible and idempotent 009 activation.
- **FR-002** Ask NotebookLM for the hierarchy using the exact Divide body, parse only a conservative structural format and preserve Chapter/Mini Chapter/Lesson order and duplicate titles.
- **FR-003** Require source-page/work-item coverage evidence from a separate NotebookLM query and block malformed, incomplete or stale map answers without fabricating units.
- **FR-004** Produce one work item's First-step output with all eleven canonical fields, preserving raw answer and immutable case/map/source lineage.
- **FR-005** Maintain a single conditional lesson cursor and one-send stateful-query ledger; 010's Third-output completion is the gate for literal `Continue`.
- **FR-006** Provide owner-only map/Section views and worker-only persistence under 004/007/008 boundaries, with safe errors, logs and retention.
- **FR-007** Expose a precise 010 handoff for Section refs, conversation, canonical prompt digests and completion proof without implementing 010's prompt/JEV models.
- **FR-008** Prove isolated behavior and live selected-MCP/Temporal behavior separately; report an installed provider contract mismatch as a mandatory gate.

## Success criteria and limits

- **SC-001** L01–L07 and L09–L11 pass with real 004–008 storage, signed worker endpoints and persisted Temporal history; races use separate processes/connections. The 010 completion caller is a test fixture until 010 exists.
- **SC-002** L08 and L12 pass through real V3 ownership middleware and legacy/admin route inventory, including denial and error responses.
- **SC-003** L02–L04, L06 and L13 pass against the installed selected MCP and a disposable source whose expected map, page coverage and Section facts are known. Live checks cannot guarantee every semantic point in arbitrary PDFs.

No local KOS/KIE, V2 Section conversion, multitenancy, scene authoring, JEV or teacher prompt-review action is part of 009. The selected MCP, Temporal and 004–008 implementations are absent from this checkout today; runtime proof is an entry gate for the future implementer, not a result of this documentation review.
