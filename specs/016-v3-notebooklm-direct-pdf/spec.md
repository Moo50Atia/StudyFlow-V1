# Feature Specification: V3 Direct PDF NotebookLM Protocol

**Feature**: `016-v3-notebooklm-direct-pdf` | **Created**: 2026-10-07 | **Status**: Design complete; implementation and live verification pending

**Input**: Upload the accepted PDF and unchanged approved `steps.txt` to NotebookLM, run the fixed lesson protocol, and isolate provider/session changes from application behavior.

## Authority and scope

Conversation 23 and Specs 004–015 remain the business baseline. The latest owner correction replaces local extraction/OCR followed by text upload for **new direct-protocol cases**. Malware screening, PDF validity/page-count inspection, original teacher ownership, captured settings, Temporal durability, typed JEV, scene checks and teacher review remain required. PDF metadata inspection is not educational extraction.

Owner decisions on 2026-10-07: use the current root `steps.txt` unchanged; return First, Second and Third outputs together for each Lesson. The file's SHA-256 is `96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec`. It contains expanded perspective rules; do not substitute Spec 009's shorter framework. Original canonical First/Second/Third constants remain unchanged and remain authoritative for legacy cases. Divide and Execution remain unchanged for both paths. A separate response-format configuration implements the approved combined-output decision without editing prompt files.

Use one Python SDK provider for new-case upload and conversation. Reuse existing explicit credential capture through a format bridge. Do not combine two stateful chat implementations or fall back after an uncertain send.

Out of scope: unrelated repairs across all fifteen specs, replacement of JEV/scene authoring, arbitrary browser-profile discovery, browser login during pipeline execution, production autoapproval, V2/KOS/KIE/multitenancy, provider deletion, and conversion of in-flight cases.

## User Scenarios & Testing

### User Story 1 — Process the original PDF (Priority: P1)

An active teacher uploads a PDF through existing intake and starts a case. NotebookLM receives that exact file and the selected immutable `steps.txt`, without local educational extraction/OCR.

**Independent test**: A scanned diagram PDF that fails the legacy visual-to-text gate reaches a ready direct binding through an adapter fixture; invalid/infected PDFs still fail intake. Live evidence is separate.

1. Clean accepted PDF plus approved framework produces two confirmed ready sources before Divide (N01–N05).
2. Partial upload/restart reuses confirmed work and reconciles uncertainty without duplicate upload (N06–N08).
3. Missing/expired credentials produce an explicit recoverable condition without a browser window (N09).

### User Story 2 — Run every Lesson in the fixed protocol (Priority: P1)

NotebookLM maps the PDF, acknowledges Execution with `System Ready`, handles `Start with First lesson`, then advances only on literal `Continue`. A Mini Chapter without Lessons is a work item.

**Independent test**: A three-work fixture, including a promoted Mini Chapter and duplicate leaf titles, produces complete ordered bundles with eleven fields and one final prompt per Section. No separate First/Second/Third sends occur (N10–N15).

1. Divide selects PDF only and records immutable work order (N10).
2. Execution acknowledges exactly `System Ready` before first start (N11).
3. One complete reply commits Sections, preparation, prompts and completion together; only that commit permits the next work (N12–N14).
4. Malformed, truncated, wrong-Lesson or incomplete output produces no partial artifacts and no Continue (N15).

### User Story 3 — Resume safely and preserve existing cases (Priority: P1)

Explicit account refresh and read-only reconciliation recover provider work. Existing cases retain their captured legacy adapter/protocol after defaults change.

**Independent test**: Concurrent claims authorize one send; legacy Temporal histories replay; restart recovers a known answer without repeating Continue (N16–N20).

### User Story 4 — Continue into scenes and owner review (Priority: P2)

Original prompts enter existing typed JEV qualification, scene authoring/checks and original-teacher review. Owner history is complete; admins see operational metadata only.

**Independent test**: Real routes reject foreign/admin content reads; a connected workflow reaches qualification and teacher review without synthetic stage-completion shortcuts (N21–N24).

