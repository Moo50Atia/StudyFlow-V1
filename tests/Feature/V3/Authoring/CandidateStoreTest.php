<?php

namespace Tests\Feature\V3\Authoring;

use App\Models\V3\CodingAttempt;
use App\Models\V3\SceneCandidate;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use App\Services\V3\Authoring\ClaimCodingAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\WorkflowFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);

function postSigned(TestCase $test, string $path, array $body) {
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);
    return $test->postJson($path, $body, $headers, JSON_UNESCAPED_SLASHES);
}

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $env = AuthoringFixtures::createAuthoringEnvironment();
    $this->teacher = $env['teacher'];
    $this->admin = $env['admin'];
    $this->settingsVersion = $env['settingsVersion'];
    $this->case = $env['case'];
    $this->map = $env['map'];

    $this->sec1 = AuthoringFixtures::createQualifiedSection($this->case, $this->map, 1, 'Prompt for section 1', true);

    $claimService = app(ClaimAuthoringRequest::class);
    $res = $claimService->claimInitial($this->case->id, $this->sec1['version']->id);
    $this->request = $res['request'];

    $attemptService = app(ClaimCodingAttempt::class);
    $attRes = $attemptService->claim($this->case->id, $this->request->id, [
        'schema_version' => 1,
        'ordinal' => 1,
    ]);
    $this->attemptRef = $attRes['data']['attempt_ref'];
});

test('A06: valid candidate result persists private artifacts and resolver reads them', function () {
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$this->attemptRef}/result";
    $resultBody = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-12345',
        'provider_response_json' => ['id' => 'resp-12345'],
        'package' => [
            'schema_version' => 1,
            'files' => [
                [
                    'path' => 'index.html',
                    'content' => "<!DOCTYPE html>\n<html><head><title>Scene</title></head><body><script src=\"app.js\"></script></body></html>",
                ],
                [
                    'path' => 'app.js',
                    'content' => "console.log('Wave initialized');",
                ],
            ],
        ],
        'failure_code' => null,
        'usage' => [
            'input_tokens' => 500,
            'output_tokens' => 200,
            'cost_minor_units' => 5,
            'currency' => 'USD',
        ],
        'duration_ms' => 1250,
    ];

    $response = postSigned($this, $resultPath, $resultBody);
    $response->assertStatus(201);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $data = $response->json('data');
    expect($data['state'])->toBe('candidate_ready');
    expect($data['candidate_ref'])->not->toBeNull();
    $candidateRef = $data['candidate_ref'];

    // Verify candidate row in DB
    $candidate = SceneCandidate::where('id', $candidateRef)->first();
    expect($candidate)->not->toBeNull();
    expect($candidate->case_id)->toBe($this->case->id);
    expect($candidate->attempt_id)->toBe($this->attemptRef);
    expect($candidate->request_id)->toBe($this->request->id);
    expect($candidate->file_count)->toBe(2);
    expect($candidate->entrypoint)->toBe('index.html');
    expect($candidate->manifest_sha256)->toBeString();
    expect($candidate->manifest_path)->toBe("scene-candidates/{$this->case->id}/{$candidateRef}/manifest.json");

    // Verify artifacts stored on v3_private
    Storage::disk('v3_private')->assertExists("scene-candidates/{$this->case->id}/{$candidateRef}/index.html");
    Storage::disk('v3_private')->assertExists("scene-candidates/{$this->case->id}/{$candidateRef}/app.js");
    Storage::disk('v3_private')->assertExists("scene-candidates/{$this->case->id}/{$candidateRef}/manifest.json");

    // Call candidate resolver GET endpoint
    $resolverPath = "/api/internal/v1/cases/{$this->case->id}/scene-candidates/{$candidateRef}";
    $resHeaders = WorkflowFixtures::signCallbackRequest('GET', $resolverPath, []);
    $resResponse = $this->withHeaders($resHeaders)->get($resolverPath);

    $resResponse->assertStatus(200);
    $resData = $resResponse->json('data');
    expect($resData['candidate_ref'])->toBe($candidateRef);
    expect($resData['files'])->toHaveCount(2);
    expect($resData['manifest']['schema_version'])->toBe(1);
    expect($resData['manifest']['entrypoint'])->toBe('index.html');
    $manifestRaw = Storage::disk('v3_private')->get($candidate->manifest_path);
    expect($resData['manifest_sha256'])->toBe($candidate->manifest_sha256);
    expect($resData['manifest_raw_base64'])->toBe(base64_encode($manifestRaw));
});

test('A07: invalid package validation creates no candidate and records known_failure', function () {
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$this->attemptRef}/result";
    // Invalid package: missing index.html
    $resultBody = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-err',
        'provider_response_json' => [],
        'package' => [
            'schema_version' => 1,
            'files' => [
                [
                    'path' => 'main.js',
                    'content' => "console.log('No HTML');",
                ],
            ],
        ],
        'failure_code' => null,
        'usage' => null,
        'duration_ms' => 500,
    ];

    $response = postSigned($this, $resultPath, $resultBody);
    $response->assertStatus(201);
    $data = $response->json('data');
    expect($data['state'])->toBe('known_failure');
    expect($data['candidate_ref'])->toBeNull();

    // Verify NO candidate row exists
    expect(SceneCandidate::count())->toBe(0);

    // Verify attempt state
    $attempt = CodingAttempt::where('id', $this->attemptRef)->first();
    expect($attempt->state)->toBe('known_failure');
    expect($attempt->failure_code)->toBe('validation');
});

