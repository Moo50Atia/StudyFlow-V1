# Implementation Report: Terminal Scene Generation (Spec 017)

**Feature:** `specs/017-v3-terminal-scene-generation`  
**Date:** 2026-10-09  
**Status:** Complete (All Gates Verified and Passing)

---

## 1. Baseline and Prerequisites (T001)

### Workspace State
- Active Feature Selector: `specs/017-v3-terminal-scene-generation` (in `.specify/feature.json`)
- Pre-existing uncommitted changes preserved (all modified/untracked files from earlier tasks/specs untouched).
- Current repository status:
  - Branch/Working directory: `/home/moahmmed/college_project`
  - Unrelated work in `app/Services/V3/`, `specs/016-...`, `v3_engine/notebooklm/` preserved without modification or reset.

### Prerequisites & Toolchain Versions
- **Python**: `3.12.3` (`.venv/bin/python`)
- **Node.js**: `v24.20.0`
- **npm**: `11.19.0`
- **Playwright**: `1.63.0`
- **Default Scene Template**: `Google-Ai-studio-System-prompts`
  - Byte count: 15,960 bytes
  - SHA-256: `8845fbb5723dea4f52cf4f3acd25b39edf9305cfa0516759137c2bf0a79d0a0c` (matches exact required digest)

### Focused Baseline Test Execution
Command:
```bash
.venv/bin/python -m pytest -c v3_engine/pyproject.toml \
  v3_engine/tests/authoring/test_provider_wire.py \
  v3_engine/tests/authoring/test_package.py \
  v3_engine/tests/notebooklm/test_lesson_archive.py \
  v3_engine/tests/test_local_env.py -m 'not live' -q
```
Result:
- **27 passed in 0.54s**
- Baseline failures: 0 (all existing regression tests passing).

---

## 2. Phase 1 — Foundations & Data Models (T001–T003)

### Implemented Modules
- `v3_engine/scene_generation/contracts.py`:
  - `SceneInput`: Immutable identity (`scene_key` as `{notebook_id}:{conversation_id}:W{lesson_ordinal}:S{scene_ordinal}`), prompt, ordinals, mapped titles.
  - `ProviderConfig`: Non-secret representation, timeout, model, endpoint.
  - `ProviderResponse` & `ProviderCheckResult`: Normalized results, safe error codes, usage tokens, raw receipts.
- `v3_engine/scene_generation/config.py`:
  - Provider environment resolution for `gemini`, `glm`, `deepseek`, `qwen`.
  - Base URL validation with HTTPS enforcement (allowing `127.0.0.1` and `localhost` for local mock doubles).
  - Pinned template resolver checking exact 15,960 byte digest `8845fbb5...`.
- `v3_engine/scene_generation/handoff.py`:
  - Enumerate inputs from single JSON files or directories sorted by ordinal (`lesson-*.json`).
  - Supports all three formats: `notebooklm_multi_pdf_lesson_raw_handoff`, `notebooklm_multi_pdf_lesson_handoff`, and `notebooklm_lesson_handoff`.
  - Enforces title precedence (`mapped_title` -> `lesson_title` -> `notebooklm_title` -> `Lesson <ordinal>`).

### Verification Evidence
- `test_config.py` & `test_handoff.py`: 21 passed.

---

## 3. Phase 2 — US2: Provider Adapters & Check Subcommand (T004–T007)

### Implemented Modules
- `v3_engine/scene_generation/providers.py`:
  - Abstract base `AIProvider` and concrete implementations `GeminiProvider`, `GLMProvider`, `DeepSeekProvider`, `QwenProvider`.
  - Enforces sink-before-parse: streaming raw body is committed to raw receipt sink before schema validation.
  - Strips thinking/reasoning parts from Chat models; collects Gemini non-thought parts cleanly.
  - Normalized error classifications: `provider_auth` (401/403), `provider_rate_limit` (429), `provider_request` (4xx), `provider_uncertain` (5xx/timeout), `provider_connection`.
