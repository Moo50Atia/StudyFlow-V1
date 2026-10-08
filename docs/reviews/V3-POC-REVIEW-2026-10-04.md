# V3 proof-of-concept review — 2026-10-04

## Verdict

**The complete PDF → NotebookLM → prompts → JEV → generated scene → browser/JEV qualification → teacher approval pipeline is not working end to end yet.** There is substantial implemented Laravel storage, authorization, ledger and reporting behavior, and its V3 tests pass. Several production Python activities are still placeholders or disconnected, however. Installing credentials alone will not repair these breaks.

This is documentation only: no implementation fixes, task-checkbox changes, commits, credential changes or live provider calls were made. Prioritize section A for an honest proof of concept; section B records issues which need not prevent an isolated, trusted local demonstration. “Deferred” does **not** mean safe for public deployment or valid evidence certification.

## Scope and evidence limits

Reviewed the current working-tree implementation in `/home/moahmmed/college_project` against the V3 map and specifications **004–015**. Most V3 files are new/untracked, so this is a working-tree review, not a review of a supplied commit. The California evidence-pilot prompt quoted in the request describes a different project and was not treated as this application's architecture.

Inspected the main workflow, worker registration, transport/activity boundaries, Laravel service/controller handoffs, review UI, evidence handling and corresponding tests. Root `AGENTS.md`/`AGENTS.override.md` were absent; `.agents/AGENTS.md` was inspected. No claim is made that every acceptance scenario, provider contract, race, browser exploit or deployment environment was exhaustively verified. Existing checked task boxes and fixture passes are not live evidence.

## A. Confirmed functional breaks, in dependency order

All paths below are repository-relative. P1 = blocks the indicated usable path; deployment-only conditions are explicit.

