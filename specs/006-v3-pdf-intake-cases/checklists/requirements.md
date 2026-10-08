# 006 Specification Review Checklist

Checked against the documentation and inspected repository on 2026-10-01. These are documentation checks, not runtime certification.

- [x] Business authority, 004/005 contracts, 007/008 and affected downstream contracts, all original 006 artifacts and actual relevant Laravel paths inspected.
- [x] Initial read-only semantic review recorded before remediation in [review report](../review-report.md); Spec Kit selector was not changed.
- [x] FR-001–FR-010 each map to stable I01–I18 acceptance scenarios and planned executable tests in [matrix](../contracts/acceptance-matrix.md).
- [x] PDF gates define supported profile, tool outcomes, captured limits, private staging and safe fail-closed reasons.
- [x] Attempt/Document/Case IDs, fields, FKs, uniqueness, state transitions, file/DB recovery and 24-hour quarantine retention are specified.
- [x] Every new route and legacy/public bypass has an ownership/privacy expectation; 004 current actor checks and 005 transactional capture are explicit.
- [x] Duplicate/idempotency and settings publication races have real concurrency test requirements.
- [x] Tasks name deliverables, dependencies, FR/I IDs and completion conditions; behavior tests precede governed implementation.
- [x] Execution plan, notes, quickstart and downstream obligations distinguish 006 evidence from future integrations.
- [ ] Runtime 004/005 prerequisite implementation exists and passes in this workspace (not part of this documentation review).
- [ ] qpdf/ClamAV, worker/scheduler and live fixture gates pass in implementation environment (not part of this documentation review).
- [ ] Application code and executable tests implemented/run (expressly outside this documentation review).
