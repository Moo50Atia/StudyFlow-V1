# Terminal Contract

Entrypoint: `.venv/bin/python scripts/v3/generate_scenes.py`. Subcommands below are future deliverables, not commands executed during this documentation task.

## Commands

| Command | Arguments | Behavior |
|---|---|---|
| `check` | required `--provider`; optional `--model` | One small selected-provider request. No template, input, output folder, build, NotebookLM auth or scene generation. |
| `run` | required `--input PATH --output-dir PATH --provider`; optional `--model`, `--template PATH`, `--template-sha256 SHA`, `--request-timeout SECONDS`, `--limit N`, repeated `--retry-scene SCENE_KEY` | Capture inputs, resume matching state, generate queued/explicitly retried scenes, automatically build sources and summarize. No automatic credential check. |
| `build` | required `--output-dir PATH`; optional repeated `--scene-key SCENE_KEY` | Rebuild intact stored source on existing attempts with failed/pending builds or damaged compiled artifacts. No API calls/key requirements, never replace an earlier immutable build. With no scene selectors, process all eligible saved sources; rehash and skip healthy completed items. |

`--request-timeout`: integer 1–3600, default 300. `--limit`: positive integer; limit new provider sends in this invocation, not total snapshot size. Retained work may be recovered locally outside the limit. Reusing a folder automatically resumes; no separate `--resume` flag. Retries count against limit. Unknown flags/keys and retries of a generated scene fail with configuration error. Explicit retry of a failed/uncertain generation prints `retry_may_duplicate_provider_work <scene_key>` and creates a new attempt; it never deletes previous results. Failed builds must use `build` or normal local resume, not `--retry-scene` to spend another model request.

Default template: project-root `Google-Ai-studio-System-prompts`, exact expected digest `8845fbb5723dea4f52cf4f3acd25b39edf9305cfa0516759137c2bf0a79d0a0c`. A changed default fails. A custom `--template` requires explicit `--template-sha256` and matching UTF-8 bytes, 1–65536 bytes; that command is the local operator's selection of approved bytes. Never edit the template. Resolve default via module/project location, not shell working directory.

`build` takes the existing run lock and checks saved run/source/host hashes plus current scaffold/catalog/Node/npm identity. It reads saved snapshots rather than requiring the original input folder, current root template, provider key or model environment. Toolchain drift returns 2 before changes; corrupt bookkeeping stops with 1. A selected item without intact saved source returns an item failure, never a generation request. Preserve the old build directory, including corrupt bytes, and publish recovered compilation under a new build ordinal. Healthy completed items need no build; an all-skipped valid selection returns 0. Unknown scene selectors return 2 without mutation.

A new `run` accepts a nonexistent/empty output folder (ignoring the held `.run.lock` only), or resumes a valid recorded run. Reject an unrelated nonempty folder with 2, preserving its files. Publish run identity, snapshots and initial queued checkpoint before any request. An interrupted initialization with incomplete run bookkeeping is a storage failure: retain it and use a new output folder; never overwrite it or guess that a send is safe.

## Input

- `--input` is one UTF-8 JSON file or a directory selecting direct children matching `lesson-*.json`, sorted by ordinal after parsing. No recursive traversal of output/checkpoint directories; no reading PDFs or raw Third text to infer more prompts.
- Accept exactly schema version 1 and kinds `notebooklm_multi_pdf_lesson_handoff`, `notebooklm_multi_pdf_lesson_raw_handoff`, `notebooklm_lesson_handoff`. The first two store one Lesson at root; the last stores `lessons[]`.
- Require nonblank `notebook_id`, `conversation_id`, positive nonboolean `lesson_ordinal`; each scene needs positive nonboolean `scene_ordinal`. All input Lessons belong to one notebook/conversation. Duplicate Lesson/scene identities are a global input error even if prompts match; use separate output folders for Subjects/conversations.
- Title precedence: `mapped_title`, `lesson_title`, `notebooklm_title`, `Lesson <ordinal>`. Section display: nonblank `section_title`, else `Scene <ordinal>`. Preserve original optional titles/type labels as metadata. `scene_type` is never gated or parsed.
- Require `scenes` to be an array. Empty Lesson arrays or empty scene lists record a Lesson input issue. Missing/nonstring/blank prompt with valid identity records an item failure and lets other scenes proceed. Malformed JSON, unsupported kind, missing identity or duplicate identities fail the input before any provider call. If there are no usable prompts, return input error without creating a run.
- Preserve all extra original fields in the private input snapshot; send only the Scene prompt to the coding provider. Do not trim prompt text after the nonblank check. If `expected_work_count` exists, require a positive nonboolean integer at least the highest supplied Lesson ordinal; contradictory declared totals in one input set are global input errors. Report absent ordinals in `1..expected_work_count` as issues while processing available prompts. Without a declared total, `complete` means every supplied prompt was generated, not that the Subject/NotebookLM mapping is complete. Do not read a sibling NotebookLM checkpoint to guess totals.
- Resolve input/output paths and reject overlap in either direction (output must not contain input or vice versa); reject symlinked root/input files. Per-input file cap 32 MiB; no hardcoded scene count.

## Progress and exit codes

Stdout lines: `inputs_loaded lessons N scenes M input_issues K`, `run_opened <run_id>`, `scene_sent <scene_key> attempt N`, `scene_source_saved <scene_key>`, `scene_generated <scene_key>`, `scene_failed <scene_key> <code>`, `scene_uncertain <scene_key> <code>`, `scene_skipped <scene_key>`, final `run_summary generated N failed N uncertain N queued N input_issues N status complete|partial`, then `checkpoint <path>`. Do not print prompts, template, provider response bodies, cookies or credentials. An error code may carry HTTP status, never raw provider error text.

| Exit | Meaning |
|---|---|
| 0 | Check passed, full run has all scene items generated and no input issues, or all requested local builds succeeded. |
| 1 | Provider check failed, or run is partial due to failed/uncertain items or input issues, or a global storage/build failure prevented progress. |
| 2 | Invalid arguments/configuration/input/tool prerequisite or changed run identity. Zero new provider calls. |
| 3 | Existing output lock is busy. Zero calls/writes to that run. |
| 4 | Intentional `--limit` stopped sends with queued items remaining and no failed/uncertain items or input issues. |
| 130 | Operator interrupt; durable receipts/artifacts retained, current unfinished send later reconciled as uncertain. |

`check` prints only `provider_check passed|failed <provider_id> <model> <safe_code>` and returns 0/1/2. `build` reports build counts; it does not claim the parent run complete if other scenes remain unresolved. Failed input/configuration leaves an existing run untouched.

## Fixed item error codes

Input items/issues use `input_prompt_missing`, `input_lesson_empty` or `input_lesson_missing`. Provider codes are defined in [providers](providers.md). Source collection uses `package_invalid`. Build codes are `dependency_install_failed`, `source_import_invalid`, `typecheck_failed`, `build_failed`, `build_timeout` or `build_output_invalid`; an earlier failed stage leaves later stages unrun. Evidence checks use `artifact_missing` or `artifact_corrupt`; an inability to commit bookkeeping uses `storage_failed` and stops the run. Store the corresponding stage from the data model and fixed code, with numeric HTTP/exit status in its evidence object. Do not use raw exceptions as codes/messages or silently convert build errors into qualification success.
