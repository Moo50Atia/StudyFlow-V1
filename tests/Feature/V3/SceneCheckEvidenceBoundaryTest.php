<?php

namespace Tests\Feature\V3;

use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneJevAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    $this->resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts/{$this->attemptRef}/result";

    $this->validPng = SceneQualificationFixtures::validPngBytes(1280, 720);
    $this->validPngB64 = base64_encode($this->validPng);
    $this->validPngSha = hash('sha256', $this->validPng);
});

function createValidReport(TestCase $test, array $overrides = []): array
{
    $base = [
        'schema_version' => 1,
        'candidate_ref' => $test->candidate->id,
        'manifest_sha256' => $test->candidate->manifest_sha256,
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
        'screenshot_sha256' => $test->validPngSha,
        'started_at' => '2026-10-04T12:00:00.000Z',
        'finished_at' => '2026-10-04T12:00:00.137Z',
    ];

    foreach ($overrides as $k => $v) {
        $base[$k] = $v;
    }

    return $base;
}

function buildRequestBody(array $report, string $pngB64, ?string $pngSha = null): array
{
    $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);
    return [
        'schema_version' => 1,
        'report_json' => $reportJson,
        'report_sha256' => hash('sha256', $reportJson),
        'screenshot_png_base64' => $pngB64,
        'screenshot_sha256' => $pngSha ?? hash('sha256', base64_decode($pngB64)),
    ];
}

test('D02 & D06: valid all-pass report records measured 137 ms duration and transitions to awaiting_jev', function () {
    $report = createValidReport($this, [
        'started_at' => '2026-10-04T12:00:00.000Z',
        'finished_at' => '2026-10-04T12:00:00.137Z',
    ]);
    $body = buildRequestBody($report, $this->validPngB64, $this->validPngSha);

    $response = SceneQualificationFixtures::postSigned($this, $this->resultPath, $body);
    $response->assertStatus(201);
    expect($response->json('data.state'))->toBe('pass');

    $run = SceneCheckRun::where('candidate_id', $this->candidate->id)->first();
    expect($run)->not->toBeNull();
    expect($run->verdict)->toBe('pass');
    expect($run->duration_ms)->toBe(137);
    expect($run->failed_checks)->toBe([]);

    $state = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($state->status)->toBe('awaiting_jev');
    expect($state->check_run_id)->toBe($run->id);

    // Private storage contains artifacts
    Storage::disk('v3_private')->assertExists($run->report_path);
    Storage::disk('v3_private')->assertExists($run->screenshot_path);
});

test('D02 & D06: legitimate check-fail report records measured 2350 ms duration and transitions to repair_pending without JEV', function () {
    $report = createValidReport($this, [
        'checks' => [
            ['code' => 'integrity', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'entrypoint', 'status' => 'pass', 'detail_codes' => []],
            ['code' => 'assets', 'status' => 'fail', 'detail_codes' => ['asset_missing']],
            ['code' => 'runtime', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
            ['code' => 'network', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
            ['code' => 'interaction', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
            ['code' => 'accessibility', 'status' => 'fail', 'detail_codes' => ['not_run_due_to_prior_failure']],
        ],
        'started_at' => '2026-10-04T12:00:00.000Z',
        'finished_at' => '2026-10-04T12:00:02.350Z',
    ]);
    $body = buildRequestBody($report, $this->validPngB64, $this->validPngSha);

    $response = SceneQualificationFixtures::postSigned($this, $this->resultPath, $body);
    $response->assertStatus(201);
    expect($response->json('data.state'))->toBe('fail');

    $run = SceneCheckRun::where('candidate_id', $this->candidate->id)->first();
    expect($run)->not->toBeNull();
    expect($run->verdict)->toBe('fail');
    expect($run->duration_ms)->toBe(2350);
    expect($run->failed_checks)->toBe(['asset_missing']);

    $state = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($state->status)->toBe('repair_pending');

    // No JEV attempt is created
    expect(SceneJevAttempt::where('candidate_id', $this->candidate->id)->count())->toBe(0);
});

test('D02: rejects empty, missing, duplicate, unknown or out-of-order check codes', function () {
    // 1. Empty checks
    $reportEmpty = createValidReport($this, ['checks' => []]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportEmpty, $this->validPngB64));
    $res->assertStatus(422);

    // 2. Missing checks (only 6 checks)
    $checks6 = array_slice(createValidReport($this)['checks'], 0, 6);
    $report6 = createValidReport($this, ['checks' => $checks6]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($report6, $this->validPngB64));
    $res->assertStatus(422);

    // 3. Duplicate checks
    $checksDup = createValidReport($this)['checks'];
    $checksDup[1]['code'] = 'integrity';
    $reportDup = createValidReport($this, ['checks' => $checksDup]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportDup, $this->validPngB64));
    $res->assertStatus(422);

    // 4. Unknown check code
    $checksUnknown = createValidReport($this)['checks'];
    $checksUnknown[0]['code'] = 'unknown_code';
    $reportUnknown = createValidReport($this, ['checks' => $checksUnknown]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportUnknown, $this->validPngB64));
    $res->assertStatus(422);

    // 5. Out of order check codes
    $checksOrder = createValidReport($this)['checks'];
    $temp = $checksOrder[0];
    $checksOrder[0] = $checksOrder[1];
    $checksOrder[1] = $temp;
    $reportOrder = createValidReport($this, ['checks' => $checksOrder]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportOrder, $this->validPngB64));
    $res->assertStatus(422);

    // 6. Unknown detail code
    $checksBadDetail = createValidReport($this)['checks'];
    $checksBadDetail[0]['status'] = 'fail';
    $checksBadDetail[0]['detail_codes'] = ['not_a_valid_detail_code'];
    $reportBadDetail = createValidReport($this, ['checks' => $checksBadDetail]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportBadDetail, $this->validPngB64));
    $res->assertStatus(422);
});

