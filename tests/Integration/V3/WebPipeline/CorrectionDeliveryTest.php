<?php

namespace Tests\Integration\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\Document;
use App\Models\V3\PipelineCommand;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ReviewOutbox;
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
use App\Models\V3\WorkflowLink;
use App\Services\V3\Authoring\ManualSceneImporter;
use App\Services\V3\Review\DispatchReviewEffects;
use App\Services\V3\Review\EnsureReviewReady;
use App\Services\V3\Review\SubmitPostapprovalCorrection;
use App\Services\V3\Review\SubmitReviewDecision;
use App\Services\V3\SceneQualification\RecordQualificationGate;
use App\Services\V3\Workflow\ReviewSignalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class CorrectionDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected User $admin;
    protected Subject $subject;
    protected SubjectRun $run;
    protected SubjectRunItem $item;
    protected ProcessingCase $case;
    protected TeacherSection $section;
    protected ScenePromptVersion $promptVersion;
    protected SceneCandidate $candidate;
    protected SceneCandidateState $candidateState;
    protected TeacherReviewItem $reviewItem;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('v3_private');

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacher = WebPipelineFixtures::createTeacherA();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher);

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => json_encode(['mode' => 'export_manual']),
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
            'request_sha256' => hash('sha256', 'run1'),
            'captured_settings_json' => json_encode(['mode' => 'export_manual']),
            'capture_sha256' => hash('sha256', json_encode(['mode' => 'export_manual'])),
            'state' => 'scenes_ready',
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
            'display_title' => 'Gravitation',
            'original_filename' => 'gravitation.pdf',
            'input_sha256' => hash('sha256', 'pdf1'),
            'input_bytes' => 1024,
            'state' => 'scenes_ready',
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
            'state' => 'scenes_ready',
            'state_version' => 1,
            'pipeline_version' => 'notebooklm_per_pdf_web_v1',
            'notebook_framework_version_id' => $frameworkId,
            'subject_run_item_id' => $this->item->id,
        ]);
        $this->item->update(['case_id' => $this->case->id]);

        WorkflowLink::create([
            'case_id' => $this->case->id,
            'workflow_id' => "v3-case-{$this->case->id}",
            'run_id' => (string) Str::uuid(),
            'settings_version_id' => $settingsId,
            'status_code' => 'running',
            'start_state' => 'started',
        ]);

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
            'section_identity' => 'Newtonian Gravitation',
            'core_understanding' => 'Inverse-square force law governs planetary attraction.',
            'first_prompt_sha256' => hash('sha256', 'f'),
            'answer_sha256' => hash('sha256', 'a'),
            'origin' => 'pipeline',
            'subject_run_item_id' => $this->item->id,
        ]);

        $promptText = 'Interactive 3D gravitational attraction simulation.';
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

        $cmdPrompt = (string) Str::uuid();
        PipelineCommand::create([
            'id' => $cmdPrompt,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'waive_prompt_gate',
            'request_sha256' => hash('sha256', 'cmdP'),
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
            'policy_sha256' => hash('sha256', 'polP'),
            'prompt_sha256' => $promptSha,
            'missing_fields' => [],
            'acknowledgment_sha256' => hash('sha256', 'ackP'),
            'command_id' => $cmdPrompt,
        ]);

        // Candidate
        $candId = (string) Str::uuid();
        $indexHtml = '<!DOCTYPE html><html><body>Gravity</body></html>';
        $sceneJson = '{"version":"1.0"}';
        $manifestJson = json_encode([
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'sha256' => hash('sha256', $indexHtml), 'bytes' => strlen($indexHtml)],
                ['path' => 'scene.json', 'sha256' => hash('sha256', $sceneJson), 'bytes' => strlen($sceneJson)],
            ],
        ]);
        Storage::disk('v3_private')->put("candidates/{$candId}/index.html", $indexHtml);
        Storage::disk('v3_private')->put("candidates/{$candId}/scene.json", $sceneJson);
        Storage::disk('v3_private')->put("candidates/{$candId}/manifest.json", $manifestJson);

        $this->candidate = SceneCandidate::forceCreate([
            'id' => $candId,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $settingsId,
            'manifest_path' => "candidates/{$candId}/manifest.json",
            'manifest_sha256' => hash('sha256', $manifestJson),
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
            'manifest_sha256' => $this->candidate->manifest_sha256,
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
            'manifest_sha256' => $this->candidate->manifest_sha256,
            'report_sha256' => hash('sha256', 'report'),
            'screenshot_sha256' => hash('sha256', 'png'),
            'report_path' => "checks/{$this->candidate->id}/report.json",
            'screenshot_path' => $screenshotPath,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'verdict' => 'pass',
            'failed_checks' => [],
            'duration_ms' => 300,
            'created_at' => now(),
        ]);

        $cmdScene = (string) Str::uuid();
        PipelineCommand::create([
            'id' => $cmdScene,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'record_scene_gate',
            'request_sha256' => hash('sha256', 'cmdS'),
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
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'demo-waiver-v1',
            'policy_sha256' => hash('sha256', 'polS'),
            'prompt_sha256' => $promptSha,
            'candidate_manifest_sha256' => $this->candidate->manifest_sha256,
            'missing_fields' => [],
            'acknowledgment_sha256' => hash('sha256', 'ackS'),
            'command_id' => $cmdScene,
        ]);

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

        $reviewReady = app(EnsureReviewReady::class)->ensure($this->case->id, $this->candidate->id);
        $this->reviewItem = TeacherReviewItem::find($reviewReady['data']['review_ref']);
    }

    public function test_exact_decision_consumption_required_for_outbox_delivery(): void
    {
        // 1. Submit request_changes decision
        $decisionService = app(SubmitReviewDecision::class);
        $submitRes = $decisionService->submit(
            $this->case,
            $this->reviewItem,
            [
                'schema_version' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'expected_review_version' => 1,
                'candidate_ref' => $this->candidate->id,
                'candidate_version' => 1,
                'decision' => 'request_changes',
                'feedback_kind' => 'visual_interaction',
                'notes' => 'Change color scheme to high contrast.',
            ],
            $this->teacher
        );

        $this->assertEquals(201, $submitRes['status']);
        $decisionRef = $submitRes['data']['decision_ref'];

        $outbox = ReviewOutbox::where('review_decision_id', $decisionRef)->first();
        $this->assertNotNull($outbox);
        $this->assertEquals('pending', $outbox->state);

        // 2. Gateway reports accepted: true for signal, but queryDecision returns consumed: false
        $gatewayMock = $this->createMock(ReviewSignalGateway::class);
        $gatewayMock->expects($this->exactly(2))
            ->method('queryDecision')
            ->willReturn(['status' => 200, 'consumed' => false]);
        $gatewayMock->expects($this->once())
            ->method('sendDecisionSignal')
            ->willReturn(['accepted' => true]);

        $dispatchService = new DispatchReviewEffects($gatewayMock);
        $dispatched = $dispatchService->dispatchPending();

        $this->assertEquals(0, $dispatched);
        $outbox->refresh();
        // Item must NOT be marked delivered
        $this->assertNotEquals('delivered', $outbox->state);
        $this->assertNull($outbox->delivered_at);

        // 3. When queryDecision confirms consumed: true and startCorrection succeeds
        $gatewayMock2 = $this->createMock(ReviewSignalGateway::class);
        $gatewayMock2->expects($this->once())
            ->method('queryDecision')
            ->willReturn(['status' => 200, 'consumed' => true]);
        $gatewayMock2->expects($this->once())
            ->method('startCorrection')
            ->willReturn(['accepted' => true]);

        $dispatchService2 = new DispatchReviewEffects($gatewayMock2);
        $dispatched2 = $dispatchService2->dispatchPending();

        $this->assertEquals(1, $dispatched2);
        $outbox->refresh();
        $this->assertEquals('delivered', $outbox->state);
        $this->assertNotNull($outbox->delivered_at);
    }

    public function test_manual_mode_awaits_replacement_and_accepts_manual_candidate(): void
    {
        // 1. Submit request_changes in export_manual mode
        $decisionService = app(SubmitReviewDecision::class);
        $submitRes = $decisionService->submit(
            $this->case,
            $this->reviewItem,
            [
                'schema_version' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'expected_review_version' => 1,
                'candidate_ref' => $this->candidate->id,
                'candidate_version' => 1,
                'decision' => 'request_changes',
                'feedback_kind' => 'visual_interaction',
                'notes' => 'Please manually update the index.html styling.',
            ],
            $this->teacher
        );
        $this->assertEquals(201, $submitRes['status']);

        // ReviewSectionState is routing, current_review_item_id is null
        $secState = ReviewSectionState::where('section_id', $this->section->id)->first();
        $this->assertEquals('routing', $secState->state);
        $this->assertNull($secState->current_review_item_id);

        $route = ReviewRoute::where('review_decision_id', $submitRes['data']['decision_ref'])->first();
        $this->assertNotNull($route);
        $this->assertEquals('scene_repair', $route->kind);
        $this->assertEquals('pending', $route->state);

        // 2. In manual mode, teacher manually imports replacement candidate
        $importer = app(\App\Services\V3\Authoring\ImportManualScene::class);
        $importCmd = (string) Str::uuid();
        $manualFiles = [
            ['path' => 'index.html', 'content' => '<!DOCTYPE html><html><body>Corrected Gravity</body></html>'],
            ['path' => 'styles.css', 'content' => 'body { color: cyan; }'],
            ['path' => 'script.js', 'content' => 'console.log("corrected");'],
        ];

        $manualPayload = [
            'command_id' => $importCmd,
            'expected_version' => 1,
            'prompt_version_id' => $this->promptVersion->id,
            'package' => [
                'schema_version' => 1,
                'files' => $manualFiles,
            ],
        ];

        $importRes = $importer->import($this->teacher, $this->run, $this->section, $manualPayload);
        $newCandId = $importRes['candidate_id'];
        $this->assertNotEmpty($newCandId);

        // Candidate exists with origin manual_import
        $newCand = SceneCandidate::find($newCandId);
        $this->assertEquals('manual_import', $newCand->origin);

        // Browser check attempt & run on replacement candidate
        $attempt2 = SceneCheckAttempt::create([
            'id' => (string) Str::uuid(),
            'candidate_id' => $newCandId,
            'case_id' => $this->case->id,
            'attempt_no' => 1,
            'manifest_sha256' => $newCand->manifest_sha256,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'state' => 'recorded',
            'request_sha256' => hash('sha256', 'check2'),
            'created_at' => now(),
        ]);
        $checkRun2 = SceneCheckRun::create([
            'id' => (string) Str::uuid(),
            'candidate_id' => $newCandId,
            'winning_attempt_id' => $attempt2->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->case->settings_version_id,
            'manifest_sha256' => $newCand->manifest_sha256,
            'report_sha256' => hash('sha256', 'rep2'),
            'screenshot_sha256' => hash('sha256', 'png2'),
            'report_path' => "checks/{$newCandId}/report.json",
            'screenshot_path' => "checks/{$newCandId}/screenshot.png",
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'verdict' => 'pass',
            'failed_checks' => [],
            'duration_ms' => 250,
            'created_at' => now(),
        ]);
        Storage::disk('v3_private')->put("checks/{$newCandId}/screenshot.png", 'png_data');

        // Scene gate for manual replacement
        $cmdManualGate = (string) Str::uuid();
        PipelineCommand::create([
            'id' => $cmdManualGate,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'record_scene_gate',
            'request_sha256' => hash('sha256', 'cmdMG'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);
        app(RecordQualificationGate::class)->record([
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => $newCandId,
            'gate_stage' => 'scene',
            'basis' => 'owner_waived_demo',
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'demo-waiver-v1',
            'policy_sha256' => hash('sha256', 'polMG'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'candidate_manifest_sha256' => $newCand->manifest_sha256,
            'missing_fields' => [],
            'acknowledgment_sha256' => hash('sha256', 'ackMG'),
            'command_id' => $cmdManualGate,
        ]);

        SceneCandidateState::where('candidate_id', $newCandId)->update([
            'status' => 'demo_review_ready',
            'check_run_id' => $checkRun2->id,
            'state_version' => 2,
        ]);

        // 3. Reconcile / ensure review ready creates successor review item
        $readyRes = app(EnsureReviewReady::class)->ensure($this->case->id, $newCandId);
        $this->assertEquals('created', $readyRes['status']);

        $secState->refresh();
        $this->assertEquals('awaiting_review', $secState->state);
        $this->assertEquals($readyRes['data']['review_ref'], $secState->current_review_item_id);

        $newItem = TeacherReviewItem::find($readyRes['data']['review_ref']);
        $this->assertEquals('pending', $newItem->status);
        $this->assertEquals($newCandId, $newItem->candidate_id);
    }
}
