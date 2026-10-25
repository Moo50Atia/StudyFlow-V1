# Acceptance Matrix and Evidence Rules

**Status**: Planned acceptance. No Feature 021 implementation/test/live-provider gate was executed while authoring this package.

## 1. Evidence levels

| Level | What must actually run | What it proves | What it does not prove |
|---|---|---|---|
| E1 Offline contracts/domain | Real validators, storage and domain commands against a disposable DB; provider transport explicitly doubled. | Scope, protocol, replay, corruption and state rules. | Provider connectivity, model quality or production throughput. |
| E2 Joined network boundary | Real Laravel HTTP server + a separate worker process and separate filesystem roots, no shared app DB/filesystem; recorded provider data explicitly substituted at provider boundary. | Actual enrollment/claim/download/upload/commit and interruption behavior over HTTP. | Live Google inference. |
| E3 Real build/check/review | Actual package materialization, isolated browser checks and real preview/review/publication paths. | Those actual technical and human-action gates for the tested artifact. | Scientific correctness across subjects or real JEV when a demo waiver is used. |
| E4 Live authoring | Explicitly authorized real PDF + actual saved provider session + real NotebookLM create/upload/chat responses through the new worker protocol. | End-to-end provider-live path for that run. | Universal quota, indefinite auth, commercial approval, or paid generation. |
| E5 Genuine scene return | Real operator-generated scenes with exact current prompt bindings, returned through the worker and existing candidate pipeline. | Manual generation handoff/import path. | Automated subscription-site generation or an API bill. |
| E6 Public production readiness | Chosen hosted topology, operational/security/backup/recovery checks and applicable account/audience conditions verified. | Readiness for the stated rollout scope. | Certification for all institutions or unlimited workloads. |

A fixture is legitimate in E1/E2 when named and isolated. It MUST NOT be inserted into a live user's run or relabeled as E4/E5. Retained historical results are useful reference evidence, not proof of this new transport's implementation.

## 2. Required cases

Each case is unexecuted until an implementation report supplies real command and artifact evidence.

| ID | Required case / observable outcome | FR references |
|---|---|---|
| A01 | Valid ordered upload preserves original hashes; 37-page original stays intact; 50 stays intact; 51 splits 1–25,26–51; 100 splits 1–50,51–100; 101 splits 1–50,51–75,76–101; 114 splits 1–28,29–57,58–85,86–114. No page duplication/loss. | 002–003 |
| A02 | Historical policy-v1 capture remains byte-identical and follows its own rules. Worker consumes final v2 parts without re-splitting. | 003,036 |
| A03 | Warning/encrypted/malicious/corrupt/oversized PDF is rejected before an executable task exists. All originals must prepare successfully for admission. | 002,032 |
| A04 | Same teacher submission replays original run; changed bytes/order/settings under same idempotency identity conflict. Local execution mode never triggers legacy run/source/map/lesson authoring, including during scheduler ticks and reconciliation discovery. | 004–005 |
| A05 | Ordinary admin cannot pair a device/read materials; owner permission can grant only intended scope; invitation reuse/expiry and token revoke/rotate/expiry are enforced. | 007–008 |
| A06 | Cross-tenant task/input/artifact/effect/result IDs reveal no content/existence; teacher loses access mid-run and later dispatch/commit is denied. | 008,030 |
| A07 | Two devices race for one stage; exactly one active assignment wins. Lost claim response and retry never acquire a second task. | 009 |
| A08 | Lost heartbeat/lease/clock skew cannot authorize new provider sends. Heartbeats continue during a provider request longer than one lease. | 013–016 |
| A09 | Clean lease expiry before any dispatch may requeue; potentially dispatched effect cannot auto-reassign. Same-device recovery retains effect identity and history. | 014–016 |
| A10 | Crash before intent, after reserve, after dispatch fence, before send, after send, after response persistence and after outcome upload produces one canonical effect result or explicit uncertainty. Actual send count is observed at the transport boundary. | 013–017 |
| A11 | Provider timeout/5xx/history absence is not treated as proof of no effect; hidden SDK retries are disabled and tested. | 014–017 |
| A12 | Ambiguous duplicate notebook/source/title/history blocks reconciliation; no invented provider-side content hash. | 016,024 |
| A13 | Conversation reset requires captured authorization, actual verified archive and a durable reset intent. Lost reset response never causes blind repeat. | 017 |
| A14 | Server pause/cancel/revoke races against dispatch and commit; fresh sends/writes are fenced, in-flight response remains private, prior outputs survive. | 015,029–030 |
| A15 | Input corruption/truncation/range mismatch/instruction substitution/unsupported protocol fails before Google receives it. Relocating local workspace preserves logical identity. | 010–012 |
| A16 | Task-supplied path, callback URL, executable command, credential profile or cross-origin redirect cannot escape local/API allowlists. | 006,012,024 |
| A17 | Partial/stale/corrupt/oversized output, duplicate artifact ID with changed bytes, path traversal/symlink/archive bomb are rejected. Only verified staging can be committed. | 020–024 |
| A18 | Missing owner/settings/source document/source hashes/pages/binding fails closed; no owner=1, invented hash, 1024 bytes or one-page substitute rows are inserted. | 018–019,022 |
| A19 | Detectable source/map/lesson ordinal/title/marker mismatch, missing mapped lesson, empty prompt or fabricated count fails stage completeness; raw response remains retained. Structurally consistent semantic errors are flagged/held for qualified review, not claimed machine-detected by hashes. | 018–019,025,031 |
| A20 | Result commit loses response or server restarts: same result ID/digest returns durable receipt and same IDs/counts; no duplicate lesson, scene or outbox effect. | 022–023 |
| A21 | Prompt export includes exact accepted prompt count and stable order; raw JSON retains exact text/template identity. No blank content is counted. | 011,025 |
| A22 | Prompts-only job finishes at actual export; scene-delivery job waits for manual generation, releases authoring lease, and uses a different stage assignment without reauthoring. | 004,026,031 |
| A23 | Wrong or old current prompt ID/hash/package bindings are rejected on scene return; changed candidate requires a new version, not an overwrite. | 026–028 |
| A24 | Manual import has reported provider/model, null unknown API usage and actual file hashes; it is never counted as a measured API call. | 026,033 |
| A25 | Actual seven browser checks produce real screenshots, report hashes and timestamps. Deliberately broken runtime/network/assets/interaction fails. Missing checker/JEV is unavailable, not pass. | 027–028 |
| A26 | Student cannot access a merely returned/checked/demo-gated candidate. Real authorized preview, current review, explicit approval and separate publication are necessary. Correction invalidates access according to existing rules. | 027–030 |
| A27 | Full-run export cannot include credentials; logs, device summaries, queue/Temporal events and ordinary admin pages contain no bodies/filenames/notebook IDs/secrets. | 006,020,033 |
| A28 | Offline laptop queues work truthfully and reconnects; idle healthy pickup <=30 s in the defined empty-queue environment. No fake progress/ETA. | 009,031–032 |
| A29 | Hosted configuration prepares/queues/checks without Google cookies or Brave. Laptop runs without hosted DB, hosted `.env`, global HMAC or public Temporal. | 006,034–035 |
| A30 | Existing same-host mode and pre-existing captured runs remain unchanged; additive migration/rollback does not destroy source, history, ownership or approvals. | 036 |
| A31 | Unknown model, token/cost data or unsupported adapter stays unknown/unsupported. No fixtures, constants, sleeps or synthetic metrics are used to make UI completion pass. | 033,037–038 |
| A32 | Worker local disk budget/captured job quota exhaustion holds safely before exceeding limits; no prompt truncation, dropped lesson or quota-evasion account switch. | 032 |

