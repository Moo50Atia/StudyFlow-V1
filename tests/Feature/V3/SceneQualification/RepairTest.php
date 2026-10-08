<?php

namespace Tests\Feature\V3\SceneQualification;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\SceneCandidate;
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

    // 2. Record failing check result (e.g. broken asset)
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
            ['code' => 'assets', 'status' => 'fail', 'detail_codes' => ['asset_missing']],
            ['code' => 'runtime', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
            ['code' => 'network', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
            ['code' => 'interaction', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
            ['code' => 'accessibility', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
        ],
        'observations' => [
            'dom_text' => 'Broken asset scene',
            'controls' => [],
            'resource_events' => [
                ['path' => 'missing.png', 'kind' => 'local', 'status' => 'missing'],
            ],
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
    $checkResultRes->assertStatus(201);
    expect($checkResultRes->json('data.state'))->toBe('fail');

    $this->checkRun = SceneCheckRun::where('candidate_id', $this->candidate->id)->firstOrFail();
});

test('S14/S15: claim repair reserves ordinal, links authoring request and transitions candidate to repairing', function () {
    $repairPath = "/api/internal/v1/cases/{$this->case->id}/scene-repairs";
    $res = SceneQualificationFixtures::postSigned($this, $repairPath, [
        'schema_version' => 1,
        'failed_candidate_ref' => $this->candidate->id,
        'expected_ordinal' => 1,
    ]);

    $res->assertStatus(201);
    expect($res->json('data.ordinal'))->toBe(1);
    expect($res->json('data.state'))->toBe('claimed');
    $repairRef = $res->json('data.repair_ref');
    $authoringRequestRef = $res->json('data.authoring_request_ref');

    // Context endpoint returns criteria and rehashed files
    $contextPath = "/api/internal/v1/cases/{$this->case->id}/scene-repairs/{$repairRef}/context";
    $ctxRes = SceneQualificationFixtures::getSigned($this, $contextPath);
    $ctxRes->assertStatus(200);
    expect($ctxRes->json('data.repair_ref'))->toBe($repairRef);
    expect($ctxRes->json('data.failed_checks'))->toContain('asset_missing');
    expect($ctxRes->json('data.check_observations.asset_failures'))->toContain('missing.png');
    expect($ctxRes->json('data.previous_files'))->not->toBeEmpty();

    // Candidate state is repairing
    $candState = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($candState->status)->toBe('repairing');

    // Authoring request origin is repair_1
    $authReq = AuthoringRequest::find($authoringRequestRef);
    expect($authReq)->not->toBeNull();
    expect($authReq->origin)->toBe('repair_1');
});

test('S14/S16: linking repair candidate marks predecessor superseded and initializes candidate state v2', function () {
    $repairPath = "/api/internal/v1/cases/{$this->case->id}/scene-repairs";
    $res = SceneQualificationFixtures::postSigned($this, $repairPath, [
        'schema_version' => 1,
        'failed_candidate_ref' => $this->candidate->id,
        'expected_ordinal' => 1,
    ]);
    $repairRef = $res->json('data.repair_ref');
    $authoringRequestRef = $res->json('data.authoring_request_ref');

    // Produce candidate on this repair request
    $attemptService = app(\App\Services\V3\Authoring\ClaimCodingAttempt::class);
    $attRes = $attemptService->claim($this->case->id, $authoringRequestRef, [
        'schema_version' => 1,
        'ordinal' => 1,
    ]);
    $attemptRef = $attRes['data']['attempt_ref'];

    $resultService = app(\App\Services\V3\Authoring\RecordAuthoringResult::class);
    $payload = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-' . \Illuminate\Support\Str::random(8),
        'provider_response_json' => ['id' => 'test'],
        'package' => [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => '<!DOCTYPE html><html lang="en"><head><title>Repaired</title></head><body><button id="b">Go</button></body></html>'],
            ],
        ],
        'failure_code' => null,
        'usage' => ['input_tokens' => 100, 'output_tokens' => 100, 'cost_minor_units' => 1, 'currency' => 'USD'],
        'duration_ms' => 500,
    ];
    $recRes = $resultService->record($this->case->id, $attemptRef, $payload, json_encode($payload, JSON_UNESCAPED_SLASHES));
    $newCandidateRef = $recRes['data']['candidate_ref'];

    // Link candidate
    $linkPath = "/api/internal/v1/cases/{$this->case->id}/scene-repairs/{$repairRef}/candidate";
    $linkRes = SceneQualificationFixtures::postSigned($this, $linkPath, [
        'schema_version' => 1,
        'candidate_ref' => $newCandidateRef,
    ]);
    $linkRes->assertStatus(201);
    expect($linkRes->json('data.state'))->toBe('candidate_ready');
    expect($linkRes->json('data.candidate_version'))->toBe(2);

    // Predecessor is superseded
    $fromState = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($fromState->status)->toBe('superseded');

    // New candidate state is awaiting_check version 2
    $toState = SceneCandidateState::where('candidate_id', $newCandidateRef)->first();
    expect($toState)->not->toBeNull();
    expect($toState->version_no)->toBe(2);
    expect($toState->status)->toBe('awaiting_check');

    // Mismatched candidate link returns 409
    $conflictRes = SceneQualificationFixtures::postSigned($this, $linkPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id, // candidate from different request
    ]);
    expect($conflictRes->status())->toBe(409);
});
