# Feature Specification: V3 Web Pipeline Integration

**Feature directory**: `019-v3-web-integration`  
**Feature Branch**: existing `main`; no branch created  
**Created**: 2026-10-10  
**Status**: Specified; implementation and live verification pending  
**Input**: Connect existing subject/lecture pages, private educational records, the per-PDF NotebookLM engine, durable orchestration, prompt exports, manual/API scene production and original-teacher review.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Bring completed terminal work into a subject (Priority: P1)

An administrator imports a verified saved run into a selected active teacher's subject. Every original PDF becomes one lecture. Its mapped lessons, retained explanations and scene prompts become navigable without copying or buying generation again. The administrator performs the operation without receiving educational content in the command output.

**Why this priority**: Existing saved work becomes useful immediately, with no provider dependency.

**Independent Test**: Import a disposable archive into an authorized teacher's subject, then browse its lectures and download the prompts while all provider transports are disabled.

**Acceptance Scenarios**:

1. **A01** Given a verified eight-PDF archive containing 63 lessons and 89 prompts, when imported, then eight lectures retain exactly those associations and counts, with no generated-scene or approval claims.
2. **A02** Given the same import identity and bytes, when repeated or resumed after an interrupted item, then committed items are reused and no duplicate lecture, lesson, prompt or provider call occurs; changed bytes or destination conflict.
3. **A03** Given a corrupt, missing, outside-root or symlinked archive artifact, when inspected, then the affected import cannot commit unverified content; dry-run makes no persistent application changes.
4. **A04** Given historical outputs, when viewed, then imported history is identified as historical and missing First-step fields remain visibly missing, with the exact original response retained.

### User Story 2 — Process lectures from the existing subject page (Priority: P1)

The original teacher submits an ordered group of PDFs on a subject page. The system does the complete process automatically, one PDF at a time within one notebook, and saves each result before proceeding.

**Why this priority**: This removes the terminal and copy/paste dependency.

**Independent Test**: Submit two small PDFs through real web routes against a deterministic external-provider double and inspect their separate maps, saved turns, lessons and prompt exports.

**Acceptance Scenarios**:

1. **A05** Given an active teacher with subject permission, when submitting ordered PDFs, then each accepted unchanged private PDF is linked to one lecture and one processing case; rejected uploads create no processing case.
2. **A06** Given two accepted PDFs, when processing, then the framework is uploaded once, each PDF receives its own Divide, Execution, Start and required Continue turns, and older PDFs are excluded from every request's source scope.
3. **A07** Given all mapped lessons for PDF 1 have been durably saved, when advancing, then archive verification and conversation reset complete before PDF 2 begins; reused remote conversation identifiers cannot merge their records.
4. **A08** Given arbitrary scene types, different scene counts or incomplete optional output projections, when capturing, then raw results and available prompts are retained without a scene-type/count gate; unknown map size or no usable prompts remains explicitly blocked.
5. **A09** Given a settled PDF-local failure, when processing the queue, then later PDFs continue automatically and the aggregate remains partial; shared authentication/quota problems or uncertain sends pause affected remote work.
6. **A10** Given export/manual mode, when all sources finish, then every saved scene prompt is exported with PDF, lecture, lesson and section identity, and zero generation API requests occur.

### User Story 3 — Produce scenes manually or through a selected API (Priority: P1)

The teacher downloads the scene descriptions for use in web providers, imports their generated HTML/CSS/JS packages, or explicitly requests API generation for selected prompts. The system stores source and runnable output with truthful provenance.

**Independent Test**: Import one scene with APIs unavailable; separately request one selected scene through a transport double and recover a failed local build without another generation request.

**Acceptance Scenarios**:

