# React frontend redesign: page and skill implementation plan

Prepared from the current repository on 2026-10-13. This is an implementation handoff for another agent. The requested work is a complete visual redesign of the existing frontend, including light and dark modes, with suitable skills assigned to every screen.

## 1. Current implementation and scope

- The frontend uses React JSX, Inertia, Tailwind, and Vite. Use the existing stack. TypeScript conversion is a separate task and is not required for this redesign.
- Entry: `resources/js/app.jsx`; styles: `resources/css/app.css`; document shell: `resources/views/app.blade.php`.
- Screen mapping: `app/Support/InertiaPage.php`; routes: `routes/web.php`, `routes/auth.php`, and `routes/v3.php`.
- There are 23 React page files, including `Legacy.jsx`, and 66 literal screen identifiers in controller calls to `InertiaPage::render`. Several distinct controller screens currently render through that generic page. Its generic forms and object rendering need purpose-built replacements.
- `Dashboard.jsx` serves teacher, student, owner, and limited-admin variants. Each variant needs its own information hierarchy within shared components.
- Existing pages repeat their own headers, surfaces, controls, and spacing. Dark mode currently includes selectors that override individual Tailwind classes. Build a shared semantic theme and layouts first.
- Laravel retains authentication, validation, authorization, private assets, and existing workflow behavior. Read the relevant controller before changing a page's form or data rendering.
- Questions, exam questions, and mind maps remain owner-only. Limited admins receive operational information only. Obtain capabilities from the server; do not infer ownership from an email string in React.
- Keep system error details owner-only and teacher-visible PDF/policy errors safe. `steps.txt` remains internal system data.

## 2. Design direction and skill bundles

Use a calm educational studio: a recognizable learning workspace with a document-to-lesson workflow rail, clear lesson typography, and restrained semantic status colors. The workflow rail is the signature element on processing pages; reading pages prioritize content; operations pages prioritize status and actionable details.

Use `frontend-design` during the foundation pass to write a short design brief before implementation: 4–6 named palette values, light/dark surface pairs, display/body font roles with Arabic coverage, spacing, and one layout sketch per major page family. Preserve the platform name and existing terminology. Choose final values after reviewing the actual landing, dashboard, and lesson content. Record them in a small design reference so later batches reuse the decisions.

All skill files are under `.agents/skills/<SkillName>/SKILL.md`. Read the actual file before applying a skill. Do not load the entire skills directory.

| Bundle | Exact skills | Where to apply and why |
| --- | --- | --- |
| **F — Foundation** | `frontend-design`, `UIConsistency`, `AIVisualHierarchy`, `TypographySystem`, `ColorPsychology`, `AccessibilityFirst`, `EnterprisePolish` | Read once for the shared design system, role layouts, reusable controls, and acceptance rules. These rules apply to every page through shared components. |
| **A — Actions** | `MicroInteractions` | Read once when building shared forms, upload controls, dialogs, and async feedback. Reuse these components across auth, content, review, settings, and account pages. |
| **R — Reading** | `VisualLearningConstitution`, `NarrativeFlow` | Lesson reading and teacher lesson review. Arrange existing educational material into a guided sequence with progressive disclosure and bilingual readability. |
| **S — Scene** | `LearningSceneFocus`, `SceneComposition`, `InteractionEngine` | Individual interactive learning experiences and their viewer controls. One concept per scene, prominent viewport, discoverable keyboard/touch controls. Apply scene rules to the viewport, not the surrounding teacher review dashboard. |
| **3D — Conditional** | `ThreeJSArchitecture`, `AdaptivePerformance`, `CameraDirector`, `LightingDirector`, `MotionSystem`, `AnimationDirector` | Only if the task actually changes Three.js scene code. Use for cleanup, rendering budgets, educational framing, lighting, and coordinated motion. Hosting or previewing an existing scene does not require loading this bundle. |

Resolve these skill conflicts explicitly:

- `AccessibilityFirst` requires a 14px minimum; use 14px labels/captions even though `TypographySystem` lists 12px labels. Educational body text is at least 16px.
- The product supports English and Arabic. Mirror directional layout where appropriate; do not force the left-to-right Z pattern from `AIVisualHierarchy` onto RTL content. Keep formulas and code direction explicit.
- Use the consistent spacing/radius scale from `UIConsistency`. UI transitions use 150/250/400ms and respect reduced motion. Educational scene motion is governed by its own purpose and accessibility constraints.
- Use text/icons alongside color. Resolve warning colors into a single amber token; reserve red for errors/destructive actions. Subject accents must not redefine status meanings.
- `NarrativeFlow` lists practice and assessment stages. Arrange stages from available authorized content; do not invent assessments or expose owner-only question banks to students.
- `LearningSceneFocus` bans portals and dashboards inside standalone learning scenes. It must not govern landing pages, management pages, course lists, or role dashboards.
- Do not select `MyFirstSkill` for this React redesign: it requires a separate approval loop and a standalone five-tab HTML scene, which does not fit these page requirements. It also conflicts with the modular `ThreeJSArchitecture` skill.
- `Glassmorphism` is not a default: it requires translucent panels and can harm dense reading/admin views. Only consider it for scene overlays if specifically requested and contrast is verified.
- `StaggeredEntrance` and `DelightEngineering` are optional later polish. Do not add fabricated streaks, mastery, percentages, sounds, or delayed controls. `imagegen` is unnecessary unless an actual illustration requirement emerges.

## 3. Page coverage matrix

F applies everywhere. A is implemented once in shared controls; rows identify where its feedback patterns matter. Paths in the current-page column are relative to `resources/js/pages/`. New page names below are suggested; the agent may reuse focused shared components while maintaining explicit screen mappings.

### Public, authentication, profile, and dashboards

| Screen or role variant | Current page | Skills | Required result |
| --- | --- | --- | --- |
| Landing `/` | `Home.jsx` | F | Product-specific hero illustrating PDF → lessons → review → publication; clear teacher/student entry points; responsive navigation; intentional typography and real product copy. |
| `auth.login` | `Legacy.jsx` | F + A | Shared AuthLayout; labeled email/password; remember-me; password reset link; submit/loading/error states. Suggested `Auth/Login.jsx`. |
| `auth.register` | `Legacy.jsx` | F + A | Clear account creation form, confirmation field, validation and sign-in link. Suggested `Auth/Register.jsx`. |
| `auth.forgot-password` | `Legacy.jsx` | F + A | Focused email form and accessible sent-link confirmation. Suggested `Auth/ForgotPassword.jsx`. |
| `auth.reset-password` | `Legacy.jsx` | F + A | New password/confirmation; retain reset token and email behavior. Suggested `Auth/ResetPassword.jsx`. |
| `auth.confirm-password` | `Legacy.jsx` | F + A | Focused security confirmation, correct current-password autocomplete and return behavior. Suggested `Auth/ConfirmPassword.jsx`. |
| `auth.verify-email` | `Legacy.jsx` | F + A | Verification instructions, resend feedback, and sign-out. Suggested `Auth/VerifyEmail.jsx`. |
| `profile.edit` | `Profile.jsx` | F + A | Distinct profile, password, and account-deletion sections; visible validation; safe destructive confirmation. |
| Teacher dashboard | `Dashboard.jsx` | F + A | Main action: create subject/upload PDF. Recent runs, lessons awaiting review, publication controls, and approved scenes ordered by what needs attention. |
| Student dashboard | `Dashboard.jsx` | F | Published subjects/lessons and approved published scene links; useful empty state. Only show continuation/history metrics if supplied by the backend. |
| Owner dashboard | `Dashboard.jsx` | F + A | Content management, teacher permissions, account controls, runs/review workload, and operations entry points. |
| Limited-admin dashboard | `Dashboard.jsx` | F | Operational tasks and service health; capability-aware navigation and operational summaries. |

### Subjects, lectures, sections, questions, and legacy management

Each listed variant is a separate screen to verify, even when a focused shared resource component implements multiple variants.