| ID | Priority / affected spec | Location | Reproduction and impact | Required behavior |
|---|---|---|---|---|
| B01 | P1 / 008 | `v3_engine/activities/extract_case_source.py:58–60`; `v3_engine/sources/notebooklm_adapter.py:60,87` | `httpx._content.encode_json()` returns `(headers, ByteStream)`, not bytes. Passing it to `compute_hmac_signature()` raises `TypeError: object supporting the buffer API required`. Every real extraction callback, source claim and source-result write hits this. | Serialize actual JSON bytes once; hash and send exactly those bytes. Test the real HTTP source client, not only its fake. |
| B02 | P1 / 009 | `v3_engine/activities/map_case.py:8–15`; `activities/prepare_lesson.py:8–14` | Direct offline calls report ready, one work item/Section, without querying NotebookLM or storing a map/Section. Importing `MapService`/`LessonProcessor` does not execute them. | Wire both registered activities to real source-scoped services and signed persistence; return only committed refs/counts. |
| B03 | P1 / 010 | `v3_engine/workflows/case_workflow.py:387–391`; `v3_engine/worker.py` | After First, the workflow waits for `lesson_ready_v1`. No production activity or scheduled command invokes `PromptCycleRunner` to produce the Third outputs/completion which can trigger that signal. Existing workflow tests inject the signal and fake activities. | Schedule the canonical generation cycle before waiting for its receipt, and register its activity. |
| B04 | P1 / 010 | `v3_engine/prompts/cycle.py:99` and other `query_once` calls | The runner calls `query_once(prompt, conversation_id=...)`; the real `KnowledgeSourceAdapter` requires `case_id, binding, query_text`. Signature binding reproduces a missing `binding` TypeError before any provider request. | Pass the verified case/binding and named query text at every turn, or supply and test an explicit scoped adapter. |
| B05 | P1 / 010 | `v3_engine/prompts/activities.py:18–23` | A bare case payload reports `qualified_count: 1`, no passing refs, zero blocked refs, and performs no JEV evaluation or revision. It cannot create the durable current-pass evidence required by authoring. | Implement the claimed qualification/revision loop and derive counts/refs from recorded outcomes; technical errors cannot become completion. |
| B06 | P1 / 009–011 | `v3_engine/workflows/case_workflow.py:332–343,387–391` | The workflow prepares only ordinal 1, handles one lesson-ready signal and then ends. There is no cursor/Continue loop. A mapped PDF with two work items never generates the second item's Sections/prompts/scenes automatically. | Iterate the persisted cursor through all mapped work, respecting the Third-output gate and one-send Continue ledger. |
| B07 | P1 / 012–013 | `v3_engine/workflows/case_workflow.py:562–585`; `v3_engine/worker.py:29–43` | Candidate-ready only publishes a scene-generation event. The workflow neither schedules scene qualification/repair nor waits for review. Its registered signals lack `review_decision_v1`, and queries lack `query_decision_consumed`. Spec 012 activities are not registered. | Connect candidate → deterministic check/JEV/repair → review creation/wait/decision routing, retaining durable per-Section progress. |
| B08 | P1 / 012 | `v3_engine/qualification/activity.py:90–97` | `run_browser_checks_in_sandbox([], 'candidate', 'digest')` raises a TypeError: `run_container_check()` needs tar bytes, candidate ref and manifest digest. It also returns a result dictionary, not the unpacked pair expected here. | Build the defined candidate tar, call the actual runner signature, consume its result envelope, and distinguish infrastructure failure from a scene verdict. |
| B09 | P1 / 012 | `v3_engine/qualification/activity.py:78–81,150–169` | `get_jev_client()` returns the external provider `SceneJevClient`, passing Laravel URL and HMAC key into its API-key/base-URL parameters. That class has no `claim_attempt`, `get_context` or `record_result`, so the first post-check claim raises AttributeError. | Separate the signed Laravel attempt client from the external TypeSafe evaluator and wire the real interfaces together. |
| B10 | P1 / 012 | `v3_engine/qualification/activity.py:100–104` | Direct `call_jev_provider()` raises ImportError because `call_typesafe_jev` does not exist in `prompts.providers`. The function additionally selects `GEMINI_API_KEY` instead of the TypeSafe credential. | Invoke the implemented TypeSafe evaluator with the correct credential, questions and typed response conversion. |
| B11 | P1 / 012 | `app/Services/V3/SceneQualification/ClaimSceneJevAttempt.php:198` | Scene-JEV reservations take the model from `providers.prompt.model`, whose schema allows generator providers Gemini/GLM/DeepSeek. Thus valid Gemini generator settings become the requested TypeSafe model and enter the request digest. | Use the dedicated verified JEV model, independently of the prompt generator descriptor; preserve it through the attempt/context/result. |
| B12 | P1 / 012 | `v3_engine/qualification/activity.py:72–80` | With the documented 007 configuration, Laravel checks `V3_CALLBACK_HMAC_KEY`, but scene clients sign with `V3_WORKER_HMAC_SECRET` and a different default. Scene check claims receive 401 even while earlier worker callbacks work. | Reuse the engine's callback URL/key configuration consistently instead of introducing an undocumented second credential. |
| B13 | P1 / 013 browser UI | `resources/views/v3/teacher/reviews/show.blade.php:24–30` | The entire review page displays only prompt/context and navigation. It never loads or instantiates `V3ReviewPreview` and has no approval/request-changes controls. The standalone JS file is not imported by the Vite entrypoint. | Connect the preview and version-bound decision forms to the existing owner APIs. A teacher must be able to perform the flow without manual API calls. |
| B14 | P1 / 013 corrections | `app/Services/V3/Review/DispatchReviewEffects.php:33–34` | Postapproval correction creates a `start_correction` outbox row and clears current approval, but dispatch handles only `signal_decision`. The correction remains pending permanently. | Dispatch/reconcile the correction operation with its deterministic workflow ID and retained route/context. |
| B15 | P1 / 013 corrections | `v3_engine/bridge.py:773–788`; `v3_engine/worker.py` | Given a missing correction workflow, the bridge returns 202 accepted with **zero** calls to `start_workflow`; it also swallows connection errors. `CorrectionWorkflow` and its activities are not registered. Even that class, called independently, only claims work/fetches context, then returns completed without generating a corrected candidate. | Actually start/register and execute the correction path through renewed qualification/review; report unavailable/uncertain rather than fictitious acceptance/completion. |
| B16 | P1 / deployment without dev autoload | `app/Services/V3/Workflow/BridgeClient.php:22` and other `Tests\\Support\\V3\\WorkflowFixtures` imports under `app/` | Composer defines `Tests\\` only in `autoload-dev`. A normal `--no-dev` installation cannot resolve the signing helper; workflow start throws before its try/catch. Preview, review and admin services have the same runtime dependency. | Move signing into an application service and have tests depend on it, not vice versa. A dev checkout can mask this blocker. |

