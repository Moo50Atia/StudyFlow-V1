# Local Data Model and Recovery

All JSON uses UTF-8, `ensure_ascii=False`; digests are lowercase SHA-256 of exact bytes. For canonical identity objects use sorted keys and compact separators. Timestamps are UTC RFC3339. UUID run IDs are local identifiers, not application case IDs. No database tables are created.

## In-memory types

`SceneInput`: `scene_key`, `notebook_id`, `conversation_id`, `lesson_ordinal`, `scene_ordinal`, display `lesson_title`, `section_title`, nullable original `scene_type`, exact `prompt` and `prompt_sha256` (both null for unusable input), `input_error:null|string`, `source_index`, `source_json_pointer`. A nonstring/blank prompt is retained in the original snapshot and normalized as a failed input item, never converted into a prompt string. `scene_key` is `{notebook_id}:{conversation_id}:W{lesson_ordinal}:S{scene_ordinal}`. Original notebook/conversation IDs are nonblank strings; paths never interpolate them. Input pointer examples: `/scenes/0` or `/lessons/0/scenes/0`. Integers reject booleans.

`ProviderConfig`: provider CLI alias/stored ID, requested model, nonsecret endpoint, request timeout, secret key excluded from repr/serialization. `ProviderCheckResult`: boolean passed, safe code, requested/returned model and optional HTTP status. `ProviderResponse`: outcome, safe code, status, requested/returned model, `model_reported`, response ID, finish reason, nullable text, usage `{input_tokens,output_tokens}` with unknown null, raw receipt reference, `request_started_at`, `request_finished_at`, and measured monotonic `duration_ms` (null only when crash recovery cannot establish it). No synthetic cost or JEV fields.

`SceneGenerationRequest` and `HtmlSceneSourceV1` follow [provider](contracts/providers.md) and [static source](contracts/source-package.md) contracts. `BuildResult` identifies build ordinal, status, source digest, materialization outcome, and static manifest reference. There is no npm lockfile, compiler output, or dependency catalog in the current path.

## Output layout

```text
OUTPUT_DIR/
  .run.lock
  run.json                                  immutable run identity and input inventory
  checkpoint.json                           mutable atomic progress projection
  snapshot/
    template.txt                            exact selected template
    inputs/0001.json                        exact original input bytes
  lesson-0001/scene-0001/
    current.json                            successful attempt/build pointer only
    attempt-0001/
      attempt.json                          append-only identity
      provider-body.bin                     exact received bytes, capped when needed
      response-receipt.json                  written last after body fsync
      response.txt                          extracted model text when available
      provider-result.json                   normalized result, no key/headers
      source-package.json                   exact parsed HTML/CSS/JS package representation
      source-manifest.json                   generated file hashes
      project/                              exactly the three generated source files
      builds/build-0001/
        build-report.json
        dist/                               materialized static files
        manifest.json                       successful static output + lineage
```

Directory modes 0700, private evidence files 0600. Tools may create disposable cache files; do not expose this folder through public Laravel storage or git. Do not automatically delete referenced source/receipts/dist/locks/logs. `node_modules` and staging temporary directories are rebuildable caches; removing them is permitted only with the run lock and without deleting evidence. No retention scheduler is added.

## Run identity and checkpoint

`run.json` records schema/run/notebook/conversation identity, selected provider/model/endpoint/timeout, exact template digest/snapshot, input snapshots, selected `scene_inputs`, input issues, static source contract `HtmlCssJsSceneV1`, and qualification provenance `{mode:"owner_waived_terminal_demo",jev_called:false}`. The `scene_inputs` selection is part of run identity. Runtime/compiler/catalog values are not required by this static format.

`--limit` is an invocation control. A selected `--scene-key` set is identity. Environment key rotation is allowed; changing provider/model/endpoint/timeout, template bytes, selected scene set, or any input bytes requires a new output folder. Original absolute input path is evidence only; relocation with identical relative names/bytes and normalized inputs is allowed. Save exact snapshots at creation, then compare current bytes before resume.

