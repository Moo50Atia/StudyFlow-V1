# Contract: V3 Settings v1

## Complete publish payload

`POST /v3/admin/settings` accepts exactly `{expected_active_version,limits,qualification,providers,templates}` in JSON; the HTML form uses the same nested field names. `expected_active_version` is a nonnegative integer and is `0` only before first publication. No partial PATCH or implicit defaults. Integers are JSON integers or base-10 integer form strings; reject floats, booleans as integers, exponent strings and null. Reject extra/missing keys at every level. `_token` and `_method` are framework fields, never business fields.

| Field | Type and valid range | Downstream use |
|---|---|---|
| `limits.max_pdf_bytes` | integer 1–104857600 | 006 byte cap |
| `limits.max_pdf_pages` | integer 1–1000 | 006 parsed page cap |
| `limits.max_ocr_pages` | integer 0–`max_pdf_pages` | 008 OCR page cap; zero disables OCR, unreadable pages block |
| `limits.technical_retries` | integer 0–10 | 007 additional retries after first attempt per retryable activity |
| `limits.retry_delay_seconds` | integer 1–3600 | 007 base retry delay |
| `limits.prompt_revisions` | integer 0–10 | 010 revised versions after original |
| `limits.scene_repairs` | integer 0–10 | 012 repaired candidates after original |
| `limits.coding_attempts` | integer 1–20 | 011 total attempts per authoring request, including first and fallback; a 012 repair is a new request still bounded by `scene_repairs` |
| `limits.review_dormancy_days` | integer 1–365 | 013 dormant/reminder threshold measured from review-ready time, never auto-approval |
| `qualification.prompt` and `.scene` | each exactly `{min_score,min_confidence}`; each integer 0–100 | 010/012 inclusive quality and confidence thresholds |
| `providers.prompt` and `.feedback` | one descriptor each | 010 generator and feedback generator; JEV is separate |
| `providers.coding_order` | ordered array of 1–3 descriptors, distinct IDs | 011 fallback order |
| `templates.feedback` and `.scene` | each exactly `{id,checksum}`; ID is a registered approved template of matching kind, checksum its exact stored lowercase SHA-256 | 010 feedback instruction and 011 scene system instruction; old case retains exact bytes |

Provider descriptor is exactly `{id,model,credential_ref}`. `id` is one of `gemini_api`, `glm_api`, `deepseek_api`, the planned pilot identifiers from Conversation 23. `model` is a 1–100 character `[A-Za-z0-9._:/-]` identifier chosen by admin; publish does not claim the vendor offers it. `credential_ref` must match the ID: `GEMINI_API_KEY`, `GLM_API_KEY`, or `DEEPSEEK_API_KEY`. It is a configuration key label, never the secret value. Publishing verifies known identifier and matching label, not live account health. `api_key`, token, URL, arbitrary metadata, teacher content, prompt/template text or a canonical-prompt override is rejected. 010/011 check actual adapter/model/credential availability when used and fail safely without changing case settings.

The approved catalog starts empty. A trusted local operator runs `php artisan v3:register-approved-template {kind} {id} {path} {sha256}` with `kind=feedback|scene`, ID matching `[a-z][a-z0-9_-]{2,63}`, an absolute path to an owner-approved UTF-8 text file of 1–65536 bytes, and an expected lowercase SHA-256 digest. The command reads bytes, verifies digest/encoding/size, inserts an immutable catalog row and prints only ID/kind/digest. Duplicate ID is a no-op only if kind/digest/bytes match exactly; otherwise it fails without alteration. No HTTP upload/edit/delete of template bodies exists. A newer approved text uses a new ID. This import is the approval boundary; no checked-in V2 scene or canonical 009 prompt is automatically imported. `ApprovedTemplateRepository::getCaptured(id,checksum)` returns the exact verified bytes to an authorized internal 010/011 consumer and fails closed on missing/corrupt data; 005 exposes no teacher/admin body endpoint. 010/011 own any worker transport and record the selected ID/digest in their lineage.

Qualification asks named stage-specific typed JEV questions: Choice selects `pass`/`revise`/`block`; Score rates quality on an explicitly requested 0–100 scale; Noul asks whether the cited source (prompt) or executable evidence (scene) supports the candidate. A successful typed answer passes exactly when Choice=`pass`, Score is at least `min_score`, all three typed answers' confidence values multiplied by 100 are each at least `min_confidence` without rounding, and Noul=yes. Boundary equality passes. The JEV adapter confirms the installed vendor wire schema and maps it to those types; it never asks a second model whether JEV was usable. `revise`, `block`, Noul=no, low score or low confidence are valid policy failures. Transport/schema failure has no verdict and uses 007's technical retry policy. The named question wording is versioned in 010/012, not admin editable in 005. Canonical 009 prompt text cannot be overridden.