1. **A11** Given a current scene prompt, when importing its exact three-file package, then the saved candidate identifies that prompt and manual origin; claimed provider information is labeled user-reported, without fabricated API usage.
2. **A12** Given an identical repeated import, when submitted, then it reuses the receipt; a different body with the same identity or an obsolete prompt version conflicts.
3. **A13** Given explicit API mode and selected scenes, when generating, then only those scenes use captured provider/model/template/thinking settings; missing credentials fail before a paid request and no fallback provider is chosen.
4. **A14** Given an interruption after a remote send or retained response, when resumed, then verified receipts are restored locally, unproven sends become uncertain, and another paid send requires explicit duplicate-risk consent.
5. **A15** Given intact saved source and a failed/corrupt build, when rebuilding, then a new immutable build attempt restores the same educational association without provider access; source or scaffold tampering fails verification.
6. **A16** Given a saved candidate, when checks run, then actual artifact/browser evidence is required; a demo waiver is displayed as a waiver and creates no synthetic JEV pass or score.

### User Story 4 — Review the final interactive scene (Priority: P1)

The original teacher previews a checked scene, requests changes or approves it. Approval remains tied to the exact current candidate and its evidence.

**Independent Test**: Seed checked candidates in tests, load the real preview page and submit real decisions as owner, foreign teacher and admin.

**Acceptance Scenarios**:

1. **A17** Given a current checked scene with either actual JEV passes or explicitly captured demo waivers, when reviewed, then only the original active teacher may open content and decide after the first preview frame.
2. **A18** Given concurrent or stale decisions, when submitted, then exactly one current decision commits, superseded candidates cannot be approved, and delivery is complete only after exact decision consumption is confirmed.
3. **A19** Given visual or factual change requests, when handled in manual mode, then replacement prompts/instructions await manual import without unexpected API spending; API mode performs the appropriate correction and repeats checks and review.
4. **A20** Given an approval, when viewing its evidence, then source, lesson, exact prompt, candidate, checks, waiver or real JEV result, preview and decision are traceable; demo approval is not called production qualification.

### User Story 5 — Observe and control durable processing (Priority: P2)

Teachers see progress on subject and lecture pages. Admins see operational metadata and controls. Durable processing survives outages, supports bounded concurrency and records every meaningful operation without putting educational content or credentials into operational history.

**Independent Test**: Start from an empty orchestration namespace, exercise real dispatch/callback boundaries, pause/cancel/resume, worker restart and offline progress, without live providers.

**Acceptance Scenarios**:

1. **A21** Given an empty namespace or lost acceptance response, when submitting again, then one durable admission is obtained and the same accepted identity is returned.
2. **A22** Given pause/cancel during an operation or while paused, when the operation settles, then no subsequent mutation dispatches; cancellation wakes the subject and releases its capacity.
3. **A23** Given simultaneous web and terminal subjects, when scheduled, then shared capacity limits hold; waiting for manual import/review does not consume active generation capacity.
4. **A24** Given worker failure, replay, repeated callbacks or control commands, when recovered, then identity/digest/version fences prevent duplicate effects and logs/counts derive from committed evidence.
5. **A25** Given offline orchestration, when reading status, then the last-known projection, its age, unknown totals and unapplied controls are shown truthfully.
6. **A26** Given guest, inactive user, student, foreign teacher or admin access through new or existing alternate routes, then linked educational data is not disclosed or mutated outside its original-teacher boundary.
7. **A27** Given logs, histories and admin summaries, then they contain only permitted operational fields; private exports and retained educational artifacts remain owner-scoped.
8. **A28** Given existing populated application data and historical workflows, when upgrading or replaying, then unrelated content, approvals and captured legacy settings remain unchanged.

### Edge Cases

