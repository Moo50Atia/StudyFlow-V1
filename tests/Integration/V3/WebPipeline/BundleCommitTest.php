<?php

namespace Tests\Integration\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Services\V3\WebPipeline\AcceptWebLesson;
use App\Services\V3\WebPipeline\ClaimOperation;
use App\Services\V3\WebPipeline\CommitOperation;
use App\Services\V3\Workflow\BridgeRequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class BundleCommitTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected User $admin;
    protected Subject $subject;
    protected SubjectRun $run;
    protected SubjectRunItem $item;
    protected ProcessingCase $case;
    protected string $secretKey = 'test-worker-signature-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.v3.callback_hmac_key' => $this->secretKey]);
        config(['services.v3.worker_hmac_key' => $this->secretKey]);
        config(['services.v3.bridge_hmac_key' => $this->secretKey]);

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacher = WebPipelineFixtures::createTeacherA();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher);

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => '{}',
            'checksum' => hash('sha256', '{}'),
            'published_by_user_id' => $this->admin->id,
            'published_at' => now(),
        ]);

        $frameworkId = (string) Str::uuid();
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $frameworkId . '.md',
            'body_sha256' => hash('sha256', 'mock_framework'),
            'body_bytes' => 14,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'fmt'),
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        $this->run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'origin' => 'web',
            'protocol' => 'notebooklm_per_pdf_web_v1',
            'settings_version_id' => $settingsId,
            'framework_version_id' => $frameworkId,
            'mode' => 'export_manual',
            'qualification_mode' => 'owner_waived_demo',
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'req1'),
            'captured_settings_json' => '{}',
            'capture_sha256' => hash('sha256', '{}'),
            'state' => 'running',
            'state_version' => 1,
            'total_pdfs' => 1,
            'last_event_sequence' => 0,
        ]);

        $this->item = SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacher->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Mechanics 1',
            'original_filename' => 'mechanics_1.pdf',
            'input_sha256' => hash('sha256', 'pdf1'),
            'input_bytes' => 1024,
            'state' => 'accepted',
            'state_version' => 1,
        ]);

        $doc = \App\Models\V3\Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'sha256' => hash('sha256', 'pdf1'),
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/' . Str::uuid() . '.pdf',
            'accepted_at' => now(),
        ]);
        $this->item->update(['document_id' => $doc->id]);

        $this->case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'document_id' => $doc->id,
            'settings_version_id' => $settingsId,
            'idempotency_key' => 'item:' . $this->item->id,
            'action' => 'web_subject',
            'state' => 'running',
            'state_version' => 1,
            'pipeline_version' => 'notebooklm_per_pdf_web_v1',
            'notebook_framework_version_id' => $frameworkId,
            'subject_run_item_id' => $this->item->id,
        ]);
        $this->item->update(['case_id' => $this->case->id]);
    }

    protected function signedHeaders(string $method, string $path, array $body = []): array
    {
        return BridgeRequestSigner::signCallbackRequest($method, $path, $body, $this->secretKey);
    }

    public function test_claim_operation_returns_lease_and_context_ref(): void
    {
        $opId = 'op_' . (string) Str::uuid();
        $payload = [
            'run_id' => $this->run->id,
            'operation_id' => $opId,
            'kind' => 'lesson_turn',
            'item_id' => $this->item->id,
            'expected_version' => 1,
            'request_sha256' => hash('sha256', 'turn1'),
        ];

        $path = '/api/v3/internal/web-pipeline/claims';
        $headers = $this->signedHeaders('POST', $path, $payload);

        $resp = $this->postJson($path, $payload, $headers);
        $resp->assertStatus(201);
        $data = $resp->json();
        $this->assertNotEmpty($data['lease_token']);
        $this->assertNotEmpty($data['context_ref']);
    }

    public function test_claim_operation_idempotent_duplicate_returns_200(): void
    {
        $opId = 'op_' . (string) Str::uuid();
        $payload = [
            'run_id' => $this->run->id,
            'operation_id' => $opId,
            'kind' => 'lesson_turn',
            'item_id' => $this->item->id,
            'expected_version' => 1,
            'request_sha256' => hash('sha256', 'turn1'),
        ];

        $path = '/api/v3/internal/web-pipeline/claims';
        $headers = $this->signedHeaders('POST', $path, $payload);

        // First call
        $resp1 = $this->postJson($path, $payload, $headers);
        $resp1->assertStatus(201);
        $lease1 = $resp1->json('lease_token');

        // Second call with same digest
        $resp2 = $this->postJson($path, $payload, $headers);
        $resp2->assertStatus(200);
        $this->assertSame($lease1, $resp2->json('lease_token'));
    }

    public function test_claim_operation_conflicting_digest_returns_409(): void
    {
        $opId = 'op_' . (string) Str::uuid();
        $payload1 = [
            'run_id' => $this->run->id,
            'operation_id' => $opId,
            'kind' => 'lesson_turn',
            'item_id' => $this->item->id,
            'expected_version' => 1,
            'request_sha256' => hash('sha256', 'turn1'),
        ];

        $path = '/api/v3/internal/web-pipeline/claims';
        $headers1 = $this->signedHeaders('POST', $path, $payload1);
        $this->postJson($path, $payload1, $headers1)->assertStatus(201);

        // Different request hash under same operation_id
        $payload2 = $payload1;
        $payload2['request_sha256'] = hash('sha256', 'turn_differing');
        $headers2 = $this->signedHeaders('POST', $path, $payload2);

        $resp2 = $this->postJson($path, $payload2, $headers2);
        $resp2->assertStatus(409);
        $this->assertSame('operation_conflict', $resp2->json('error.code'));
    }

    public function test_commit_operation_verifies_lease_token_and_advances_version(): void
    {
        $claimService = app(ClaimOperation::class);
        $opId = 'op_' . (string) Str::uuid();
        $claim = $claimService->claim([
            'run_id' => $this->run->id,
            'operation_id' => $opId,
            'kind' => 'lesson_turn',
            'item_id' => $this->item->id,
            'expected_version' => 1,
            'request_sha256' => hash('sha256', 'turn1'),
        ]);

        $commitPayload = [
            'operation_id' => $opId,
            'lease_token' => $claim['lease_token'],
            'expected_version' => 1,
            'result_ref' => (string) Str::uuid(),
            'result_sha256' => hash('sha256', 'result1'),
            'outcome' => 'completed',
            'safe_code' => 'ok',
        ];

        $path = '/api/v3/internal/web-pipeline/results';
        $headers = $this->signedHeaders('POST', $path, $commitPayload);

        $resp = $this->postJson($path, $commitPayload, $headers);
        $resp->assertStatus(201);

        $this->run->refresh();
        $this->assertSame(2, $this->run->state_version);
    }

    public function test_commit_with_stale_lease_is_rejected(): void
    {
        $commitPayload = [
            'operation_id' => 'op_unclaimed',
            'lease_token' => (string) Str::uuid(),
            'expected_version' => 1,
            'result_ref' => (string) Str::uuid(),
            'result_sha256' => hash('sha256', 'res'),
            'outcome' => 'completed',
            'safe_code' => 'ok',
        ];

        $path = '/api/v3/internal/web-pipeline/results';
        $headers = $this->signedHeaders('POST', $path, $commitPayload);

        $resp = $this->postJson($path, $commitPayload, $headers);
        $this->assertTrue(in_array($resp->status(), [409, 422]));
    }

    public function test_claim_and_commit_survives_cache_flush_with_durable_operations(): void
    {
        $claimService = app(ClaimOperation::class);
        $commitService = app(CommitOperation::class);

        $opId = 'op_' . (string) Str::uuid();
        $claim = $claimService->claim([
            'run_id' => $this->run->id,
            'operation_id' => $opId,
            'kind' => 'lesson_turn',
            'item_id' => $this->item->id,
            'expected_version' => 1,
            'request_sha256' => hash('sha256', 'turn1'),
        ]);

        $this->assertNotEmpty($claim['lease_token']);

        // Evict/flush cache to simulate process restart / cache loss
        \Illuminate\Support\Facades\Cache::flush();

        // Commit operation should still succeed from durable storage
        $commitPayload = [
            'operation_id' => $opId,
            'lease_token' => $claim['lease_token'],
            'expected_version' => 1,
            'result_ref' => (string) Str::uuid(),
            'result_sha256' => hash('sha256', 'result1'),
            'outcome' => 'completed',
            'safe_code' => 'ok',
        ];

        $commitResult = $commitService->commit($commitPayload);
        $this->assertSame(201, $commitResult['status'] ?? 201);
        $this->assertSame('completed', $commitResult['outcome']);

        // Flush cache again and replay exact commit
        \Illuminate\Support\Facades\Cache::flush();
        $replayResult = $commitService->commit($commitPayload);
        $this->assertSame(200, $replayResult['status'] ?? 200);

        // Different result digest after cache flush should 409 conflict
        \Illuminate\Support\Facades\Cache::flush();
        $divergentPayload = $commitPayload;
        $divergentPayload['result_sha256'] = hash('sha256', 'divergent');

        try {
            $commitService->commit($divergentPayload);
            $this->fail('Expected 409 conflict on divergent result digest');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_accept_web_lesson_commits_bundle_without_losing_third_prompts(): void
    {
        $acceptor = app(AcceptWebLesson::class);

        $bundleData = [
            'run_id' => $this->run->id,
            'item_id' => $this->item->id,
            'case_id' => $this->case->id,
            'lesson_ordinal' => 1,
            'first_raw' => [
                'title' => 'Newtonian Mechanics',
                'summary' => 'Introduction to classical mechanics',
                'learning_objectives' => ['Understand inertia', 'Apply F=ma'],
                'target_audience' => 'Undergraduate Physics',
                'estimated_duration_minutes' => 45,
                'key_concepts' => ['Mass', 'Force', 'Acceleration'],
                'prerequisites' => ['Basic Calculus'],
                'pedagogical_approach' => 'Problem-based',
                'assessment_guidance' => 'Conceptual questions',
                'difficulty_level' => 'Intermediate',
                'laws_if_any' => null, // Intentionally null to test missing-field awareness
            ],
            'third_prompt' => 'Create a 3D simulation of a harmonic oscillator with mass on a spring.',
            'scene_title' => 'Harmonic Oscillator Simulation',
        ];

        $res = $acceptor->accept($bundleData);
        $this->assertSame('completed', $res['outcome']);

        // Verify section created
        $section = DB::table('v3_teacher_sections')->where('case_id', $this->case->id)->first();
        $this->assertNotNull($section);
        $this->assertSame('Newtonian Mechanics', $section->section_identity);

        // Verify prompt created with exact body
        $prompt = DB::table('v3_scene_prompt_versions')->where('case_id', $this->case->id)->first();
        $this->assertNotNull($prompt);
        $promptText = \Illuminate\Support\Facades\Storage::disk('v3_private')->get($prompt->body_path);
        $this->assertSame($bundleData['third_prompt'], $promptText);
    }

    public function test_accept_web_lesson_idempotent_replay_returns_same_refs_without_duplication(): void
    {
        $acceptor = app(AcceptWebLesson::class);

        $bundleData = [
            'run_id' => $this->run->id,
            'item_id' => $this->item->id,
            'case_id' => $this->case->id,
            'lesson_ordinal' => 1,
            'section_ordinal' => 1,
            'first_raw' => [
                'title' => 'Newtonian Mechanics',
                'summary' => 'Introduction to classical mechanics',
            ],
            'third_prompt' => 'Create a 3D simulation of harmonic oscillator.',
            'scene_title' => 'Harmonic Oscillator Simulation',
        ];

        $res1 = $acceptor->accept($bundleData);
        $this->assertSame('completed', $res1['outcome']);
        $sectionId1 = $res1['section_id'];
        $promptVersionId1 = $res1['prompt_version_id'];

        // Initial counts
        $this->assertSame(1, DB::table('v3_teacher_sections')->where('case_id', $this->case->id)->count());
        $this->assertSame(1, DB::table('v3_scene_prompt_versions')->where('case_id', $this->case->id)->count());
        $this->assertSame(1, $this->item->fresh()->saved_lesson_count);
        $this->assertSame(1, $this->item->fresh()->prompt_count);
        $this->assertSame(1, $this->run->fresh()->total_lessons);
        $this->assertSame(1, $this->run->fresh()->total_scenes);

        // Replay identical bundle
        $res2 = $acceptor->accept($bundleData);
        $this->assertSame('completed', $res2['outcome']);
        $this->assertSame($sectionId1, $res2['section_id']);
        $this->assertSame($promptVersionId1, $res2['prompt_version_id']);

        // Assert zero added records and zero count inflation
        $this->assertSame(1, DB::table('v3_teacher_sections')->where('case_id', $this->case->id)->count());
        $this->assertSame(1, DB::table('v3_scene_prompt_versions')->where('case_id', $this->case->id)->count());
        $this->assertSame(1, $this->item->fresh()->saved_lesson_count);
        $this->assertSame(1, $this->item->fresh()->prompt_count);
        $this->assertSame(1, $this->run->fresh()->total_lessons);
        $this->assertSame(1, $this->run->fresh()->total_scenes);
    }

    public function test_accept_web_lesson_divergent_digest_conflicts_with_409(): void
    {
        $acceptor = app(AcceptWebLesson::class);

        $bundleData1 = [
            'run_id' => $this->run->id,
            'item_id' => $this->item->id,
            'case_id' => $this->case->id,
            'lesson_ordinal' => 1,
            'section_ordinal' => 1,
            'first_raw' => [
                'title' => 'Newtonian Mechanics',
                'summary' => 'Introduction to classical mechanics',
            ],
            'third_prompt' => 'Create a 3D simulation of harmonic oscillator.',
            'scene_title' => 'Harmonic Oscillator Simulation',
        ];

        $acceptor->accept($bundleData1);

        // Same section ordinal, but divergent third_prompt
        $bundleData2 = $bundleData1;
        $bundleData2['third_prompt'] = 'Divergent prompt text for same section';

        try {
            $acceptor->accept($bundleData2);
            $this->fail('Expected 409 conflict on divergent bundle digest');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_multiple_sections_in_one_lesson_does_not_inflate_lesson_count(): void
    {
        $acceptor = app(AcceptWebLesson::class);

        // Section 1 of Lesson 1
        $bundleSection1 = [
            'run_id' => $this->run->id,
            'item_id' => $this->item->id,
            'case_id' => $this->case->id,
            'lesson_ordinal' => 1,
            'section_ordinal' => 1,
            'first_raw' => [
                'title' => 'Kinematics',
                'summary' => 'Velocity and Acceleration',
            ],
            'third_prompt' => 'Prompt for velocity vector visualization.',
        ];

        $res1 = $acceptor->accept($bundleSection1);
        $this->assertSame('completed', $res1['outcome']);
        $this->assertSame(1, $this->item->fresh()->saved_lesson_count);
        $this->assertSame(1, $this->item->fresh()->prompt_count);
        $this->assertSame(1, $this->run->fresh()->total_lessons);
        $this->assertSame(1, $this->run->fresh()->total_scenes);

        // Section 2 of the SAME Lesson 1
        $bundleSection2 = [
            'run_id' => $this->run->id,
            'item_id' => $this->item->id,
            'case_id' => $this->case->id,
            'lesson_ordinal' => 1,
            'section_ordinal' => 2,
            'first_raw' => [
                'title' => 'Dynamics',
                'summary' => 'Force and Newton Laws',
            ],
            'third_prompt' => 'Prompt for free body diagram simulation.',
        ];

        $res2 = $acceptor->accept($bundleSection2);
        $this->assertSame('completed', $res2['outcome']);

        // Lesson count remains 1, while scenes and prompts advance to 2
        $this->assertSame(1, $this->item->fresh()->saved_lesson_count);
        $this->assertSame(2, $this->item->fresh()->prompt_count);
        $this->assertSame(1, $this->run->fresh()->total_lessons);
        $this->assertSame(2, $this->run->fresh()->total_scenes);
        $this->assertSame(2, DB::table('v3_teacher_sections')->where('case_id', $this->case->id)->count());
        $this->assertSame(2, DB::table('v3_scene_prompt_versions')->where('case_id', $this->case->id)->count());
    }

    public function test_commit_with_stale_expected_version_returns_409(): void
    {
        $claimService = app(ClaimOperation::class);
        $opId = 'op_' . (string) Str::uuid();

        $claim = $claimService->claim([
            'run_id' => $this->run->id,
            'operation_id' => $opId,
            'kind' => 'lesson_turn',
            'item_id' => $this->item->id,
            'expected_version' => 1,
            'request_sha256' => hash('sha256', 'turn1'),
        ]);

        $commitPayload = [
            'operation_id' => $opId,
            'lease_token' => $claim['lease_token'],
            'expected_version' => 999, // Stale version
            'result_ref' => (string) Str::uuid(),
            'result_sha256' => hash('sha256', 'result1'),
            'outcome' => 'completed',
            'safe_code' => 'ok',
        ];

        $path = '/api/v3/internal/web-pipeline/results';
        $headers = $this->signedHeaders('POST', $path, $commitPayload);

        $resp = $this->postJson($path, $commitPayload, $headers);
        $resp->assertStatus(409);
        $this->assertSame('operation_conflict', $resp->json('error.code'));
    }

    public function test_signed_callback_results_with_bundle_persists_canonical_records_and_commits(): void
    {
        $claimService = app(ClaimOperation::class);
        $opId = 'op_' . (string) Str::uuid();

        $claim = $claimService->claim([
            'run_id' => $this->run->id,
            'operation_id' => $opId,
            'kind' => 'lesson_turn',
            'item_id' => $this->item->id,
            'expected_version' => 1,
            'request_sha256' => hash('sha256', 'turn1'),
        ]);

        $bundlePayload = [
            'run_id' => $this->run->id,
            'item_id' => $this->item->id,
            'case_id' => $this->case->id,
            'lesson_ordinal' => 1,
            'section_ordinal' => 1,
            'first_raw' => [
                'title' => 'Optics',
                'summary' => 'Refraction and Snell Law',
            ],
            'third_prompt' => 'Prompt for prism refraction simulation.',
        ];

        $resultSha = hash('sha256', 'optics_result');
        $commitPayload = [
            'operation_id' => $opId,
            'lease_token' => $claim['lease_token'],
            'expected_version' => 1,
            'result_ref' => (string) Str::uuid(),
            'result_sha256' => $resultSha,
            'outcome' => 'completed',
            'safe_code' => 'ok',
            'bundle' => $bundlePayload,
        ];

        $path = '/api/v3/internal/web-pipeline/results';
        $headers = $this->signedHeaders('POST', $path, $commitPayload);

        // 1. Initial signed commit
        $resp = $this->postJson($path, $commitPayload, $headers);
        $resp->assertStatus(201);

        $this->assertSame(1, DB::table('v3_teacher_sections')->where('case_id', $this->case->id)->count());
        $this->assertSame(1, DB::table('v3_scene_prompt_versions')->where('case_id', $this->case->id)->count());

        // 2. Replay with identical payload returns 200
        $replayHeaders = $this->signedHeaders('POST', $path, $commitPayload);
        $respReplay = $this->postJson($path, $commitPayload, $replayHeaders);
        $respReplay->assertStatus(200);

        // 3. Divergent result sha returns 409
        $divergentPayload = $commitPayload;
        $divergentPayload['result_sha256'] = hash('sha256', 'different_sha');
        $divergentHeaders = $this->signedHeaders('POST', $path, $divergentPayload);
        $respDivergent = $this->postJson($path, $divergentPayload, $divergentHeaders);
        $respDivergent->assertStatus(409);
    }
}
