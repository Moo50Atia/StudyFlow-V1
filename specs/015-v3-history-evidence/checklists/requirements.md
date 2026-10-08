# Specification Review Checklist: 015

Documentation checks below concern the reviewed contract, not implemented behavior. See [review-report.md](../review-report.md) for evidence and limitations.

- [x] Every FR-001–FR-008 maps to H01–H20 and a dependency-ordered task/test file.
- [x] Existing 006–014 identities, current approval semantics, failed branches and 014 operational actions have explicit traversal/gap rules; no duplicate lineage ledger is proposed.
- [x] Owner/admin HTTP routes, exact request/response shapes, denial precedence, stale replay, expiry and no-store are specified.
- [x] Case elapsed, stage residence, observed operation duration, review wait, counts, currency and missing monetary data have distinct definitions.
- [x] Export creation, download, file integrity, audit, concurrency and cleanup have testable states and recovery rules.
- [x] `pilot-retain-v1` forbids educational artifact purge; 014 assessment is not treated as deletion authority.
- [x] Tests precede each 015 behavior they govern, and H19 requires real overlapping operations on a shared DB.
- [x] Quickstart marks commands as future instructions; runtime and live-service behavior is not certified by this review.
- [ ] Owner has approved a physical educational-artifact retention/deletion policy. **Open; no physical deletion is in 015 pilot scope.**
- [ ] Owner has approved an estimate tariff/billing source for missing provider charges. **Open; pilot reports unknown full cost.**
- [ ] 004–014 V3 application implementations and live gates exist. **Not true in the current V2 repository; implementation entry condition.**
