# Repository Research and Decision Record: 005

**Reviewed**: 2026-10-01. This is code and contract inspection, not runtime verification.

| Evidence | Consequence |
|---|---|
| `app/Models/User.php` and users migration: role enum only, no global active column | Reuse 004's separate V3 access record and fresh role check; do not invent a second identity system. |
| `routes/web.php`, `bootstrap/app.php`: legacy `/users`, `/study-flow`, `/api` and middleware; no V3 route yet | Append to 004's `routes/v3.php` after prerequisite; never use legacy `role`/`teacher.access` as settings authority. |
| `TeacherAccessMiddleware`: admins pass through and teacher GETs may pass without ownership | Keep settings admin-only under 004; do not expose it through legacy content routes. |
| `config/services.php` and `.env.example`: no V3 credentials or provider registry | Validate known pilot IDs and secret-reference labels, not live vendor availability; later adapters own actual resolution. |
| `tests/Pest.php` and `phpunit.xml`: SQLite in-memory feature DB | Use feature tests for HTTP/schema and a file-backed SQLite database with two connections/processes for actual contention. |
| `specs/006.../data-model.md`: case with settings version FK; `007.../contracts/workflow.md`: start includes settings version | Provide exact selector contract now; real case capture and retries are later feature obligations. |
| `specs/009.../contracts/canonical-prompts.md`: exact owner text; 010/012: JEV typed Choice/Score/Noul | Reject prompt override fields; version numeric JEV policy, consume successful typed decisions directly in later specs. |
| Conversation 23 §7: admin changes provider, model, templates and JEV criteria for later runs; owner selected approved-template choice for 005 | 005 covers limits, provider/model selection, numeric qualification policy and selection of immutable approved feedback/scene templates. Canonical prompts cannot change; 010/011 consume selected bytes and record lineage. |
| `Google-Ai-studio-System-prompts` exists as an untracked scene-instruction candidate; no approved feedback body was found | Do not silently import the candidate or author a replacement. A trusted operator imports owner-approved files before first publish. |

## Decisions

- **D1** Complete replacement payload with no defaults. The authority says limits and thresholds are configurable, but supplies no numeric values. An admin must choose the first values; validation ranges are safety bounds, not business defaults. Empty active state blocks case creation.
- **D2** The known pilot provider IDs are `gemini_api`, `glm_api`, `deepseek_api`. A model name is syntax-checked but not asserted live. Publication has no provider adapter today; live health validation would make 005 dependent on 010/011 and on provider uptime. Runtime outages follow captured fallback policy.
- **D3** Version and pointer move atomically. A pointer-row first write serializes SQLite; the same transaction inserts safe publication history. Access eligibility is rechecked under 004's lock before settings mutation. This prevents two admins from silently overwriting each other and a disabled actor from publishing.
- **D4** `SelectCaseSettings` is a transaction-bound service, not a fake production case model. 006 will persist its ID and prove real upload/case routes. Test-only consumers here cannot certify 006 behavior.
- **D5** Credential references are fixed labels, not secrets. Strict shape validation rejects credential fields, and serialization/logging allowlists avoid leaking request values. A privileged admin pasting a secret into a model name is constrained by the model syntax/length; arbitrary free-text fields are excluded from settings publication.
- **D6** JEV policy uses explicit inclusive score/confidence thresholds plus typed Choice/Noul gates. The provider's installed wire schema must be confirmed by 010/012; 005 defines the business policy independent of transport. A successful typed low-confidence result is a policy failure, not a second model-review step.
- **D7** Keep all versions and events for the pilot. 015 owns any later retention policy, and any deletion must respect restrictive case references and audit requirements.
- **D8** Owner chose selection of separately approved noncanonical template versions, not admin text editing. An immutable catalog stores trusted operator-imported bytes and digests; settings stores only selected IDs/digests. No initial file in the repository is treated as approved without explicit owner approval. The importer is part of 005, while 010/011 own transport to workers and use. Existing V2/reference materials are not silently adopted.

TypeSafe's [official API reference](https://api.typesafe.ai/redoc) describes structured named questions and typed responses; its [workflow evals](https://evals.typesafe.ai/) describe Noul as yes/no, Choice as one of defined options, and Score as a scale. The exact deployed model alias and wire response remain downstream integration facts, so 005 does not claim a live JEV test.

## Operational prerequisite

The catalog is empty until an operator imports owner-approved feedback and scene files. No approved initial file paths were supplied in the inspected authority, and the existing `Google-Ai-studio-System-prompts` is not assumed approved. The 005 code and test suite can be completed with test-only approved fixtures after 004, but a live first publish is blocked until both approved bodies are provided and imported. This is an explicit deployment/business input, not a choice for the implementation agent to invent.
