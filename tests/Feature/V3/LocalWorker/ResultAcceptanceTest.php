<?php

namespace Tests\Feature\V3\LocalWorker;

use App\Models\Lecture;
use App\Models\Subject;
use App\Models\User;
use App\Models\V3\DevicePairing;
use App\Models\V3\Document;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalResult;
use App\Models\V3\LocalSceneSubmission;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\PromptSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\LocalWorker\DeviceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class ResultAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $teacher;
    protected ProcessingDevice $device;
    protected string $plainToken;
    protected SubjectRun $run;
    protected SubjectRunItem $item;
    protected ProcessingCase $case;
    protected LocalJob $job;
    protected LocalTask $task;
    protected LocalAssignment $assignment;
    protected string $manifestSha256;

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

        // Create Subject, Lecture, Case, Run, Item
        $subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher, 'Physics 101');
        $lecture = Lecture::create([
            'subject_id' => $subject->id,
            'title' => 'Lecture 1',
        ]);

        $frameworkId = (string) Str::uuid();
        \App\Models\V3\NotebookFrameworkVersion::create([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $frameworkId . '.md',
            'body_sha256' => hash('sha256', 'body'),
            'body_bytes' => 4,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'fmt'),
            'approved_by_user_id' => $this->owner->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'published_by_user_id' => $this->owner->id,
            'published_at' => now(),
            'payload' => json_encode(['dummy' => 'settings']),
            'checksum' => hash('sha256', '{}'),
        ]);

        $this->run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'origin' => 'web_intake',
            'owner_user_id' => $this->teacher->id,
            'subject_id' => $subject->id,
            'mode' => 'export_manual',
            'settings_version_id' => $settingsId,
            'framework_version_id' => $frameworkId,
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'req'),
            'captured_settings_json' => '{}',
            'capture_sha256' => hash('sha256', '{}'),
            'state' => 'running',
            'state_version' => 1,
            'total_lessons' => 0,
            'total_scenes' => 0,
        ]);

        $document = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'byte_size' => 2048,
            'page_count' => 5,
            'sha256' => hash('sha256', 'pdf-bytes'),
            'storage_path' => 'documents/mechanics.pdf',
            'state' => 'ready',
        ]);

        $this->case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'settings_version_id' => $settingsId,
            'document_id' => $document->id,
            'notebook_framework_version_id' => $frameworkId,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'ready',
        ]);

        $binding = app(\App\Services\V3\NotebookLM\DirectSourceCoordinator::class)->prepare($this->case);
        \App\Models\V3\SourceActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'workflow_id' => 'web-' . $this->case->id,
            'state' => 'accepted',
            'signal_attempts' => 0,
        ]);
        \App\Models\V3\MapActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'binding_id' => $binding->id,
            'workflow_id' => 'web-' . $this->case->id,
            'state' => 'accepted',
            'signal_attempts' => 0,
        ]);

        $this->item = SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacher->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Mechanics Item',
            'original_filename' => 'mechanics.pdf',
            'document_id' => $document->id,
            'case_id' => $this->case->id,
            'state' => 'running',
            'state_version' => 1,
        ]);

        $this->job = LocalJob::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'teacher_id' => $this->teacher->id,
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_and_scenes',
            'status' => 'claimed',
            'settings_snapshot_id' => (string) Str::uuid(),
            'settings_sha256' => str_repeat('c', 64),
            'policy_budgets' => ['max_output_bytes' => 536870912],
            'control_version' => 1,
        ]);

        $this->task = LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $this->job->id,
            'task_kind' => 'author_prompts',
            'status' => 'leased',
            'input_manifest_id' => (string) Str::uuid(),
            'input_manifest_sha256' => str_repeat('0', 64),
            'control_version' => 1,
        ]);

        $runtimeSettingsArtifactId = (string) Str::uuid();
        $preparedArtifactId = (string) Str::uuid();
        $settingsSnapshotId = (string) Str::uuid();
        $settingsSha = str_repeat('b', 64);
        $runtimeSettingsBytes = json_encode([
            'schema_version' => 1,
            'settings_version_id' => $settingsSnapshotId,
            'settings_sha256' => $settingsSha,
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_and_scenes',
            'captured_settings' => ['limits' => [], 'qualification' => [], 'providers' => [], 'templates' => []],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $runtimeSettingsSha = hash('sha256', $runtimeSettingsBytes);
        $frameworkArtifactId = (string) Str::uuid();
        $frameworkBytes = 'Approved test framework';
        $frameworkSha = hash('sha256', $frameworkBytes);
        $preparedBytes = '%PDF-1.4 prepared test';
        $preparedSha = hash('sha256', $preparedBytes);
        $originalBytes = 'original test PDF';
        $originalSha = hash('sha256', $originalBytes);
        foreach ([
            [$runtimeSettingsArtifactId, 'runtime_settings', $runtimeSettingsBytes, 'application/json', 'runtime_settings.json'],
            [$frameworkArtifactId, 'framework_body', $frameworkBytes, 'text/plain', 'framework.txt'],
            [$preparedArtifactId, 'prepared_pdf', $preparedBytes, 'application/pdf', 'part_1.pdf'],
            [$this->item->document_id, 'original_pdf', $originalBytes, 'application/pdf', 'original.pdf'],
        ] as [$id, $purpose, $bytes, $mediaType, $logicalName]) {
            $path = "local_worker/{$this->job->id}/{$logicalName}";
            Storage::disk('v3_private')->put($path, $bytes);
            LocalArtifact::create([
                'id' => $id, 'job_id' => $this->job->id, 'task_id' => $this->task->id,
                'purpose' => $purpose, 'digest_sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes),
                'media_type' => $mediaType, 'logical_name' => $logicalName, 'storage_path' => $path,
                'is_quarantine' => false, 'is_committed' => true,
            ]);
        }
        $inputManifest = [
            'schema_version' => 1,
            'kind' => 'local_worker_input_manifest',
            'task_id' => $this->task->id,
            'job_id' => $this->job->id,
            'run_id' => $this->run->id,
            'task_kind' => 'author_prompts',
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_and_scenes',
            'protocol_id' => 'v3_notebooklm_authoring_v1',
            'protocol_version' => 'v3_notebooklm_authoring_v1',
            'settings_snapshot_id' => $settingsSnapshotId,
            'settings_sha256' => $settingsSha,
            'runtime_settings_artifact_id' => $runtimeSettingsArtifactId,
            'runtime_settings_sha256' => $runtimeSettingsSha,
            'preparation' => ['inventory_sha256' => str_repeat('c', 64)],
            'items' => [[
                'item_id' => $this->item->id,
                'original_artifact_id' => $this->item->document_id,
                'original_sha256' => $originalSha,
                'original_byte_count' => strlen($originalBytes),
                'original_page_count' => 5,
                'upload_ordinal' => 1,
                'source_ordinal' => 1,
                'part_ordinal' => 1,
                'original_page_start' => 1,
                'original_page_end' => 5,
                'prepared_artifact_id' => $preparedArtifactId,
                'prepared_sha256' => $preparedSha,
                'prepared_byte_count' => strlen($preparedBytes),
                'prepared_page_count' => 5,
            ]],
            'instruction_artifacts' => [[
                'name' => 'framework', 'version' => '1', 'artifact_id' => $frameworkArtifactId,
                'sha256' => $frameworkSha, 'size_bytes' => strlen($frameworkBytes),
            ]],
            'artifacts' => [
                ['artifact_id' => $runtimeSettingsArtifactId, 'purpose' => 'runtime_settings', 'size_bytes' => strlen($runtimeSettingsBytes), 'digest_sha256' => $runtimeSettingsSha, 'media_type' => 'application/json', 'logical_name' => 'runtime_settings.json'],
                ['artifact_id' => $frameworkArtifactId, 'purpose' => 'framework_body', 'size_bytes' => strlen($frameworkBytes), 'digest_sha256' => $frameworkSha, 'media_type' => 'text/plain', 'logical_name' => 'framework.txt'],
                ['artifact_id' => $this->item->document_id, 'purpose' => 'original_pdf', 'size_bytes' => strlen($originalBytes), 'digest_sha256' => $originalSha, 'media_type' => 'application/pdf', 'logical_name' => 'original.pdf'],
                ['artifact_id' => $preparedArtifactId, 'purpose' => 'prepared_pdf', 'size_bytes' => strlen($preparedBytes), 'digest_sha256' => $preparedSha, 'media_type' => 'application/pdf', 'logical_name' => 'part_1.pdf'],
            ],
            'required_outputs' => ['prompts' => true],
            'resource_budget' => ['max_total_task_bytes' => 536870912],
            'capabilities' => ['notebooklm_authoring_v1'],
        ];
        $manifestBytes = json_encode($inputManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $manifestArtifactId = (string) Str::uuid();
        $manifestPath = "local_worker/{$this->job->id}/input_manifest.json";
        Storage::disk('v3_private')->put($manifestPath, $manifestBytes);
        LocalArtifact::create([
            'id' => $manifestArtifactId,
            'job_id' => $this->job->id,
            'task_id' => $this->task->id,
            'purpose' => 'input_manifest',
            'digest_sha256' => hash('sha256', $manifestBytes),
            'size_bytes' => strlen($manifestBytes),
            'media_type' => 'application/json',
            'logical_name' => 'input_manifest.json',
            'storage_path' => $manifestPath,
            'is_quarantine' => false,
            'is_committed' => true,
        ]);
        $this->manifestSha256 = hash('sha256', $manifestBytes);
        $this->task->update([
            'input_manifest_id' => $manifestArtifactId,
            'input_manifest_sha256' => $this->manifestSha256,
        ]);

        $this->assignment = LocalAssignment::create([
            'id' => (string) Str::uuid(),
            'task_id' => $this->task->id,
            'device_id' => $this->device->id,
            'claim_id' => (string) Str::uuid(),
            'assignment_epoch' => 1,
            'status' => 'active',
            'leased_at' => now(),
            'lease_expires_at' => now()->addMinutes(5),
            'heartbeat_at' => now(),
            'control_version' => 1,
        ]);
    }

    protected function validAuthoringPayload(string $resultId, string $promptText): array
    {
        $promptSha = hash('sha256', $promptText);
        $rawBytes = "=== FIRST ===\nLecture: Kinematics\n=== SECOND ===\nSec 1\n=== THIRD ===\nLec 1: \"Prompt\"\n=== END ===\n";
        $rawSha = hash('sha256', $rawBytes);
        $rawArtId = (string) Str::uuid();
        $rawPath = "local_worker/{$this->job->id}/raw_{$rawArtId}.txt";
        \Illuminate\Support\Facades\Storage::disk('v3_private')->put($rawPath, $rawBytes);

        \App\Models\V3\LocalArtifact::create([
            'id' => $rawArtId,
            'job_id' => $this->job->id,
            'task_id' => $this->task->id,
            'purpose' => 'provider_evidence',
            'digest_sha256' => $rawSha,
            'size_bytes' => strlen($rawBytes),
            'media_type' => 'text/plain',
            'logical_name' => "raw_{$rawArtId}.txt",
            'storage_path' => $rawPath,
            'is_quarantine' => false,
            'is_committed' => true,
        ]);

        return [
            'schema_version' => 1,
            'kind' => 'local_worker_authoring_result',
            'result_id' => $resultId,
            'task_id' => $this->task->id,
            'job_id' => $this->job->id,
            'run_id' => $this->run->id,
            'assignment_id' => $this->assignment->id,
            'assignment_epoch' => 1,
            'input_manifest_sha256' => $this->manifestSha256,
            'protocol_version' => 'v3_notebooklm_authoring_v1',
            'settings_sha256' => str_repeat('b', 64),
            'preparation_sha256' => str_repeat('c', 64),
            'artifact_references' => [],
            'lessons' => [
                [
                    'item_id' => $this->item->id,
                    'lesson_ordinal' => 0,
                    'lesson_title' => 'Kinematics',
                    'raw_response_artifact_id' => $rawArtId,
                    'raw_response_sha256' => $rawSha,
                    'scenes' => [
                        [
                            'scene_ordinal' => 0,
                            'scene_title' => 'Pendulum Dynamics',
                            'prompt_text' => $promptText,
                            'prompt_sha256' => $promptSha,
                            'generation_context' => 'standard_context',
                        ]
                    ]
                ]
            ],
            'archive_inventory' => [
                'archive_artifact_id' => (string) Str::uuid(),
                'archive_sha256' => str_repeat('d', 64),
                'item_count' => 1,
            ],
            'metadata' => [
                'measured_usage' => ['measurement_status' => 'not_recorded']
            ],
        ];
    }

    public function test_fencing_headers_are_enforced(): void
    {
        $payload = $this->validAuthoringPayload((string) Str::uuid(), 'Valid prompt text');

        // Missing fencing headers
        $resp = $this->call(
            'POST',
            "/api/v3/local-worker/v1/tasks/{$this->task->id}/results",
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => "Bearer {$this->plainToken}",
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode($payload)
        );
        $resp->assertStatus(400);

        // Stale epoch header
        $resp = $this->call(
            'POST',
            "/api/v3/local-worker/v1/tasks/{$this->task->id}/results",
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => "Bearer {$this->plainToken}",
                'HTTP_X_ASSIGNMENT_ID' => $this->assignment->id,
                'HTTP_X_ASSIGNMENT_EPOCH' => '99',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode($payload)
        );
        $resp->assertStatus(409);
    }

    public function test_authoring_result_fails_closed_when_manifest_is_missing(): void
    {
        $payload = $this->validAuthoringPayload((string) Str::uuid(), 'Valid prompt text');
        LocalArtifact::where('task_id', $this->task->id)->where('purpose', 'input_manifest')->delete();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $this->assignment->id,
            'X-Assignment-Epoch' => '1',
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/results", $payload);

        $response->assertStatus(422)->assertJsonPath('error.code', 'incomplete_result');
        $this->assertDatabaseMissing('v3_local_results', ['id' => $payload['result_id']]);
        $this->assertSame('leased', $this->task->fresh()->status);
    }

    public function test_incomplete_result_missing_or_quarantine_artifact_rejected(): void
    {
        $artId = (string) Str::uuid();
        LocalArtifact::create([
            'id' => $artId,
            'job_id' => $this->job->id,
            'task_id' => $this->task->id,
            'storage_path' => "staging/{$artId}.bin",
            'size_bytes' => 100,
            'logical_name' => 'evidence.bin',
            'digest_sha256' => hash('sha256', 'data'),
            'media_type' => 'application/octet-stream',
            'purpose' => 'provider_evidence',
            'is_quarantine' => true,
        ]);

        $payload = $this->validAuthoringPayload((string) Str::uuid(), 'Valid prompt text');
        $payload['artifact_references'] = [
            [
                'artifact_id' => $artId,
                'digest_sha256' => hash('sha256', 'data'),
                'media_type' => 'application/octet-stream',
                'purpose' => 'provider_evidence',
            ]
        ];

        $resp = $this->call(
            'POST',
            "/api/v3/local-worker/v1/tasks/{$this->task->id}/results",
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => "Bearer {$this->plainToken}",
                'HTTP_X_ASSIGNMENT_ID' => $this->assignment->id,
                'HTTP_X_ASSIGNMENT_EPOCH' => '1',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode($payload)
        );

        $resp->assertStatus(422);
        $this->assertEquals('incomplete_result', $resp->json('error.code'));
    }

    public function test_authoring_result_commits_atomically_and_enqueues_return_scenes_task(): void
    {
        $promptText = 'Create a 3D simulation / تمثيل البندول with velocity vectors.';
        $promptSha = hash('sha256', $promptText);
        $resultId = (string) Str::uuid();

        $payload = $this->validAuthoringPayload($resultId, $promptText);
        $wireBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $resp = $this->call(
            'POST',
            "/api/v3/local-worker/v1/tasks/{$this->task->id}/results",
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => "Bearer {$this->plainToken}",
                'HTTP_X_ASSIGNMENT_ID' => $this->assignment->id,
                'HTTP_X_ASSIGNMENT_EPOCH' => '1',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $wireBody
        );

        $resp->assertStatus(200);
        $receipt = $resp->json();

        $this->assertEquals(1, $receipt['schema_version']);
        $this->assertEquals(strtolower($resultId), $receipt['result_id']);
        $this->assertEquals($this->task->id, $receipt['task_id']);
        $this->assertEquals('completed', $receipt['status']);
        $this->assertEquals(1, $receipt['accepted_lessons']);
        $this->assertEquals(1, $receipt['accepted_scenes']);
        $this->assertCount(1, $receipt['prompt_version_ids']);

        // Verify task and assignment updated
        $this->task->refresh();
        $this->assertEquals('completed', $this->task->status);
        $this->assignment->refresh();
        $this->assertEquals('released', $this->assignment->status);

        // Verify domain models created
        $sec = TeacherSection::where('case_id', $this->case->id)->first();
        $this->assertNotNull($sec);
        $this->assertEquals('Pendulum Dynamics', $sec->section_identity);

        $pv = ScenePromptVersion::where('section_id', $sec->id)->first();
        $this->assertNotNull($pv);
        $this->assertEquals($promptSha, $pv->body_sha256);
        $this->assertEquals($promptText, Storage::disk('v3_private')->get($pv->body_path));

        $state = PromptSectionState::where('section_id', $sec->id)->first();
        $this->assertNotNull($state);
        $this->assertEquals($pv->id, $state->current_prompt_version_id);
        $this->assertEquals('awaiting_manual', $state->status);

        // Verify return_scenes task was created since output_scope is prompts_and_scenes
        $returnTask = LocalTask::where('job_id', $this->job->id)->where('task_kind', 'return_scenes')->first();
        $this->assertNotNull($returnTask);
        $this->assertEquals('awaiting_pull', $returnTask->status);

        // Test Idempotent replay
        $replayResp = $this->call(
            'POST',
            "/api/v3/local-worker/v1/tasks/{$this->task->id}/results",
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => "Bearer {$this->plainToken}",
                'HTTP_X_ASSIGNMENT_ID' => $this->assignment->id,
                'HTTP_X_ASSIGNMENT_EPOCH' => '1',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $wireBody
        );
        $replayResp->assertStatus(200);

        // Receipt lookup
        $getResp = $this->call(
            'GET',
            "/api/v3/local-worker/v1/results/{$resultId}",
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => "Bearer {$this->plainToken}",
                'HTTP_ACCEPT' => 'application/json',
            ]
        );
        $getResp->assertStatus(200);
        $this->assertEquals(strtolower($resultId), $getResp->json('result_id'));
    }

    public function test_multi_scene_lesson_shares_one_ordered_work_item(): void
    {
        $payload = $this->validAuthoringPayload((string) Str::uuid(), 'First actual prompt');
        $payload['lessons'][0]['scenes'][] = [
            'scene_ordinal' => 1, 'scene_title' => 'Second section',
            'prompt_text' => 'Second actual prompt', 'prompt_sha256' => hash('sha256', 'Second actual prompt'),
            'generation_context' => ['scene_type' => 'Simulation'],
        ];
        $raw = "=== FIRST ===\nLecture: Kinematics\nSection 1:\nSection Identity: First section\nCore Understanding: First idea\n"
            . "Section 2:\nSection Identity: Second section\nCore Understanding: Second idea\n=== SECOND ===\nSecond\n=== THIRD ===\nThird\n=== END ===";
        $artifact = LocalArtifact::findOrFail($payload['lessons'][0]['raw_response_artifact_id']);
        Storage::disk('v3_private')->put($artifact->storage_path, $raw);
        $artifact->update(['digest_sha256' => hash('sha256', $raw), 'size_bytes' => strlen($raw)]);
        $payload['lessons'][0]['raw_response_sha256'] = hash('sha256', $raw);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $this->assignment->id, 'X-Assignment-Epoch' => '1',
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/results", $payload);
        $response->assertOk()->assertJsonPath('accepted_lessons', 1)->assertJsonPath('accepted_scenes', 2);
        $workItems = DB::table('v3_prompt_work_items')->where('case_id', $this->case->id)->get();
        $this->assertCount(1, $workItems);
        $this->assertSame(array_column($response->json('scene_bindings'), 'scene_id'), json_decode($workItems[0]->section_refs, true));
        $this->assertSame([$workItems[0]->id], ScenePromptVersion::where('case_id', $this->case->id)->pluck('work_item_id')->unique()->all());
    }

    public function test_terminal_result_uses_final_envelope_after_quoted_instructions(): void
    {
        $payload = $this->validAuthoringPayload((string) Str::uuid(), 'First actual prompt');
        $payload['lessons'][0]['scenes'][] = [
            'scene_ordinal' => 1, 'scene_title' => 'Second section',
            'prompt_text' => 'Second actual prompt', 'prompt_sha256' => hash('sha256', 'Second actual prompt'),
            'generation_context' => ['scene_type' => 'Simulation'],
        ];
        $raw = "=== FIRST ===\nLecture: Kinematics\nSection 1:\nSection Identity: First section\nCore Understanding: First idea\n"
            . "Section 2:\nSection Identity: Second section\nCore Understanding: Second idea\n=== SECOND ===\nSecond\n=== THIRD ===\nThird\n=== END ===";
        $raw = "=== FIRST ===\nExample only\n=== SECOND ===\nExample\n=== THIRD ===\nInstructions\n=== END ===\n" . str_replace("=== THIRD ===\nThird", "=== THIRD ===\nLec1 (Sec1): \"First actual prompt\"\nLec1 (Sec2): \"Second actual prompt\"", $raw);
        $payload['metadata']['authoring_backend'] = 'run_temporal_pipeline';
        $artifact = LocalArtifact::findOrFail($payload['lessons'][0]['raw_response_artifact_id']);
        Storage::disk('v3_private')->put($artifact->storage_path, $raw);
        $artifact->update(['digest_sha256' => hash('sha256', $raw), 'size_bytes' => strlen($raw)]);
        $payload['lessons'][0]['raw_response_sha256'] = hash('sha256', $raw);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->plainToken,
            'X-Assignment-ID' => $this->assignment->id, 'X-Assignment-Epoch' => '1',
        ])->postJson("/api/v3/local-worker/v1/tasks/{$this->task->id}/results", $payload);
        $response->assertOk()->assertJsonPath('accepted_lessons', 1)->assertJsonPath('accepted_scenes', 2);
        $workItems = DB::table('v3_prompt_work_items')->where('case_id', $this->case->id)->get();
        $this->assertCount(1, $workItems);
        $this->assertSame(array_column($response->json('scene_bindings'), 'scene_id'), json_decode($workItems[0]->section_refs, true));
        $this->assertSame([$workItems[0]->id], ScenePromptVersion::where('case_id', $this->case->id)->pluck('work_item_id')->unique()->all());
    }

    public function test_scene_result_acceptance_creates_candidate_in_awaiting_check(): void
    {
        // First setup lesson map, node, query, section, and prompt version
        $mapId = (string) Str::uuid();
        $binding = DB::table('v3_knowledge_source_bindings')->where('case_id', $this->case->id)->first();
        $activation = DB::table('v3_map_activations')->where('case_id', $this->case->id)->first();

        DB::table('v3_lesson_maps')->insert([
            'id' => $mapId,
            'case_id' => $this->case->id,
            'binding_id' => $binding->id,
            'activation_id' => $activation->id,
            'source_origin' => 'local_worker',
            'state' => 'ready',
            'page_count' => 5,
            'work_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nodeId = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert([
            'id' => $nodeId,
            'map_id' => $mapId,
            'kind' => 'lesson',
            'position_path' => '000',
            'ordinal_in_parent' => 0,
            'title' => 'Gravitation',
            'work_ordinal' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queryId = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert([
            'id' => $queryId,
            'case_id' => $this->case->id,
            'map_id' => $mapId,
            'kind' => 'first',
            'target_work_ordinal' => 0,
            'state' => 'recorded',
            'source_origin' => 'local_worker',
            'send_count' => 1,
            'request_sha256' => hash('sha256', 'first-req'),
            'canonical_prompt_sha256' => hash('sha256', 'first-p'),
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sec = TeacherSection::create([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $mapId,
            'work_node_id' => $nodeId,
            'query_id' => $queryId,
            'ordinal_in_work' => 0,
            'section_identity' => 'Orbital Mechanics',
            'core_understanding' => 'Gravitation',
            'first_prompt_sha256' => hash('sha256', 'p1'),
            'answer_sha256' => hash('sha256', 'a1'),
            'origin' => 'local_worker',
            'projection_status' => 'complete',
            'subject_run_item_id' => $this->item->id,
        ]);

        $workItemId = (string) Str::uuid();
        DB::table('v3_prompt_work_items')->insert([
            'id' => $workItemId,
            'case_id' => $this->case->id,
            'map_id' => $mapId,
            'work_node_id' => $nodeId,
            'work_ordinal' => 0,
            'expected_cursor_version' => 1,
            'section_query_id' => $queryId,
            'state' => 'receipt_committed',
            'section_refs' => json_encode([$sec->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pv = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'work_item_id' => $workItemId,
            'section_id' => $sec->id,
            'version_no' => 1,
            'origin' => 'third',
            'body_path' => 'prompts/test.txt',
            'body_sha256' => hash('sha256', 'prompt-text'),
            'body_bytes' => 11,
            'scene_type' => 'Interactive Visualization',
            'section_fields_sha256' => hash('sha256', $sec->id),
            'canonical_third_sha256' => hash('sha256', 'third'),
            'prompt_text' => 'prompt-text',
            'scene_title' => 'Orbital Mechanics',
            'status' => 'active',
        ]);
        PromptSectionState::create([
            'section_id' => $sec->id,
            'case_id' => $this->case->id,
            'current_prompt_version_id' => $pv->id,
            'status' => 'awaiting_manual',
            'revision_count' => 0,
        ]);

        // Create return_scenes task & assignment
        $sceneTask = LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $this->job->id,
            'task_kind' => 'return_scenes',
            'status' => 'leased',
            'input_manifest_id' => (string) Str::uuid(),
            'input_manifest_sha256' => $this->manifestSha256,
            'control_version' => 1,
        ]);

        $sceneAssignment = LocalAssignment::create([
            'id' => (string) Str::uuid(),
            'task_id' => $sceneTask->id,
            'device_id' => $this->device->id,
            'claim_id' => (string) Str::uuid(),
            'assignment_epoch' => 1,
            'status' => 'active',
            'leased_at' => now(),
            'lease_expires_at' => now()->addMinutes(5),
            'heartbeat_at' => now(),
            'control_version' => 1,
        ]);

        // Upload valid static three file package artifact
        $packageJson = json_encode([
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => '<!DOCTYPE html><html><body><h1>Orbital</h1><canvas id="c"></canvas></body></html>'],
                ['path' => 'styles.css', 'content' => 'body { margin: 0; background: #000; }'],
                ['path' => 'script.js', 'content' => 'console.log("initialized orbital scene");'],
            ]
        ], JSON_UNESCAPED_SLASHES);

        $pkgSha = hash('sha256', $packageJson);
        $pkgArtId = (string) Str::uuid();
        $storagePath = "artifacts/{$pkgArtId}.json";
        Storage::disk('v3_private')->put($storagePath, $packageJson);

        LocalArtifact::create([
            'id' => $pkgArtId,
            'job_id' => $this->job->id,
            'task_id' => $sceneTask->id,
            'storage_path' => $storagePath,
            'size_bytes' => strlen($packageJson),
            'logical_name' => 'package.json',
            'digest_sha256' => $pkgSha,
            'media_type' => 'application/json',
            'purpose' => 'scene_package',
            'is_quarantine' => false,
        ]);

        $resultId = (string) Str::uuid();
        $subId = (string) Str::uuid();

        $payload = [
            'schema_version' => 1,
            'kind' => 'local_worker_scene_result',
            'result_id' => $resultId,
            'task_id' => $sceneTask->id,
            'job_id' => $this->job->id,
            'run_id' => $this->run->id,
            'assignment_id' => $sceneAssignment->id,
            'assignment_epoch' => 1,
            'control_version' => 1,
            'input_manifest_sha256' => $this->manifestSha256,
            'submissions' => [
                [
                    'submission_id' => $subId,
                    'scene_id' => $sec->id,
                    'prompt_version_id' => $pv->id,
                    'prompt_sha256' => $pv->body_sha256,
                    'package_artifact_id' => $pkgArtId,
                    'package_sha256' => $pkgSha,
                    'provenance' => [
                        'mode' => 'manual_web',
                        'operator_id' => $this->teacher->id,
                        'device_id' => $this->device->id,
                        'provider' => 'manual_studio',
                        'model' => 'gemini-2.5-pro',
                        'effort' => 'high',
                        'measurement_status' => 'not_recorded',
                    ]
                ]
            ]
        ];

        // A scene result must not define its own required-scene inventory when
        // the sealed return manifest is absent or has been substituted.
        $missingManifestPayload = $payload;
        $missingManifestPayload['result_id'] = (string) Str::uuid();
        $missingManifestResponse = $this->postJson(
            "/api/v3/local-worker/v1/tasks/{$sceneTask->id}/results",
            $missingManifestPayload,
            [
                'Authorization' => "Bearer {$this->plainToken}",
                'X-Assignment-ID' => $sceneAssignment->id,
                'X-Assignment-Epoch' => '1',
            ]
        );
        $missingManifestResponse->assertStatus(422)->assertJsonPath('error.code', 'incomplete_result');
        $this->assertSame(0, LocalSceneSubmission::where('task_id', $sceneTask->id)->count());

        $runtimeSettingsId = (string) Str::uuid();
        $returnManifest = [
            'schema_version' => 1,
            'kind' => 'local_worker_input_manifest',
            'task_id' => $sceneTask->id,
            'job_id' => $this->job->id,
            'run_id' => $this->run->id,
            'task_kind' => 'return_scenes',
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_and_scenes',
            'protocol_id' => 'manual_scene_return_v1',
            'protocol_version' => 'manual_scene_return_v1',
            'settings_snapshot_id' => (string) Str::uuid(),
            'settings_sha256' => str_repeat('a', 64),
            'runtime_settings_artifact_id' => $runtimeSettingsId,
            'runtime_settings_sha256' => str_repeat('b', 64),
            'preparation' => ['inventory_sha256' => str_repeat('c', 64)],
            'items' => [[
                'item_id' => $this->item->id,
                'original_artifact_id' => $this->item->document_id,
                'original_sha256' => LocalArtifact::whereKey($this->item->document_id)->value('digest_sha256'),
                'original_byte_count' => 16,
                'original_page_count' => 5,
                'upload_ordinal' => 1,
                'source_ordinal' => 1,
                'part_ordinal' => 1,
                'original_page_start' => 1,
                'original_page_end' => 5,
                'prepared_artifact_id' => LocalArtifact::where('task_id', $this->task->id)->where('purpose', 'prepared_pdf')->value('id'),
                'prepared_sha256' => LocalArtifact::where('task_id', $this->task->id)->where('purpose', 'prepared_pdf')->value('digest_sha256'),
                'prepared_byte_count' => 22,
                'prepared_page_count' => 5,
            ]],
            'instruction_artifacts' => [],
            'artifacts' => [],
            'required_outputs' => ['profile' => 'static_three_file_v1', 'format' => 'static_three_file_package'],
            'resource_budget' => ['max_provider_effects' => 100, 'max_output_bytes' => 536870912, 'timeout_seconds' => 7200],
            'capabilities' => ['scene_return_v1', 'static_three_file_v1'],
            'required_scenes' => [[
                'scene_id' => $sec->id,
                'prompt_version_id' => $pv->id,
                'prompt_sha256' => $pv->body_sha256,
                'scene_title' => $pv->scene_title,
                'scene_ordinal' => 1,
                'template_version' => 'v1',
                'scene_type' => 'Interactive Visualization',
            ]],
        ];
        $returnManifestBytes = json_encode($returnManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $returnManifestPath = "artifacts/{$sceneTask->input_manifest_id}.json";
        Storage::disk('v3_private')->put($returnManifestPath, $returnManifestBytes);
        LocalArtifact::create([
            'id' => $sceneTask->input_manifest_id,
            'job_id' => $this->job->id,
            'task_id' => $sceneTask->id,
            'storage_path' => $returnManifestPath,
            'size_bytes' => strlen($returnManifestBytes),
            'logical_name' => 'scene_return_manifest.json',
            'digest_sha256' => hash('sha256', $returnManifestBytes),
            'media_type' => 'application/json',
            'purpose' => 'input_manifest',
            'is_quarantine' => false,
            'is_committed' => true,
        ]);
        $sceneTask->input_manifest_sha256 = hash('sha256', $returnManifestBytes);
        $sceneTask->save();
        $payload['input_manifest_sha256'] = $sceneTask->input_manifest_sha256;

        $wrongRunPayload = $payload;
        $wrongRunPayload['result_id'] = (string) Str::uuid();
        $wrongRunPayload['run_id'] = (string) Str::uuid();
        $wrongRunResponse = $this->postJson(
            "/api/v3/local-worker/v1/tasks/{$sceneTask->id}/results",
            $wrongRunPayload,
            [
                'Authorization' => "Bearer {$this->plainToken}",
                'X-Assignment-ID' => $sceneAssignment->id,
                'X-Assignment-Epoch' => '1',
            ]
        );
        $wrongRunResponse->assertStatus(409)->assertJsonPath('error.code', 'mismatched_identity');

        $duplicateScenePayload = $payload;
        $duplicateScenePayload['result_id'] = (string) Str::uuid();
        $duplicateScenePayload['submissions'][] = [
            ...$duplicateScenePayload['submissions'][0],
            'submission_id' => (string) Str::uuid(),
        ];
        $duplicateSceneResponse = $this->postJson(
            "/api/v3/local-worker/v1/tasks/{$sceneTask->id}/results",
            $duplicateScenePayload,
            [
                'Authorization' => "Bearer {$this->plainToken}",
                'X-Assignment-ID' => $sceneAssignment->id,
                'X-Assignment-Epoch' => '1',
            ]
        );
        $duplicateSceneResponse->assertStatus(422)->assertJsonPath('error.code', 'duplicate_scene_submission');
        $this->assertSame(0, LocalSceneSubmission::where('task_id', $sceneTask->id)->count());

        $foreignOperatorPayload = $payload;
        $foreignOperatorPayload['result_id'] = (string) Str::uuid();
        $foreignOperatorPayload['submissions'][0]['provenance']['operator_id'] = User::factory()->create(['role' => 'teacher'])->id;
        $foreignOperatorResponse = $this->call(
            'POST',
            "/api/v3/local-worker/v1/tasks/{$sceneTask->id}/results",
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => "Bearer {$this->plainToken}",
                'HTTP_X_ASSIGNMENT_ID' => $sceneAssignment->id,
                'HTTP_X_ASSIGNMENT_EPOCH' => '1',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode($foreignOperatorPayload)
        );
        $foreignOperatorResponse->assertStatus(403);

        $resp = $this->call(
            'POST',
            "/api/v3/local-worker/v1/tasks/{$sceneTask->id}/results",
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => "Bearer {$this->plainToken}",
                'HTTP_X_ASSIGNMENT_ID' => $sceneAssignment->id,
                'HTTP_X_ASSIGNMENT_EPOCH' => '1',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode($payload)
        );

        $resp->assertStatus(200);
        $receipt = $resp->json();
        $this->assertEquals(1, $receipt['schema_version']);
        $this->assertEquals('completed', $receipt['status']);
        $this->assertCount(1, $receipt['accepted_candidates']);

        $candidateId = $receipt['accepted_candidates'][0];
        $candidate = SceneCandidate::find($candidateId);
        $this->assertNotNull($candidate);
        $this->assertEquals('manual_import', $candidate->origin);

        $candidateState = DB::table('v3_scene_candidate_states')->where('candidate_id', $candidateId)->first();
        $this->assertNotNull($candidateState);
        $this->assertEquals('awaiting_check', $candidateState->status);

        $submission = LocalSceneSubmission::find($subId);
        $this->assertNotNull($submission);
        $this->assertEquals($candidateId, $submission->candidate_id);

        $sceneTask->refresh();
        $this->assertEquals('completed', $sceneTask->status);
        $this->job->refresh();
        $this->assertEquals('completed', $this->job->status);

        $sentPayload = null;
        Http::fake(function ($request) use (&$sentPayload) {
            $sentPayload = $request->data();
            return Http::response(['outcome' => 'accepted', 'command_id' => $sentPayload['command_id']], 202);
        });
        $this->assertSame(1, app(\App\Services\V3\WebPipeline\DispatchRunOutbox::class)->dispatchPending());
        $this->assertSame('check_candidate', \App\Models\V3\PipelineCommand::where('id', $sentPayload['command_id'])->value('action'));
        $this->assertSame($this->run->id, $sentPayload['run_id']);
        $this->assertSame($this->case->id, $sentPayload['case_id']);
        $this->assertSame($candidateId, $sentPayload['candidate_ref']);
        $this->assertDatabaseHas('v3_pipeline_outbox', [
            'command_id' => $sentPayload['command_id'], 'destination' => 'qualification_runner', 'state' => 'acknowledged',
        ]);
        $this->assertSame('awaiting_check', DB::table('v3_scene_candidate_states')->where('candidate_id', $candidateId)->value('status'));
        $this->assertDatabaseHas('v3_pipeline_commands', [
            'id' => $sentPayload['command_id'], 'state' => 'applied', 'result_ref' => 'scene-qualification-' . $sentPayload['command_id'],
        ]);
    }
}
