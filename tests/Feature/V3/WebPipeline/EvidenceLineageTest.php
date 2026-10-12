<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\AuthoringRequest;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\CodingAttempt;
use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\MapActivation;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\PipelineCommand;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\ReviewRoute;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\Admin\AssessDeletion;
use App\Services\V3\Evidence\EvidenceProjector;
use App\Services\V3\Evidence\MetricProjector;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class EvidenceLineageTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected User $admin;
    protected Subject $subject;
    protected SubjectRun $run;
    protected SettingsVersion $settingsVersion;
    protected NotebookFrameworkVersion $frameworkVersion;
    protected Document $document;
    protected ProcessingCase $case;
    protected KnowledgeSourceBinding $binding;
    protected LessonMap $lessonMap;
    protected TeacherSection $section;
    protected ScenePromptVersion $promptVersion;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Lineage');
        $this->admin = AccessFixtures::createEnrolledAdmin('Admin Lineage');

        $this->subject = Subject::forceCreate([
            'name' => 'Physics Mechanics',
            'description' => 'Physics curriculum subject',
        ]);

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
        $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

        $this->settingsVersion = SettingsVersion::create([
            'version' => 1,
            'schema_version' => 1,
            'payload' => $canonical,
            'checksum' => $checksum,
            'published_by_user_id' => $this->admin->id,
            'published_at' => now(),
        ]);

        $frameworkBytes = file_get_contents(base_path('steps.txt'));
        $frameworkSha256 = hash('sha256', $frameworkBytes);
        Storage::disk('v3_private')->put('frameworks/steps.txt', $frameworkBytes);

        $this->frameworkVersion = NotebookFrameworkVersion::create([
            'id' => (string) Str::uuid(),
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/steps.txt',
            'body_sha256' => $frameworkSha256,
            'body_bytes' => strlen($frameworkBytes),
            'first_sha256' => hash('sha256', 'first-slice'),
            'second_sha256' => hash('sha256', 'second-slice'),
            'third_sha256' => hash('sha256', 'third-slice'),
            'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
        ]);

        $this->run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'origin' => 'web',
            'protocol' => 'notebooklm_per_pdf_web_v1',
            'settings_version_id' => $this->settingsVersion->id,
            'framework_version_id' => $this->frameworkVersion->id,
            'mode' => 'export_manual',
            'qualification_mode' => 'owner_waived_demo',
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'run1'),
            'captured_settings_json' => json_encode(['mode' => 'export_manual']),
            'capture_sha256' => hash('sha256', json_encode(['mode' => 'export_manual'])),
            'state' => 'prompts_ready',
            'state_version' => 1,
        ]);

        $docContent = "%PDF-1.4 evidence test content";
        $docSha256 = hash('sha256', $docContent);
        $docPath = 'accepted/evidence-test.pdf';
        Storage::disk('v3_private')->put($docPath, $docContent);

        $this->document = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'sha256' => $docSha256,
            'byte_size' => strlen($docContent),
            'page_count' => 12,
            'state' => 'ready',
            'storage_path' => $docPath,
            'accepted_at' => now(),
        ]);

        $this->case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'document_id' => $this->document->id,
            'settings_version_id' => $this->settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'pipeline_version' => 'notebooklm_combined_v1',
            'notebook_framework_version_id' => $this->frameworkVersion->id,
            'state' => 'running',
            'state_version' => 1,
        ]);

        $this->binding = KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'source_mode' => 'original_pdf',
            'adapter_kind' => 'notebooklm_py',
            'adapter_version' => '0.8.4',
            'notebook_title' => "v3-case-{$this->case->id}",
            'notebook_id' => 'nb-evidence-lineage-1',
            'state' => 'bound',
            'document_id' => $this->document->id,
            'framework_version_id' => $this->frameworkVersion->id,
            'chat_configuration_state' => 'configured',
            'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
        ]);

        $activation = MapActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'binding_id' => $this->binding->id,
            'workflow_id' => "v3-case-{$this->case->id}",
            'state' => 'accepted',
        ]);

        $this->lessonMap = LessonMap::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'binding_id' => $this->binding->id,
            'activation_id' => $activation->id,
            'state' => 'ready',
            'coverage_mode' => 'not_requested_direct_pdf',
            'page_count' => 12,
            'work_count' => 1,
        ]);

        $lessonNode = LessonNode::forceCreate([
            'id' => (string) Str::uuid(),
            'map_id' => $this->lessonMap->id,
            'work_ordinal' => 1,
            'position_path' => '001.001',
            'ordinal_in_parent' => 1,
            'kind' => 'lesson',
            'title' => 'Linear Equations',
        ]);

        $q = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert([
            'id' => $q,
            'case_id' => $this->case->id,
            'kind' => 'divide',
            'state' => 'recorded',
            'target_work_ordinal' => 1,
            'send_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->section = TeacherSection::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $this->lessonMap->id,
            'work_node_id' => $lessonNode->id,
            'query_id' => $q,
            'ordinal_in_work' => 1,
            'section_identity' => 'Linear Equations Section',
            'core_understanding' => 'Linear equations represent lines with constant slope.',
            'first_prompt_sha256' => hash('sha256', 'f'),
            'answer_sha256' => hash('sha256', 'a'),
            'origin' => 'pipeline',
            'created_at' => now(),
        ]);

        $promptBody = 'Build an interactive 3D coordinate system showing a linear slope.';
        $promptSha = hash('sha256', $promptBody);
        $bodyPath = "prompt-versions/{$this->case->id}/{$this->section->id}/v1_{$promptSha}.txt";
        Storage::disk('v3_private')->put($bodyPath, $promptBody);

        $wi = PromptWorkItem::create([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $this->lessonMap->id,
            'work_node_id' => $lessonNode->id,
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
            'origin' => 'pipeline_generated',
            'body_path' => $bodyPath,
            'body_sha256' => $promptSha,
            'body_bytes' => strlen($promptBody),
            'scene_type' => 'simulation',
            'section_fields_sha256' => hash('sha256', 'fields'),
            'canonical_third_sha256' => hash('sha256', 'third-canonical'),
            'created_at' => now(),
        ]);

        PromptSectionState::forceCreate([
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'current_prompt_version_id' => $this->promptVersion->id,
            'status' => 'prompts_ready',
        ]);
    }

    public function test_manual_import_approval_graph_has_intentional_absence_of_api_and_jev_evidence_without_artificial_gaps(): void
    {
        $cmdId = (string) Str::uuid();
        PipelineCommand::forceCreate([
            'id' => $cmdId,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'import_manual_scene',
            'request_sha256' => hash('sha256', 'import_cmd'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);

        // Candidate manifest on disk
        $manifest = json_encode(['schema_version' => 2, 'entrypoint' => 'index.html']);
        $manifestSha = hash('sha256', $manifest);
        $manifestPath = "cases/{$this->case->id}/candidates/manual_cand_1/manifest.json";
        Storage::disk('v3_private')->put($manifestPath, $manifest);

        $manualImportId = (string) Str::uuid();
        $candidateId = (string) Str::uuid();

        // 1. Insert manual scene import first with null candidate_id
        DB::table('v3_manual_scene_imports')->insert([
            'id' => $manualImportId,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'actor_user_id' => $this->teacher->id,
            'command_id' => $cmdId,
            'package_sha256' => $manifestSha,
            'private_source_ref' => 'private/manual.json',
            'user_reported_provider' => 'handcrafted',
            'user_reported_model' => 'threejs-r128',
            'candidate_id' => null,
            'created_at' => now(),
        ]);

        // 2. Insert candidate referencing manual_import_id
        $candidate = SceneCandidate::forceCreate([
            'id' => $candidateId,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'origin' => 'manual_import',
            'package_profile' => 'static_three_file_v1',
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestSha,
            'entrypoint' => 'index.html',
            'file_count' => 3,
            'total_bytes' => 1024,
            'manual_import_id' => $manualImportId,
            'collected_at' => now(),
        ]);

        // 3. Connect candidate_id in manual import
        DB::table('v3_manual_scene_imports')->where('id', $manualImportId)->update(['candidate_id' => $candidateId]);

        // Browser check run
        $reportJson = json_encode(['candidate_ref' => $candidate->id, 'verdict' => 'pass']);
        $reportPath = "scene-checks/{$this->case->id}/attempt_1/report.json";
        Storage::disk('v3_private')->put($reportPath, $reportJson);

        $checkAttempt = SceneCheckAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'case_id' => $this->case->id,
            'attempt_no' => 1,
            'manifest_sha256' => $candidate->manifest_sha256,
            'request_sha256' => hash('sha256', 'req_manual_check'),
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'state' => 'recorded',
            'created_at' => now(),
        ]);

        $checkRun = SceneCheckRun::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'winning_attempt_id' => $checkAttempt->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'verdict' => 'pass',
            'failed_checks' => [],
            'duration_ms' => 450,
            'manifest_sha256' => $candidate->manifest_sha256,
            'report_path' => $reportPath,
            'report_sha256' => hash('sha256', $reportJson),
            'screenshot_path' => "screens/{$candidate->id}.png",
            'screenshot_sha256' => hash('sha256', 'shot'),
            'created_at' => now(),
        ]);

        // Scene waiver gate
        $sceneGateId = (string) Str::uuid();
        DB::table('v3_qualification_gates')->insert([
            'id' => $sceneGateId,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => $candidate->id,
            'gate_stage' => 'scene',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'spec_019_web_demo_v1',
            'policy_sha256' => hash('sha256', 'policy'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'candidate_manifest_sha256' => $manifestSha,
            'missing_fields_json' => json_encode([]),
            'command_id' => $cmdId,
            'created_at' => now(),
        ]);

        SceneCandidateState::forceCreate([
            'case_id' => $this->case->id,
            'candidate_id' => $candidate->id,
            'root_candidate_id' => $candidate->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'status' => 'demo_review_ready',
            'version_no' => 1,
            'check_run_id' => $checkRun->id,
        ]);

        // Review item, decision, approved scene
        $reviewItem = TeacherReviewItem::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candidate->id,
            'prompt_version_id' => $this->promptVersion->id,
            'qualification_id' => null,
            'qualification_gate_id' => $sceneGateId,
            'eligibility_basis' => 'owner_waived_demo',
            'candidate_version' => 1,
            'status' => 'decided',
            'state_version' => 2,
            'ready_at' => now(),
            'decided_at' => now(),
        ]);

        $decision = TeacherReviewDecision::forceCreate([
            'id' => (string) Str::uuid(),
            'review_item_id' => $reviewItem->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candidate->id,
            'actor_user_id' => $this->teacher->id,
            'candidate_version' => 1,
            'expected_review_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'approve'),
            'decision' => 'approve',
            'feedback_kind' => null,
            'eligibility_basis' => 'owner_waived_demo',
            'qualification_gate_id' => $sceneGateId,
            'created_at' => now(),
        ]);

        $approvedScene = ApprovedScene::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candidate->id,
            'qualification_id' => null,
            'qualification_gate_id' => $sceneGateId,
            'eligibility_basis' => 'owner_waived_demo',
            'review_decision_id' => $decision->id,
            'candidate_version' => 1,
            'approved_at' => now(),
        ]);

        ReviewSectionState::forceCreate([
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'current_approval_id' => $approvedScene->id,
            'current_review_item_id' => null,
            'state' => 'approved',
            'state_version' => 2,
        ]);

        // Project Approval Graph
        $projector = app(EvidenceProjector::class);
        $graph = $projector->projectApprovalGraph($this->case, $approvedScene);

        $this->assertNotNull($graph);
        $this->assertSame('complete', $graph['integrity'], 'Manual import graph must be complete with zero gaps');
        $this->assertEmpty($graph['gaps'], 'No artificial gaps should be produced for manual import');
        $this->assertTrue($graph['current']);

        $nodeKinds = array_column($graph['nodes'], 'kind');
        $this->assertContains('approved_scene', $nodeKinds);
        $this->assertContains('teacher_review_decision', $nodeKinds);
        $this->assertContains('teacher_review_item', $nodeKinds);
        $this->assertContains('qualification_gate', $nodeKinds);
        $this->assertContains('scene_check_run', $nodeKinds);
        $this->assertContains('scene_candidate', $nodeKinds);
        $this->assertContains('manual_scene_import', $nodeKinds);
        $this->assertContains('prompt_version', $nodeKinds);
        $this->assertContains('teacher_section', $nodeKinds);

        // Crucial invariant: No authoring_request or coding_attempt nodes
        $this->assertNotContains('authoring_request', $nodeKinds, 'Manual import must never invent authoring_request');
        $this->assertNotContains('coding_attempt', $nodeKinds, 'Manual import must never invent coding_attempt');
        $this->assertNotContains('prompt_qualification', $nodeKinds, 'Manual import has no prompt qualification');
        $this->assertNotContains('scene_qualification', $nodeKinds, 'Demo manual import has no scene qualification');
    }

    public function test_demo_waiver_api_generated_graph_preserves_prompt_and_scene_gates_without_fake_jev(): void
    {
        $cmdId = (string) Str::uuid();
        PipelineCommand::forceCreate([
            'id' => $cmdId,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'claim_authoring_request',
            'request_sha256' => hash('sha256', 'claim_cmd'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);

        // Prompt qualification gate
        $promptGateId = (string) Str::uuid();
        DB::table('v3_qualification_gates')->insert([
            'id' => $promptGateId,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => null,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'spec_019_web_demo_v1',
            'policy_sha256' => hash('sha256', 'policy'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'candidate_manifest_sha256' => null,
            'missing_fields_json' => json_encode([]),
            'command_id' => $cmdId,
            'created_at' => now(),
        ]);

        // Authoring request with demo eligibility
        $authRequest = AuthoringRequest::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'qualification_id' => null,
            'eligibility_basis' => 'owner_waived_demo',
            'prompt_gate_id' => $promptGateId,
            'prompt_body_sha256' => $this->promptVersion->body_sha256,
            'settings_checksum' => $this->settingsVersion->checksum,
            'template_sha256' => hash('sha256', 'tmpl'),
            'settings_version_id' => $this->settingsVersion->id,
            'template_id' => 'tmpl-scene-v1',
            'origin' => 'initial',
            'state' => 'pending',
            'state_version' => 1,
            'attempt_limit' => 3,
            'created_at' => now(),
        ]);

        $attempt = CodingAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'request_id' => $authRequest->id,
            'case_id' => $this->case->id,
            'ordinal' => 1,
            'provider_id' => 'gemini_api',
            'requested_model' => 'gemini-2.5-flash',
            'credential_ref' => 'gemini_default',
            'state' => 'candidate_ready',
            'created_at' => now(),
        ]);

        // Candidate manifest on disk
        $manifest = json_encode(['schema_version' => 2, 'entrypoint' => 'index.html']);
        $manifestSha = hash('sha256', $manifest);
        $manifestPath = "cases/{$this->case->id}/candidates/api_cand_1/manifest.json";
        Storage::disk('v3_private')->put($manifestPath, $manifest);

        $candidate = SceneCandidate::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'origin' => 'provider_generated',
            'package_profile' => 'static_three_file_v1',
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestSha,
            'entrypoint' => 'index.html',
            'file_count' => 3,
            'total_bytes' => 1024,
            'request_id' => $authRequest->id,
            'attempt_id' => $attempt->id,
            'prompt_gate_id' => $promptGateId,
            'collected_at' => now(),
        ]);

        // Scene check run
        $reportJson = json_encode(['candidate_ref' => $candidate->id, 'verdict' => 'pass']);
        $reportPath = "scene-checks/{$this->case->id}/attempt_api/report.json";
        Storage::disk('v3_private')->put($reportPath, $reportJson);

        $checkAttempt = SceneCheckAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'case_id' => $this->case->id,
            'attempt_no' => 1,
            'manifest_sha256' => $candidate->manifest_sha256,
            'request_sha256' => hash('sha256', 'req_api_check'),
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'state' => 'recorded',
            'created_at' => now(),
        ]);

        $checkRun = SceneCheckRun::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'winning_attempt_id' => $checkAttempt->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'verdict' => 'pass',
            'failed_checks' => [],
            'duration_ms' => 600,
            'manifest_sha256' => $candidate->manifest_sha256,
            'report_path' => $reportPath,
            'report_sha256' => hash('sha256', $reportJson),
            'screenshot_path' => "screens/{$candidate->id}.png",
            'screenshot_sha256' => hash('sha256', 'shot'),
            'created_at' => now(),
        ]);

        // Scene waiver gate
        $sceneGateId = (string) Str::uuid();
        DB::table('v3_qualification_gates')->insert([
            'id' => $sceneGateId,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => $candidate->id,
            'gate_stage' => 'scene',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'spec_019_web_demo_v1',
            'policy_sha256' => hash('sha256', 'policy'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'candidate_manifest_sha256' => $manifestSha,
            'missing_fields_json' => json_encode([]),
            'command_id' => $cmdId,
            'created_at' => now(),
        ]);

        SceneCandidateState::forceCreate([
            'case_id' => $this->case->id,
            'candidate_id' => $candidate->id,
            'root_candidate_id' => $candidate->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'status' => 'demo_review_ready',
            'version_no' => 1,
            'check_run_id' => $checkRun->id,
        ]);

        // Review item, decision, approved scene
        $reviewItem = TeacherReviewItem::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candidate->id,
            'prompt_version_id' => $this->promptVersion->id,
            'qualification_id' => null,
            'qualification_gate_id' => $sceneGateId,
            'eligibility_basis' => 'owner_waived_demo',
            'candidate_version' => 1,
            'status' => 'decided',
            'state_version' => 2,
            'ready_at' => now(),
            'decided_at' => now(),
        ]);

        $decision = TeacherReviewDecision::forceCreate([
            'id' => (string) Str::uuid(),
            'review_item_id' => $reviewItem->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candidate->id,
            'actor_user_id' => $this->teacher->id,
            'candidate_version' => 1,
            'expected_review_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'approve'),
            'decision' => 'approve',
            'feedback_kind' => null,
            'eligibility_basis' => 'owner_waived_demo',
            'qualification_gate_id' => $sceneGateId,
            'created_at' => now(),
        ]);

        $approvedScene = ApprovedScene::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candidate->id,
            'qualification_id' => null,
            'qualification_gate_id' => $sceneGateId,
            'eligibility_basis' => 'owner_waived_demo',
            'review_decision_id' => $decision->id,
            'candidate_version' => 1,
            'approved_at' => now(),
        ]);

        ReviewSectionState::forceCreate([
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'current_approval_id' => $approvedScene->id,
            'current_review_item_id' => null,
            'state' => 'approved',
            'state_version' => 2,
        ]);

        $projector = app(EvidenceProjector::class);
        $graph = $projector->projectApprovalGraph($this->case, $approvedScene);

        $this->assertNotNull($graph);
        $this->assertSame('complete', $graph['integrity']);
        $this->assertEmpty($graph['gaps']);

        $nodeKinds = array_column($graph['nodes'], 'kind');
        $this->assertContains('authoring_request', $nodeKinds);
        $this->assertContains('coding_attempt', $nodeKinds);
        $this->assertContains('qualification_gate', $nodeKinds);
        $this->assertNotContains('prompt_qualification', $nodeKinds);
        $this->assertNotContains('scene_qualification', $nodeKinds);
    }

    public function test_current_versus_historical_approval_distinction(): void
    {
        $cmdId = (string) Str::uuid();
        PipelineCommand::forceCreate([
            'id' => $cmdId,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'import_manual_scene',
            'request_sha256' => hash('sha256', 'import_distinction'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);

        // Manifest on disk
        $manifest = json_encode(['schema_version' => 2]);
        $manifestSha = hash('sha256', $manifest);
        $path1 = "cases/{$this->case->id}/cand_1/manifest.json";
        $path2 = "cases/{$this->case->id}/cand_2/manifest.json";
        Storage::disk('v3_private')->put($path1, $manifest);
        Storage::disk('v3_private')->put($path2, $manifest);

        $cand1 = SceneCandidate::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'origin' => 'manual_import',
            'package_profile' => 'static_three_file_v1',
            'manifest_path' => $path1,
            'manifest_sha256' => $manifestSha,
            'entrypoint' => 'index.html',
            'file_count' => 3,
            'total_bytes' => 1024,
            'collected_at' => now()->subHour(),
        ]);

        $cand2 = SceneCandidate::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'origin' => 'manual_import',
            'package_profile' => 'static_three_file_v1',
            'manifest_path' => $path2,
            'manifest_sha256' => $manifestSha,
            'entrypoint' => 'index.html',
            'file_count' => 3,
            'total_bytes' => 1024,
            'collected_at' => now(),
        ]);

        $attempt1 = SceneCheckAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $cand1->id,
            'case_id' => $this->case->id,
            'attempt_no' => 1,
            'manifest_sha256' => $cand1->manifest_sha256,
            'request_sha256' => hash('sha256', 'req_cand1'),
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'state' => 'recorded',
            'created_at' => now()->subHour(),
        ]);

        SceneCheckRun::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $cand1->id,
            'winning_attempt_id' => $attempt1->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'verdict' => 'pass',
            'failed_checks' => [],
            'duration_ms' => 300,
            'manifest_sha256' => $cand1->manifest_sha256,
            'report_path' => 'reports/1.json',
            'report_sha256' => hash('sha256', 'rep1'),
            'screenshot_path' => "screens/{$cand1->id}.png",
            'screenshot_sha256' => hash('sha256', 'shot1'),
            'created_at' => now()->subHour(),
        ]);

        $attempt2 = SceneCheckAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $cand2->id,
            'case_id' => $this->case->id,
            'attempt_no' => 1,
            'manifest_sha256' => $cand2->manifest_sha256,
            'request_sha256' => hash('sha256', 'req_cand2'),
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'state' => 'recorded',
            'created_at' => now(),
        ]);

        SceneCheckRun::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $cand2->id,
            'winning_attempt_id' => $attempt2->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'verdict' => 'pass',
            'failed_checks' => [],
            'duration_ms' => 300,
            'manifest_sha256' => $cand2->manifest_sha256,
            'report_path' => 'reports/2.json',
            'report_sha256' => hash('sha256', 'rep2'),
            'screenshot_path' => "screens/{$cand2->id}.png",
            'screenshot_sha256' => hash('sha256', 'shot2'),
            'created_at' => now(),
        ]);

        $gate1 = (string) Str::uuid();
        DB::table('v3_qualification_gates')->insert([
            'id' => $gate1,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => $cand1->id,
            'gate_stage' => 'scene',
            'basis' => 'owner_waived_demo',
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'spec_019_web_demo_v1',
            'policy_sha256' => hash('sha256', 'p1'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'missing_fields_json' => '[]',
            'command_id' => $cmdId,
            'created_at' => now()->subHour(),
        ]);

        $gate2 = (string) Str::uuid();
        DB::table('v3_qualification_gates')->insert([
            'id' => $gate2,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => $cand2->id,
            'gate_stage' => 'scene',
            'basis' => 'owner_waived_demo',
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'spec_019_web_demo_v1',
            'policy_sha256' => hash('sha256', 'p2'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'missing_fields_json' => '[]',
            'command_id' => $cmdId,
            'created_at' => now(),
        ]);

        $item1 = TeacherReviewItem::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $cand1->id,
            'prompt_version_id' => $this->promptVersion->id,
            'qualification_id' => null,
            'qualification_gate_id' => $gate1,
            'eligibility_basis' => 'owner_waived_demo',
            'candidate_version' => 1,
            'status' => 'decided',
            'state_version' => 2,
            'ready_at' => now()->subHour(),
        ]);

        $dec1 = TeacherReviewDecision::forceCreate([
            'id' => (string) Str::uuid(),
            'review_item_id' => $item1->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $cand1->id,
            'actor_user_id' => $this->teacher->id,
            'candidate_version' => 1,
            'expected_review_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', '1'),
            'decision' => 'approve',
            'created_at' => now()->subHour(),
        ]);

        $appr1 = ApprovedScene::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $cand1->id,
            'qualification_id' => null,
            'qualification_gate_id' => $gate1,
            'eligibility_basis' => 'owner_waived_demo',
            'review_decision_id' => $dec1->id,
            'candidate_version' => 1,
            'approved_at' => now()->subHour(),
        ]);

        $item2 = TeacherReviewItem::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $cand2->id,
            'prompt_version_id' => $this->promptVersion->id,
            'qualification_id' => null,
            'qualification_gate_id' => $gate2,
            'eligibility_basis' => 'owner_waived_demo',
            'candidate_version' => 2,
            'status' => 'decided',
            'state_version' => 2,
            'ready_at' => now(),
        ]);

        $dec2 = TeacherReviewDecision::forceCreate([
            'id' => (string) Str::uuid(),
            'review_item_id' => $item2->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $cand2->id,
            'actor_user_id' => $this->teacher->id,
            'candidate_version' => 2,
            'expected_review_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', '2'),
            'decision' => 'approve',
            'created_at' => now(),
        ]);

        $appr2 = ApprovedScene::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $cand2->id,
            'qualification_id' => null,
            'qualification_gate_id' => $gate2,
            'eligibility_basis' => 'owner_waived_demo',
            'review_decision_id' => $dec2->id,
            'candidate_version' => 2,
            'approved_at' => now(),
        ]);

        // Current pointer points to appr2
        ReviewSectionState::forceCreate([
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'current_approval_id' => $appr2->id,
            'current_review_item_id' => null,
            'state' => 'approved',
            'state_version' => 3,
        ]);

        $projector = app(EvidenceProjector::class);

        // Project appr2 (current)
        $graphCurrent = $projector->projectApprovalGraph($this->case, $appr2);
        $this->assertTrue($graphCurrent['current']);
        $apprNodeCurrent = collect($graphCurrent['nodes'])->firstWhere('ref', (string) $appr2->id);
        $this->assertSame('current', $apprNodeCurrent['state']);
        $this->assertContains((string) $appr2->id, $graphCurrent['current_approval_refs']);
        $this->assertContains((string) $appr1->id, $graphCurrent['historical_approval_refs']);

        // Project appr1 (historical)
        $graphHistorical = $projector->projectApprovalGraph($this->case, $appr1);
        $this->assertFalse($graphHistorical['current']);
        $apprNodeHist = collect($graphHistorical['nodes'])->firstWhere('ref', (string) $appr1->id);
        $this->assertSame('historical', $apprNodeHist['state']);
    }

    public function test_assess_deletion_blocked_on_unresolved_review_effects(): void
    {
        $this->case->forceFill(['state' => 'complete'])->save();

        // Create an operation control record
        CaseOperationControl::forceCreate([
            'case_id' => $this->case->id,
            'mode' => 'normal',
            'control_version' => 1,
        ]);

        $candidate = SceneCandidate::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'origin' => 'manual_import',
            'package_profile' => 'static_three_file_v1',
            'manifest_path' => 'path/del.json',
            'manifest_sha256' => hash('sha256', 'del'),
            'entrypoint' => 'index.html',
            'file_count' => 3,
            'total_bytes' => 1024,
            'collected_at' => now(),
        ]);

        $item = TeacherReviewItem::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candidate->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_version' => 1,
            'status' => 'decided',
            'state_version' => 2,
            'ready_at' => now(),
        ]);

        // Create a review decision
        $decision = TeacherReviewDecision::forceCreate([
            'id' => (string) Str::uuid(),
            'review_item_id' => $item->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candidate->id,
            'actor_user_id' => $this->teacher->id,
            'candidate_version' => 1,
            'expected_review_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'req'),
            'decision' => 'request_changes',
            'created_at' => now(),
        ]);

        // Unresolved review outbox entry
        $outbox = ReviewOutbox::forceCreate([
            'id' => (string) Str::uuid(),
            'review_decision_id' => $decision->id,
            'postapproval_correction_id' => null,
            'operation' => 'signal_decision',
            'workflow_id' => "v3-case-{$this->case->id}",
            'state' => 'pending',
            'attempt_count' => 0,
            'created_at' => now(),
        ]);

        $assessor = app(AssessDeletion::class);
        $input = [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 1,
            'expected_control_version' => 1,
            'reason_code' => \App\Models\V3\DeletionAssessment::REASON_CODE,
        ];

        $res = $assessor->assess($this->admin, (string) $this->case->id, $input);
        $this->assertSame('ineligible', $res['assessment']->status);
        $this->assertSame('effect_uncertain', $res['assessment']->safe_blocker, 'Pending outbox must block deletion with effect_uncertain');

        // Mark outbox sent/delivered
        $outbox->update(['state' => 'delivered', 'delivered_at' => now()]);

        // Add an open review route
        ReviewRoute::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'review_decision_id' => $decision->id,
            'postapproval_correction_id' => null,
            'kind' => 'scene_repair',
            'state' => 'pending',
            'target_ref' => null,
        ]);

        $input2 = [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 1,
            'expected_control_version' => 1,
            'reason_code' => \App\Models\V3\DeletionAssessment::REASON_CODE,
        ];

        $res2 = $assessor->assess($this->admin, (string) $this->case->id, $input2);
        $this->assertSame('ineligible', $res2['assessment']->status);
        $this->assertSame('review_pending', $res2['assessment']->safe_blocker, 'Open route must block deletion with review_pending');
    }

    public function test_metric_projector_separates_real_demo_and_imported_counts(): void
    {
        // Candidate 1: manual import
        $candManual = SceneCandidate::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'origin' => 'manual_import',
            'package_profile' => 'static_three_file_v1',
            'manifest_path' => 'path/manual.json',
            'manifest_sha256' => hash('sha256', 'm'),
            'entrypoint' => 'index.html',
            'file_count' => 3,
            'total_bytes' => 1024,
            'collected_at' => now(),
        ]);

        // Candidate 2: provider generated demo
        $candGen = SceneCandidate::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'origin' => 'provider_generated',
            'package_profile' => 'static_three_file_v1',
            'manifest_path' => 'path/gen.json',
            'manifest_sha256' => hash('sha256', 'g'),
            'entrypoint' => 'index.html',
            'file_count' => 3,
            'total_bytes' => 1024,
            'collected_at' => now(),
        ]);

        $cmdGate = PipelineCommand::forceCreate([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'record_qualification_gate',
            'request_sha256' => hash('sha256', 'gate_cmd_metric'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);

        // Demo gate for candidate 1
        DB::table('v3_qualification_gates')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => $candManual->id,
            'gate_stage' => 'scene',
            'basis' => 'owner_waived_demo',
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'v1',
            'policy_sha256' => hash('sha256', 'p'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'missing_fields_json' => '[]',
            'command_id' => $cmdGate->id,
            'created_at' => now(),
        ]);

        $itemMetric = TeacherReviewItem::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candManual->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_version' => 1,
            'status' => 'decided',
            'state_version' => 2,
            'ready_at' => now(),
        ]);

        $decisionMetric = TeacherReviewDecision::forceCreate([
            'id' => (string) Str::uuid(),
            'review_item_id' => $itemMetric->id,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candManual->id,
            'actor_user_id' => $this->teacher->id,
            'candidate_version' => 1,
            'expected_review_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'decision_metric'),
            'decision' => 'approve',
            'created_at' => now(),
        ]);

        // Approved scene for manual demo candidate
        ApprovedScene::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'candidate_id' => $candManual->id,
            'qualification_id' => null,
            'eligibility_basis' => 'owner_waived_demo',
            'review_decision_id' => $decisionMetric->id,
            'candidate_version' => 1,
            'approved_at' => now(),
        ]);

        $projector = app(MetricProjector::class);
        $from = now()->subDays(1);
        $to = now()->addDays(1);

        $metrics = $projector->projectAdminMetrics($from, $to);

        $this->assertArrayHasKey('real_qualified_count', $metrics);
        $this->assertArrayHasKey('demo_reviewed_count', $metrics);
        $this->assertArrayHasKey('generated_scene_count', $metrics);
        $this->assertArrayHasKey('imported_scene_count', $metrics);
        $this->assertArrayHasKey('real_approved_scene_count', $metrics);
        $this->assertArrayHasKey('demo_approved_scene_count', $metrics);

        $this->assertSame(1, $metrics['imported_scene_count']);
        $this->assertSame(1, $metrics['generated_scene_count']);
        $this->assertSame(1, $metrics['demo_reviewed_count']);
        $this->assertSame(0, $metrics['real_qualified_count']);
        $this->assertSame(1, $metrics['demo_approved_scene_count']);
        $this->assertSame(0, $metrics['real_approved_scene_count']);
    }
}
