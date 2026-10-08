# 008 Specification Review Checklist

Documentation checks on 2026-10-02; unchecked items are implementation/live gates outside this review.

- [x] Conversation 23, V3 map, all original 008 files, 004–007 prerequisites, directly affected 009/010/015 contracts and relevant code/config/V2 reference extractor inspected.
- [x] Initial read-only speckit-analyze semantic findings recorded before remediation in [review report](../review-report.md); feature selector was not changed.
- [x] FR-001–FR-008 map to K01–K18 and T001–T024; each K case has planned executable evidence in [matrix](../contracts/acceptance-matrix.md).
- [x] Laravel private PDF/text transport, exact worker HMAC boundary, activation signal, metadata and error responses are specified.
- [x] Extraction format/gate, OCR cap, private artifact atomicity, page gaps and no-truncation behavior are concrete.
- [x] Selected MCP's real add-message/no-source-ID behavior, one-send ledger, uncertain reconciliation and exact content proof are explicit.
- [x] Teacher original-owner route, admin closed DTO, legacy bypass, redacted logs, transfer evidence and pilot retention are concrete.
- [x] 008 bound-source/query contract is separated from 009 NotebookLM learning-unit mapping, canonical prompts and eleven Section fields.
- [x] Tasks have paths, dependencies, FR/K IDs and completion; meaningful tests precede governed implementation; execution plan/notes/quickstart match tasks.
- [ ] 004–007 prerequisite application contracts exist and affected tests pass in this workspace.
- [ ] Poppler/Tesseract, selected pinned MCP/account and live Laravel/Temporal bridge are provisioned; real OCR and old-007/new-008 replay pass.
- [ ] Live `notebook_add_text` inventory/content round trip proves exact source identity and source-scoped query; fake MCP tests alone are insufficient.
- [ ] Application implementation or runtime tests were performed during this documentation-only review (intentionally not done).
