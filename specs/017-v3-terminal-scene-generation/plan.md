# Implementation Plan: Terminal Scene Generation

**Feature**: 017 | **Date**: 2026-10-09 | **Spec**: [spec.md](spec.md)

## Summary

Build a Python library plus thin terminal entrypoint over existing NotebookLM handoffs. One selected API generates each Scene's HTML, CSS, and browser JavaScript. The runner materializes static files locally, records integrity hashes, and does not run npm, TypeScript, or Vite. Append-only attempt directories and atomic JSON checkpoints permit recovery without automatically repeating paid requests.

The owner later directed this local terminal stage to use `HtmlSceneSourceV1`. Do not change 011's parser/transport/settings to fit this experiment.

## Technical Context

- Python >=3.12; existing `httpx`, `python-dotenv`, `pytest`, `pytest-asyncio`. No new Python SDK required.
- Linux CLI; `fcntl.flock` for one writer. No Node/npm build toolchain is required for the current static scene output.
- Storage: caller-selected local private folder, UTF-8 JSON, raw receipts, source projects and static builds. No database/schema/migration.
- API transport: Gemini generateContent; shared OpenAI-shaped Chat transport for GLM/DeepSeek/Alibaba Qwen. Explicit credentials/models, no generation health check, fallback or automatic request retries.
- Processing: one provider request at a time per run, one generation request per initial Scene attempt, configurable timeout default 300 seconds, local static materialization with no subprocess.
- Volume: all prompt records in one notebook/conversation; no imposed Scene count. Separate Subjects use separate output folders.
- Scope: local generation and compilation; qualified candidate ingestion, browser qualification, teacher review and application rollout remain later work.

## Constitution and Instructions

`.specify/memory/constitution.md` is an unfilled template and contributes no ratified principles. No ancestor/root `AGENTS.md` applies to this spec; `.agents/AGENTS.md` is scoped to the skills directory. The user's explicit specification request and earlier approved decisions govern this documentation task. No extension hooks are configured. Do not modify that constitution or application instructions.

## Architecture and fixed deliverables

```text
scripts/v3/generate_scenes.py                    thin entrypoint
v3_engine/scene_generation/
  __init__.py
  contracts.py                                 shared dataclasses/enums/ABC
  config.py                                    selected provider/template/environment
  providers.py                                 factory + four concrete adapters
  handoff.py                                   three input kinds -> SceneInput
  source_package.py                            HtmlSceneSourceV1 collector
  artifact_store.py                            receipts, checkpoint, manifest, flock
  builder.py                                   static file materialization and hashing
  service.py                                   SceneGenerationService
  cli.py                                       check/run/build arguments and progress
  scaffold/
v3_engine/tests/scene_generation/
  conftest.py
  test_config.py
  test_handoff.py
  test_providers.py
  test_source_package.py
  test_store.py
  test_builder.py
  test_service.py
  test_resume.py
  test_concurrency.py
  test_cli.py
  test_live_scene.py
  browser-requirements.txt                       test-only Playwright provisioning
  fixtures/                                    tiny handoffs and API responses
```

`SceneGenerationService` receives injected `AIProvider`, `SceneArtifactStore`, and `SceneBuilder`; it does not read environment, parse CLI arguments, or import Laravel/Temporal clients. The CLI reads configuration and supplies dependencies. `AIProvider` is a Python abstract base class with `check_connection` and `generate_scene`; callers do not select providers through conditionals inside the service. Factory selection happens once. All exact signatures are in [provider contract](contracts/providers.md).

### Reuse and boundaries

- Reuse `load_local_env` to read existing `.env` and its encoded Gemini key; do not rewrite credentials or launch browsers. Extend `.env.example` with labels only.
- Use the actual wire shapes in `v3_engine/authoring/providers.py` as transport evidence, but keep this terminal response contract and parser local. New adapters share an OpenAI Chat transport for three providers and a Gemini transport.
- Use `scripts/v3/run_multi_pdf_notebooklm.py` and `v3_engine/notebooklm/lesson_archive.py` as read-only format authorities. Do not restart/requery NotebookLM or change their writers.
- Do not alter root `package.json`/lockfile. The current terminal static scene path installs no packages and runs no generated source during materialization.
- Do not change generated scene files to repair content or behavior. Retain failures and continue. No model repair loop is included.

## Processing order

1. Validate selected configuration, template digest, input identity, output-path separation and scene selection without a provider call. `check` needs only provider configuration.
2. Acquire output lock, capture/compare run identity and immutable input snapshot, recover any complete response receipts, rehash completed artifacts.
3. For each eligible item in ordinal order, commit a `sending` attempt before its one provider request. Persist raw body/receipt first, then normalized output/source.
4. Parse the exact three-file response, retain the source, materialize the fixed filenames under a temporary build directory, hash each file, then publish the manifest and current pointer atomically.
5. Update checkpoint after every boundary. Record item errors and continue; stop on a global inability to record evidence. Summarize counts and return defined exit status.

## Validation strategy

Tests cover exact HTML/CSS/JavaScript shapes, provider wire behavior, retained receipts, one-scene selection, integrity hashing, recovery, privacy, and locking across real concurrent processes. Live API and browser observation are separate gates; a successful static materialization does not prove scene interaction works. [Task execution plan](task-execution-plan.md) specifies commands and checkpoints; [acceptance matrix](contracts/acceptance-matrix.md) maps A01–A20.

## Specification outputs

[Research](research.md), [data model](data-model.md), [CLI](contracts/cli.md), [providers](contracts/providers.md), [source/build](contracts/source-package.md), [downstream contract](contracts/downstream-integration.md), [tasks](tasks.md), [tasks-note.md](tasks-note.md), [execution plan](task-execution-plan.md), [quickstart](quickstart.md) and [checklist](checklists/requirements.md).
