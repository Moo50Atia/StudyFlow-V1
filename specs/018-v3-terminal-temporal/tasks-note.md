# Tasks Note: Read Before Implementation

This file fixes decisions for the next agent. It complements [tasks](tasks.md); [task-execution-plan](task-execution-plan.md) is the plan to execute. Do not generate a competing plan or replace approved prompts.

## What the owner approved

- Temporal actively schedules the local terminal pipeline, with native UI and detailed local logs.
- Four subjects globally, each with its own multi-PDF notebook; serial turns per conversation. Four IO Activities and two builds are worker limits, not per-submission multipliers.
- Complete all NotebookLM Lessons for a subject, then freeze handoffs and generate scenes. Do not snapshot a directory while NotebookLM is still adding Lesson files.
- New notebooks and saved handoffs; no automatic adoption of an unfinished old checkpoint. Proof-notebook ID checks the account; it is never the notebook reused for new work.
- mo50atiapro only, with existing Brave/MCP credential setup and notebooklm-py upload/chat. Do not open a login window at runtime. Wrong-account proof cannot be bypassed by a profile label.
- Selected framework and canonical Divide/Execution unchanged. Preserve current permissive terminal capture, all raw answers and any parsed First/Second output. No allowed-scene-type enum, mandatory perspective fields or fixed3-scene assumption.
- Gemini/GLM/DeepSeek/Qwen remain 017 adapters, explicit model and approved scene-generation prompt. Current scene output is exactly plain HTML/CSS/browser JavaScript (`index.html`, `styles.css`, `script.js`); no React, TypeScript, npm, Vite, or build-toolchain requirement. No automatic provider probe/fallback. Artifacts must be materialized from the retained response, never fabricated.
- JEV is owner-waived only for this local demo. Record waiver/no call; do not fabricate100% scores or production qualification/teacher approval.

## Implementation traps already found

1. **Profile_ref is not account proof.** Current SDK provider loads a storage file; verify explicit owner role for the approved proof notebook. Shared access and missing role must fail. Four sessions use designated isolated copies because SDK refresh/close can write cookies.
2. **allow_headless=False is not the whole browser restriction.** Disable external `NOTEBOOKLM_REFRESH_CMD` and related settings after dotenv loading and before SDK bootstrap in the new worker. Native token/CSRF bootstrap can remain. Tests must prove no refresh subprocess is invoked.
3. **The runner bypasses ports.** Move source inventory/current conversation discovery behind provider methods instead of reaching through `.client`. Do not duplicate upload/chat logic inside Activities and CLI.
4. **Repeated Continue is ambiguous without anchors.** Store the full pre-send history prefix hash/identity, then require exactly the expected next turn. Incomplete history is not absence of delivery. Uncertain chat is never resent in the same conversation.
5. **Paid send and parser are different boundaries.** A complete retained response survives parsing/export failure. Reentry restores it; no provider call to repair JSON bookkeeping. Recover generating/source_saved/building states intentionally before scheduling anything.
6. **Synchronous build can block the event loop.** Run owned subprocesses outside it, keep heartbeat responsive, cancel/reap their process group only. A coroutine label on synchronous code does not make it concurrent.
7. **OS lock between Activities is insufficient.** Managed marker rejects standalone writes even while lock is released. Epoch transfer never occurs merely because time expired. A timed-out old sender may still be alive.
8. **Child Continue-As-New is one admitted chain.** Do not free capacity when only a Run ID changes. Explicit parent-close REQUEST_CANCEL prevents default termination bypassing cleanup. Do not rotate parent over active children/handlers.
9. **Update acceptance is not applied control.** Record requested/applied/rejected and command IDs across rotation. Address admitted subjects directly; queued cancellation goes to supervisor. Query requires a worker, so fallback is labelled last-known.
10. **Temporal history is not verbose application logs.** Write the safe local journal from real Activity boundaries; replay must not duplicate events. Never log raw exception causes, SDK HTTP bodies, generated code, cookie values or template/prompt text.

## Test discipline

Build collecting tests that fail on wrong behavior, not only unavailable imports. Where existing behavior already passes, record regression coverage. Vendor doubles are allowed at SDK/API transport boundary; do not replace map/Lesson/export/generation Activities with fixed-ready mocks in the connected gate. Race tests must have actual overlapping processes/operations and counted sends. Restart test must reuse the same server DB; a fresh test environment does not prove persistence. Replayer must consume recorded history, including forced rotation/control events.

All fake future-feature entities and tiny PDFs live under tests. Do not introduce dummy Laravel rows or production SceneCandidate types. Unit3 and mechanics files are operator inputs, not regenerated/split/OCR-transformed test data. Live G-LIVE is opt-in and must report actual credentials/model/results or a truthful skip.

## Scope and stop rules

Application code is changed only by the future implementer for paths in this plan. Preserve pre-existing edits and other numbered specs; no commit/deploy/reset or account-wide notebook deletion. Stop dependent work for missing tools/auth, unavailable original input/template, incompatible017 source contract, wrong account, unresolved delivery/history conflict, changed captured settings or failed required storage. Continue unrelated local tests/research and report the specific blocker. Do not demand additional approval for already authorized routine implementation; do not conceal a live blocker behind fixture passes.

The agent-context updater expected by speckit-plan is absent in this checkout. These notes carry the implementation context; do not create a root instruction file to work around the tooling omission.