## Requirements

- **FR-001**: Capture adapter, protocol, framework and technical policy at case creation. Replay retains them; changing defaults never changes an existing case.
- **FR-002**: Upload verified original PDF bytes. Do not locally extract/OCR, summarize, split or truncate educational content in direct cases. Keep intake screening and captured limits.
- **FR-003**: Upload exact approved framework bytes with source title `steps.txt`; snapshot/hash before provider work. Missing, changed or unapproved framework prevents notebook creation.
- **FR-004**: One notebook belongs to one case with distinct `educational_pdf` and `framework_steps` sources, both provider-ready before conversation work.
- **FR-005**: Separate credentials/session, provider files, provider conversations, Laravel persistence and educational orchestration behind typed interfaces.
- **FR-006**: Runtime uses the explicitly configured account and never opens a browser, chooses another profile or changes accounts automatically. Explicit setup/refresh remains supported.
- **FR-007**: Send unchanged Divide against PDF only; persist hierarchy and contiguous depth-first work ordinals. Promote childless Mini Chapters; do not fabricate Lessons for Chapter-only maps.
- **FR-008**: Send unchanged Execution once against both sources; require `System Ready`; send exact `Start with First lesson` once; then exact `Continue`. No separate step or coverage questions.
- **FR-009**: Preserve all eleven First fields, Section order, Second preparation and one Third prompt per Section from each combined reply.
- **FR-010**: Commit lesson artifacts and receipt atomically. Identical duplicate results return existing refs; changed results and stale versions cannot overwrite evidence.
- **FR-011**: One outbound authorization per immutable source/turn effect. Retry known IDs/history; uncertain acceptance never causes automatic resend/provider fallback.
- **FR-012**: Persist explicit pending, processing, ready, uncertain and blocked evidence with concrete recovery for session expiry, slow processing and lost acknowledgements.
- **FR-013**: Keep educational content, cookies and provider IDs outside Temporal payloads, ordinary logs and admin responses. Only the active original owner receives private evidence.
- **FR-014**: Preserve legacy APIs and histories; reject old source/step operations when they would bypass direct-protocol fences.
- **FR-015**: Reuse typed JEV and immutable prompt lineage. A demo waiver is labelled, never evidence of live JEV, browser safety or teacher approval.
- **FR-016**: Extend owner history/export with PDF/framework/turn lineage under existing pilot retention. Never claim a PDF text-equivalence digest or verified page coverage from a structural map.
- **FR-017**: Process all mapped work. Case completion remains governed by existing scene/review terminal policy, not the first Lesson or a provider acknowledgement.
- **FR-018**: Prove behavior with meaningful tests, actual concurrent claims, workflow replay and a separately labelled live provider gate.

## Key Entities

Captured case protocol; approved framework; binding and two-source manifest; source-effect ledger; conversation query; immutable map/work order; combined lesson bundle; existing Sections, prompt versions and completions. See [data model](data-model.md) and [protocol contract](contracts/notebooklm-protocol.md).

## Success Criteria

- **SC-001**: One accepted PDF and exact framework become two ready sources with zero local educational transformation.
- **SC-002**: N work items produce one Divide, one Execution, one first start and N−1 Continue sends in order, without skipped/duplicate work.
- **SC-003**: Every accepted Section retains eleven fields, preparation and one original prompt; invalid replies produce zero partial artifacts.
- **SC-004**: Lost acknowledgements/restarts do not increase outbound counts; concurrent/stale requests cannot change accepted lineage.
- **SC-005**: Existing cases replay unchanged; unauthorized reads expose zero content; owner evidence continues into scene/review lineage.

## Assumptions and dependencies

004–015 are prerequisites, not proof their runtime currently works. The selected provider is an unofficial integration; live upload/conversation verification is mandatory before rollout. No processing-time SLA is invented for remote PDF ingestion. A response limited to final prompts is invalid for this feature even if provider transport succeeded. Any connected baseline defect blocking these gates must be recorded and fixed through an explicitly bounded dependency repair, never hidden by a placeholder.
