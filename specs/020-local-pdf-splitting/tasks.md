# Tasks: Local PDF Splitting Before Upload

**Input**: Design documents from `specs/020-local-pdf-splitting/`
**Prerequisites**: spec.md, plan.md, research.md, data-model.md, contracts/local-preparation.md, quickstart.md
**Tests**: No new automated test tasks requested. Manual acceptance and appropriate existing regressions belong to implementation validation. This package contains planned tasks only.
**Organization**: Story phases follow priority/dependency order US1 (P1), US3 (P1), US2 (P2).

## Format: `[ID] [P?] [Story] Description`

All paths are repository-relative. [P] indicates different files with no dependency within its parallel wave; omitted markers indicate serial work. Shared phases have no story label.

## Phase 1: Setup

**Purpose**: Establish the local dependency and existing-path baseline.

- [X] T001 Document qpdf availability, argv/timeouts and terminal prerequisite checks in docs/PER-PDF-NOTEBOOKLM.md; preserve the existing credentials/provider instructions (FR-001, FR-012).
- [X] T002 Inspect capture_manifest, _reuse_existing_capture and optional-field digest behavior in v3_engine/terminal_pipeline/manifest.py; implement the shared original-selection helper preserving explicit order, folder filename order and duplicate original rejection (FR-006, FR-011, FR-012).

## Phase 2: Foundational

**Purpose**: Define records and compatibility before any story implementation.

- [X] T003 Define policy v1, OriginalSource, PreparedPDF and PreparationInventory serialization/validation in v3_engine/terminal_pipeline/pdf_preparation.py, using existing ArtifactRef and SafeCode types with package-relative paths and strict range/ordinal validation (FR-002, FR-007, FR-008).
- [X] T004 Extend InputSnapshot and SubjectConfig in v3_engine/terminal_pipeline/manifest.py with optional pdf_lineage/pdf_preparation_ref; omit absent fields so legacy captured digests round-trip unchanged (FR-007, FR-012).

**Checkpoint**: Typed records and legacy compatibility defined. No external dispatch.

## Phase 3: User Story 1 - Local preparation (Priority: P1) — Offline MVP

**Goal**: Produce a complete verified package from original PDFs.
**Independent Test**: Follow quickstart.md offline with all nine page-count cases plus text/scan/diagram/rotation/blank samples. Inspect physical order and original hashes without provider calls.

- [X] T005 [US1] Implement pure range planning in v3_engine/terminal_pipeline/pdf_preparation.py: original N<=100 passthrough, otherwise recursively split both branches with left_end=start+floor(L/2)-1 until L<=50; verify 101/150/200/1000 examples (FR-002, FR-003, SC-001).
- [X] T006 [US1] Implement safe original snapshotting and strict qpdf availability/encryption/structure/page-count inspection in v3_engine/terminal_pipeline/pdf_preparation.py using bounded argv subprocesses; reject warnings, zero pages, encryption, errors and changes during capture (FR-001, FR-004, FR-009).
- [X] T007 [US1] Implement padded source/range filenames, bounded Unicode stems, intact-copy naming and direct final-leaf extraction from retained originals in v3_engine/terminal_pipeline/pdf_preparation.py; retain legitimate identical-part bytes as distinct range identities and exclude intermediate files (FR-003, FR-004, FR-005, FR-006, SC-002, SC-005).
- [X] T008 [US1] Implement complete inventory verification in v3_engine/terminal_pipeline/pdf_preparation.py: exact final set, canonical digest, originals/final hashes and sizes, qpdf counts/checks, contiguous ordinals, filename sorting and gap-free per-original partitions; enforce split cap and intact byte equality (FR-007, FR-008, SC-001, SC-003, SC-004).
- [X] T009 [US1] Implement locked staging, same-filesystem atomic package publication, conflict rejection and unchanged complete-package reuse in v3_engine/terminal_pipeline/pdf_preparation.py; recover incomplete staging without exposing it to upload consumers (FR-009, FR-011, SC-004).

**Checkpoint**: Service produces inspectable offline preparation. Direct page copying preserves physical content; manual sample inspection validates visible preservation.

## Phase 4: User Story 3 - Verified upload and recovery (Priority: P1)

**Goal**: Expand new per-PDF captures into verified final-file inputs without changing old runs.
**Independent Test**: Use the production submission boundary with a provider recorder, checking local rejection, serial final-file uploads/archive/reset and legacy/new retry identity. Dependency: US1 service.