| Screens | Current page | Skills | Required result |
| --- | --- | --- | --- |
| `subjects.index` | `Legacy.jsx` | F | Search/filter only when supported; readable subject collection, publication/access state if supplied, create action for authorized users. |
| `subjects.create`, `subjects.edit` | `Legacy.jsx` | F + A | Focused name/description form with correct create/update behavior. |
| `subjects.show` | `LearningSubjectShow.jsx` | F | Clear subject context and ordered lecture links; authorized teaching actions; published-content view for students. |
| `lectures.index` | `Legacy.jsx` | F | Subject context, usable list/pagination, permitted create/edit actions. |
| `lectures.create`, `lectures.edit` | `Legacy.jsx` | F + A | Subject selector, manual lecture fields, PDF input, and owner-authorized mind map handling. Preserve real field/file rules. |
| `lectures.show` | `LearningContentShow.jsx` | F + R | Lecture overview, readable summary, ordered sections, safe downloads where authorized, next-lecture navigation. |
| `sections.index` | `Legacy.jsx` | F | Section list with lecture/subject context and permitted management actions. |
| `sections.create`, `sections.edit` | `Legacy.jsx` | F + A | Group actual lesson fields with clear labels, bilingual text areas, and field validation. |
| `sections.show` | `LearningContentShow.jsx` | F + R; S for an actual scene viewport | Core concept, Egyptian Arabic explanation, formulas, examples, and navigation. Surface approved scene links only from authorized data. Preserve content accurately. |
| `questions.index` | `Legacy.jsx` | F | Owner question library with existing subject/lecture filters and pagination. |
| `questions.create`, `questions.edit` | `Legacy.jsx` | F + A | Owner authoring form: prompt, hint, explanation, image uploads, and current authorized image references. |
| `questions.show` | `Legacy.jsx` | F + R | Owner-only readable question/hint/solution presentation with protected assets. |
| `exam_questions.index` | `Legacy.jsx` | F | Owner exam library with existing filters, legible rows, and authorized actions. |
| `exam_questions.create`, `exam_questions.edit` | `Legacy.jsx` | F + A | Owner exam question form with real image/explanation fields and update behavior. |
| `exam_questions.show` | `Legacy.jsx` | F + R | Owner-only exam prompt and solution detail with protected asset rendering. |
| `users.index`, `users.show` | `Legacy.jsx` | F | Account list/detail with capability-aware actions and readable role/state labels. |
| `users.create`, `users.edit` | `Legacy.jsx` | F + A | Account form with a real role selector, correct password requirements, and existing validation. |
| `teacher_permissions.index`, `teacher_permissions.show` | `Legacy.jsx` | F | Owner access list/detail showing teacher, subject, and lecture scope in plain language. |
| `teacher_permissions.create`, `teacher_permissions.edit` | `Legacy.jsx` | F + A | Owner scope selection using actual teacher/subject/lecture props and dependent choices where supported. |
| `teacher-study-flow.lectures` | `Legacy.jsx` | F + A | Teacher-specific manual lecture/section workflow with explicit next action and existing permission scopes. |

Suggested focused families: `Subjects/*`, `Lectures/*`, `Sections/*`, `Questions/*`, `ExamQuestions/*`, `Users/*`, `TeacherPermissions/*`, and `Teacher/ManualLectures.jsx`. Share layout/list/form primitives; write domain-specific fields and actions. Replace generic object dumps with named content and operational fields.

### Teacher upload, processing, lessons, review, and evidence