## HTTP surface

004 loads `routes/v3.php` in the `web` stack. 005 appends these routes behind 004's actual active-admin middleware alias and private/no-store middleware; it must use the alias as implemented by 004 rather than create a second role check.

| Method/path | Route name | Result |
|---|---|---|
| GET `/v3/admin/settings` | `v3.admin.settings.show` | HTML edit form or JSON `{data:{version_id,active_version,settings,checksum,published_at}}`; before first publish: `version_id:null`, `active_version:0`, others null |
| GET `/v3/admin/settings/templates` | `v3.admin.settings.templates` | HTML selection list or JSON `{data:[{id,kind,checksum,registered_at}]}` sorted by `kind,id`; no template body |
| POST `/v3/admin/settings` | `v3.admin.settings.store` | JSON 201 `{data:{version_id,version,checksum,active_version}}`; browser 303 to show with generic success |
| GET `/v3/admin/settings/history` | `v3.admin.settings.history` | HTML or JSON `{data:[{version_id,version,publisher_user_id,published_at,checksum,changed_paths}],meta:{page,per_page:20}}`; newest version first |

`settings` is the exact nonsecret payload above, excluding `expected_active_version`. History has no raw payload. `changed_paths` is a sorted array of changed scalar leaf paths; treat `providers.coding_order` as one atomic path when its membership/order/content changes. It contains no old/new values. The first version compares against an empty object. Pagination uses `page` positive integer, 20 per page, ordered by `version DESC`; invalid page is 422 and an out-of-range valid page returns an empty `data` array. Publisher ID is permitted operational identity; teacher names, emails, template bodies and educational content are never serialized. No GET mutates state or includes credential values.

Check authentication → 004 active-admin role → input validation → expected-version conflict → mutation. Guest JSON gets 401 `unauthenticated`; inactive/teacher/student gets 403 `access_denied`; invalid input gets Laravel 422; stale expected version gets 409 `settings_conflict` with message `Settings changed. Reload and retry.`; storage/audit failure gets 503 `settings_unavailable` with message `V3 settings are unavailable.` Browser uses 004 login/denial, 302 validation redirect, generic 409/503 pages and normal 419 CSRF. Custom JSON errors use 004's `{error:{code,message}}` with the stated fixed messages and no raw identifiers/exception. All V3 settings success/error responses carry `Cache-Control: private, no-store`.

On browser validation failure, return field names/messages but do not flash the submitted input into the session; re-render the form from the active version. This prevents an unexpected credential field from being retained as old input. Never include submitted values in validation messages or logs.

## Service contract and concurrency

`PublishSettings::publish(array $payload, int $expectedActiveVersion, int $actorUserId): SettingsVersion` uses one DB transaction. First update `v3_access_control_locks.revision = revision + 1 WHERE id = 1` (require one affected row), then freshly read `users.role` and `v3_account_access.is_active` for the actor under that lock. Next update `v3_active_settings.revision = revision + 1 WHERE key = 'pilot'` (require one affected row), serializing publishers on SQLite and row-locking databases. Validate/canonicalize, compare expected active version, insert new immutable row with version `old+1`, insert its safe event, update pointer and commit. Failed eligibility, validation, conflict or persistence rolls back; access denials follow 004's audit behavior. Identical payload with a fresh expected version creates a new version; stale replay conflicts. No idempotency key is claimed.

`SelectCaseSettings::selectForNewCase(Connection $connection): SettingsVersion` requires 006's open write transaction on the same connection. It first-writes the pointer revision, reads its active version and returns that immutable row. It does not persist a case. Absent pointer/version throws typed `SettingsUnavailable`. 006 inserts the returned ID in its own case transaction. `SettingsVersionRepository::getCaptured(int $id)` loads exactly that row, verifies its SHA-256 checksum of canonical payload bytes and throws `SettingsUnavailable` if absent/corrupt; it never reads active settings as fallback. Worker callers are internal service users, not teacher HTTP clients.

A provider outage or absent deployment credential is not publication failure after a version is active; downstream stages follow the captured order/retry budget and record a safe operational outcome. No mid-case migration or silent policy substitution is allowed.
