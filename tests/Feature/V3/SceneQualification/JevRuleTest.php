<?php

namespace Tests\Feature\V3\SceneQualification;

use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\SceneQualification;
use App\Models\V3\SettingsVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\Support\V3\SceneQualificationFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
    Config::set('services.v3.jev_model', 'typesafe-jev-contract-v1');
    $this->env = SceneQualificationFixtures::createCandidateEnvironment();
    $this->case = $this->env['case'];
    $this->candidate = $this->env['candidate'];

    // 1. Claim check attempt 1
    $claimCheckPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts";
    $claimCheckRes = SceneQualificationFixtures::postSigned($this, $claimCheckPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'attempt_no' => 1,
    ]);
    $this->checkAttemptRef = $claimCheckRes->json('data.attempt_ref');

    // 2. Record passing check result
    $checkResultPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts/{$this->checkAttemptRef}/result";
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
});

test('scene JEV reservation uses its dedicated model independently from the prompt generator', function () {
    $settings = SettingsVersion::findOrFail($this->case->settings_version_id);
    $generatorModel = $settings->payload['providers']['prompt']['model'] ?? 'gemini-1.5-pro';

    $path = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";
    $response = SceneQualificationFixtures::postSigned($this, $path, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'check_ref' => $this->checkRun->id,
        'attempt_no' => 1,
    ]);

    $response->assertStatus(201);
    $attempt = SceneJevAttempt::findOrFail($response->json('data.attempt_ref'));
    expect($attempt->requested_model)->toBe('typesafe-jev-contract-v1')
        ->and($attempt->requested_model)->not->toBe($generatorModel);
});

test('scene JEV reservation fails before creating an attempt when its model is unset', function () {
    Config::set('services.v3.jev_model', '');
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";

    SceneQualificationFixtures::postSigned($this, $path, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'check_ref' => $this->checkRun->id,
        'attempt_no' => 1,
    ])->assertStatus(404);

    expect(SceneJevAttempt::where('candidate_id', $this->candidate->id)->count())->toBe(0);
});

test('S09/S10/S11/S12: exact boundary equality satisfies JEV pass and transitions candidate to qualified', function () {
    // 1. Claim JEV attempt 1
    $claimJevPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";
    $claimRes = SceneQualificationFixtures::postSigned($this, $claimJevPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'check_ref' => $this->checkRun->id,
        'attempt_no' => 1,
    ]);
    $claimRes->assertStatus(201);
    $jevAttemptRef = $claimRes->json('data.attempt_ref');
    expect($claimRes->json('data.disposition'))->toBe('send_now');

    // 2. Fetch context
    $contextPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts/{$jevAttemptRef}/context";
    $contextRes = SceneQualificationFixtures::getSigned($this, $contextPath);
    $contextRes->assertStatus(200);
    expect($contextRes->json('data.evidence.schema_version'))->toBe(1);

    // 3. Post boundary pass result
    // min_score is 85, min_confidence is 80
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts/{$jevAttemptRef}/result";
    $rawAnswer = [
        'schema_version' => 1,
        'answers' => [
            'choice' => [
                'choice' => 'pass',
                'confidence' => 0.80, // 100 * 0.80 = 80 == min_confidence
            ],
            'score' => [
                'score' => 85.0, // 85 == min_score
                'confidence' => 0.80, // 80 == min_confidence
            ],
            'noul' => [
                'noul' => 0.80, // 0.80 >= 0.5 (yes), 100 * 0.80 = 80 >= min_confidence
            ],
        ],
    ];
    $rawAnswerJson = json_encode($rawAnswer, JSON_UNESCAPED_SLASHES);
    $answerSha = hash('sha256', $rawAnswerJson);

    $res = SceneQualificationFixtures::postSigned($this, $resultPath, [
        'schema_version' => 1,
        'raw_answer_json' => $rawAnswerJson,
        'answer_sha256' => $answerSha,
        'returned_model' => 'gemini-2.5-pro',
    ]);

    $res->assertStatus(201);
    expect($res->json('data.state'))->toBe('recorded');
    expect($res->json('data.decision'))->toBe('pass');
    expect($res->json('data.failed_checks'))->toBeEmpty();

    $qual = SceneQualification::where('candidate_id', $this->candidate->id)->first();
    expect($qual)->not->toBeNull();
    expect($qual->decision)->toBe('pass');
    expect((float)$qual->score)->toBe(85.0);

    $state = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($state->status)->toBe('qualified');
    expect($state->qualification_id)->toBe($qual->id);

    // Exact replay returns 200
    $replayRes = SceneQualificationFixtures::postSigned($this, $resultPath, [
        'schema_version' => 1,
        'raw_answer_json' => $rawAnswerJson,
        'answer_sha256' => $answerSha,
        'returned_model' => 'gemini-2.5-pro',
    ]);
    $replayRes->assertStatus(200);
});

