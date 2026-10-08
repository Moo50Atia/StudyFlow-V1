# 011 Specification Review Checklist

Documentation checks below reflect this review. They do **not** certify application implementation.

- [x] Current-pass/Section/captured settings and approved scene template handoff defined (FR-001–003; A01–A04).
- [x] Exact API provider order, known fallback, reservation, uncertainty and caps defined (FR-003–004,007; A10–A14).
- [x] Candidate package, manifest, private store, duplicate/partial failure and 012 boundary defined (FR-005–007; A05–A09).
- [x] Signed route, owner/admin/V2 bypass and proof-backed event coverage defined (FR-008–009; A15–A17).
- [x] Every FR maps to a scenario/test and ordered test-before-code task; execution plan and notes match tasks.
- [ ] 004–010 prerequisites implemented and verified in the actual repository.
- [ ] 011 implementation/tests, two-process race, live selected API and approved compatible template verified.
- [ ] 012 browser/JEV scene qualification and 013 teacher review implemented (downstream, not 011 completion).
