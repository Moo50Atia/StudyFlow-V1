# 011 Acceptance Matrix

All test paths below are **planned implementation tests**. A case fixture uses actual 004–010 rows/services where the boundary is under test; provider fixtures never count as live API proof. `0 sends` means the fake adapter call ledger is empty. All responses use [scene-authoring.md](scene-authoring.md).

| ID | Requirements | Planned test file | Scenario and expected outcome |
|---|---|---|---|
| A01 | FR-001, FR-002 | `tests/Feature/V3/Authoring/HandoffTest.php` | Signed eligible-prompts lists only current same-case passes in 009 ordinal order; each claims one request with captured settings/template; immutable refs/digests match and replay returns the same request. |
| A02 | FR-001, FR-008 | `tests/Feature/V3/Authoring/HandoffTest.php` | Missing, failed, foreign-case or mismatched Section/qualification ref returns indistinguishable 404; 0 sends/rows. |
| A03 | FR-001, FR-007 | `tests/Feature/V3/Authoring/HandoffTest.php` | Superseded prompt at claim, context read, send preflight or result commit is rejected/staled; no new candidate; replay cannot revive it. |
| A04 | FR-002, FR-003 | `tests/Feature/V3/Authoring/TemplateTest.php`, `v3_engine/tests/authoring/test_provider_wire.py` | Missing/corrupt captured template or credential blocks before send; exact approved bytes and only qualified prompt enter model payload, without source/owner data. |
| A05 | FR-003, FR-005 | `v3_engine/tests/authoring/test_provider_wire.py`, `v3_engine/tests/authoring/test_package.py` | Gemini/Z.AI/DeepSeek official-shape fixtures yield one complete package; filtered, multiple, truncated, empty or tool-call output yields known invalid result. |
| A06 | FR-005, FR-006 | `tests/Feature/V3/Authoring/CandidateStoreTest.php` | Valid package commits exact file and manifest hashes on `v3_private`; 012 internal resolver rehashes and returns current untrusted candidate. |
| A07 | FR-005, FR-006 | `tests/Feature/V3/Authoring/CandidateStoreTest.php` | Missing index, invalid UTF-8, duplicate/case-folded or escaping paths, symlink claim, disallowed extension and size limit create no candidate; attempt records validation. |
| A08 | FR-005, FR-008 | `tests/Feature/V3/Authoring/AccessTest.php` | Generated JS is never run by 011; public disk/storage link and V2 Section/DynamicView routes cannot retrieve V3 candidate bytes. |
| A09 | FR-006, FR-007 | `tests/Feature/V3/Authoring/CandidateStoreTest.php` | Same result replay returns same candidate; changed digest conflicts; file-write/DB fault leaves no referenced partial candidate and orphan is safely reconciled. |
| A10 | FR-003, FR-004 | `v3_engine/tests/authoring/test_fallback.py`, `tests/Feature/V3/Authoring/AttemptPolicyTest.php` | Missing credential consumes one reserved slot with no HTTP call; known 401/403/429 or structurally invalid output also advances once to next captured distinct API provider, preserving prompt/template digests. |
| A11 | FR-004, FR-009 | `tests/Feature/V3/Authoring/AttemptPolicyTest.php` | One-provider order, attempt cap and all-known-failure exhaustion create no extra send; Section blocked; case only fails if no other work can continue. |
| A12 | FR-004, FR-007 | `v3_engine/tests/authoring/test_fallback.py`, `tests/Feature/V3/Authoring/AttemptPolicyTest.php` | Post-reservation timeout/5xx/crash becomes uncertain; no provider resend or fallback; readback/014 handoff recorded. |
| A13 | FR-004, FR-006 | `tests/Feature/V3/Authoring/AttemptPolicyTest.php` | Real two-process DB race for one request claims exactly one ordinal/provider; loser returns same reservation or conflict, never another send. SQLite in-memory test alone is insufficient. |
| A14 | FR-006, FR-007 | `tests/Feature/V3/Authoring/CandidateStoreTest.php` | Lost completion acknowledgement readback returns committed candidate; same bytes may replay; missing acknowledgement without durable result stays uncertain. |
| A15 | FR-008 | `tests/Feature/V3/Authoring/AccessTest.php` | Unsigned, bad signature, expired and foreign-case internal requests fail before lookup; no payload/path leakage. |
| A16 | FR-008 | `tests/Feature/V3/Authoring/AccessTest.php` | Active original teacher sees safe status; other teacher, inactive owner, student, admin and guest cannot; admin case summary shows only allowed closed scalars and counts. |
| A17 | FR-008, FR-009 | `tests/Feature/V3/Authoring/MilestoneTest.php` | Forged `scene_generation` completion without persisted candidate is rejected; valid milestone is idempotent, never labels an unqualified scene review-ready. |
