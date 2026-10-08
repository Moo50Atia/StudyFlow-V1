# 009 Specification Review Checklist

Documentation checks on 2026-10-02; unchecked items are future implementation/live or downstream gates.

- [x] Conversation 23, V3 map, all original 009 files, 004–008 prerequisite contracts, directly affected 010/011/015 contracts and relevant code/routes/migrations/tests/config inspected.
- [x] Initial read-only speckit-analyze semantic findings were recorded before remediation in [review report](../review-report.md); the 004 feature selector was not changed.
- [x] Five canonical prompt bodies were left byte-identical; fixed SHA-256 digests and runtime comparison are specified.
- [x] FR-001–FR-008 map to L01–L13, planned test files and ordered T001–T023 in [matrix](../contracts/acceptance-matrix.md).
- [x] 009 activation, map/coverage/First grammar, page evidence, eleven fields, private answer storage, query one-send ledger and cursor transitions are specified.
- [x] Signed worker routes, owner-only teacher routes, admin closed DTO, legacy bypass, safe errors/logging and pilot retention are concrete.
- [x] 009's First-only proof and test-only 010 receipt are distinguished from production Second/Third/JEV and full Continue integration in [downstream contract](../contracts/integration-obligations.md).
- [x] Tasks name paths/dependencies/FR/L/completion; meaningful tests precede implementation and execution plan/notes/quickstart match.
- [ ] 004–008 prerequisite application code exists here and affected suites pass.
- [ ] Selected live MCP/Temporal known-fixture map, page coverage, First fields and old-history replay pass.
- [ ] 010 reconciles its current Second/Third plan with a literal `Continue` combined reply and proves persisted Third outputs before signed completion; full multi-Lesson integration passes.
- [ ] Application implementation or runtime tests were performed during this documentation-only review (intentionally not done).
