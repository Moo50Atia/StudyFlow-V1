<?php

namespace Tests\Feature\V3\SceneQualification;

use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\SceneQualificationFixtures;
use Tests\Support\V3\WorkflowFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);



beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
    $this->env = SceneQualificationFixtures::createCandidateEnvironment();
    $this->case = $this->env['case'];
    $this->candidate = $this->env['candidate'];

    // Claim attempt 1
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts";
    $claimBody = [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'attempt_no' => 1,
    ];
    $claimRes = SceneQualificationFixtures::postSigned($this, $claimPath, $claimBody);
    $this->attemptRef = $claimRes->json('data.attempt_ref');
});

test('S12: passing check records run and transitions candidate to awaiting_jev', function () {
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts/{$this->attemptRef}/result";

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
    $reportSha = hash('sha256', $reportJson);
    $screenshotPng = base64_encode($png);

    $resultBody = [
        'schema_version' => 1,
        'report_json' => $reportJson,
        'report_sha256' => $reportSha,
        'screenshot_png_base64' => $screenshotPng,
        'screenshot_sha256' => $screenshotSha,
    ];

    $response = SceneQualificationFixtures::postSigned($this, $resultPath, $resultBody);
    $response->assertStatus(201);
    expect($response->json('data.state'))->toBe('pass');
    // dump:
    // dump($conflictRes->json());

    $run = SceneCheckRun::where('candidate_id', $this->candidate->id)->first();
    expect($run)->not->toBeNull();
    expect($run->verdict)->toBe('pass');

    // A lost Temporal activity acknowledgement must recover the committed
    // result and its identity without executing Chromium a second time.
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts";
    $claimBody = [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'attempt_no' => 1,
    ];
    $reconcile = SceneQualificationFixtures::postSigned($this, $claimPath, $claimBody);
    $reconcile->assertStatus(200)->assertJson([
        'data' => [
            'disposition' => 'recorded',
            'attempt_ref' => $this->attemptRef,
            'check_ref' => $run->id,
            'verdict' => 'pass',
        ],
    ]);

    $state = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($state->status)->toBe('awaiting_jev');
    expect($state->check_run_id)->toBe($run->id);

    // Exact replay returns 200
    $replayRes = SceneQualificationFixtures::postSigned($this, $resultPath, $resultBody);
    $replayRes->assertStatus(200);

    // Altered replay returns 409
    $alteredReport = $report;
    $alteredReport['started_at'] = '2020-01-01T00:00:00Z';
    $alteredReportJson = json_encode($alteredReport, JSON_UNESCAPED_SLASHES);
    $alteredBody = [
        'schema_version' => 1,
        'report_json' => $alteredReportJson,
        'report_sha256' => hash('sha256', $alteredReportJson),
        'screenshot_png_base64' => base64_encode($png),
        'screenshot_sha256' => $screenshotSha,
    ];
    $conflictRes = SceneQualificationFixtures::postSigned($this, $resultPath, $alteredBody);
    $conflictRes->assertStatus(409);
});

test('S12: failing check records failure and sets check_failed', function () {
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts/{$this->attemptRef}/result";

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
            ['code' => 'assets', 'status' => 'fail', 'detail_codes' => ['asset_missing']],
            ['code' => 'runtime', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'network', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'interaction', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'accessibility', 'status' => 'pass', 'detail_codes' => []],
        ],
        'observations' => [
            'dom_text' => 'Sample DOM text for failing scene',
            'controls' => [],
            'resource_events' => [],
            'runtime_errors' => [],
            'asset_failures' => ['missing.css'],
            'console_error_count' => 0,
            'page_error_count' => 0,
            'forbidden_request_count' => 0,
        ],
        'screenshot_sha256' => $screenshotSha,
        'started_at' => $startedAt->toIso8601String(),
        'finished_at' => $finishedAt->toIso8601String(),
    ];

    $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);
    $resultBody = [
        'schema_version' => 1,
        'report_json' => $reportJson,
        'report_sha256' => hash('sha256', $reportJson),
        'screenshot_png_base64' => base64_encode($png),
        'screenshot_sha256' => $screenshotSha,
    ];

    $response = SceneQualificationFixtures::postSigned($this, $resultPath, $resultBody);
    $response->assertStatus(201);
    expect($response->json('data.state'))->toBe('fail');

    $state = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($state->status)->toBe('repair_pending');
});

test('S18: technical failure updates attempt state without recording run', function () {
    $techPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts/{$this->attemptRef}/technical-failure";
    $body = [
        'schema_version' => 1,
        'error_code' => 'timeout',
        'detail_code' => 'checker_unavailable',
    ];

    $response = SceneQualificationFixtures::postSigned($this, $techPath, $body);
    $response->assertStatus(200);
    expect($response->json('data.state'))->toBe('technical_failure');

    $attempt = SceneCheckAttempt::find($this->attemptRef);
    expect($attempt->state)->toBe('technical_failure');
    expect(SceneCheckRun::where('candidate_id', $this->candidate->id)->count())->toBe(0);
});
