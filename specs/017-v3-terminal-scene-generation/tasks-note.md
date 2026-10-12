# Notes for the Implementation Agent

Read this before [tasks.md](tasks.md), then follow [task-execution-plan.md](task-execution-plan.md). These are fixed decisions and repository traps, not another task list. The requested filename is exactly `tasks-note.md`.

## The actual goal

The operator already has a working terminal NotebookLM run over multiple PDFs. The terminal stage loads saved scene prompts, calls the selected model for each requested scene, stores plain HTML/CSS/JavaScript files, and materializes a local browser artifact. Do not manually rewrite educational prompts or start another NotebookLM run to make a test pass.

Gemini, GLM, DeepSeek and Qwen are API adapters behind one interface. The service orchestrates adapters and storage/building; it is not a giant provider-specific class. Qwen is Alibaba Model Studio, with a configured regional endpoint. The selected model is a user configuration input; do not pick or silently substitute a model. Keys already loaded in `.env` must work through `load_local_env`, including the encoded Gemini fallback. No credential onboarding/browser step belongs here.

## Read the real inputs, not assumed shapes (T001–T003)

Read the current versions of:

- `scripts/v3/run_multi_pdf_notebooklm.py`: `save_lesson`/raw handoff construction, not just its command flags.
- `v3_engine/notebooklm/lesson_archive.py`: archive root has `lessons[]`; multi-PDF files have one Lesson at root.
- `notebooklm_mechanics_8pdf_test/automatic_run_legacy/lesson_handoffs/lesson-001.json`: read privately, do not copy its full educational content into logs/reports or tests. Build tiny test fixtures separately.

Multi-PDF title is `mapped_title`; `lesson_title` is not present in the actual raw files. `scene_type` may be `Comparison | Objects`. First/Second fields and Section title may be absent. None of these blocks generation. Use every nonblank `generated_prompt` verbatim. The source writer already determines which prompts exist; do not invent a maximum/three-scene grouping or run the strict NotebookLM bundle parser again. Missing prompts are reported, not recreated from raw responses.

Source identity is notebook + conversation + Lesson ordinal + Scene ordinal. Display titles are metadata, never directory names. Use numeric path segments so Arabic, long paragraph titles, punctuation, and duplicate display names cannot collide. Preserve the original Lesson title and Scene title in manifests even when display fallback is needed.

## The template and HTML/CSS/JavaScript contract (current owner direction)

The owner changed the local output requirement. Section 12 of `Google-Ai-studio-System-prompts` now requires plain HTML, CSS, and JavaScript with exactly `index.html`, `styles.css`, and `script.js`. Do not generate React, TypeScript, JSX, package manifests, or external dependencies.

The runner persists the three files into an immutable attempt, materializes them into the build's `dist` directory, hashes them, and publishes the current pointer. Local recovery repeats materialization only; it makes no provider call.

Keep this terminal HTML bundle parser local. Do not change production authoring code or Spec 011's package contract.

The old live-provider test passes `(api_key, model, prompt, template)` to functions expecting `(model, api_key, template, prompt)` and assumes a dict where a tuple is returned. It is not a live check you can reuse. New APIs use dataclasses/keyword arguments with explicit contract tests.

Never turn type/JSON/build failures into a JEV rejection, or mark them successful because JEV is waived. Save the actual returned text before parsing. A broken item must leave a useful response/source/log trail and permit the next Scene to run.

## Builds are a controlled local stage (T010–T015)

Keep the root Laravel `package.json`, lockfile, and Vite config untouched. This terminal static path installs no dependencies and executes no model-generated code during materialization.

The static materializer accepts only the three fixed filenames and does not execute generated JavaScript. Do not rewrite the prompt's scientific rules or generated source automatically.

Use isolated process groups for owned install/typecheck/build commands. On timeout/interrupt, stop those children, retain measured duration and exit evidence, and continue/exit according to contract. Never kill unrelated Brave, Docker, Laravel or worker processes. Do not leave a development server running after verification.

Compiled output can include binary fonts; its local manifest hashes those bytes. It is not an old `ScenePackageV1`. A successful `dist/index.html` should be served through local HTTP; `file://` and raw TSX are not the verification path. Actual dependency install/build may fail, and real fixture gates must catch this instead of using a fake compiler result.

## Preserve receipts and avoid repeat charges (T008–T019)

Save `sending` before HTTP and a complete body/receipt before interpretation. A timeout or a crash with no complete receipt cannot prove no provider work occurred. Keep it uncertain and require explicit Scene retry, appending a new attempt and warning that the provider may have processed the first request. Do not implement generic HTTP retries or automatic fallback.

When raw receipt already exists, recover from it locally. When generated sources exist, rebuild locally. When successful artifacts exist, rehash and skip. A corrupt/unwritable checkpoint is a global stop because continuing could lose receipt accounting. Failure in one Scene's model/package/build stage is a per-item error and the batch continues.

Take `flock` before reading/mutating an existing run, compare identity before sends, and hold the lock through API/build/checkpoint work. A stale lock file is harmless; the OS lock is authoritative. Race tests must use actual concurrent processes and a counting fake server, not sequential calls or two mock lock objects.

For T018, start the owner child, wait until the counting server confirms one request and holds its reply, then start the contender against the same output. Assert exit 3 while the owner is still waiting, release the reply, and assert one send/artifact owner. Repeat with an owner killed during that wait to prove lock release and uncertain recovery. Test-only child helpers may inject a local HTTP fake provider/builder into the real service/store; do not add an insecure endpoint override or testing flags to the production CLI. Separate-root children must actually overlap at the server, not merely run consecutively.

The same output folder pins model/provider/endpoint/timeout, template/input bytes and toolchain. Changing these requires a new folder. New keys for the same configured provider may be read without changing identity. `--limit` restricts new provider sends in that invocation; it does not turn the remaining queued Scenes into success.

## Waiver and evidence (T012–T022)

All supplied prompts are eligible for this local demo. Do not call JEV or wait for its key. Store `qualification.mode=owner_waived_terminal_demo` and `jev_called=false`; do not fabricate a vendor result or write Score=100 to production tables. A compiled artifact is not automatically safe, scientifically correct, accessible or approved by a teacher.

Tests with model/HTTP/compiler doubles prove their declared boundaries only. Real npm/TypeScript/Vite tests, concurrent processes, a local browser fixture and a live selected-provider sample are separate gates. The local browser fixture may use the existing Python Playwright installation; pin test-only provisioning in `browser-requirements.txt`. A missing browser/key is a skip and an open gate. It cannot close T020/T021 or establish a tested full pipeline.

Final report should give safe references/counts/hashes and commands, not raw prompts/code/keys. Leave the existing application's current-pass resolver, provider settings, qualifications, review and owner boundaries intact. Future web integration must explicitly implement [downstream obligations](contracts/downstream-integration.md), rather than importing local manifests as passing database candidates.
