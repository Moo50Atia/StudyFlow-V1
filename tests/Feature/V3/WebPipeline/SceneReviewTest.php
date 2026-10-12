<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\Document;
use App\Models\V3\PipelineCommand;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\Review\EnsureReviewReady;
use App\Services\V3\SceneQualification\RecordQualificationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class SceneReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacherA;
    protected User $teacherB;
    protected User $admin;
    protected User $student;
    protected Subject $subject;
    protected SubjectRun $run;
    protected SubjectRunItem $item;
    protected ProcessingCase $case;
    protected TeacherSection $section;
    protected ScenePromptVersion $promptVersion;
    protected SceneCandidate $candidate;
    protected SceneCandidateState $candidateState;
    protected string $promptGateId;
    protected string $sceneGateId;
    protected TeacherReviewItem $reviewItem;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('v3_private');

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacherA = WebPipelineFixtures::createTeacherA();
        $this->teacherB = WebPipelineFixtures::createTeacherB();
        $this->student = WebPipelineFixtures::createStudent();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacherA);

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => json_encode([
                'templates' => [
                    'scene' => [
                        'id' => 'tmpl-scene-v1',
                        'checksum' => hash('sha256', 'tmpl'),
                    ],
                ],
                'limits' => ['coding_attempts' => 3],
                'providers' => ['coding_order' => ['gemini']],
            ]),
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
            'owner_user_id' => $this->teacherA->id,
            'subject_id' => $this->subject->id,
            'origin' => 'web',
            'protocol' => 'notebooklm_per_pdf_web_v1',
            'settings_version_id' => $settingsId,
            'framework_version_id' => $frameworkId,
            'mode' => 'export_manual',
            'qualification_mode' => 'owner_waived_demo',
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'run1'),
            'captured_settings_json' => json_encode(['mode' => 'export_manual']),
            'capture_sha256' => hash('sha256', json_encode(['mode' => 'export_manual'])),
            'state' => 'prompts_ready',
            'state_version' => 1,
            'total_pdfs' => 1,
            'total_lessons' => 1,
            'total_scenes' => 1,
            'last_event_sequence' => 1,
        ]);

        $doc = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'sha256' => hash('sha256', 'doc1'),
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/d1.pdf',
            'accepted_at' => now(),
        ]);

        $this->item = SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacherA->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Orbital Dynamics',
            'original_filename' => 'orbital.pdf',
            'input_sha256' => hash('sha256', 'pdf1'),
            'input_bytes' => 1024,
            'state' => 'prompts_ready',
            'document_id' => $doc->id,
            'saved_lesson_count' => 1,
            'prompt_count' => 1,
            'state_version' => 1,
        ]);

        $this->case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'document_id' => $doc->id,
            'settings_version_id' => $settingsId,
            'idempotency_key' => 'item:' . $this->item->id,
            'action' => 'web_subject',
            'state' => 'prompts_ready',
            'state_version' => 1,
            'pipeline_version' => 'notebooklm_per_pdf_web_v1',
            'notebook_framework_version_id' => $frameworkId,
            'subject_run_item_id' => $this->item->id,
        ]);
        $this->item->update(['case_id' => $this->case->id]);

        $srcAct = (string) Str::uuid();
        DB::table('v3_source_activations')->insert(['id' => $srcAct, 'case_id' => $this->case->id, 'workflow_id' => 'wf', 'state' => 'accepted']);
        $src = (string) Str::uuid();
        DB::table('v3_extracted_sources')->insert(['id' => $src, 'case_id' => $this->case->id, 'activation_id' => $srcAct, 'document_id' => $doc->id, 'settings_version_id' => $settingsId, 'status' => 'ready', 'page_report' => '{}', 'extractor_version' => 'v1']);
        $bind = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert(['id' => $bind, 'case_id' => $this->case->id, 'extracted_source_id' => $src, 'adapter_kind' => 'nb', 'adapter_version' => '1', 'notebook_title' => 't', 'source_title' => 's', 'state' => 'bound', 'text_sha256' => 't', 'text_bytes' => 1]);
        $mapAct = (string) Str::uuid();
        DB::table('v3_map_activations')->insert(['id' => $mapAct, 'case_id' => $this->case->id, 'binding_id' => $bind, 'workflow_id' => 'w', 'state' => 'ready']);
        $map = (string) Str::uuid();
        DB::table('v3_lesson_maps')->insert([
            'id' => $map,
            'case_id' => $this->case->id,
            'binding_id' => $bind,
            'activation_id' => $mapAct,
            'state' => 'ready',
            'page_count' => 1,
        ]);

        $node = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert([
            'id' => $node,
            'map_id' => $map,
            'kind' => 'l',
            'position_path' => '001',
            'ordinal_in_parent' => 1,
            'title' => 'L1',
            'work_ordinal' => 1,
        ]);

        $q = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert([
            'id' => $q,
            'case_id' => $this->case->id,
            'map_id' => $map,
            'kind' => 'first',
            'target_work_ordinal' => 1,
            'state' => 'recorded',
            'send_count' => 1,
            'request_sha256' => 'r',
        ]);

        $this->section = TeacherSection::create([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $map,
            'work_node_id' => $node,
            'query_id' => $q,
            'ordinal_in_work' => 1,
            'section_identity' => 'Keplerian Motion',
            'core_understanding' => 'Planetary orbits follow elliptical paths around the primary focus.',
            'first_prompt_sha256' => hash('sha256', 'f'),
            'answer_sha256' => hash('sha256', 'a'),
            'origin' => 'pipeline',
            'subject_run_item_id' => $this->item->id,
        ]);

        $promptText = 'Interactive 3D simulation of two-body Keplerian orbit with velocity vectors.';
        $promptSha = hash('sha256', $promptText);
        $bodyPath = "prompt-versions/{$this->case->id}/{$this->section->id}/v1_{$promptSha}.txt";
        Storage::disk('v3_private')->put($bodyPath, $promptText);

        $wi = PromptWorkItem::create([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $map,
            'work_node_id' => $node,
            'work_ordinal' => 1,
            'expected_cursor_version' => 1,
            'section_query_id' => $q,
            'state' => 'receipt_committed',
            'section_refs' => [$this->section->id],
        ]);

        $this->promptVersion = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'work_item_id' => $wi->id,
            'version_no' => 1,
            'origin' => 'third',
            'body_path' => $bodyPath,
            'body_sha256' => $promptSha,
            'body_bytes' => strlen($promptText),
            'scene_type' => 'simulation',
            'section_fields_sha256' => hash('sha256', 'fields'),
            'canonical_third_sha256' => hash('sha256', 'third'),
            'created_at' => now(),
        ]);

        PromptSectionState::create([
            'section_id' => $this->section->id,
            'case_id' => $this->case->id,
            'current_prompt_version_id' => $this->promptVersion->id,
            'status' => 'ready',
            'revision_count' => 1,
        ]);

        // Record prompt gate
        $cmdPrompt = (string) Str::uuid();
        PipelineCommand::create([
            'id' => $cmdPrompt,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacherA->id,
            'action' => 'waive_prompt_gate',
            'request_sha256' => hash('sha256', 'cmdPrompt'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);
        $gateRecorder = app(RecordQualificationGate::class);
        $pGate = $gateRecorder->record([
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'waived_by_owner_id' => $this->teacherA->id,
            'policy_version' => 'demo-waiver-v1',
            'policy_sha256' => hash('sha256', 'policy_prompt'),
            'prompt_sha256' => $promptSha,
            'missing_fields' => [],
            'acknowledgment_sha256' => hash('sha256', 'ack_prompt'),
            'command_id' => $cmdPrompt,
        ]);
        $this->promptGateId = $pGate['id'];

        // Write candidate files
        $candId = (string) Str::uuid();
        $indexHtml = '<!DOCTYPE html><html lang="en"><head><title>Orbit</title></head><body><div id="app">Keplerian Simulation</div></body></html>';
        $sceneJson = json_encode(['title' => 'Keplerian Orbit', 'version' => '1.0']);
        $manifestJson = json_encode([
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'sha256' => hash('sha256', $indexHtml), 'bytes' => strlen($indexHtml)],
                ['path' => 'scene.json', 'sha256' => hash('sha256', $sceneJson), 'bytes' => strlen($sceneJson)],
            ],
        ]);
        $manifestPath = "candidates/{$candId}/manifest.json";
        Storage::disk('v3_private')->put("candidates/{$candId}/index.html", $indexHtml);
        Storage::disk('v3_private')->put("candidates/{$candId}/scene.json", $sceneJson);
        Storage::disk('v3_private')->put($manifestPath, $manifestJson);
        $manifestSha = hash('sha256', $manifestJson);

        $this->candidate = SceneCandidate::forceCreate([
            'id' => $candId,
            'request_id' => null,
            'attempt_id' => null,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'qualification_id' => null,
            'settings_version_id' => $settingsId,
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestSha,
            'entrypoint' => 'index.html',
            'file_count' => 2,
            'total_bytes' => strlen($indexHtml) + strlen($sceneJson),
            'collected_at' => now(),
            'origin' => 'authoring',
            'package_profile' => 'standard',
            'prompt_gate_id' => $this->promptGateId,
        ]);

        // Create browser check attempt & run
        $attempt = SceneCheckAttempt::create([
            'id' => (string) Str::uuid(),
            'candidate_id' => $this->candidate->id,
            'case_id' => $this->case->id,
            'attempt_no' => 1,
            'manifest_sha256' => $manifestSha,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'state' => 'recorded',
            'request_sha256' => hash('sha256', 'check_req'),
            'created_at' => now(),
        ]);

        // Write screenshot to storage
        $screenshotPath = "checks/{$this->candidate->id}/screenshot.png";
        Storage::disk('v3_private')->put($screenshotPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $checkRun = SceneCheckRun::create([
            'id' => (string) Str::uuid(),
            'candidate_id' => $this->candidate->id,
            'winning_attempt_id' => $attempt->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $settingsId,
            'manifest_sha256' => $manifestSha,
            'report_sha256' => hash('sha256', 'report'),
            'screenshot_sha256' => hash('sha256', 'png'),
            'report_path' => "checks/{$this->candidate->id}/report.json",
            'screenshot_path' => $screenshotPath,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'verdict' => 'pass',
            'failed_checks' => [],
            'duration_ms' => 450,
            'created_at' => now(),
        ]);

        // Record scene gate
        $cmdScene = (string) Str::uuid();
        PipelineCommand::create([
            'id' => $cmdScene,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacherA->id,
            'action' => 'record_scene_gate',
            'request_sha256' => hash('sha256', 'cmdScene'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);
        $sGate = $gateRecorder->record([
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => $this->candidate->id,
            'gate_stage' => 'scene',
            'basis' => 'owner_waived_demo',
            'waived_by_owner_id' => $this->teacherA->id,
            'policy_version' => 'demo-waiver-v1',
            'policy_sha256' => hash('sha256', 'policy_scene'),
            'prompt_sha256' => $promptSha,
            'candidate_manifest_sha256' => $manifestSha,
            'missing_fields' => [],
            'acknowledgment_sha256' => hash('sha256', 'ack_scene'),
            'command_id' => $cmdScene,
        ]);
        $this->sceneGateId = $sGate['id'];

        $this->candidateState = SceneCandidateState::create([
            'candidate_id' => $this->candidate->id,
            'root_candidate_id' => $this->candidate->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'version_no' => 1,
            'status' => 'demo_review_ready',
            'check_run_id' => $checkRun->id,
            'qualification_id' => null,
            'state_version' => 2,
        ]);

        // Prepare review via EnsureReviewReady
        $reviewReadyService = app(EnsureReviewReady::class);
        $res = $reviewReadyService->ensure($this->case->id, $this->candidate->id);
        $this->assertEquals('created', $res['status']);
        $this->reviewItem = TeacherReviewItem::find($res['data']['review_ref']);
    }

    public function test_owner_can_view_review_index_and_show(): void
    {
        // 1. Index
        $indexRes = $this->actingAs($this->teacherA)
            ->get(route('v3.teacher.cases.reviews.index', $this->case->id));

        $indexRes->assertStatus(200);

        // JSON index
        $indexJson = $this->actingAs($this->teacherA)
            ->getJson(route('v3.teacher.cases.reviews.index', $this->case->id));

        $indexJson->assertStatus(200);
        $indexJson->assertJsonPath('data.0.review_ref', $this->reviewItem->id);

        // 2. Show HTML
        $showRes = $this->actingAs($this->teacherA)
            ->get(route('v3.teacher.cases.reviews.show', ['case' => $this->case->id, 'review' => $this->reviewItem->id]));

        $showRes->assertStatus(200);
        $showRes->assertSee('Review Scene: Keplerian Motion');

        // 3. Show JSON
        $showJson = $this->actingAs($this->teacherA)
            ->getJson(route('v3.teacher.cases.reviews.show', ['case' => $this->case->id, 'review' => $this->reviewItem->id]));

        $showJson->assertStatus(200);
        $showJson->assertJsonPath('data.review_ref', $this->reviewItem->id);
        $showJson->assertJsonPath('data.review_state', 'pending');
        $showJson->assertJsonPath('data.eligibility_basis', 'owner_waived_demo');
    }

    public function test_unauthorized_teacher_and_student_forbidden(): void
    {
        // Teacher B
        $resB = $this->actingAs($this->teacherB)
            ->get(route('v3.teacher.cases.reviews.show', ['case' => $this->case->id, 'review' => $this->reviewItem->id]));
        $resB->assertStatus(404);

        // Student
        $resStudent = $this->actingAs($this->student)
            ->get(route('v3.teacher.cases.reviews.show', ['case' => $this->case->id, 'review' => $this->reviewItem->id]));
        $this->assertTrue(in_array($resStudent->status(), [403, 404], true));
    }

    public function test_admin_cannot_access_teacher_review(): void
    {
        // Admin gets 403 because role middleware restricts to teacher
        $resAdmin = $this->actingAs($this->admin)
            ->get(route('v3.teacher.cases.reviews.show', ['case' => $this->case->id, 'review' => $this->reviewItem->id]));
        $resAdmin->assertStatus(403);
    }

    public function test_owner_can_start_preview_session_and_fetch_frame_0(): void
    {
        $pngBytes = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x05\x00\x00\x00\x02\xd0\x08\x06\x00\x00\x00" . str_repeat("\x00", 100);
        $pngB64 = base64_encode($pngBytes);

        Http::fake([
            '*/internal/v1/review-preview/sessions' => function ($request) use ($pngB64) {
                $body = json_decode($request->body(), true) ?? [];
                $viewerRef = $body['viewer_ref'] ?? (string) Str::uuid();
                return Http::response([
                    'data' => [
                        'viewer_ref' => $viewerRef,
                        'frame_no' => 0,
                        'frame' => [
                            'viewer_ref' => $viewerRef,
                            'frame_no' => 0,
                            'png_base64' => $pngB64,
                            'dom_text' => 'Interactive Scene Heading',
                            'controls' => [
                                ['ordinal' => 1, 'role' => 'button', 'name' => 'Toggle', 'focusable' => true],
                            ],
                            'runtime_error_count' => 0,
                            'forbidden_request_count' => 0,
                        ],
                    ],
                ], 201);
            },
            '*/internal/v1/review-preview/sessions/*/frames/*' => function () use ($pngBytes) {
                return Http::response($pngBytes, 200, ['Content-Type' => 'image/png']);
            },
            '*/internal/v1/review-preview/sessions/*/actions' => function () use ($pngB64) {
                return Http::response([
                    'data' => [
                        'viewer_ref' => (string) Str::uuid(),
                        'frame_no' => 1,
                        'frame' => [
                            'viewer_ref' => (string) Str::uuid(),
                            'frame_no' => 1,
                            'png_base64' => $pngB64,
                            'dom_text' => 'Updated Scene Text',
                            'controls' => [],
                            'runtime_error_count' => 0,
                            'forbidden_request_count' => 0,
                        ],
                    ],
                ], 200);
            },
            '*/internal/v1/review-preview/sessions/*' => Http::response(null, 204),
        ]);

        // 1. Start viewer session
        $startRes = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.cases.reviews.viewer.start', [
                'case' => $this->case->id,
                'review' => $this->reviewItem->id,
            ]), [
                'schema_version' => 1,
                'expected_review_version' => 1,
            ]);

        $startRes->assertStatus(201);
        $viewerId = $startRes->json('data.viewer_ref');
        $this->assertNotNull($viewerId);

        // 2. Show viewer session
        $showRes = $this->actingAs($this->teacherA)
            ->getJson(route('v3.teacher.cases.reviews.viewer.show', [
                'case' => $this->case->id,
                'review' => $this->reviewItem->id,
                'viewer' => $viewerId,
            ]));
        $showRes->assertStatus(200);

        // 3. Fetch first frame 0
        $frameRes = $this->actingAs($this->teacherA)
            ->get(route('v3.teacher.cases.reviews.viewer.frame', [
                'case' => $this->case->id,
                'review' => $this->reviewItem->id,
                'viewer' => $viewerId,
                'frame_no' => 0,
            ]));
        $frameRes->assertStatus(200);

        // Verify session recorded first_frame_at
        $session = ReviewPreviewSession::find($viewerId);
        $this->assertNotNull($session->first_frame_at);

        // 4. Send action
        $actionRes = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.cases.reviews.viewer.action', [
                'case' => $this->case->id,
                'review' => $this->reviewItem->id,
                'viewer' => $viewerId,
            ]), [
                'schema_version' => 1,
                'expected_frame_no' => 0,
                'kind' => 'click',
                'payload' => [
                    'x' => 100,
                    'y' => 100,
                ],
            ]);
        $actionRes->assertStatus(200);

        // 5. Close session
        $closeRes = $this->actingAs($this->teacherA)
            ->deleteJson(route('v3.teacher.cases.reviews.viewer.close', [
                'case' => $this->case->id,
                'review' => $this->reviewItem->id,
                'viewer' => $viewerId,
            ]));
        $closeRes->assertStatus(204);
    }

    public function test_approve_requires_frame_0_proof_and_succeeds_after_frame_0(): void
    {
        $decisionUrl = route('v3.teacher.cases.reviews.decide', [
            'case' => $this->case->id,
            'review' => $this->reviewItem->id,
        ]);

        // Attempt approve without frame 0 -> 409 review_conflict
        $failApprove = $this->actingAs($this->teacherA)->postJson($decisionUrl, [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'candidate_version' => 1,
            'decision' => 'approve',
            'feedback_kind' => null,
            'notes' => null,
        ]);
        $failApprove->assertStatus(409);
        $this->assertEquals('review_conflict', $failApprove->json('error.code'));

        // Start session and fetch frame 0
        $pngBytes = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x05\x00\x00\x00\x02\xd0\x08\x06\x00\x00\x00" . str_repeat("\x00", 100);
        $pngB64 = base64_encode($pngBytes);

        Http::fake([
            '*/internal/v1/review-preview/sessions' => function ($request) use ($pngB64) {
                $body = json_decode($request->body(), true) ?? [];
                $viewerRef = $body['viewer_ref'] ?? (string) Str::uuid();
                return Http::response([
                    'data' => [
                        'viewer_ref' => $viewerRef,
                        'frame_no' => 0,
                        'frame' => [
                            'viewer_ref' => $viewerRef,
                            'frame_no' => 0,
                            'png_base64' => $pngB64,
                            'dom_text' => 'Interactive Scene Heading',
                            'controls' => [],
                            'runtime_error_count' => 0,
                            'forbidden_request_count' => 0,
                        ],
                    ],
                ], 201);
            },
            '*/internal/v1/review-preview/sessions/*/frames/*' => function () use ($pngBytes) {
                return Http::response($pngBytes, 200, ['Content-Type' => 'image/png']);
            },
            '*/internal/v1/review-preview/sessions/*' => Http::response(null, 204),
        ]);

        $startRes = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.cases.reviews.viewer.start', [
                'case' => $this->case->id,
                'review' => $this->reviewItem->id,
            ]), [
                'schema_version' => 1,
                'expected_review_version' => 1,
            ]);
        $viewerId = $startRes->json('data.viewer_ref');

        $this->actingAs($this->teacherA)->get(route('v3.teacher.cases.reviews.viewer.frame', [
            'case' => $this->case->id,
            'review' => $this->reviewItem->id,
            'viewer' => $viewerId,
            'frame_no' => 0,
        ]));

        // Now approve succeeds
        $idempotencyKey = (string) Str::uuid();
        $okApprove = $this->actingAs($this->teacherA)->postJson($decisionUrl, [
            'schema_version' => 1,
            'idempotency_key' => $idempotencyKey,
            'expected_review_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'candidate_version' => 1,
            'decision' => 'approve',
            'feedback_kind' => null,
            'notes' => null,
        ]);

        $okApprove->assertStatus(201);
        $this->assertEquals('recorded', $okApprove->json('data.state'));
        $this->assertNotNull($okApprove->json('data.approval_ref'));

        $this->reviewItem->refresh();
        $this->assertEquals('decided', $this->reviewItem->status);

        $this->assertDatabaseHas('v3_approved_scenes', [
            'id' => $okApprove->json('data.approval_ref'),
            'candidate_id' => $this->candidate->id,
            'section_id' => $this->section->id,
        ]);

        // Replay same idempotency key returns 200 recorded
        $replay = $this->actingAs($this->teacherA)->postJson($decisionUrl, [
            'schema_version' => 1,
            'idempotency_key' => $idempotencyKey,
            'expected_review_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'candidate_version' => 1,
            'decision' => 'approve',
            'feedback_kind' => null,
            'notes' => null,
        ]);
        $replay->assertStatus(200);
        $this->assertEquals('recorded', $replay->json('data.state'));
    }

    public function test_superseded_prompt_version_blocks_review(): void
    {
        // Advance prompt section to a newer version
        $newPromptVersion = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'work_item_id' => $this->promptVersion->work_item_id,
            'version_no' => 2,
            'origin' => 'third',
            'body_path' => 'prompts/v2.txt',
            'body_sha256' => hash('sha256', 'v2'),
            'body_bytes' => 2,
            'scene_type' => 'simulation',
            'section_fields_sha256' => hash('sha256', 'fields2'),
            'canonical_third_sha256' => hash('sha256', 'third2'),
            'created_at' => now(),
        ]);

        PromptSectionState::where('section_id', $this->section->id)
            ->update(['current_prompt_version_id' => $newPromptVersion->id]);

        // Starting preview session now fails due to currency fence
        $startRes = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.cases.reviews.viewer.start', [
                'case' => $this->case->id,
                'review' => $this->reviewItem->id,
            ]), [
                'schema_version' => 1,
                'expected_review_version' => 1,
            ]);

        $startRes->assertStatus(409);
        $this->assertEquals('review_conflict', $startRes->json('error.code'));
    }
}