`checkpoint.json`: `{schema_version:1,run_id,updated_at,status:"complete"|"partial",items:[{scene_key,status:"queued"|"sending"|"response_saved"|"source_saved"|"building"|"generated"|"failed"|"uncertain",active_attempt:null|positive_integer,successful_build:null|positive_integer,error_stage:null|"input"|"provider"|"package"|"build"|"artifact",error_code:null|string,attempts:[{ordinal,state,receipt_path:null|string,source_manifest_path:null|string,builds:[{ordinal,status,report_path}]}]}],counts:{generated,failed,uncertain,queued,input_issues}}`.

For summaries, queued includes nonterminal local work (`response_saved|source_saved|building`) remaining after an intentional stop; full success requires every item `generated` and zero input issues. Per-input missing prompts can be failed items without attempts. Missing expected Lessons/empty lists are issues, not imaginary scene items. Counts derive from items/issues each save, never incremental counters that duplicate after recovery.

`attempt.json`: immutable `{schema_version:1,run_id,scene_key,ordinal,created_at,prompt_sha256,template_sha256,provider_id,requested_model,endpoint,toolchain_sha256,explicit_retry_of:null|positive_integer}`. Commit it plus checkpoint `sending` before dispatch. Attempt ordinals increase monotonically and no path is reused.

## Receipts and manifests

`response-receipt.json`: `{schema_version:1,attempt_ordinal,http_status:null|integer,complete:boolean,truncated:boolean,body_path,body_bytes,body_sha256,received_at}`. Write body to temporary file, flush/fsync, atomic publish, then write/fsync receipt and directory. Only a complete nontruncated receipt proves a locally retained provider completion. On stream timeout retain partial bytes with `complete=false`; on cap overflow `truncated=true`. Never retain request auth headers.

`source-manifest.json` retains run/scene/attempt/prompt/template/provider refs and sorted HTML/CSS/JavaScript `{path,bytes,sha256}`. `build-report.json` records materialization start/end/duration, safe outcome/code, and file count. It must not claim npm/typecheck/Vite ran.

Successful manifest identifies `kind:"local_static_html_scene"`, run/scene/notebook/conversation/Lesson identity, prompt/template/source-manifest digests, provider/model/response/usage, attempt/build ordinals, `entrypoint:"index.html"`, exact output file hashes, qualification waiver provenance, and verification `{static_files_materialized:true,browser:"not_run",educational_review:"not_run"}`. No claim is made that TypeScript or a production build ran.

Build output can include local binary fonts/assets; hash raw bytes. A separate manual/live report may add browser observations but never rewrite the immutable manifest or upgrade qualification automatically. `current.json` stores only run/scene/attempt/build refs and manifest SHA, and is published after complete manifest verification.

## State and crash recovery

1. `queued -> sending -> response_saved -> source_saved -> building -> generated`. Provider/package/build/artifact failures become `failed`; unknown sends become `uncertain`.
2. On startup hold nonblocking `flock` for the entire mutation/request lifecycle. A second process returns exit 3. A crash releases the OS lock; do not interpret an old lock file as a live owner.
3. Receipt is authoritative after a crash. `sending` plus complete verified receipt is parsed locally. `sending` without such proof becomes `uncertain`, unless it has a retained definitive failure/truncation result. Never silently resend.
4. `response_saved` reparses retained body; `source_saved|building|failed(build)` builds saved project under a new build ordinal. A normal rerun can rebuild failed local builds once per invocation, never call the provider for them.
5. Validate any existing successful build/hash/pointer before skipping. Missing/corrupt evidence produces an artifact failure and no provider resend; retain the broken evidence. `build` may regenerate compiled files from intact source into a new build directory.
6. `--retry-scene` is allowed only for failed provider/package attempts or uncertain sends. Create a new attempt, preserve previous result, use the same captured configuration, and print potential duplicate-provider-work provenance. No automatic retries or fallback.
7. Publish each immutable artifact using exclusive destination and staged atomic rename. If failure occurs after artifact publish before checkpoint, reconcile from matching receipts/manifests. Never overwrite changed bytes. If checkpoint itself is corrupt/unwritable, stop globally with exit 1; do not send another request without durable bookkeeping.
8. Handle interrupt by retaining current evidence, checkpointing if possible and returning 130. Unknown in-flight requests remain uncertain on recovery.
