# Feature Specification: Terminal Scene Generation

**Feature**: `017-v3-terminal-scene-generation`

**Created**: 2026-10-09

**Status**: Designed; implementation and live verification pending. No feature branch created.

**Input**: Generate interactive scenes from existing NotebookLM JSON handoffs using one selected Gemini, GLM, DeepSeek, or Qwen API. The current owner direction uses plain HTML, CSS, and JavaScript, saves each scene against its Lesson, and materializes static browser files locally.

## User Scenarios & Testing

### User Story 1 — Generate all saved scene prompts (P1)

The local operator selects a handoff file or folder, an output folder, and one provider. The command generates each selected scene, saving the original response, HTML/CSS/JavaScript files, static files, and Lesson identity without manual prompt copying.

**Independent test**: A two-Lesson input with different scene counts produces one linked output per prompt through an injected provider and a real local build. A selected live-provider sample establishes separate remote evidence.

1. **A01** Given either existing handoff format, generation enumerates every nonblank `generated_prompt` in order, retaining Lesson and scene identity.
2. **A02** Arbitrary scene-type labels and absent First/Second fields are accepted without judging educational content or type.
3. **A03** A provider success uses exact template/prompt bytes, retains the response, and links its HTML/CSS/JavaScript source and static output to the original Lesson.
4. **A04** SVG and spatial 3D source packages build using trusted scaffolding and only selected renderer dependencies.
5. **A05** Malformed output or one failed build is retained as an item failure; later scenes still run.

### User Story 2 — Select and check an API provider (P1)

The operator selects exactly one provider per run and can independently verify its configured key/model through one small chat request.

**Independent test**: HTTP fixtures exercise all four adapter boundaries and CLI selection; a credential check is separate from generation.

1. **A06** Only the selected adapter receives generation requests; there is no fallback or subscription/CLI account adapter.
2. **A07** `check` performs one small chat request, prints no credentials, and creates no scene run.
3. **A08** Missing key/model or invalid endpoint configuration causes an error before any provider request.
4. **A09** Refusal, truncation, multiple content parts, timeout, and malformed provider JSON yield truthful normalized evidence and sanitized errors.

### User Story 3 — Recover a long run (P1)

The operator reruns the command after interruption. Completed artifacts are verified and skipped, retained source is rebuilt locally, and unknown provider sends are not automatically repeated.

**Independent test**: Crash injection and two actual processes against one output folder prove recovery and locking.

1. **A10** Matching completed artifacts cause zero new calls and retain valid hashes/lineage.
2. **A11** A retained response or saved source completes parsing/building without another provider call.
3. **A12** An interrupted send without a complete receipt becomes `uncertain`; explicit scene retry adds a new retained attempt.
4. **A13** Changed input, provider/model, template, or toolchain refuses before sending or overwriting artifacts.
5. **A14** Two concurrent commands sharing one output folder have one sender; separate output folders are independent.
6. **A15** Limits, input gaps, failures, and uncertainty produce a partial report, never a full-success claim.

### Cross-cutting evidence cases

1. **A16** Invalid source paths, reserved files and dependency declarations fail locally with retained evidence; build children receive no provider/application secrets.
2. **A17** The CLI and artifacts explicitly identify the owner-waived terminal demo, no JEV call and no teacher approval; terminal output contains no private bodies or secrets.
3. **A18** The new library uses injected dependencies and leaves Laravel, Temporal, production qualification routes and root JavaScript dependencies unchanged.
4. **A19** Real SVG and R3F fixture builds succeed, and a locally served fixture control changes observable state without page errors or external runtime requests.
5. **A20** An opted-in selected-provider sample produces actual remote response and local-build evidence. Missing credentials leave the live gate open; fixture success never substitutes for it.

### Edge cases and boundaries

- Scene count is variable; the code never forces three or infers scenes from First/Second output.
- An empty `scenes` list records an input issue; it does not restart NotebookLM.
- Missing titles display `Lesson <ordinal>` / `Scene <ordinal>`; paths use ordinals.
- TypeScript source is not browser-ready; compiled files are served over local HTTP.
- No JEV call occurs. The owner-authorized demo assumption permits all supplied prompts to proceed; metadata records the waiver, never a vendor score.
- A build proves compilation, not scientific accuracy, runtime safety, accessibility, or teacher approval.