test('A09: same result replay returns same candidate and changed digest conflicts', function () {
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$this->attemptRef}/result";
    $resultBody = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-replay',
        'provider_response_json' => [],
        'package' => [
            'schema_version' => 1,
            'files' => [
                [
                    'path' => 'index.html',
                    'content' => "<!DOCTYPE html><html><body>Valid</body></html>",
                ],
            ],
        ],
        'failure_code' => null,
        'usage' => null,
        'duration_ms' => 800,
    ];

    $res1 = postSigned($this, $resultPath, $resultBody);
    $res1->assertStatus(201);
    $candidateRef = $res1->json('data.candidate_ref');

    // Replay with exact same payload -> 200 with identical candidate_ref
    $resReplay = postSigned($this, $resultPath, $resultBody);
    $resReplay->assertStatus(200);
    expect($resReplay->json('data.candidate_ref'))->toBe($candidateRef);

    // Changed payload -> 409 conflict
    $changedBody = $resultBody;
    $changedBody['duration_ms'] = 999;
    $resConflict = postSigned($this, $resultPath, $changedBody);
    $resConflict->assertStatus(409);
    expect($resConflict->json('error.code'))->toBe('authoring_conflict');
});

test('A14: readback returns committed candidate after lost acknowledgement', function () {
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$this->attemptRef}/result";
    $resultBody = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-readback',
        'provider_response_json' => [],
        'package' => [
            'schema_version' => 1,
            'files' => [
                [
                    'path' => 'index.html',
                    'content' => "<!DOCTYPE html><html><body>Readback</body></html>",
                ],
            ],
        ],
        'failure_code' => null,
        'usage' => null,
        'duration_ms' => 600,
    ];

    postSigned($this, $resultPath, $resultBody)->assertStatus(201);

    // Attempt readback GET
    $readbackPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$this->attemptRef}";
    $rbHeaders = WorkflowFixtures::signCallbackRequest('GET', $readbackPath, []);
    $rbRes = $this->withHeaders($rbHeaders)->get($readbackPath);

    $rbRes->assertStatus(200);
    $data = $rbRes->json('data');
    expect($data['state'])->toBe('candidate_ready');
    expect($data['candidate_ref'])->not->toBeNull();
    expect($data['result_sha256'])->not->toBeNull();
});

test('A06: resolver refuses corrupt artifact with 503 authoring_unavailable', function () {
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$this->attemptRef}/result";
    $resultBody = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-corrupt',
        'provider_response_json' => [],
        'package' => [
            'schema_version' => 1,
            'files' => [
                [
                    'path' => 'index.html',
                    'content' => "<!DOCTYPE html><html><body>Corrupt test</body></html>",
                ],
            ],
        ],
        'failure_code' => null,
        'usage' => null,
        'duration_ms' => 700,
    ];

    $res = postSigned($this, $resultPath, $resultBody);
    $candidateRef = $res->json('data.candidate_ref');

    // Corrupt index.html on v3_private disk
    Storage::disk('v3_private')->put("scene-candidates/{$this->case->id}/{$candidateRef}/index.html", "CORRUPTED CONTENT");

    // Call candidate resolver
    $resolverPath = "/api/internal/v1/cases/{$this->case->id}/scene-candidates/{$candidateRef}";
    $resHeaders = WorkflowFixtures::signCallbackRequest('GET', $resolverPath, []);
    $resResponse = $this->withHeaders($resHeaders)->get($resolverPath);

    $resResponse->assertStatus(503);
    expect($resResponse->json('error.code'))->toBe('authoring_unavailable');
});

test('A06: resolver returns 404 for candidate with superseded stale prompt', function () {
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$this->attemptRef}/result";
    $resultBody = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-stale',
        'provider_response_json' => [],
        'package' => [
            'schema_version' => 1,
            'files' => [
                [
                    'path' => 'index.html',
                    'content' => "<!DOCTYPE html><html><body>Stale test</body></html>",
                ],
            ],
        ],
        'failure_code' => null,
        'usage' => null,
        'duration_ms' => 600,
    ];

    $res = postSigned($this, $resultPath, $resultBody);
    $candidateRef = $res->json('data.candidate_ref');

    // Supersede prompt version
    $this->sec1['sectionState']->update(['status' => 'superseded']);

    // Call candidate resolver
    $resolverPath = "/api/internal/v1/cases/{$this->case->id}/scene-candidates/{$candidateRef}";
    $resHeaders = WorkflowFixtures::signCallbackRequest('GET', $resolverPath, []);
    $resResponse = $this->withHeaders($resHeaders)->get($resolverPath);

    $resResponse->assertStatus(404);
    expect($resResponse->json('error.code'))->toBe('authoring_unavailable');
});