| Screen | Current page | Skills | Required result |
| --- | --- | --- | --- |
| `v3.teacher.workspace` | `Teacher/Workspace.jsx` | F + A | Consistent teacher navigation and direct upload/manual-teaching entry points. |
| `v3.teacher.subject-runs.create` | `Teacher/RunCreate.jsx` | F + A | Ordered multi-PDF selection, per-file title/removal, real upload progress, format/size validation, and current generation/qualification choices. |
| `v3.teacher.subject-runs.show` | `Teacher/RunShow.jsx` | F + A | Workflow rail, persisted completed lessons, per-PDF status, current phase/position, safe errors, and valid retry/pause/resume/cancel actions. |
| `v3.teacher.subject-runs.scenes` | `Teacher/RunScenes.jsx` | F + A | Scene workbench with source lesson context, manual import/export, current provider choices, qualification status, and permitted generation requests. |
| `v3.teacher.pdf-uploads.index` | `Teacher/PdfUploadIndex.jsx` | F + A | Shared upload control plus upload history with usable status and next actions. |
| `v3.teacher.pdf-uploads.show` | `Teacher/PdfUploadShow.jsx` | F + A | File metadata, safe processing outcome, protected download, process/reprocess action, and route to its case. |
| `v3.teacher.cases.show` | `Legacy.jsx` | F + A | Dedicated case overview with meaningful state, source/lesson/prompt/review links and permitted actions. Suggested `Teacher/CaseShow.jsx`. |
| `v3.teacher.cases.progress` | `Legacy.jsx` | F + A | Dedicated case progress view using actual projection data and shared status components. Suggested `Teacher/CaseProgress.jsx`. |
| `v3.teacher.cases.source-status` | `Legacy.jsx` | F | Source inventory/status, actionable safe errors, and upload context. Suggested `Teacher/SourceStatus.jsx`. |
| `v3.teacher.prompts.status` | `Legacy.jsx` | F | Prompt/version readiness and available next steps; readable status detail. Suggested `Teacher/PromptStatus.jsx`. |
| `v3.teacher.cases.conflict` | `Teacher/CaseConflict.jsx` | F + A | Clear stale/conflicting-action explanation and a working recovery link. |
| `v3.teacher.lessons.map` | `Teacher/LessonMap.jsx` | F | Hierarchical PDF/lesson/section map, saved/review/publication status where supplied, and reliable section navigation. |
| `v3.teacher.lessons.section` | `Teacher/LessonSection.jsx` | F + R | Structured generated lesson fields, accurate bilingual content, formulas, scene context, and review/navigation actions. |
| `v3.teacher.reviews.index` | `Teacher/ReviewIndex.jsx` | F | Review queue ordered by existing data, qualification status, reminders, and clear review links. |
| `v3.teacher.reviews.show` | `Teacher/ReviewShow.jsx` | F + A + R; S inside preview only | Large preview beside collapsible lesson context; clear approval/rejection/correction controls, required notes, eligibility, and async feedback. Keep preview session/frame protocol. |
| `v3.teacher.reviews.approvals` | `Teacher/Approvals.jsx` | F + A | Approved scene list with version/context and existing publication/correction actions where authorized. |
| `v3.teacher.evidence.show` | `Legacy.jsx` | F + A | Teacher-safe evidence summary and authorized export/download. Suggested `Teacher/Evidence.jsx`. |
| `v3.teacher.evidence.approval` | `Legacy.jsx` | F | Approval-specific evidence with readable review/version context. Suggested `Teacher/ApprovalEvidence.jsx`. |

Run progress must count backend-persisted lessons only. Unknown total means an indeterminate state; known total means “N of M lessons complete.” Failed/partial runs must not look successful. Poll at the configurable interval (default 5 seconds), prevent overlap, stop at terminal states, and clean up timers/requests. Display the actual interval rather than hardcoded copy. Preserve the existing projection contract; report backend count inconsistencies separately instead of inventing client estimates. Announce meaningful progress changes with a restrained live region.

### Owner/admin settings and operations

| Screen | Current page | Skills | Required result |
| --- | --- | --- | --- |
| `v3.admin.workspace` | `Admin/Workspace.jsx` | F + A | Role/capability-aware operations overview and session-health feedback; protected configuration controls only for authorized actors. |
| `v3.admin.accounts.index` | `Admin/Accounts.jsx` | F + A | Account access table, clear current state, accessible change controls, and stale/failed update handling. |
| `v3.admin.settings.show` | `Admin/Settings.jsx` | F + A | Group known settings into labeled controls where their schema supports it; retain advanced JSON editing and optimistic version checks. Do not expose secrets. |
| `v3.admin.settings.history` | `Admin/SettingsHistory.jsx` | F | Scannable version history with timestamps/actor information only when supplied. |
| `v3.admin.settings.templates` | `Admin/SettingsTemplates.jsx` | F | Readable approved-template/version list using actual available metadata. |
| `v3.admin.cases.index` | `Legacy.jsx` | F | Operational case queue with safe states, filtering/pagination if supported. Suggested `Admin/CasesIndex.jsx`. |
| `v3.admin.cases.summary` | `Legacy.jsx` | F + A | Operational case summary with server-filtered details and permitted actions. Suggested `Admin/CaseSummary.jsx`. |
| `v3.admin.subject-runs.index` | `Legacy.jsx` | F + A | Run list with persisted progress/status and permitted run controls. Suggested `Admin/RunIndex.jsx`. |
| `v3.admin.operations.index` | `Legacy.jsx` | F | Service/operations overview with actionable status and clear incident navigation. Suggested `Admin/OperationsIndex.jsx`. |
| `v3.admin.operations.show` | `Legacy.jsx` | F + A | Case operational timeline, safe metadata, command/reminder feedback, and permitted detail. Suggested `Admin/OperationShow.jsx`. |
| `v3.admin.evidence.index` | `Legacy.jsx` | F | Operational evidence/export list reflecting the controller's access-filtered projection. Suggested `Admin/EvidenceIndex.jsx`. |

