<?php

namespace Tests\Feature\V3\SceneQualification;

use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\V3\SceneQualificationFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
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
    $checkAttemptRef = $claimCheckRes->json('data.attempt_ref');

    $png = SceneQualificationFixtures::validPngBytes(1280, 720);
    $screenshotSha = hash('sha256', $png);
    $startedAt = now()->subSeconds(2);
    $finishedAt = now();

    // 2. Record failing check result
    $checkResultPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts/{$checkAttemptRef}/result";
    $report = [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'manifest_sha256' => $this->candidate->manifest_sha256,
        'checker_version' => 'chromium-pw-1.63.0-v1',
        'checks' => [
            ['code' => 'integrity', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'entrypoint', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'assets', 'status' => 'fail', 'detail_codes' => ['asset_missing']],
            ['code' => 'runtime', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
            ['code' => 'network', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
            ['code' => 'interaction', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
            ['code' => 'accessibility', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
        ],
        'observations' => [
            'dom_text' => 'Broken asset scene',
            'controls' => [],
            'resource_events' => [],
            'runtime_errors' => [],
            'asset_failures' => ['missing.png'],
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
});

test('S16/S17: concurrent repair claim on same failed candidate prevents duplicate ordinal slot', function () {
    $repairPath = "/api/internal/v1/cases/{$this->case->id}/scene-repairs";

    // First claim succeeds
    $res1 = SceneQualificationFixtures::postSigned($this, $repairPath, [
        'schema_version' => 1,
        'failed_candidate_ref' => $this->candidate->id,
        'expected_ordinal' => 1,
    ]);
    $res1->assertStatus(201);
    expect($res1->json('data.ordinal'))->toBe(1);

    // Second claim for same candidate conflicts (409) because candidate is already repairing
    $res2 = SceneQualificationFixtures::postSigned($this, $repairPath, [
        'schema_version' => 1,
        'failed_candidate_ref' => $this->candidate->id,
        'expected_ordinal' => 1,
    ]);
    expect($res2->status())->toBeIn([409, 200]);
});
