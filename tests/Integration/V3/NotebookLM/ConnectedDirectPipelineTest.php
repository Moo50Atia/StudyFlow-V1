<?php

namespace Tests\Integration\V3\NotebookLM;

use App\Jobs\V3\NotifyLessonCompletionJob;
use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\KnowledgeSourceFile;
use App\Models\V3\LessonCompletion;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\NotebookLessonBundle;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Intake\StartProcessingCase;
use App\Services\V3\Lessons\AcceptLessonMap;
use App\Services\V3\NotebookLM\AcceptCombinedLesson;
use App\Services\V3\NotebookLM\DirectQueryLedger;
use App\Services\V3\NotebookLM\DirectSourceCoordinator;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use App\Models\V3\AuthoringRequest;
use App\Models\V3\ApprovedScene;
use App\Models\V3\CodingAttempt;
use App\Models\V3\PromptJevAttempt;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewRoute;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\SceneQualification;
use App\Models\V3\SceneRepair;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Services\V3\Review\DispatchReviewEffects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SceneQualificationFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;
use Tests\TestCase;

class ConnectedDirectPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected $teacher;
    protected $admin;
    protected $otherTeacher;
    protected $settingsVersion;
    protected $frameworkVersion;
    protected $document;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');
        Queue::fake([NotifyLessonCompletionJob::class]);

        $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Connected Direct');
        $this->otherTeacher = AccessFixtures::createEnrolledTeacher('Other Teacher');
        $this->admin = AccessFixtures::createEnrolledAdmin('Admin Connected Direct');

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $this->settingsVersion = app(\App\Services\V3\Settings\PublishSettings::class)->publish(
            $payload,
            0,
            $this->admin->id
        );

        $catalog = app(FrameworkCatalog::class);
        $this->frameworkVersion = $catalog->importFramework(
            base_path('steps.txt'),
            '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec',
            $this->admin->id
        );

        $docContent = "%PDF-1.4 test math connected full pipeline";
        $docSha256 = hash('sha256', $docContent);
        $docPath = 'accepted/test-math-connected.pdf';
        Storage::disk('v3_private')->put($docPath, $docContent);

        $this->document = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'sha256' => $docSha256,
            'byte_size' => strlen($docContent),
            'page_count' => 14,
            'state' => 'ready',
            'storage_path' => $docPath,
            'accepted_at' => now(),
        ]);
    }

    public function test_n23_connected_direct_pipeline_commits_lesson_bundle_and_enforces_review_boundary(): void
    {
        // =========================================================================
        // Step 1: Real Case Intake via StartProcessingCase service
        // =========================================================================
        config(['services.v3.notebook_protocol' => 'notebooklm_combined_v1']);

        $intakeResult = app(StartProcessingCase::class)->start(
            actorUserId: $this->teacher->id,
            documentId: $this->document->id,
            action: 'process',
            idempotencyKey: (string) Str::uuid(),
        );

        $this->assertTrue($intakeResult['success']);
        /** @var ProcessingCase $case */
        $case = $intakeResult['case'];
        $this->assertSame('notebooklm_combined_v1', $case->pipeline_version);
        $this->assertSame($this->frameworkVersion->id, $case->notebook_framework_version_id);

        WorkflowLink::forceCreate([
            'case_id' => $case->id,
            'workflow_id' => "v3-case-{$case->id}",
            'run_id' => 'run-connected-direct-001',
            'settings_version_id' => $this->settingsVersion->id,
            'settings_checksum' => $this->settingsVersion->checksum,
            'start_state' => 'started',
            'stage_code' => 'sourcing',
        ]);

        // =========================================================================
        // Step 2: Direct Source Coordination & Two File Roles
        // =========================================================================
        $sourceCoordinator = app(DirectSourceCoordinator::class);
        $binding = $sourceCoordinator->prepare($case);

        $files = KnowledgeSourceFile::where('binding_id', $binding->id)->get();
        $this->assertCount(2, $files);

        $pdfFile = $files->firstWhere('role', 'educational_pdf');
        $stepsFile = $files->firstWhere('role', 'framework_steps');
        $this->assertNotNull($pdfFile);
        $this->assertNotNull($stepsFile);
        $this->assertSame($this->document->sha256, $pdfFile->artifact_sha256);
        $this->assertSame($this->frameworkVersion->body_sha256, $stepsFile->artifact_sha256);

        // Simulate transport double outcome at outer edge (upload + configure success)
        $pdfFile->update(['provider_source_id' => 'src-pdf-conn-01', 'state' => 'ready']);
        $stepsFile->update(['provider_source_id' => 'src-steps-conn-01', 'state' => 'ready']);
        $binding->update(['notebook_id' => 'nb-conn-001', 'chat_configuration_state' => 'ready']);

        $binding = $sourceCoordinator->reconcileBindingState($binding);
        $this->assertSame('bound', $binding->state);
        $this->assertSame('confirmed', $binding->transfer_status);

        // =========================================================================
        // Step 3: Map Activation & Three-Work Divide (including Promoted Mini Chapter)
        // =========================================================================
        $activation = MapActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'binding_id' => $binding->id,
            'workflow_id' => "v3-case-{$case->id}",
            'state' => 'accepted',
            'signal_accepted_at' => now(),
        ]);

        $ledger = app(DirectQueryLedger::class);
        $mapAcceptor = app(AcceptLessonMap::class);

        // 3a. Claim and accept PDF-only Divide
        $divideClaim = $ledger->claimQuery(
            caseId: $case->id,
            kind: 'divide',
            workOrdinal: 0,
            bindingRef: $binding->id,
            expectedCursorVersion: null,
            conversationIn: null,
        );
        $this->assertSame('send_now', $divideClaim['disposition']);

        $threeWorkOutline = "Chapter 1: Foundations of Cartesian Geometry\n"
            . "Lesson 1.1: The Number Line and Real Numbers\n"
            . "Mini Chapter 1.1: Coordinate Planes\n"
            . "Lesson 1.2: Ordered Pairs and Coordinates\n"
            . "Mini Chapter 1.2: Historical Context and Overview\n";

        $divideResult = $mapAcceptor->acceptDivideResult(
            queryRef: $divideClaim['query_ref'],
            answer: $threeWorkOutline,
            conversationOut: 'convo-conn-001',
            parsedNodes: [],
        );

        $this->assertSame('recorded', $divideResult['state']);
        $map = LessonMap::find($divideResult['map_ref']);
        $this->assertNotNull($map);
        $this->assertSame('ready', $map->state);
        $this->assertSame('not_requested_direct_pdf', $map->coverage_mode);
        $this->assertSame(3, $map->work_count);

        // Verify nodes including promoted Mini Chapter (Work 3)
        $workNodes = LessonNode::where('map_id', $map->id)->whereNotNull('work_ordinal')->orderBy('work_ordinal')->get();
        $this->assertCount(3, $workNodes);
        $this->assertSame(1, $workNodes[0]->work_ordinal);
        $this->assertSame('lesson', $workNodes[0]->kind);
        $this->assertSame('The Number Line and Real Numbers', $workNodes[0]->title);

        $this->assertSame(2, $workNodes[1]->work_ordinal);
        $this->assertSame('lesson', $workNodes[1]->kind);
        $this->assertSame('Ordered Pairs and Coordinates', $workNodes[1]->title);

        $this->assertSame(3, $workNodes[2]->work_ordinal);
        $this->assertSame('mini_chapter', $workNodes[2]->kind); // Promoted Mini Chapter!
        $this->assertSame('Historical Context and Overview', $workNodes[2]->title);

        // 3b. Claim and accept Execution prompt (both sources -> System Ready)
        $execClaim = $ledger->claimQuery(
            caseId: $case->id,
            kind: 'execution',
            workOrdinal: 0,
            bindingRef: $binding->id,
            expectedCursorVersion: 1,
            conversationIn: 'convo-conn-001',
        );
        $this->assertSame('send_now', $execClaim['disposition']);

        $execPath = "/api/internal/v1/lesson-queries/{$execClaim['query_ref']}/result";
        $execPayload = [
            'schema_version' => 1,
            'answer' => 'System Ready',
            'answer_sha256' => hash('sha256', 'System Ready'),
            'conversation_out' => 'convo-conn-001',
        ];
        $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('POST', $execPath, $execPayload);
        $execResp = $this->withHeaders($headers)->postJson($execPath, $execPayload);
        $execResp->assertOk();
        $this->assertSame('recorded', $execResp->json('data.state'));

        $cursor = LessonCursor::where('case_id', $case->id)->first();
        $this->assertNotNull($cursor);
        $this->assertSame('ready_to_start', $cursor->phase);
        $this->assertSame(1, $cursor->current_work_ordinal);
        $this->assertSame(2, $cursor->version);

        // =========================================================================
        // Step 4: Fixed Sequence - Start with First lesson, then two Continue turns
        // =========================================================================
        $acceptor = app(AcceptCombinedLesson::class);

        // --- Turn 1: Start with First lesson (Work 1) ---
        $claim1 = $ledger->claimQuery(
            caseId: $case->id,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $binding->id,
            expectedCursorVersion: 2,
            conversationIn: 'convo-conn-001',
        );
        $this->assertSame('send_now', $claim1['disposition']);

        $combinedText1 = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $res1 = $acceptor->acceptCombinedLesson(
            queryRef: $claim1['query_ref'],
            payload: [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $combinedText1,
                'answer_sha256' => hash('sha256', $combinedText1),
                'conversation_out' => 'convo-conn-001',
            ],
        );
        $this->assertSame(201, $res1['status']);
        $bundle1Ref = $res1['data']['bundle_ref'];

        $cursor->refresh();
        $this->assertSame('ready_to_continue', $cursor->phase);
        $this->assertSame(2, $cursor->current_work_ordinal);
        $this->assertSame(4, $cursor->version);

        // --- Turn 2: Continue (Work 2) ---
        $claim2 = $ledger->claimQuery(
            caseId: $case->id,
            kind: 'lesson_continue',
            workOrdinal: 2,
            bindingRef: $binding->id,
            expectedCursorVersion: 4,
            conversationIn: 'convo-conn-001',
        );
        $this->assertSame('send_now', $claim2['disposition']);

        $combinedText2 = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_2.txt'));
        $res2 = $acceptor->acceptCombinedLesson(
            queryRef: $claim2['query_ref'],
            payload: [
                'schema_version' => 1,
                'expected_cursor_version' => 5,
                'answer' => $combinedText2,
                'answer_sha256' => hash('sha256', $combinedText2),
                'conversation_out' => 'convo-conn-001',
            ],
        );
        $this->assertSame(201, $res2['status']);
        $bundle2Ref = $res2['data']['bundle_ref'];

        $cursor->refresh();
        $this->assertSame('ready_to_continue', $cursor->phase);
        $this->assertSame(3, $cursor->current_work_ordinal);
        $this->assertSame(6, $cursor->version);

        // --- Turn 3: Continue (Work 3, Promoted Mini Chapter - Final) ---
        $claim3 = $ledger->claimQuery(
            caseId: $case->id,
            kind: 'lesson_continue',
            workOrdinal: 3,
            bindingRef: $binding->id,
            expectedCursorVersion: 6,
            conversationIn: 'convo-conn-001',
        );
        $this->assertSame('send_now', $claim3['disposition']);

        $combinedText3 = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_3.txt'));
        $res3 = $acceptor->acceptCombinedLesson(
            queryRef: $claim3['query_ref'],
            payload: [
                'schema_version' => 1,
                'expected_cursor_version' => 7,
                'answer' => $combinedText3,
                'answer_sha256' => hash('sha256', $combinedText3),
                'conversation_out' => 'convo-conn-001',
            ],
        );
        $this->assertSame(201, $res3['status']);
        $bundle3Ref = $res3['data']['bundle_ref'];

        // Cursor reaches complete phase on final work
        $cursor->refresh();
        $this->assertSame('complete', $cursor->phase);
        $this->assertSame(8, $cursor->version);

        // =========================================================================
        // Step 5: Verification of Atomic Bundles, 11 First Fields, and Distinct IDs
        // =========================================================================
        $this->assertSame(3, NotebookLessonBundle::where('case_id', $case->id)->count());
        $bundle1 = NotebookLessonBundle::find($bundle1Ref);
        $bundle2 = NotebookLessonBundle::find($bundle2Ref);
        $bundle3 = NotebookLessonBundle::find($bundle3Ref);
        $this->assertSame(1, $bundle1->work_ordinal);
        $this->assertSame(2, $bundle2->work_ordinal);
        $this->assertSame(3, $bundle3->work_ordinal);

        // All 3 completions exist with distinct cursor versions and completion refs
        $completions = LessonCompletion::where('case_id', $case->id)->orderBy('work_ordinal')->get();
        $this->assertCount(3, $completions);
        $this->assertSame(4, $completions[0]->cursor_version);
        $this->assertSame(6, $completions[1]->cursor_version);
        $this->assertSame(8, $completions[2]->cursor_version);
        $this->assertNotSame($completions[0]->completion_ref, $completions[1]->completion_ref);
        $this->assertNotSame($completions[1]->completion_ref, $completions[2]->completion_ref);

        // Eleven First fields verified on all sections across all works
        $allSections = TeacherSection::where('case_id', $case->id)->get();
        $this->assertGreaterThanOrEqual(4, $allSections->count()); // Work 1 has 2 sections, works 2 & 3 have 1 section each

        foreach ($allSections as $section) {
            $this->assertNotEmpty($section->section_identity);
            $this->assertNotEmpty($section->core_understanding);
            $this->assertNotEmpty($section->visual_first_explanation);
            $this->assertNotEmpty($section->visual_scene_script);
            $this->assertNotEmpty($section->dynamic_view_blueprint);
            $this->assertNotEmpty($section->visual_learning_outcome);
            $this->assertNotEmpty($section->concept_check);
            $this->assertNotEmpty($section->conceptual_summary);
            $this->assertNotEmpty($section->colloquial_egyptian_explanation);
            $this->assertNotEmpty($section->real_life_example);
            $this->assertNotNull($section->laws_if_any);
        }

        // Scene prompt versions start with permitted scene types
        $promptVersions = ScenePromptVersion::where('case_id', $case->id)->get();
        $this->assertCount($allSections->count(), $promptVersions);
        foreach ($promptVersions as $pv) {
            $this->assertContains($pv->scene_type, ['Process', 'Comparison', 'Accumulation', 'Parallel Flow', 'Time Evolution']);
        }

        // =========================================================================
        // Step 6: Authorization Boundaries (N21 / N23)
        // =========================================================================
        // Owner teacher can view lesson map and sections
        $ownerResp = $this->actingAs($this->teacher)->getJson("/v3/teacher/cases/{$case->id}/lesson-map");
        $ownerResp->assertOk();
        $this->assertCount(3, $ownerResp->json('data.work_items'));
        $this->assertSame('complete', $ownerResp->json('data.phase'));

        $sectionId = $allSections->first()->id;
        $this->actingAs($this->teacher)->getJson("/v3/teacher/cases/{$case->id}/sections/{$sectionId}")->assertOk();

        // Foreign teacher receives 404 resource_unavailable
        $foreignResp = $this->actingAs($this->otherTeacher)->getJson("/v3/teacher/cases/{$case->id}/lesson-map");
        $foreignResp->assertStatus(404);
        $this->assertSame('resource_unavailable', $foreignResp->json('error.code'));

        $foreignSecResp = $this->actingAs($this->otherTeacher)->getJson("/v3/teacher/cases/{$case->id}/sections/{$sectionId}");
        $foreignSecResp->assertStatus(404);

        // Admin is forbidden (403) from teacher private content routes
        $adminResp = $this->actingAs($this->admin)->getJson("/v3/teacher/cases/{$case->id}/lesson-map");
        $adminResp->assertStatus(403);

        $adminSecResp = $this->actingAs($this->admin)->getJson("/v3/teacher/cases/{$case->id}/sections/{$sectionId}");
        $adminSecResp->assertStatus(403);

        // =========================================================================
        // Step 7: Prompt JEV Qualification (010) across all Sections (T027)
        // =========================================================================
        $questionAssetPath = resource_path('v3/jev/prompt-questions-v1.json');
        $questionSha256 = hash_file('sha256', $questionAssetPath);
        $requestedModel = 'typesafe-systemone-v1';

        $promptVersionsBySection = [];
        foreach ($allSections as $section) {
            $pv = ScenePromptVersion::where('section_id', $section->id)->where('version_no', 1)->firstOrFail();
            $promptVersionsBySection[$section->id] = $pv;

            // Assert prompt section state is awaiting_qualification
            $pss = PromptSectionState::where('section_id', $section->id)->firstOrFail();
            $this->assertSame('awaiting_qualification', $pss->status);

            // Formulation of 010 JEV request digest
            $reqPayload = "010-jev-v1\n"
                . $pv->id . "\n"
                . $pv->body_sha256 . "\n"
                . $pv->section_fields_sha256 . "\n"
                . $this->settingsVersion->checksum . "\n"
                . $questionSha256 . "\n"
                . $requestedModel;
            $requestSha = hash('sha256', $reqPayload);

            // Claim JEV attempt
            $claimPath = "/api/internal/v1/cases/{$case->id}/prompt-versions/{$pv->id}/jev-attempts/claim";
            $claimBody = [
                'schema_version' => 1,
                'request_sha256' => $requestSha,
                'question_sha256' => $questionSha256,
                'requested_model' => $requestedModel,
            ];
            $claimResp = $this->postSigned($claimPath, $claimBody);
            $claimResp->assertStatus(200);
            $this->assertSame('send_now', $claimResp->json('data.disposition'));
            $attemptRef = $claimResp->json('data.attempt_ref');

            // Record passing typed JEV result (Choice/Score/Noul)
            $resultPath = "/api/internal/v1/cases/{$case->id}/prompt-jev-attempts/{$attemptRef}/result";
            $responsePayload = [
                'model' => $requestedModel,
                'answers' => [
                    'choice' => [
                        'type' => 'choice',
                        'choice' => 'pass',
                        'confidence' => 0.85,
                        'probabilities' => ['pass' => 0.85, 'revise' => 0.1, 'block' => 0.05],
                    ],
                    'score' => [
                        'type' => 'score',
                        'score' => 90.0,
                        'confidence' => 0.85,
                    ],
                    'noul' => [
                        'type' => 'noul',
                        'noul' => 0.95,
                    ],
                ],
            ];
            $resultBody = [
                'schema_version' => 1,
                'request_sha256' => $requestSha,
                'response' => $responsePayload,
            ];
            $resultResp = $this->postSigned($resultPath, $resultBody);
            $resultResp->assertStatus(201);
            $this->assertSame('pass', $resultResp->json('data.decision'));

            // Section state is now qualified
            $pss->refresh();
            $this->assertSame('qualified', $pss->status);

            // Resolve qualified prompt
            $resolvePath = "/api/internal/v1/cases/{$case->id}/qualified-prompts/{$pv->id}";
            $resolveResp = $this->getSigned($resolvePath);
            $resolveResp->assertStatus(200);
            $this->assertSame($section->id, $resolveResp->json('data.section_ref'));
        }

        // =========================================================================
        // Step 8: Scene Authoring (011) across all Sections (T027)
        // =========================================================================
        $eligPath = "/api/internal/v1/cases/{$case->id}/scene-authoring/eligible-prompts";
        $eligResp = $this->getSigned($eligPath);
        $eligResp->assertOk();
        $eligibleList = $eligResp->json('data');
        $this->assertCount(count($allSections), $eligibleList);

        $initialCandidates = [];
        foreach ($allSections as $section) {
            $pv = $promptVersionsBySection[$section->id];

            // Claim authoring request
            $claimAuthPath = "/api/internal/v1/cases/{$case->id}/scene-authoring/requests";
            $claimAuthBody = [
                'schema_version' => 1,
                'prompt_version_ref' => $pv->id,
            ];
            $claimAuthResp = $this->postSigned($claimAuthPath, $claimAuthBody);
            $claimAuthResp->assertStatus(201);
            $authReqRef = $claimAuthResp->json('data.request_ref');

            // Claim coding attempt
            $claimAttemptPath = "/api/internal/v1/cases/{$case->id}/scene-authoring/requests/{$authReqRef}/attempts";
            $claimAttemptBody = [
                'schema_version' => 1,
                'ordinal' => 1,
            ];
            $claimAttemptResp = $this->postSigned($claimAttemptPath, $claimAttemptBody);
            $claimAttemptResp->assertStatus(201);
            $codingAttemptRef = $claimAttemptResp->json('data.attempt_ref');

            // Record authoring result (package with index.html and app.js)
            $recordPath = "/api/internal/v1/cases/{$case->id}/scene-authoring/attempts/{$codingAttemptRef}/result";
            $pkgFiles = [
                ['path' => 'index.html', 'content' => '<!DOCTYPE html><html lang="en"><head><title>Section ' . $section->ordinal_in_work . '</title></head><body><button id="b">Interact</button></body></html>'],
                ['path' => 'app.js', 'content' => 'document.getElementById("b").onclick = function() { console.log("clicked"); };'],
            ];
            $codingPayload = [
                'schema_version' => 1,
                'outcome' => 'candidate',
                'returned_model' => 'gemini-2.5-pro',
                'provider_response_id' => 'resp-' . Str::random(8),
                'provider_response_json' => ['id' => 'test-resp'],
                'package' => [
                    'schema_version' => 1,
                    'files' => $pkgFiles,
                ],
                'failure_code' => null,
                'usage' => [
                    'input_tokens' => 200,
                    'output_tokens' => 150,
                    'cost_minor_units' => 2,
                    'currency' => 'USD',
                ],
                'duration_ms' => 450,
            ];
            $recResp = $this->postSigned($recordPath, $codingPayload);
            $recResp->assertStatus(201);
            $candidateRef = $recResp->json('data.candidate_ref');
            $initialCandidates[$section->id] = SceneCandidate::findOrFail($candidateRef);

            // Candidate created
            $this->assertSame('index.html', $initialCandidates[$section->id]->entrypoint);
            $this->assertSame(2, $initialCandidates[$section->id]->file_count);
        }

        // =========================================================================
        // Step 9: Scene Qualification & Permitted Repair Loop (012 / T027)
        // =========================================================================
        $finalQualifiedCandidates = [];

        // 9a. Section 1 exercises candidate failure -> repair -> qualified successor
        $sec1 = $allSections[0];
        $cand1 = $initialCandidates[$sec1->id];

        // Candidate 1 fails browser check
        $checkClaimPath1 = "/api/internal/v1/cases/{$case->id}/scene-check-attempts";
        $checkClaimBody1 = ['schema_version' => 1, 'candidate_ref' => $cand1->id, 'attempt_no' => 1];
        $checkClaimResp1 = $this->postSigned($checkClaimPath1, $checkClaimBody1);
        $checkAttemptRef1 = $checkClaimResp1->json('data.attempt_ref');

        $pngBytes = SceneQualificationFixtures::validPngBytes(1280, 720);
        $pngSha = hash('sha256', $pngBytes);
        $failReport = [
            'schema_version' => 1,
            'candidate_ref' => $cand1->id,
            'manifest_sha256' => $cand1->manifest_sha256,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'checks' => [
                ['code' => 'integrity', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'entrypoint', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'assets', 'status' => 'fail', 'detail_codes' => ['asset_missing']],
                ['code' => 'runtime', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'network', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'interaction', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'accessibility', 'status' => 'pass', 'detail_codes' => []],
            ],
            'observations' => [
                'dom_text' => 'Failed scene',
                'controls' => [],
                'resource_events' => [],
                'runtime_errors' => ['Missing asset'],
                'asset_failures' => ['missing.png'],
                'console_error_count' => 1,
                'page_error_count' => 0,
                'forbidden_request_count' => 0,
            ],
            'screenshot_sha256' => $pngSha,
            'started_at' => now()->subSeconds(2)->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ];
        $failReportJson = json_encode($failReport, JSON_UNESCAPED_SLASHES);
        $checkResultPath1 = "/api/internal/v1/cases/{$case->id}/scene-check-attempts/{$checkAttemptRef1}/result";
        $checkResultBody1 = [
            'schema_version' => 1,
            'report_json' => $failReportJson,
            'report_sha256' => hash('sha256', $failReportJson),
            'screenshot_png_base64' => base64_encode($pngBytes),
            'screenshot_sha256' => $pngSha,
        ];
        $checkResultResp1 = $this->postSigned($checkResultPath1, $checkResultBody1);
        $checkResultResp1->assertStatus(201);
        $this->assertSame('fail', $checkResultResp1->json('data.state'));

        // Claim repair for Candidate 1
        $repairPath = "/api/internal/v1/cases/{$case->id}/scene-repairs";
        $repairClaimResp = $this->postSigned($repairPath, [
            'schema_version' => 1,
            'failed_candidate_ref' => $cand1->id,
            'expected_ordinal' => 1,
        ]);
        $repairClaimResp->assertStatus(201);
        $repairRef = $repairClaimResp->json('data.repair_ref');
        $repairAuthReqRef = $repairClaimResp->json('data.authoring_request_ref');

        // Produce Candidate 1 Repaired (successor)
        $attClaimResp = $this->postSigned("/api/internal/v1/cases/{$case->id}/scene-authoring/requests/{$repairAuthReqRef}/attempts", [
            'schema_version' => 1,
            'ordinal' => 1,
        ]);
        $repairAttemptRef = $attClaimResp->json('data.attempt_ref');

        $repairPkgFiles = [
            ['path' => 'index.html', 'content' => '<!DOCTYPE html><html lang="en"><head><title>Repaired Scene</title></head><body><button id="b">Action</button></body></html>'],
        ];
        $repairCodingPayload = [
            'schema_version' => 1,
            'outcome' => 'candidate',
            'returned_model' => 'gemini-2.5-pro',
            'provider_response_id' => 'resp-' . Str::random(8),
            'provider_response_json' => ['id' => 'test-resp-repair'],
            'package' => ['schema_version' => 1, 'files' => $repairPkgFiles],
            'failure_code' => null,
            'usage' => ['input_tokens' => 150, 'output_tokens' => 100, 'cost_minor_units' => 1, 'currency' => 'USD'],
            'duration_ms' => 300,
        ];
        $repairRecResp = $this->postSigned("/api/internal/v1/cases/{$case->id}/scene-authoring/attempts/{$repairAttemptRef}/result", $repairCodingPayload);
        $cand1RepairedRef = $repairRecResp->json('data.candidate_ref');

        // Link repaired candidate
        $linkPath = "/api/internal/v1/cases/{$case->id}/scene-repairs/{$repairRef}/candidate";
        $linkResp = $this->postSigned($linkPath, [
            'schema_version' => 1,
            'candidate_ref' => $cand1RepairedRef,
        ]);
        $linkResp->assertStatus(201);
        $this->assertSame(2, $linkResp->json('data.candidate_version'));

        // Candidate 1 is superseded, Candidate 1 Repaired is awaiting_check version 2
        $cand1State = SceneCandidateState::where('candidate_id', $cand1->id)->firstOrFail();
        $this->assertSame('superseded', $cand1State->status);

        $cand1RepairedState = SceneCandidateState::where('candidate_id', $cand1RepairedRef)->firstOrFail();
        $this->assertSame(2, $cand1RepairedState->version_no);
        $this->assertSame('awaiting_check', $cand1RepairedState->status);

        // Qualify Candidate 1 Repaired with passing browser check and passing Scene JEV
        $candidatesToQualify = [
            $sec1->id => SceneCandidate::findOrFail($cand1RepairedRef),
        ];
        for ($i = 1; $i < count($allSections); $i++) {
            $sec = $allSections[$i];
            $candidatesToQualify[$sec->id] = $initialCandidates[$sec->id];
        }

        foreach ($candidatesToQualify as $sectionId => $candidate) {
            // Claim and record passing browser check
            $chkClaimPath = "/api/internal/v1/cases/{$case->id}/scene-check-attempts";
            $chkClaimBody = ['schema_version' => 1, 'candidate_ref' => $candidate->id, 'attempt_no' => 1];
            $chkClaimResp = $this->postSigned($chkClaimPath, $chkClaimBody);
            $chkAttemptRef = $chkClaimResp->json('data.attempt_ref');

            $passReport = [
                'schema_version' => 1,
                'candidate_ref' => $candidate->id,
                'manifest_sha256' => $candidate->manifest_sha256,
                'checker_version' => 'chromium-pw-1.63.0-v1',
                'checks' => [
                    ['code' => 'integrity', 'status' => 'pass', 'detail_codes' => []],
                    ['code' => 'entrypoint', 'status' => 'pass', 'detail_codes' => []],
                    ['code' => 'assets', 'status' => 'pass', 'detail_codes' => []],
                    ['code' => 'runtime', 'status' => 'pass', 'detail_codes' => []],
                    ['code' => 'network', 'status' => 'pass', 'detail_codes' => []],
                    ['code' => 'interaction', 'status' => 'pass', 'detail_codes' => []],
                    ['code' => 'accessibility', 'status' => 'pass', 'detail_codes' => []],
                ],
                'observations' => [
                    'dom_text' => 'Valid visual learning scene',
                    'controls' => [],
                    'resource_events' => [],
                    'runtime_errors' => [],
                    'asset_failures' => [],
                    'console_error_count' => 0,
                    'page_error_count' => 0,
                    'forbidden_request_count' => 0,
                ],
                'screenshot_sha256' => $pngSha,
                'started_at' => now()->subSeconds(2)->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
            ];
            $passReportJson = json_encode($passReport, JSON_UNESCAPED_SLASHES);
            $chkResPath = "/api/internal/v1/cases/{$case->id}/scene-check-attempts/{$chkAttemptRef}/result";
            $chkResBody = [
                'schema_version' => 1,
                'report_json' => $passReportJson,
                'report_sha256' => hash('sha256', $passReportJson),
                'screenshot_png_base64' => base64_encode($pngBytes),
                'screenshot_sha256' => $pngSha,
            ];
            $chkResResp = $this->postSigned($chkResPath, $chkResBody);
            $chkResResp->assertStatus(201);
            $this->assertSame('pass', $chkResResp->json('data.state'));
            $checkRef = $chkResResp->json('data.check_ref');

            // Claim and record passing Scene JEV attempt
            $jevClaimPath = "/api/internal/v1/cases/{$case->id}/scene-jev-attempts";
            $jevClaimBody = [
                'schema_version' => 1,
                'candidate_ref' => $candidate->id,
                'check_ref' => $checkRef,
                'attempt_no' => 1,
            ];
            $jevClaimResp = $this->postSigned($jevClaimPath, $jevClaimBody);
            $jevClaimResp->assertStatus(201);
            $jevAttemptRef = $jevClaimResp->json('data.attempt_ref');

            $rawAnswerJson = json_encode([
                'answers' => [
                    'choice' => ['choice' => 'pass', 'confidence' => 0.95],
                    'score' => ['score' => 92.0, 'confidence' => 0.9],
                    'noul' => ['noul' => 0.95],
                ],
            ], JSON_UNESCAPED_SLASHES);
            $jevResPath = "/api/internal/v1/cases/{$case->id}/scene-jev-attempts/{$jevAttemptRef}/result";
            $jevResBody = [
                'schema_version' => 1,
                'raw_answer_json' => $rawAnswerJson,
                'answer_sha256' => hash('sha256', $rawAnswerJson),
                'returned_model' => 'gemini-2.5-pro',
            ];
            $jevResResp = $this->postSigned($jevResPath, $jevResBody);
            $jevResResp->assertStatus(201);
            $this->assertSame('pass', $jevResResp->json('data.decision'));

            // Candidate state is qualified
            $candState = SceneCandidateState::where('candidate_id', $candidate->id)->firstOrFail();
            $this->assertSame('qualified', $candState->status);
            $finalQualifiedCandidates[$sectionId] = $candidate;
        }

        // =========================================================================
        // Step 10: Ensure Review Ready (013 / T027)
        // =========================================================================
        $reviewItems = [];
        foreach ($allSections as $section) {
            $candidate = $finalQualifiedCandidates[$section->id];
            $readyPath = "/api/internal/v1/cases/{$case->id}/reviews/ready";
            $readyResp = $this->postSigned($readyPath, [
                'schema_version' => 1,
                'candidate_ref' => $candidate->id,
            ]);
            $readyResp->assertStatus(201);
            $reviewRef = $readyResp->json('data.review_ref');
            $this->assertNotNull($reviewRef);
            $reviewItem = TeacherReviewItem::findOrFail($reviewRef);
            $this->assertSame('pending', $reviewItem->status);
            $this->assertSame($candidate->id, $reviewItem->candidate_id);
            $reviewItems[$section->id] = $reviewItem;
        }

        // =========================================================================
        // Step 11: Teacher Review Decisions, Fences, Outbox & Access Controls (T028)
        // =========================================================================
        // 11a. Section 1: Foreign teacher and admin cannot decide or view review notes
        $sec1Review = $reviewItems[$sec1->id];
        $decideUrlSec1 = "/v3/teacher/cases/{$case->id}/reviews/{$sec1Review->id}/decisions";
        $sec1Cand = $finalQualifiedCandidates[$sec1->id];

        // Foreign teacher cannot decide (404)
        $this->actingAs($this->otherTeacher)->postJson($decideUrlSec1, [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 1,
            'candidate_ref' => $sec1Cand->id,
            'candidate_version' => $sec1Review->candidate_version,
            'decision' => 'request_changes',
            'feedback_kind' => 'visual_interaction',
            'notes' => 'Foreign teacher attempt',
        ])->assertStatus(404);

        // Admin cannot decide (403)
        $this->actingAs($this->admin)->postJson($decideUrlSec1, [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 1,
            'candidate_ref' => $sec1Cand->id,
            'candidate_version' => $sec1Review->candidate_version,
            'decision' => 'request_changes',
            'feedback_kind' => 'visual_interaction',
            'notes' => 'Admin attempt',
        ])->assertStatus(403);

        // 11b. Original teacher submits request_changes for Section 1
        $reqChangesIdem = (string) Str::uuid();
        $reqChangesResp = $this->actingAs($this->teacher)->postJson($decideUrlSec1, [
            'schema_version' => 1,
            'idempotency_key' => $reqChangesIdem,
            'expected_review_version' => 1,
            'candidate_ref' => $sec1Cand->id,
            'candidate_version' => $sec1Review->candidate_version,
            'decision' => 'request_changes',
            'feedback_kind' => 'visual_interaction',
            'notes' => 'Enhance color contrast and increase interaction feedback speed.',
        ]);
        $reqChangesResp->assertStatus(201);
        $this->assertSame('request_changes', $reqChangesResp->json('data.decision'));
        $routeRef = $reqChangesResp->json('data.route_ref');
        $this->assertNotNull($routeRef);

        // Admin cannot inspect review notes via show route
        $this->actingAs($this->admin)->getJson("/v3/teacher/cases/{$case->id}/reviews/{$sec1Review->id}")->assertStatus(403);

        // Outbox entry created for request_changes
        $outboxRow = ReviewOutbox::where('review_decision_id', $reqChangesResp->json('data.decision_ref'))->firstOrFail();
        $this->assertSame('signal_decision', $outboxRow->operation);
        $this->assertSame('pending', $outboxRow->state);

        // Submitting duplicate decision on decided review item returns 409 review_conflict
        $dupResp = $this->actingAs($this->teacher)->postJson($decideUrlSec1, [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => 1,
            'candidate_ref' => $sec1Cand->id,
            'candidate_version' => $sec1Review->candidate_version,
            'decision' => 'approve',
            'feedback_kind' => null,
            'notes' => null,
        ]);
        $dupResp->assertStatus(409);
        $this->assertSame('review_conflict', $dupResp->json('error.code'));

        // 11c. Section 1 correction route produces successor candidate (version 3)
        $teacherRepairPath = "/api/internal/v1/cases/{$case->id}/teacher-scene-repairs";
        $teacherRepairResp = $this->postSigned($teacherRepairPath, [
            'schema_version' => 1,
            'route_ref' => $routeRef,
        ]);
        $teacherRepairResp->assertStatus(201);
        $teacherRepRef = $teacherRepairResp->json('data.repair_ref');
        $teacherAuthReqRef = $teacherRepairResp->json('data.authoring_request_ref');

        // Author successor candidate
        $attClaimResp3 = $this->postSigned("/api/internal/v1/cases/{$case->id}/scene-authoring/requests/{$teacherAuthReqRef}/attempts", [
            'schema_version' => 1,
            'ordinal' => 1,
        ]);
        $attemptRef3 = $attClaimResp3->json('data.attempt_ref');

        $recPayload3 = [
            'schema_version' => 1,
            'outcome' => 'candidate',
            'returned_model' => 'gemini-2.5-pro',
            'provider_response_id' => 'resp-' . Str::random(8),
            'provider_response_json' => ['id' => 'test-resp-v3'],
            'package' => [
                'schema_version' => 1,
                'files' => [['path' => 'index.html', 'content' => '<!DOCTYPE html><html><body><h1>Contrast Fixed</h1></body></html>']],
            ],
            'failure_code' => null,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 100, 'cost_minor_units' => 1, 'currency' => 'USD'],
            'duration_ms' => 200,
        ];
        $recResp3 = $this->postSigned("/api/internal/v1/cases/{$case->id}/scene-authoring/attempts/{$attemptRef3}/result", $recPayload3);
        $cand1V3Ref = $recResp3->json('data.candidate_ref');

        // Link successor candidate
        $linkResp3 = $this->postSigned("/api/internal/v1/cases/{$case->id}/scene-repairs/{$teacherRepRef}/candidate", [
            'schema_version' => 1,
            'candidate_ref' => $cand1V3Ref,
        ]);
        $linkResp3->assertStatus(201);
        $this->assertSame(3, $linkResp3->json('data.candidate_version'));

        $cand1V3 = SceneCandidate::findOrFail($cand1V3Ref);

        // Qualify Candidate 1 V3 (browser check + Scene JEV)
        $chkClaimResp3 = $this->postSigned("/api/internal/v1/cases/{$case->id}/scene-check-attempts", [
            'schema_version' => 1, 'candidate_ref' => $cand1V3->id, 'attempt_no' => 1,
        ]);
        $chkAttRef3 = $chkClaimResp3->json('data.attempt_ref');

        $passReport3 = [
            'schema_version' => 1,
            'candidate_ref' => $cand1V3->id,
            'manifest_sha256' => $cand1V3->manifest_sha256,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'checks' => [
                ['code' => 'integrity', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'entrypoint', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'assets', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'runtime', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'network', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'interaction', 'status' => 'pass', 'detail_codes' => []],
                ['code' => 'accessibility', 'status' => 'pass', 'detail_codes' => []],
            ],
            'observations' => [
                'dom_text' => 'Valid visual learning scene v3',
                'controls' => [],
                'resource_events' => [],
                'runtime_errors' => [],
                'asset_failures' => [],
                'console_error_count' => 0,
                'page_error_count' => 0,
                'forbidden_request_count' => 0,
            ],
            'screenshot_sha256' => $pngSha,
            'started_at' => now()->subSeconds(2)->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ];
        $passReport3Json = json_encode($passReport3, JSON_UNESCAPED_SLASHES);

        $chkResResp3 = $this->postSigned("/api/internal/v1/cases/{$case->id}/scene-check-attempts/{$chkAttRef3}/result", [
            'schema_version' => 1,
            'report_json' => $passReport3Json,
            'report_sha256' => hash('sha256', $passReport3Json),
            'screenshot_png_base64' => base64_encode($pngBytes),
            'screenshot_sha256' => $pngSha,
        ]);
        $chkResResp3->assertStatus(201);

        $jevClaimResp3 = $this->postSigned("/api/internal/v1/cases/{$case->id}/scene-jev-attempts", [
            'schema_version' => 1, 'candidate_ref' => $cand1V3->id, 'check_ref' => $chkResResp3->json('data.check_ref'), 'attempt_no' => 1,
        ]);
        $jevAttRef3 = $jevClaimResp3->json('data.attempt_ref');

        $jevResResp3 = $this->postSigned("/api/internal/v1/cases/{$case->id}/scene-jev-attempts/{$jevAttRef3}/result", [
            'schema_version' => 1,
            'raw_answer_json' => $rawAnswerJson,
            'answer_sha256' => hash('sha256', $rawAnswerJson),
            'returned_model' => 'gemini-2.5-pro',
        ]);
        $jevResResp3->assertStatus(201);

        // Ensure review ready for Candidate 1 V3
        $readyResp3 = $this->postSigned("/api/internal/v1/cases/{$case->id}/reviews/ready", [
            'schema_version' => 1, 'candidate_ref' => $cand1V3->id,
        ]);
        $readyResp3->assertStatus(201);
        $sec1FinalReview = TeacherReviewItem::findOrFail($readyResp3->json('data.review_ref'));
        $this->assertSame(3, $sec1FinalReview->candidate_version);

        // 11d. Teacher approves all current final review items across all 3 works
        $allFinalReviews = [
            $sec1->id => $sec1FinalReview,
        ];
        for ($i = 1; $i < count($allSections); $i++) {
            $sec = $allSections[$i];
            $allFinalReviews[$sec->id] = $reviewItems[$sec->id];
        }

        foreach ($allFinalReviews as $sectionId => $finalReview) {
            $decideUrl = "/v3/teacher/cases/{$case->id}/reviews/{$finalReview->id}/decisions";

            // Approve without preview fails (409 review_conflict)
            $approveWithoutPreview = $this->actingAs($this->teacher)->postJson($decideUrl, [
                'schema_version' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'expected_review_version' => 1,
                'candidate_ref' => $finalReview->candidate_id,
                'candidate_version' => $finalReview->candidate_version,
                'decision' => 'approve',
                'feedback_kind' => null,
                'notes' => null,
            ]);
            $approveWithoutPreview->assertStatus(409);
            $this->assertSame('review_conflict', $approveWithoutPreview->json('error.code'));

            // Create verified preview session with frame 0
            ReviewPreviewSession::forceCreate([
                'id' => (string) Str::uuid(),
                'case_id' => $case->id,
                'section_id' => $sectionId,
                'review_item_id' => $finalReview->id,
                'candidate_id' => $finalReview->candidate_id,
                'actor_user_id' => $this->teacher->id,
                'worker_session_ref' => (string) Str::uuid(),
                'state' => 'ready',
                'first_frame_at' => now(),
                'last_frame_no' => 0,
                'expires_at' => now()->addMinutes(30),
                'idle_expires_at' => now()->addMinutes(15),
            ]);

            // Approve with preview succeeds!
            $approveResp = $this->actingAs($this->teacher)->postJson($decideUrl, [
                'schema_version' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'expected_review_version' => 1,
                'candidate_ref' => $finalReview->candidate_id,
                'candidate_version' => $finalReview->candidate_version,
                'decision' => 'approve',
                'feedback_kind' => null,
                'notes' => null,
            ]);
            $approveResp->assertStatus(201);
            $this->assertSame('approve', $approveResp->json('data.decision'));
            $this->assertNotNull($approveResp->json('data.approval_ref'));

            // Outbox entry created for approve
            $outboxApprove = ReviewOutbox::where('review_decision_id', $approveResp->json('data.decision_ref'))->firstOrFail();
            $this->assertSame('signal_decision', $outboxApprove->operation);
            $this->assertSame('pending', $outboxApprove->state);
        }

        // 11e. Verify DispatchReviewEffects delivers outbox items and marks them delivered
        Http::fake([
            '*/internal/v1/workflows/' . "v3-case-{$case->id}" . '/review-decisions/*' => function () {
                return Http::response(['data' => ['consumed' => true]], 200);
            },
            '*/internal/v1/workflows/' . "v3-case-{$case->id}" . '/review-decisions' => function () {
                return Http::response(['data' => ['accepted' => true]], 202);
            },
        ]);

        $dispatchedCount = app(DispatchReviewEffects::class)->dispatchPending();
        $this->assertGreaterThan(0, $dispatchedCount);

        // 11f. Three-work completion: all sections across all 3 works are approved!
        $allReviewStates = ReviewSectionState::where('case_id', $case->id)->get();
        $this->assertCount(count($allSections), $allReviewStates);
        foreach ($allReviewStates as $state) {
            $this->assertSame('approved', $state->state);
            $this->assertNotNull($state->current_approval_id);
            $this->assertNull($state->current_review_item_id);
        }

        // Approved scenes exist for all sections
        $this->assertSame(count($allSections), ApprovedScene::where('case_id', $case->id)->count());
    }

    protected function postSigned(string $path, array $body): \Illuminate\Testing\TestResponse
    {
        $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);
        return $this->postJson($path, $body, $headers, JSON_UNESCAPED_SLASHES);
    }

    protected function getSigned(string $path): \Illuminate\Testing\TestResponse
    {
        $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);
        return $this->withHeaders($headers)->get($path);
    }
}
