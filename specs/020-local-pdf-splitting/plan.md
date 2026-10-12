# Implementation Plan: Local PDF Splitting Before Upload

**Branch**: existing `main` | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)
**Input**: `specs/020-local-pdf-splitting/spec.md`

## Summary

Add an offline preparation service at terminal submission capture and a reusable `prepare-pdfs` command. Inspect selected originals, plan both branches of recursive halving, extract final leaves directly using qpdf, verify them, and publish an ordered inventory and final folder. New per-PDF captures reference those verified final files and retain separate original-source lineage. Reuse the existing serial notebook workflow and archive/reset fences.

## Technical Context

**Language/Version**: Existing Python >=3.12 project in `v3_engine/pyproject.toml`.
**Primary Dependencies**: Installed qpdf CLI (also used by Laravel intake); Python stdlib for paths, hashes, JSON, locks and subprocesses; existing Temporal and notebooklm-py dependencies unchanged.
**Storage**: Private filesystem under terminal submissions; canonical JSON inventories and existing ArtifactRef references. No database migration.
**Testing**: Manual/offline acceptance guide and existing pytest regression lanes where appropriate at implementation time. No new automated test tasks requested; no tests run during specification generation.
**Target Platform**: Existing local Linux terminal environment.
**Project Type**: Internal service plus terminal CLI extension.
**Performance Goals**: Zero external requests during preparation; compute ranges once and write each terminal leaf once directly from a retained original, avoiding intermediate PDF writes.
**Constraints**: Split trigger >100; descendant cap <=50; first half floor(L/2); immutable originals/framework; explicit source order; no text extraction/OCR or prompt changes. Run subprocesses as argv without a shell, bound inspection to 60 seconds and extraction to 120 seconds per file; timeout rejects locally.
**Scale/Scope**: Acceptance up to 1000 pages including 32 resulting parts. No new terminal maximum page count; existing byte/intake/provider limits remain applicable. Terminal new per-PDF subjects and standalone local preparation only.

## Constitution Check

Before research: `.specify/memory/constitution.md` contains only template placeholders and commented examples; no ratified principles can be extracted. There is no identified normative conflict, but constitutional compliance cannot be certified. Do not invent principles or update the constitution in this feature.

After design: same governance limitation. Design follows observed immutable-capture, explicit-source and archive-before-reset behavior. These are repository integration constraints, not invented constitution rules.

Tooling: `.specify/extensions.yml` is absent, so before/after hooks for all four requested skills are skipped. `update-agent-context.sh` is absent from `.specify/scripts/bash/`; the prescribed context update cannot execute. Document this limitation rather than generating a substitute script or editing unrelated agent files.

## Project Structure

### Documentation (this feature)

```text
specs/020-local-pdf-splitting/
  spec.md
  plan.md
  research.md
  data-model.md
  quickstart.md
  contracts/local-preparation.md
  tasks.md
  checklists/requirements.md
```

### Source Code (repository root)

```text
v3_engine/terminal_pipeline/pdf_preparation.py    # new policy, inspection, extraction, publication
v3_engine/terminal_pipeline/manifest.py           # capture/reuse + optional lineage
v3_engine/terminal_pipeline/cli.py                # prepare-pdfs + verified admission payload
v3_engine/terminal_pipeline/pdf_scope.py          # archive lineage
v3_engine/terminal_pipeline/activities.py         # verify preparation and project report lineage
v3_engine/terminal_pipeline/subject_workflow.py   # reuse existing serial loop; no splitting here
scripts/v3/run_temporal_pipeline.py              # existing CLI entrypoint
scripts/v3/examples/local_pdf_preparation.json   # new operator example
 docs/PER-PDF-NOTEBOOKLM.md                       # update operator guide
```

**Structure Decision**: Put preparation next to terminal capture; keep PDF subprocess work outside Temporal workflows. Existing workflow source lists need only expanded, ordered inputs. `v3_engine/pyproject.toml` and lockfile require no new Python dependency. Document qpdf as a runtime prerequisite.

## Architecture and phases

### Phase 0 — Research

Complete [research.md](research.md) from local source inspection, qpdf help and delegated read-only integration research. All technical choices resolved. Scope deliberately excludes Laravel item expansion: its admission bridge does not call terminal `capture_manifest`.

### Phase 1 — Design

Implement [data-model.md](data-model.md) and [contracts/local-preparation.md](contracts/local-preparation.md):

