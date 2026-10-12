# Acceptance Matrix

These are planned executable checks, not results from this documentation task. Paths below are under `v3_engine/tests/terminal_pipeline/` unless a regression path is explicitly given. Requirement behavior is normative in [spec](spec.md); exact boundaries are in [workflow](contracts/workflow.md). Tasks identify test authoring and implementation separately.

| Case | Requirements | Planned test files | Tasks | Observable outcome |
|---|---|---|---|---|
| A01 | FR-001,FR-023 | test_manifest.py, test_notebook_steps.py, test_connected.py | T002,T003,T010,T011,T030 | Different PDF counts; unchanged copied/upload bytes; one new notebook per subject and no reused proof notebook. |
| A02 | FR-002,FR-015 | test_scheduler.py, test_concurrency.py | T012,T013,T020,T021 | Two submissions/five children synchronize at a barrier:4 chains admitted, fifth pending, max4 IO/max2 build, serial conversation. |
| A03 | FR-003 | test_notebook_steps.py, test_workflow.py | T010–T013 | Recorded source scoping and exact turn sequence; all mapped ordinals, unknown map blocks; polls use timers. |
| A04 | FR-004 | test_notebook_steps.py, test_connected.py | T010,T011,T030 | Arbitrary types/counts, optional missing fields and malformed envelope retain raw output and warnings without stopping later Lessons. |
| A05 | FR-004,FR-014 | test_store.py, test_recovery.py | T004,T005,T016,T017 | Inject receipt/export disk failure after response; zero subsequent Lesson/provider sends until valid recovery. |
| A06 | FR-005 | test_workflow.py, test_connected.py | T012,T013,T030 | First scene dispatch occurs strictly after last mapped Lesson and frozen input digest. |
| A07 | FR-006,FR-023 | test_scene_steps.py, test_connected.py | T008,T009,T030 | Provider/model/template preserved, no extra ping/fallback/JEV request/app mutation, waiver fields and real build evidence. |
| A08 | FR-007 | test_cli.py, test_controls.py, test_scheduler.py | T014,T015,T018,T019 | Same ID returns same result after lost acknowledgement/CAN; different digest conflicts without another child/send. |
| A09 | FR-008,FR-013 | test_scene_steps.py, test_recovery.py | T008,T009,T016,T017 | Crash after raw receipt/normalized response/source/built dist; reentry consumes retained evidence with zero paid resends. |
| A10 | FR-008 | test_recovery.py | T016,T017 | Unique full-UUID notebook/inventory delta binds original resource; zero/multiple matches stay uncertain. |
| A11 | FR-008,FR-011 | test_recovery.py, test_concurrency.py | T016,T017,T020,T021 | Lost delivery/late lock owner stays uncertain; retries reconcile first and cannot blindly resend chat. |
| A12 | FR-009 | test_recovery.py | T016,T017 | Full-prefix anchoring disambiguates identical Continue; manual edit/addition/truncation blocks. |
| A13 | FR-010,FR-011 | test_credentials.py, test_controls.py | T006,T007,T018,T019 | Distinct auth/rate/transport codes, retry_after retained, no runtime browser/refresh subprocess or account switching. |
| A14 | FR-010 | test_credentials.py | T006,T007 | Wrong account with shared proof visibility/missing role fails; explicit owner passes; per-subject copies preserve master bytes. |
| A15 | FR-012 | test_controls.py, test_concurrency.py, test_restart.py | T018–T021,T026,T027 | Requested/applied distinction, no sends after applied fence, queued cancellation never relaunched after bootstrap, owned-process cleanup/retained receipts. |
| A16 | FR-007,FR-012,FR-015 | test_store.py, test_concurrency.py, test_controls.py | T004,T005,T018–T021 | Genuine overlapping processes/control Updates; stale epoch/version, standalone interleave and second worker/recovery rejected. |
| A17 | FR-013 | test_retry.py | T022,T023 | Closed partial run creates one linked queued recovery; consent required for uncertain generation, original reports/attempts unchanged. |
| A18 | FR-013 | test_scene_steps.py, test_retry.py | T008,T009,T022,T023 | Failed or damaged completed build uses saved source/new ordinal, original report intact; zero provider clients/requests/credentials required. |
| A19 | FR-016 | test_observability.py | T024,T025 | Every actual step/attempt/poll/control correlates to journal event; duration measured/null and event sequence stable. |
| A20 | FR-016,FR-018 | test_observability.py, test_replay.py | T024–T027 | Replayer and repeated local publication add zero event duplicates; real distinct attempts remain visible. |
| A21 | FR-017 | test_observability.py, test_cli.py | T024,T025 | Query failure/offline Describe fallback labelled last_known; unknown totals/usage null and freshness shown. |
| A22 | FR-019 | test_privacy.py | T002,T003,T024,T025 | Sentinels in every error/response/cause never reach history/logs/index; payload <=32KiB and no raw IDs/body leak. |
| A23 | FR-020 | test_restart.py | T026,T027 | Real same-DB worker/server restart; settled close proofs survive history expiry, unknown started chains never relaunch,30d history and retained artifacts. |
| A24 | FR-002,FR-007,FR-018 | test_rotation.py, test_replay.py | T026,T027 | Child-chain slot retained across CAN; supervisor drains/rejects bounded admission, carries queue/dedup refs, replay deterministic. |
| A25 | FR-021 | test_saved_results.py | T028,T029 | Each supported 017 handoff format works; no NotebookLM credentials/client/call, fixed input snapshot. |
| A26 | FR-022 | test_scene_steps.py, test_saved_results.py | T008,T009,T028,T029 | One scene fails/uncertain, later scenes still processed; zero-scene Lesson and input gaps yield partial, not complete. |
| A27 | FR-023 | test_legacy_compatibility.py; existing notebooklm/test_sdk_contract.py and scene_generation/test_cli.py | T001,T010,T011,T030,T032 | Old CLI flags/output preserved, no private SDK accesses in extracted flow; baseline legacy history replay unchanged. |
| A28 | FR-024 | test_connected.py, test_live_pipeline.py | T030,T031,T032 | Actual registered Activities + fake providers + real build/browser separate from live sample; missing live gates openly skipped. |

