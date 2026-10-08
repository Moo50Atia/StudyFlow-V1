# Implementation notes and traps

These notes complement the [execution plan](task-execution-plan.md). They identify where an apparently small provider change can break the application.

## Required repository reading

- `Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md`, `specs/V3-spec-map.md`, every016 file, and these existing contract directories in full: `004-v3-access-ownership` (`access.md`), `005-v3-versioned-settings` (`settings.md`), `006-v3-pdf-intake-cases` (`intake.md`), `007-v3-workflow-progress` (`workflow.md`), `008-v3-knowledge-source-adapter` (`knowledge-source-adapter.md`), `009-v3-lesson-map-sections` (`lesson-structure.md`, `canonical-prompts.md`), `010-v3-prompt-jev-qualification` (`prompt-qualification.md`), `011-v3-scene-authoring` (`scene-authoring.md`), `012-v3-scene-qualification` (`scene-qualification.md`), `013-v3-teacher-review` (`review.md`), `014-v3-admin-operations` (`admin-operations.md`), `015-v3-history-evidence` (`evidence.md`). Paths are `specs/<directory>/contracts/`; read their integration and acceptance files as well, where supplied.
- Current evidence: `docs/reviews/V3-NOTEBOOKLM-ADAPTER-COMPARISON-2026-10-07.md`, `docs/reviews/V3-LIVE-PIPELINE-TEST-2026-10-07.md` and `docs/reviews/V3-POC-REVIEW-2026-10-04.md`. These document limits; they do not establish current runtime success.
- `steps.txt` **all bytes**; `v3_engine/lessons/{canonical_prompts,map_parser,first_parser,lesson_processor,map_service}.py`; `v3_engine/prompts/{canonical,parser,cycle,activities}.py`; `v3_engine/sources/{notebooklm_adapter,mcp_client}.py`; `v3_engine/workflows/case_workflow.py`, `worker.py`, `bridge.py`; scripts credential setup/check; integration source/auth/transport modules.
- Migrations006–015 and cursor-version backfill; `app/Services/V3/Intake/{StartProcessingCase,AcceptedPdfReader,PrivatePdfStore}.php`; Source activation/effect/reconciler; Lessons accept/map/query/cursor stores; Prompts context/turn/receipt/current-pass services; `ProjectCaseEvent.php`, `StartWorkflow.php`, `CaseOperationGuard.php`, `EvidenceProjector.php`; actual internal/teacher/admin controllers, `routes/{api,v3,console}.php`, config and existing affected tests.

## Fixed decisions

The owner approved current expanded root `steps.txt` and all-three output visibility. Initial import must verify the hash in spec.md. Existing canonical assets are not edited. Only the new profile's Third-slice digest belongs on direct versions; legacy constants continue to identify legacy versions. Do not count framework approval as proof a provider followed it.

The new SDK implements both file and conversation transport. “Mix” means reuse explicit credential capture plus new provider ports; it does not mean chat state alternates between MCP and SDK. Keep the old MCP integration installed for active legacy cases.

qpdf and ClamAV remain intake dependencies. Skip `pdfplumber`/Tesseract educational extraction only for direct cases; do not disable malware/validity checks. A provider upload failure is not permission to OCR locally. Preserve the original Unit3 PDF; no splitting/compression to bypass a limit.

## Exact existing extension paths

Task shorthand resolves to `app/Services/V3/Source/{ActivateSource,SourceEffectLedger,ReconcileSource}.php`, `app/Http/Controllers/V3/Internal/{SourceInputController,SourceBindingController,MapContextController,LessonQueryController}.php`, `app/Services/V3/Lessons/{AcceptLessonMap,LessonQueryLedger,LessonCursorService,LessonEvidenceStore}.php`, `app/Services/V3/Prompts/{PromptContextReader,RecordPromptTurn,PromptReceiptSender,QualifiedPromptResolver}.php`, `app/Services/V3/Workflow/{StartWorkflow,BridgeClient,ProjectCaseEvent}.php`, `app/Http/Controllers/V3/Teacher/CaseSourceStatusController.php`, and `app/Services/V3/Evidence/EvidenceProjector.php`. Inspect resource/export classes through their actual callers before changing projections. New files have full paths in plan.md.

## Failures to prevent

1. **Fake extraction provenance**: old binding FK and map page count require extracted rows. Add mode-aware nullable fields/manifest and accepted-Document lineage; never insert a fake “ready extraction.”
2. **Lost bytes or mistaken titles**: SDK `add_file` takes a private path, not PDF text. Verify downloaded bytes before claim; source title/type confirms registration, not a provider hash echo. Framework title is exactly `steps.txt` within an isolated case notebook.
3. **SDK auth/retry side effects**: flat cookies lack domain/path siblings; `None` conversation may continue an existing chat. New Divide requires empty notebook history. Supply subsequent server ID explicitly. Intercept actual SDK HTTP calls to prove no hidden retries, including auth-refresh behavior. Public option names do not prove semantics.
4. **Lifecycle bugs**: no globally cached async client shared across Temporal event loops. Managed activity-local context closes it even after error/cancel. Large PDF uses streamed private file, bounded memory and heartbeat cleanup; do not put its bytes in activity args.
5. **False lesson completeness**: System Ready is acknowledgement only. Missing First/Second output, malformed quotes or `Scene Type:` prefix does not qualify as a valid bundle. Preserve raw reply, block, and do not derive First from Third or ask a model if it is usable.
6. **Partial commit**: old First/turn/receipt services commit separately. Reuse validation/digest helpers inside the new coordinator; one transaction owns all rows. Files are staged/verified beforehand, with orphan grace and unresolved-reference retention.
7. **Wrong IDs/order**: use one counter for each parent's siblings across node kinds, then depth-first work order. Leaf titles may repeat. Real cursor versions replace the legacy arithmetic formula; final cursor complete is not case approved.
8. **Concurrency illusions**: SQLite `lockForUpdate()` is ignored; use an actual transactional case/control write to serialize claims, uniqueness and CAS. Two simultaneous processes must exercise the boundary. File `:memory:` databases or sequential duplicate calls do not prove this.
9. **Recovery by replay**: `Continue` is repeated literal text, not provider idempotency. Persist private anchor/count before send and recover only exact next history turn. Unknown IDs/truncated listing/interleaving stay uncertain; no fallback to another provider, new notebook or reset send_count.
10. **Workflow replay/privacy**: captured version determines branch, never current env. Raw answers/source/conversation IDs must not enter workflow args/results/signals/memo. Each stage key includes relevant work/version identity; shared keys suppress later Lessons.
11. **Downstream false pass**: reuse persisted current JEV result and original owner review. Waiver100 is demo-only and never supplies real browser screenshot/safety evidence or teacher approval. Connected test failure is a real dependency failure, even if hundreds of isolated tests pass.
12. **Leakage through errors**: SDK chat/parser exceptions may include answer previews. Translate at the port boundary into fixed codes; do not log `str(exc)` or HTTP response bodies. Admin evidence uses closed allowlists, not models/relations/private manifests.

## Agent context and spec tooling

This checkout has no `.specify/scripts/bash/update-agent-context.sh`; keep these feature-specific notes as the execution context rather than creating unrelated root agent files. `.specify/memory/constitution.md` remains an unratified template. Feature creation used the existing templates and temporary selector; authoring restored the prior004 selector. The implementer must explicitly select016 and restore its prior selector after temporary tooling use.