The acceptance cases include lost acknowledgments, identical bytes belonging to different teachers, partially accepted batches, stale prompt imports, shared account expiry, quota exhaustion, interruption after Continue, duplicate callbacks, unknown map totals, missing explanation fields, failed browser checks, corrupted retained builds, paused cancellation and historical imports with no generated scenes. Rejected PDFs remain visible as rejected attempts; they do not become empty successful lectures.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Existing subject/lecture pages MUST be the teacher entry point; one accepted PDF MUST become one lecture with ordered mapped lessons and scenes.
- **FR-002**: Every educational record and action MUST remain owned by its original teacher; admin access MUST remain metadata-only, including alternate routes.
- **FR-003**: Original private PDF bytes MUST remain unchanged and pass existing intake checks before remote processing; no local educational extraction/OCR is used in this path.
- **FR-004**: A run MUST capture immutable ordered inputs, framework, processing mode, qualification policy and generation settings; changes MUST require a new run or explicit versioned correction.
- **FR-005**: A run MUST use one notebook, process PDFs separately in order, preserve approved prompt bytes and exclude previous PDFs from source scope.
- **FR-006**: All lesson turns MUST be persisted before advancing; verified archive and reset MUST fence each PDF boundary.
- **FR-007**: All eleven First-step fields MUST be preserved when supplied; missing fields MUST remain missing with projection warnings and retained original response.
- **FR-008**: Arbitrary scene labels and variable scene counts MUST be accepted; required structural identities, map boundaries and artifact integrity MUST still be verified.
- **FR-009**: Settled PDF-local failures MUST allow later PDFs to continue; shared authentication/quota failures and unresolved remote delivery MUST pause affected work.
- **FR-010**: Export/manual MUST be the default; full-run, lecture and selected-scene prompt exports MUST retain exact descriptions and associations without generation calls.
- **FR-011**: Explicit selected API generation MUST support existing provider adapters, captured high-thinking settings and truthful provider evidence without silent fallback or probes.
- **FR-012**: Manual scenes MUST be associated with the exact current prompt and retained as a new candidate version with explicit manual provenance.
- **FR-013**: Scene source and runnable output MUST be retained and verified; local recovery MUST not purchase generation when verified response/source already exists.
- **FR-014**: Uncertain remote mutations MUST NOT be automatically resent; authorized regeneration MUST have a distinct attempt and duplicate-risk consent.
- **FR-015**: JEV MUST be either real typed evaluation or an explicit captured demo waiver; waiver MUST NOT fabricate scores, confidence, evaluation attempts or production qualification.
- **FR-016**: Actual browser/artifact checks and original-teacher preview/review MUST remain mandatory in both qualification modes.
- **FR-017**: Review decisions MUST fence current prompt/candidate versions and require exact durable consumption before their dispatch is marked delivered.
- **FR-018**: Factual and visual corrections MUST preserve history and repeat the relevant checks/review; manual mode MUST await manual replacement without paid generation.
- **FR-019**: Submission/control/import identities MUST deduplicate full request digests; stale versions and changed requests MUST conflict.
- **FR-020**: Durable admission and application outbox delivery MUST survive first startup, outages and acknowledgment loss without duplicate workflows.
- **FR-021**: Processing MUST share bounded subject/IO/build capacity with terminal operations and release generation capacity during manual/review waits.
- **FR-022**: Pause/cancel MUST take effect at every settled operation boundary; requested and applied controls MUST be distinguished.
- **FR-023**: Progress MUST reflect committed PDF/lesson/prompt/scene/check/review outcomes, expose control version and timestamp, and label offline projections.
- **FR-024**: Every state-changing operation MUST have durable safe metadata evidence; educational bodies and secrets MUST remain outside operational logs/history.
- **FR-025**: An admin CLI MUST import verified saved archives into an authorized active teacher/subject, with dry-run, resumability and zero remote provider calls.
- **FR-026**: Imported historical records MUST retain original lineage and distinguish historical events from newly executed import operations.
- **FR-027**: Additive rollout MUST preserve existing data, legacy captured protocols and workflow replay; linked content MUST not leak through existing public paths.
- **FR-028**: Retention/deletion MUST reuse existing owner/evidence retention rules and preserve artifacts needed for unresolved operations or retained approvals.

### Key Entities

