# Feature Specification: Local PDF Splitting Before Upload

**Feature Branch**: `main` (existing; feature directory is independent)
**Created**: 2026-10-11
**Status**: Draft — documentation only; implementation pending
**Input**: Inspect source PDFs locally before upload; split large originals repeatedly through both halves until each part has at most 50 pages; include original start/end pages in names; order final PDFs before the existing multiple-PDF processing loop.

## Overview

Large PDFs have produced inconsistent lesson division. Deterministic local preparation will supply smaller PDFs in reading order and preserve every original physical page. This controls input size; it does not establish educational completeness.

The policy has two limits: originals with **more than 100 pages** trigger splitting; once triggered, all resulting parts contain **at most 50 pages**. Originals of 1–100 pages stay intact. Repeated halving determines the number of parts.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Prepare a large source locally (Priority: P1)

As an operator, I want predictable local inspection and splitting so I can prepare manageable source PDFs without manually cutting pages.

**Why this priority**: Produces the verified inputs needed by processing.
**Independent Test**: Prepare numbered PDFs offline and inspect decisions, ranges, counts and content.

**Acceptance Scenarios**:

1. **Given** originals of 1, 50, 51, 99 or 100 pages, **When** prepared, **Then** each remains one byte-identical upload file without a range suffix.
2. **Given** 101 pages, **When** prepared, **Then** final ranges are 1–50, 51–75, 76–101 (50, 25, 26 pages).
3. **Given** 150 pages, **When** prepared, **Then** final ranges are 1–37, 38–75, 76–112, 113–150 (37, 38, 37, 38 pages).
4. **Given** 200 pages, **When** prepared, **Then** final ranges are 1–50, 51–100, 101–150, 151–200.
5. **Given** 1000 pages, **When** prepared, **Then** 32 final parts of 31 or 32 pages cover 1–1000; intermediate halves never enter the upload folder.
6. **Given** an odd range above 50 pages, **When** divided, **Then** the first half takes floor(length/2) pages and the second takes the remainder; both continue until within the limit.

### User Story 2 - Inspect named parts in reading order (Priority: P2)

As an operator, I want final files in one folder with recognizable original ranges and filenames that sort in reading order.

**Why this priority**: Prevents parts being processed out of sequence.
**Independent Test**: Prepare multiple sources, including similar and Unicode names; compare filename sorting with the ordered upload inventory.

**Acceptance Scenarios**:

1. **Given** split Mechanics.pdf, **When** published, **Then** filenames identify Mechanics and inclusive original start/end physical pages with padded numbers.
2. **Given** multiple originals, **When** prepared, **Then** all parts of each original remain contiguous, sorted by starting page, before the next original.
3. **Given** an explicit original list, **When** prepared, **Then** its supplied order is retained; for folder input, existing Unicode filename order determines original order.
4. **Given** identical basenames in different directories or Unicode names, **When** prepared, **Then** filenames are distinct and the exact original names/locations remain in the record.

### User Story 3 - Process verified final PDFs and recover safely (Priority: P1)

As an operator, I want complete local verification before upload and each part processed separately, so failures cannot silently omit pages or mix part identities.

**Why this priority**: Connects preparation to the existing serial PDF map, lesson, archive and reset cycle.
**Independent Test**: Observe the production submission boundary with a recorded provider substitute; validate zero uploads before successful preparation, exact upload order, archives and unchanged retry identity.

**Acceptance Scenarios**:

1. **Given** a new per-PDF subject, **When** submitted, **Then** all originals/final files verify locally before admission or any external upload, and only final parts/intact small sources enter processing.
2. **Given** encrypted/malformed sources, missing or altered output, invalid ranges, interrupted preparation or write failure, **When** submitted, **Then** reject locally with a reason and zero uploads.
3. **Given** valid preparation, **When** processed, **Then** each final file uses its own contiguous upload ordinal and the existing divide/execution/lesson/archive/reset cycle; archive before resetting for the next file.
4. **Given** an unchanged repeated submission, **When** retried, **Then** reuse verified preparation/captures; reject changed original bytes, order, policy or final bytes under the same submission identity.
5. **Given** an existing capture, joint mode or saved handoff, **When** resumed/submitted, **Then** retain its previous interpretation and identity; automatic preparation applies only to newly captured terminal per-PDF subjects.

### Edge Cases

