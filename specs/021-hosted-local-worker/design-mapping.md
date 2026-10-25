# Feature 021: Architecture Mapping and Exclusivity Decisions

**Status**: Baseline Mapping (T02)  
**Execution Mode**: `local_worker_v1`  
**Protocol Version**: `local-worker.v1`  
**Schema Version**: `1`

---

## 1. Additive Role and Permission Decisions

1. **Role Scope & Centralized Authority**:
   - `LearningAccess` is the single source of truth for platform owner authority.
   - Platform owner is checked via `LearningAccess::isPlatformOwner($user)`.
   - Dedicated permission: `manage_processing_devices`. Only the platform owner can create pairing tokens, revoke devices, rotate tokens, or modify device capability grants. Ordinary operational admins (`admin` role) have read-only visibility into operational summaries (status, device name, online state, task counts) and CANNOT manage devices, see pairing secrets, or access educational contents.
   - Content-bearing processing permission: `execute_processing`. Scoped to the enrolled device token for its explicitly assigned tasks.
   - Enrolled devices authenticate via `Authorization: Bearer <device-token>` on `/api/v3/local-worker/v1/...` routes.
   - Enrolled devices CANNOT access `/api/internal/...` routes (which remain protected by internal HMAC) and CANNOT act as web users or teachers.

2. **State Lifecycle**:
   - Local Job States:
     - `queued`: Sealed input manifest, waiting for worker claim.
     - `leased`: Claimed by active worker, lease timer running.
     - `running`: Processing effects active under lease.
     - `paused`: Halted by owner; no new external mutations.
     - `auth_required`: Local worker provider session expired/needs refresh.
     - `rate_limited`: Provider 429 received; backed off until retry timestamp.
     - `disconnected`: Heartbeat missed / lease expired without completion.
     - `reconciliation_required`: In-flight provider mutation outcome unknown.
     - `committing`: Result submitted, server validating and storing atomically.
     - `completed`: Required deliverables accepted and committed.
     - `failed`: Terminal unrecoverable error.
     - `cancelled`: Cancelled by owner/teacher; existing artifacts preserved.
   - Stage Task Kinds:
     - `author_prompts`: Generates mapped lessons, handoffs, and frozen scene prompts.
     - `return_scenes`: Ingests and binds manual/approved-API 3-file scene packages.

3. **Old/New Dispatch Exclusivity (FR-005)**:
   - When a `SubjectRun` has `execution_mode == 'local_worker_v1'`:
     - `DispatchRunOutbox`: MUST skip queuing internal `application_bridge` authoring jobs.
     - `DiscoverCases` / `MapActivation`: MUST exclude cases linked to `local_worker_v1` runs from legacy bridge or Temporal dispatch.
     - `ReconcileActiveCases`: MUST ignore `local_worker_v1` runs.
     - Old runs (`execution_mode == null` or `execution_mode == 'same_host'`) continue using existing internal bridge unchanged.
     - Mutually exclusive guarantee: A run is processed EITHER via legacy same-host bridge OR via `local_worker_v1`, NEVER both.

---

## 2. FR / SC / Acceptance Case Mapping Matrix