test('D02: rejects mismatched candidate identity, manifest digest or checker version', function () {
    // 1. Foreign candidate_ref
    $reportCand = createValidReport($this, ['candidate_ref' => '00000000-0000-0000-0000-000000000999']);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportCand, $this->validPngB64));
    $res->assertStatus(422);

    // 2. Mismatched manifest_sha256
    $reportMan = createValidReport($this, ['manifest_sha256' => '0' * 64]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportMan, $this->validPngB64));
    $res->assertStatus(422);

    // 3. Wrong checker_version
    $reportChecker = createValidReport($this, ['checker_version' => 'unapproved-checker-2.0']);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportChecker, $this->validPngB64));
    $res->assertStatus(422);
});

test('D02: rejects invalid base64, non-PNG, wrong dimensions or screenshot hash mismatch', function () {
    $report = createValidReport($this);

    // 1. Invalid base64
    $bodyBadB64 = [
        'schema_version' => 1,
        'report_json' => json_encode($report),
        'report_sha256' => hash('sha256', json_encode($report)),
        'screenshot_png_base64' => '!!!not-valid-base64!!!',
        'screenshot_sha256' => hash('sha256', 'x'),
    ];
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, $bodyBadB64);
    $res->assertStatus(422);

    // 2. Non-PNG bytes (e.g. plain text)
    $textBytes = 'This is plain text and not a PNG image';
    $bodyText = buildRequestBody($report, base64_encode($textBytes));
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, $bodyText);
    $res->assertStatus(422);

    // 3. Wrong dimensions (e.g. 100x100 instead of 1280x720)
    $wrongDimPng = SceneQualificationFixtures::validPngBytes(100, 100);
    $bodyWrongDim = buildRequestBody($report, base64_encode($wrongDimPng));
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, $bodyWrongDim);
    $res->assertStatus(422);

    // 4. Screenshot hash mismatch
    $bodyHashMismatch = buildRequestBody($report, $this->validPngB64, '0' * 64);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, $bodyHashMismatch);
    $res->assertStatus(422);
});

test('D06: rejects malformed, reversed, zero or out-of-bound duration timestamps', function () {
    // 1. Malformed timestamp
    $reportMalformed = createValidReport($this, ['started_at' => 'not-a-timestamp']);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportMalformed, $this->validPngB64));
    $res->assertStatus(422);

    // 2. Reversed timestamp (finished before start)
    $reportReversed = createValidReport($this, [
        'started_at' => '2026-10-04T12:00:05.000Z',
        'finished_at' => '2026-10-04T12:00:01.000Z',
    ]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportReversed, $this->validPngB64));
    $res->assertStatus(422);

    // 3. Zero duration
    $reportZero = createValidReport($this, [
        'started_at' => '2026-10-04T12:00:00.000Z',
        'finished_at' => '2026-10-04T12:00:00.000Z',
    ]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportZero, $this->validPngB64));
    $res->assertStatus(422);

    // 4. Out of bounds (> 60 seconds)
    $reportTooLong = createValidReport($this, [
        'started_at' => '2026-10-04T12:00:00.000Z',
        'finished_at' => '2026-10-04T12:01:05.000Z',
    ]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportTooLong, $this->validPngB64));
    $res->assertStatus(422);
});

test('D02: exact replay returns 200, different body on recorded attempt returns 409 conflict', function () {
    $report = createValidReport($this);
    $body = buildRequestBody($report, $this->validPngB64, $this->validPngSha);

    $res1 = SceneQualificationFixtures::postSigned($this, $this->resultPath, $body);
    $res1->assertStatus(201);

    // Exact replay
    $resReplay = SceneQualificationFixtures::postSigned($this, $this->resultPath, $body);
    $resReplay->assertStatus(200);
    expect($resReplay->json('data.check_ref'))->toBe($res1->json('data.check_ref'));

    // Different body on recorded attempt
    $reportDiff = createValidReport($this, ['observations' => ['dom_text' => 'Changed observation text']]);
    $bodyDiff = buildRequestBody($reportDiff, $this->validPngB64, $this->validPngSha);
    $resConflict = SceneQualificationFixtures::postSigned($this, $this->resultPath, $bodyDiff);
    $resConflict->assertStatus(409);
});

test('D02: rejected evidence prevents DB mutation, storage persistence and candidate advancement', function () {
    $reportBad = createValidReport($this, ['checks' => []]);
    $res = SceneQualificationFixtures::postSigned($this, $this->resultPath, buildRequestBody($reportBad, $this->validPngB64));
    $res->assertStatus(422);

    // Verify candidate state did not change
    $state = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($state->status)->not->toBe('awaiting_jev');
    expect($state->check_run_id)->toBeNull();

    // Verify no check run exists
    expect(SceneCheckRun::where('candidate_id', $this->candidate->id)->count())->toBe(0);

    // Verify no artifacts stored
    expect(Storage::disk('v3_private')->allFiles('scene-checks'))->toBeEmpty();
});