**Important:** B07 is an orchestration omission; B08–B12 are independently broken implementations which will still fail after orchestration is connected. Do not treat fixing only B07 as completion of 012.

## B. Deferred security, evidence and recovery defects

These do not need to prevent a trusted local happy-path demonstration, but must be recorded accurately and fixed before relying on the affected guarantees.

| ID | Priority | Location | Confirmed problem / trigger | Remedy and scope |
|---|---|---|---|---|
| D01 | P1 security; conditional exposure | `config/services.php:40–41`; `v3_engine/settings.py` | Missing environment secrets silently selects published `dev-*-hmac-key` strings. Anyone who can reach the Laravel internal endpoints can generate the worker signature and access private worker content/mutations. | Fail closed outside explicitly isolated development; require separately generated secrets. Binding only the Python bridge to loopback does not protect Laravel's API routes. |
| D02 | P2 evidence/safety | `app/Services/V3/SceneQualification/RecordSceneCheck.php:81–95` | Empty/missing `checks` initializes and retains verdict `pass`. An offline in-memory probe submitted a report with `checks: []` and non-PNG bytes; the service returned 201/pass and advanced the candidate to `awaiting_jev`. The report's candidate/manifest identity is not checked here either. | Require the exact complete check-code set and report identity, validate screenshot bytes, and reject malformed evidence before committing a verdict. |
| D03 | P2 evidence integrity | `v3_engine/qualification/activity.py:125–127` | The production activity deliberately stores `PNG signature + fake-screenshot` rather than the browser output. Hashing this placeholder does not make it real capture evidence. | Preserve the actual runner PNG/digest; missing capture is an evidence failure, not a synthetic replacement. |
| D04 | P2 recovery correctness | `v3_engine/bridge.py:713–718`; `app/Services/V3/Review/DispatchReviewEffects.php:73–81` | A failed decision query on any completed workflow is converted to consumed=true. Separately the dispatcher ignores a false confirmation and marks an accepted signal delivered. A decision never applied by the workflow can therefore disappear from recovery. | Require exact decision-ID consumption proof; transport acceptance or unrelated workflow completion is insufficient. Retain unresolved work for reconciliation. |
| D05 | P2 availability | `v3_engine/review/preview_gateway.py:111–113,184–185` | `start()` holds a non-reentrant `threading.Lock`; if preview initialization throws (for example malformed JSON), it calls `close()` which acquires the same lock. The call deadlocks instead of returning unavailable. | Separate locked cleanup or otherwise avoid nested acquisition; enforce bounded preview I/O. The happy path does not trigger this. |
| D06 | P2 evidence accuracy | `app/Services/V3/SceneQualification/RecordSceneCheck.php:125` | Every browser run is recorded as exactly 1000 ms, regardless of its actual report/runtime. This fabricates duration evidence even when the scene works. | Compute a validated measured duration, or explicitly represent unknown rather than inventing one second. |

## C. External/deployment gates — not asserted as verified defects

- No current TypeSafe SDK/API/model/quota verification or live JEV call was performed. The HTTP clients use `/v1/systemone` and a default `typesafe-systemone-v1`; compatibility with today's provider cannot be certified by their mocked tests. Verify the official current contract and account entitlement before a bounded real smoke. Do not transplant the California project's flags or historical model assertions blindly.
- NotebookLM's installed MCP schema/login, source grounding, bounded stateful conversations and real coverage need an explicit live gate. No NotebookLM/search/generation requests were authorized or completed in this review.
- Temporal server/worker/replay, queue/scheduler and browser container deployment were not certified. The Python suite contains Temporal-local tests; some tests named `test_live_*` are not excluded by the `live` marker alone. Their attempted runs were interrupted under blocked networking, not reported as passing.
- MySQL/PostgreSQL installation was not tested. New 012/013 migrations sort before 007–011 prerequisite table creation, and some settings foreign-key column types differ from the referenced bigint. SQLite tests do not establish portability; validate migration ordering/types on the intended non-SQLite backend before deployment.
- No public-deployment security audit, dependency-advisory scan or adversarial browser isolation assessment is claimed.

