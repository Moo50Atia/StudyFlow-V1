# Historical Archive Import

Future command (not run by this documentation task):

```sh
php artisan v3:import-terminal-run \
 --terminal-root /absolute/terminal-root --run-id RUN_UUID \
 --subject SUBJECT_ID --teacher TEACHER_ID --actor ADMIN_ID \
 --import-id UUID --dry-run
```

Remove dry-run only for an explicitly selected destination. Actor must be fresh active admin; teacher active teacher with subject TeacherPermission. Admin does not own imported content. Output only IDs/counts/state/safe codes. Exit0 validated/completed,1 partial/failed verification,2 invalid arguments/config/access,3 queued/pending. No NotebookLM, generator API or live auth calls in any mode.

## Verification

Support current018 schema1 per_pdf archive. Read runs/{run}/subject-config.json, per-PDF archive.json, private turn receipts, lesson_handoffs and scene-prompt-exports/scene-prompts.json. Verify captured run, ordered inputs, complete archived PDF boundaries, raw answer hashes, exact prompt digests/associations, byte counts and every listed file. Export alone does not prove source/map/First lineage. Partial archives may import separately verified archived PDFs, explicitly marking partial; unfinished/uncertain PDFs are not successes.

Reject duplicate identities, invalid types before sorting, path escape, root/descendant symlinks, missing artifacts, unknown schemas and changed bytes. Archive files resolve against run root; accepted_pdf_ref/accepted_steps_ref resolve against terminal root. Snapshot the same parsed bytes, stage exact copies and rehash before commit. Never copy cookies/sessions/full terminal root.

Dry-run makes zero writes/copies/workflow/provider calls. Actual immutable digest covers archive, destination teacher/subject, schema/profile and import-id. Exact repeat returns retained receipt; changed409 import_conflict. Item receipts and deterministic UUIDv5 IDs allow recovery without duplicates. Source archives remain read-only.

## Application records and history

Create terminal_import run, one item/case/lecture per verified PDF, shared historical notebook parent, actual map/lesson/section/prompt records marked imported. Preserve original timestamps privately as historical; created_at is actual import. Deterministically parse raw First with missing-aware wrapper; null/warnings retain missing data. No new model supplies fields. Sections link canonical TeacherSection to legacy Section navigation; do not copy eleven fields into old summary columns.

Prompts default awaiting_manual. If verified source/builds exist, import as historical candidates with real retained generation evidence or explicit imported manual provenance; rerun browser checks, do not invent qualification/approval. Current reference archive has no scenes. No fake queries/dispatches/costs are created to satisfy FKs.

ApplicationImportWorkflow executes local verify/copy/materialize/finalize activities and safe callbacks only. Its history records actual import; prior journal events are historical=true projections linked to retained private evidence. Never alter source owner marker or live terminal run. New application roots differ from source archives.

## Reference target

storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root; run09a59afe-ed26-4567-906b-e42d87d53362. Eight PDFs; lesson counts9,3,9,7,5,8,8,14 sum63; prompts89; generated/built0. Tests use small synthetic archives; final reference dry-run is local retained-data evidence, not a model quality/count promise.
