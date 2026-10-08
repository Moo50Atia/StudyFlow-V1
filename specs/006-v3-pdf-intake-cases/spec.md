# Feature Specification: V3 PDF Intake and Processing Cases

**Reviewed**: 2026-10-01 · **Status**: documentation design · **Prerequisites**: implemented 004 access and 005 versioned settings

## Purpose and scope

An active original teacher submits a PDF as an upload attempt. Only a completely received, structurally supported, malware-clean file becomes a private accepted Document. The same teacher can see an exact-byte duplicate and open a previous case or deliberately start another. A ProcessingCase is a separate run with a newly captured 005 settings version. 006 owns these records, teacher-facing intake/case receipt routes and pre-case validation recovery. 007 owns Temporal start, progress and later workflow states; 008 owns local text/OCR extraction and NotebookLM `notebook_add_text` ingestion. No NotebookLM map, JEV result, scene or teacher review is created here.

Teacher and admin remain the pilot roles. The original teacher is the immutable content owner and later final-scene reviewer. Admin receives no PDF, filename, hash, path or teacher content through 006. V2 materials remain reference-only; KOS/KIE and multitenancy are outside this feature. Canonical 009 prompts and all eleven Section fields are untouched.

## User stories and acceptance cases

### US1 — Safely accept a PDF

An active teacher can submit a PDF from the V3 workspace and inspect the resulting attempt through a protected detail page without exposing unvalidated bytes. The HTTP and validation rules are in [intake contract](contracts/intake.md).

- **I01** A complete `.pdf` upload within the upload-time captured 005 byte/page limits enters private quarantine; after successful structure and malware checks it produces one ready private Document and an accepted attempt. No case starts on upload.
- **I02** Empty/interrupted transfer, forbidden extension, renamed executable, malformed or warning-recovered PDF, encrypted PDF, zero/excess pages, active content and embedded-file fixtures are rejected with fixed safe reasons, no Document/Case and no retained rejected bytes.
- **I03** A malware-positive result is rejected and purged. Missing qpdf/clamdscan, timeout or scanner error never yields acceptance; the attempt stays isolated for bounded retry, then blocks and purges.
- **I04** Scanned/image-only PDFs may pass file acceptance; unreadable educational content is an 008 decision, not a false 006 rejection.
- **I05** Every authenticated submission reaching the controller gets an opaque attempt ID, including an application-level rejection. Upstream web-server/PHP body rejection is recorded as a deployment limit and cannot promise an application attempt row.
- **I06** A crash between quarantine, inspection, private promotion and database commit is reconciled from durable attempt/Document state. No pending file is downloaded or used for a case; after recovery there is either one verified ready Document or a blocked attempt with bytes removed.

### US2 — Show only own exact duplicates

Exact-byte SHA-256 is internal identity evidence, never an external document ID.

- **I07** A second upload by the same owner with identical bytes yields a second attempt linked to the one ready Document and, if present, that owner's latest case ID; its detail page offers open-previous or process-again. A one-byte difference yields a new Document.
- **I08** Another teacher uploading identical bytes receives a separate Document and learns nothing about the first teacher. Own list/counts and missing/foreign resource responses reveal no cross-owner data.
- **I09** Two concurrent identical same-owner uploads converge on one ready Document under `(owner_user_id, sha256)` while retaining two attempts; neither overwrites the accepted file. Two concurrent cross-owner uploads remain separate.
- **I10** Reuse of an already accepted Document does not bypass the current case-start byte/page limits; an old Document over a newly captured limit cannot start a new case. Missing or hash-mismatched accepted bytes also block a new case.

### US3 — Start or reopen a case

The teacher chooses `process` for a Document with no earlier case, opens an existing case by GET, or explicitly chooses `process_again` for another run.