- [X] T010 [US3] Integrate automatic preparation into new per_pdf capture in v3_engine/terminal_pipeline/manifest.py after original selection/duplicate checks, using private submission preparation storage and safe subject tokens; build effective inputs from final refs in inventory order and publish submission manifest only after every subject verifies (FR-001, FR-006, FR-007, FR-009, FR-010).
- [X] T011 [US3] Update _reuse_existing_capture in v3_engine/terminal_pipeline/manifest.py to compare new raw original sets/order/config against inventory originals and verify retained originals/final artifacts; preserve the existing legacy branch and reject changed source/policy/output identities without regeneration (FR-011, FR-012, SC-004).
- [X] T012 [US3] Verify complete preparation immediately before admission and construct ranged source titles with contiguous ordinals in v3_engine/terminal_pipeline/cli.py; retain existing safe-code rejection reasons and keep all local capture ahead of Temporal/provider access (FR-005, FR-008, FR-009, FR-010, SC-003, SC-004).
- [X] T013 [US3] Extend new archive sealing/verification in v3_engine/terminal_pipeline/pdf_scope.py with original/range/policy/preparation-ref lineage validated against subject config and inventory; retain legacy archive verification and archive-before-reset semantics (FR-007, FR-010, FR-012, SC-003, SC-005).
- [X] T014 [US3] Extend archive-derived final-report projection in v3_engine/terminal_pipeline/activities.py with optional original and part-range summaries; retain the existing accepted-hash upload guard and keep private source details out of operational logs (FR-007, FR-010, FR-012, SC-003, SC-005).
- [X] T015 [US3] Validate the existing serial loop in v3_engine/terminal_pipeline/subject_workflow.py consumes expanded source ordinals without extra filesystem enumeration or split work; record observed upload/source-selection/archive/reset/recovery results in specs/020-local-pdf-splitting/quickstart.md, including zero dispatch for local failure and unchanged joint/saved/canonical prompts (FR-008, FR-009, FR-010, FR-011, FR-012, SC-003, SC-004).

**Checkpoint**: Newly captured per-PDF submissions process final files; captured old runs keep existing identity/behavior. Do not rewrite the workflow if ordered inputs already satisfy the contract.

## Phase 5: User Story 2 - Operator command and inspectable output (Priority: P2)

**Goal**: Expose the shared offline preparation service with clear file/range summaries.
**Independent Test**: Run the proposed prepare-pdfs command on ordered explicit inputs/folder inputs and inspect filename sorting, exact originals, range summaries and reuse/conflict results. Dependency: US1 service; no provider needed.

- [X] T016 [US2] Add prepare-pdfs parser/handler to v3_engine/terminal_pipeline/cli.py with mutually exclusive folder or repeated file flags, required output package path and JSON/text output, enforcing output outside selected originals/folder; call the shared service without Temporal or provider connection (FR-005, FR-006, FR-009, SC-005).
- [X] T017 [P] [US2] Add a replace-before-use local original-source manifest example in scripts/v3/examples/local_pdf_preparation.json with explicit new/per_pdf mode, source order and existing provider/model/proof-notebook fields; include no credentials and no prepared files inside original inputs (FR-006, FR-010).
- [X] T018 [P] [US2] Update docs/PER-PDF-NOTEBOOKLM.md with both trigger/terminal limits, 100/101/150/1000 examples, the local command/package layout, names/ordering, retry rules, terminal scope and semantic/provider-limit caveats (FR-002, FR-005, FR-006, FR-011, FR-012, SC-001, SC-005).

**Checkpoint**: Operators can prepare offline and inspect every original/range before deciding to submit.

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T019 Execute the offline boundary/content/order/failure/concurrency validation guide and appropriate existing terminal/per-PDF regression checks; record actual outcomes and any skips in specs/020-local-pdf-splitting/quickstart.md without live-provider requests or quality claims (FR-001–FR-012, SC-001–SC-005).
- [X] T020 Reconcile implemented filenames, fields, refs, exit codes, policy math and scope against specs/020-local-pdf-splitting/contracts/local-preparation.md and document deviations there before marking the feature complete (FR-002, FR-007, FR-012).

## Dependencies & Execution Order

Setup T001→T002 → foundation T003→T004 → US1 T005→T006→T007→T008→T009.
US3 T010→T011→T012→T013→T014→T015 depends on US1 and foundation.
US2 T016 depends on US1 and completion of shared CLI changes T012; T017/T018 may run concurrently after T016. Final T019→T020 follows all stories.

User stories are independently demonstrable at their boundaries, but US2/US3 reuse US1 and shared files cannot be edited concurrently. Do not infer permission to dispatch implementation agents from these parallel markers.

## Parallel Examples by Story

- US1: implementation is serial because policy, writer and validation share pdf_preparation.py; after T009, content and count acceptance inspections can be independently performed.
- US3: implementation is serial across capture/admission/archive/report contracts; after T015, legacy and newly prepared run validation can be inspected independently.
- US2: T017 (example JSON) and T018 (operator guide) can run together after command behavior is established by T016; they edit different files.

## Implementation Strategy

1. Deliver US1 after setup/foundation as the offline MVP; inspect final packages before integration.
2. Deliver US3 to integrate verified preparation into new per-PDF submissions and recovery.
3. Deliver US2 to expose the reusable operator command and examples.
4. Complete the guide and existing regression checks, then reconcile contracts. New automated tests or live provider experiments require explicit task scope if added later.

## Task Summary

20 tasks total: setup 2, foundational 2, US1 5, US3 6, US2 3, polish 2. Two parallel-marked tasks in one safe wave. Every task has a checkbox, sequential ID, appropriate story label and concrete repository path.
