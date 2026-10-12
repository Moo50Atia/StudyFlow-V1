# Feature Specification: Temporal Terminal Pipeline

**Feature Branch**: `main` (existing workspace; no branch change)

**Created**: 2026-10-09

**Status**: Specified; implementation and runtime verification pending

**Input**: Make the approved Temporal plan executable: durable control of the terminal multi-PDF NotebookLM and scene-generation pipeline, four concurrent subjects, recoverable external operations, native monitoring and detailed operational logs.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Obtain scene artifacts automatically (Priority: P1)

The local operator submits subjects with their PDFs and selected framework. The system creates a notebook per subject, uploads sources, maps content, captures every Lesson, then generates and builds associated scenes without manual conversation advancement.

**Why this priority**: Existing terminal operations must become one automatically scheduled journey.

**Independent Test**: Submit five subjects through the actual orchestration/service boundaries with only external providers substituted; four progress, one queues, and each produces its own Lesson exports and scene outcomes. Compilation is separately exercised with real local tools.

**Acceptance Scenarios**:

1. **A01** Given subjects with different PDF counts, when submitted, then each receives a new notebook containing exactly its unchanged PDFs and selected unchanged framework.
2. **A02** Given five subjects across two submissions, when admitted, then four execution chains run and one queues; conversations never have overlapping turns.
3. **A03** Given ready sources, when processing starts, then Divide uses PDFs only, Execution uses PDFs plus framework, and Start with First lesson is followed by literal Continue turns up to the mapped count.
4. **A04** Given arbitrary scene labels, variable Section counts or malformed optional fields, when a Lesson arrives, then its raw response is saved and processing advances with warnings.
5. **A05** Given an unsaved response, when required storage fails, then the next Lesson is not requested and the failure is visible.
6. **A06** Given unfinished Lessons, when scenes become available, then generation waits for the final Lesson and frozen handoffs.
7. **A07** Given a selected provider/model, when generating scenes, then no automatic fallback or extra connection-probe request occurs; artifacts retain truthful waiver and real build outcomes.

### User Story 2 - Recover and control work (Priority: P1)

The operator restarts services, pauses, resumes, cancels or explicitly retries selected work, preserving results and avoiding blind duplication of uncertain requests.

**Why this priority**: Long conversations and paid generation requests cannot safely restart from the beginning after every interruption.

**Independent Test**: Interrupt registered operations before dispatch, after remote acceptance and after receipt capture; recover and inspect actual send counts, preserved attempts and applied control receipts.

**Acceptance Scenarios**:

1. **A08** Given duplicate submission/command identities, when inputs match, then the existing result is returned; conflicting inputs create no work.
2. **A09** Given a saved complete receipt and lost acknowledgement, when recovering, then the result is reused without another remote mutation.
3. **A10** Given pending creation/upload, when remote evidence uniquely identifies its effect, then it binds that resource; ambiguous evidence blocks resending.
4. **A11** Given unknown delivery or a surviving timed-out process, when recovering, then read-only reconciliation precedes any further dispatch; missing proof remains uncertain.
5. **A12** Given repeated Continue turns or changed history, when recovering, then only an exact anchored prefix and expected next turn permit advancement.
6. **A13** Given expired authentication/rate limiting, when an operation fails, then the reason is visible without account switching or runtime login automation.
7. **A14** Given another account, even with shared visibility of the proof notebook, when remote work is requested, then approved-owner verification fails before mutation.
8. **A15** Given queued or running work, when pause/cancel is requested, then requested and applied are distinguished, retained artifacts survive, and later dispatch stops when applied; cancelled queued work is never launched after a restart.
9. **A16** Given overlapping controls/writers, when racing, then current ownership/state fences prevent conflicting mutations.
10. **A17** Given a closed partial run, when explicitly retrying selected failed/uncertain scenes, then linked recovery appends attempts and discloses possible duplicate provider work.
11. **A18** Given saved source and a failed, missing or corrupt build, including output from a completed run, when rebuilding, then no generation request or provider credential is required.