Additional required variants: A04/A18 include real accepted item/case/lecture/binding materialization before task sealing and waiting beyond 24-hour intake quarantine expiry; A10 includes suspended/stale dispatch permits and process-incarnation changes; A09 includes a reconciliation-only fresh assignment that cannot dispatch; A23 includes mixed stale/current scene batches and preservation of unaffected accepted candidates; A07/A20 include fresh-UUID attempts to bypass logical-effect and scene-package deduplication; A05 includes a lost enrollment-token response without plaintext-token replay.

## 3. Existing tests and domain contracts worth extending

Inspect actual source first; names below may change and are not evidence of passing:

- `tests/Feature/V3/WebPipeline/{ManualSceneImportTest,EvidenceLineageTest,SceneReviewTest,TerminalImportTest,OrderedPdfSubmissionTest}.php`
- `tests/Feature/V3/SceneCheckEvidenceBoundaryTest.php`
- `tests/Feature/V3/Review/{ReviewDecisionTest,ReviewConcurrencyTest,PreviewTest}.php`
- `v3_engine/tests/web_pipeline/{test_pdf_preparation,test_scene_execution,test_browser_checks,test_privacy_history}.py`
- `v3_engine/tests/terminal_pipeline/` and `v3_engine/tests/notebooklm/test_sdk_contract.py`
- `v3_engine/tests/scene_generation/test_source_package.py`

Add a joined-boundary test lane with the provider double at the actual external provider transport boundary, not by replacing the worker/coordinator/result acceptance service. Verify actual outgoing provider-call counts. Same-process mocks of the entire server cannot satisfy E2.

Concurrency tests must exercise durable constraints, not just sequential happy paths. Crashes must terminate/restart actual worker/server processes around deterministic boundaries. Never disable authorization, CSRF, validation or uncertainty handling to make a browser test work.

## 4. Live execution policy

Implementation authorization covers code and isolated local acceptance. Before a provider-live test, use a real explicitly authorized material/account and confirm that this execution scope permits its create/upload/chat operations. No live call is implied by a fixture or doc example. No paid scene generation, deletion of historical notebooks/conversations, account creation, publishing to real students or deployment to a public host is assumed by this package.

If no live material/session/approval is available, finish all independent code and offline/joined acceptance and mark E4 `not_run` or `blocked`, stating the exact missing item. Do not substitute the historical 8-PDF/11-prompt runs or fabricate a new successful run. Do not tell the user the whole feature is blocked when independently authorized implementation remains possible.

## 5. Required implementation report

The final `implementation-report.md` must contain:

1. Source snapshot: checkout, commit, tracked diff digest and relevant untracked-source hashes, excluding secrets.
2. Final architecture and exact working commands for hosted and local processes.
3. FR/SC/case matrix with status `passed`, `failed`, `not_run`, `blocked` or `not_applicable` with reason. Planned assertions are not outcomes.
4. Actual command, timestamp, exit code, pass/fail/skip count, environment and named doubles for each test lane.
5. Actual task/assignment/result references and byte hashes for evidence. Educational data stays in authorized private storage; public report uses safe refs.
6. Separate measured provider metadata, operator-reported labels, estimates and unknowns.
7. Unresolved errors and skipped gates, including unrelated baseline failures, without suppressing regressions.
8. Rollout/rollback and data-retention implications; no destructive migration shortcuts.
9. Separate statuses for implementation completeness, E2 boundary verification, E4 provider-live verification, E5 genuine manual return and public production readiness.

Do not write a success-shaped example implementation report now. The implementer creates it from actual outcomes.