- **Subject run**: owner, destination subject, ordered PDFs, immutable policy, durable execution and aggregate outcome.
- **Lecture item**: accepted document, lecture, case, order, separate map and outcome.
- **Notebook instance**: shared run notebook and private account/source lineage.
- **Lesson/section/prompt**: ordered content, original outputs, eleven-field projection, generated description and exact version.
- **Scene candidate**: manual/API provenance, verified source/runnable package, actual checks and eligibility basis.
- **Qualification gate**: real evaluation references or explicit demo-waiver evidence.
- **Command/event/import receipt**: stable request identity, captured digest, durable application/orchestration acknowledgment and safe counts.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The verified reference archive imports as eight lectures, 63 lessons and 89 prompts, with zero remote requests or invented generated scenes.
- **SC-002**: A two-PDF web run completes each PDF's separate map/lesson loop and exports every saved prompt with zero missing or crossed associations.
- **SC-003**: Default export/manual runs make zero scene-generation requests; an explicit one-scene request generates only that scene.
- **SC-004**: Every approved scene has actual checked runnable evidence and a current original-teacher preview/decision, with its qualification mode visible.
- **SC-005**: Repeated submissions, imported items, controls and recovered settled effects create zero duplicate records or automatic uncertain sends in interruption/concurrency scenarios.
- **SC-006**: All tested alternate access routes deny unauthorized linked content; operational histories contain zero injected content/credential sentinels.
- **SC-007**: Progress displays actual counts and a last-update timestamp, with unknown totals and offline information clearly labeled, throughout failure and review waits.

## Assumptions and boundaries

Teacher and admin were the pilot roles for the original release. The following release amendment supersedes the earlier student-delivery exclusion and the metadata-only rule for the single platform owner. The approved NotebookLM account remains operator-managed; a completed terminal archive is not proof of a healthy current session. Tests with doubles prove application wiring, not live provider service.

## Release amendment: unified lessons and publication

- Every completed PDF uses its linked legacy lecture. Every V3 lesson section is reconciled to one legacy section in the same transaction. The six legacy fields are mapped from `section_identity`, `conceptual_summary`, `core_understanding`, `colloquial_egyptian_explanation`, `laws_if_any`, and `real_life_example`; the original eleven fields and source evidence remain in V3. Replays and dry-run backfills cannot duplicate sections or overwrite manual edits. Partial historical imports report missing fields and remain unpublished.
- The original teacher publishes a complete V3 lesson independently of other lessons. Publication releases its linked legacy sections to students through subject, lecture, section, and API routes. Corrections revoke release until republished. Scene release is separate and requires an approved current scene plus a published lesson. The student scene route serves only the approved static package. `dynamic_view_link` is set only while that route is usable.
- A teacher-created subject starts owned by that teacher and receives subject-level permission before a lecture exists. Other teachers require explicit assignment. Newly created subjects stay absent from student listings until released content exists.
- Questions, exam questions, their favorites, bulk creation, and mind maps remain owner-only, including direct routes and image bytes. Existing public images move to private storage. `concept_check` remains internal and is omitted from teacher and student displays.
- `admin@test.com` is the single explicit platform-owner capability. `admin@example.com` and other admins retain operational metadata access without private teacher content. Owner interventions retain the real actor in the publication and correction audit.
- New teacher PDF runs use manual scene export. Paid teacher generation and demo waiver controls are disabled by default and require explicit owner configuration. Existing runs keep their captured mode. The web uploader accepts at most 20 PDFs; 32-part prepared batches from a 1,000-page source require a separate web upload feature.

Prerequisites: existing access/settings/intake/case/evidence contracts in 004–015, direct source components in 016, current terminal scene services in 017, and per-PDF durable engine in 018 as amended by `docs/PER-PDF-NOTEBOOKLM.md`. The old React-only wording in 017/018 is historical: this feature uses the current approved three-file HTML/CSS/JS contract described in its own design artifacts.
