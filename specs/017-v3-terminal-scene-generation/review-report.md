# Specification Creation and Consistency Review

**Feature:** 017 — Terminal Scene Generation

**Date:** 2026-10-09

**Result:** Documentation decisions and cross-artifact review completed. Application implementation, application tests, scene builds and live provider calls were not performed. All T001–T022 remain open.

## Scope and owner decisions

Create the next terminal stage over existing NotebookLM JSON results: select one Gemini, GLM, DeepSeek or Alibaba Qwen API, generate every supplied Scene prompt, retain Lesson association and responses, and automatically compile React sources into local browser artifacts. Do not integrate Laravel/Temporal or restart NotebookLM.

The owner confirmed one provider per run, a separate small-chat configuration check, Alibaba Model Studio Qwen, and preserving the current approved scene template with local React builds. The owner's demo JEV waiver permits generation but does not create vendor qualifications, scores or teacher approvals. No material business question remains unresolved in this scope.

## Initial evidence and design gaps

| ID | Severity | Evidence / gap | Resolution and reason |
|---|---|---|---|
| I01 | High | `Google-Ai-studio-System-prompts` Section 12 requires React/TypeScript/Vite, while `v3_engine/authoring/contracts.py` and `package.py` accept static HTML/JS/CSS/SVG/JSON, no TSX. | Confirmed owner choice; define `ReactSceneSourceV1`, trusted React host and actual local builds. Reusing 011's unchanged package would reject approved output. |
| I02 | High | `v3_engine/authoring/providers.py` forces the old output contract; existing live test passes incompatible positional arguments and assumes a dict instead of the five-element tuple. | New isolated API ABC/adapters, boundary tests and live sample. Treat old wire code as evidence, not a working new contract or credential check. |
| I03 | High | Multi-PDF writers use `mapped_title` and parsed/raw root kinds; archive writer uses `lessons[]`. Actual legacy results include different Scene counts and labels such as `Comparison \| Objects`. | Read all three actual kinds, preserve exact prompts, use stable identity and title precedence, accept any type label and optional pedagogical fields. Prevent hardcoded three-scene behavior or renewed NotebookLM gates. |
| I04 | Medium | Existing providers/settings cover three production APIs; Qwen endpoint and model are account/region specific. | Local Alibaba Qwen adapter with required explicit base/model/key; no fallback and no change to 005's production catalog. |
| I05 | High | A paid generation interrupted between send and recording can have unknown completion; a repeated batch could duplicate provider work. | Durable local send reservation, raw receipt before parsing, append-only attempts, zero-call local recovery and explicit uncertain retry. Use actual OS locking and concurrent process tests. |
| I06 | Medium | Laravel root JavaScript tooling is not the approved React host; source builds can require renderer-specific peer dependencies. | Isolated per-attempt projects, pinned selected dependency closure, real SVG/R3F build gates, root dependencies unchanged. Registry inspection is not build evidence. |
| I07 | High | Production 010/011/012 require persisted qualifications and 013 requires original-teacher review. A local waiver cannot satisfy these states. | Explicit waiver provenance in every local manifest and downstream integration obligations; no production placeholders or qualification mutations. |

## Draft consistency findings resolved

The read-only Spec Kit analysis found six authoring gaps: missing browser-test paths in the plan; incomplete `build` recovery/identity rules; undeclared-total completion ambiguity; missing timing/AST-validation evidence fields; missing A16–A20 inventory in `spec.md`; and wording that could forbid 017's own implementation files. All were resolved in their governing artifacts. Final authoring also specifies output-folder collision handling, fixed failure codes, and an explicit subprocess environment.

## Files authored or updated

