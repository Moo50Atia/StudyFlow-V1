# Downstream Integration Obligations from 005

005 completion proves a real admin settings boundary, publication transaction, immutable version reads and a transaction-bound selection service. Its fixture consumer does **not** prove a real case, workflow, provider, JEV or teacher review integration.

| Consumer | Required use of 005 | Proof owned by consumer |
|---|---|---|
| 006 PDF intake/cases | Call `SelectCaseSettings` on the same DB connection/transaction that inserts `processing_cases`; persist non-null restrictive `settings_version_id`; duplicate idempotency key returns existing case/version; fail no-active selection without case or document/case mismatch. Use captured PDF byte/page limits. | Real upload/start-case routes, FK and concurrency tests, including publication racing case creation. |
| 007 Temporal/progress | Start with case's stored version ID; load that version for retries, never current pointer. Apply captured technical retry count/delay; no version mutation on resume. | Crash/duplicate/milestone tests on a real case; worker auth separate from admin session. |
| 008 extraction | Use captured `max_ocr_pages`; preserve local extraction → `notebook_add_text`; report unusable content rather than exceeding cap. | Text/OCR and adapter tests on real accepted document. |
| 009 mapping/sections | Preserve canonical dividing/execution/First prompt text and all eleven Section fields; do not allow settings to override them. | Canonical digest and teacher-only real Section tests. |
| 010 prompt/JEV | Use captured provider/model/credential label, captured feedback template ID/digest/bytes, prompt-revision budget and prompt score/confidence thresholds. Map successful typed Choice/Score/Noul to the fixed policy directly; transport failure remains separate. Keep 009 canonical prompts unchanged. | Live-schema contract evidence, typed fixture policy tests and selected-template lineage; no second suitability model. |
| 011 authoring | Use captured `coding_order`, `coding_attempts` and scene template ID/digest/bytes, with attempt identity and bounded fallback. Missing provider/credential/template is a safe runtime failure, not a reason to substitute active settings. | Real provider adapter, selected instruction digest and artifact lineage tests. |
| 012 scene qualification | Use captured scene score/confidence and repair budget; require deterministic check plus JEV pass; no second JEV judge. | Real sandbox/check and typed-decision tests. |
| 013 teacher review | Use captured review dormancy rule and preserve case version through review/revision; original teacher remains sole reviewer. | Real owner authorization and review tests. |
| 014 admin operations | Link to 005 settings show/history/publish, do not add parallel settings mutation. Admin case views remain 004 closed metadata only. | Route inventory and operational projection tests. |
| 015 evidence | Attribute case/attempt/decision to captured version; respect retained settings publication history and its value-free changed paths. | Real owner/admin export privacy and lineage tests. |

No downstream worker is authorized by an admin web session, and no admin route may serialize teacher source, prompt, scene or review note bodies. Any future payload schema extension requires a new `schema_version`, validation and explicit consumer handling; old cases retain their old version.
