<?php

namespace Tests\Integration\V3\WebPipeline;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Models\V3\PipelineCommand;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\QualificationGate;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\Authoring\ClaimCodingAttempt;
use App\Services\V3\Authoring\ImportManualScene;
use App\Services\V3\Authoring\RecordAuthoringResult;
use App\Services\V3\SceneQualification\ClaimSceneCheck;
use App\Services\V3\SceneQualification\RecordQualificationGate;
use App\Services\V3\SceneQualification\RecordSceneCheck;
use App\Services\V3\SceneQualification\SceneReviewEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\SceneQualificationFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class StaticSceneChecksTest extends TestCase
{
    use RefreshDatabase;

    protected $teacher;
    protected $admin;
    protected $subject;
    protected $run;
    protected $item;
    protected $case;
    protected $section;
    protected $promptVersion;
    protected $map;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $env = AuthoringFixtures::createAuthoringEnvironment([], 'notebooklm_per_pdf_web_v1');
        $this->teacher = $env['teacher'];
        $this->admin = $env['admin'];
        $this->case = $env['case'];
        $this->map = $env['map'];

        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher);

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
            'settings_version_id' => $env['settingsVersion']->id,
            'framework_version_id' => $frameworkId,
            'mode' => 'export_manual',
            'qualification_mode' => 'owner_waived_demo',
            'captured_settings_json' => '{}',
            'capture_sha256' => hash('sha256', '{}'),
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'run1'),
            'state' => 'prompts_ready',
            'state_version' => 1,
            'total_pdfs' => 1,
            'total_lessons' => 1,
            'total_scenes' => 1,
        ]);

        $this->item = SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacher->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Mechanics',
            'original_filename' => 'mechanics.pdf',
            'input_sha256' => hash('sha256', 'pdf1'),
            'input_bytes' => 1024,
            'state' => 'prompts_ready',
            'document_id' => $env['document']->id,
            'case_id' => $this->case->id,
            'saved_lesson_count' => 1,
            'prompt_count' => 1,
            'state_version' => 1,
        ]);

        $sec = AuthoringFixtures::createQualifiedSection($this->case, $this->map, 1, 'Interactive 2D Canvas harmonic motion simulation.', false);
        $this->section = $sec['section'];
        $this->promptVersion = $sec['version'];

        $this->section->update(['subject_run_item_id' => $this->item->id]);
    }

    protected function createPipelineCommand(): string
    {
        $cmdId = (string) Str::uuid();
        PipelineCommand::create([
            'id' => $cmdId,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'test_command',
            'request_sha256' => hash('sha256', 'cmd-' . $cmdId),
            'state' => 'applied',
        ]);
        return $cmdId;
    }

    protected function makeValidCheckReport(SceneCandidate $candidate, string $screenshotSha, string $verdict = 'pass'): array
    {
        $startedAt = now()->subSeconds(2);
        $finishedAt = now();

        $checks = [
            ['code' => 'integrity', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'entrypoint', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'assets', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'runtime', 'status' => $verdict === 'pass' ? 'pass' : 'fail', 'detail_codes' => $verdict === 'pass' ? [] : ['script_error']],
            ['code' => 'network', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'interaction', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'accessibility', 'status' => 'pass', 'detail_codes' => []],
        ];

        return [
            'schema_version' => 1,
            'candidate_ref' => $candidate->id,
            'manifest_sha256' => $candidate->manifest_sha256,
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'checks' => $checks,
            'observations' => [
                'dom_text' => 'Simulation stage text',
                'controls' => [
                    ['ordinal' => 1, 'role' => 'button', 'name' => 'Start', 'before_sha256' => 'abc', 'after_sha256' => 'def', 'pointer_changed' => true, 'keyboard_changed' => true, 'focusable' => true],
                ],
                'resource_events' => [],
                'runtime_errors' => $verdict === 'pass' ? [] : [['kind' => 'page', 'message' => 'Sim crash']],
                'asset_failures' => [],
                'console_error_count' => 0,
                'page_error_count' => $verdict === 'pass' ? 0 : 1,
                'forbidden_request_count' => 0,
            ],
            'screenshot_sha256' => $screenshotSha,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
        ];
    }

    public function test_passing_browser_check_on_demo_waived_candidate_transitions_to_demo_review_ready(): void
    {
        $commandId = $this->createPipelineCommand();

        // 1. Record demo prompt gate
        $gateService = app(RecordQualificationGate::class);
        $promptGate = $gateService->record([
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacher->id,
            'policy_version' => 'spec_019_web_demo_v1',
            'policy_sha256' => hash('sha256', 'policy'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'missing_fields' => [],
            'command_id' => $commandId,
        ]);

        // 2. Claim demo AuthoringRequest
        $authoringClaimService = app(\App\Services\V3\Authoring\ClaimAuthoringRequest::class);
        $claimRes = $authoringClaimService->claimInitial($this->case->id, $this->promptVersion->id);
        $req = $claimRes['request'];

        // 3. Claim coding attempt & record candidate
        $attemptService = app(ClaimCodingAttempt::class);
        $attRes = $attemptService->claim($this->case->id, $req->id, ['schema_version' => 1, 'ordinal' => 1]);
        $attemptRef = $attRes['data']['attempt_ref'];

        $package = [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => '<!DOCTYPE html><html lang="en"><head><title>Sim</title></head><body><h1>Sim</h1><button id="b" aria-label="Start">Start</button><script src="script.js"></script></body></html>'],
                ['path' => 'script.js', 'content' => 'document.getElementById("b").innerText = "Running";'],
            ],
        ];
        $recordAuthoring = app(RecordAuthoringResult::class);
        $payload = [
            'schema_version' => 1,
            'outcome' => 'candidate',
            'returned_model' => 'gemini-2.5-flash',
            'provider_response_id' => 'resp-' . Str::random(8),
            'provider_response_json' => ['ok' => true],
            'package' => $package,
            'failure_code' => null,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 200, 'cost_minor_units' => 0, 'currency' => 'USD'],
            'duration_ms' => 800,
        ];
        $candRes = $recordAuthoring->record($this->case->id, $attemptRef, $payload, json_encode($payload));
        $candidate = SceneCandidate::find($candRes['data']['candidate_ref']);
        $this->assertNotNull($candidate);

        // 4. Claim scene check attempt
        $checkClaim = app(ClaimSceneCheck::class);
        $chkClaimRes = $checkClaim->claim($this->case->id, [
            'schema_version' => 1,
            'candidate_ref' => $candidate->id,
            'attempt_no' => 1,
        ]);
        $checkAttemptRef = $chkClaimRes['data']['attempt_ref'];

        // 5. Post passing check report
        $png = SceneQualificationFixtures::validPngBytes(1280, 720);
        $screenshotSha = hash('sha256', $png);
        $report = $this->makeValidCheckReport($candidate, $screenshotSha, 'pass');
        $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);
        $reportSha = hash('sha256', $reportJson);

        $checkRecord = app(RecordSceneCheck::class);
        $resultPayload = [
            'schema_version' => 1,
            'report_json' => $reportJson,
            'report_sha256' => $reportSha,
            'screenshot_png_base64' => base64_encode($png),
            'screenshot_sha256' => $screenshotSha,
        ];

        $recResult = $checkRecord->record($this->case->id, $checkAttemptRef, $resultPayload, json_encode($resultPayload));
        $this->assertSame(201, $recResult['status_code']);
        $this->assertSame('pass', $recResult['data']['state']);

        // Assert SceneCheckRun was saved with pass verdict and duration
        $run = SceneCheckRun::where('candidate_id', $candidate->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('pass', $run->verdict);
        $this->assertGreaterThan(0, $run->duration_ms);

        // Assert candidate state transitioned to 'demo_review_ready'
        $state = SceneCandidateState::where('candidate_id', $candidate->id)->first();
        $this->assertSame('demo_review_ready', $state->status);

        // Assert scene qualification gate was created
        $sceneGate = DB::table('v3_qualification_gates')
            ->where('candidate_id', $candidate->id)
            ->where('gate_stage', 'scene')
            ->where('basis', 'owner_waived_demo')
            ->first();
        $this->assertNotNull($sceneGate);
        $this->assertSame($this->case->id, $sceneGate->case_id);
        $this->assertSame($candidate->manifest_sha256, $sceneGate->candidate_manifest_sha256);

        // Assert review eligibility confirms demo_review_ready
        $eligibilityService = app(SceneReviewEligibility::class);
        $eligibility = $eligibilityService->check($this->case->id, $candidate->id);
        $this->assertTrue($eligibility['eligible']);
        $this->assertSame('owner_waived_demo', $eligibility['basis']);
        $this->assertSame('demo_review_ready', $eligibility['status']);
        $this->assertNull($eligibility['real_qualification_id']);
    }

    public function test_passing_browser_check_on_manual_scene_creates_gate_and_is_review_ready(): void
    {
        $commandId = (string) Str::uuid();

        // 1. Import manual scene
        $importer = app(ImportManualScene::class);
        $importRes = $importer->import(
            $this->teacher,
            $this->run,
            $this->section,
            [
                'command_id' => $commandId,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => WebPipelineFixtures::makeValidStaticScenePackage('Harmonic Wave Manual'),
                'reported_provider' => 'teacher_custom',
                'reported_model' => 'manual_code_v1',
            ]
        );

        $candidate = SceneCandidate::find($importRes['candidate_id']);
        $this->assertNotNull($candidate);
        $this->assertSame('manual_import', $candidate->origin);
        $this->assertSame('static_three_file_v1', $candidate->package_profile);

        // 2. Claim check attempt
        $checkClaim = app(ClaimSceneCheck::class);
        $chkClaimRes = $checkClaim->claim($this->case->id, [
            'schema_version' => 1,
            'candidate_ref' => $candidate->id,
            'attempt_no' => 1,
        ]);
        $checkAttemptRef = $chkClaimRes['data']['attempt_ref'];

        // 3. Post passing check report
        $png = SceneQualificationFixtures::validPngBytes(1280, 720);
        $screenshotSha = hash('sha256', $png);
        $report = $this->makeValidCheckReport($candidate, $screenshotSha, 'pass');
        $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);
        $reportSha = hash('sha256', $reportJson);

        $checkRecord = app(RecordSceneCheck::class);
        $resultPayload = [
            'schema_version' => 1,
            'report_json' => $reportJson,
            'report_sha256' => $reportSha,
            'screenshot_png_base64' => base64_encode($png),
            'screenshot_sha256' => $screenshotSha,
        ];
        $recResult = $checkRecord->record($this->case->id, $checkAttemptRef, $resultPayload, json_encode($resultPayload));
        $this->assertSame(201, $recResult['status_code']);

        // Assert state is demo_review_ready
        $state = SceneCandidateState::where('candidate_id', $candidate->id)->first();
        $this->assertSame('demo_review_ready', $state->status);

        // Assert scene gate recorded
        $sceneGate = DB::table('v3_qualification_gates')
            ->where('candidate_id', $candidate->id)
            ->where('gate_stage', 'scene')
            ->where('basis', 'owner_waived_demo')
            ->first();
        $this->assertNotNull($sceneGate);

        // Assert review eligibility confirms demo_review_ready
        $eligibilityService = app(SceneReviewEligibility::class);
        $eligibility = $eligibilityService->check($this->case->id, $candidate->id);
        $this->assertTrue($eligibility['eligible']);
        $this->assertSame('owner_waived_demo', $eligibility['basis']);
        $this->assertSame('demo_review_ready', $eligibility['status']);
    }

    public function test_failing_browser_check_blocks_review_and_marks_repair_pending(): void
    {
        $commandId = (string) Str::uuid();

        $importer = app(ImportManualScene::class);
        $importRes = $importer->import(
            $this->teacher,
            $this->run,
            $this->section,
            [
                'command_id' => $commandId,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => WebPipelineFixtures::makeValidStaticScenePackage('Harmonic Wave Manual'),
                'reported_provider' => 'teacher_custom',
                'reported_model' => 'manual_code_v1',
            ]
        );
        $candidate = SceneCandidate::find($importRes['candidate_id']);

        $checkClaim = app(ClaimSceneCheck::class);
        $chkClaimRes = $checkClaim->claim($this->case->id, [
            'schema_version' => 1,
            'candidate_ref' => $candidate->id,
            'attempt_no' => 1,
        ]);
        $checkAttemptRef = $chkClaimRes['data']['attempt_ref'];

        // Post FAILING check report
        $png = SceneQualificationFixtures::validPngBytes(1280, 720);
        $screenshotSha = hash('sha256', $png);
        $report = $this->makeValidCheckReport($candidate, $screenshotSha, 'fail');
        $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);
        $reportSha = hash('sha256', $reportJson);

        $checkRecord = app(RecordSceneCheck::class);
        $resultPayload = [
            'schema_version' => 1,
            'report_json' => $reportJson,
            'report_sha256' => $reportSha,
            'screenshot_png_base64' => base64_encode($png),
            'screenshot_sha256' => $screenshotSha,
        ];
        $recResult = $checkRecord->record($this->case->id, $checkAttemptRef, $resultPayload, json_encode($resultPayload));
        $this->assertSame(201, $recResult['status_code']);
        $this->assertSame('fail', $recResult['data']['state']);

        // Assert state is repair_pending
        $state = SceneCandidateState::where('candidate_id', $candidate->id)->first();
        $this->assertSame('repair_pending', $state->status);

        // Assert ineligible for review
        $eligibilityService = app(SceneReviewEligibility::class);
        $eligibility = $eligibilityService->check($this->case->id, $candidate->id);
        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('check_failed', $eligibility['status']);
    }

    public function test_invalid_screenshot_dimensions_rejected(): void
    {
        $commandId = (string) Str::uuid();
        $importer = app(ImportManualScene::class);
        $importRes = $importer->import(
            $this->teacher,
            $this->run,
            $this->section,
            [
                'command_id' => $commandId,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => WebPipelineFixtures::makeValidStaticScenePackage('Harmonic Wave Manual'),
                'reported_provider' => 'teacher_custom',
                'reported_model' => 'manual_code_v1',
            ]
        );
        $candidate = SceneCandidate::find($importRes['candidate_id']);

        $checkClaim = app(ClaimSceneCheck::class);
        $chkClaimRes = $checkClaim->claim($this->case->id, [
            'schema_version' => 1,
            'candidate_ref' => $candidate->id,
            'attempt_no' => 1,
        ]);
        $checkAttemptRef = $chkClaimRes['data']['attempt_ref'];

        // Invalid PNG size: 640x480 instead of 1280x720
        $badPng = SceneQualificationFixtures::validPngBytes(640, 480);
        $screenshotSha = hash('sha256', $badPng);
        $report = $this->makeValidCheckReport($candidate, $screenshotSha, 'pass');
        $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);
        $reportSha = hash('sha256', $reportJson);

        $checkRecord = app(RecordSceneCheck::class);
        $resultPayload = [
            'schema_version' => 1,
            'report_json' => $reportJson,
            'report_sha256' => $reportSha,
            'screenshot_png_base64' => base64_encode($badPng),
            'screenshot_sha256' => $screenshotSha,
        ];

        $this->expectException(\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Screenshot dimensions must be 1280x720');
        $checkRecord->record($this->case->id, $checkAttemptRef, $resultPayload, json_encode($resultPayload));
    }
}
