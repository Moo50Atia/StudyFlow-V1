# Research: Local PDF Splitting

**Date**: 2026-10-11. Evidence: local repository source, installed qpdf help and one read-only research agent dispatched as required by speckit-plan. No external-provider calls or PDF processing occurred.

## Policy

**Decision**: Original trigger strictly >100; resulting cap <=50; smaller first half for odd ranges. All assumptions are explicit in spec.md.
**Rationale**: Follows the stated threshold and loop through both branches. Halving yields 101 → 50/25/26; 150 → 37/38/37/38; 1000 → 32 leaves of 31/32.
**Alternatives considered**: >=100 trigger would split exactly 100; >50 trigger would split medium originals; consecutive 50-page blocks would yield twenty parts for 1000. None is silently substituted.

## Local writer and inspection

**Decision**: Use installed qpdf through bounded argv subprocesses. `command -v qpdf` resolves `/usr/bin/qpdf`; `qpdf --help=page-selection` documents original page selection.
**Rationale**: `app/Services/V3/Intake/QpdfAdapter.php` already uses qpdf for encryption, structure and page count. Python declares pdfplumber but no dedicated PDF writer. Direct page selection preserves page content without OCR or rendering.
**Alternatives considered**: A new pypdf/pikepdf dependency, extraction/reconstruction or physical intermediate halves. They add dependencies or avoidable transformations/writes.

## Integration and ordering

**Decision**: Prepare before PDF snapshots in `manifest.py:capture_manifest`, before `_handle_submit` connects/admission. Keep splitting out of `subject_workflow.py:_run_per_pdf_notebook` and upload activities.
**Rationale**: Existing loop consumes ordered captured sources, selects each source explicitly, archives and resets between PDFs. Existing enumeration already defines original list/folder order.
**Alternatives considered**: Splitting while uploading, changing prompts, or using legacy `run_multi_pdf_notebooklm.py`. These do not provide a complete local barrier for this per-PDF feature.

## Original identity and retries

**Decision**: Separate retained-original inventory from upload inputs and add optional preparation refs/lineage. Reuse prepared files on retry; retain legacy absent-field serialization.
**Rationale**: `_reuse_existing_capture` currently compares original selection and snapshots one-to-one; expanded inputs invalidate that assumption. Current default-field omissions intentionally preserve digests. Duplicate split bytes can be legitimate, while duplicate originals remain rejected.
**Alternatives considered**: Reinterpreting old inputs, regenerating on resume, or globally rejecting identical part hashes. Each breaks identity or legitimate page coverage.

## Publication and filenames

**Decision**: One atomic preparation package containing `originals/`, `final/` and `inventory.json`; padded source ordinal plus original stem/range. Lock writers, stage on the same filesystem, publish last.
**Rationale**: Directory entries have no intrinsic reading order. Numeric source prefix guarantees filename ordering matches original order even for same basenames. A final-only folder avoids accidentally submitting original plus generated copies.
**Alternatives considered**: Write into selected source folder or rely on unpadded lexical ranges. Both can misidentify/reorder inputs.

## Scope and known limitations

**Decision**: Terminal new per-PDF capture plus local command. Web integration is separate.
**Rationale**: Web `DispatchRunOutbox.php` and `web_pipeline/bridge.py` use admission refs and do not enumerate originals through terminal capture. Extending web would need item expansion/projection work not requested for the current experiment.
**Alternatives considered**: Imply terminal changes automatically alter web uploads. Source inspection disproves that assumption.

Page preservation is measurable; semantic continuity and better lesson counts are not promised by page cuts. Provider source quotas remain an existing operational constraint.

## Tooling and governance

Constitution is an unfilled template; no ratified rules available. No extensions.yml exists. The requested agent-context updater is absent; record its absence and keep unrelated agent context files untouched. These are known tooling limits, not unresolved architecture choices.
