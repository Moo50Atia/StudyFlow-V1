<?php

namespace Tests\Integration\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\Document;
use App\Models\V3\PipelineCommand;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewRoute;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\Review\EnsureReviewReady;
use App\Services\V3\SceneQualification\RecordQualificationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class ReviewRaceTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected Subject $subject;
    protected SubjectRun $run;
    protected SubjectRunItem $item;
    protected ProcessingCase $case;
    protected TeacherSection $section;
    protected ScenePromptVersion $promptVersion;
    protected SceneCandidate $candidate;
    protected TeacherReviewItem $reviewItem;
    protected string $viewerId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('v3_private');

        $admin = WebPipelineFixtures::createAdmin();
        $this->teacher = WebPipelineFixtures::createTeacherA();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher);

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
            'published_by_user_id' => $admin->id,
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
            'approved_by_user_id' => $admin->id,
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
            'owner_user_id' => $this->teacher->id,
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
            'owner_user_id' => $this->teacher->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Thermodynamics',
            'original_filename' => 'thermo.pdf',
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
            'owner_user_id' => $this->teacher->id,
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
            'section_identity' => 'Carnot Cycle',
            'core_understanding' => 'Maximum efficiency of a thermodynamic engine.',
            'first_prompt_sha256' => hash('sha256', 'f'),
            'answer_sha256' => hash('sha256', 'a'),
            'origin' => 'pipeline',
            'subject_run_item_id' => $this->item->id,
        ]);

        $promptText = 'Interactive Carnot engine simulation with pressure-volume diagram.';
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
            'actor_user_id' => $this->teacher->id,
            'action' => 'waive_prompt_gate',
            'request_sha256' => hash('sha256', 'cmdPromptRace'),
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
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'demo-waiver-v1',
            'policy_sha256' => hash('sha256', 'policy_prompt'),
            'prompt_sha256' => $promptSha,
            'missing_fields' => [],
            'acknowledgment_sha256' => hash('sha256', 'ack_prompt'),
            'command_id' => $cmdPrompt,
        ]);

        // Candidate files
        $candId = (string) Str::uuid();
        $indexHtml = '<!DOCTYPE html><html lang="en"><head><title>Carnot</title></head><body>PV Diagram</body></html>';
        $sceneJson = json_encode(['title' => 'Carnot Cycle', 'version' => '1.0']);
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
            'prompt_gate_id' => $pGate['id'],
        ]);

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
            'duration_ms' => 380,
            'created_at' => now(),
        ]);

        // Record scene gate
        $cmdScene = (string) Str::uuid();
        PipelineCommand::create([
            'id' => $cmdScene,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'record_scene_gate',
            'request_sha256' => hash('sha256', 'cmdSceneRace'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);
        $gateRecorder->record([
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => $this->candidate->id,
            'gate_stage' => 'scene',
            'basis' => 'owner_waived_demo',
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'demo-waiver-v1',
            'policy_sha256' => hash('sha256', 'policy_scene'),
            'prompt_sha256' => $promptSha,
            'candidate_manifest_sha256' => $manifestSha,
            'missing_fields' => [],
            'acknowledgment_sha256' => hash('sha256', 'ack_scene'),
            'command_id' => $cmdScene,
        ]);

        SceneCandidateState::create([
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

        $res = app(EnsureReviewReady::class)->ensure($this->case->id, $this->candidate->id);
        $this->reviewItem = TeacherReviewItem::find($res['data']['review_ref']);

        // Create verified preview session with frame 0
        $this->viewerId = (string) Str::uuid();
        ReviewPreviewSession::forceCreate([
            'id' => $this->viewerId,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'review_item_id' => $this->reviewItem->id,
            'candidate_id' => $this->candidate->id,
            'actor_user_id' => $this->teacher->id,
            'worker_session_ref' => $this->viewerId,
            'state' => 'ready',
            'first_frame_at' => now(),
            'last_frame_no' => 0,
            'expires_at' => now()->addMinutes(30),
            'idle_expires_at' => now()->addMinutes(15),
        ]);
    }

    public function test_competing_concurrent_decisions_exactly_one_wins(): void
    {
        $url = route('v3.teacher.cases.reviews.decide', [
            'case' => $this->case->id,
            'review' => $this->reviewItem->id,
        ]);

        $key1 = (string) Str::uuid();
        $key2 = (string) Str::uuid();

        $bodyApprove = [
            'schema_version' => 1,
            'idempotency_key' => $key1,
            'expected_review_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'candidate_version' => 1,
            'decision' => 'approve',
            'feedback_kind' => null,
            'notes' => null,
        ];

        $bodyChanges = [
            'schema_version' => 1,
            'idempotency_key' => $key2,
            'expected_review_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'candidate_version' => 1,
            'decision' => 'request_changes',
            'feedback_kind' => 'visual_interaction',
            'notes' => 'Please adjust piston stroke speed.',
        ];

        // First decision arrives and succeeds
        $res1 = $this->actingAs($this->teacher)->postJson($url, $bodyApprove);
        $res1->assertStatus(201);
        $this->assertEquals('recorded', $res1->json('data.state'));

        // Second decision with different key arrives against already decided review
        $res2 = $this->actingAs($this->teacher)->postJson($url, $bodyChanges);
        $res2->assertStatus(409);
        $this->assertEquals('review_conflict', $res2->json('error.code'));

        // Exactly one decision recorded in database
        $this->assertEquals(1, TeacherReviewDecision::where('review_item_id', $this->reviewItem->id)->count());

        // Review item status is decided and state version is 2
        $this->reviewItem->refresh();
        $this->assertEquals('decided', $this->reviewItem->status);
        $this->assertEquals(2, $this->reviewItem->state_version);
    }

    public function test_concurrent_idempotent_replay_returns_200(): void
    {
        $url = route('v3.teacher.cases.reviews.decide', [
            'case' => $this->case->id,
            'review' => $this->reviewItem->id,
        ]);

        $key = (string) Str::uuid();
        $body = [
            'schema_version' => 1,
            'idempotency_key' => $key,
            'expected_review_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'candidate_version' => 1,
            'decision' => 'approve',
            'feedback_kind' => null,
            'notes' => null,
        ];

        $res1 = $this->actingAs($this->teacher)->postJson($url, $body);
        $res1->assertStatus(201);

        // Immediate replay of exact same body and key
        $res2 = $this->actingAs($this->teacher)->postJson($url, $body);
        $res2->assertStatus(200);
        $this->assertEquals($res1->json('data.decision_ref'), $res2->json('data.decision_ref'));
        $this->assertEquals(1, TeacherReviewDecision::where('review_item_id', $this->reviewItem->id)->count());
    }
}
