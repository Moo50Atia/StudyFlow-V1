<?php

namespace Tests\Feature\V3;

use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\SceneQualification;
use App\Models\V3\SettingsVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\V3\SceneQualificationFixtures;
use Tests\Support\V3\WorkflowFixtures;
use Tests\TestCase;

class SceneJevModelIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected $env;
    protected $case;
    protected $candidate;
    protected $checkRun;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');
        Config::set('services.v3.jev_model', 'typesafe-systemone-v1');

        $this->env = SceneQualificationFixtures::createCandidateEnvironment();
        $this->case = $this->env['case'];
        $this->candidate = $this->env['candidate'];

        // Claim and record passing check
        $claimCheckPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts";
        $claimCheckRes = SceneQualificationFixtures::postSigned($this, $claimCheckPath, [
            'schema_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'attempt_no' => 1,
        ]);
        $checkAttemptRef = $claimCheckRes->json('data.attempt_ref');

        $checkResultPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts/{$checkAttemptRef}/result";
        $png = SceneQualificationFixtures::validPngBytes(1280, 720);
        $screenshotSha = hash('sha256', $png);
        $startedAt = now()->subSeconds(2);
        $finishedAt = now();

        $report = [
            'schema_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'manifest_sha256' => $this->candidate->manifest_sha256,
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
                'dom_text' => 'Sample DOM text for passing scene',
                'controls' => [
                    ['ordinal' => 1, 'role' => 'button', 'name' => 'Run', 'before_sha256' => 'abc', 'after_sha256' => 'def', 'pointer_changed' => true, 'keyboard_changed' => true, 'focusable' => true],
                ],
                'resource_events' => [],
                'runtime_errors' => [],
                'asset_failures' => [],
                'console_error_count' => 0,
                'page_error_count' => 0,
                'forbidden_request_count' => 0,
            ],
            'screenshot_sha256' => $screenshotSha,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
        ];
        $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);
        $checkResultRes = SceneQualificationFixtures::postSigned($this, $checkResultPath, [
            'schema_version' => 1,
            'report_json' => $reportJson,
            'report_sha256' => hash('sha256', $reportJson),
            'screenshot_png_base64' => base64_encode($png),
            'screenshot_sha256' => $screenshotSha,
        ]);
        $checkResultRes->assertStatus(201);
        $this->checkRun = SceneCheckRun::where('candidate_id', $this->candidate->id)->firstOrFail();
    }

    public function test_jev_reservation_uses_dedicated_model_independent_of_generator_models(): void
    {
        $dedicatedJevModel = 'typesafe-systemone-v1';
        Config::set('services.v3.jev_model', $dedicatedJevModel);

        $generatorModels = [
            'gemini-2.5-pro',
            'glm-4-plus',
            'deepseek-chat',
        ];

        foreach ($generatorModels as $genModel) {
            $settingsOverrides = [
                'providers' => [
                    'prompt' => [
                        'id' => str_contains($genModel, 'gemini') ? 'gemini_api' : (str_contains($genModel, 'glm') ? 'glm_api' : 'deepseek_api'),
                        'model' => $genModel,
                        'credential_ref' => 'PROVIDER_KEY',
                    ],
                ],
            ];

            $env = SceneQualificationFixtures::createCandidateEnvironment([], true, $settingsOverrides);
            $case = $env['case'];
            $candidate = $env['candidate'];

            // Claim and record check run
            $claimCheckPath = "/api/internal/v1/cases/{$case->id}/scene-check-attempts";
            $claimCheckRes = SceneQualificationFixtures::postSigned($this, $claimCheckPath, [
                'schema_version' => 1,
                'candidate_ref' => $candidate->id,
                'attempt_no' => 1,
            ]);
            $checkAttemptRef = $claimCheckRes->json('data.attempt_ref');

            $checkResultPath = "/api/internal/v1/cases/{$case->id}/scene-check-attempts/{$checkAttemptRef}/result";
            $png = SceneQualificationFixtures::validPngBytes(1280, 720);
            $screenshotSha = hash('sha256', $png);
            $startedAt = now()->subSeconds(2);
            $finishedAt = now();

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
                    'dom_text' => 'Sample DOM text for passing scene',
                    'controls' => [
                        ['ordinal' => 1, 'role' => 'button', 'name' => 'Run', 'before_sha256' => 'abc', 'after_sha256' => 'def', 'pointer_changed' => true, 'keyboard_changed' => true, 'focusable' => true],
                    ],
                    'resource_events' => [],
                    'runtime_errors' => [],
                    'asset_failures' => [],
                    'console_error_count' => 0,
                    'page_error_count' => 0,
                    'forbidden_request_count' => 0,
                ],
                'screenshot_sha256' => $screenshotSha,
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => $finishedAt->toIso8601String(),
            ];
            $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);
            $checkResultRes = SceneQualificationFixtures::postSigned($this, $checkResultPath, [
                'schema_version' => 1,
                'report_json' => $reportJson,
                'report_sha256' => hash('sha256', $reportJson),
                'screenshot_png_base64' => base64_encode($png),
                'screenshot_sha256' => $screenshotSha,
            ]);
            $checkResultRes->assertStatus(201);
            $checkRun = SceneCheckRun::where('candidate_id', $candidate->id)->firstOrFail();

            $settings = SettingsVersion::findOrFail($case->settings_version_id);
            $actualGenModel = $settings->payload['providers']['prompt']['model'];
            $this->assertSame($genModel, $actualGenModel);
            $this->assertNotSame($dedicatedJevModel, $actualGenModel);

            $path = "/api/internal/v1/cases/{$case->id}/scene-jev-attempts";
            $response = SceneQualificationFixtures::postSigned($this, $path, [
                'schema_version' => 1,
                'candidate_ref' => $candidate->id,
                'check_ref' => $checkRun->id,
                'attempt_no' => 1,
            ]);
            $response->assertStatus(201);
            $attemptRef = $response->json('data.attempt_ref');
            $attempt = SceneJevAttempt::findOrFail($attemptRef);

            $this->assertSame($dedicatedJevModel, $attempt->requested_model);
            $this->assertNotSame($actualGenModel, $attempt->requested_model);

            // Context endpoint returns dedicated model
            $contextPath = "/api/internal/v1/cases/{$case->id}/scene-jev-attempts/{$attemptRef}/context";
            $ctxRes = SceneQualificationFixtures::getSigned($this, $contextPath);
            $ctxRes->assertStatus(200);
            $this->assertSame($dedicatedJevModel, $ctxRes->json('data.model'));

            // Verify request_sha256 digest contains dedicated model
            $expectedDigest = \App\Services\V3\SceneQualification\ClaimSceneJevAttempt::computeJevRequestDigest(
                $candidate->id,
                $candidate->manifest_sha256,
                $checkRun->report_sha256,
                $attempt->evidence_sha256,
                $attempt->question_sha256,
                $settings->checksum,
                $dedicatedJevModel
            );
            $this->assertSame($expectedDigest, $attempt->request_sha256);
        }
    }

    public function test_missing_jev_model_configuration_fails_closed(): void
    {
        Config::set('services.v3.jev_model', '');

        $path = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";
        $response = SceneQualificationFixtures::postSigned($this, $path, [
            'schema_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'check_ref' => $this->checkRun->id,
            'attempt_no' => 1,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'scene_unavailable']]);
        $this->assertStringContainsString('Dedicated JEV model is not configured', $response->json('error.message'));
    }

    public function test_retry_attempt_reuses_captured_model_even_if_global_config_changes(): void
    {
        Config::set('services.v3.jev_model', 'typesafe-systemone-v1');

        $path = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";
        $res1 = SceneQualificationFixtures::postSigned($this, $path, [
            'schema_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'check_ref' => $this->checkRun->id,
            'attempt_no' => 1,
        ]);
        $res1->assertStatus(201);
        $attempt1 = SceneJevAttempt::findOrFail($res1->json('data.attempt_ref'));
        $this->assertSame('typesafe-systemone-v1', $attempt1->requested_model);

        // Global configuration changes
        Config::set('services.v3.jev_model', 'typesafe-systemone-v2-modified');

        // Claim retry attempt 2
        $res2 = SceneQualificationFixtures::postSigned($this, $path, [
            'schema_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'check_ref' => $this->checkRun->id,
            'attempt_no' => 2,
        ]);
        $res2->assertStatus(201);
        $attempt2 = SceneJevAttempt::findOrFail($res2->json('data.attempt_ref'));

        // Attempt 2 MUST reuse the captured model from attempt 1, NOT the changed global config!
        $this->assertSame('typesafe-systemone-v1', $attempt2->requested_model);
        $this->assertNotSame('typesafe-systemone-v2-modified', $attempt2->requested_model);

        // Context endpoint returns the captured model
        $ctxRes2 = SceneQualificationFixtures::getSigned($this, "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts/{$attempt2->id}/context");
        $ctxRes2->assertStatus(200);
        $this->assertSame('typesafe-systemone-v1', $ctxRes2->json('data.model'));
    }

    public function test_recorded_result_captures_both_requested_and_returned_models_and_conflicts_on_changed_replay(): void
    {
        Config::set('services.v3.jev_model', 'typesafe-systemone-v1');

        $path = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";
        $res1 = SceneQualificationFixtures::postSigned($this, $path, [
            'schema_version' => 1,
            'candidate_ref' => $this->candidate->id,
            'check_ref' => $this->checkRun->id,
            'attempt_no' => 1,
        ]);
        $res1->assertStatus(201);
        $attemptRef = $res1->json('data.attempt_ref');

        // Prepare valid answers
        $answerData = [
            'answers' => [
                'choice' => ['choice' => 'pass', 'confidence' => 0.95],
                'score' => ['score' => 90.0, 'confidence' => 0.90],
                'noul' => ['noul' => 0.95],
            ],
        ];
        $rawAnswerJson = json_encode($answerData);
        $answerSha = hash('sha256', $rawAnswerJson);

        $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts/{$attemptRef}/result";
        $resultRes = SceneQualificationFixtures::postSigned($this, $resultPath, [
            'schema_version' => 1,
            'raw_answer_json' => $rawAnswerJson,
            'answer_sha256' => $answerSha,
            'returned_model' => 'typesafe-systemone-v1-custom-version',
        ]);
        $resultRes->assertStatus(201);

        $qual = SceneQualification::findOrFail($resultRes->json('data.qualification_ref'));
        $this->assertSame('typesafe-systemone-v1', $qual->requested_model);
        $this->assertSame('typesafe-systemone-v1-custom-version', $qual->returned_model);

        // Exact replay with same returned_model succeeds
        $replayRes = SceneQualificationFixtures::postSigned($this, $resultPath, [
            'schema_version' => 1,
            'raw_answer_json' => $rawAnswerJson,
            'answer_sha256' => $answerSha,
            'returned_model' => 'typesafe-systemone-v1-custom-version',
        ]);
        $replayRes->assertStatus(200);

        // Replay with different returned_model causes 409 conflict
        $conflictRes = SceneQualificationFixtures::postSigned($this, $resultPath, [
            'schema_version' => 1,
            'raw_answer_json' => $rawAnswerJson,
            'answer_sha256' => $answerSha,
            'returned_model' => 'different-returned-model',
        ]);
        $conflictRes->assertStatus(409);
        $conflictRes->assertJson(['error' => ['code' => 'scene_conflict']]);
    }
}
