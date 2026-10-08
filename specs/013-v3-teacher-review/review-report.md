# Spec 013 Documentation Review Report

**Scope:** Only `specs/013-v3-teacher-review/` was edited. The preexisting Spec Kit feature selector stayed `specs/004-v3-access-ownership`; this review did not change it. This report concerns a proposed design, not running application behavior.

## Authority and inspected evidence

I read [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), the [V3 map](../V3-spec-map.md), every pre-edit 013 artifact, applicable repository instructions, 004 access/closed admin DTO, 005 captured settings, 007 workflow/ReviewGate, 009 Section, 010 prompt/JEV/feedback, 011 candidate, 012 qualification/repair, and directly affected 014/015 contracts. I inspected actual `routes/web.php`, `bootstrap/app.php`, V2 Section/teacher-access controllers and middleware, `DynamicViewModel`, migrations/tests, `config/filesystems.php`, `phpunit.xml`, `composer.json` and `package.json`. The current application has V2 Laravel routes and mutable HTML, not implemented V3 004–012 code, a review viewer or Python preview service. PHPUnit's routine SQLite-memory setup cannot prove a cross-process decision race.

I used the `speckit-analyze` read-only method before editing, including its prerequisite script and a direct semantic 013 pass because the selector pointed to 004. `.specify/extensions.yml` was absent; the constitution file is an unratified template with no operative project rules. The owner explicitly authorized remediation inside 013.

## Initial findings

| ID | Severity | Finding and repository evidence | Resolution |
|---|---|---|---|
| F01 | Critical | 013 promised a “restricted viewer” but specified neither a browser execution boundary nor a safe way to show 011 multi-file untrusted HTML. V2 `DynamicViewModel.html_content`, `routes/web.php` and URL-serving disks are unsafe substitutes. | Defined an owner-authorized remote Playwright preview session using the 012 no-network container, PNG/escaped controls only, exact routes, limits, failures and a real hostile-container gate. |
| F02 | Critical | Visual notes could not call 012's failed-candidate-only `repair_N` claim, and factual notes could not call 010's failed-JEV-only feedback endpoint on a passing prompt. The original plan's unspecified “classification” would invent a model/business rule. | Defined teacher-selected category, a narrow 012 `RepairCriteriaV2`/011 payload extension and a 013-owned 010 teacher-correction effect/origin with the same captured budgets, provider/template and fresh JEV. |
| F03 | High | Original `ApprovedScene` text said one current approval per **case**, contradicting 009's multiple Sections and 007 per-scene counts. | Added Section-current review/approval pointers, historical immutable approvals, per-Section ReviewGates and all-current-Sections case-completion proof. |
| F04 | High | Review item creation, exact owner routes, fields, decision/note schema, idempotency, stale/concurrent behavior, rollback and private evidence were unspecified. | Added exact model, route/request/response/error/state contracts, DB locks/unique constraints, note atomic writes, decision/outbox/audit transaction and shared-DB tests. |
| F05 | High | A duplicate/lost Temporal signal had no durable delivery ledger; a later change to an approved scene could not restart 007's closed fixed-ID case workflow. | Added one per-decision outbox, query-before-resend, per-Section ReviewGate and a deterministic separate CorrectionWorkflow for every post-approval correction. |
| F06 | High | 004 ownership, admin exclusion, current-proof checks, V2/public alternate paths, dormancy, retention and approved-scene revisit were only broad statements. | Specified owner checks for every context/frame/action/decision/correction, current approval listing/reopen, captured-day dormancy, private retention and real legacy-route denial cases. |
| F07 | High | Original tasks put schema/models before meaningful tests; quickstart had no real preview/Temporal/provider gate or shared-DB race; execution notes lacked checkpoints and blockers. | Rebuilt T001–T022 with behavioral red/green pairs, ordered migrations, exact files, group checkpoints, future commands, live gates and required implementation-report evidence. |

## Files edited and necessity

