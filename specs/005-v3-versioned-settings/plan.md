# Implementation Plan: V3 Versioned Settings

**Reviewed**: 2026-10-01 · **Authority**: [spec](spec.md), [settings contract](contracts/settings.md), [data model](data-model.md)

## Repository baseline and prerequisites

`composer.json` requires PHP ^8.2 and Laravel ^12.0. `tests/Pest.php` applies `RefreshDatabase`; `phpunit.xml` uses SQLite `:memory:` for feature tests. `routes/web.php` presently has legacy role and content routes and no V3 routes. `bootstrap/app.php` has only `role` and `teacher.access` aliases. `config/services.php` contains no V3 provider credentials; `.env.example` supplies none. The 004 contract, once implemented, provides `routes/v3.php`, active-admin middleware and private V3 responses. Verify those artifacts actually exist before implementation; stop if 004 is incomplete rather than recreating its authority in 005.

The checked-in constitution is an unratified placeholder. The binding rules here are the owner's V3 decisions and test-first execution. No code from V2 is reused.

## Architecture and file ownership

| Area | 005 deliverable |
|---|---|
| Schema | `database/migrations/2026_10_01_000005_create_v3_settings_tables.php` |
| Models | `app/Models/V3/SettingsVersion.php`, `SettingsPublication.php`, `ActiveSettings.php`, `ApprovedTemplate.php` |
| Template import | `app/Console/Commands/V3/RegisterApprovedTemplate.php`; operator-provided approved UTF-8 file, not a seeded default |
| Validation/publish | `app/Http/Requests/V3/PublishSettingsRequest.php`; `app/Services/V3/Settings/SettingsSchema.php`, `PublishSettings.php`, `SettingsUnavailable.php` |
| Selection/read | `app/Services/V3/Settings/SelectCaseSettings.php`, `SettingsVersionRepository.php`, `ApprovedTemplateRepository.php`, `CanonicalSettings.php` |
| HTTP | Append routes to 004's `routes/v3.php`; `app/Http/Controllers/V3/Admin/SettingsController.php`; explicit serializers in `app/Http/Resources/V3/SettingsResource.php`; `resources/views/v3/admin/settings/{show,history}.blade.php` |
| Tests | `tests/Feature/V3/Settings/` and `tests/Integration/V3/Settings/` per [tasks](tasks.md) |

No new `config/services.php` entry or environment value is required for 005's publication validation. The `credential_ref` labels are known enums, and 005 never reads credential values. Later provider adapters configure and resolve them at execution time. The template catalog is a separate immutable table populated only through a trusted local import of owner-approved files; 005 does not author the text. Do not build a provider registry, fake adapter or production case table to make 005 tests pass.

## Ordered design

1. Run baseline checks and record current workspace changes. Implement an additive schema with an empty singleton pointer and empty approved-template catalog. Test and implement the trusted import command and captured-byte lookup. No default active version or template text is invented. Public request, model and event shape are fixed by the contract.
2. Test exact schema validation and real role boundary, then implement explicit allowlist validation. The legacy `TeacherAccessMiddleware` is unsuitable: it grants admin access to teacher content and has no V3 enrollment check. Use 004's V3 middleware on every settings route, with web CSRF and private responses. Existing `/users` account management and `/study-flow` remain isolated and cannot publish settings.
3. Test stale, duplicate and concurrent publication using independent connections. Publish takes 004's access lock/fresh actor check, then first-writes the pointer row and inserts version, event and pointer update in one transaction. Use one serial ordering for first publish and later publish. A failed audit or DB write rolls back all. No external provider health check occurs in that transaction.
4. Test a transaction-bound selection against publication and historical lookup. The selection service uses the caller's connection and pointer first-write lock. 006 later inserts its case FK inside that transaction; 005's test-only consumer proves only the handoff semantics, not a real case route.
5. Test current, template-catalog and history projections through real 005 routes, pagination and sentinel privacy. Return allowlisted fields only; no template body in admin responses; audit holds paths, never values. Keep old versions and selected templates for case reproducibility.
6. Run the focused tests, route inventory, static docs/secret inspection and changed PHP formatter check. Report what passed and what remains for 006/007/010–013.

## Failure and recovery

No active version means 006 cannot start a case, while admin GET displays the empty state. Before an operator registers owner-approved feedback and scene templates, publish validation cannot succeed; the settings UI shows an empty selection list, not fabricated defaults. Stale publish is 409 and never writes a version/event. Retry after uncertain HTTP success must reload active version/history before submitting a new expected version; replay of an old expected version conflicts, and there is no false idempotency promise. Missing/corrupt captured version or selected template blocks downstream processing; it cannot migrate to latest. Runtime provider or credential outage is a downstream typed operational failure under captured order and budget. Admin audit/event write failure aborts publication; 004 denial auditing handles access failures.

## Downstream obligations

[Integration contract](contracts/integration-obligations.md) distinguishes 005 evidence from required real case, workflow, JEV, authoring, scene and review evidence. This spec can finish after 004 even though those production entities do not yet exist.