| File | Substantive content and why |
|---|---|
| [spec.md](spec.md) | Stories, FR-001–FR-013, A01–A20, SC-001–SC-005, owner authorization and local boundaries. Defines the requested behavior. |
| [plan.md](plan.md) | Exact module/test/scaffold paths, interfaces, reuse decisions and processing order. Prevents incompatible production wrappers or root dependency changes. |
| [research.md](research.md) | Inspected repository evidence, approved template digest, provider primary documentation and alternatives. Separates design rationale from runtime proof. |
| [data-model.md](data-model.md) | Run/input/attempt/receipt/build/manifests, paths, state and crash recovery. Makes lineage and repeat-request behavior executable. |
| [contracts/providers.md](contracts/providers.md) | API ABC, configuration, actual wire shapes, separate check and normalized outcomes. Fixes provider selection and transport decisions. |
| [contracts/cli.md](contracts/cli.md) | Exact flags, supported input kinds, identities/gaps, exit statuses, safe codes, collision and recovery rules. Provides a terminal contract. |
| [contracts/source-package.md](contracts/source-package.md) | React output structure, host boundaries, dependency pins, controlled commands and measurable build publication. Resolves the approved-template mismatch. |
| [contracts/downstream-integration.md](contracts/downstream-integration.md) | What local artifacts supply and what future production consumers must add. Prevents waived local output from becoming an application pass. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | Stable case-to-requirement/test/task mapping, expected outcomes and proof scope. Supplies meaningful completion evidence. |
| [tasks.md](tasks.md) | 22 ordered tasks with deliverables, dependencies, FR/case coverage and completion conditions. Tests govern new behavior before implementation. |
| [tasks-note.md](tasks-note.md) | Requested agent notes: real handoff/template traps, wrong existing live test, compilation, receipt recovery, waiver and cleanup. Avoids repeated implementation mistakes. |
| [implementation-notes.md](implementation-notes.md) | Index to the authoritative tasks-note rather than another competing copy. |
| [task-execution-plan.md](task-execution-plan.md) | Entry conditions, baseline, ordered groups, before/after commands, checkpoints, blockers and required report. Direct implementation runbook. |
| [quickstart.md](quickstart.md) | Future commands and expected results for unit/build/race/browser/check/full-batch/live verification. No unexecuted command claimed passed. |
| [checklists/requirements.md](checklists/requirements.md) | Reviewed documentation-quality checks, explicitly separate from open runtime gates. |
| [review-report.md](review-report.md) | This evidence/decision/scope record. |
| [../V3-spec-map.md](../V3-spec-map.md) | Registers 017 and its local prerequisite/waiver/integration exception; other numbered specs remain unchanged. |
| `.specify/feature.json` | Selects `specs/017-v3-terminal-scene-generation` for the implementation agent. Previous selector was 016; this is the intentional new feature selection, not a temporary review change. No branch/commit created. |

## Coverage and final review

| Inventory | Documentation evidence |
|---|---|
| Functional requirements | 13/13 have semantic acceptance cases, named test files and implementation tasks. |
| Success criteria | 5/5 map to A01–A20 and executable gates; no unbacked runtime certification. |
| Acceptance cases | 20/20 have expected results and task/test mapping. Variable counts, failure continuation, recovery, actual concurrency, waiver and live evidence are covered. |
| Tasks | 22 unique unchecked IDs, each with concrete deliverables/dependencies/coverage/done condition; dependencies reference earlier tasks and no task is unmapped. |
| Cross-artifact semantics | Provider IDs, React contract, immutable attempts, checkpoint states, failure codes, build eligibility, exit statuses and terminology reviewed against tasks/notes/runbook. No remaining material contradiction identified. |
| Links/placeholders | Final local-document link and unresolved-marker checks pass; future CLI/code paths are instructions, not claimed existing links. |
| Whitespace | Changed tracked-document `git diff --check` and new-document whitespace checks pass. |
| Workspace scope | SHA-256 comparison of all 85 recorded pre-existing changed/untracked files found no changes. Only this new spec, its map entry and intentional selector changed during this task. |

Spec Kit prerequisites resolve to 017 and discover plan/research/data-model/contracts/quickstart/tasks. The constitution is an unfilled template, not ratified policy; no configured extension hooks or applicable root/ancestor AGENTS were found. No other numbered spec or application code was edited. Private handoff bodies/credentials were not copied into the specification.

## Pending implementation prerequisites and limits

- All implementation and verification work is pending. Explicit model/key/region configuration must be available for the selected live API; a missing key leaves T021 open.
- Actual npm installs, strict typechecking, Vite builds, process race tests and browser fixture must establish the planned toolchain; read-only package registry checks cannot close those gates.
- Model output can be invalid or fail compilation. The run retains evidence and continues, but this feature includes no automatic model repair or scientific validation.
- Local compilation is not runtime safety, accessibility, JEV qualification or teacher review. Future web integration must implement the downstream obligations explicitly.
- No provider fallback, automatic request retries, NotebookLM login, database model, web route, Laravel/Temporal service launch, commit or deployment belongs to this feature creation.

**No application implementation, application tests, live generation, dependency installation or scene build was performed during this documentation task.**