### Errors, scene viewing, and endpoints that do not currently render pages

| Surface | Current behavior | Skills | Plan |
| --- | --- | --- | --- |
| `v3.errors.403` | `Legacy.jsx` | F | Dedicated access-denied state and authorized return destination. |
| `v3.errors.404` | `Legacy.jsx` | F | Resource-unavailable state without disclosing private resource existence. |
| `v3.errors.409` | `Legacy.jsx` | F + A | Conflict message with safe refresh/recovery behavior. |
| `v3.errors.503` | `Legacy.jsx` | F + A | Service-unavailable state with retry feedback where appropriate. |
| `/scenes/{approval}/{file}` | Authorized published HTML/assets, not an Inertia page | S; 3D only when changing scene code | Keep private serving/publication rules. Improve the surrounding learner navigation/scene link from authorized data; use scene-first rules inside actual scene content. Do not bulk regenerate or mutate approved artifacts. |
| Review preview viewer sessions and frames | JSON/frame protocol | S for controls, F + A for host page | Improve `Teacher/ReviewShow.jsx` and its controls while retaining session cleanup and protocol. |
| Teacher subject run index `/v3/teacher/subjects/{subject}/runs` | JSON-only | F if adding its UI | Consume existing authorized data in subject/teacher pages. A dedicated `Teacher/RunIndex.jsx` can be introduced if needed; preserve JSON responses for clients. |
| Teacher scene authoring/qualification status | JSON-only | F + A in host UI | Render meaningful status in case/scenes pages; preserve these endpoints. |
| Admin individual run summary | JSON-only | F + A in run UI | Consume the authorized summary in a run detail panel/page without changing the JSON contract. |
| Progress/events/health/filter APIs, downloads, exports, owner assets | JSON/files | Shared host-page skills | These are data/file endpoints, not missing React pages. Keep correct headers, private serving, and existing authorization. |
| `/v3`, study-flow start/exit routes | Redirects | Destination-page skills | Verify destination routes; no extra page needed. |
| `/mohammed-test` | Authenticated diagnostic JSON | None for redesign | Existing diagnostic response, not a product page. Keep out of product navigation. |

Use a shared `Errors/Status.jsx` if desired, with explicit mappings for the four error screens and preserved HTTP status codes. At completion, no known user-facing HTML screen should depend on `Legacy.jsx` or silently resolve to it.

## 4. Implementation batches and token use

Execute in this order. Each batch reads its target pages, the relevant controller props/actions, the design reference, and only the listed additional skills. Shared F/A decisions should be reused rather than redesigned per screen.

| Batch | Work | Read skills | Completion gate |
| --- | --- | --- | --- |
| 0 | Foundation: tokens, theme, layouts, shared components | F + A | Landing, teacher dashboard, lesson reader, and operations screen demonstrate one coherent system in both themes. |
| 1 | Landing, six auth screens, profile, four dashboard variants | Reuse F/A; reopen a skill only if needed | Each role has clear navigation and correct forms. |
| 2 | Subject/lecture/section lists and authoring; users, permissions, manual teaching; owner questions/exams | Reuse F/A; R for question detail presentation | Domain-specific forms/lists cover every variant; protected content remains protected. |
| 3 | Teacher upload, run, case, status, and evidence pages | Reuse F/A | Upload/run workflow rail, progress states, safe errors, and controls are usable. |
| 4 | Subject/lecture/section reading, teacher lesson map/section | R | English/Arabic content, formulas, navigation, and authorized scene entry points are readable. |
| 5 | Teacher scene workbench, review queue/detail, approvals, learner scene host | S; reuse F/A/R | Preview and decisions work with preserved session behavior and accessible controls. Load 3D only if actually editing a scene renderer. |
| 6 | Admin operations, cases, runs, evidence, accounts, settings, errors | Reuse F/A | Owner and limited-admin variants show correct data/actions; all known screen mappings are explicit. |
| 7 | Visual and functional verification | Reuse acceptance reference | Route coverage, representative screenshots, build, and targeted existing regression tests pass. |