- **I11** A first `process` for an owned ready Document creates one case with a new opaque ID, original owner, immutable Document ID and active-at-start settings ID. State is `awaiting_workflow`; 006 does not claim it is queued in Temporal.
- **I12** Same owner and idempotency key with the same Document/action returns the original case and its original settings, even after a settings change; reuse of that key for a different Document/action returns 409 without mutation.
- **I13** Two actual concurrent same-key starts create one case. Different keys with `process_again` create distinct deliberate cases. A concurrent first `process` with a different key cannot silently create a second run.
- **I14** Foreign/missing Document or Case, admin/student/inactive teacher, client-supplied owner/settings/state fields and unaccepted/pending Document cannot create or disclose a case. A previous own case can be opened by the 006 receipt page; 007 later adds progress.
- **I15** If no 005 active version exists or it is corrupt, case creation fails safely with no case. A publication racing case start resolves before or after the 005 selector transaction; the committed case references exactly one existing immutable version.
- **I16** Once case creation commits, it remains `awaiting_workflow` for 007 to discover; 007 owns a stable exactly-once workflow identity. An uncertain future Temporal handoff never causes 006 to create a replacement case or change owner/settings.

### US4 — Preserve private access and recoverability

- **I17** Owning active teacher can list own attempts, read an own case receipt and stream an own ready PDF through a controller. Other teachers/admins cannot stream bytes; no public/signed storage URL, legacy route or client path bypass works.
- **I18** Rejection, retry, cleanup and case creation retain only allowlisted safe state/reason/timestamps/IDs. Quarantined bytes expire after 24 hours and are purged when storage is available; failed deletion stays inaccessible in cleanup with an overdue alert; accepted bytes and minimal records remain for the pilot until a later retention policy. Logs/admin projections contain no PDF bytes, filename, hash, private path, parser output or malware signature.

## Functional requirements

- **FR-001** Use the upload-time captured 005 size/page limits and exact supported PDF checks before ready acceptance; fail closed on tool or scan uncertainty.
- **FR-002** Stage privately, retain an attempt for each controller-level authenticated submission, and purge rejected/expired bytes while reconciling interrupted work.
- **FR-003** Compute SHA-256 over exact staged bytes and assign separate opaque attempt, Document and Case UUIDs; keep one ready Document per owner/hash.
- **FR-004** Restrict duplicate discovery and previous-case choices to the original teacher, including concurrent uploads and list/count routes.
- **FR-005** Create a case only from an owned ready Document under current case-start limits; capture the 005 active version atomically and preserve owner/Document/version.
- **FR-006** Enforce owner-scoped idempotency key and explicit `process`/`process_again` semantics across retries and real concurrency.
- **FR-007** Apply 004 active-teacher and owned-resource policy to every V3 intake/read/download/case route; close public and legacy bypasses with private/no-store responses.
- **FR-008** Define bounded validation retry, promotion reconciliation, cleanup and safe failure behavior without relying on 007 Temporal before a case exists.
- **FR-009** Provide a durable `awaiting_workflow` case handoff for 007; 006 never starts Temporal or reports a queued workflow.
- **FR-010** Keep safe attempt/case evidence and accepted-file retention while withholding source bytes and sensitive metadata from admin, responses and logs.

## Success criteria and proof limit

- **SC-001** I01–I06 pass with real private-disk and parser/scanner adapter behavior; fake tool results prove decision logic only, not live malware coverage.
- **SC-002** I07–I10 and I12–I13 pass through actual owner-scoped HTTP/DB paths, including separate-process contention tests.
- **SC-003** I11/I14–I17 pass with 004/005 real services and real 006 routes; 007 must separately prove Temporal handoff and progress.
- **SC-004** I18 passes with storage reconciliation and privacy assertions; documentation checks do not establish runtime compliance.

## Dependencies and exclusions

004 supplies V3 access/policies/private response; 005 supplies immutable settings and `SelectCaseSettings`. Both are currently documented but absent from inspected application code and must exist before 006 implementation. A qpdf JSON-v2 capable CLI and running ClamAV daemon/client are required for live acceptance; their absence fails closed. 006 does not install deployment services, implement 007 workflow, 008 OCR/NotebookLM, or alter legacy Lecture/Section content. A trusted scheduler and intake worker must run for asynchronous validation and recovery.
