# Spec 014 Documentation Review Report

**Scope:** Only `specs/014-v3-admin-operations/` was edited. The existing Spec Kit selector remained `specs/004-v3-access-ownership`. This report records a documentation design, not implemented or tested runtime behavior.

## Authority and repository evidence

I read [Conversation 23](../../Conversations/23_Version3_CoreBusinessFeatureProcessingTechnologies.md), the [V3 map](../V3-spec-map.md), every pre-edit 014 artifact, 004–007 and 013 prerequisite contracts (including 008–012 effect boundaries needed for admin operations), the directly affected 015 contract and applicable instructions. I inspected current Laravel routes, `bootstrap/app.php`, `UserController`, `User`, `TeacherAccessMiddleware`, migrations, tests, filesystems, `phpunit.xml` and Composer configuration. Current code is V2; no 004–013 V3 application implementation, Temporal bridge or 014 admin services are present. `phpunit.xml` uses SQLite `:memory:` and cannot prove a two-process race. `.agents/AGENTS.md` is scoped to `.agents`; the owner explicitly authorized this 014 edit. The Spec Kit prerequisite script selected 004, so I applied the `speckit-analyze` read-only semantic pass directly to 014 without changing the selector. The constitution file is an unratified template and `.specify/extensions.yml` is absent.

## Initial findings before remediation

| ID | Severity | Finding and evidence | Resolution |
|---|---|---|---|
| F01 | Critical | Original US2 promises retry of a blocked case but 007 defines blocked/failed as terminal and fixes `v3-case-{case_id}` with reject-duplicate reuse. 008/010/011/013 also have one-send uncertainty ledgers. | Bounded `retry` to six same-operation reconciliation targets, with explicit rejection of terminal/ambiguous generator sends and no replacement workflow. |
| F02 | Critical | Original retention action said “delete” without retention period, physical-delete owner, legal/evidence hold rule or 015 handoff; 013 retains private review/uncertain evidence and 015 owns retention-aware export/history. | Defined `pilot-retain-v1` with no automatic deletion and an auditable operational assessment deferred to 015; no 014 purge route/job. |
| F03 | High | Hold/cancel had no state matrix or rule for an in-flight provider callback. A controller-only check races 008–013 claims and 013 teacher decisions. | Defined a case-locked control fence, separate control version, exact claim/result extension points and late-evidence behavior. |
| F04 | High | One case may have its original 007 workflow closed and an open 013 correction workflow. A single command delivery/ack would falsely mark case cancel complete. | Added per-workflow delivery rows, deterministic ID discovery and aggregate acknowledgement before applying hold/resume/cancel. |
| F05 | High | Original plan duplicated 004 account mutation via a new AccountController even though 004 already specifies `ChangeAccountAccess` and guards V2 `/users`. The current V2 `UserController` writes directly. | Reused 004 as the only account authority; planned real V2/V3 account regression tests and no 014 account writer. |
| F06 | High | Original dashboard/export/health text lacked a closed field contract; 004 ClosedSummary is exact and 015 owns export. Raw service/error data could leak teacher content. | Defined `OperationSummaryV1`, exact filters/routes/errors, a bounded real Temporal bridge probe, safe backlog counts and no 014 export route. |
| F07 | High | Conversation 23 permits admin reminder of dormant review, but original tasks had no reminder interface, ownership delivery or duplicate rule. | Defined one fixed in-app reminder for Section-current 013 dormant item, shown only through the original owner's workspace. |
| F08 | High | Original schema/contract/tasks omitted field constraints, audit rollback, idempotency digest, concurrency, Temporal reconciliation and meaningful live completion evidence; tasks placed schema before tests and runbook was five generic steps. | Added exact model/HTTP/bridge contracts, O01–O24 matrix, T001–T018 test-first tasks, direct execution plan and future-only verification commands. |

## Files edited and why