### User Story 3 - Observe truthful progress (Priority: P2)

The operator follows submissions, uploads, source processing, Lessons, generation, builds, controls and recovery through workflow history and local operational logs.

**Why this priority**: Monitoring must explain what ran and why work stopped.

**Independent Test**: Compare actual activity history, ordered events, retained artifacts and displayed counters for a run with recovery and partial failure, including worker-offline status.

**Acceptance Scenarios**:

1. **A19** Given executed operations, when inspecting events, then starts, dispatches, captures, completions and failures have correlated identities and measured or explicitly unknown duration.
2. **A20** Given replay, when reconstructing execution, then persisted events and their log export are not duplicated.
3. **A21** Given unknown totals/usage or an unavailable worker, when requesting status, then unknown/last-known values and their age are displayed honestly.
4. **A22** Given credential/content sentinels, when recording results/errors, then history, logs and indexed metadata exclude them.
5. **A23** Given service restart, when persistent storage is reused, then accepted execution survives; history expiry does not delete artifacts.
6. **A24** Given history rotation with queued work and controls, when continuing, then identities and progress survive without prematurely releasing a subject slot.

### User Story 4 - Reuse saved Lesson results (Priority: P2)

The operator submits saved handoffs for durable scene generation without reuploading PDFs or rerunning NotebookLM.

**Why this priority**: Completed results remain useful independently of NotebookLM availability.

**Independent Test**: Submit supported handoffs with NotebookLM credentials absent; scene capture/build proceeds using only the selected scene provider and local tools.

**Acceptance Scenarios**:

1. **A25** Given supported saved handoffs, when submitted, then inputs are frozen and no NotebookLM operation occurs.
2. **A26** Given a failed scene among valid scenes, when finishing, then later scenes proceed and accurate partial counts retain successful artifacts.
3. **A27** Given standalone commands and legacy workflows, when terminal orchestration is added, then their interfaces and recorded histories remain compatible.
4. **A28** Given substituted providers, real builds and optional live tests, when reporting results, then evidence distinguishes these lanes and skipped live gates remain open.

### Edge Cases

- Empty/duplicate/changed input; unsupported or interrupted sources; unknown map count; incomplete conversation history.
- Lost acknowledgements; full admission queue; scheduler rotation delayed by a paused child; worker-offline queries.
- Old process alive after timeout; receipt/journal disk failure; SDK cookie writes racing the master cache; external refresh command launching a browser.
- Parsing/build failures; corrupt/deleted artifacts; provider/toolchain drift; concurrent recovery; cancellation of owned subprocesses.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Accept one/multiple subjects with one/multiple PDFs each; create a new notebook per new subject using unchanged PDFs/framework.
- **FR-002**: Admit at most four subject execution chains across submissions, queue in order, and serialize turns per conversation.
- **FR-003**: Preserve the approved Divide/Execution/first-Lesson/Continue sequence and block if the map supplies no determinable work count.
- **FR-004**: Retain each response before advancement, associate exports with Lesson identity/title and accept variable scene counts/types without optional educational-output gates.
- **FR-005**: Finish and freeze the subject's Lesson handoffs before scene generation.
- **FR-006**: Preserve selected provider/model, approved template and existing source/build contract; no automatic fallback/probe. The demo waiver MUST NOT become a score, qualification pass or teacher approval.
- **FR-007**: Submission, operation and control identities MUST deduplicate matching inputs and reject conflicts.
- **FR-008**: External mutations MUST have durable pending records/receipts; reuse completed evidence and never blindly resend unknown delivery.
- **FR-009**: Validate anchored conversation history and the exact next turn, including repeated Continue questions.
- **FR-010**: Verify the approved mo50atiapro account/session before remote mutation; no runtime login automation or account switching.
- **FR-011**: Distinguish authentication, rate limits, transport uncertainty and invalid local inputs in failure/recovery behavior.
- **FR-012**: Pause/resume/retry/cancel MUST expose acknowledged applied/rejected outcomes, preserve artifacts and enforce current-owner/stale-command checks.
- **FR-013**: Explicit retry MUST preserve earlier attempts/disclose uncertainty; rebuild retained source without a paid request or provider credentials when compilation failed or retained compiled output is missing/corrupt, including output from a completed run.
- **FR-014**: Required receipt/event storage failure MUST stop new remote dispatch and MUST NOT count as successful progress.
- **FR-015**: Prevent conflicting standalone/recovery writers and limit local builds to two concurrently.
- **FR-016**: Record every actual operational step/attempt/control with chronological correlation and measured or explicitly unknown duration.
- **FR-017**: Display recorded stages/counts; unknown values stay unknown and offline values are labelled last-known.
- **FR-018**: Replay MUST NOT duplicate events; history rotation MUST preserve logical operation and control identities.
- **FR-019**: Logs, indexed metadata and execution payloads MUST exclude credentials and educational bodies; private artifacts remain available through references.
- **FR-020**: Persistent execution MUST survive service restart; history retention is 30 days and artifacts remain until explicitly removed.
- **FR-021**: Saved-results mode MUST accept existing handoff formats without NotebookLM credentials/calls and preserve Lesson-to-scene association.
- **FR-022**: Individual scene failures MUST permit later scenes, retain successes and yield truthful partial outcomes.
- **FR-023**: Preserve standalone command compatibility and legacy workflow histories; terminal results MUST NOT mutate production ownership/qualification/review state.
- **FR-024**: Tests/reports MUST separate orchestration fixtures, real builds/browser checks and live providers. Skips remain open gates. Meaningful behavior tests MUST precede their implementation.