- `v3_engine/scene_generation/cli.py` & `scripts/v3/generate_scenes.py`:
  - `check` subcommand: Sends single small check request (`Reply exactly: V3_PROVIDER_OK`), no template/handoff required, prints sanitized `provider_check passed|failed <provider_id> <model> <safe_code>`.

### Verification Evidence
- `test_providers.py`: 12 passed.
- Real provider check execution:
  ```bash
  .venv/bin/python scripts/v3/generate_scenes.py check --provider gemini --model gemini-2.5-flash
  # Output: provider_check passed gemini_api gemini-2.5-flash ok (Exit code 0)
  ```

---

## 4. Phase 3 — US1: Source Packaging, Storage & Local Builds (T008–T015)

### Implemented Modules
- `v3_engine/scene_generation/source_package.py`:
  - `ReactSceneSourceV1` parser/validator: Extracts JSON within markdown fences, validates required `src/Scene.tsx`, rejects path escapes (`..`), hidden files, Windows separators, disallowed extensions, and unapproved dependencies.
- `v3_engine/scene_generation/artifact_store.py`:
  - Per-directory non-blocking `fcntl.flock` (`.run.lock`).
  - Atomic staged writes (`_atomic_write_bytes`, `_atomic_write_json`) preventing partial corruption.
  - Immutable attempts: `attempt.json`, `response-receipt.json`, `response.txt`, `source-package.json`, `source-manifest.json`, `manifest.json`.
  - Atomic checkpoints with accurate counts (`generated`, `failed`, `uncertain`, `queued`, `input_issues`).
- `v3_engine/scene_generation/builder.py`:
  - Isolated subprocess builder with sanitized environment (allowlist: `PATH`, `HOME`, `USER`, `TMPDIR`, etc.).
  - Pinned scaffold in `v3_engine/scene_generation/scaffold/`: `package-catalog.json`, `index.html`, `main.tsx`, `vite.config.mjs`, `tsconfig.json`, `validate-source.mjs`.
  - Synthesizes `package.json` with only selected dependencies; validates AST syntax with local TypeScript compiler; executes Vite build to produce production bundle.
- `v3_engine/scene_generation/service.py`:
  - `SceneGenerationService`: Injected orchestration coordinating handoff inventory, provider, builder, and artifact store. Continues on item failures; respects limits without corrupting state.

### Verification Evidence
- `test_source_package.py`, `test_store.py`, `test_builder.py`, `test_local_build.py`, `test_service.py`, `test_cli.py`: All passed cleanly.

---

## 5. Phase 4 — US3: Resumption & Concurrency (T016–T019)

### Implemented Modules
- Resumption and state reconciliation:
  - Healthy compiled-artifact skip: Verifies intact `manifest.json` and file SHA256 hashes in `dist/`. Healthy scenes are skipped (`scene_build_skipped`) with zero rebuild overhead and zero model requests.
  - Corrupted dist recovery: If files in `dist/` are damaged or modified, automatically recompiles under a new build ordinal (`build-0002`), preserving historical build directories.
  - Toolchain drift detection: Checks recorded `node_version` and `catalog_sha256` against current host. Rejects drifted runs with exit code 2 before modifying files.
  - `--retry-scene` validation: Prohibits retrying already generated scenes (exit 2) or unknown keys (exit 2). When retrying failed/uncertain scenes, prints `retry_may_duplicate_provider_work <scene_key>` and records a new attempt while preserving prior history.
- Concurrency and lock enforcement:
  - Multi-process mutual exclusion with a live HTTP synchronization barrier: Process 1 holds lock in-flight; Process 2 attempting to run against the same directory immediately aborts with exit code 3 (`lock_busy`) and makes zero network requests.
  - Killed-owner recovery: When Process 1 is forcefully terminated (`SIGKILL`), the OS kernel releases `flock`, allowing Process 2 to start cleanly without stale lock errors.
  - Independent directories run simultaneously without interference.

### Verification Evidence
- `test_resume.py`: 9 passed.
- `test_concurrency.py`: 3 passed.

---

## 6. Phase 5 — Executable Gates & Live Verification (T020–T022)

