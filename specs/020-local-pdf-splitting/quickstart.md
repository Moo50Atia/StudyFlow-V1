# Validation Guide: Local PDF Splitting

This is a future implementation guide. `prepare-pdfs` is proposed and does not exist yet. No provider work is required for local acceptance.

## Prerequisites

Existing project virtualenv, qpdf on PATH, private writable storage, unchanged steps.txt. For upload-cycle acceptance, existing worker environment with provider transport replaced by a recorder. Real-provider execution is a separate operator decision.

## Prepare numbered sources

Use disposable PDFs with visible physical page numbers and counts 1, 50, 51, 99, 100, 101, 150, 200, 1000. Include a small scan/diagram sample, rotated and blank pages. Place sources outside the output package. Existing reportlab/PDF tooling can create these samples during implementation validation; no fixture-generation code is part of this guide.

## Offline command (after implementation)

```bash
.venv/bin/python scripts/v3/run_temporal_pipeline.py prepare-pdfs \
  --pdf-dir /tmp/v3-pdf-source-samples \
  --output /tmp/v3-pdf-prepared-samples --json
```

Expect package `originals/`, `final/`, `inventory.json`. Only `final/` contains upload PDFs. Compare counts/ranges with spec.md US1: 101 → 50/25/26, 150 → 37/38/37/38, 200 → four 50-page parts, 1000 → 32 parts. Exactly 100 remains intact. Use `qpdf --check` and `qpdf --show-npages` on final files and review inventory.

For content QA, inspect visible page numbers and compare rendered original/part pages at matching original positions across text/scan/diagram/rotation/blank cases. Rendering is inspection only. Confirm original hashes and intact-copy hashes are unchanged.

## Ordering and names

Repeat with two sources of the same basename from different directories through repeated `--pdf-file` arguments; include Unicode names. Original order follows arguments; all parts of the first precede the second. Filename sorting of final/ and inventory ordinals must agree. Detect start/end of every split file without opening it. Folder mode keeps existing Unicode original filename order.

## Submission barrier and loop

Use the proposed example `scripts/v3/examples/local_pdf_preparation.json` with new mode/per_pdf, original paths, existing explicit provider/model and proof notebook. In a provider-recording validation environment:

```bash
.venv/bin/python scripts/v3/run_temporal_pipeline.py submit \
  --manifest scripts/v3/examples/local_pdf_preparation.json \
  --pdf-processing per-pdf --export-prompts --json
```

Confirm all preparation completes before admission/provider access; final inputs match inventory order; one active PDF selected for divide; framework added for execution; each completed part archived before reset. Archive/report contain original ranges. Export mode alone does not disable NotebookLM requests: never run this example against live credentials as an offline test.

## Failure and recovery acceptance

- Reject encrypted/zero-page/malformed PDFs, qpdf warnings/tool absence/timeouts and write failure with no external dispatch.
- Delete/alter a recorded part, add an unexpected PDF or corrupt inventory ranges/order; reject before admission.
- Interrupt before publication; no complete inventory is visible. Retry rebuilds only incomplete local staging.
- Launch two preparations for one destination; serialize writers and reuse identical completion; conflict never overwrites prior output.
- Retry unchanged submission: same original inventory, final fingerprints/ordinals and acknowledgement; no regeneration. Change original bytes/order/policy or prepared bytes: input_changed.
- Resume a captured run: captured final refs only. Legacy captures round-trip existing digests and resume without new preparation. Joint/saved behavior and prompt hashes remain intact.
- Select duplicate originals: reject as today. Legitimate byte-identical split parts are accepted as distinct ranges.

Record results against SC-001 through SC-005. Run relevant existing terminal/per-PDF regression checks when implementing, without asserting new tests or live quality from this documentation package.

## Observed Implementation Validation Results

### 1. Serial Loop Consumption & Admission Barrier (T015)
- **Zero Workflow Filesystem Enumeration / Splitting**: In `v3_engine/terminal_pipeline/subject_workflow.py`, `_run_per_pdf_notebook` directly consumes `inp["sources"]` filtered by `role == "educational_pdf"`. No filesystem scanning, qpdf invocation, or splitting work occurs in the Temporal workflow or activity worker during processing.
- **Strict Pre-Admission Barrier**: Automatic preparation occurs in `manifest.py` during submission capture. `cli.py` verifies the preparation package via `verify_preparation` before supervisor admission. Any failure (malformed PDF, encryption, missing inventory) causes local rejection with exit code 2 and an appropriate `SafeCode` without dispatching Temporal workflows or making external provider calls.
- **Source Selection, Archive, and Reset**: The serial loop in `subject_workflow.py` uploads each prepared leaf PDF, isolates its Divide turn, pairs it with framework steps for execution, seals its scoped archive with preparation lineage, and resets server conversation history before uploading the subsequent part.
- **Unchanged Modes and Prompts**: `pdf_processing="joint"` and saved handoff modes bypass preparation completely. Canonical prompts (`COMBINED_RESPONSE_FORMAT_TEXT`, `DIVIDE_PROMPT`, `EXECUTION_PROMPT`) and their hashes remain bit-for-bit identical to prior baseline.