### Key Entities *(include if feature involves data)*

- **Submission**: An immutable operator request and its ordered subjects.
- **Subject execution**: One durable journey, notebook binding, cursor, controls and final outcome.
- **Operation attempt**: One intended action, dispatch evidence and retained receipt.
- **Lesson handoff**: Captured response and best-effort scenes associated with a mapped Lesson.
- **Scene execution**: Selected prompt, provider attempts, source and build outcomes.
- **Control receipt**: A uniquely identified command and its requested/applied/rejected state.
- **Operational event**: Ordered metadata describing an actual step and its evidence.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An eight-PDF subject reaches all mapped Lessons and scene outcomes without manual chat advancement or changed PDF/prompt bytes.
- **SC-002**: Five submitted subjects produce four admitted chains, one queued subject and zero overlapping conversation turns.
- **SC-003**: Controlled crash tests reuse 100% of completed receipts without remote resend and keep 100% of unverifiable sends explicitly uncertain.
- **SC-004**: Every captured Lesson/attempted scene is traceable to retained input/result with accurate final counts.
- **SC-005**: All accepted work survives worker/service restart and remains discoverable from recorded progress.
- **SC-006**: Applied pause/cancel prevents later dispatch; every rebuild-only recovery makes zero generation requests.
- **SC-007**: Every tested boundary produces its expected event; replay adds zero duplicates and sentinel checks find zero secret/content leaks.
- **SC-008**: Saved-results runs make zero NotebookLM operations and evidence clearly separates live requests from substituted providers.

## Assumptions

- Local operator tool on one Linux machine, over the terminal NotebookLM runner and [Spec 017](../017-v3-terminal-scene-generation/spec.md); Laravel integration is later.
- Owner chose four subjects, native workflow UI/logs, scenes after all Lessons, and new runs plus saved handoffs. Unfinished legacy checkpoint import is excluded.
- mo50atiapro is the owner-approved account. Locally configured proof/credentials establish its binding; subscription status does not prove unlimited provider capacity.
- Permissive capture is the current demo choice. Warnings do not authorize rewriting approved prompts or discarding received output.
- Production ownership/teacher review remain governed by [the V3 map](../V3-spec-map.md). Local artifacts are not application approvals.
- This is documentation only. [Acceptance matrix](acceptance-matrix.md), [plan](plan.md) and [CLI contract](contracts/cli.md) define evidence for the future implementer.