### 1. Browser Smoke Gate (T020)
- **Specification:** Headless Playwright 1.63.0 interaction with a real local build served over local HTTP.
- **Command:**
  ```bash
  .venv/bin/python -m pytest -c v3_engine/pyproject.toml \
    v3_engine/tests/scene_generation/test_browser_smoke.py -v --run-browser-ui
  ```
- **Result:** **PASSED in 14.17s**
- **Evidence:**
  - Component compiled via `SceneBuilder`.
  - Dist files served via local HTTP server (`http://127.0.0.1:<port>`).
  - Headless Chromium launched cleanly.
  - Playwright clicked the interactive counter button `#btn-count`, verified counter changed from `"Current Count: 0"` to `"Current Count: 1"`.
  - Zero unhandled page errors or console errors occurred.

### 2. Live API Gate (T021)
- **Specification:** Opted-in single real live provider request, response capture, source generation, and local Vite compilation.
- **Command:**
  ```bash
  V3_SCENE_LIVE=1 V3_LIVE_MODEL=gemini-2.5-flash .venv/bin/python -m pytest -c v3_engine/pyproject.toml \
    v3_engine/tests/scene_generation/test_live_scene.py -v -m live --run-live -s
  ```
- **Result:** **PASSED in 54.01s**
- **Evidence:**
  - Provider: `gemini_api`
  - Model: `gemini-2.5-flash`
  - Prompt: `"Create an interactive 2D balance scale comparing weights using pure SVG and React."`
  - Scene key: `live-nb:live-conv:W1:S1`
  - Raw receipt saved to: `live_scene_output/lesson-0001/scene-0001/attempt-0001/response-receipt.json`
  - React source saved to: `live_scene_output/lesson-0001/scene-0001/attempt-0001/project/src/Scene.tsx`
  - Local compilation succeeded: `live_scene_output/lesson-0001/scene-0001/attempt-0001/builds/build-0001/dist/index.html` (2 files compiled)
  - Provenance recorded: `qualification.mode = "owner_waived_terminal_demo"`, `qualification.jev_called = False`.
  - Missing providers (`glm`, `deepseek`, `qwen`) skip honestly when unconfigured; no false claims of live execution.

### 3. Full Regression Summary
- Unit, contract, storage, builder, resume, concurrency suite:
  ```bash
  .venv/bin/python -m pytest -c v3_engine/pyproject.toml \
    v3_engine/tests/scene_generation/ -q -m "not live and not browser_ui"
  # Output: 86 passed, 2 deselected in 94.99s
  ```
- Browser smoke test: **1 passed** (`--run-browser-ui`)
- Live model test: **1 passed** (`-m live --run-live`)
- **Total Tests Passing:** **88 passed** (100% pass rate).

---

## 7. Compliance and Scope Verification

1. **JEV Evaluation Waiver:**
   - Recorded truthfully as `owner_waived_terminal_demo` with `jev_called: False`. No vendor scores or qualification decisions were fabricated.
2. **Template Integrity:**
   - `Google-Ai-studio-System-prompts` remains unchanged with exact digest `8845fbb5723dea4f52cf4f3acd25b39edf9305cfa0516759137c2bf0a79d0a0c`.
3. **Architecture Boundaries:**
   - No Laravel routes, web controllers, migrations, or database tables were modified or introduced.
   - Root `package.json` and package lockfiles remain completely untouched.
   - Spec 011 static packages remain intact and unaltered.
   - All pre-existing modified/untracked files in the workspace were carefully preserved.
4. **Execution Cleanup:**
   - All background tasks, child processes, and temporary test servers were terminated cleanly.
# Phase 6 Convergence Baseline (2026-10-09)

This section records the baseline for repair tasks T023–T048. The prior report and reviewer results are retained historical evidence; this focused command was run at T023 before convergence implementation.

