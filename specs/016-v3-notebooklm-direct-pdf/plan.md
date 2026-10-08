# Implementation Plan: V3 Direct PDF NotebookLM Protocol

**Feature**: 016 | **Date**: 2026-10-07 | **Spec**: [spec.md](spec.md)

## Summary

Introduce `notebooklm_combined_v1` as an immutable new-case protocol. Use `notebooklm-py==0.8.4` behind Python activity-local session/files/conversation ports, stream the accepted PDF, upload exact approved `steps.txt`, and atomically project each combined reply into existing 009/010 records. Keep the legacy MCP branch intact. Follow [execution plan](task-execution-plan.md) directly.

## Technical Context

Python >=3.12; existing Temporal/FastAPI/httpx worker; Laravel/PHP and existing private storage/DB. SDK 0.8.4 requires httpx >=0.27,<0.29; resolve and record the complete worker dependency set before adopting it. Use PHP feature/integration tests, pytest protocol/adapter tests, real DB concurrency tests and Temporal replay tests. Linux local pilot; original teacher/admin roles; one case notebook and two sources per direct case. Processing is asynchronous, not an HTTP request held open for the entire pipeline.

## Constitution Check

`.specify/memory/constitution.md` is an unratified placeholder template. Its example principles are not adopted requirements. Gates are the owner's V3 decisions: canonical prompt immutability, eleven-field preservation, direct typed JEV, owner-only content, metadata-only admin, durable Temporal coordination, no future infrastructure. These gates hold before and after design. Do not edit the constitution to manufacture compliance.

## Repository-backed implementation decisions

| Inspect/reuse | Required extension |
|---|---|
| `StartProcessingCase.php`, immutable settings/idempotency and `AcceptedPdfReader.php` | Capture protocol/framework under same creation transaction; stream original file through current signed endpoint. |
| `ActivateSource.php`, existing source ledger and reconcilers | Direct branch discovers `sourcing`, not `extraction`; independent notebook/PDF/steps effects and two-source readiness. |
| Existing binding FK and `MapContextController.php` extraction assumptions | Add honest direct-mode provenance; no fabricated extracted-source row. |
| `map_parser.py`, lesson nodes/cursor/completion and evidence stores | Fix mixed sibling ordinals in a protocol-specific parser; direct map is ready without Coverage; actual cursor version. |
| Existing prompt work/turn/version/section-state tables | One transaction projects combined First/Second/Third; logical reuse turns send zero provider requests. |
| `case_workflow.py`, worker registration, existing qualification/review activities | Replay-safe captured branch; reuse downstream stages; work-specific event keys and recovery signals. |
| Existing Brave credential setup and pinned MCP | Domain-aware SDK credential bridge; legacy cache stays valid; no runtime browser or Node dependency for direct branch. |
| 014 control guard; 015 evidence projection/export | Fence new sends, retain unresolved effects and include direct provenance without admin leakage. |

## Project Structure and file ownership

New production files to implement:

```text
v3_engine/notebooklm/
  ports.py                 # typed session/files/conversation ports and results
  credentials.py           # cache validation/SDK format bridge, no business logic
  sdk_provider.py          # SDK 0.8.4 transport and lifecycle only
  protocol.py              # source scopes and fixed conversation sequence
  bundle_parser.py          # approved combined-output grammar
  laravel_client.py         # signed exact-byte case-scoped contract client
v3_engine/activities/process_notebook_lesson.py
app/Models/V3/NotebookFrameworkVersion.php
app/Models/V3/KnowledgeSourceFile.php
app/Models/V3/NotebookLessonBundle.php
app/Services/V3/NotebookLM/FrameworkCatalog.php
app/Services/V3/NotebookLM/DirectSourceCoordinator.php
app/Services/V3/NotebookLM/DirectQueryLedger.php
app/Services/V3/NotebookLM/CombinedLessonValidator.php
app/Services/V3/NotebookLM/AcceptCombinedLesson.php
app/Services/V3/NotebookLM/ReconcileDirectProtocol.php
app/Http/Controllers/V3/Internal/NotebookProtocolController.php
app/Console/Commands/V3/ImportNotebookFramework.php
app/Console/Commands/V3/ReconcileNotebookProtocol.php
database/migrations/2026_10_07_000016_add_v3_notebooklm_direct_protocol.php
```

Modify existing files only as named in tasks: intake/model/case start, binding/map/query/cursor projection, worker/bridge/workflow, source and operation/evidence projections, config and explicit credential/check scripts. Do not edit old migration files; the new migration handles additive changes/backfill. No production fixtures or duplicate JEV/scene services.

## Delivery groups

1. Inventory/baseline and dependency pins (T001–T002).
2. Framework/protocol capture and honest schema (T003–T004).
3. Decoupled credentials/provider contract (T005–T006).
4. Original files and durable source ledger (T007–T008).
5. Fixed sequence/parser and atomic projection (T009–T012).
6. Recovery/control/concurrency (T013–T014).
7. Replay-safe workflow and downstream integration (T015–T016).
8. Owner history/privacy and connected/live gates (T017–T020).

Every implementation task follows the behavioral tests it governs. Tests that already pass are regression evidence, not manufactured red failures. Live provider tests are mandatory separately; mocks prove contract behavior only. See [acceptance matrix](contracts/acceptance-matrix.md).

## Rollout and complexity

Default remains `legacy_staged_v1` during implementation. Enable `V3_NOTEBOOK_PROTOCOL=notebooklm_combined_v1` only after mandatory connected/live gates and framework import. New-case creation captures it; rollback changes only future defaults. Running direct cases remain on their pinned branch. Nullable legacy extraction fields and one source manifest are necessary because two real binary/text sources cannot be represented as one extracted text row. No new orchestration service or optional MCP process is required.