### 2. Offline Validation Matrix (T019)
The offline validation script executed the full synthetic test matrix using ReportLab and qpdf 11.9.0:
- **N = 1, 50, 51, 99, 100 pages**: 1 intact PDF each (`0001--{stem}.pdf`), no range suffix. Retained copy in `originals/` and final copy in `final/` have identical SHA-256 and byte counts.
- **N = 101 pages**: Split into 3 parts: `1–50` (50 pages), `51–75` (25 pages), `76–101` (26 pages).
- **N = 150 pages**: Split into 4 parts: `1–37` (37 pages), `38–75` (38 pages), `76–112` (37 pages), `113–150` (38 pages).
- **N = 200 pages**: Split into 4 parts: `1–50`, `51–100`, `101–150`, `151–200` (50 pages each).
- **N = 1000 pages**: Split into 32 parts of 31 or 32 pages covering `1–1000`.
- **Rotated, Blank & Diagram Pages**: Verified 100% physical page preservation across rotated pages (90° orientation preserved), blank pages, and vector graphics without rendering artifacts.
- **Unicode Filename Handling**: Validated `كتاب_ميكانيكا_اختبار.pdf` (101 pages) with sanitized ASCII stem fallback in `final/` and original Unicode name recorded in `inventory.json`.
- **Explicit Ordering & Disambiguation**: Tested two distinct files sharing the same basename (`lecture.pdf`) via repeated `--pdf-file` flags. Argument order was preserved and outputs were disambiguated into `0001--lecture.pdf` and `0002--lecture.pdf`.
- **Filename Sort Agreement**: All generated filenames in `final/` sort lexicographically in exact agreement with `upload_ordinal` (reading order).
- **Failure & Security Acceptance**:
  - 0-page empty file: Rejected with `SafeCode.input_invalid` / exit code 2.
  - Corrupt PDF: Rejected with `SafeCode.input_invalid` (`qpdf --check` exit code 2) / exit code 2.
  - 256-bit AES encrypted PDF: Rejected with `SafeCode.input_invalid` (`qpdf --is-encrypted` exit code 0) / exit code 2.
  - Overlapping input/output directory: Rejected with `SafeCode.input_invalid` / exit code 2.
  - Conflicting output directory: Altering `final/` contents rejected with `SafeCode.input_invalid` without overwriting prior files.
  - Unchanged retry: Verified existing package reused cleanly with matching `inventory_sha256`.

### 3. Regression Test Executions
- `pytest v3_engine/tests/terminal_pipeline -m 'not temporal and not live and not integration'`:
  - Result: **110 passed, 22 deselected in 4.18s**.
- `pytest --import-mode=importlib v3_engine/tests/terminal_pipeline v3_engine/tests/notebooklm/test_sdk_contract.py v3_engine/tests/scene_generation -m 'not temporal and not live and not integration'`:
  - Result: **269 passed, 1 skipped, 23 deselected in 25.10s**.
  - Skipped: 1 test (`test_live_direct_pipeline.py` requiring live provider credentials, skipped per test isolation rules).
- Live provider tests: Skipped per specification instructions (zero live requests or external uploads).

### 4. Success Criteria Verification
- **SC-001 (Deterministic Part Cap)**: All parts $\le 50$ pages; originals $\le 100$ remain intact.
- **SC-002 (Page Preservation)**: Direct qpdf leaf copy preserved 100% of physical pages without OCR/text transformation.
- **SC-003 (Pre-Admission Verification)**: Verified complete inventory and file fingerprints before supervisor admission or provider dispatch.
- **SC-004 (Retry & Cache Reusability)**: Unchanged submissions reuse verified packages; modified inputs reject with `input_changed` or `input_invalid`.
- **SC-005 (Inspectable Operator Tooling)**: `prepare-pdfs` CLI provides detailed text and JSON outputs for inspection before submission.