## D. Reproduced checks

All review test commands were run with network protection: `/tmp/v3-review-no-network.so` interposes IPv4/IPv6 `connect` and addressed `sendto`, in addition to the session network sandbox. No real API credentials were supplied to probes and no live provider outcome was used as evidence. `unshare -n` was unavailable (`Operation not permitted`), so it was not claimed as an isolation mechanism.

| Check | Result |
|---|---|
| `LD_PRELOAD=/tmp/v3-review-no-network.so vendor/bin/pest tests/Feature/V3` | **468 passed, 1 skipped, 5048 assertions** |
| `LD_PRELOAD=/tmp/v3-review-no-network.so vendor/bin/pest` | **489 passed, 1 skipped, 5 failed, 5105 assertions** |
| Isolated Python command below | **104 passed, 2 deselected** |
| `.venv/bin/python -m compileall -q v3_engine` | Passed |
| `find app routes database/migrations -name '*.php' -print0 \| xargs -0 -n 1 php -l` | Passed |
| `LD_PRELOAD=/tmp/v3-review-no-network.so npm run build` | Passed; ordinary stale browserslist warning |
| Direct Python probes | Reproduced B01/B02/B04/B05/B08/B09/B10/B15 and enumerated missing review signal/query |
| In-memory Laravel service probe | Reproduced D02: empty checks and `not-a-png` bytes accepted as 201/pass |
| Mocked preview-process probe | Reproduced D05: malformed initialization JSON leaves `start()` blocked reacquiring its own lock |

Exact isolated Python command:

```sh
LD_PRELOAD=/tmp/v3-review-no-network.so timeout 90 .venv/bin/python -m pytest v3_engine/tests \
  -m 'not live and not container' \
  --ignore-glob='*test_live*' --ignore-glob='*test_workflow*' \
  --ignore=v3_engine/tests/workflow/test_case_workflow.py \
  --ignore=v3_engine/tests/workflow/test_review_gate.py \
  --ignore=v3_engine/tests/workflow/test_start_bridge.py \
  --ignore=v3_engine/tests/admin/test_admin_control_workflow.py \
  --ignore=v3_engine/tests/qualification/test_browser.py \
  --ignore=v3_engine/tests/review/test_preview_container.py -q
```

This is **not** the entire Python/integration suite. Initial broader invocations stalled in Temporal or unmarked live-MCP setup under the network block and were interrupted; do not count them as either successful integrations or confirmed product failures. `vendor/bin/phpunit` was also tried first and rejected the Pest-style suite with “Please run ./vendor/bin/pest instead.”

The five full-PHP failures concern three `UserFactory::unverified()` calls, the missing `email_verified_at` column on profile update, and a legacy verification-state assertion. The factory/schema are unchanged and the implicated profile-update code is identical in HEAD; these are documented baseline issues, **not new V3 findings**. They do not demonstrate an intact overall legacy suite either.

Temporary logs/probes are `/tmp/v3-review-{pest,pest-all,pytest-isolated,probes,check-probe,build,php-lint}.log`, `/tmp/v3-review-probes.py`, and `/tmp/v3-review-check-probe.php`. The PHP probe explicitly used SQLite `:memory:` and fake private storage. Build/cache/test outputs were generated normally; no historical empirical records or application database was deliberately migrated/changed.

## E. Minimum honest proof-of-concept exit criteria

1. One disposable accepted PDF, one captured settings version, one real mapped lesson and one Section must traverse **production** activities, not fake replacements.
2. Original Third output and real typed prompt-JEV evidence must be persisted before authoring; authoring must produce a verified private package.
3. The real isolated checker and scene-JEV transport must produce the exact retained evidence used for eligibility.
4. The teacher must interact through the actual review page and approve that exact candidate; workflow consumption and approval/history must agree.
5. A two-work-item PDF must establish that processing does not silently stop at the first item. A change request must establish a real bounded successor path, not merely a pending row.
6. Then run one explicitly authorized, bounded live smoke with disposable data and verified providers. Until then describe this as tested components with integration blockers, not a completed end-to-end engine.

No corrective implementation has been performed in this review.
