# Specification Quality Checklist: Terminal Scene Generation

Reviewed 2026-10-09. Checked items certify the documentation decision/coverage only. All implementation tasks, runtime gates and live evidence remain pending.

## Scope and authority

- [x] CHK001 The requested terminal stage and its boundary before web integration are explicit. [spec](../spec.md), FR-001–FR-003, FR-012
- [x] CHK002 Owner choices name one provider per run, Alibaba Qwen, separate check, and approved React template with automatic local build. [research](../research.md)
- [x] CHK003 Existing production JEV/ownership/review rules are distinguished from the temporary local waiver. [downstream contract](../contracts/downstream-integration.md), A17–A18
- [x] CHK004 Repository evidence identifies actual input kinds and the existing static-package/template incompatibility. [research](../research.md), [tasks-note](../tasks-note.md)

## Contracts and decisions

- [x] CHK005 Input identities, title precedence, exact prompt preservation, variable counts, gaps and invalid inputs are defined. [CLI](../contracts/cli.md), A01–A02, A08, A15
- [x] CHK006 Provider interface, configuration precedence, endpoints, payloads, normalization and failure outcomes are specified. [providers](../contracts/providers.md), A03, A06–A09
- [x] CHK007 React source files, reserved host files, pinned dependency closure and actual build commands are fixed. [source/build](../contracts/source-package.md), A04, A16, A19
- [x] CHK008 Fields, state transitions, receipt ordering, attempts/build ordinals and immutable artifact lineage are specified. [data model](../data-model.md), A03, A10–A13
- [x] CHK009 Lock scope, actual concurrent-operation evidence, drift rejection, local recovery and explicit retry are defined. [data model](../data-model.md), A10–A14
- [x] CHK010 Partial failure continuation, limits, exit statuses, fixed error codes and global storage stops are unambiguous. [CLI](../contracts/cli.md), A05, A09, A15
- [x] CHK011 Private storage, path bounds, child environment, safe logs and evidence retention are concrete and proportional. [data model](../data-model.md), [source/build](../contracts/source-package.md), A16–A17

## Execution and evidence

- [x] CHK012 Every FR and SC maps to named acceptance cases and task evidence. [matrix](../contracts/acceptance-matrix.md)
- [x] CHK013 Every task has a stable ID, deliverable path, dependency, requirement/case mapping and completion condition. [tasks](../tasks.md)
- [x] CHK014 Test-first groups distinguish meaningful new-behavior red from passing regression and syntax/import failures. [execution plan](../task-execution-plan.md)
- [x] CHK015 Mock transport, actual builds/process races/browser fixtures and live API evidence have separate completion gates. [matrix](../contracts/acceptance-matrix.md), T018–T022
- [x] CHK016 Notes and direct execution plan cover workspace changes, baseline failures, stop conditions, cleanup and final report. [tasks-note](../tasks-note.md), [execution plan](../task-execution-plan.md)
- [x] CHK017 Verification commands are concrete future instructions with expected outcomes, never claims of executed tests. [quickstart](../quickstart.md)
- [x] CHK018 Scope, local links, task dependencies, terms, route absence and pending live prerequisites received a final read-only consistency review. [review report](../review-report.md)

## Open implementation gates

All T001–T022 remain unchecked. Selected API key/model/endpoint availability, dependency installation, actual compilation, browser fixture, concurrency and live generation must be demonstrated by the implementation agent. The documentation review cannot certify those behaviors.
