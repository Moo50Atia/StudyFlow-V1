# Acceptance Matrix

All listed test files are proposed under `v3_engine/tests/scene_generation/`; creating this spec did not run them. Provider doubles prove orchestration/wire behavior; real build and live scopes are explicitly distinct.

| Case | Requirements | Planned executable evidence | Expected result | Tasks |
|---|---|---|---|---|
| A01 | FR-001, FR-005 | `test_handoff.py`, `test_service.py` | All three actual kinds, two Lessons with 2 and 4 Scenes, exact prompts/source pointers/titles/ordering; input bytes unchanged. | T002,T003,T012,T013 |
| A02 | FR-002 | `test_handoff.py`, `test_cli.py` | Arbitrary labels including `Comparison \| Objects` accepted; no First/Second gate; bad prompt/empty Lesson visible and other items proceed. | T002,T003,T014,T015 |
| A03 | FR-005, FR-006, FR-008 | `test_providers.py`, `test_store.py`, `test_service.py` | Exact 15,960-byte template, prompt and new contract sent; complete raw receipt precedes parse; correct source/compiled lineage. | T004,T005,T008,T009,T012,T013 |
| A04 | FR-006, FR-007 | `test_source_package.py`, `test_builder.py`, `test_local_build.py` | React host default component accepted, SVG and R3F fixture typecheck/build, selected dependency closure/pins only. | T008,T009,T010,T011 |
| A05 | FR-006, FR-007, FR-011 | `test_service.py`, `test_cli.py` | Bad JSON/package/type/build failure retains raw/source evidence and later scene is generated. | T012,T013,T014,T015 |
| A06 | FR-003, FR-005 | `test_providers.py`, `test_service.py` | Correct four API wires, one selected provider, one call per queued attempt, no fallback/implicit ping/history. | T004,T005,T012,T013 |
| A07 | FR-004 | `test_cli.py`, `test_providers.py` | Separate `check`, one bounded chat, proper pass/fail and no run/scene artifacts. | T004,T005,T006,T007 |
| A08 | FR-004, FR-005, FR-010 | `test_config.py`, `test_cli.py` | Missing model/key, URL mismatch shape, wrong default/custom template digest, input/output overlap or missing tools returns 2 before provider send. | T002,T003,T006,T007,T014,T015 |
| A09 | FR-006, FR-009, FR-013 | `test_providers.py`, `test_store.py` | Text parts joined correctly, reasoning not used as code; structured 4xx/5xx/timeouts/oversize/truncation outcomes and retained evidence, no raw error logs. | T004,T005,T008,T009 |
| A10 | FR-008, FR-009 | `test_resume.py` | Generated outputs rehashed/skipped with zero calls; corrupted artifact flagged rather than silently trusted. | T016,T017 |
| A11 | FR-006, FR-007, FR-008, FR-009 | `test_resume.py`, `test_cli.py` | Crashes after receipt/source/dist publication recover locally with zero new provider sends; build uses saved inputs without a key/original input; healthy builds skip and intact source can replace damaged compilation under a new ordinal. | T014–T017 |
| A12 | FR-008, FR-009 | `test_resume.py`, `test_cli.py` | Interrupted reservation stays uncertain; explicit retry appends attempt and warning; earlier evidence unchanged. | T016,T017 |
| A13 | FR-005, FR-008, FR-010 | `test_resume.py`, `test_store.py` | Changed prompt/template/provider/model/endpoint/timeout/scaffold/catalog/tool versions refuses with 2 before new calls or overwrite. | T008,T009,T016,T017 |
| A14 | FR-010 | `test_concurrency.py` | Two processes reach a shared barrier/output lock; only one sends and owns receipts; a killed lock owner permits recovery. Separate roots run independently. | T018,T019 |
| A15 | FR-002, FR-011 | `test_handoff.py`, `test_cli.py`, `test_service.py`, `test_resume.py` | Exact complete/partial counts, 0/1/2/3/4/130 codes, missing expected Lessons visible, invalid/conflicting totals rejected; without a total completion covers supplied prompts only; limit applies to new sends. | T002,T003,T012–T017 |
| A16 | FR-008, FR-013 | `test_source_package.py`, `test_store.py`, `test_builder.py` | Path escape, reserved host overwrite, generated configs/scripts, duplicate paths and symlinks rejected; original inputs/template untouched; child env excludes secrets. | T008,T009,T010,T011 |
| A17 | FR-012, FR-013 | `test_cli.py`, `test_service.py` | Only selected provider network endpoint called; outputs explicitly mark owner waiver and no JEV call/score/approval; secret/prompt sentinels absent from stdout/stderr. | T012,T013,T014,T015 |
| A18 | FR-003, FR-007, FR-012 | `test_service.py`, final diff/report | Service injected, no Laravel/Temporal imports/calls, root JS dependencies and production provider/qualification routes unchanged. | T012,T013,T022 |
| A19 | FR-007, FR-011 | `test_local_build.py`, `test_browser_smoke.py` (`browser_ui`) | Real npm/tsc/Vite outputs rehashed; locally served fixture button changes displayed state, zero page errors; no external runtime requests. | T010,T011,T020 |
| A20 | FR-003, FR-005, FR-006, FR-007, FR-012 | `test_live_scene.py` (`live`), separate manual browser record | Opted-in actual selected-provider generation, real source/build manifest and usage/response evidence; unavailable credentials skip truthfully and gate remains open. No JEV/teacher claim. | T021,T022 |

## Requirement coverage

| Requirement | Cases | Task coverage |
|---|---|---|
| FR-001 | A01 | T002,T003,T012,T013 |
| FR-002 | A02,A15 | T002,T003,T012–T017 |
| FR-003 | A06,A18,A20 | T004,T005,T012,T013,T021,T022 |
| FR-004 | A07,A08 | T002–T007,T014,T015 |
| FR-005 | A01,A03,A06,A08,A13,A20 | T002–T005,T008,T009,T012–T017,T021 |
| FR-006 | A03–A05,A09,A11,A20 | T004,T005,T008,T009,T012,T013,T016,T017,T021 |
| FR-007 | A04,A05,A16,A18–A20 | T010–T013,T020–T022 |
| FR-008 | A03,A10–A13,A16 | T008,T009,T016,T017 |
| FR-009 | A09–A12 | T004,T005,T016,T017 |
| FR-010 | A08,A13,A14 | T002,T003,T008,T009,T016–T019 |
| FR-011 | A05,A15,A19 | T012–T017,T020 |
| FR-012 | A17,A18,A20 | T012–T015,T021,T022 |
| FR-013 | A09,A16,A17 | T004,T005,T008–T015 |

SC-001 maps A01–A05; SC-002 A06–A09; SC-003 A10–A14; SC-004 A15–A18; SC-005 A19–A20. Task range notation in this coverage summary is inclusive; actual deliverables/dependencies are explicit in [tasks](../tasks.md).
