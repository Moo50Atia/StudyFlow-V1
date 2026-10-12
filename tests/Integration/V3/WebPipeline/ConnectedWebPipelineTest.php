<?php

namespace Tests\Integration\V3\WebPipeline;

use App\Models\Lecture;
use App\Models\Subject;
use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\Document;
use App\Models\V3\PipelineCommand;
use App\Models\V3\PipelineOutbox;
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
use App\Services\V3\Review\DispatchReviewEffects;
use App\Services\V3\Review\EnsureReviewReady;
use App\Services\V3\SceneQualification\RecordQualificationGate;
use App\Services\V3\SceneQualification\RecordSceneCheck;
use App\Services\V3\SceneQualification\SceneReviewEligibility;
use App\Services\V3\Settings\PublishSettings;
use App\Services\V3\WebPipeline\AcceptRunItem;
use App\Services\V3\WebPipeline\AcceptWebLesson;
use App\Services\V3\WebPipeline\ClaimOperation;
use App\Services\V3\WebPipeline\CommitOperation;
use App\Services\V3\WebPipeline\DispatchRunOutbox;
use App\Services\V3\Workflow\BridgeRequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

/**
 * T060: Connected full application chain test.
 *
 * Verifies the complete lifecycle:
 * 1. Teacher web run intake with 2 PDFs and explicit demo waiver.
 * 2. Bridge outbox dispatch with valid HMAC signing.
 * 3. Worker signed claims and lesson commits creating lectures/sections/prompts.
 * 4. Exact prompt export with zero external generation calls.
 * 5. Manual scene 3-file package import with truthful provenance.
 * 6. Browser qualification checks passing and review eligibility.
 * 7. Teacher preview session start and frame 0 consumption.
 * 8. Teacher approval decision and review outbox materialization.
 * 9. Accurate progress projection and private operational admin summary.
 */
class ConnectedWebPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected User $admin;
    protected Subject $subject;
    protected string $secretKey = 'test-connected-pipeline-secret';
    protected string $frameworkId;
    protected int $settingsId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('v3_private');

        config(['services.v3.callback_hmac_key' => $this->secretKey]);
        config(['services.v3.worker_hmac_key' => $this->secretKey]);
        config(['services.v3.bridge_hmac_key' => $this->secretKey]);
        config(['services.v3.bridge_url' => 'http://127.0.0.1:8765']);

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacher = WebPipelineFixtures::createTeacherA();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher, 'Quantum Physics');

        $initialLecture = Lecture::create([
            'subject_id' => $this->subject->id,
            'title' => 'Initial Seed Lecture',
        ]);
        WebPipelineFixtures::grantTeacherPermission($this->teacher, $this->subject, $initialLecture);

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(PublishSettings::class);
        $publisher->publish($payload, 0, $this->admin->id);
        $this->settingsId = (int) DB::table('v3_settings_versions')->max('id');

        $this->frameworkId = (string) Str::uuid();
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $this->frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $this->frameworkId . '.md',
            'body_sha256' => hash('sha256', 'mock_framework_body'),
            'body_bytes' => 19,
            'first_sha256' => hash('sha256', 'first_step'),
            'second_sha256' => hash('sha256', 'second_step'),
            'third_sha256' => hash('sha256', 'third_step'),
            'response_format_sha256' => hash('sha256', 'format_step'),
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);
        DB::table('v3_notebook_framework_pointer')->updateOrInsert(
            ['id' => 1],
            ['version_id' => $this->frameworkId, 'updated_at' => now()]
        );
        Storage::disk('v3_private')->put('frameworks/' . $this->frameworkId . '.md', 'mock_framework_body');
    }

    protected function signedHeaders(string $method, string $path, array $body = []): array
    {
        return BridgeRequestSigner::signCallbackRequest($method, $path, $body, $this->secretKey);
    }

    public function test_full_connected_application_chain(): void
    {
        // ---------------------------------------------------------------------
        // Step 1: Teacher submits web run with two PDFs and demo waiver
        // ---------------------------------------------------------------------
        $submissionKey = (string) Str::uuid();
        $file1 = UploadedFile::fake()->createWithContent('chapter1.pdf', PdfFixtures::minimalValidPdf(1, 'Quantum Wave Mechanics'));
        $file2 = UploadedFile::fake()->createWithContent('chapter2.pdf', PdfFixtures::minimalValidPdf(1, 'Schrodinger Equation Solutions'));

        $intakeResponse = $this->actingAs($this->teacher)
            ->postJson("/v3/teacher/subjects/{$this->subject->id}/runs", [
                'submission_key' => $submissionKey,
                'files' => [$file1, $file2],
                'titles' => ['Wave Mechanics', 'Schrodinger Equation'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);

        $intakeResponse->assertStatus(202);
        $runId = $intakeResponse->json('data.run_id');
        $this->assertNotEmpty($runId);

        $run = SubjectRun::findOrFail($runId);
        $this->assertSame($this->teacher->id, $run->owner_user_id);
        $this->assertSame($this->subject->id, $run->subject_id);
        $this->assertSame('export_manual', $run->mode);
        $this->assertSame('owner_waived_demo', $run->qualification_mode);
        $this->assertSame(2, $run->total_pdfs);

        $items = SubjectRunItem::where('run_id', $run->id)->orderBy('pdf_ordinal')->get();
        $this->assertCount(2, $items);
        $item1 = $items[0];
        $item2 = $items[1];

        // ---------------------------------------------------------------------
        // Step 2: Bridge signed outbox admission dispatch
        // ---------------------------------------------------------------------
        Http::fake([
            'http://127.0.0.1:8765/internal/v1/application-pipeline/*' => Http::response([
                'outcome' => 'accepted',
                'submission_id' => $run->submission_key,
            ], 202),
        ]);

        $dispatcher = app(DispatchRunOutbox::class);
        $dispatchedCount = $dispatcher->dispatchPending(10);
        $this->assertGreaterThanOrEqual(1, $dispatchedCount);

        $cmd = PipelineCommand::where('run_id', $run->id)->where('action', 'submit')->first();
        $outbox = PipelineOutbox::where('command_id', $cmd->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame('acknowledged', $outbox->state);

        // Accept item 1 via IntakeCoordinator
        $itemAcceptor = app(AcceptRunItem::class);
        $itemRes = $itemAcceptor->accept($item1);
        $this->assertSame('completed', $itemRes['outcome']);
        $item1->refresh();
        $this->assertNotNull($item1->case_id);

        // ---------------------------------------------------------------------
        // Step 3: Worker signed claims & lesson commit for item 1
        // ---------------------------------------------------------------------
        $claimOpId = 'op_divide_' . (string) Str::uuid();
        $claimPayload = [
            'run_id' => $run->id,
            'operation_id' => $claimOpId,
            'kind' => 'divide',
            'item_id' => $item1->id,
            'expected_version' => 1,
            'request_sha256' => hash('sha256', 'divide_req_1'),
        ];
        $claimPath = '/api/v3/internal/web-pipeline/claims';
        $claimHeaders = $this->signedHeaders('POST', $claimPath, $claimPayload);

        $claimRes = $this->postJson($claimPath, $claimPayload, $claimHeaders);
        $claimRes->assertStatus(201);
        $leaseToken = $claimRes->json('lease_token');
        $this->assertNotEmpty($leaseToken);

        // Worker commits lesson turn with raw first projection and prompt
        $lessonText = "Wave-particle duality and de Broglie wavelength.";
        $promptText = "Create an interactive simulation of electron diffraction with double-slit interference pattern.";
        $promptSha = hash('sha256', $promptText);

        $acceptor = app(AcceptWebLesson::class);
        $bundleRes = $acceptor->accept([
            'run_id' => $run->id,
            'item_id' => $item1->id,
            'case_id' => $item1->case_id,
            'lesson_ordinal' => 1,
            'first_raw' => [
                'title' => 'Wave Mechanics Fundamentals',
                'summary' => $lessonText,
                'learning_objectives' => ['Analyze interference', 'Compute fringe spacing'],
                'target_audience' => 'Undergraduate Physics',
                'estimated_duration_minutes' => 45,
                'key_concepts' => ['Interference', 'De Broglie'],
                'prerequisites' => ['Optics'],
                'pedagogical_approach' => 'Simulation-based',
                'assessment_guidance' => 'Fringe measurement',
                'difficulty_level' => 'Intermediate',
                'laws_if_any' => null,
            ],
            'third_prompt' => $promptText,
            'scene_title' => 'Wave Mechanics Fundamentals',
        ]);
        $this->assertSame('completed', $bundleRes['outcome']);

        // Worker commits result to advance run state version
        $commitPayload = [
            'operation_id' => $claimOpId,
            'lease_token' => $leaseToken,
            'expected_version' => 1,
            'result_ref' => (string) Str::uuid(),
            'result_sha256' => hash('sha256', 'divide_result_1'),
            'outcome' => 'completed',
            'safe_code' => 'ok',
        ];
        $resultPath = '/api/v3/internal/web-pipeline/results';
        $resultHeaders = $this->signedHeaders('POST', $resultPath, $commitPayload);
        $resultRes = $this->postJson($resultPath, $commitPayload, $resultHeaders);
        $resultRes->assertStatus(201);

        $section = TeacherSection::where('case_id', $item1->case_id)->first();
        $this->assertNotNull($section);

        $promptVersion = ScenePromptVersion::where('section_id', $section->id)->first();
        $this->assertNotNull($promptVersion);
        $this->assertSame($promptSha, $promptVersion->body_sha256);
        $this->assertSame(strlen($promptText), $promptVersion->body_bytes);

        // ---------------------------------------------------------------------
        // Step 4: Prompt export verified
        // ---------------------------------------------------------------------
        $exportRes = $this->actingAs($this->teacher)
            ->getJson("/v3/teacher/subject-runs/{$run->id}/prompt-export");

        $exportRes->assertStatus(200);
        $exportData = $exportRes->json();
        $this->assertNotEmpty($exportData['prompts']);
        $this->assertSame($promptText, $exportData['prompts'][0]['prompt_text']);

        // ---------------------------------------------------------------------
        // Step 5: Manual Scene Package Import
        // ---------------------------------------------------------------------
        $package = WebPipelineFixtures::makeValidStaticScenePackage('Wave Interference');
        $importCommandId = (string) Str::uuid();

        $importRes = $this->actingAs($this->teacher)
            ->postJson("/v3/teacher/subject-runs/{$run->id}/scenes/{$section->id}/imports", [
                'command_id' => $importCommandId,
                'expected_version' => 2,
                'prompt_version_id' => $promptVersion->id,
                'package' => $package,
                'user_reported_provider' => 'Anthropic Claude',
                'user_reported_model' => 'claude-3-7-sonnet',
                'waiver_acknowledged' => true,
            ]);

        $importRes->assertStatus(201);
        $candidateId = $importRes->json('candidate_id');
        $this->assertNotEmpty($candidateId);

        $candidate = SceneCandidate::findOrFail($candidateId);
        $this->assertSame('manual_import', $candidate->origin);
        $this->assertSame('static_three_file_v1', $candidate->package_profile);

        // ---------------------------------------------------------------------
        // Step 6: Browser Qualification Checks & Review Eligibility
        // ---------------------------------------------------------------------
        $pngBytes = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x05\x00\x00\x00\x02\xd0\x08\x06\x00\x00\x00" . str_repeat("\x00", 50);
        $screenshotSha = hash('sha256', $pngBytes);

        $checkClaim = app(\App\Services\V3\SceneQualification\ClaimSceneCheck::class);
        $chkClaimRes = $checkClaim->claim($section->case_id, [
            'schema_version' => 1,
            'candidate_ref' => $candidate->id,
            'attempt_no' => 1,
        ]);
        $checkAttemptRef = $chkClaimRes['data']['attempt_ref'];

        $report = [
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
                'dom_text' => 'Wave Interference Canvas Heading',
                'controls' => [
                    ['ordinal' => 1, 'role' => 'button', 'name' => 'Start', 'before_sha256' => 'abc', 'after_sha256' => 'def', 'pointer_changed' => true, 'keyboard_changed' => true, 'focusable' => true],
                ],
                'resource_events' => [],
                'runtime_errors' => [],
                'asset_failures' => [],
                'console_error_count' => 0,
                'page_error_count' => 0,
                'forbidden_request_count' => 0,
            ],
            'screenshot_sha256' => $screenshotSha,
            'started_at' => now()->subSeconds(2)->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ];
        $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);
        $reportSha = hash('sha256', $reportJson);

        $resultPayload = [
            'schema_version' => 1,
            'report_json' => $reportJson,
            'report_sha256' => $reportSha,
            'screenshot_png_base64' => base64_encode($pngBytes),
            'screenshot_sha256' => $screenshotSha,
        ];

        $checkRunner = app(RecordSceneCheck::class);
        $checkResult = $checkRunner->record($section->case_id, $checkAttemptRef, $resultPayload, json_encode($resultPayload));
        $this->assertSame(201, $checkResult['status_code']);
        $this->assertSame('pass', $checkResult['data']['state']);

        // Prepare review item
        $reviewReady = app(EnsureReviewReady::class);
        $readyRes = $reviewReady->ensure($section->case_id, $candidate->id);
        $reviewItemId = $readyRes['data']['review_ref'];
        $this->assertNotEmpty($reviewItemId);

        // ---------------------------------------------------------------------
        // Step 7: Teacher Review Preview Session & Frame 0 Proof
        // ---------------------------------------------------------------------
        $pngBytes = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x05\x00\x00\x00\x02\xd0\x08\x06\x00\x00\x00" . str_repeat("\x00", 50);
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
                            'dom_text' => 'Wave Interference Canvas Heading',
                            'controls' => [],
                            'runtime_error_count' => 0,
                            'forbidden_request_count' => 0,
                        ],
                    ],
                ], 201);
            },
            '*/internal/v1/review-preview/sessions/*/frames/0' => Http::response($pngBytes, 200, ['Content-Type' => 'image/png']),
        ]);

        $viewerRes = $this->actingAs($this->teacher)
            ->postJson(route('v3.teacher.cases.reviews.viewer.start', [
                'case' => $section->case_id,
                'review' => $reviewItemId,
            ]), [
                'schema_version' => 1,
                'expected_review_version' => 1,
            ]);
        $viewerRes->assertStatus(201);
        $viewerId = $viewerRes->json('data.viewer_ref');

        // Fetch frame 0 to fulfill frame-0 fence proof
        $frameRes = $this->actingAs($this->teacher)->get(route('v3.teacher.cases.reviews.viewer.frame', [
            'case' => $section->case_id,
            'review' => $reviewItemId,
            'viewer' => $viewerId,
            'frame_no' => 0,
        ]));
        $frameRes->assertStatus(200);

        // ---------------------------------------------------------------------
        // Step 8: Teacher Review Decision & Approved Scene Materialization
        // ---------------------------------------------------------------------
        $decideRes = $this->actingAs($this->teacher)
            ->postJson(route('v3.teacher.cases.reviews.decide', [
                'case' => $section->case_id,
                'review' => $reviewItemId,
            ]), [
                'schema_version' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'expected_review_version' => 1,
                'candidate_ref' => $candidate->id,
                'candidate_version' => 1,
                'decision' => 'approve',
                'feedback_kind' => null,
                'notes' => null,
            ]);

        $decideRes->assertStatus(201);
        $approvalId = $decideRes->json('data.approval_ref');
        $this->assertNotEmpty($approvalId);

        $approvedScene = ApprovedScene::find($approvalId);
        $this->assertNotNull($approvedScene);
        $this->assertSame($candidate->id, $approvedScene->candidate_id);
        $this->assertSame($section->id, $approvedScene->section_id);

        // ---------------------------------------------------------------------
        // Step 9: Progress Projection & Privacy Preservation
        // ---------------------------------------------------------------------
        $progressRes = $this->actingAs($this->teacher)
            ->getJson("/v3/teacher/subject-runs/{$run->id}/progress");

        $progressRes->assertStatus(200);
        $progData = $progressRes->json();
        $this->assertArrayHasKey('state', $progData);
        $this->assertArrayHasKey('state_version', $progData);
        $this->assertArrayHasKey('counts', $progData);
        $this->assertSame(1, $progData['counts']['lessons_saved']);

        // Admin Operational Summary: zero content or secret disclosure
        $adminRes = $this->actingAs($this->admin)
            ->getJson("/v3/admin/subject-runs");

        $adminRes->assertStatus(200);
        $adminJson = $adminRes->getContent();

        // Operational metadata present
        $this->assertStringContainsString($run->id, $adminJson);
        $this->assertStringContainsString('export_manual', $adminJson);

        // Content, prompt bodies, and secrets MUST NOT leak to admin
        $this->assertStringNotContainsString('electron diffraction', $adminJson);
        $this->assertStringNotContainsString('Wave-particle duality', $adminJson);
        $this->assertStringNotContainsString($this->secretKey, $adminJson);
    }
}
