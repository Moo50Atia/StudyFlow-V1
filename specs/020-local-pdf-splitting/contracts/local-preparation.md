# Contract: Local Preparation and Submission

## CLI

Extend the existing entrypoint with:

```text
prepare-pdfs (--pdf-dir PATH | --pdf-file PATH [--pdf-file PATH ...]) --output PATH [--json]
```

Exactly one input selection form. Folder enumeration is nonrecursive, case-insensitive PDF suffix, Unicode filename order. Explicit repeated flags retain supplied order. Output is a preparation package root; final upload folder is PATH/final. Output must be outside the selected source folder and originals, with no symlink traversal. Fixed policy v1; no threshold overrides. This command does not connect to Temporal or any provider.

Success exit 0 returns JSON schema_version=1, outcome=prepared, policy, original_count, prepared_pdf_count, output_dir, final_pdf_dir, inventory_path, inventory_sha256, and ordered original/range/filename summaries. Text output gives folder/counts/ranges. Rejection exit 2 returns outcome=rejected, an existing SafeCode and a local reason. Sensitive content or credentials must not enter logs.

If output exists, verify and reuse unchanged complete data. Reject conflicts without overwrite. Incomplete staging is not success.

## Service and publication

Proposed Python interface in pdf_preparation.py: `prepare_pdf_sources(original_paths, preparation_dir) -> PreparationInventory`; companion `verify_preparation(inventory_path)` and pure `plan_ranges(page_count)`. Inputs are resolved paths in caller-defined order. Service owns private original retention, strict qpdf inspection, direct leaf extraction and package lock/staging/publication.

For L>50, left_end=start+floor(L/2)-1, recurse left then right. N<=100 bypasses splitting entirely. This policy distinguishes trigger from terminal size.

Layout:

```text
PACKAGE/
  originals/               # retained originals; never uploaded
  final/                   # final PDFs only
    0001--Mechanics--pages-0001-0050.pdf
    0001--Mechanics--pages-0051-0075.pdf
    0001--Mechanics--pages-0076-0101.pdf
    0002--Small.pdf
  inventory.json           # complete verified record
```

Inventory fields/invariants are defined in ../data-model.md. Complete-package rename is atomic on the same filesystem. Exact final set and fingerprints verify before reuse/admission; upload checks its accepted file hash immediately before upload. Original and range identity is separate from equal part hashes.

## Submission integration

New per_pdf capture automatically invokes preparation after resolving and validating originals, before effective PDF InputSnapshot construction. Automatic private location: terminal-root/submissions/submission-id/preparation/subject-token. CLI-selected per-pdf or raw `pdf_processing: per_pdf` both qualify. Existing captured manifest lookup precedes new preparation and uses its legacy/new appropriate reuse path.

New captured optional fields: SubjectConfig.pdf_preparation_ref and InputSnapshot.pdf_lineage. Inputs refer only to verified final PDFs plus unchanged framework. All subjects complete local capture before the existing supervisor admission. New lineage is included in canonical captured digest; absence must round-trip unchanged for legacy captures.

Reuse compares raw selected originals to inventory originals, then verifies retained originals/prepared refs. Original input order/policy/bytes and effective bytes are immutable under a submission ID. Provider-bound source rows retain explicit contiguous ordinals and ranged names. Existing `_run_per_pdf_notebook` consumes the list serially. Archives/reports link each final ordinal to original/range, and verify new lineage against inventory.

Joint and saved modes retain their existing paths; legacy captured per_pdf runs are never reinterpreted. Laravel admissions and the legacy joint runner are outside this contract.

## Implementation Reconciliation

Implementation in `v3_engine/terminal_pipeline/pdf_preparation.py`, `manifest.py`, `cli.py`, `pdf_scope.py`, and `activities.py` was reconciled against this contract:
- **CLI Syntax & Arguments**: Exactly matches `prepare-pdfs (--pdf-dir PATH | --pdf-file PATH ...) --output PATH [--json]`. Mutually exclusive input forms enforced.
- **Exit Codes & Rejections**: Returns exit code 0 on successful preparation with schema_version=1 JSON or text summary; returns exit code 2 on local rejection with `safe_code` and message.
- **Service Interfaces**: Implemented `prepare_pdf_sources`, `verify_preparation`, and `plan_ranges` with exact signatures and pure mathematical recursive halving.
- **Layout & Naming**: Package layout strictly adheres to `originals/`, `final/`, and `inventory.json`. Padded ordinals and page ranges match `{source_ordinal:04d}--{stem}.pdf` and `{source_ordinal:04d}--{stem}--pages-{start:04d}-{end:04d}.pdf`. Lexicographical filename order agrees with inventory `upload_ordinal`.
- **Atomic Publication**: Locked staging using `.staging-{name}-{pid}-{uuid}` on the same filesystem before atomic `os.replace`.
- **Submission Barrier**: Capture automatically performs preparation for new `per_pdf` mode. Admission pre-verifies packages. Workflow consumes serial sources without modification. Legacy digests round-trip unchanged due to omitting `None` fields.
- **Deviations**: **Zero deviations**. All behaviors, records, exit codes, and field structures fully comply with this contract.

