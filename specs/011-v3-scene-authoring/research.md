# Research and Repository Decisions: 011

| Finding from inspected files | Decision and reason |
|---|---|
| 005 `providers.coding_order` admits only `gemini_api`, `glm_api`, `deepseek_api` with fixed credential labels; it has no CLI descriptor. | Initial 011 uses those APIs only. A CLI/subscription production branch would contradict captured settings and require a separately approved settings contract. |
| 005 captures exact operator-approved `templates.scene` bytes, while the catalog may start empty. | Load/verify by ID and SHA-256 at request/context time; absence blocks authoring. Never seed V2 prompts or edit a template for an active case. |
| 010 supplies a current-pass resolver and immutable prompt/Section lineage. | 011 binds and rechecks that resolver; a UUID or historical pass is insufficient authority. No second model judges a successful JEV decision. |
| 007 owns HMAC worker calls and Temporal replay; its event projection rejects unproved later milestones. | Extend its signed client and proof checks for `scene_generation`; keep code/prompt/template outside Temporal payload/history. |
| 008 `v3_private` is private; Laravel's existing `local` disk has `serve => true`, `public` has a URL, V2 `DynamicViewModel` stores mutable HTML. | Store new immutable V3 files only on `v3_private`; never reuse a V2 model/route/disk. |
| 012 owns restricted browser execution, safety/interaction checks, JEV scene pass and repair. | 011 performs only structural package validation and private collection. A `candidate_ready` row is untrusted and cannot be shown to teacher yet. |
| `package.json` has Vite/Tailwind/Alpine, no Three.js or V3 runtime bundle; V2 scene examples use external CDN assets. | Accept local text assets only and let 012's browser reject external dependencies. Template/runtime compatibility is an explicit live gate, not an implied V2 dependency. |
| 014 owns audited operational recovery and 015 retention. | 011 stops on unknown sends, keeps evidence, exposes no admin content/retry action, and supplies refs/digests for later reviewed recovery/export. |

No code or migration for 011 exists in the inspected repository at review time. The above decisions are implementation design based on current predecessor contracts; vendor availability and deployment credentials remain live prerequisites, not documentation-proven facts.
