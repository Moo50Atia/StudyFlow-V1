# Acceptance matrix

All paths are **planned tests**, not executed evidence. `P/` = `v3_engine/tests/notebooklm/`; `F/` = `tests/Feature/V3/NotebookLM/`; `I/` = `tests/Integration/V3/NotebookLM/`. Test names must contain their N-ID. Security assertions use actual routes/middleware, not policy-only calls. Concurrent cases run overlapping independent processes/connections against a shared disposable database.

| ID | Requirements | Planned test file | Observable expected outcome | Tasks |
|---|---|---|---|---|
| N01 | FR-001,014 | F/ProtocolCaptureTest.php | Old row backfill legacy; new case captures direct policy/framework; changed defaults cannot affect replay. | T003–T004 |
| N02 | FR-001,003 | F/FrameworkCatalogTest.php | Exact approved import immutable; hash/missing/unauthorized import fails; no case/provider work without framework. | T003–T004 |
| N03 | FR-002,014 | F/DirectIntakeTest.php; P/test_source_files.py | Actual intake rejects invalid/infected PDF; accepted scanned/diagram original is streamed unchanged and no extractor/OCR called. | T007–T008 |
| N04 | FR-003,004 | P/test_source_files.py; F/SourceManifestTest.php | PDF and exact steps bytes/titles/MIME/roles captured; no caller-selected extra sources. | T007–T008 |
| N05 | FR-004,012 | P/test_source_files.py | Registration not ready; known-ID polling makes both ready before configure/Divide; processing timeout polls again without upload. | T007–T008 |
| N06 | FR-011,012 | P/test_source_recovery.py | PDF confirmed, steps uncertain: no duplicate PDF/steps send; known source ID reconciles. | T007–T008,T013–T014 |
| N07 | FR-011,012 | P/test_source_recovery.py | Lost create ID + absent/truncated recent listing remains uncertain; no new create/adoption. | T007–T008 |
| N08 | FR-004,011 | F/SourceManifestTest.php | Changed title/type, extra source or duplicated cross-case provider identity cannot bind. | T007–T008 |
| N09 | FR-005,006,012,013 | P/test_credentials.py | Domain/path-aware bridge preserves account profile; malformed/expired auth gives fixed status, zero browser launches, no secret logs. | T005–T006 |
| N10 | FR-007,008,016 | P/test_protocol.py; F/DirectMapTest.php | Divide PDF-only, no Coverage; mixed siblings have unique paths; promoted Mini and repeated titles have contiguous work order; no page proof fabricated. | T009–T012 |
| N11 | FR-008,011 | P/test_protocol.py | Execution uses both sources/exact bytes; only exact System Ready unlocks exact first-start; no additional step sends. | T009–T012 |
| N12 | FR-009,010 | P/test_bundle_parser.py; F/CombinedLessonCommitTest.php | All eleven multilingual fields/inner quotes/whitespace preserved; thirteen preparation fields and one exact Third body per Section. | T009–T012 |
| N13 | FR-010,012 | F/CombinedLessonCommitTest.php | Inject artifact/DB failure after First staging: zero committed Section/prompt/receipt/cursor advancement; known reply can be reposted. | T011–T012 |
| N14 | FR-010,011 | F/CombinedLessonCommitTest.php | Exact duplicate returns original refs/version; changed conversation/projection/body gives409; completion digest uses real claimed version. | T011–T012 |
| N15 | FR-008,009,010 | P/test_bundle_parser.py; F/CombinedLessonCommitTest.php | Missing/repeated labels, wrong Lecture/Lec/marker, final-only, oversized body, invalid type/truncated quote => rejection, raw evidence retained, no Continue. | T009–T012 |
| N16 | FR-010,011,018 | I/DirectClaimConcurrencyTest.php | Two overlapping upload/Continue claims and duplicate result commits authorize one send and create one immutable bundle. | T013–T014 |
| N17 | FR-011,012 | P/test_turn_recovery.py; F/DirectRecoveryTest.php | Anchored known history response recovers local commit; repeated Continue text without anchor, interleaving/truncated history or missing convo does not. | T013–T014 |
| N18 | FR-012,014 | I/DirectControlRaceTest.php; F/DirectRecoveryTest.php | Real hold/cancel/suspension racing claim/commit prevents advance; private known result retained; no resend on resume. | T013–T014 |
| N19 | FR-014,018 | P/test_workflow_replay.py | Saved legacy start/source/lesson/review histories replay unchanged against new worker; direct path schedules no extraction. | T015–T016 |
| N20 | FR-008,012,017 | P/test_workflow_progression.py | Three work items progress via real registered activities/committed receipts; unique event keys; lost signal/restart recovers without false parking or repeat Continue. | T015–T016 |
| N21 | FR-013,014 | F/DirectAccessBoundaryTest.php | Guest/inactive/admin/foreign teacher rejected on actual streams/Sections/history/export; same missing/foreign404, no-store; legacy IDs/routes cannot bypass. | T017–T018 |
| N22 | FR-013,016 | F/DirectEvidenceTest.php; P/test_privacy.py | Owner chain includes PDF/framework/bundle provenance; admin excludes content/paths/digests/providerIDs; SDK/parser exceptions don't log sentinel content. | T017–T018 |
| N23 | FR-015,017,018 | I/ConnectedDirectPipelineTest.php | Connected Laravel/Temporal/worker plus existing qualification/authoring/check/review services reaches owner decision; current-pass fences remain; waiver never shown as real JEV/approval. | T015–T020 |
| N24 | FR-002,004,008,009,018 | P/test_live_direct_pipeline.py | Live SDK uploads small mixed PDF and real Unit3 original, both sources ready; Divide/handshake/full lesson-loop evidence valid; no OCR fallback. Separate live JEV/scene/review gate accurately reported. | T019–T020 |

`P/test_sdk_contract.py` additionally governs N04/N05/N09/N11/N17: assert installed0.8.4 argument/return types and instrument actual HTTP transport retries. A fake `ask_once` call count does not prove the SDK did not resend internally. Live fixtures compare known educational content rather than only checking valid IDs or schema.

## Requirement → task coverage

| Requirements | Owning tasks |
|---|---|
| FR-001,003 | T003–T004,T007–T008 |
| FR-002,004 | T007–T008,T019–T020 |
| FR-005,006 | T005–T006 |
| FR-007,008,009 | T009–T012,T015–T016 |
| FR-010 | T011–T014 |
| FR-011,012 | T005–T008,T013–T016 |
| FR-013,016 | T017–T018 |
| FR-014 | T003–T004,T013–T018 |
| FR-015,017 | T015–T016,T019–T020 |
| FR-018 | All behavioral test tasks and T019–T020 |

SC-001 maps N03–N05/N24; SC-002 maps N10/N11/N20/N24; SC-003 maps N12–N15; SC-004 maps N06/N07/N14/N16–N18; SC-005 maps N19/N21–N24. A checked task requires named test assertions/results, not merely ID mentions.
