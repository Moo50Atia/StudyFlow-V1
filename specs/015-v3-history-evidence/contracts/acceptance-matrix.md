# 015 Acceptance Matrix

IDs are stable. Paths are planned future tests; this documentation review did not run them. Every test must assert response bytes and persisted source/export state, not merely a helper return value. `LineageTest`, `MetricsTest`, `ExportTest` and `ExportRaceTest` live under `tests/Feature/V3/Evidence/`.

| ID | Requirements | Planned test | Observable outcome |
|---|---|---|---|
| H01 | FR-001, FR-003 | `LineageTest.php` | Active original teacher traces a current 013 approval through exact review item, passing 012 check/JEV, 011 candidate/attempt, its recorded passing 010 prompt/JEV, 009 Section/map, 008 extraction/binding, 006 Document and captured 005 settings; `integrity=complete`. |
| H02 | FR-001, FR-002 | `LineageTest.php` | A later 013 correction clears the current pointer; old approval remains traceable and marked `current=false`, with successor and failed branch IDs retained. |
| H03 | FR-002 | `LineageTest.php` | Failed/uncertain 008–013 attempts and 014 hold/retry/cancel remain in case timeline with fixed codes and do not become ancestors of an approval. |
| H04 | FR-001, FR-002 | `LineageTest.php` | Same-case-looking but mismatched candidate/qualification/Section parent and a missing required row yield typed gap and `incomplete`; never certify by timestamp/latest row. |
| H05 | FR-002, FR-008 | `LineageTest.php` | Missing or digest-mismatched referenced private file yields `missing_or_corrupt`, fixed gap and no replacement content/ref to a private path; other branches remain visible. |
| H06 | FR-003 | `LineageTest.php` | Guest/inactive/admin/other teacher cannot open owner history or approval trace; foreign and missing child IDs share 404 body; no-store on denial. |
| H07 | FR-003 | `LineageTest.php` | Existing 006 download, 009 Section, 013 review and V2 routes do not expose another teacher's V3 history/export; owner-scoped list is filtered before pagination. |
| H08 | FR-004 | `MetricsTest.php` | Frozen UTC case/event times produce exact case elapsed and 007 stage residence; ongoing case uses `as_of`, terminal only uses recorded terminal proof. |
| H09 | FR-004 | `MetricsTest.php` | 011/012 measured duration is separate from wall elapsed; 013 `ready_at→decided_at` includes dormancy and is labeled wait, active human labor stays unknown; inverted/missing times produce gap/null. |
| H10 | FR-004 | `MetricsTest.php` | Technical attempts, prompt successors, repairs, teacher changes, historical approvals and current pointers count once each despite exact callbacks; cancelled current count is zero while old approval remains. |
| H11 | FR-005 | `MetricsTest.php` | Explicit provider-reported zero remains actual zero; omitted NotebookLM/JEV/agent/infrastructure/human charge gives null full cost, known subtotal and unknown-component count, never numeric zero total. |
| H12 | FR-005 | `MetricsTest.php` | Same-currency fully known case total divides by current approval count; zero denominator, mixed currency or one unknown charge yields null ratio with fixed reason; `process_again` is a separate case. |
| H13 | FR-004, FR-005 | `MetricsTest.php` | `[from,to)` membership, empty period and boundary cases give deterministic aggregate counts; provider response/digest mismatch yields unknown usage; no implicit rate estimate. |
| H14 | FR-006 | `ExportTest.php` | Owner case and approval exports contain exact allowlisted refs/decisions and verified content states but no embedded PDF/prompt/scene/note/provider answer bytes or storage path. |
| H15 | FR-006 | `MetricsTest.php` (route), `ExportTest.php` (file) | Admin metrics route and generated export bytes contain only closed operational fields; fixtures with unique teacher name/email, title, Section, prompt, note, filename, source hash/path and provider text produce no matching secret in response/file/log. |
| H16 | FR-006, FR-007 | `ExportTest.php` | Guest, inactive, other teacher, admin on owner export, teacher on admin export, wrong admin actor and role changed after creation cannot download; missing/foreign export 404 matches; no-store on all. |
| H17 | FR-007 | `ExportTest.php` | Exact actor/key/body replay returns same export and one generation audit; changed body 409; snapshot remains original after underlying case changes and a new key reflects new state. |
| H18 | FR-007, FR-008 | `ExportTest.php` | 15-minute expiry returns 410 after fresh auth; a corrupted/missing export file returns 503 without partial bytes; failed audit/DB write creates no downloadable row; hourly cleanup removes only old export files, never referenced 006–014 files. |
| H19 | FR-007 | `ExportRaceTest.php` | Two independent DB connections/processes concurrently POST same actor/key: exactly one committed snapshot and generation audit; losing request returns identical export or fixed 409 for changed body. SQLite memory test is insufficient. |
| H20 | FR-002, FR-006, FR-008 | `ExportTest.php` | 014 `deferred_to_015` assessment never purges bytes; missing referenced artifact is marked incident rather than deleted; admin export sees count/code only and owner sees typed gap. |
