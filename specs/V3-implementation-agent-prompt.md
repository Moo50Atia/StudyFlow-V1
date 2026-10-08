# Reusable V3 Implementation Agent Prompt

Fill in the three-digit spec number below. This prompt requires evidence of completion and uses the spec's prepared execution plan.

```text
Implement V3 spec ___ in /home/moahmmed/college_project, from its current state through verified completion.

1. Establish scope and context
- Find the single specs/___-v3-* directory. Read repository instructions and that directory's spec.md, plan.md, research.md, data-model.md, every contracts/ file, quickstart.md, tasks.md, implementation-notes.md, task-execution-plan.md, and review-report.md if present.
- Read specs/V3-spec-map.md and relevant prerequisite contracts. Confirm which prerequisites must actually be implemented. Do not treat an unimplemented dependency as available merely because its spec exists.
- Set .specify/feature.json to the selected directory before any Spec Kit command and verify the resolved path. Never operate on another selected spec by accident.
- Inspect git status and actual named code files. Record pre-existing changes and relevant baseline test failures. Preserve work that belongs to someone else.

2. Follow the existing execution plan
- Use task-execution-plan.md directly. Do not generate a replacement plan or rewrite the spec/tasks to match easier implementation.
- Execute all unchecked tasks in dependency order. Verify evidence for checked tasks when resuming. Do not silently label tasks inapplicable, skip them, or mark stubs finished.
- Use the prescribed Spec Kit implementation workflow where available. Continue within this spec until its completion gate is met; do not stop after the first successful story or scaffold.
- If repository details differ, resolve minor implementation details without changing the contract. Ask only when a real conflict requires a new business decision. State the conflicting requirements and continue independent work while waiting.

3. Implement with meaningful TDD
- Write the specified behavioral tests first. For new behavior, demonstrate the intended assertion failure, implement, and make it pass. Syntax or missing-import failures alone do not count as behavioral red evidence.
- Preserve passing existing behavior with regression tests; do not manufacture failures. Use the real HTTP/service/database boundary required by each case. Do not disable middleware or mock the security boundary the test must verify.
- Follow documented ownership, errors, transactions, concurrency, audit, versioning and recovery. Use test doubles only where permitted. A fake passing integration does not prove a live provider works.
- Run each story's focused checks, the complete quickstart and relevant regressions. Fix new failures within scope and rerun affected checks. Record baseline failures separately; never call an unrun or skipped check passed.

4. Preserve V3 boundaries
- Enforce original-teacher ownership/review and admin metadata-only access. Protect existing alternate routes where the selected spec requires it.
- Keep V2 scenes and KOS/KIE untouched. Preserve canonical prompts and required Section fields. Do not begin another spec or create future-feature placeholders to make this one appear complete.
- For external integrations, follow the selected spec's real interfaces and credential requirements. If a service is unavailable, finish independent local work and report the precise integration/check blocked. Do not replace it with an invented success or claim full completion.

5. Review and prove completion
- Review actual implementation against every requirement, acceptance case, contract, task and success criterion. Use speckit-analyze for read-only artifact consistency where available; also inspect code and tests, since document consistency alone does not prove implementation.
- Check off a task only after its deliverable and required checks are done. Keep incomplete tasks unchecked. Do not change acceptance criteria just to obtain green tests.
- Write implementation-report.md inside the selected spec: outcome; changed files/reasons; baseline versus new failures; requirement/case-to-test evidence; TDD evidence; commands/results; migration/route/integration checks; incomplete task IDs; remaining limitations.
- Declare complete only if the mandatory completion gate is met. Otherwise declare partial or blocked and identify what prevents completion.

Do not commit, deploy, change production data, or start another spec unless I explicitly ask.
```