| Requirement | Description | Target Files & Components | Validator / Constraint | Acceptance Cases |
|---|---|---|---|---|
| **FR-001** | Server authoritative for access, settings, material, budget, results | `app/Services/V3/LocalWorker/*`, `SubjectRun`, `LocalJob` | Model scopes, server-side transactions | A04, A06, A18 |
| **FR-002** | Hosted intake reuses inspection/v2 prep; idempotent accepted item/case/binding shell | `LocalWorkerAdmissionCoordinator.php`, `AcceptRunItem.php`, `IntakeCoordinator.php`, `DirectSourceCoordinator.php` | Intake coordinator, DB transactions | A01, A03, A04, A18 |
| **FR-003** | Policy v2 (>50 trigger, <=50 parts); worker cannot re-split | `v3_engine/web_pipeline/pdf_preparation.py`, `v3_engine/local_worker/runner.py` | Hash & range verification | A01, A02, A15 |
| **FR-004** | Idempotent admission binding `local_worker_v1` mode | `LocalWorkerAdmissionCoordinator.php`, `v3_local_jobs` | Unique run_id constraint on local jobs | A04 |
| **FR-005** | Legacy authoring dispatchers exclude local worker runs | `DispatchRunOutbox.php`, `routes/console.php`, `DiscoverCases.php` | `execution_mode !== 'local_worker_v1'` check | A04 |
| **FR-006** | Outbound HTTPS only; no hosted secrets/DB to laptop | `app/Http/Controllers/V3/LocalWorker/*`, `v3_engine/local_worker/*` | Route middleware, token auth, separate DB | A16, A27, A29 |
| **FR-007** | Device enrollment, rotation, revocation, masked pairing | `DeviceManager.php`, `DeviceEnrollmentController.php`, `v3_device_pairings` | Single-use pairing hash, 10-min TTL, SHA-256 token | A05 |
| **FR-008** | Dedicated revocable scoped device identity; revalidate on ops | `LocalWorkerDeviceAuth.php`, `ProcessingDevice.php` | Token hash lookup, expiry & revocation check | A05, A06 |
| **FR-009** | Atomic claims, 1 active assignment/epoch, claim replay | `ClaimTaskAction.php`, `v3_local_assignments` | DB locking, unique active assignment index, monotonic epoch | A07 |
| **FR-010** | Allowlisted input streaming with byte size & SHA-256 | `InputArtifactController.php`, `ArtifactGateway.php` | SHA-256 rehash, Content-Range verification | A15, A16 |
| **FR-011** | Frozen content artifacts for prompts, framework, contract | `SealedManifestBuilder.php`, `v3_local_artifacts` | Manifest SHA-256 binding | A15, A21 |
| **FR-012** | Local credential/profile allowlist; no server-sent commands | `v3_engine/local_worker/config.py`, `doctor.py` | Strict config allowlist, no eval/exec of remote payload | A16 |
| **FR-013** | Durable local journal & exclusive local flock | `v3_engine/local_worker/journal.py`, `lock.py` | SQLite/WAL journal, flock() on workspace | A08, A10, A13 |
| **FR-014** | Provider effect key, attempt ID, request digest, pre-send fence | `ProviderEffectCoordinator.php`, `v3_engine/local_worker/journal.py` | Unique logical_effect_key, dispatch permit | A10, A11, A14 |
| **FR-015** | Lease & control check before external mutation | `HeartbeatManager.php`, `DispatchPermitAction.php` | Monotonic permit <= 10s, lease headroom >= 30s | A08, A14 |
| **FR-016** | Block uncertain effects until evidence reconciliation | `ReconciliationCoordinator.php`, `v3_engine/local_worker/reconcile.py` | Read-first comparison, no auto-reassign | A09, A10, A12 |
| **FR-017** | Verified archive before history reset; journaled intent | `v3_engine/local_worker/history_reset.py`, `LocalWorkerAdmissionCoordinator.php` | Verified archive check, explicit owner consent | A13 |
| **FR-018** | Raw response and derived prompt separated with provenance | `v3_engine/local_worker/authoring.py`, `ResultAcceptanceService.php` | Typed artifact models, raw vs parsed mapping | A18, A19 |
| **FR-019** | Reject association mismatches; semantic correctness review | `ResultAcceptanceService.php`, `ProtocolValidator.php` | Set completeness, contiguous ordinals | A19 |
| **FR-020** | Typed artifact whitelist; exclude entire run dirs/secrets | `ArtifactGateway.php`, `UploadArtifactAction.php` | Allowlisted purpose & media types | A17, A27 |
| **FR-021** | Upload quarantine, byte count & SHA-256 rehash | `UploadArtifactAction.php`, `v3_local_artifacts` | Server-computed SHA-256 match before promote | A17 |
| **FR-022** | Atomic result commit + outbox | `ResultAcceptanceService.php`, `v3_local_results` | Single DB transaction, lesson/scene creation | A20 |
| **FR-023** | Idempotent mutation replay (same ID/digest) vs conflict | `IdempotencyGuard.php` | SHA-256 digest match returns receipt, diff returns 409 | A20 |
| **FR-024** | Disallow local paths / provider IDs as server storage paths | `ArtifactGateway.php`, `v3_local_artifacts` | Server-generated UUID paths in private storage | A16 |
| **FR-025** | Deterministic prompt export (raw JSON + text) | `ExportScenePromptsAction.php`, `Teacher/SubjectRunController.php` | Stable order, accepted only, nonempty prompts | A21 |
| **FR-026** | Manual scene return bound to current server prompt IDs | `SceneReturnCoordinator.php`, `v3_local_scene_submissions` | Prompt version & SHA-256 match | A22, A23, A24 |
| **FR-027** | New scenes enter validation/check/review gates | `SceneReturnCoordinator.php`, `ImportManualScene.php`, `RecordSceneCheck.php` | `awaiting_check` status, real browser checks | A25, A26 |
| **FR-028** | Distinction between upload, checks, review, publication | Status transitions in `SceneQualification`, `ReviewDecision` | Gate independence | A25, A26 |
| **FR-029** | Pause/cancel/retry preserves prior immutable records | `ControlPlaneService.php`, `LocalJob.php` | Soft state change, immutable artifacts | A14 |
| **FR-030** | Revoked teacher access / cancel halts dispatch & commit | `LocalWorkerDeviceAuth.php`, `ResultAcceptanceService.php` | Live teacher permission recheck on commit | A06, A14 |
| **FR-031** | Progress counts derived from accepted artifacts only | `TeacherRunPresenter.php`, `v3_local_jobs` | Query accepted DB records, not worker progress | A28, A31 |
| **FR-032** | Policy bounds on payload sizes, counts, retries, disk | `LocalWorkerV1Schema.php`, `DiskBudgetGuard.php` | Schema bounds, max 512MiB output budget | A03, A32 |
| **FR-033** | Unknown usage/cost/timing kept null/not-recorded | `v3_engine/local_worker/provenance.py`, `ResultAcceptanceService.php` | Explicit `measurement_status: not_recorded` | A24, A31 |
| **FR-034** | Hosted deployment requires no Google session or Brave | Docker / Artisan environment audit | Test suite runs with no GOOGLE_* or Brave vars | A29 |
| **FR-035** | Local worker needs no hosted DB or hosted .env | `v3_engine/local_worker/config.py` | Runs with standalone config and local SQLite | A29 |
| **FR-036** | Preserve same-host mode & captured historical runs | `DispatchRunOutbox.php`, `TerminalImportTest.php` | Existing tests continue passing | A02, A30 |
| **FR-037** | Replaceable provider adapters; unsupported reports 501 | `v3_engine/local_worker/provider_adapter.py` | Protocol interface, unsupported raises error | A31 |
| **FR-038** | Implementation deliverable evidence matrix | `specs/021-hosted-local-worker/implementation-report.md` | Verification report with command transcripts | All |

---

## 3. Success Criteria (SC) Mapping

- **SC-001**: Teacher submits when worker offline -> verified via A01, A28 (task stays queued; worker claims later and completes without re-upload).
- **SC-002**: Idle worker discovers eligible task <= 30s -> verified via A28 (claim long-poll / poll timing).
- **SC-003**: Controlled failure at every interruption boundary yields canonical result or visible uncertain state, zero duplicate sends -> verified via A08, A09, A10, A11, A14.
- **SC-004**: Artifact bytes match SHA-256 and size; corruption/cross-owner rejected -> verified via A15, A16, A17.
- **SC-005**: Returned prompt set is nonempty, matches map/provenance, deterministic export -> verified via A19, A21.
- **SC-006**: Revocation/lease loss stops worker at next boundary, no rollback claim for in-flight -> verified via A08, A14, A29.
- **SC-007**: Returned scenes inaccessible to students until qualification, review, publication pass -> verified via A25, A26.
- **SC-008**: Hosted deployment has no Google session; laptop cannot read hosted DB or impersonate teacher -> verified via A05, A06, A29.
- **SC-009**: Evidence matrix truthfully labels doubles, skips, and failures without synthetic passes -> verified via A31, implementation-report.md.
