# Acceptance Matrix: 005

Test paths are planned implementation files, not tests run in this documentation review. `005 fixture` means a test-only consumer proves the service contract; real case evidence belongs to 006.

| ID | Requirements | Planned evidence | Expected outcome |
|---|---|---|---|
| S01 | FR-002, FR-003 | `tests/Feature/V3/Settings/PublicationTest.php` | First publish activates version 1 and one audit event. |
| S02 | FR-003 | `PublicationTest.php` | Replacement increments version; old bytes/digest/actor/time unchanged. |
| S03 | FR-002, FR-007, FR-008 | `SettingsValidationTest.php` | Complete schema rejects invalid/extra/secret/canonical fields; zero writes. |
| S04 | FR-001, FR-006 | `SettingsBoundaryTest.php`, `SettingsRouteInventoryTest.php` | Real routes enforce 004 role/enrollment; legacy routes give no settings access. |
| S05 | FR-003 | `PublicationTest.php`, `tests/Integration/V3/Settings/PublicationConcurrencyTest.php` | Stale 409; simultaneous same-version requests have one winner. |
| S06 | FR-003, FR-007 | `PublicationTest.php` | Injected audit/storage failure rolls back all rows/pointer. |
| S07 | FR-003 | `SettingsSchemaTest.php`, `PublicationTest.php` | Historical row immutable in app and no edit/delete route. |
| S08 | FR-004 | `CaptureContractTest.php` | No active setting throws typed failure; fixture inserts nothing. |
| S09 | FR-004 | `CaptureContractTest.php`, `SelectionConcurrencyTest.php` | Same-transaction select returns committed row; concurrent publish linearizes. |
| S10 | FR-004, FR-005, FR-008 | `CaptureContractTest.php` | 005 fixture retains A after B; new selection gets B. Real case proof deferred to 006. |
| S11 | FR-005 | `CaptureContractTest.php` | Missing/corrupt captured row blocks, never falls back to active. |
| S12 | FR-006 | `SettingsHistoryTest.php` | Admin history has ordered pagination and paths only. |
| S13 | FR-007 | `SettingsPrivacyTest.php`, `SettingsBoundaryTest.php` | Credential sentinel absent from DB/HTTP/log; no-store on successes/denials. |
| S14 | FR-008 | `CaptureContractTest.php` | Frozen provider order is returned; runtime fallback behavior is proven in 007/011. |
| S15 | FR-009 | `ApprovedTemplateTest.php`, `SettingsValidationTest.php`, `CaptureContractTest.php`, `SettingsPrivacyTest.php`, `SettingsRouteInventoryTest.php` | Trusted import, kind/digest selection, exact old bytes after newer import and no body route or admin body response. |

SC-001 maps to S01–S07/S12–S13. SC-002 maps to S08–S11. SC-003 maps to S03/S13. SC-004 maps to S05/S09. SC-005 maps to S15. Each listed test must assert behavior through the actual service or route indicated, not only method calls on a mock.
