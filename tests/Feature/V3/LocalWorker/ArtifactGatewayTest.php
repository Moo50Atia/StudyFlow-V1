<?php

namespace Tests\Feature\V3\LocalWorker;

use App\Models\User;
use App\Models\V3\DevicePairing;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Services\V3\LocalWorker\DeviceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class ArtifactGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $teacher;
    protected ProcessingDevice $device;
    protected string $plainToken;
    protected LocalJob $job;
    protected LocalTask $task;
    protected LocalAssignment $assignment;
    protected LocalArtifact $manifestArtifact;
    protected LocalArtifact $pdfArtifact;
    protected string $manifestContent;
    protected string $pdfContent;

    private function assignmentHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $this->assignment->id,
            'X-Assignment-Epoch' => (string) $this->assignment->assignment_epoch,
        ];
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

        $deviceManager = app(DeviceManager::class);
        $pairData = $deviceManager->createPairingInvitation(
            $this->owner,
            'MacBook Pro',
            null,
            ['tenant:all'],
            ['notebooklm_authoring_v1', 'scene_return_v1']
        );

        $redeemResult = $deviceManager->redeemPairing($pairData['pairing_secret']);
        $this->device = ProcessingDevice::findOrFail($redeemResult['device_id']);
        $this->plainToken = $redeemResult['device_token'];

        $runId = (string) Str::uuid();
        $jobId = (string) Str::uuid();
        $taskId = (string) Str::uuid();
        $manifestArtifactId = (string) Str::uuid();
        $pdfArtifactId = (string) Str::uuid();

        $this->pdfContent = "%PDF-1.4 Minimal PDF Content For Range Test 1234567890";
        $pdfPath = "sources/{$pdfArtifactId}.pdf";
        Storage::disk('v3_private')->put($pdfPath, $this->pdfContent);

        $this->pdfArtifact = LocalArtifact::create([
            'id' => $pdfArtifactId,
            'job_id' => $jobId,
            'task_id' => $taskId,
            'purpose' => 'source_pdf',
            'digest_sha256' => hash('sha256', $this->pdfContent),
            'size_bytes' => strlen($this->pdfContent),
            'media_type' => 'application/pdf',
            'logical_name' => 'lesson.pdf',
            'storage_path' => $pdfPath,
            'is_quarantine' => false,
            'is_committed' => true,
        ]);

        $this->manifestContent = json_encode([
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
                [
                    'artifact_id' => $pdfArtifactId,
                    'purpose' => 'source_pdf',
                    'size' => strlen($this->pdfContent),
                    'sha256' => hash('sha256', $this->pdfContent),
                    'media_type' => 'application/pdf',
                    'logical_name' => 'lesson.pdf',
                ],
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
        Storage::disk('v3_private')->put($manifestPath, $this->manifestContent);

        $this->manifestArtifact = LocalArtifact::create([
            'id' => $manifestArtifactId,
            'job_id' => $jobId,
            'task_id' => $taskId,
            'purpose' => 'input_manifest',
            'digest_sha256' => hash('sha256', $this->manifestContent),
            'size_bytes' => strlen($this->manifestContent),
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
            'status' => 'leased',
            'input_manifest_id' => $manifestArtifactId,
            'input_manifest_sha256' => hash('sha256', $this->manifestContent),
            'control_version' => 1,
            'active_epoch' => 1,
        ]);

        $this->assignment = LocalAssignment::create([
            'id' => (string) Str::uuid(),
            'task_id' => $this->task->id,
            'device_id' => $this->device->id,
            'claim_id' => (string) Str::uuid(),
            'assignment_epoch' => 1,
            'lease_expires_at' => now()->addSeconds(120),
            'heartbeat_at' => now(),
            'status' => 'active',
            'control_version' => 1,
        ]);

        $this->task->active_assignment_id = $this->assignment->id;
        $this->task->save();
    }

    public function test_stream_input_manifest_with_etag(): void
    {
        $res = $this->withHeaders($this->assignmentHeaders())
            ->get("/api/v3/local-worker/v1/tasks/{$this->task->id}/input-manifest");

        $res->assertStatus(200);
        $res->assertHeader('ETag', '"' . $this->task->input_manifest_sha256 . '"');
        $this->assertSame($this->manifestContent, $res->getContent());
    }

    public function test_input_manifest_download_rechecks_current_teacher_access(): void
    {
        $this->teacher->v3Access()->update(['is_active' => false]);

        $this->withHeaders($this->assignmentHeaders())
            ->get("/api/v3/local-worker/v1/tasks/{$this->task->id}/input-manifest")
            ->assertForbidden();
    }

    public function test_input_manifest_download_rejects_expired_lease(): void
    {
        $this->assignment->update(['lease_expires_at' => now()->subSecond()]);

        $this->withHeaders($this->assignmentHeaders())
            ->get("/api/v3/local-worker/v1/tasks/{$this->task->id}/input-manifest")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'lease_lost');
    }

    public function test_input_manifest_download_rechecks_current_device_capability(): void
    {
        $this->device->update(['capabilities' => []]);

        $this->withHeaders($this->assignmentHeaders())
            ->get("/api/v3/local-worker/v1/tasks/{$this->task->id}/input-manifest")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'unsupported_capability');
    }

    public function test_stream_input_artifact_full_and_range(): void
    {
        // 1. Full stream
        $resFull = $this->withHeaders($this->assignmentHeaders())
            ->get("/api/v3/local-worker/v1/tasks/{$this->task->id}/inputs/{$this->pdfArtifact->id}");

        $resFull->assertStatus(200);
        $resFull->assertHeader('Content-Length', (string) strlen($this->pdfContent));
        $resFull->assertHeader('Accept-Ranges', 'bytes');

        // 2. Range request (bytes=0-9)
        $resRange = $this->withHeaders($this->assignmentHeaders())
            ->withHeader('Range', 'bytes=0-9')
            ->get("/api/v3/local-worker/v1/tasks/{$this->task->id}/inputs/{$this->pdfArtifact->id}");

        $resRange->assertStatus(206);
        $resRange->assertHeader('Content-Range', "bytes 0-9/" . strlen($this->pdfContent));
        $resRange->assertHeader('Content-Length', '10');
    }

    public function test_upload_staging_slot_creation_and_bounds_check(): void
    {
        $uploadId = (string) Str::uuid();
        $artifactId = (string) Str::uuid();

        // 1. Exceeds turn budget (16 MiB)
        $resFail = $this->withHeaders($this->assignmentHeaders())
            ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/uploads", [
                'schema_version' => 1,
                'upload_id' => $uploadId,
                'artifact_id' => $artifactId,
                'purpose' => 'raw_turn',
                'media_type' => 'application/json',
                'expected_bytes' => 20000000, // 20 MB > 16 MB
                'expected_sha256' => str_repeat('d', 64),
            ]);

        $resFail->assertStatus(413)
            ->assertJsonPath('error.code', 'bounds_exceeded');

        // 2. Valid slot creation
        $resOk = $this->withHeaders($this->assignmentHeaders())
            ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/uploads", [
                'schema_version' => 1,
                'upload_id' => $uploadId,
                'artifact_id' => $artifactId,
                'purpose' => 'raw_turn',
                'media_type' => 'application/json',
                'expected_bytes' => 1024,
                'expected_sha256' => str_repeat('e', 64),
            ]);

        $resOk->assertStatus(201)
            ->assertJson([
                'schema_version' => 1,
                'upload_id' => $uploadId,
                'status' => 'slot_reserved',
            ]);
    }

    public function test_upload_bytes_verification_and_mismatch_rejection(): void
    {
        $uploadId = (string) Str::uuid();
        $artifactId = (string) Str::uuid();
        $content = "{\"hello\":\"world\"}";
        $contentSha = hash('sha256', $content);

        // Create slot
        $this->withHeaders($this->assignmentHeaders())
            ->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/uploads", [
                'schema_version' => 1,
                'upload_id' => $uploadId,
                'artifact_id' => $artifactId,
                'purpose' => 'raw_turn',
                'media_type' => 'application/json',
                'expected_bytes' => strlen($content),
                'expected_sha256' => $contentSha,
            ])->assertStatus(201);

        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'HTTP_X_ASSIGNMENT_ID' => $this->assignment->id,
            'HTTP_X_ASSIGNMENT_EPOCH' => (string) $this->assignment->assignment_epoch,
        ];

        // 1. Upload corrupted bytes (content mismatch)
        $corruptRes = $this->call(
            'PUT',
            "/api/v3/local-worker/v1/tasks/{$this->task->id}/uploads/{$uploadId}",
            [],
            [],
            [],
            $server,
            "corrupted bytes"
        );

        $corruptRes->assertStatus(422)
            ->assertJsonPath('error.code', 'artifact_mismatch');

        // 2. Upload exact matching bytes
        $validRes = $this->call(
            'PUT',
            "/api/v3/local-worker/v1/tasks/{$this->task->id}/uploads/{$uploadId}",
            [],
            [],
            [],
            $server,
            $content
        );

        $validRes->assertStatus(200)
            ->assertJson([
                'schema_version' => 1,
                'upload_id' => $uploadId,
                'status' => 'uploaded',
                'verified_bytes' => strlen($content),
                'verified_sha256' => $contentSha,
            ]);

        // 3. GET /uploads/{upload} recovers verified status
        $getRes = $this->withHeaders($this->assignmentHeaders())
            ->getJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/uploads/{$uploadId}");

        $getRes->assertStatus(200)
            ->assertJson([
                'schema_version' => 1,
                'status' => 'uploaded',
                'verified_bytes' => strlen($content),
                'verified_sha256' => $contentSha,
            ]);
    }
}
