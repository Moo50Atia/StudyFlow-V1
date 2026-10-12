> Superseded for the current repair by [repair-agent-prompt-v2.md](repair-agent-prompt-v2.md), tasks T116–T155 and [repair-acceptance-v2.md](repair-acceptance-v2.md). The original prompt below is retained for history.

# Agent prompt — repair Spec 019 integration findings

Paste the prompt below into the implementation agent from `/home/moahmmed/college_project`. The executable task list is the appended **T066–T115** repair section in `specs/019-v3-web-integration/tasks.md`.

```text
$speckit-implement

Repair Spec 019's web integration with the already working terminal/per-PDF services. Execute the open repair tasks T066–T115 in specs/019-v3-web-integration/tasks.md, respecting their dependencies, completion conditions and evidence rules. Do the implementation and required tests; a plan or another declaration of completion is insufficient.

FEATURE SCOPE
The workspace selector currently points to Spec 020. Before any Spec Kit command, record its exact current contents and explicitly select /home/moahmmed/college_project/specs/019-v3-web-integration for this repair. Restore the entry selector when the session ends, including on failure. Do not implement or rewrite Spec 020. Inspect current files because the workspace is dirty and the existing services are dependencies.

The user has authorized this repair implementation, task/document updates, reversible fixes and the specification's offline/local test work. Proceed through existing incomplete implementation checklists; record their gaps and repair them. Do not ask for renewed permission for ordinary edits or safe isolated tests. Keep paid/live verification gated by its dedicated opt-in and authorized inputs/settings.

READ FIRST
Read .agents/AGENTS.md, Spec 019 spec.md, plan.md, data-model.md, research.md, contracts/*, acceptance-matrix.md, tasks-note.md, task-execution-plan.md, the repair section of tasks.md and this prompt. Read docs/PER-PDF-NOTEBOOKLM.md, docs/PER-PDF-IMPLEMENTATION-REPORT.md and docs/reports/V3-WEB-SERVICE-AUDIT-2026-10-11.md. The existing implementation-report.md contains disputed claims: reassess them; do not use them to redefine requirements.

WHAT MUST BE FIXED
1. Persist a real immutable captured manifest and resolve its opaque reference; remove random manifest UUIDs generated per dispatch.
2. Make the signed Python bridge call the actual shared Temporal supervisor with durable Update-With-Start admission, exact receipts and the correct terminal namespace. HTTP 202 alone is not execution or consumption.
3. Replace fixed-result web Divide/lesson/reset/export activities with wrappers invoking the existing NotebookTerminalService and its retained per-PDF receipts/archive/reset behavior. Save actual outputs through signed Laravel callbacks and atomic cursor/item/version fences.
4. Match registered workflow names/activity symbols/queues. Implement real import verification/copy/materialization, with no hardcoded /tmp success or fixed accepted-PDF counts.
5. Replace memory/expiring-cache receipt authority with durable operation/lease/result storage. Reconcile lost acknowledgments, preserve exact digests/versions and branch on every lesson/archive/reset/export failure before advancing.
6. Connect selected scene commands to actual SceneGenerationService, retained-response/build recovery, real browser checks, original-owner preview/review and exact correction/decision consumption. Keep export/manual the default and release processing capacity during manual/review waits.
7. Exercise real controls, shared capacity, restarts, captured-history replay and safe operational histories. Correct the documentation and original-ID completion evidence.

ARCHITECTURE AND PRESERVATION
Reuse the working services and shared scheduler; thin application workflows/adapters are the intended design. Do not rewrite the engine, start an independent scheduler, spawn CLI scripts from HTTP handlers, enqueue duplicate legacy CaseWorkflow ownership, weaken ownership/gate requirements, rewrite approved prompt/framework bytes, rotate credentials or open a runtime login browser. Preserve terminal commands, captured histories, existing archives, database and unrelated dirty files. Use additive migrations and disposable DB/artifact/Temporal test environments. Do not commit, reset, deploy, migrate:fresh the real database or import fixtures into real user subjects.

TEST RULES
Write meaningful behavioral tests before each governed repair. In connected acceptance, double only external NotebookLM/generation/JEV transports. Real web routes, signing, bridge, Temporal client/queues/workers, activities/service methods, callbacks/database/artifacts, browser checks, preview and decisions must execute. No internal Http::fake accepted bridge, direct workflow.run injection, Activity success stubs, manual insertion of expected lessons, manufactured screenshot/check evidence or mock consumed query may prove integration.

Use varying PDF-local maps/scene counts and request counters so fixed one-lesson results cannot pass. Verify one framework upload, correct source scope, exact saved bytes, archive/reset boundaries, partial/uncertain outcomes, zero default generation sends and one explicitly selected scene. Keep useful unit tests under accurate scope labels. Fetch and actually replay histories with Replayer; constructing it is not replay.

LIVE GATE
Implement the real opt-in two-PDF web-to-owner-review live chain in T110. Run T112 only with its dedicated authorized opt-in, approved session/proof, disposable inputs, providers and runtime prerequisites. If unavailable, keep T112 open with the precise reason and finish all independent offline work. Do not claim 100% live verification from a skip, a session read or the previous successful terminal run.

TASK TRACKING AND DELIVERY
Preserve original FR/SC/A/T identities; the previous report incorrectly remapped them. T067 must reassess/reopen original completion claims; close each through its mapped repair evidence, without unnecessarily redoing valid work. Execute dependency order and update repair checkboxes only after their conditions are met. Record exact commands, counts, evidence categories, sanitized artifact/history refs and limitations as work proceeds.

Finish with a concise report of changed files, the actual connected web chain evidence, restart/control/replay/import/browser results, protected workspace/database/terminal behavior and any open live or acceptance gates. Distinguish integration implemented, offline verified and live verified. Do not state that Spec 019 is fully complete if original required behavior or a claimed verification gate is still missing.
```
