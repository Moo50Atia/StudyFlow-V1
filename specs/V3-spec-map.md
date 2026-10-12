# V3 Spec Map: Core Business Feature Processing

**Status**: Specification inventory; verify runtime evidence per feature.  
**Date**: 2026-10-09

**Parent context**: [Version 3 technology workstream](../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md).

Each numbered directory contains `spec.md` (business behavior), `plan.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`, `tasks.md`, `implementation-notes.md`, `task-execution-plan.md` and a specification checklist. Implement one directory at a time using its own task execution plan. IDs 001–003 remain older V2/future-engine material and are not replaced.

| ID | Spec | Depends on | Business-flow coverage |
|---|---|---|---|
| 004 | [Access and Ownership](004-v3-access-ownership/spec.md) | None | 1, 6–8 access boundary |
| 005 | [Versioned Settings](005-v3-versioned-settings/spec.md) | 004 | Cross-cutting operating rules |
| 006 | [PDF Intake and Cases](006-v3-pdf-intake-cases/spec.md) | 004–005 | 2 |
| 007 | [Workflow and Progress](007-v3-workflow-progress/spec.md) | 004–006 | 2–3, 6 |
| 008 | [Knowledge Source Adapter](008-v3-knowledge-source-adapter/spec.md) | 004–007 | 2, 4 source handoff |
| 009 | [Lesson Map and Teacher Sections](009-v3-lesson-map-sections/spec.md) | 004, 007–008 | 4, teacher Section view |
| 010 | [Prompt Generation and JEV](010-v3-prompt-jev-qualification/spec.md) | 005, 007–009 | 4 |
| 011 | [Scene Authoring](011-v3-scene-authoring/spec.md) | 004–005, 007, 009–010 | 5 generation |
| 012 | [Scene Qualification and Repair](012-v3-scene-qualification/spec.md) | 005, 007, 010–011 | 5 qualification |
| 013 | [Teacher Scene Review](013-v3-teacher-review/spec.md) | 004, 007, 009–012 | 6 |
| 014 | [Admin Operations](014-v3-admin-operations/spec.md) | 004–007, 013 | 7 |
| 015 | [History, Evidence and Pilot Metrics](015-v3-history-evidence/spec.md) | 004–014 | 8 |
| 016 | [Direct PDF NotebookLM Protocol](016-v3-notebooklm-direct-pdf/spec.md) | Existing 004–015 contracts and affected implementation | Versioned amendment: original PDF + approved steps.txt, SDK adapter and complete lesson loop |
| 017 | [Terminal Scene Generation](017-v3-terminal-scene-generation/spec.md) | Existing NotebookLM JSON handoff writers and local Python/Node tools; production stack is not a runtime prerequisite | Local next stage: one selected Gemini/GLM/DeepSeek/Qwen API, approved React template, saved sources/builds, resumable per-Scene evidence |
| 018 | [Temporal Terminal Pipeline](018-v3-terminal-temporal/spec.md) | Existing multi-PDF terminal NotebookLM runner, 017 service/artifact contracts and local Temporal SDK/CLI | Durable local control: four concurrent subjects, serial Lesson loop, scenes after completed handoffs, per-operation recovery, native UI and safe operational logs |

## Locked V3 Boundaries

- Teacher and admin are the only pilot roles. Original teacher owns and formally reviews only the final interactive scene. Admin sees operational metadata, not educational content.
- Accepted PDF remains private locally. Legacy cases send extracted text through the selected MCP adapter. For newly captured `notebooklm_combined_v1` cases, the owner's later correction in016 instead uploads original PDF plus approved unchanged `steps.txt` through the Python SDK, with no local educational extraction/OCR or Drive dependency. Existing cases are not repinned.
- NotebookLM produces the Chapter/Mini Chapter/Lesson map. The exact owner-provided dividing, execution and three step prompts are preserved in [009's canonical prompt artifact](009-v3-lesson-map-sections/contracts/canonical-prompts.md). All eleven First-step Section fields are retained for teacher view.
- 016 keeps Divide/Execution and legacy canonical assets unchanged, pins the owner-selected expanded root `steps.txt`, and requires all three output artifacts per Lesson. Its contract supersedes only the direct-case source/combined-output path; JEV, scene checks and original-teacher review still apply.
- JEV provides typed Choice, Score and Noul qualifications. Code applies captured policy directly; a separate generator revises failed prompts or scenes. Technical API failures have their own path.
- 017 is an owner-authorized terminal demo with explicitly recorded JEV waiver, no fabricated scores, and no production qualification/review mutations. Its React source/build contract is separate from 011's static package. Later web integration requires the amendments in [017's downstream contract](017-v3-terminal-scene-generation/contracts/downstream-integration.md).
- Temporal manages durable long-running workflow and review waits; Laravel manages identity, ownership, status and review. LangChain manages model/tool calls where appropriate.
- V2 scene materials are read-only quality references. KOS/KIE are separate future modules. Student delivery and multitenancy are future work.

## Business Flow Coverage

1. Access and roles → 004, 014.
2. PDF submission and processing case → 005–008.
3. Progress visibility → 007, 014.
4. Prompt generation and JEV qualification → 008–010.
5. Scene generation and automated qualification → 011–012.
6. Original teacher review and revisions → 007, 013.
7. Admin management and operations → 004–005, 014.
8. History and business evidence → 015.

## Implementation Sequence

Follow numerical order. For each spec, use its `task-execution-plan.md` as the execution plan; do not generate a competing plan at implementation time. Start with failing story tests, implement tasks in order, run that spec's quickstart, then report evidence and unresolved external-service limitations. The [reusable agent prompt](V3-implementation-agent-prompt.md) leaves the spec number blank.

017 can be implemented independently over saved handoff files without starting 004–016 application services. Its [tasks-note.md](017-v3-terminal-scene-generation/tasks-note.md) fixes repository-specific decisions for the implementation agent. Numerical sequencing of the production workflow does not turn the local waiver into an application pass.

018 schedules the terminal path over reusable service steps; it does not replace the existing production CaseWorkflow or implement Laravel teacher-upload integration. Use its [direct execution plan](018-v3-terminal-temporal/task-execution-plan.md) and [tasks-note.md](018-v3-terminal-temporal/tasks-note.md). Its native history and local journal provide operational evidence; local waiver/build output is still not production qualification or approval.