| File | Substantive change / necessity |
|---|---|
| [spec.md](spec.md) | Eight functional requirements and four success criteria state admin privacy, safe commands, reminder/account reuse and retention boundaries. |
| [plan.md](plan.md) | Maps new and prerequisite extension files to inspected V2 repository and 004–013 authorities. |
| [research.md](research.md) | Records repository/contract evidence for projection, control version, terminal retry, account reuse and retention decisions. |
| [data-model.md](data-model.md) | Defines case control, command, per-workflow delivery, fixed audit, reminder, assessment and projection constraints. |
| [contracts/admin-operations.md](contracts/admin-operations.md) | Specifies owner-safe admin HTTP, errors, state matrix, fences, signed Temporal callbacks, target reconciliation and no-delete handoff. |
| [contracts/acceptance-matrix.md](contracts/acceptance-matrix.md) | Maps O01–O24 to requirements, planned tests and observable outcomes. |
| [contracts/integration-obligations.md](contracts/integration-obligations.md) | Distinguishes 014's evidence/control foundation from 015 export/physical retention and later student delivery. |
| [tasks.md](tasks.md) | T001–T018 give concrete files, order, dependencies, FR/O links and completion conditions with behavioral tests before implementation. |
| [task-execution-plan.md](task-execution-plan.md) | Direct runbook with prerequisites, before/after checks, workspace/baseline handling, stop rules, live gate and report contents. |
| [implementation-notes.md](implementation-notes.md) | Explains V2 account/teacher-access traps, closed DTO, control versus case versions and one-send effect boundaries. |
| [quickstart.md](quickstart.md) | Lists proposed future verification commands and expected live/shared-DB outcomes without claiming execution. |
| [checklists/requirements.md](checklists/requirements.md) | Separates documented coverage from unimplemented prerequisites/live/015 work. |
| [review-report.md](review-report.md) | Records findings, rationale, coverage, checks and remaining risks. |

## Design choices and rationale

- **No second identity or settings authority.** 004 already owns active roles and legacy `/users` integration; 005 owns published settings. 014 exercises their real routes and does not change captured case settings.
- **Closed metadata with honest health.** 014 wraps 004 ClosedSummary in fixed scalars and reports a bounded Temporal bridge probe. Backlogs are labeled counts; provider health stays unknown without a provider probe. 015 owns export.
- **Separate operational fence.** A case-locked control version stops new claims immediately without invalidating a 007 worker event sequence. Only proven cancellation changes the terminal case state; it also stales pending reviews and clears current approval pointers while retaining historical approvals. Per-workflow deliveries include 013 correction workflows.
- **Same-operation retry.** A retry reconciles an existing deterministic operation or bounded read-only technical attempt. It cannot make a terminal fixed workflow run again or duplicate an uncertain external send.
- **No physical deletion in 014.** The pilot policy has no automatic deadline. A deferred assessment is safe operational metadata for 015, which must recheck eligibility and define any later physical deletion policy.

## Coverage and final documentation review

| Check | Result |
|---|---|
| Requirements and acceptance | FR-001–FR-008 map to O01–O24 with planned PHP/Python tests and expected outcomes. |
| Tasks and order | T001–T018 reference FR/O IDs, concrete paths, dependencies and completion checks. Four ordered migrations follow their governing behavior tests. Behavioral red is recorded after the final route/table/class skeleton exists, not from missing imports or migrations. |
| Semantic consistency | Compared `case.state_version` versus `control_version`, fixed workflow IDs, 013 correction workflows, command/delivery states, closed summary fields, safe errors, one-send ledgers and deletion assessment terminology across all 014 artifacts. |
| Links/placeholders/scope | Local links, placeholders, task IDs and whitespace were checked after edits. Other numbered specs and application code were not edited; preexisting unrelated worktree changes were left alone and the selector stayed 004. |

The fresh read-only check covered 13 selected-spec Markdown files, 8 requirements, 24 scenarios, 18 tasks, planned test names, state/field terminology, local links and placeholders; it reported no issues. `git diff --check` reported no tracked whitespace errors, and the selected untracked spec files were checked directly. The final review is document validation, **not runtime certification**. Application implementation/tests, Temporal worker execution, provider calls and physical deletion were **not performed during this documentation review**.

## Open decisions, prerequisites and risks

1. 004–013 are documented but absent from the current V2 application. The future T001 must inspect their delivered contracts/code; a mismatch in the 007 case/event bridge, 013 correction workflow or effect ledger blocks dependent implementation.
2. The real multi-workflow hold/cancel fence and late-result behavior require a shared transactional DB and live Temporal worker test. Fixture callbacks are insufficient; a missing live gate means partial implementation.
3. The pending owner clarification may choose a different terminal retry or deletion policy. This report uses the conservative repository-supported choice: same-operation reconciliation and deferred physical deletion. A different answer requires revising 014's command/retention contracts and tests before implementation. No arbitrary retention period or new terminal workflow was invented.
4. 015 still owns owner evidence, admin export and any physical retention/deletion. An old 014 assessment cannot authorize 015 cleanup. Student publication, KOS/KIE and multitenancy remain outside 014.
