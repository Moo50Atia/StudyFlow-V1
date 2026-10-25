<?php

namespace Tests\Feature\V3\LocalWorker;

use App\Models\Lecture;
use App\Models\Subject;
use App\Models\User;
use App\Models\V3\DevicePairing;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\SubjectRun;
use App\Services\V3\LocalWorker\DeviceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class TaskClaimAndLeaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $teacher;
    protected ProcessingDevice $device;
    protected DevicePairing $pairing;
    protected string $plainToken;

    protected LocalJob $job;
    protected LocalTask $task;

    public function test_admin_workspace_alias_and_owner_recovery_page_are_available(): void
    {
        $this->actingAs($this->owner)->get('/v3/admin/workspace')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Workspace'));
        $this->get("/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/RunRecovery')
                ->where('initial.task_id', $this->task->id));
        $this->actingAs($this->teacher)->getJson("/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation")
            ->assertForbidden();
    }

    public function test_run_navigation_redirects_by_role_and_admin_detail_renders(): void
    {
        $run = $this->createHostedRun();
        $legacy = "/v3/teacher/runs/{$run->id}";
        $this->actingAs($this->owner)->get($legacy)->assertRedirect("/v3/admin/subject-runs/{$run->id}");
        $this->get("/v3/admin/subject-runs/{$run->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/RunShow')->where('run.run_id', $run->id));
        $this->get('/v3/admin/evidence')->assertOk();
        $this->actingAs($this->teacher)->get($legacy)->assertRedirect("/v3/teacher/subject-runs/{$run->id}");
        $this->get($legacy.'/scenes')->assertRedirect("/v3/teacher/subject-runs/{$run->id}/scenes");
        $other = \Tests\Support\V3\AccessFixtures::createEnrolledTeacher('Other run teacher');
        $this->actingAs($other)->get($legacy)->assertNotFound();
        $this->get("/v3/admin/subject-runs/{$run->id}")->assertForbidden();
    }

    public function test_worker_progress_is_fenced_and_never_releases_a_hold_or_renews_a_lease(): void
    {
        $run = $this->createHostedRun();
        $claim = $this->withToken($this->plainToken)->postJson('/api/v3/local-worker/v1/claims', [
            'schema_version' => 1, 'claim_id' => (string) Str::uuid(), 'protocol_version' => 1,
            'capabilities' => ['notebooklm_authoring_v1'], 'available_slots' => 1,
        ])->assertOk();
        $assignment = LocalAssignment::findOrFail($claim->json('assignment_id'));
        $lease = $assignment->lease_expires_at->toIso8601String();
        $this->task->update(['status' => 'reconciliation_required']);
        $payload = ['schema_version' => 1, 'sequence' => 10, 'phase' => 'generating_lessons',
            'status' => 'running', 'lessons_saved' => 13, 'scenes_generated' => 0, 'scenes_built' => 0,
            'current_pdf_ordinal' => 1, 'pdfs_completed' => 0, 'total_pdfs' => 1];
        $url = "/api/v3/local-worker/v1/tasks/{$this->task->id}/progress";
        $this->withHeaders(['X-Assignment-ID' => $assignment->id,
            'X-Assignment-Epoch' => (string) $assignment->assignment_epoch])->postJson($url, $payload)->assertOk();
        $this->assertSame('reconciliation_required', $this->task->fresh()->status);
        $this->assertSame($lease, $assignment->fresh()->lease_expires_at->toIso8601String());
        $this->postJson($url, [...$payload, 'sequence' => 9, 'lessons_saved' => 2])->assertOk();
        $projection = app(\App\Services\V3\WebPipeline\RunProgress::class)->project($run);
        $this->assertSame(13, $projection['counts']['lessons_saved']);
        $this->assertSame('held_reconciliation', $projection['state']);
        $this->assertSame(1, $projection['workflow']['current_pdf_ordinal']);
        $this->postJson($url, [...$payload, 'prompt' => 'forbidden'])->assertUnprocessable();
        $this->withHeader('X-Assignment-Epoch', '999')->postJson($url, $payload)->assertConflict();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('v3_private');

        $this->owner = User::factory()->create([
            'email' => 'admin@test.com',
            'role' => 'admin',
        ]);
        \App\Models\V3\V3AccountAccess::create([
            'user_id' => $this->owner->id,
            'is_active' => true,
            'provisioned_at' => now(),
        ]);
        $this->teacher = WebPipelineFixtures::createTeacherA();

        // Enroll device
        $deviceManager = app(DeviceManager::class);
        $pairData = $deviceManager->createPairingInvitation(
            $this->owner,
            'Test MacBook',
            null,
            ['tenant:all'],
            ['notebooklm_authoring_v1', 'scene_return_v1']
        );

        $redeemResult = $deviceManager->redeemPairing($pairData['pairing_secret']);

        $this->device = ProcessingDevice::findOrFail($redeemResult['device_id']);
        $this->plainToken = $redeemResult['device_token'];
        $this->pairing = DevicePairing::findOrFail($pairData['invitation_id']);

        // Create job and task
        $runId = (string) Str::uuid();
        $jobId = (string) Str::uuid();
        $taskId = (string) Str::uuid();
        $manifestArtifactId = (string) Str::uuid();

        $manifestContent = json_encode([
            'schema_version' => 1,
            'kind' => 'local_worker_input_manifest',
            'task_id' => $taskId,
            'job_id' => $jobId,
            'run_id' => $runId,
            'task_kind' => 'author_prompts',
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'protocol_id' => 'v3_notebooklm_authoring_v1',
            'protocol_version' => 1,
            'settings_snapshot_id' => 'snap-1',
            'settings_sha256' => str_repeat('a', 64),
            'runtime_settings_artifact_id' => (string) Str::uuid(),
            'runtime_settings_sha256' => str_repeat('b', 64),
            'preparation' => ['policy_version' => 2],
            'items' => [],
            'instruction_artifacts' => [],
            'artifacts' => [
                ['artifact_id' => 'art-1', 'purpose' => 'source_pdf'],
            ],
            'required_outputs' => ['prompts' => true],
            'resource_budget' => [
                'max_turn_bytes' => 16777216,
                'max_prompt_export_bytes' => 67108864,
                'max_total_task_bytes' => 536870912,
            ],
            'capabilities' => ['notebooklm_authoring_v1'],
        ]);

        $manifestPath = "manifests/{$taskId}.json";
        Storage::disk('v3_private')->put($manifestPath, $manifestContent);

        LocalArtifact::create([
            'id' => $manifestArtifactId,
            'job_id' => $jobId,
            'task_id' => $taskId,
            'purpose' => 'input_manifest',
            'digest_sha256' => hash('sha256', $manifestContent),
            'size_bytes' => strlen($manifestContent),
            'media_type' => 'application/json',
            'logical_name' => 'manifest.json',
            'storage_path' => $manifestPath,
            'is_quarantine' => false,
            'is_committed' => true,
        ]);

        $this->job = LocalJob::create([
            'id' => $jobId,
            'run_id' => $runId,
            'teacher_id' => $this->teacher->id,
            'status' => 'queued',
            'output_scope' => 'prompts_only',
            'protocol_version' => 'v1',
            'settings_snapshot_id' => 'snap-1',
            'settings_sha256' => str_repeat('a', 64),
            'policy_budgets' => [],
        ]);

        $this->task = LocalTask::create([
            'id' => $taskId,
            'job_id' => $jobId,
            'task_kind' => 'author_prompts',
            'status' => 'queued',
            'input_manifest_id' => $manifestArtifactId,
            'input_manifest_sha256' => hash('sha256', $manifestContent),
            'control_version' => 1,
        ]);
    }

    public function test_claim_queued_task_and_idempotent_replay(): void
    {
        $claimId = (string) Str::uuid();

        // 1. Claim task
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => $claimId,
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $res1->assertStatus(200)
            ->assertJson([
                'schema_version' => 1,
                'task_id' => $this->task->id,
                'job_id' => $this->job->id,
                'task_kind' => 'author_prompts',
                'device_id' => $this->device->id,
                'assignment_epoch' => 1,
                'control_version' => 1,
            ]);

        $assignmentId = $res1->json('assignment_id');
        $this->assertNotNull($assignmentId);

        // Task should now be leased
        $this->task->refresh();
        $this->assertSame('leased', $this->task->status);
        $this->assertSame($assignmentId, $this->task->active_assignment_id);

        // 2. Idempotent replay of same claim returns identical response
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => $claimId,
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $res2->assertStatus(200);
        $this->assertSame($assignmentId, $res2->json('assignment_id'));

        // 3. GET /claims/{claim_id} recovers receipt
        $res3 = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->getJson("/api/v3/local-worker/v1/claims/{$claimId}");

        $res3->assertStatus(200);
        $this->assertSame($assignmentId, $res3->json('assignment_id'));
    }

    public function test_claim_orders_by_creation_time_then_id(): void
    {
        $this->createHostedRun();
        $this->task->created_at = now()->addMinute();
        $this->task->save();
        $created = [];
        foreach (['00000000-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000001'] as $id) {
            $run = SubjectRun::findOrFail($this->job->run_id)->replicate();
            $run->id = (string) Str::uuid();
            $run->submission_key = (string) Str::uuid();
            $run->save();
            $job = $this->job->replicate();
            $job->id = (string) Str::uuid();
            $job->run_id = $run->id;
            $job->save();
            $task = $this->task->replicate();
            $task->id = $id;
            $task->job_id = $job->id;
            $task->created_at = now()->startOfMinute();
            $task->save();
            $created[] = $task;
        }
        $claim = $this->withToken($this->plainToken)->postJson('/api/v3/local-worker/v1/claims', [
            'schema_version' => 1, 'claim_id' => (string) Str::uuid(), 'protocol_version' => 1,
            'capabilities' => ['notebooklm_authoring_v1'], 'available_slots' => 1,
        ])->assertOk();
        $this->assertSame($created[1]->id, $claim->json('task_id'));
        $this->withToken($this->plainToken)->postJson('/api/v3/local-worker/v1/claims', [
            'schema_version' => 1, 'claim_id' => (string) Str::uuid(), 'protocol_version' => 1,
            'capabilities' => ['notebooklm_authoring_v1'], 'available_slots' => 1,
        ])->assertStatus(429)->assertJsonPath('error.code', 'capacity_exhausted');
    }

    public function test_owner_authorizes_attachment_to_existing_terminal_with_bound_evidence(): void
    {
        $this->createHostedRun();
        $effect = LocalProviderEffect::create([
            'id' => (string) Str::uuid(), 'task_id' => $this->task->id, 'job_id' => $this->job->id,
            'operation_kind' => 'terminal_pipeline_export', 'logical_effect_key' => $this->job->id . ':terminal-export-v1',
            'request_digest' => hash('sha256', 'retained terminal request'), 'status' => 'unknown',
        ]);
        LocalAssignment::create([
            'id' => (string) Str::uuid(), 'task_id' => $this->task->id, 'device_id' => $this->device->id,
            'claim_id' => (string) Str::uuid(), 'assignment_epoch' => 1, 'status' => 'expired',
            'lease_expires_at' => now()->subSecond(), 'heartbeat_at' => now()->subMinutes(3), 'control_version' => 1,
        ]);
        $this->task->update(['status' => 'reconciliation_required', 'active_epoch' => 1]);
        $this->job->update(['status' => 'held_reconciliation']);
        $begin = $this->actingAs($this->owner)->postJson("/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation")
            ->assertCreated();
        $evidence = [
            'schema_version' => 1, 'kind' => 'local_terminal_run_evidence',
            'task_id' => $this->task->id, 'job_id' => $this->job->id, 'run_id' => $this->job->run_id,
            'terminal_run_id' => \Ramsey\Uuid\Uuid::uuid5($this->job->id, 'terminal-subject-v1')->toString(),
            'input_manifest_sha256' => $this->task->input_manifest_sha256,
            'request_digest' => $effect->request_digest, 'terminal_status' => 'prompts_exported',
            'terminal_state_version' => null, 'export_sha256' => hash('sha256', 'fixture-export'),
        ];
        $receipt = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $begin->json('assignment_id'),
            'X-Assignment-Epoch' => (string) $begin->json('assignment_epoch'),
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/recoveries", [
            'schema_version' => 1, 'evidence_payload' => $evidence,
        ])->assertOk();
        $data = ['request_id' => (string) Str::uuid(), 'effect_id' => $effect->id,
            'evidence_artifact_id' => $receipt->json('evidence_artifact_id'), 'acknowledge_existing_terminal_run' => true];
        $this->actingAs($this->owner)->getJson("/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation")
            ->assertOk()->assertJsonPath('recovery.can_continue', true)
            ->assertJsonPath('recovery.effect_id', $effect->id)
            ->assertJsonPath('recovery.evidence_artifact_id', $receipt->json('evidence_artifact_id'));
        $endpoint = "/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation/resolve-terminal";
        $this->actingAs($this->teacher)->postJson($endpoint, $data)->assertForbidden();
        $artifact = LocalArtifact::findOrFail($data['evidence_artifact_id']);
        $realBytes = Storage::disk('v3_private')->get($artifact->storage_path);
        Storage::disk('v3_private')->put($artifact->storage_path, '{}');
        $this->actingAs($this->owner)->postJson($endpoint, $data)->assertStatus(422);
        $this->assertSame('unknown', $effect->fresh()->status);
        Storage::disk('v3_private')->put($artifact->storage_path, $realBytes);
        $this->actingAs($this->owner)->postJson($endpoint, $data)->assertOk();
        $this->postJson($endpoint, $data)->assertOk();
        $this->assertSame('reserved', $effect->fresh()->status);
        $this->assertSame($artifact->id, $effect->fresh()->outcome_ref);
        $this->assertSame('queued', $this->task->fresh()->status);
        $claim = $this->withToken($this->plainToken)->postJson('/api/v3/local-worker/v1/claims', [
            'schema_version' => 1, 'claim_id' => (string) Str::uuid(), 'protocol_version' => 1,
            'capabilities' => ['notebooklm_authoring_v1'], 'available_slots' => 1,
        ])->assertOk();
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $claim->json('assignment_id'),
            'X-Assignment-Epoch' => (string) $claim->json('assignment_epoch'),
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/reserve", [
            'schema_version' => 1, 'effect_id' => $effect->id, 'operation_kind' => 'terminal_pipeline_export',
            'logical_effect_key' => $effect->logical_effect_key, 'request_digest' => $effect->request_digest,
        ])->assertOk()->assertJsonPath('terminal_recovery.terminal_run_id', $evidence['terminal_run_id']);
    }

    private function createHostedRun(): SubjectRun
    {
        $settings = \App\Models\V3\SettingsVersion::create([
            'version' => 1, 'schema_version' => 1, 'payload' => [], 'checksum' => hash('sha256', 'fixture-settings'),
            'published_by_user_id' => $this->owner->id, 'published_at' => now(),
        ]);
        $framework = \App\Models\V3\NotebookFrameworkVersion::create([
            'id' => (string) Str::uuid(), 'profile' => 'expanded-root-v1', 'body_path' => 'fixture-framework.txt',
            'body_sha256' => hash('sha256', 'fixture-framework'), 'body_bytes' => 17,
            'first_sha256' => hash('sha256', 'fixture-first'), 'second_sha256' => hash('sha256', 'fixture-second'),
            'third_sha256' => hash('sha256', 'fixture-third'), 'response_format_sha256' => hash('sha256', 'fixture-format'),
            'approved_by_user_id' => $this->owner->id, 'approved_at' => now(),
        ]);
        $subject = Subject::create(['name' => 'Queue/recovery fixture', 'description' => 'Transport-only fixture']);
        return SubjectRun::create([
            'id' => $this->job->run_id, 'owner_user_id' => $this->teacher->id, 'subject_id' => $subject->id,
            'origin' => 'web', 'protocol' => 'notebooklm_per_pdf_web_v1', 'settings_version_id' => $settings->id,
            'framework_version_id' => $framework->id, 'execution_mode' => 'local_worker_v1',
            'mode' => 'export_manual', 'qualification_mode' => 'owner_waived_demo', 'state' => 'queued',
            'state_version' => 1, 'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'fixture-request'), 'captured_settings_json' => '{}',
            'capture_sha256' => hash('sha256', '{}'),
        ]);
    }

    public function test_expiry_clears_dead_assignment_without_clearing_reconciliation_hold(): void
    {
        $assignment = LocalAssignment::create([
            'id' => (string) Str::uuid(), 'task_id' => $this->task->id, 'device_id' => $this->device->id,
            'claim_id' => (string) Str::uuid(), 'assignment_epoch' => 1, 'status' => 'active',
            'lease_expires_at' => now()->subSecond(), 'heartbeat_at' => now()->subMinutes(3), 'control_version' => 1,
        ]);
        $this->task->update(['status' => 'reconciliation_required', 'active_assignment_id' => $assignment->id, 'active_epoch' => 1]);
        $this->artisan('v3:expire-local-worker-leases')->assertSuccessful();
        $this->assertNull($this->task->fresh()->active_assignment_id);
        $this->assertSame('reconciliation_required', $this->task->fresh()->status);
        $this->actingAs($this->owner)->postJson("/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation")
            ->assertCreated()->assertJsonPath('status', 'reconciliation_only');
    }

    public function test_claim_empty_queue_returns_204(): void
    {
        $this->task->status = 'completed';
        $this->task->save();

        $claimId = (string) Str::uuid();

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => $claimId,
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $res->assertStatus(204);
    }

    public function test_worker_recovery_endpoint_cannot_self_attest_effect_outcomes(): void
    {
        $claim = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => (string) Str::uuid(),
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);
        $claim->assertOk();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $claim->json('assignment_id'),
            'X-Assignment-Epoch' => (string) $claim->json('assignment_epoch'),
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/recoveries", [
            'schema_version' => 1,
            'evidence_payload' => ['observed' => 'provider history inspected'],
            'reconciled_effects' => [[
                'effect_id' => (string) Str::uuid(),
                'status' => 'succeeded',
                'outcome_ref' => 'worker_claimed_success',
            ]],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_recovery_request');
        $this->assertSame('leased', $this->task->fresh()->status);
        $this->assertSame(0, LocalArtifact::where('task_id', $this->task->id)
            ->where('logical_name', 'like', 'reconciliation_evidence_%')->count());
    }

    public function test_owner_reconciliation_flow_records_evidence_before_resuming_task(): void
    {
        $effect = LocalProviderEffect::create([
            'id' => (string) Str::uuid(),
            'task_id' => $this->task->id,
            'job_id' => $this->job->id,
            'logical_effect_key' => 'reconcile-after-crash',
            'operation_kind' => 'divide_map',
            'request_digest' => hash('sha256', 'request'),
            'status' => 'unknown',
        ]);
        $secondEffect = LocalProviderEffect::create([
            'id' => (string) Str::uuid(),
            'task_id' => $this->task->id,
            'job_id' => $this->job->id,
            'logical_effect_key' => 'second-reconcile-after-crash',
            'operation_kind' => 'chat',
            'request_digest' => hash('sha256', 'second request'),
            'status' => 'dispatching',
        ]);
        LocalAssignment::create([
            'id' => (string) Str::uuid(),
            'task_id' => $this->task->id,
            'device_id' => $this->device->id,
            'claim_id' => (string) Str::uuid(),
            'assignment_epoch' => 1,
            'lease_expires_at' => now()->subSecond(),
            'heartbeat_at' => now()->subMinutes(3),
            'status' => 'expired',
            'control_version' => 1,
        ]);
        $this->task->update(['status' => 'reconciliation_required', 'active_epoch' => 1]);
        $this->job->update(['status' => 'held_reconciliation']);

        $begin = $this->actingAs($this->owner)
            ->postJson("/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation");
        $begin->assertCreated()->assertJsonPath('status', 'reconciliation_only');

        $assignmentId = $begin->json('assignment_id');
        $epoch = (string) $begin->json('assignment_epoch');
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $assignmentId,
            'X-Assignment-Epoch' => $epoch,
        ])->getJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/assignment")
            ->assertOk()->assertJsonPath('assignment_status', 'reconciliation_only');

        $evidence = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $assignmentId,
            'X-Assignment-Epoch' => $epoch,
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/recoveries", [
            'schema_version' => 1,
            'evidence_payload' => ['provider_history' => 'confirmed no remote effect'],
        ])->assertOk();

        $untrustedEvidenceId = (string) Str::uuid();
        LocalArtifact::create([
            'id' => $untrustedEvidenceId,
            'job_id' => $this->job->id,
            'task_id' => $this->task->id,
            'purpose' => 'authoring_raw_response',
            'digest_sha256' => hash('sha256', 'untrusted upload'),
            'size_bytes' => strlen('untrusted upload'),
            'media_type' => 'application/json',
            'logical_name' => 'reconciliation_evidence_' . $untrustedEvidenceId . '.json',
            'storage_path' => 'local-worker-staging/untrusted-evidence',
            'is_quarantine' => true,
            'is_committed' => false,
        ]);
        $this->actingAs($this->owner)
            ->postJson("/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation/resolve-no-effect", [
                'schema_version' => 1,
                'effect_id' => $effect->id,
                'evidence_artifact_id' => $untrustedEvidenceId,
                'acknowledge_no_external_effect' => true,
            ])->assertUnprocessable()->assertJsonPath('error.code', 'reconciliation_evidence_required');

        $firstResolution = $this->actingAs($this->owner)
            ->postJson("/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation/resolve-no-effect", [
                'schema_version' => 1,
                'effect_id' => $effect->id,
                'evidence_artifact_id' => $evidence->json('evidence_artifact_id'),
                'acknowledge_no_external_effect' => true,
            ]);
        $firstResolution->assertOk()->assertJsonPath('effect_status', 'failed_no_effect')
            ->assertJsonPath('task_status', 'reconciliation_required');
        $this->assertSame('reconciliation_only', LocalAssignment::findOrFail($assignmentId)->status);

        $this->actingAs($this->owner)
            ->postJson("/v3/admin/local-worker/tasks/{$this->task->id}/reconciliation/resolve-no-effect", [
                'schema_version' => 1,
                'effect_id' => $secondEffect->id,
                'evidence_artifact_id' => $evidence->json('evidence_artifact_id'),
                'acknowledge_no_external_effect' => true,
            ])->assertOk()->assertJsonPath('effect_status', 'failed_no_effect')
                ->assertJsonPath('task_status', 'queued');

        $this->assertSame('queued', $this->task->fresh()->status);
        $this->assertSame('queued', $this->job->fresh()->status);
    }

    public function test_expired_clean_lease_is_requeued_by_scheduled_reaper(): void
    {
        $claim = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => (string) Str::uuid(),
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);
        $claim->assertOk();
        $assignment = LocalAssignment::findOrFail($claim->json('assignment_id'));
        $assignment->lease_expires_at = now()->subSecond();
        $assignment->save();

        $this->artisan('v3:expire-local-worker-leases')->assertExitCode(0);

        $this->assertSame('expired', $assignment->fresh()->status);
        $this->assertSame('queued', $this->task->fresh()->status);
        $this->assertNull($this->task->fresh()->active_assignment_id);
    }

    public function test_expired_lease_with_ambiguous_effect_is_held_for_reconciliation(): void
    {
        $claim = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => (string) Str::uuid(),
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);
        $claim->assertOk();
        $assignment = LocalAssignment::findOrFail($claim->json('assignment_id'));
        $assignment->lease_expires_at = now()->subSecond();
        $assignment->save();
        LocalProviderEffect::create([
            'id' => (string) Str::uuid(),
            'task_id' => $this->task->id,
            'job_id' => $this->job->id,
            'logical_effect_key' => 'unknown-after-lease',
            'operation_kind' => 'divide_map',
            'request_digest' => hash('sha256', 'uncertain'),
            'status' => 'unknown',
        ]);

        $this->artisan('v3:expire-local-worker-leases')->assertExitCode(0);

        $this->assertSame('expired', $assignment->fresh()->status);
        $this->assertSame('reconciliation_required', $this->task->fresh()->status);
        $this->assertSame('held_reconciliation', $this->job->fresh()->status);
        $this->assertNull($this->task->fresh()->active_assignment_id);
    }

    public function test_heartbeat_renews_lease(): void
    {
        // Setup assignment
        $claimId = (string) Str::uuid();
        $claimRes = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => $claimId,
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $assignmentId = $claimRes->json('assignment_id');
        $assignmentEpoch = $claimRes->json('assignment_epoch');

        $hbRes = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->withHeader('X-Assignment-ID', $assignmentId)
            ->withHeader('X-Assignment-Epoch', 1)
            ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/heartbeats", [
                'schema_version' => 1,
                'observed_control_version' => 1,
            ]);

        $hbRes->assertStatus(200)
            ->assertJson([
                'schema_version' => 1,
                'task_id' => $this->task->id,
                'assignment_id' => $assignmentId,
                'assignment_epoch' => 1,
            ]);
    }

    public function test_release_clean_task_requeues_for_next_worker(): void
    {
        $claimId = (string) Str::uuid();
        $claimRes = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => $claimId,
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $assignmentId = $claimRes->json('assignment_id');

        $relRes = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->withHeader('X-Assignment-ID', $assignmentId)
            ->withHeader('X-Assignment-Epoch', 1)
            ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/release", [
                'schema_version' => 1,
                'reason' => 'Worker rebooting',
            ]);

        $relRes->assertStatus(200)
            ->assertJson(['status' => 'released']);

        $this->task->refresh();
        $this->assertSame('queued', $this->task->status);
        $this->assertNull($this->task->active_assignment_id);
    }

    public function test_release_does_not_resume_a_paused_task(): void
    {
        $claim = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => (string) Str::uuid(),
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $this->task->update(['status' => 'paused', 'control_version' => 2]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $claim->json('assignment_id'),
            'X-Assignment-Epoch' => (string) $claim->json('assignment_epoch'),
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/release", [
            'schema_version' => 1,
            'reason' => 'Worker drained after pause',
        ]);

        $response->assertOk()->assertJsonPath('status', 'released');
        $this->task->refresh();
        $this->assertSame('paused', $this->task->status);
        $this->assertNull($this->task->active_assignment_id);
    }

    public function test_heartbeat_rejects_stale_control_version(): void
    {
        $claim = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => (string) Str::uuid(),
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $this->task->update(['control_version' => 2]);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $claim->json('assignment_id'),
            'X-Assignment-Epoch' => (string) $claim->json('assignment_epoch'),
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/heartbeats", [
            'schema_version' => 1,
            'observed_control_version' => 1,
        ]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'control_changed');
    }

    public function test_provider_hold_outcomes_are_persisted_without_controller_errors(): void
    {
        $claim = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => (string) Str::uuid(),
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $fence = [
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $claim->json('assignment_id'),
            'X-Assignment-Epoch' => (string) $claim->json('assignment_epoch'),
        ];

        foreach ([
            ['authentication_required', 'auth_required', 'paused_auth', 'paused_auth'],
            ['rate_limited', 'rate_limited', 'paused_quota', 'paused_quota'],
            ['unknown', 'ambiguous_transport', 'reconciliation_required', 'held_reconciliation'],
        ] as [$status, $outcomeRef, $taskStatus, $jobStatus]) {
            $effect = LocalProviderEffect::create([
                'id' => (string) Str::uuid(),
                'task_id' => $this->task->id,
                'job_id' => $this->job->id,
                'logical_effect_key' => 'hold:' . $status,
                'operation_kind' => 'ask_lesson',
                'request_digest' => hash('sha256', $status),
                'status' => 'reserved',
            ]);

            $this->withHeaders($fence)
                ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/{$effect->id}/outcome", [
                    'schema_version' => 1,
                    'outcome_ref' => $outcomeRef,
                    'status' => $status,
                ])
                ->assertOk()
                ->assertJsonPath('status', $status);

            $this->assertSame($taskStatus, $this->task->fresh()->status);
            $this->assertSame($jobStatus, $this->job->fresh()->status);
            $this->assertSame($status, $effect->fresh()->status);
        }
    }

    public function test_held_task_states_cannot_reserve_or_dispatch_provider_effects(): void
    {
        $claim = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => (string) Str::uuid(),
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ])->assertOk();

        $fence = [
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $claim->json('assignment_id'),
            'X-Assignment-Epoch' => (string) $claim->json('assignment_epoch'),
        ];

        foreach (['paused_auth', 'paused_quota', 'reconciliation_required', 'completed'] as $status) {
            $this->task->update(['status' => $status]);
            $effectId = (string) Str::uuid();
            $effect = LocalProviderEffect::create([
                'id' => $effectId,
                'task_id' => $this->task->id,
                'job_id' => $this->job->id,
                'logical_effect_key' => 'held:' . $status,
                'operation_kind' => 'ask_lesson',
                'request_digest' => hash('sha256', $status),
                'status' => 'reserved',
            ]);

            $this->withHeaders($fence)
                ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/reserve", [
                    'schema_version' => 1,
                    'effect_id' => (string) Str::uuid(),
                    'logical_effect_key' => 'new:' . $status,
                    'operation_kind' => 'ask_lesson',
                    'request_digest' => hash('sha256', 'new:' . $status),
                ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'control_changed');

            $this->withHeaders($fence)
                ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/{$effectId}/dispatch", [
                    'schema_version' => 1,
                    'worker_process_id' => 'held-state-test',
                ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'control_changed');

            $this->assertSame('reserved', $effect->fresh()->status);
            $this->assertSame(0, (int) $effect->fresh()->attempt_count);
        }
    }

    public function test_release_with_pending_effect_is_rejected_with_reconciliation_required(): void
    {
        $claimId = (string) Str::uuid();
        $claimRes = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => $claimId,
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $assignmentId = $claimRes->json('assignment_id');

        // Reserve a provider effect
        LocalProviderEffect::create([
            'id' => (string) Str::uuid(),
            'task_id' => $this->task->id,
            'job_id' => $this->job->id,
            'logical_effect_key' => 'ask:0:0',
            'operation_kind' => 'ask_lesson',
            'request_digest' => str_repeat('c', 64),
            'status' => 'reserved',
        ]);

        $relRes = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->withHeader('X-Assignment-ID', $assignmentId)
            ->withHeader('X-Assignment-Epoch', 1)
            ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/release", [
                'schema_version' => 1,
                'reason' => 'Trying to release with pending effect',
            ]);

        $relRes->assertStatus(409)
            ->assertJsonPath('error.code', 'reconciliation_required');
    }

    public function test_effect_reserve_dispatch_and_outcome_lifecycle(): void
    {
        $claimId = (string) Str::uuid();
        $claimRes = $this->withHeader('Authorization', 'Bearer ' . $this->plainToken)
            ->postJson('/api/v3/local-worker/v1/claims', [
                'schema_version' => 1,
                'claim_id' => $claimId,
                'protocol_version' => 1,
                'capabilities' => ['notebooklm_authoring_v1'],
                'available_slots' => 1,
            ]);

        $assignmentId = $claimRes->json('assignment_id');
        $assignmentEpoch = $claimRes->json('assignment_epoch');
        $effectId = (string) Str::uuid();
        $digest = hash('sha256', 'request payload');

        // 1. Reserve effect
        $resReserve = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $assignmentId,
            'X-Assignment-Epoch' => (string) $assignmentEpoch,
        ])
            ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/reserve", [
                'schema_version' => 1,
                'effect_id' => $effectId,
                'logical_effect_key' => 'effect:create_notebook',
                'operation_kind' => 'create_notebook',
                'request_digest' => $digest,
            ]);

        $resReserve->assertStatus(201)
            ->assertJson([
                'schema_version' => 1,
                'effect_id' => $effectId,
                'status' => 'reserved',
            ]);

        // 2. Dispatch permit
        $resDispatch = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $assignmentId,
            'X-Assignment-Epoch' => (string) $assignmentEpoch,
        ])
            ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/{$effectId}/dispatch", [
                'schema_version' => 1,
                'worker_process_id' => 'pid-999',
            ]);

        $resDispatch->assertStatus(200)
            ->assertJson([
                'schema_version' => 1,
                'effect_id' => $effectId,
                'attempt_number' => 1,
            ]);
        $this->assertNotNull($resDispatch->json('permit_id'));
        $this->assertNotNull($resDispatch->json('send_not_after'));

        // 3. Outcome
        $resOutcome = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $assignmentId,
            'X-Assignment-Epoch' => (string) $assignmentEpoch,
        ])
            ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/{$effectId}/outcome", [
                'schema_version' => 1,
                'outcome_ref' => 'notebook-uuid-777',
                'status' => 'succeeded',
            ]);

        $resOutcome->assertStatus(200)
            ->assertJson([
                'schema_version' => 1,
                'effect_id' => $effectId,
                'status' => 'succeeded',
                'outcome_ref' => 'notebook-uuid-777',
            ]);

        $exactReplay = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $assignmentId,
            'X-Assignment-Epoch' => (string) $assignmentEpoch,
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/{$effectId}/outcome", [
            'schema_version' => 1,
            'outcome_ref' => 'notebook-uuid-777',
            'status' => 'succeeded',
        ]);
        $exactReplay->assertOk();

        $changedReplay = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $assignmentId,
            'X-Assignment-Epoch' => (string) $assignmentEpoch,
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/{$effectId}/outcome", [
            'schema_version' => 1,
            'outcome_ref' => 'different-receipt',
            'status' => 'failed_no_effect',
        ]);
        $changedReplay->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');

        // 4. Read effect
        $resGet = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $assignmentId,
            'X-Assignment-Epoch' => (string) $assignmentEpoch,
        ])
            ->getJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/effects/{$effectId}");

        $resGet->assertStatus(200)
            ->assertJson([
                'status' => 'succeeded',
                'outcome_ref' => 'notebook-uuid-777',
                'attempt_count' => 1,
            ]);
    }
}