test('S10: valid nonpass results in revise when repair budget remains', function () {
    $claimJevPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";
    $claimRes = SceneQualificationFixtures::postSigned($this, $claimJevPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'check_ref' => $this->checkRun->id,
        'attempt_no' => 1,
    ]);
    $jevAttemptRef = $claimRes->json('data.attempt_ref');

    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts/{$jevAttemptRef}/result";
    // Score is 70 < min_score 85, choice is revise
    $rawAnswer = [
        'schema_version' => 1,
        'answers' => [
            'choice' => [
                'choice' => 'revise',
                'confidence' => 0.90,
            ],
            'score' => [
                'score' => 70.0,
                'confidence' => 0.90,
            ],
            'noul' => [
                'noul' => 0.85,
            ],
        ],
    ];
    $rawAnswerJson = json_encode($rawAnswer, JSON_UNESCAPED_SLASHES);
    $answerSha = hash('sha256', $rawAnswerJson);

    $res = SceneQualificationFixtures::postSigned($this, $resultPath, [
        'schema_version' => 1,
        'raw_answer_json' => $rawAnswerJson,
        'answer_sha256' => $answerSha,
        'returned_model' => 'gemini-2.5-pro',
    ]);

    $res->assertStatus(201);
    expect($res->json('data.decision'))->toBe('revise');
    expect($res->json('data.failed_checks'))->toContain('choice');
    expect($res->json('data.failed_checks'))->toContain('score');

    $state = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($state->status)->toBe('repair_pending');
});

test('S10: valid nonpass results in block with repair_budget_exhausted when limit is 0', function () {
    $env0 = SceneQualificationFixtures::createCandidateEnvironment([], true, ['limits' => ['scene_repairs' => 0]]);
    $case0 = $env0['case'];
    $candidate0 = $env0['candidate'];

    // 1. Claim check attempt 1
    $claimCheckPath = "/api/internal/v1/cases/{$case0->id}/scene-check-attempts";
    $claimCheckRes = SceneQualificationFixtures::postSigned($this, $claimCheckPath, [
        'schema_version' => 1,
        'candidate_ref' => $candidate0->id,
        'attempt_no' => 1,
    ]);
    $checkAttemptRef0 = $claimCheckRes->json('data.attempt_ref');

    // 2. Record passing check result
    $checkResultPath = "/api/internal/v1/cases/{$case0->id}/scene-check-attempts/{$checkAttemptRef0}/result";
    $png = SceneQualificationFixtures::validPngBytes(1280, 720);
    $screenshotSha = hash('sha256', $png);
    $startedAt = now()->subSeconds(2);
    $finishedAt = now();

    $report = [
        'schema_version' => 1,
        'candidate_ref' => $candidate0->id,
        'manifest_sha256' => $candidate0->manifest_sha256,
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
    $checkRun0 = SceneCheckRun::where('candidate_id', $candidate0->id)->firstOrFail();

    $claimJevPath = "/api/internal/v1/cases/{$case0->id}/scene-jev-attempts";
    $claimRes = SceneQualificationFixtures::postSigned($this, $claimJevPath, [
        'schema_version' => 1,
        'candidate_ref' => $candidate0->id,
        'check_ref' => $checkRun0->id,
        'attempt_no' => 1,
    ]);
    $jevAttemptRef = $claimRes->json('data.attempt_ref');

    $resultPath = "/api/internal/v1/cases/{$case0->id}/scene-jev-attempts/{$jevAttemptRef}/result";
    $rawAnswer = [
        'schema_version' => 1,
        'answers' => [
            'choice' => [
                'choice' => 'revise',
                'confidence' => 0.90,
            ],
            'score' => [
                'score' => 70.0,
                'confidence' => 0.90,
            ],
            'noul' => [
                'noul' => 0.85,
            ],
        ],
    ];
    $rawAnswerJson = json_encode($rawAnswer, JSON_UNESCAPED_SLASHES);
    $answerSha = hash('sha256', $rawAnswerJson);

    $res = SceneQualificationFixtures::postSigned($this, $resultPath, [
        'schema_version' => 1,
        'raw_answer_json' => $rawAnswerJson,
        'answer_sha256' => $answerSha,
        'returned_model' => 'gemini-2.5-pro',
    ]);

    $res->assertStatus(201);
    expect($res->json('data.decision'))->toBe('block');
    expect($res->json('data.failed_checks'))->toContain('repair_budget_exhausted');

    $state = SceneCandidateState::where('candidate_id', $candidate0->id)->first();
    expect($state->status)->toBe('blocked');
});

test('S10: choice block is terminal block even if score passes', function () {
    $claimJevPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";
    $claimRes = SceneQualificationFixtures::postSigned($this, $claimJevPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'check_ref' => $this->checkRun->id,
        'attempt_no' => 1,
    ]);
    $jevAttemptRef = $claimRes->json('data.attempt_ref');

    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts/{$jevAttemptRef}/result";
    $rawAnswer = [
        'schema_version' => 1,
        'answers' => [
            'choice' => [
                'choice' => 'block',
                'confidence' => 0.99,
            ],
            'score' => [
                'score' => 95.0,
                'confidence' => 0.99,
            ],
            'noul' => [
                'noul' => 0.99,
            ],
        ],
    ];
    $rawAnswerJson = json_encode($rawAnswer, JSON_UNESCAPED_SLASHES);
    $answerSha = hash('sha256', $rawAnswerJson);

    $res = SceneQualificationFixtures::postSigned($this, $resultPath, [
        'schema_version' => 1,
        'raw_answer_json' => $rawAnswerJson,
        'answer_sha256' => $answerSha,
        'returned_model' => 'gemini-2.5-pro',
    ]);

    $res->assertStatus(201);
    expect($res->json('data.decision'))->toBe('block');
    expect($res->json('data.failed_checks'))->toContain('choice');

    $state = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($state->status)->toBe('blocked');
});