- Zero pages, unreadable PDFs, encryption and structural warnings fail inspection; automatic repair/password recovery are excluded.
- Physical positions starting at 1 include front matter/blank pages; printed labels do not define ranges.
- Legitimate repeated pages may create byte-identical parts. Part identity includes original/range/ordinal, while duplicate original selections follow current rejection rules.
- Missing inspection tools, timeouts, disk exhaustion, concurrent attempts or interruption must never publish a completed record for partial outputs.
- Final output folders exclude originals and temporary intermediate files. Filesystem enumeration order does not define processing order.
- Topics can cross page cuts. Overlap, semantic boundaries, continuation summaries and lesson-map merging are excluded; physical preservation does not guarantee semantic continuity.
- Prepared batches may reach provider limits; existing quota/error handling applies without a promise that every batch fits one notebook.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Inspect every original locally for readability, encryption, structural validity and a positive physical page count before accepting new per-PDF subjects; reject failures before external work.
- **FR-002**: Apply fixed policy version 1: split originals only above 100 pages; pass originals of 100 or fewer pages intact; terminate split descendants only at 50 or fewer pages.
- **FR-003**: For each triggered range [start,end] of length L > 50, divide it into a first range of floor(L/2) pages and a second of the remaining pages; recursively process both, preserve original coordinates, and upload terminal ranges only.
- **FR-004**: Preserve every original page exactly once and in order, including text, images, diagrams, rotation and blank pages, through direct page copying. Do not replace pages using text extraction, OCR, summarization or rendering. Original sources and the framework remain unchanged.
- **FR-005**: Name every split PDF with its original-source identity and inclusive original start/end physical positions; pad numbers for filename sorting and ensure distinct names. Intact sources retain their basename in the ordered naming convention, without a range suffix.
- **FR-006**: Publish final PDFs in one dedicated folder per subject; use original order then numerical starting-page order. Provide an ordered upload inventory; ascending filename sorting must agree with it.
- **FR-007**: Retain policy/version/limits, ordered original identities/names/locations/fingerprints/page counts, final identities/fingerprints/page counts/ranges/upload ordinals, split status and completed verification result.
- **FR-008**: Verify final structural readability, expected page counts and recorded bytes, and a complete gap-free, non-overlapping partition of every original. Verify intact copies are byte-identical. Detect missing, extra, altered or misordered final files before publication and upload.
- **FR-009**: Publish completion only after every original and final file verifies; staging is unavailable to upload consumers. Preserve prior output on conflict, expose rejection reasons, and recover interruptions without accepting partial preparation.
- **FR-010**: Flatten verified final PDFs into the existing serial per-PDF cycle with unique contiguous upload ordinals. Retain original/range lineage in archives and final reports; archive each completed part before its between-file chat reset.
- **FR-011**: Reuse verified artifacts/captures on unchanged retry and reject changes to original bytes/order/policy/output for the same submission. Resume captured final files without resplitting or renumbering.
- **FR-012**: Preserve legacy captured-manifest identity/interpretation, joint mode, saved handoffs and canonical prompts; automatically prepare only newly captured terminal per-PDF subjects.

### Key Entities *(include if feature involves data)*

- **Preparation Policy**: Version, original trigger, terminal limit and halving convention.
- **Original Source**: Selected ordinal, location/name, content fingerprint, retained copy and physical page count.
- **Prepared PDF**: Original relationship, split/intact status, original range, final path/name, page count, fingerprint and upload ordinal.
- **Preparation Inventory**: Policy, ordered original/final records, verification status and identity binding preparation to submission.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All 1, 50, 51, 99, 100, 101, 150, 200 and 1000-page acceptance cases match specified decisions/ranges; every split part has at most 50 pages.
- **SC-002**: Numbered text, scans, diagrams, rotated and blank-page samples retain 100% of original physical pages exactly once and in reading order, with unchanged visible page content.
- **SC-003**: Filename sorting, inventory and observed upload sequence agree for 100% of mixed-source final files; each archive identifies its original/range.
- **SC-004**: All defined invalid/interrupted/altered/conflicting cases cause zero uploads before rejection; unchanged retries retain accepted inventory and file identities.
- **SC-005**: Operators can identify every split part's original and inclusive range from the output folder/report without opening PDFs.

## Assumptions

- Strictly greater than 100 follows the stated threshold. Exactly 100 pages remain intact; the 50+50 example illustrates halving. Optional policy questions were presented; these defaults apply until revised.
- Recursive halving follows the requested loop through both branches: 1000 pages yields 32 parts, rather than twenty 50-page blocks.
- Version 1 uses page count only; configuration, byte/token budgeting and educational quality comparisons are separate features.
- Output lives beside retained originals in private preparation storage, rather than inside the operator's selected input directory.
- Scope is the existing terminal per-PDF submission pipeline and a local preparation command. Laravel intake/projection, legacy joint scripts and provider-capacity redesign are separate work.
- Existing intake and provider protections remain applicable. This local check verifies PDF preparation, not educational coverage.
