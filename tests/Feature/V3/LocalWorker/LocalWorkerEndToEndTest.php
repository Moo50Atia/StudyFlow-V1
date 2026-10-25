<?php

namespace Tests\Feature\V3\LocalWorker;

use App\Models\Lecture;
use App\Models\Subject;
use App\Models\User;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalTask;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\V3AccountAccess;
use App\Services\V3\LocalWorker\DeviceManager;
use App\Services\V3\LocalWorker\LocalWorkerAdmissionCoordinator;
use App\Services\V3\NotebookLM\DirectSourceCoordinator;
use App\Services\V3\WebPipeline\SubmitSubjectRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class LocalWorkerEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $teacher;
    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.v3_teacher_demo_waiver_enabled', true);
        Storage::fake('v3_private');

        $this->owner = User::factory()->create([
            'email' => 'admin@test.com',
            'role' => 'admin',
        ]);
        V3AccountAccess::create([
            'user_id' => $this->owner->id,
            'is_active' => true,
            'provisioned_at' => now(),
        ]);
        $this->teacher = WebPipelineFixtures::createTeacherA();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher, 'Physics 101');

        $lecture = Lecture::create([
            'subject_id' => $this->subject->id,
            'title' => 'Lecture 1',
        ]);
        WebPipelineFixtures::grantTeacherPermission($this->teacher, $this->subject, $lecture);

        $frameworkId = (string) Str::uuid();
        $frameworkBody = 'Framework body content';
        NotebookFrameworkVersion::create([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $frameworkId . '.md',
            'body_sha256' => hash('sha256', $frameworkBody),
            'body_bytes' => strlen($frameworkBody),
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'format'),
            'approved_by_user_id' => $this->owner->id,
            'approved_at' => now(),
        ]);
        Storage::disk('v3_private')->put('frameworks/' . $frameworkId . '.md', $frameworkBody);
        DB::table('v3_notebook_framework_pointer')->updateOrInsert(
            ['id' => 1],
            ['version_id' => $frameworkId, 'updated_at' => now()]
        );

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(\App\Services\V3\Settings\PublishSettings::class);
        $publisher->publish($payload, 0, $this->owner->id);

        config()->set('services.v3.bridge_hmac_key', str_repeat('a', 64));
        \Illuminate\Support\Facades\Http::fake(['*/pdf-preparations' => function ($request) {
            $process = new \Symfony\Component\Process\Process([base_path('.venv/bin/python'), '-c',
                'import json,sys; from pathlib import Path; from v3_engine.web_pipeline.pdf_preparation import prepare_web_pdfs; print(json.dumps(prepare_web_pdfs(Path(sys.argv[1]),json.loads(sys.argv[2]))))',
                \Illuminate\Support\Facades\Storage::disk('v3_private')->path(''), $request->body()], base_path());
            $process->setTimeout(60);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            return \Illuminate\Support\Facades\Http::response(['data' => $result]);
        }]);
    }

    public function test_complete_end_to_end_local_worker_lifecycle(): void
    {
        // 1. Owner creates device invitation
        $deviceManager = app(DeviceManager::class);
        $invitation = $deviceManager->createPairingInvitation($this->owner, 'Lab-Worker-E2E');
        $pairingCode = $invitation['pairing_secret'];

        // 2. Worker redeems pairing code
        $redeemResp = $this->postJson('/api/v3/local-worker/v1/enrollments/redeem', [
            'schema_version' => 1,
            'pairing_secret' => $pairingCode,
            'device_name' => 'Lab-Worker-E2E',
            'device_uuid' => (string) Str::uuid(),
            'os_family' => 'linux',
        ]);
        $redeemResp->assertOk();
        $deviceToken = $redeemResp->json('device_token');
        $deviceId = $redeemResp->json('device_id');
        $this->assertNotEmpty($deviceToken);

        $authHeaders = [
            'Authorization' => "Bearer {$deviceToken}",
            'User-Agent' => 'local-worker.v1',
            'Accept' => 'application/json',
        ];

        // 3. Worker checks device endpoint
        $deviceResp = $this->getJson('/api/v3/local-worker/v1/device', $authHeaders);
        $deviceResp->assertOk();
        $this->assertEquals($deviceId, $deviceResp->json('device_id'));

        // 4. Teacher submits curriculum PDF run with execution_mode = local_worker_v1
        $pdfContent = PdfFixtures::minimalValidPdf(2, 'Physics Mechanics');
        $tmp = tempnam(sys_get_temp_dir(), 'pdf_');
        file_put_contents($tmp, $pdfContent);
        $file = new UploadedFile($tmp, 'mechanics.pdf', 'application/pdf', null, true);

        $submitService = app(SubmitSubjectRun::class);
        $runResult = $submitService->submit($this->teacher, $this->subject->id, [
            'submission_key' => (string) Str::uuid(),
            'files' => [$file],
            'titles' => ['Mechanics Lecture 1'],
            'mode' => 'export_manual',
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_and_scenes',
            'qualification_mode' => 'owner_waived_demo',
            'waiver_acknowledged' => true,
        ]);

        $this->assertArrayHasKey('run_id', $runResult);
        $this->assertArrayHasKey('job_id', $runResult);
        $runId = $runResult['run_id'];
        $jobId = $runResult['job_id'];

        $run = SubjectRun::findOrFail($runId);
        $this->assertEquals('local_worker_v1', $run->execution_mode);
        $job = LocalJob::findOrFail($jobId);
        $this->assertEquals('prompts_and_scenes', $job->output_scope);

        // 5. Worker claims authoring task
        $claimId = (string) Str::uuid();
        $claimResp = $this->postJson('/api/v3/local-worker/v1/claims', [
            'schema_version' => 1,
            'claim_id' => $claimId,
            'protocol_version' => 1,
            'capabilities' => ['notebooklm_authoring_v1', 'scene_return_v1'],
            'available_slots' => 1,
        ], $authHeaders);

        $claimResp->assertOk();
        $task = $claimResp->json();
        $this->assertEquals('author_prompts', $task['task_kind']);
        $taskId = $task['task_id'];
        $assignmentId = $task['assignment_id'];
        $epoch = $task['assignment_epoch'];
        $manifestSha = $task['input_manifest_sha256'];

        $fencedHeaders = array_merge($authHeaders, [
            'X-Assignment-ID' => $assignmentId,
            'X-Assignment-Epoch' => (string) $epoch,
        ]);

        // 6. Worker streams input manifest and downloads artifact
        $manifestResp = $this->get("/api/v3/local-worker/v1/tasks/{$taskId}/input-manifest", $fencedHeaders);
        $manifestResp->assertOk();
        $this->assertEquals($manifestSha, hash('sha256', $manifestResp->getContent()));

        $manifestData = json_decode($manifestResp->getContent(), true);
        $artId = $manifestData['artifacts'][0]['artifact_id'];

        $inputResp = $this->get("/api/v3/local-worker/v1/tasks/{$taskId}/inputs/{$artId}", $fencedHeaders);
        $inputResp->assertOk();
        $this->assertNotEmpty($inputResp->streamedContent());

        $firstItem = $manifestData['items'][0];
        $itemId = $firstItem['item_id'];

        // 7. Worker reserves and dispatches effect
        $effKey = "{$job->id}:divide:{$itemId}";
        $reserveResp = $this->postJson("/api/v3/local-worker/v1/tasks/{$taskId}/effects/reserve", [
            'schema_version' => 1,
            'effect_id' => (string) Str::uuid(),
            'logical_effect_key' => $effKey,
            'operation_kind' => 'divide_map',
            'request_digest' => hash('sha256', 'divide-req'),
        ], $fencedHeaders);
        $reserveResp->assertStatus(201);
        $effectId = $reserveResp->json('effect_id');

        $dispatchResp = $this->postJson("/api/v3/local-worker/v1/tasks/{$taskId}/effects/{$effectId}/dispatch", [
            'schema_version' => 1,
            'worker_process_id' => '1234',
        ], $fencedHeaders);
        $dispatchResp->assertOk();
        $this->assertNotEmpty($dispatchResp->json('permit_id'));

        // 8. Worker submits effect outcome
        $outcomeResp = $this->postJson("/api/v3/local-worker/v1/tasks/{$taskId}/effects/{$effectId}/outcome", [
            'schema_version' => 1,
            'outcome_ref' => 'ref-123',
            'status' => 'succeeded',
            'evidence_sha256' => hash('sha256', 'raw-response-bytes'),
        ], $fencedHeaders);
        $outcomeResp->assertOk();

        // 9. Worker uploads raw response artifact
        $rawBytes = "=== FIRST ===\nLecture: W1 Kinematics\n=== SECOND ===\nSec 1\n=== THIRD ===\nLec 1 (Sec 1): \"Prompt\"\n=== END ===\n✅ Lesson [1] Completed.";
        $rawSha = hash('sha256', $rawBytes);
        $rawArtId = (string) Str::uuid();
        $uploadId = (string) Str::uuid();

        $this->postJson("/api/v3/local-worker/v1/tasks/{$taskId}/uploads", [
            'schema_version' => 1,
            'upload_id' => $uploadId,
            'artifact_id' => $rawArtId,
            'purpose' => 'authoring_raw_response',
            'media_type' => 'text/plain',
            'expected_bytes' => strlen($rawBytes),
            'expected_sha256' => $rawSha,
        ], $fencedHeaders)->assertStatus(201);

        $this->call('PUT', "/api/v3/local-worker/v1/tasks/{$taskId}/uploads/{$uploadId}", [], [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$deviceToken}",
            'HTTP_X_ASSIGNMENT_ID' => $assignmentId,
            'HTTP_X_ASSIGNMENT_EPOCH' => (string) $epoch,
            'CONTENT_TYPE' => 'text/plain',
        ], $rawBytes)->assertOk();

        // 10. Worker submits Authoring Result
        $promptText = "Scene Type: 2D Simulation\nKinematics velocity simulation.";
        $promptSha = hash('sha256', $promptText);
        $resultId = (string) Str::uuid();

        $resultPayload = [
            'schema_version' => 1,
            'kind' => 'local_worker_authoring_result',
            'result_id' => $resultId,
            'task_id' => $taskId,
            'job_id' => $job->id,
            'run_id' => $runId,
            'assignment_id' => $assignmentId,
            'assignment_epoch' => $epoch,
            'input_manifest_sha256' => $manifestSha,
            'protocol_version' => $manifestData['protocol_version'],
            'settings_sha256' => $manifestData['settings_sha256'],
            'preparation_sha256' => $manifestData['preparation']['inventory_sha256'],
            'artifact_references' => [
                ['artifact_id' => $rawArtId, 'digest_sha256' => $rawSha],
            ],
            'lessons' => [
                [
                    'item_id' => $itemId,
                    'lesson_ordinal' => 0,
                    'lesson_title' => 'Kinematics Introduction',
                    'raw_response_artifact_id' => $rawArtId,
                    'raw_response_sha256' => $rawSha,
                    'scenes' => [
                        [
                            'scene_ordinal' => 0,
                            'scene_title' => 'Velocity Vector Simulation',
                            'prompt_text' => $promptText,
                            'prompt_sha256' => $promptSha,
                            'generation_context' => 'standard_context',
                        ],
                    ],
                ],
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

        $commitResp = $this->postJson("/api/v3/local-worker/v1/tasks/{$taskId}/results", $resultPayload, $fencedHeaders);
        $commitResp->assertOk();
        $this->assertEquals($resultId, $commitResp->json('result_id'));
        $this->assertEquals(1, $commitResp->json('accepted_lessons'));

        // 11. Manual generation has no lease/claim until an explicit Dashboard Pull.
        $returnTask = LocalTask::where('job_id', $job->id)->where('task_kind', 'return_scenes')->first();
        $this->assertNotNull($returnTask);
        $this->assertEquals('awaiting_pull', $returnTask->status);
        $this->assertSame($promptText, $commitResp->json('scene_bindings.0.prompt_text'));
        $this->assertSame($itemId, $commitResp->json('scene_bindings.0.item_id'));
        $this->postJson('/api/v3/local-worker/v1/claims', [
            'schema_version' => 1, 'claim_id' => (string) Str::uuid(), 'protocol_version' => 1,
            'capabilities' => ['scene_return_v1'], 'available_slots' => 1,
        ], $authHeaders)->assertNoContent(204);

        $pullId = (string) Str::uuid();
        $this->actingAs(WebPipelineFixtures::createTeacherB())
            ->postJson("/v3/teacher/subject-runs/{$runId}/pull-scenes", ['request_id' => $pullId])
            ->assertNotFound();
        $this->actingAs($this->teacher)->postJson("/v3/teacher/subject-runs/{$runId}/pull-scenes", ['request_id' => $pullId])
            ->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->actingAs($this->teacher)->postJson("/v3/teacher/subject-runs/{$runId}/pull-scenes", ['request_id' => $pullId])
            ->assertStatus(202);
        $this->assertSame('queued', $returnTask->fresh()->status);
        $this->actingAs($this->owner)->postJson("/v3/admin/subject-runs/{$runId}/pull-scenes", ['request_id' => (string) Str::uuid()])
            ->assertStatus(202);
        $otherPairing = $deviceManager->createPairingInvitation($this->owner, 'Different laptop');
        $otherDevice = $deviceManager->redeemPairing($otherPairing['pairing_secret']);
        $this->postJson('/api/v3/local-worker/v1/claims', [
            'schema_version' => 1, 'claim_id' => (string) Str::uuid(), 'protocol_version' => 1,
            'capabilities' => ['scene_return_v1'], 'available_slots' => 1,
        ], array_merge($authHeaders, ['Authorization' => 'Bearer ' . $otherDevice['device_token']]))->assertNoContent(204);

        // 12. Worker claims return_scenes task
        $sceneClaimId = (string) Str::uuid();
        $sceneClaimResp = $this->postJson('/api/v3/local-worker/v1/claims', [
            'schema_version' => 1,
            'claim_id' => $sceneClaimId,
            'protocol_version' => 1,
            'capabilities' => ['scene_return_v1'],
            'available_slots' => 1,
        ], $authHeaders);

        $sceneClaimResp->assertOk();
        // A premature Pull returns to manual generation without blocking the device.
        $early = $sceneClaimResp->json();
        $this->postJson("/api/v3/local-worker/v1/tasks/{$early['task_id']}/release", [
            'schema_version' => 1, 'reason' => 'manual_scenes_missing',
        ], array_merge($authHeaders, [
            'X-Assignment-ID' => $early['assignment_id'], 'X-Assignment-Epoch' => (string) $early['assignment_epoch'],
        ]))->assertOk();
        $this->assertSame('awaiting_pull', $returnTask->fresh()->status);
        $this->assertSame('awaiting_generation', $job->fresh()->status);
        $this->actingAs($this->owner)->postJson("/v3/admin/subject-runs/{$runId}/pull-scenes", ['request_id' => (string) Str::uuid()])
            ->assertStatus(202);
        $sceneClaimResp = $this->postJson('/api/v3/local-worker/v1/claims', [
            'schema_version' => 1, 'claim_id' => (string) Str::uuid(), 'protocol_version' => 1,
            'capabilities' => ['scene_return_v1'], 'available_slots' => 1,
        ], $authHeaders)->assertOk();
        $sceneTask = $sceneClaimResp->json();
        $this->assertEquals('return_scenes', $sceneTask['task_kind']);
        $sceneTaskId = $sceneTask['task_id'];
        $sceneAssignmentId = $sceneTask['assignment_id'];
        $sceneEpoch = $sceneTask['assignment_epoch'];

        $sceneFencedHeaders = array_merge($authHeaders, [
            'X-Assignment-ID' => $sceneAssignmentId,
            'X-Assignment-Epoch' => (string) $sceneEpoch,
        ]);

        // 13. A self-contained HTML scene can be returned without invented sidecar files.
        $pkg = [
            'schema_version' => 1,
            'profile' => 'manual_scene_files_v1',
            'files' => [
                ['path' => 'index.html', 'content' => '<!DOCTYPE html><html><body>Scene</body></html>'],
            ],
        ];
        $pkgBytes = json_encode($pkg);
        $pkgSha = hash('sha256', $pkgBytes);
        $pkgArtId = (string) Str::uuid();
        $pkgUploadId = (string) Str::uuid();

        $this->postJson("/api/v3/local-worker/v1/tasks/{$sceneTaskId}/uploads", [
            'schema_version' => 1,
            'upload_id' => $pkgUploadId,
            'artifact_id' => $pkgArtId,
            'purpose' => 'scene_package',
            'media_type' => 'application/json',
            'expected_bytes' => strlen($pkgBytes),
            'expected_sha256' => $pkgSha,
        ], $sceneFencedHeaders)->assertStatus(201);

        $this->call('PUT', "/api/v3/local-worker/v1/tasks/{$sceneTaskId}/uploads/{$pkgUploadId}", [], [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$deviceToken}",
            'HTTP_X_ASSIGNMENT_ID' => $sceneAssignmentId,
            'HTTP_X_ASSIGNMENT_EPOCH' => (string) $sceneEpoch,
            'CONTENT_TYPE' => 'application/json',
        ], $pkgBytes)->assertOk();

        // 14. Worker submits scene return result
        $itemModel = SubjectRunItem::findOrFail($itemId);
        $caseId = $itemModel->case_id;

        $createdPrompt = ScenePromptVersion::where('case_id', $caseId)->first();
        $this->assertNotNull($createdPrompt);

        $sceneResultId = (string) Str::uuid();
        $sceneResultPayload = [
            'schema_version' => 1,
            'kind' => 'local_worker_scene_result',
            'result_id' => $sceneResultId,
            'task_id' => $sceneTaskId,
            'job_id' => $job->id,
            'run_id' => $runId,
            'assignment_id' => $sceneAssignmentId,
            'assignment_epoch' => $sceneEpoch,
            'control_version' => 1,
            'input_manifest_sha256' => $sceneTask['input_manifest_sha256'],
            'submissions' => [
                [
                    'submission_id' => (string) Str::uuid(),
                    'scene_id' => $createdPrompt->section_id,
                    'prompt_version_id' => $createdPrompt->id,
                    'prompt_sha256' => $createdPrompt->body_sha256,
                    'package_artifact_id' => $pkgArtId,
                    'package_sha256' => $pkgSha,
                    'provenance' => [
                        'mode' => 'manual_web',
                        'operator_id' => $this->owner->id,
                        'device_id' => $deviceId,
                        'provider' => 'gemini',
                        'model' => 'gemini-2.5-pro',
                        'effort' => 'high',
                        'measurement_status' => 'not_recorded',
                    ],
                ],
            ],
        ];

        $sceneCommitResp = $this->postJson("/api/v3/local-worker/v1/tasks/{$sceneTaskId}/results", $sceneResultPayload, $sceneFencedHeaders);
        $sceneCommitResp->assertOk();
        $this->assertCount(1, $sceneCommitResp->json('accepted_candidates'));

        // 15. Verify candidate created in awaiting_check (never auto-approved or student published)
        $candidate = DB::table('v3_scene_candidates')->where('section_id', $createdPrompt->section_id)->first();
        $this->assertNotNull($candidate);
        $this->assertSame('manual_scene_files_v1', $candidate->package_profile);
        $this->assertSame(1, (int) $candidate->file_count);
        $storedManifest = json_decode(Storage::disk('v3_private')->get($candidate->manifest_path), true);
        $this->assertSame('manual_scene_files_v1', $storedManifest['profile']);

        $state = DB::table('v3_scene_candidate_states')->where('candidate_id', $candidate->id)->first();
        $this->assertEquals('awaiting_check', $state->status);
    }
}