## Requirements

### Functional Requirements

| ID | Requirement |
|---|---|
| FR-001 | Read current multi-PDF parsed/raw handoffs and the single-conversation archive; enumerate prompts in Lesson/scene order with stable source identities. |
| FR-002 | Accept every nonblank prompt and any type label, without requiring First/Second fields. Record missing prompts/empty Lessons as input issues. |
| FR-003 | Select one API provider per run through a common Gemini, GLM, DeepSeek, and Alibaba Model Studio Qwen interface. |
| FR-004 | Provide a separate small-chat check; require selected configuration and never silently substitute a model, provider, endpoint, or account. |
| FR-005 | Send the captured template bytes and exact stored prompt with the HTML/CSS/JavaScript file contract; capture run identity. |
| FR-006 | Retain each provider response before parsing; collect `index.html`, `styles.css`, and `script.js`. |
| FR-007 | Materialize the three static files locally without npm, TypeScript, or Vite; retain source/build evidence and file hashes. |
| FR-008 | Write atomic checkpoints and immutable per-attempt artifacts retaining prompt, Lesson, scene, provider and digest lineage. |
| FR-009 | Recover completed/received work without new provider calls; require explicit retries for failed/uncertain sends and preserve earlier evidence. |
| FR-010 | Enforce one writer per output folder with an OS lock; reject changed run identity before outbound requests. |
| FR-011 | Continue after item failures, report exact generated/failed/uncertain/queued/input-issue counts, and return truthful exit statuses. |
| FR-012 | Restrict the JEV demo waiver to this terminal feature and record provenance; local artifacts do not mutate production qualification/review state. |
| FR-013 | Keep credentials and prompt bodies out of terminal logs, retain educational output privately, prevent path escapes and delete evidence only by explicit operator action. |

### Key Entities

- **SceneInput**: Immutable prompt, notebook/conversation identity, Lesson/scene ordinals/titles, source pointer and digests.
- **ProviderConfig / ProviderResponse**: Selected nonsecret API configuration and normalized result/usage/evidence.
- **SceneRun / SceneAttempt**: Captured run identity and append-only attempt state, receipts and output references.
- **HtmlSceneSourceV1 / BuildManifest**: Generated HTML/CSS/JavaScript files, local materialization evidence, and file hashes.

Exact fields belong to [data-model.md](data-model.md) and [contracts](contracts/cli.md).

## Success Criteria

- **SC-001**: Every prompt in a successful full run has one generated artifact set with exact Lesson linkage; Lessons retain their differing scene counts (A01–A05).
- **SC-002**: All four adapters satisfy the same boundary; check is one separate request, with no automatic check or fallback during generation (A06–A09).
- **SC-003**: Completed/received work produces zero repeat generation calls; uncertainty requires explicit retry (A10–A14).
- **SC-004**: Partial runs and waived evaluation are visibly labeled; no local output is presented as qualification or teacher approval (A15–A18).
- **SC-005**: Real local fixture builds and separately labeled live samples supply executable evidence; mocks/skips are not live success (A19–A20).

## Assumptions and authorization

- This is the local terminal stage before web integration. Its prerequisites are the inspected handoff writers and Python environment; 004–015 services need not run.
- The owner selected one provider per run, Alibaba Model Studio for Qwen, and a separate check command. Models remain explicit configuration.
- Current owner direction (2026-10-09) replaces the earlier React/TypeScript choice with standard HTML, CSS, and browser JavaScript only. Section 12 of `Google-Ai-studio-System-prompts` now requires exactly `index.html`, `styles.css`, and `script.js`.
- The owner authorized demo qualification bypass and provider use; these do not create production qualifications or review decisions.
- This terminal-only static artifact path leaves Spec 011 and its application contract unchanged; see [downstream integration](contracts/downstream-integration.md).
- Creating these documents performs no application implementation, dependency installation, provider calls, or runtime certification.