## Success-criterion evidence

| Criterion | Cases | Governing task groups |
|---|---|---|
| SC-001 | A01,A03,A04,A06,A07,A28 | T010–T015,T030–T032; actual original8-PDF evidence is separate from the connected tiny fixture/live small sample. |
| SC-002 | A02,A24 | T012,T013,T020,T021,T026,T027 |
| SC-003 | A05,A09,A10,A11,A12 | T004,T005,T008,T009,T016,T017,T020,T021 |
| SC-004 | A04,A19,A25,A26 | T010,T011,T024,T025,T028–T032 |
| SC-005 | A21,A23,A24 | T024–T027 |
| SC-006 | A15,A16,A18 | T018–T023 |
| SC-007 | A19,A20,A22 | T002,T003,T024–T027 |
| SC-008 | A25,A28 | T028–T032 |

## Evidence lanes

- Unit/contract checks prove local decisions and adapter mapping; SDK doubles are not account/quota evidence.
- Temporal lane uses real registered workflows/Activities and local server; fake remote providers do not prove live NotebookLM/AI.
- Connected lane uses eight tiny PDFs, multiple Lessons/variable scenes and actual fixture compilation; browser interaction is a separate opted-in real check, never production012 qualification.
- Live lane uses explicitly configured approved session and selected model, minimal disposable inputs, original approved assets and real results/build. No owner-authorized large eight-PDF upload is run automatically by default pytest.
- Existing Unit3/subject files remain byte-identical inputs. Record hashes if used live; fake PDFs are confined to tests and do not stand in for real-source quality evidence.