Suggested shared components: `PublicLayout`, `AuthLayout`, `AppLayout` with server-driven role navigation, `PageHeader`, `Breadcrumbs`, `Button`, `FormField`, `SelectField`, `FileUpload`, `StatusBadge`, `EmptyState`, `ErrorSummary`, `Pagination`, `DataTable`, `ConfirmDialog`, `WorkflowRail`, `LessonProgress`, `PdfStatusList`, `LessonContent`, and `SceneViewport`.

The theme should use semantic CSS variables for canvas/surface/text/border/action/status/focus colors. Apply the variables through shared components and Tailwind-compatible styles. Keep the existing saved theme preference, system default, and pre-render theme initialization. Place the theme control in a consistent layout location that does not obscure mobile content. Support reduced motion and RTL at the foundation level.

For efficient handoffs, finish each batch with a short entry in the implementation report: changed files, completed screen IDs, design decisions, checks/results, and concrete remaining issues. Another agent should need this plan, the design reference, and that report rather than the full conversation. Do not reread every controller or every skill for each page. Do not add an orchestration framework for this work.

## 5. Verification and definition of done

- Inventory every HTML screen from controller `InertiaPage::render`/`Inertia::render` calls and compare it with explicit page mappings. Also inspect redirect and JSON/file routes to avoid treating APIs as pages.
- Run `npm run build` after foundation and final integration. Use existing relevant Laravel feature tests when changing controller mappings/forms; run targeted existing route/access regressions for each affected family.
- Check every screen with representative authorized data and its empty state. Exercise loading, field validation, action success/failure, disabled controls, and stale/conflicting actions where supported.
- Inspect screenshots at approximately 390px, 768px, and 1440px for landing, dashboards, upload/run, lesson, review, and operations. Confirm all remaining pages inherit the shared responsive behavior. Inspect both light and dark themes.
- Verify keyboard navigation, skip link, visible focus, labels/error associations, dialog focus handling, touch targets, progress announcements, reduced motion, text contrast, mixed Arabic/English content, and formulas/code direction.
- Verify role boundaries with existing tests/direct requests: teacher/student/owner/limited admin; owner-only educational routes/assets; student published lessons and approved published scenes. Hiding a link does not replace route authorization.
- Preserve form endpoints, HTTP methods, CSRF, upload encodings, validation props, pagination, safe downloads, publication eligibility, preview protocols, and version/idempotency fields.
- Cover run states: empty, staging/queued, running, unknown total, known total, paused, partial, failed, retried, completed, and cancelled. Verify no overlapping polling and no polling after terminal state/unmount.
- UI review can use existing saved data and fixtures without a healthy NotebookLM session. A real PDF → lessons → scenes → review → publication run is a separate live verification gate; report it honestly if live auth or scene generation is unavailable.
- Deliver a concise implementation report with completed page coverage, screenshots/artifact paths, build/test results, and remaining gates. Do not claim visual quality based on build output alone.

## 6. Copy-ready instruction for the implementing agent

```text
Implement docs/FRONTEND-SKILL-IMPLEMENTATION-PLAN.md across every listed React/HTML product screen. Read applicable repository instructions, inspect the current code, and preserve existing unrelated work. Start with batch 0 and record the design tokens/reference. Use the exact local skills assigned to each batch, reading each once and reusing shared components. Execute all batches through visual and functional verification. Replace known Legacy screen fallbacks with explicit purpose-built mappings. Keep Laravel auth, role boundaries, existing URLs, backend contracts, private files, and workflow behavior intact. Support light/dark modes, English/Arabic, mobile, keyboard access, and reduced motion. Keep a short batch completion report so work can continue efficiently across contexts. Report real live-provider gates separately from frontend completion. Do not stop after only redesigning the landing page or dashboard.
```