- Command: `.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests/scene_generation --ignore=v3_engine/tests/scene_generation/test_local_build.py -m 'not live and not browser_ui and not temporal and not integration' -q`
- Result: **84 passed, 2 deselected in 107.34s** (non-live scene-generation lane; the local-build test was intentionally excluded).
- `git diff --check`: passed at baseline.
- Existing workspace: many changes were present in V3 NotebookLM/Laravel files, Specs 016–018, scene-generation and terminal-pipeline paths, scripts and the operator's mechanics test directory. Those changes were not created by convergence and must remain protected.
- Shared implementation paths changed while this repair phase was being prepared. The current service contains new step methods alongside the original `execute_run`; source hashes and task behavior must be inspected again before each overlapping edit. Pre-existing changes are not proof that a reviewer case is fixed.
- T023 source snapshot SHA-256 (captured before convergence regression tests): `service.py` `b9a8ded8df805b91b54aa5ba9bcfc5e8ad9a7e4dcbaefb7f3c33c08a44ae6e7b`; `artifact_store.py` `83939c6c2b043180d4aac605507fab9dd606562845fcab86d46558d2c2fcbb3d`; `builder.py` `362d94541570a8568662acdd89c725ed167cd319cf7921c0ea6060b926bf7b25`; `cli.py` `1134893bc7c84cb3c9d033c0bbcd4e438013b0071c8f7dff9582a3ea028502f4`; `providers.py` `d1596555735ac184677ed3b1f75a16bb81a50c61a9767c4016d2a228b43054eb`; `handoff.py` `b4cd8262ec10b2aed2a2a538141dea91293a9ff507d1af2cf88bdecd3afee495`.
- Review findings R01–R22 were not rerun individually before this baseline; their reproduction details are attributed to the supplied reviewer. Convergence regression tests will distinguish reproduced-before/fixed-after behavior per case. No provider calls, browser launches, live API requests or root application tests were run for this baseline.

## 8. Phase 6 Convergence Implementation (T024–T048)

This section records the convergence repair performed after the supplied 22-comment review. The fixes stay within Spec 017's terminal scene generation paths and tests. The prior report above remains historical evidence; its live-provider result was not repeated during this repair.

### Changes made

| Findings | Implementation and evidence |
|---|---|
| R01–R02 | `service.py` and `artifact_store.py` now reconcile only complete, hash-verified provider receipts, classify unproven sends as uncertain, and route retained source/build failures to a new local build ordinal. A rerun cannot silently purchase another provider attempt for `sending`, `response_saved`, `source_saved`, or build failure. `test_service.py` asserts stored receipt variants, send counts, attempts, and local recovery. |
| R03–R05, R21–R22 | Durable reservations account for failed, interrupted, and orphan build directories; source/current manifests and compiled files are hash-checked; damaged compiled output can be rebuilt only from verified source. Pointer JSON is staged, flushed and atomically replaced. Input snapshot bytes are checked against the hashes parsed by the inventory loader. `test_store.py`, `test_service.py`, `test_builder.py`, `test_cli.py`, and `test_concurrency.py` cover ordinal allocation, damaged evidence, publication failure, input mutation, and competing processes. |
| R06–R08 | Build manifests derive source digest and provider response/model/usage from committed receipts. Rebuild inputs restore lesson title, section title, scene type, prompt and prompt digest from the captured run snapshot. Complete Node/npm/scaffold identity is checked before resume. The connected gate verifies manifest lineage/usage; `test_cli.py` verifies restored scene metadata and prompt at the build boundary. |
| R09–R12 | Missing or unsupported Node/npm/scaffold prerequisites fail before run publication or paid provider work. Both adapter families stream responses under the 8 MiB bound and retain partial bytes on stream failure; strict response decoding rejects malformed structures, multiple choices and abnormal finish reasons. `test_cli.py` and `test_providers.py` cover no-send preflight, truncation, byte caps, partial receipts, malformed JSON shapes and provider checks. |
| R13–R20 | The AST validator inspects every allowed source path and uses path-relative containment; generated packages reject bypass directories. Handoff parsing rejects duplicate lesson identities and validates lesson structures before sorting. CLI checks symlink roots before resolution, explicit build selections fail when source is absent, limits must be positive, and configuration errors do not echo secret-bearing endpoint URLs. Tests cover the real JavaScript validator and CLI/parser boundaries. |
| R14/T047 regression additions | `test_local_build.py` now performs a real failed build followed by successful local recovery on build ordinal 2, with no second provider call. It also verifies source and lockfile mutation detection through the real store. `test_service.py` covers corrupted dist, pointer, and manifest recovery. |

