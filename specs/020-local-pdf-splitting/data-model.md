# Data Model: PDF Preparation

## PreparationPolicy

`version=1`, `split_trigger_pages=100` with strict > comparison, `max_part_pages=50`, `algorithm=recursive_halving`, `odd_rule=smaller_first`. Fixed values validated on read; configuration is out of scope.

## OriginalSource

`source_ordinal` contiguous starting at 1; `original_path`, exact `original_filename`; `sha256`, `bytes`, `page_count>0`; `retained_original_ref` using existing ArtifactRef; `split_applied = page_count > 100`. Original selection order is significant. Duplicate paths/hashes across originals are rejected as today.

## PreparedPDF

`upload_ordinal` contiguous over all final files; `source_ordinal` foreign key into inventory; `part_ordinal` contiguous within original; `start_page`, `end_page` inclusive physical coordinates; `page_count=end-start+1`; `filename`; `artifact_ref` including path/bytes/sha256. Intact originals have range 1..N and part ordinal 1, even though their filename lacks a range suffix. Each split count <=50; intact count may be 51..100.

Part identity is `(inventory identity, source_ordinal, part_ordinal, start_page, end_page)`, independent of content hash. Equal part bytes are valid. Filenames have padded source prefix and, for split files, padded original range. Raw original filename remains in OriginalSource.

## PreparationInventory

`schema_version=1`, `kind=v3_pdf_preparation`, `state=complete`; policy, ordered `originals`, ordered `prepared_pdfs`, `qpdf_version`, verification summary, `inventory_sha256`. Digest canonical JSON excluding its own digest field; no wall-clock timestamp is required for identity. Artifact paths are relative to package root and must resolve inside it without symlinks/traversal. Package root itself is referred to by the existing terminal ArtifactRef convention for inventory.json.

Validate complete per-original partitions: first start=1, next start=previous end+1, last end=original page_count; every part's count matches the range; aggregate count equals original count. Verify exact final filename set, sort agreement, hashes/bytes and structure/counts. Inventory tracks original bytes separately from prepared bytes.

## Captured integration

`SubjectConfig.pdf_preparation_ref`: optional inventory ArtifactRef relative to terminal root, absent for legacy/joint/saved captures. Inventory defines its package root through its own parent path.

`InputSnapshot.pdf_lineage`: optional object with source ordinal, part ordinal, original start/end and preparation inventory fingerprint. The snapshot ref points to the final prepared PDF. `original_path` stays the operator original location; repeated original_path for different parts is valid. Legacy serialization must omit new fields if absent.

Per-PDF archive and final report add optional preparation ref, original ordinal/name/hash/page count, split status, part ordinal and original range. Verify these values against the referenced inventory and captured config. Existing provider conversation/PDF ordinals remain intact.

## Lifecycle

`selected → retained → inspected → planned → written → verified → complete → captured → uploaded`.

Failures before complete produce local rejection and no upload-visible package. A lock guards a destination; staging is a private sibling. Complete packages are immutable, atomically renamed into place and reusable only after verification. Submission manifest is published after all prepared subjects verify. Resume uses captured refs; retries compare original selection/order/policy and all artifacts. Changed data yields input_changed; inspection/input failure yields input_invalid; missing tool/write/timeout failures are reported locally without external dispatch. Existing safe-code vocabulary is reused.
