# Feature Specification: V3 Versioned Settings

**Reviewed**: 2026-10-01 · **Status**: documentation design · **Prerequisite**: 004 access and ownership

## Purpose and boundary

One pilot-wide configuration governs new V3 cases. An active admin publishes a complete, validated version; publication never mutates an older version. A case created by 006 stores one version ID and every later stage reads that captured version. This spec delivers the settings authority and selection interface, not a ProcessingCase table, provider adapter, Temporal workflow, or teacher content route.

Teacher and admin are the pilot roles. Admin may see operating settings and safe publication history, never teacher source, prompts, scenes or review notes. The original teacher owns content and reviews the final scene. The canonical dividing, execution, First, Second and Third prompts in 009 are immutable inputs, outside admin-editable settings. NotebookLM maps learning units; JEV's successful typed Choice/Score/Noul decisions are applied directly by 010/012 without another suitability model. V2, KOS/KIE and multitenancy are outside this feature.

## User stories and acceptance

### US1 — Publish operating settings

An active admin can inspect the current version and publish a complete replacement using the expected active version. See [settings contract](contracts/settings.md) for exact fields and HTTP behavior.

- **S01** With approved feedback and scene templates registered, valid first publication from an empty pointer creates version 1 and activates it.
- **S02** Valid replacement with the current expected version creates the next version; the earlier row and its checksum remain unchanged.
- **S03** Missing/extra fields, wrong types, out-of-range limits/thresholds, duplicate or unknown providers, invalid model/credential references, or an attempted canonical-prompt field return validation errors and leave pointer/history unchanged.
- **S04** Guest, inactive/unenrolled admin, teacher, and student cannot publish or read settings; legacy `/users`, `/study-flow` and `/api` routes do not expose a settings action.
- **S05** Stale expected version returns conflict with no new row; two simultaneous admins using the same expected version yield exactly one committed publication.
- **S06** Database/audit failure rolls back both version insertion and pointer movement; no success is reported.
- **S07** Publishing a new version never changes a prior version's payload, digest, actor or timestamp; attempts through a model or public route cannot edit/delete one.

### US2 — Capture stable case rules

An authorized case-creation service can select the current version inside its case transaction. The 006 case feature persists the result.

- **S08** With no active version, selection returns a typed `settings_unavailable` failure and no case is created by 006.
- **S09** Selection under a real database transaction returns an existing immutable version and its ID. A concurrent publication linearizes before or after the selection; no selected version is missing or half-written.
- **S10** A 006 test-only consumer records selected version A, then after B publishes still loads A for retry/review; a newly selected case uses B. The real ProcessingCase route and FK are acceptance obligations of 006, not evidence claimed by 005.
- **S11** A downstream consumer given a missing/deleted/corrupt captured version fails closed and never silently replaces it with current settings.

### US3 — Inspect safe history

An active admin can read active settings and publication history with publisher, time, version, checksum and changed field paths.

- **S12** History is newest-first and paginated; first publication records changed paths from an empty predecessor, later publication records only changed paths. No old/new values are embedded in audit rows.
- **S13** Credential values and request bodies are absent from version/audit rows, HTTP bodies and ordinary logs. Credential reference labels are allowed only in active admin settings and version detail, not history change values.
- **S14** If a referenced credential or provider is unavailable later, the captured order remains unchanged; the consuming stage reports a typed operational failure or uses only the next captured provider under its own retry/fallback budget. No live-settings substitution occurs.
- **S15** An admin can select only immutable operator-approved feedback and scene template IDs with matching digests; an unknown/wrong-kind/mismatched template is rejected. Registering a newer approved template does not change an older case's selected template bytes.

## Functional requirements

- **FR-001** Gate all settings HTTP routes with 004's fresh active-admin access, V3 private/no-store response and web CSRF behavior. Do not add a legacy-route alias.
- **FR-002** Validate the complete version-1 settings schema and known pilot provider identifiers; reject unknown keys and credential values.
- **FR-003** Publish an immutable, checksum-identified version, active pointer and safe audit atomically under compare-and-swap expected version.
- **FR-004** Expose a transaction-bound selection operation for 006; never create a production case model or route in 005.
- **FR-005** Resolve a captured version by ID without consulting the active pointer; fail closed on missing or corrupt data.
- **FR-006** Give only active admins safe current-settings and history views with deterministic pagination and changed field paths.
- **FR-007** Keep secrets out of persisted settings, audit, responses and ordinary logs; configuration carries secret values outside this feature.
- **FR-008** Preserve canonical prompts and the selected provider/qualification rules through all downstream case activity; later specs must consume the captured version.
- **FR-009** Let admins select, but not edit, separately versioned noncanonical feedback and scene templates from an immutable trusted catalog; preserve each selected ID/digest with case settings.

## Success criteria and proof limit

- **SC-001** S01–S07/S12–S13 pass through real V3 routes and database transactions where the behavior occurs, plus direct model guards for S07.
- **SC-002** S08–S11 pass at the 005 service boundary with a test-only consumer; 006 must separately prove real case capture and route authorization.
- **SC-003** Exact payload validation and privacy assertions include a credential sentinel in request/config fixtures and verify no persisted/returned/logged value.
- **SC-004** A genuine two-connection contention test demonstrates one winner for the same expected version; sequential requests alone do not satisfy this criterion.
- **SC-005** S15 proves catalog registration, selection and captured-byte lookup with a test-approved template; no canonical 009 prompt is catalogued or editable here.

## Dependencies and exclusions

004 supplies `routes/v3.php`, active-admin middleware, access audit and private response/error conventions. The repository currently has no V3 tables/routes; 004 must be implemented first. No active settings version or template body is seeded by migration: a trusted operator registers approved noncanonical feedback and scene template files, then an admin publishes the first version before 006 can start cases. The approval of initial template wording is an operator/business prerequisite, not an implementer choice. 006 owns the non-null immutable case FK and idempotent case creation; 007, 010–013 consume captured policy. Provider implementation, live credential health, PDF scanning, OCR and JEV transport are downstream work. This spec makes no runtime integration claim for them.
