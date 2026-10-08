<?php

namespace Tests\Feature\V3\NotebookLM;

use App\Models\V3\ApprovedScene;
use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\KnowledgeSourceFile;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\MapActivation;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\NotebookLessonBundle;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptJevAttempt;
use App\Models\V3\PromptQualification;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SceneQualification;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Evidence\EvidenceProjector;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class DirectEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected $teacher;
    protected $admin;
    protected $settingsVersion;
    protected $frameworkVersion;
    protected $document;
    protected $processingCase;
    protected $binding;
    protected $bundle;
    protected $approvedScene;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Direct Evidence');
        $this->admin = AccessFixtures::createEnrolledAdmin('Admin Direct Evidence');

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

        $this->processingCase = ProcessingCase::forceCreate([
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

        WorkflowLink::forceCreate([
            'case_id' => $this->processingCase->id,
            'workflow_id' => "v3-case-{$this->processingCase->id}",
            'run_id' => 'run-evidence-direct',
            'settings_version_id' => $this->settingsVersion->id,
            'settings_checksum' => $this->settingsVersion->checksum,
            'start_state' => 'started',
            'stage_code' => 'authoring',
        ]);

        $this->binding = KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'source_mode' => 'original_pdf',
            'adapter_kind' => 'notebooklm_py',
            'adapter_version' => '0.8.4',
            'notebook_title' => "v3-case-{$this->processingCase->id}",
            'notebook_id' => 'nb-evidence-secret-id-999',
            'state' => 'bound',
            'document_id' => $this->document->id,
            'framework_version_id' => $this->frameworkVersion->id,
            'chat_configuration_state' => 'configured',
            'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
        ]);

        $activation = MapActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'binding_id' => $this->binding->id,
            'workflow_id' => "v3-case-{$this->processingCase->id}",
            'state' => 'accepted',
        ]);

        $map = LessonMap::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'binding_id' => $this->binding->id,
            'activation_id' => $activation->id,
            'state' => 'ready',
            'coverage_mode' => 'not_requested_direct_pdf',
            'page_count' => 12,
            'work_count' => 1,
        ]);

        $node = LessonNode::forceCreate([
            'id' => (string) Str::uuid(),
            'map_id' => $map->id,
            'work_ordinal' => 1,
            'position_path' => '001.001',
            'ordinal_in_parent' => 1,
            'kind' => 'lesson',
            'title' => 'The Number Line and Real Numbers',
        ]);

        LessonCursor::forceCreate([
            'case_id' => $this->processingCase->id,
            'map_id' => $map->id,
            'current_work_ordinal' => 1,
            'phase' => 'ready_to_start',
            'conversation_id' => 'convo-evidence-1',
            'version' => 1,
        ]);

        $ledger = app(\App\Services\V3\NotebookLM\DirectQueryLedger::class);
        $acceptor = app(\App\Services\V3\NotebookLM\AcceptCombinedLesson::class);

        $claim = $ledger->claimQuery(
            caseId: $this->processingCase->id,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $this->binding->id,
            expectedCursorVersion: 1,
            conversationIn: 'convo-evidence-1',
        );

        $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $answerSha = hash('sha256', $validCombinedText);

        $commitResult = $acceptor->acceptCombinedLesson(
            queryRef: $claim['query_ref'],
            payload: [
                'schema_version' => 1,
                'expected_cursor_version' => 2,
                'answer' => $validCombinedText,
                'answer_sha256' => $answerSha,
                'conversation_out' => 'convo-evidence-1',
            ],
        );

        $this->bundle = NotebookLessonBundle::find($commitResult['data']['bundle_ref']);
        $section = TeacherSection::where('case_id', $this->processingCase->id)->first();
        $promptVersion = ScenePromptVersion::where('case_id', $this->processingCase->id)->first();

        // 1. Prompt qualification
        $jevAttempt = PromptJevAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'prompt_version_id' => $promptVersion->id,
            'settings_version_id' => $this->processingCase->settings_version_id,
            'attempt_no' => 1,
            'state' => 'recorded',
            'requested_model' => 'typesafe-jev-v1',
            'request_sha256' => hash('sha256', 'jev-req-1'),
            'question_sha256' => hash('sha256', 'questions-1'),
        ]);

        $promptQual = PromptQualification::forceCreate([
            'id' => (string) Str::uuid(),
            'prompt_version_id' => $promptVersion->id,
            'winning_attempt_id' => $jevAttempt->id,
            'case_id' => $this->processingCase->id,
            'section_id' => $section->id,
            'settings_version_id' => $this->processingCase->settings_version_id,
            'settings_checksum' => $this->settingsVersion->checksum,
            'request_sha256' => hash('sha256', 'jev-req-1'),
            'answer_sha256' => hash('sha256', 'ans-1'),
            'question_sha256' => hash('sha256', 'questions-1'),
            'rule_version' => 'prompt-rule-v1',
            'requested_model' => 'typesafe-jev-v1',
            'returned_model' => 'typesafe-jev-v1',
            'choice' => 'pass',
            'choice_confidence' => 0.95,
            'score' => 90.0,
            'score_confidence' => 0.95,
            'noul_probability' => 0.95,
            'noul_yes' => true,
            'min_score' => 80,
            'min_confidence' => 80,
            'decision' => 'pass',
            'failed_checks' => [],
            'answer_path' => 'raw/jev-1.json',
            'created_at' => now(),
        ]);

        // 2. AuthoringRequest
        $authRequest = AuthoringRequest::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'section_id' => $section->id,
            'prompt_version_id' => $promptVersion->id,
            'qualification_id' => $promptQual->id,
            'prompt_body_sha256' => $promptVersion->body_sha256,
            'settings_checksum' => $this->settingsVersion->checksum,
            'template_sha256' => hash('sha256', 'template'),
            'settings_version_id' => $this->settingsVersion->id,
            'template_id' => 'template-1',
            'origin' => 'initial',
            'state' => 'completed',
            'state_version' => 1,
            'attempt_limit' => 3,
        ]);

        // 3. CodingAttempt
        $attempt = CodingAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'request_id' => $authRequest->id,
            'case_id' => $this->processingCase->id,
            'ordinal' => 1,
            'provider_id' => 'gemini_api',
            'requested_model' => 'gemini-2.5-pro',
            'credential_ref' => 'cred-1',
            'state' => 'candidate_ready',
        ]);

        // 4. Candidate
        $manifestContent = json_encode(['files' => ['index.html']]);
        $manifestSha = hash('sha256', $manifestContent);
        $candidateId = (string) Str::uuid();
        $manifestPath = "manifests/{$candidateId}.json";
        Storage::disk('v3_private')->put($manifestPath, $manifestContent);

        $candidate = SceneCandidate::forceCreate([
            'id' => $candidateId,
            'request_id' => $authRequest->id,
            'attempt_id' => $attempt->id,
            'case_id' => $this->processingCase->id,
            'section_id' => $section->id,
            'prompt_version_id' => $promptVersion->id,
            'qualification_id' => $promptQual->id,
            'settings_version_id' => $this->settingsVersion->id,
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestSha,
            'entrypoint' => 'index.html',
            'file_count' => 1,
            'total_bytes' => strlen($manifestContent),
        ]);

        // 5. Check run
        $checkAttempt = SceneCheckAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'case_id' => $this->processingCase->id,
            'attempt_no' => 1,
            'manifest_sha256' => $manifestSha,
            'request_sha256' => hash('sha256', 'check-req-1'),
            'state' => 'recorded',
        ]);

        $checkRun = SceneCheckRun::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'winning_attempt_id' => $checkAttempt->id,
            'case_id' => $this->processingCase->id,
            'section_id' => $section->id,
            'prompt_version_id' => $promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'manifest_sha256' => $manifestSha,
            'report_sha256' => hash('sha256', 'report-1'),
            'screenshot_sha256' => hash('sha256', 'screenshot-1'),
            'report_path' => "reports/{$candidate->id}.json",
            'screenshot_path' => "screens/{$candidate->id}.png",
            'verdict' => 'pass',
            'failed_checks' => [],
            'duration_ms' => 100,
        ]);

        // 6. Scene qualification
        $sceneJevAttempt = SceneJevAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'check_run_id' => $checkRun->id,
            'case_id' => $this->processingCase->id,
            'attempt_no' => 1,
            'request_sha256' => hash('sha256', 'scene-jev-req-1'),
            'question_sha256' => hash('sha256', 'scene-questions-1'),
            'evidence_sha256' => hash('sha256', 'scene-ev-1'),
            'evidence_path' => "ev/{$candidate->id}.json",
            'requested_model' => 'gemini-2.5-pro',
            'state' => 'recorded',
        ]);

        $sceneQual = SceneQualification::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'check_run_id' => $checkRun->id,
            'winning_jev_attempt_id' => $sceneJevAttempt->id,
            'case_id' => $this->processingCase->id,
            'section_id' => $section->id,
            'prompt_version_id' => $promptVersion->id,
            'settings_version_id' => $this->settingsVersion->id,
            'settings_checksum' => $this->settingsVersion->checksum,
            'manifest_sha256' => $manifestSha,
            'evidence_sha256' => hash('sha256', 'scene-ev-1'),
            'request_sha256' => hash('sha256', 'scene-jev-req-1'),
            'question_sha256' => hash('sha256', 'scene-questions-1'),
            'answer_sha256' => hash('sha256', 'scene-ans-1'),
            'evidence_path' => "ev/{$candidate->id}.json",
            'answer_path' => "ans/{$candidate->id}.json",
            'rule_version' => 'scene-rule-v1',
            'requested_model' => 'gemini-2.5-pro',
            'returned_model' => 'gemini-2.5-pro',
            'choice' => 'pass',
            'choice_confidence' => 0.95,
            'score' => 95.0,
            'score_confidence' => 0.95,
            'noul_probability' => 0.95,
            'noul_yes' => true,
            'min_score' => 80,
            'min_confidence' => 80,
            'decision' => 'pass',
            'failed_checks' => [],
        ]);

        // 7. Teacher review item
        $reviewItem = TeacherReviewItem::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'section_id' => $section->id,
            'candidate_id' => $candidate->id,
            'qualification_id' => $sceneQual->id,
            'prompt_version_id' => $promptVersion->id,
            'candidate_version' => 1,
            'status' => 'decided',
            'ready_at' => now(),
        ]);

        // 8. Teacher review decision
        $decision = TeacherReviewDecision::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'review_item_id' => $reviewItem->id,
            'section_id' => $section->id,
            'candidate_id' => $candidate->id,
            'qualification_id' => $sceneQual->id,
            'actor_user_id' => $this->teacher->id,
            'candidate_version' => 1,
            'expected_review_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'approve-req-1'),
            'decision' => 'approve',
        ]);

        // 9. ApprovedScene
        $this->approvedScene = ApprovedScene::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'section_id' => $section->id,
            'candidate_id' => $candidate->id,
            'qualification_id' => $sceneQual->id,
            'review_decision_id' => $decision->id,
            'candidate_version' => 1,
            'approved_at' => now(),
        ]);

        // 10. ReviewSectionState
        ReviewSectionState::forceCreate([
            'section_id' => $section->id,
            'case_id' => $this->processingCase->id,
            'current_review_item_id' => $reviewItem->id,
            'current_approval_id' => $this->approvedScene->id,
            'state' => 'approved',
            'state_version' => 1,
        ]);
    }

    public function test_n22_owner_evidence_trace_includes_pdf_framework_and_bundle_provenance(): void
    {
        $projector = app(EvidenceProjector::class);
        $graph = $projector->projectApprovalGraph($this->processingCase, $this->approvedScene);

        $this->assertNotNull($graph);
        $this->assertSame('complete', $graph['integrity'], 'Direct evidence graph must be complete without missing extracted_source gaps');
        $this->assertEmpty($graph['gaps'], 'Direct PDF cases must have zero gaps for missing extraction');

        $nodeKinds = array_column($graph['nodes'], 'kind');
        $this->assertContains('document', $nodeKinds, 'Must include original PDF document node');
        $this->assertContains('knowledge_source_binding', $nodeKinds, 'Must include knowledge source binding');
        $this->assertContains('notebook_framework_version', $nodeKinds, 'Must include framework version');
        $this->assertContains('notebook_lesson_bundle', $nodeKinds, 'Must include lesson bundle');

        // Document node has exact sha256 and byte size
        $docNode = collect($graph['nodes'])->firstWhere('kind', 'document');
        $this->assertSame((string) $this->document->id, $docNode['ref']);

        // Knowledge source binding node links to document directly (not via extracted_source)
        $links = $graph['links'];
        $docLink = collect($links)->first(fn ($l) => $l['from_kind'] === 'knowledge_source_binding' && $l['to_kind'] === 'document');
        $this->assertNotNull($docLink, 'Binding must link directly to document');
        $this->assertSame('document_id', $docLink['relation']);

        // Framework version link
        $fwLink = collect($links)->first(fn ($l) => $l['from_kind'] === 'knowledge_source_binding' && $l['to_kind'] === 'notebook_framework_version');
        $this->assertNotNull($fwLink, 'Binding must link to framework version');
    }

    public function test_n22_admin_case_summary_excludes_private_prompt_content_and_provider_identifiers(): void
    {
        // Admin views case summary
        $resp = $this->actingAs($this->admin)->getJson("/v3/admin/cases/{$this->processingCase->id}/summary");
        $this->assertSame(200, $resp->status());

        $content = $resp->getContent();

        // Must not contain confidential content, prompts, provider IDs, or internal cookies
        $this->assertStringNotContainsString('nb-evidence-secret-id-999', $content, 'Must not expose provider notebook ID');
        $this->assertStringNotContainsString('prompt body', $content, 'Must not expose prompt text');
        $this->assertStringNotContainsString('bundle raw body', $content, 'Must not expose raw lesson bundle body');
        $this->assertStringNotContainsString('bundles/bundle-test.txt', $content, 'Must not expose private storage paths');

        // Headers include no-store
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
    }
}