**Files changed for this repair:** `v3_engine/scene_generation/{artifact_store.py,builder.py,cli.py,handoff.py,providers.py,service.py,source_package.py,scaffold/validate-source.mjs}` and the corresponding `v3_engine/tests/scene_generation/` provider, store, builder, service, CLI, handoff, package, local-build, concurrency, connected-repair, and browser fixtures/tests. `specs/017-v3-terminal-scene-generation/tasks.md` records T024–T045 as complete based on their regression evidence, updates the real AST test path, and leaves T046–T048 open. No other numbered spec or application/Laravel file was edited for this convergence work.

### Verification run during convergence

| Command / boundary | Result |
|---|---|
| `.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests/scene_generation --ignore=v3_engine/tests/scene_generation/test_local_build.py --ignore=v3_engine/tests/scene_generation/test_source_package.py -m 'not live and not browser_ui and not temporal and not integration' -q --tb=short` | **110 passed, 2 deselected in 117.81s.** |
| `.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests/scene_generation/test_source_package.py v3_engine/tests/scene_generation/test_local_build.py v3_engine/tests/scene_generation/test_concurrency.py v3_engine/tests/scene_generation/test_repair_connected.py --run-integration -m 'not live and not browser_ui and not temporal' -q --tb=short` | **28 passed in 61.09s.** Includes installed TypeScript AST checks, real local npm/Vite builds, two-process reservation coverage and the connected fake-provider CLI→store→builder path. No external provider was called. |
| `.venv/bin/python -m pytest -c v3_engine/pyproject.toml v3_engine/tests/scene_generation/test_browser_smoke.py --run-browser-ui -m browser_ui -q --tb=short` | **1 passed in 10.18s.** Local browser fixture interacted successfully. |
| Adjacent provider/package/lesson-archive/local-env regression command from Phase 6 task plan | **27 passed in 1.70s.** |
| `git diff --check` | Passed at the check performed after implementation edits. |

The downstream `v3_engine/tests/terminal_pipeline/` compatibility lane was also attempted and **did not pass**: its first full run reported 75 passed, 11 skipped, and 5 failed. The failures include tests importing obsolete/missing handoff APIs (`HandoffInventory.scenes`, `capture_submission_manifest`), an empty-handoff expectation that conflicts with the current loader contract, and a build-recovery fixture without the receipt/source/toolchain evidence now required by Spec 017. A later direct rerun of that build-recovery test stopped earlier with `NameError: hashlib is not defined` in the Spec 018 test file. Those tests/files belong to the downstream feature and were not edited here. This is an unresolved compatibility gate; it is not evidence that Spec 018 runtime works.

### Task and acceptance coverage

- T024–T045 are checked in `tasks.md`; the regression mapping is R01–R22 → RG01–RG22 → implementation task T024–T045. Coverage uses stored receipt states, actual CLI and provider decoder paths, real file hashes, a real source validator and local builds, and concurrent processes rather than implementation-only mocks.
- T046 remains open. The connected fake-provider/build/rerun and process-level reservation tests pass, but the task's complete fault-injection matrix across every send, receipt, source, build, pointer, and checkpoint crash boundary has not been executed as one connected gate.
- T047 remains open because its Spec 018 compatibility run failed as described above. Its Spec 017 non-live, AST/build, concurrency, browser, and adjacent regression lanes passed. The previous report's live provider evidence is historical; no live provider call was made in this convergence repair.
- T048 remains open because T046/T047 are open and downstream compatibility is unresolved. Do not accept Spec 017 as a fully converged dependency for Spec 018 until those items have been resolved and rerun.

### Boundaries and remaining risk

No paid or live AI-provider request, JEV evaluation, Laravel mutation, NotebookLM session, or Temporal server was used for the convergence checks. Test transports were explicitly local fakes; real npm/Vite and browser checks were local. The failure in the adjacent Spec 018 test lane is intentionally reported rather than hidden or fixed by editing another spec. No commit, reset, branch change, deployment, or process cleanup outside this task's own tests was performed.
