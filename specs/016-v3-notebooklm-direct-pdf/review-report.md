# Specification authoring and final review report: 016

**Date**: 2026-10-07. **Outcome**: New feature design and implementation handoff prepared. Application implementation, migrations, application tests and live provider calls were **not performed during this specification task**.

## Initial gaps and evidence

| Severity | Finding | Inspected evidence / resolution |
|---|---|---|
| High | Current source contract cannot upload original PDF or represent PDF plus framework honestly. | MCP source tools/current Python adapter;008 binding nonnull extraction FK/single source. New mode-aware binding, two-role manifest, binary SDK upload and independent effect/readiness ledgers. |
| High | Staged First/Execution/Second/Third sends do not implement owner's fixed combined loop. | `lesson_processor.py`, `prompts/cycle.py`,009/010 contracts. Direct protocol defines exact Divide/Execution/start/Continue and three-artifact response envelope. |
| High | Root framework differs from legacy canonical bodies and combined artifact visibility is ambiguous. | Full root `steps.txt`,009 canonical artifact, Third “Output ONLY” wording. Owner explicitly selected unchanged root file and all-three output; immutable file hash/profile/configuration now pinned. No prompt asset edited. |
| High | Independent First/turn/receipt commits permit partial educational data. | `AcceptFirstSections.php`, `RecordPromptTurn.php`, `PromptReceiptSender.php`. New coordinator transaction validates all artifacts then commits existing rows and receipt together. |
| High | Stateful retries/lost IDs/history can duplicate work or falsely recover. | SDK upload/chat implementation, current ledgers and incomplete recent listing. Single effect authorization, no repeated uncertain transport phase, known-ID polling and anchored turn recovery; unknown outcome stays uncertain. |
| High | Changing worker activity order/current settings can alter active cases. | `case_workflow.py`, `StartWorkflow.php`, source discovery and stage projector. Captured protocol/default rollback, old-history replay, new sourcing activation and safe recovery signal. |
| Medium | Hierarchy mixed child kinds may collide, and legacy cursor arithmetic is not portable. | `map_parser.py`, cursor-version backfill. One sibling counter, actual claimed/committed cursor versions and shared PHP/Python golden fixtures. |
| High | Privacy/downstream assumptions could make a source change appear to complete the entire app. |004 original-owner policies;010 current-pass;012 checks;013 review;014 controls;015 evidence; prior live/review reports. Explicit real-route privacy, downstream gates, direct evidence chain and honest demo/live distinction. |

## Files created or edited and substantive changes

| File | Change / reason |
|---|---|
| `spec.md` | Four stories,18 requirements,5 success criteria, approved authority and exact scope. |
| `plan.md` | Repository-supported reuse/extensions, concrete new production paths, dependency pin and staged rollout. |
| `research.md` | Primary-source SDK findings, existing-file evidence and rationale for one provider plus credential bridge. |
| `data-model.md` | Additive schema/backfill, immutable framework/case capture, source/query/cursor/bundle constraints and atomic projection. |
| `contracts/notebooklm-protocol.md` | Typed ports, credentials, exact format/configuration/request sequence, parsers, signed HTTP, errors/recovery and workflow invariants. |
| `contracts/downstream-integration.md` | Versioned amendment and obligations across004–015; what later stages must still prove. |
| `contracts/acceptance-matrix.md` |24 stable cases mapping requirements, planned test files/outcomes and owning tasks. |
| `tasks.md` |20 ordered test/implementation tasks, each with paths/dependencies/coverage/completion and trap notes. |
| `task-execution-plan.md` | Direct agent plan, entry reads, before/after commands, checkpoints, stop conditions and mandatory report. |
| `implementation-notes.md` | Actual extension paths and detailed failure traps; preserves unrelated work and missing context-script limitation. |
| `quickstart.md` | Future commands/expected evidence, isolated dependency/migration setup, connected/live gates and real Unit3 path. |
| `checklists/requirements.md` | Documentation quality checks complete, runtime checks deliberately pending. |
| `review-report.md` | This report, evidence/limits and final scope audit. |
| `specs/V3-spec-map.md` | Register016 and make legacy/direct source authority explicit; replace stale global “implementation not started” statement with evidence-per-feature status. |

## Design decisions

- New captured `notebooklm_combined_v1` uses SDK0.8.4; old cases remain legacy. Source/session/provider changes are confined to typed activity-local ports.
- Upload verified original PDF plus exact approved expanded root framework; no dummy extracted-source record, local OCR fallback, Drive or public provider URL.
- Separate source role readiness and format readback from upload acceptance; local hashes are input evidence, not remote PDF byte proof.
- All-three reply envelope implements owner correction without rewriting Divide, Execution or canonical/framework bytes.
- One Laravel transaction supplies existing Section/prompt/completion lineage; real version/digest identities avoid separate partial commits.
- Qualification/scene/review continue through existing persisted gates. Demo JEV100 remains visible waiver evidence, never production/live approval.

## Final read-only consistency review

The Spec Kit prerequisite resolver identified016 and required artifacts. `speckit-analyze` principles were applied read-only across spec/plan/tasks and supporting contracts, including semantic flow, requirements/tasks/scenarios/tests and authority. Subsequent authoring corrections were made separately under the user's specification request.

Draft review found and resolved four concrete inconsistencies: history-anchor fields missing from the initial model; Section/logical-turn/projection digest semantics incomplete; upload result processing conflated with effect confirmation; and single-send wording incorrectly equating a legitimate multi-phase upload with one HTTP request. SDK enum inspection also corrected response length to `ChatResponseLength.LONGER`. The binding shell preparation point and read-only context access are now explicit.

Final checks:18 unique requirements,24 unique scenarios,20 unique ordered tasks; all are covered by the acceptance/task tables and semantic ownership. Every test/implementation pair is ordered; required cross-group dependencies are explicit. Local Markdown document links resolve. No unresolved business choice, template marker or undefined task reference remains. States, roles, digest ownership, route kinds and legacy/direct terminology were checked across artifacts. Quickstart claims no runtime passes.

Baseline hashes cover existing application/config/routes/migrations/worker/integration/scripts/spec files. Only the shared spec map changed among those files. No existing numbered spec, application code or prompt file was changed by this task. The temporary `.specify/feature.json` selection was restored byte-for-byte to its previous004 value; no branch/commit/deployment or service lifecycle action occurred. New files are confined to016.

## Remaining risks and execution prerequisites

No owner business question remains unanswered. SDK dependency resolution and exact no-replay transport behavior, Google internal API stability, direct Unit3 upload/processing limits, combined-output adherence and source fidelity require real execution evidence. Legacy workflow replay, real concurrent DB claims and downstream review/revision/correction compatibility must be tested rather than assumed. The pinned public chat API does not itself establish independent correction-conversation creation; the consumer integration must prove isolation or stop the affected correction path without guessing a conversation ID.

Existing prerequisite implementation may still contain production wiring defects described in prior reports. The implementer must reproduce current blockers and make bounded required repairs with regression evidence; this spec does not declare the existing full app functional. Missing JEV credentials still prevent a fully verified production pipeline, even when an explicit demo waiver permits a demo.

This documentation is prepared for implementation. It is **not a zero-error guarantee or runtime certification**. Use `task-execution-plan.md` and report partial/blocked status if mandatory gates fail.