test('S11: malformed answer records technical_failure and no qualification row', function () {
    $claimJevPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";
    $claimRes = SceneQualificationFixtures::postSigned($this, $claimJevPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'check_ref' => $this->checkRun->id,
        'attempt_no' => 1,
    ]);
    $jevAttemptRef = $claimRes->json('data.attempt_ref');

    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts/{$jevAttemptRef}/result";
    $rawAnswerJson = '{"schema_version":1,"answers":{"choice":{"choice":"invalid_choice"}}}';
    $answerSha = hash('sha256', $rawAnswerJson);

    $res = SceneQualificationFixtures::postSigned($this, $resultPath, [
        'schema_version' => 1,
        'raw_answer_json' => $rawAnswerJson,
        'answer_sha256' => $answerSha,
        'returned_model' => 'gemini-2.5-pro',
    ]);

    $res->assertStatus(200);
    expect($res->json('data.state'))->toBe('technical_failure');
    expect($res->json('data.qualification_ref'))->toBeNull();

    $attempt = SceneJevAttempt::find($jevAttemptRef);
    expect($attempt->state)->toBe('technical_failure');

    expect(SceneQualification::where('candidate_id', $this->candidate->id)->count())->toBe(0);
});

test('S11: technical failure endpoint records technical_failure and allows next attempt', function () {
    $claimJevPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts";
    $claimRes = SceneQualificationFixtures::postSigned($this, $claimJevPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'check_ref' => $this->checkRun->id,
        'attempt_no' => 1,
    ]);
    $jevAttemptRef = $claimRes->json('data.attempt_ref');

    $failPath = "/api/internal/v1/cases/{$this->case->id}/scene-jev-attempts/{$jevAttemptRef}/technical-failure";
    $failRes = SceneQualificationFixtures::postSigned($this, $failPath, [
        'schema_version' => 1,
        'error_code' => 'timeout',
    ]);
    $failRes->assertStatus(200);
    expect($failRes->json('data.state'))->toBe('technical_failure');

    $attempt = SceneJevAttempt::find($jevAttemptRef);
    expect($attempt->state)->toBe('technical_failure');

    // Next attempt can now be claimed
    $claim2Res = SceneQualificationFixtures::postSigned($this, $claimJevPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'check_ref' => $this->checkRun->id,
        'attempt_no' => 2,
    ]);
    $claim2Res->assertStatus(201);
    expect($claim2Res->json('data.disposition'))->toBe('send_now');
});