1. Resolve originals exactly as today: explicit list order or nonrecursive Unicode filename ordering. Reject duplicate original paths/content before preparation. Snapshot originals privately, checking hashes before/after copying; inspect the retained originals and never write back to operator inputs.
2. New `prepare-pdfs` accepts an explicit list or folder and an output preparation directory. It performs local work only. Automatic capture uses the same service under `submissions/<id>/preparation/<subject-token>/`; subject-token is a safe encoded/hash identity, not an unchecked path segment.
3. First inspect all originals with qpdf availability, encryption, structure and page count. Reject encrypted files, zero pages, check warnings (exit 3), errors and timeouts. Read physical counts using `--show-npages`. No arbitrary page maximum is added by this feature.
4. For N<=100, retain one intact file. For N>100, plan leaves: L=end-start+1; left_end=start+floor(L/2)-1; recurse on [start,left_end] and [left_end+1,end] while L>50. This formula deliberately gives the smaller first half for odd lengths.
5. Write leaves directly from the retained original using argv equivalent to `qpdf ORIGINAL --pages . START-END -- OUTPUT`. Never materialize intermediate halves. Preserve page objects/content/resources, page dimensions and rotation; do not assert document-level bookmarks/signatures remain identical after splitting. Intact copies remain byte-identical.
6. Name outputs `0001--Mechanics--pages-0001-0050.pdf` for split files and `0002--Small.pdf` for intact files. Source ordinal padding is max(4,digits(number_of_originals)); page padding is max(4,digits(original_pages)). Sanitize stems to a bounded filename while retaining Unicode when supported; ordinal prefix prevents basename collisions. Record the exact unsanitized original name/location. Inventory order is authoritative; sorted names match it.
7. Verify all expected filenames, hashes, sizes, structural checks/counts and per-original adjacent ranges; reject unexpected final files. Validate gap-free coverage and each split count <=50. Rehash retained originals to detect changes during work. Completed inventory contains canonical digest excluding its own digest field, plus qpdf version for diagnostics.
8. Use a preparation lock for a subject/output path; stage as a sibling on the same filesystem. Rename the complete package directory atomically only after verification. Existing completed package is reused only if original identities/order/policy and every recorded artifact verify. Conflicts do not replace it. Incomplete staging is discarded or rebuilt under the lock; uploads never enumerate staging. Publish the captured submission manifest last, after all subjects verify, before CLI connects to Temporal.
9. Captured `SubjectConfig` gains optional `pdf_preparation_ref`; PDF `InputSnapshot` gains optional lineage referencing inventory/original ordinal/range/part ordinal. New original inventory is separate from effective upload `inputs`. Resume/reuse compares operator originals against that inventory, then checks all final refs instead of the old original-to-input zip. Preserve legacy absent-field serialization and digest validation exactly. Legacy captures are reused without preparation.
10. Capture PDF inputs by verified final ArtifactRef rather than copying parts again; framework remains a separate unchanged snapshot. Original path is retained as the operator's original location and lineage distinguishes multiple parts. Duplicate original validation remains; valid split parts can share bytes because range/ordinal identifies them.
11. CLI sends final file title/name and contiguous upload ordinal. Immediately before admission, verify complete preparation; at each upload verify the accepted file fingerprint using the existing upload guard. Existing serial loop selects only the active final PDF, applies unchanged prompts, archives and resets as today. No filesystem enumeration or split decisions in workflow code.
12. Add optional original/range/policy/preparation-ref fields to each archive and final report; verify lineage against subject config/inventory when sealing or reading new archives. Legacy archives retain their old verification path. Provenance lives in private artifacts; educational responses and source paths do not enter operational logs.

### Phase 2 — Task Generation

Generate story phases in implementation order US1 (local preparation), US3 (safe submission integration), US2 (operator CLI). US3 is P1 and precedes US2 P2; US1's service produces inspectable files, so it is an offline MVP before provider integration.

## Validation and completion

Use [quickstart.md](quickstart.md) for policy boundaries, content preservation, ordering, corruption, concurrency, retry and legacy behavior. Offline rendering for QA may compare visible page content; it must never replace uploaded pages. Production qpdf checks/counts and hash guards do not constitute an educational quality claim.

Run appropriate existing terminal per-PDF regressions when implementing changes, without a default live-provider request. No new automated suite is mandated in this documentation request. Live NotebookLM work requires a separately selected operator execution. Skipped live checks cannot establish provider quality or capacity.

## Complexity Tracking

No normative constitution violation identified; alignment remains uncertified because the constitution is a template. Separate original inventory is needed because one original can expand to many upload files. Atomic package publication and additive lineage use the existing private artifact approach.