| File | Change / reason |
|---|---|
| [spec.md](spec.md) | Ten functional requirements and four evidence-backed success criteria define the original-teacher, per-Section, preview, correction and post-approval boundary. |
| [plan.md](plan.md) | Maps Laravel/Python/Temporal ownership and concrete new/extended files to the inspected V2 repository and prerequisite contracts. |
| [research.md](research.md) | Records source/contract/code evidence and [official HTML sandbox](https://html.spec.whatwg.org/multipage/iframe-embed-object.html) and [Playwright Docker](https://playwright.dev/python/docs/docker) guidance; distinguishes design from runtime proof. |
| [data-model.md](data-model.md) | Defines review/approval pointers, decisions/notes, routes/outbox, preview sessions, post-approval correction and narrow 010/012 extensions with constraints and private retention. |
| [contracts/review.md](contracts/review.md) | Defines exact owner/internal/bridge operations, viewer action/frame boundary, idempotency/errors, both correction paths, multi-Section ReviewGate and case-state transitions. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | Adds R01–R26 with requirements, planned test files and observable outcomes, including real container/Temporal/provider gates. |
| [contracts/integration-obligations.md](contracts/integration-obligations.md) | Separates what 013 proves from 014 admin recovery, 015 history/export and later student publication. |
| [tasks.md](tasks.md) | T001–T022 name deliverables, paths, dependencies, FR/R links and completion checks, with tests before their behavior. |
| [task-execution-plan.md](task-execution-plan.md) | Direct implementation runbook with entry conditions, before/after tests, checkpoints, baseline handling, stop rules and report requirements. |
| [implementation-notes.md](implementation-notes.md) | Captures V2/public-storage traps, fixed teacher category, Section approval count and closed-workflow correction boundary. |
| [quickstart.md](quickstart.md) | Future-only commands and expected evidence for owner routes, real preview, shared-DB race, Temporal and selected provider. |
| [checklists/requirements.md](checklists/requirements.md) | Marks documentation checks separately from unproved prerequisite/live/downstream gates. |
| [review-report.md](review-report.md) | Records initial findings, edits, rationale, coverage, final review and open prerequisites. |

## Design decisions

- **Teacher-selected category:** The original teacher chooses visual/interaction, factual/source or mixed. Mixed follows factual requalification. This follows Conversation 23's distinction and avoids an unapproved note classifier or an extra model that judges JEV.
- **Remote preview:** Generated scene code runs in an ephemeral 012-style isolated container; the authenticated Laravel browser receives only owner-scoped PNG frames and escaped semantic controls. This uses the existing 011 multi-file package without granting generated JS application origin/session access. It is a planned pilot design, subject to real latency/accessibility/security tests.
- **Per-Section authority:** Approval is an immutable decision for one candidate/qualification, with a mutable **current pointer per Section**. Later correction clears that pointer but retains the earlier approval. The case reaches `complete/approved` only when every expected current Section is approved.
- **Narrow prerequisite extensions:** 013 extends 012's existing linear repair budget for a committed visual review; factual/mixed review adds a 010 teacher-correction effect because 010's feedback route requires a failed JEV answer. Both use captured 005 settings and their original provider ledgers; neither repeats uncertain sends or silently changes canonical prompts/Section fields.
- **Durable delivery:** Laravel commits review decision, route/outbox, audit and case event before Temporal. Query/replay uses stable IDs. A post-approval correction always gets a fixed new CorrectionWorkflow ID; the closed original 007 workflow is never restarted.
- **Replay and preview proof:** Immutable decision/correction rows store the canonical request digest for same-key replay. Approval proof begins only with an owner-authorized frame-0 GET; bridge startup alone does not qualify. A newly qualified current tip clears a former current approval pointer while preserving the approval row.

## Coverage and final review

| Check | Result |
|---|---|
| Requirements | FR-001–FR-010 each map to at least one R01–R26 scenario and T001–T022 task. |
| Acceptance scenarios | R01–R26 each name planned PHP/Python test files and expected outcomes; every scenario appears in the task table. |
| Success criteria | SC-001–SC-004 map to owner/preview, decision, correction and live durability gates. |
| Task order | T001–T022 are sequential with explicit dependencies; each test is authored before its implementation, and behavioral red is recorded after the final route/table/class skeleton exists, before behavior logic. Missing routes or migrations alone are not red evidence. The visual and factual schema extensions use separate migrations. |
| Semantic consistency | Review item/candidate/version/qualification, Section pointer, safe errors, owner routes, preview frames, correction budgets/origins and Temporal IDs were compared across spec, model, contract, plan, tasks, notes and runbook. |
| Links/placeholders/scope | Local links resolve; no unresolved placeholder or trailing whitespace remained in the final read-only check. Other numbered specs and application code were not edited; the prior Spec Kit selector stayed 004. The worktree already contained unrelated tracked and untracked changes before this review; they were left untouched. |

The final read-only cross-artifact check covered 13 selected-spec Markdown files, FR-001–FR-010, R01–R26, T001–T022, planned test names, local links and unresolved placeholders; it reported no coverage/link/order issues. `git diff --check` reported no whitespace errors, though the selected spec is untracked in the preexisting worktree and was checked directly for trailing whitespace. The review was **documentation validation**, not executable proof. Application implementation/tests, browser container startup, provider calls and Temporal workflow execution were **not performed during this documentation review**.

## Open prerequisites, risks and boundaries

1. 004–012 application prerequisites must be implemented first. Their documented interfaces, not yet present in the current V2 codebase, must be inspected at T001; a material delivered-contract mismatch blocks dependent work until resolved.
2. The target OCI deployment must enforce the 012 nonroot sandbox/no-network/no-secret policy for long-lived preview sessions and operate the single-process pilot gateway. The real hostile-container, representative multi-file scene, action latency and keyboard/semantic-control checks remain open. A mock PNG is insufficient.
3. The selected captured 005 feedback template/provider and credentials, live 010 JEV, 011 authoring, 012 qualification and Temporal worker/bridge must be available for R26. A skipped or blocked provider/sandbox/Temporal gate means implementation is partial; no substitute model, public iframe or active-settings fallback is authorized.
4. 014 still owns audited reminders/cancellation and reviewed uncertainty recovery; 015 still owns owner history/export, admin aggregate export and eventual retention/deletion. 013 approval does not publish to students.

No owner business choice remains unresolved for the teacher-selected note categories in this review. The remote preview's deployability and accessibility are technical validation risks, not certified facts. **Application implementation and tests were not performed.**
