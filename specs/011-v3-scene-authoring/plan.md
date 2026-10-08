# Implementation Plan: V3 Scene Authoring

**Scope**: [spec.md](spec.md), [contract](contracts/scene-authoring.md), [acceptance cases](contracts/acceptance-matrix.md). Documentation only here; planned paths do not yet prove implementation.

## Repository basis and architecture

Laravel 12 currently has V2 `SectionController`, `DynamicViewModel`, shared `/sections` and public storage routes. They are not a V3 candidate store. `routes/api.php` and `v3_engine/` are supplied by prerequisite 007, `v3_private` by 008, 009 Section and 010 prompt/qualification tables/services by their prerequisites. Laravel owns the case/owner/current-pass check, request/attempt state, private files and projection. Python 3.12 Temporal activity owns ordered provider calls, using HMAC to call Laravel. Temporal history contains only case/request/attempt/candidate opaque refs and safe states. The API coding adapters may reuse 010's fixed vendor transport; they must keep 011's ScenePackage parser and policy separate from 010 feedback generation. No LangChain abstraction is needed for exactly three fixed API wires; it may be used only if it preserves this contract and does not add tools/memory.

## Planned files

| Area | Create or extend |
|---|---|
| Schema/models | `database/migrations/2026_10_01_000011_create_v3_scene_authoring_tables.php`; `app/Models/V3/{AuthoringRequest,CodingAttempt,SceneCandidate}.php` |
| Laravel services | `app/Services/V3/Authoring/{ClaimAuthoringRequest,ClaimCodingAttempt,RecordAuthoringResult,SceneArtifactStore,SceneCandidateResolver,AuthoringStatusProjector,ReconcileAuthoring}.php`; `app/Console/Commands/V3/ReconcileAuthoringCommand.php`; scheduler in `routes/console.php` |
| HTTP/auth | `app/Http/Controllers/V3/Internal/{AuthoringRequestController,CodingAttemptController,SceneCandidateController}.php`; `app/Http/Controllers/V3/Teacher/SceneAuthoringStatusController.php`; extend `routes/api.php`, `routes/v3.php` and 004 closed summary query only |
| Worker | `v3_engine/authoring/{contracts,package,providers,activity}.py`; extend `v3_engine/workflows/case_workflow.py`, 007 signed Laravel client, and 007 projector validation for `scene_generation` |
| Tests | `tests/Feature/V3/Authoring/{HandoffTest,TemplateTest,CandidateStoreTest,AttemptPolicyTest,AccessTest,MilestoneTest}.php`; `v3_engine/tests/authoring/{test_provider_wire,test_package,test_fallback}.py` |

## Ordered design

1. Add schema and Laravel service contract tests against real 010 rows and 005 captured settings. List only current passing prompt refs in 009 Section order; claim each initial request under lock, with no arbitrary Section or supplied pass flag. Read exact 010 body and template bytes only over signed internal context. Recheck current pass at send preflight and result commit.
2. Implement a write-ahead attempt reservation in Laravel. Python calls one configured API per ordinal; a locally absent credential records a known no-send failure for that reserved slot. Known refusal/invalid output may advance to the next descriptor; post-send unknown outcome cannot. No provider replay is delegated to Temporal retry policy. Every distinct provider reservation consumes one `coding_attempts` slot, and no provider is repeated within an initial request.
3. Parse one text response as bounded `ScenePackageV1`. Laravel independently validates all paths/bytes, writes to private staging, hashes, exclusive-renames and commits immutable manifest/candidate. No generated JS, shell command, package install or browser is run in 011. Do not mark scene safe; 012 performs that check later.
4. Add 007 proof checks for `scene_generation` events and owner-scoped status. The 004 admin serializer remains closed; counts use persisted rows. V2 routes/models are not extended to recognize V3 IDs. 012 receives only the private signed resolver in this feature.
5. Verify three official-shape adapter fixtures, a live call to the **configured** selected provider/model in a safe deployment, two-process DB race, storage/DB fault recovery, owner/admin/legacy route denial and no public candidate bytes. Fixture tests are not live provider evidence.

## Important limits

The repository has no installed Three.js package or approved V3 runtime asset in `package.json`; V2's CDN-based five-layer contract is reference-only. `ScenePackageV1` therefore allows self-contained text files and local links; 012 will reject external runtime network access. If an approved scene template cannot produce that format, the operator must import a compatible **new** template through 005 before live publication. 011 does not alter canonical 009/010 prompts or produce repairs. 012's repair entry point is a later extension using this immutable candidate lineage, not a production placeholder here.
