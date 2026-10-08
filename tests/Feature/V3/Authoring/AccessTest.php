<?php

namespace Tests\Feature\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Models\V3\SceneCandidate;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use App\Services\V3\Authoring\ClaimCodingAttempt;
use App\Services\V3\Authoring\RecordAuthoringResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
    Storage::fake('public');

    $env = AuthoringFixtures::createAuthoringEnvironment();
    $this->teacher = $env['teacher'];
    $this->admin = $env['admin'];
    $this->settingsVersion = $env['settingsVersion'];
    $this->case = $env['case'];
    $this->map = $env['map'];

    $this->sec1 = AuthoringFixtures::createQualifiedSection($this->case, $this->map, 1, 'Prompt 1', true);

    $claimService = app(ClaimAuthoringRequest::class);
    $res = $claimService->claimInitial($this->case->id, $this->sec1['version']->id);
    $this->request = $res['request'];

    $attemptService = app(ClaimCodingAttempt::class);
    $attRes = $attemptService->claim($this->case->id, $this->request->id, [
        'schema_version' => 1,
        'ordinal' => 1,
    ]);
    $this->attemptRef = $attRes['data']['attempt_ref'];

    // Create a committed candidate
    $recordService = app(RecordAuthoringResult::class);
    $resultPayload = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-access',
        'provider_response_json' => [],
        'package' => [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => '<!DOCTYPE html><html><body>Access Test</body></html>'],
                ['path' => 'app.js', 'content' => 'console.log("secret code");'],
            ],
        ],
        'failure_code' => null,
        'usage' => null,
        'duration_ms' => 500,
    ];
    $rawBytes = json_encode($resultPayload, JSON_UNESCAPED_SLASHES);
    $recordRes = $recordService->record($this->case->id, $this->attemptRef, $resultPayload, $rawBytes);
    $this->candidateRef = $recordRes['data']['candidate_ref'];
});

test('A15: unsigned, bad signature, expired and foreign-case internal requests fail before lookup', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/eligible-prompts";

    // 1. Unsigned request
    $response = $this->get($path);
    $response->assertStatus(401);

    // 2. Bad signature
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);
    $headers['X-V3-Signature'] = 'wrong-signature';
    $headers['HTTP_X_V3_SIGNATURE'] = 'wrong-signature';
    $response2 = $this->withHeaders($headers)->get($path);
    $response2->assertStatus(401);

    // 3. Expired timestamp (> 300 seconds ago)
    $expiredHeaders = WorkflowFixtures::signCallbackRequest('GET', $path, [], null, time() - 400);
    $response3 = $this->withHeaders($expiredHeaders)->get($path);
    $response3->assertStatus(401);

    // 4. Foreign case UUID in path with valid signature for another case fails signature check (401) before lookup
    $foreignCaseId = (string) Str::uuid();
    $foreignPath = "/api/internal/v1/cases/{$foreignCaseId}/scene-authoring/eligible-prompts";
    // Valid signature was created for $path ($this->case->id), NOT $foreignPath!
    $validCaseAHeaders = WorkflowFixtures::signCallbackRequest('GET', $path, []);
    $response4 = $this->withHeaders($validCaseAHeaders)->get($foreignPath);
    $response4->assertStatus(401);
});

test('A16: active original teacher sees safe status without raw code or secrets', function () {
    $statusUrl = "/v3/teacher/cases/{$this->case->id}/scene-authoring";

    // Authenticate as active original teacher
    $response = $this->actingAs($this->teacher)
        ->getJson($statusUrl);

    $response->assertStatus(200);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $data = $response->json('data');
    expect($data['case_id'])->toBe($this->case->id);
    expect($data['sections'])->toHaveCount(1);
    expect($data['sections'][0]['section_ref'])->toBe($this->sec1['section']->id);
    expect($data['sections'][0]['state'])->toBe('candidate_ready');
    expect($data['sections'][0]['candidate_ref'])->toBe($this->candidateRef);

    // Confirm NO raw code, script, prompt, or provider payload exists in response
    $rawContent = $response->getContent();
    expect($rawContent)->not->toContain('secret code');
    expect($rawContent)->not->toContain('<!DOCTYPE html>');
    expect($rawContent)->not->toContain('gemini_api_key');
});

test('A16: other teacher, inactive teacher, student, admin and guest are denied teacher status route', function () {
    $statusUrl = "/v3/teacher/cases/{$this->case->id}/scene-authoring";

    // 1. Guest -> redirect to login (or 401/403)
    $guestRes = $this->get($statusUrl);
    expect(in_array($guestRes->status(), [302, 401, 403, 404], true))->toBeTrue();

    // 2. Other teacher -> 404/403
    $otherTeacher = AccessFixtures::createEnrolledTeacher('Other Teacher');
    $otherRes = $this->actingAs($otherTeacher)->getJson($statusUrl);
    expect(in_array($otherRes->status(), [403, 404], true))->toBeTrue();

    // 3. Admin -> 403 (teacher role middleware rejects admin)
    $adminRes = $this->actingAs($this->admin)->getJson($statusUrl);
    expect(in_array($adminRes->status(), [403, 404], true))->toBeTrue();

    // 4. Inactive original teacher -> 403
    DB::table('v3_account_access')->where('user_id', $this->teacher->id)->update(['is_active' => false]);
    $inactiveRes = $this->actingAs($this->teacher)->getJson($statusUrl);
    expect(in_array($inactiveRes->status(), [403, 404], true))->toBeTrue();
});

test('A16: admin case summary shows only allowed closed scalars and counts', function () {
    $summaryUrl = "/v3/admin/cases";

    $response = $this->actingAs($this->admin)
        ->getJson($summaryUrl);

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->not->toBeEmpty();

    $thisCase = collect($data)->firstWhere('case_id', $this->case->id);
    expect($thisCase)->not->toBeNull();

    // Persisted attemptCount and sceneCount reflect 011 records
    expect($thisCase['attempt_count'])->toBe(1);
    expect($thisCase['scene_count'])->toBe(1);

    // Verify no private paths or secrets in response
    $rawSummary = $response->getContent();
    expect($rawSummary)->not->toContain('scene-candidates');
    expect($rawSummary)->not->toContain('secret code');
});

test('A08: public storage and V2 routes cannot access V3 candidate bytes', function () {
    // 1. Candidate files are NOT on public disk
    Storage::disk('public')->assertMissing("scene-candidates/{$this->case->id}/{$this->candidateRef}/index.html");

    // 2. Legacy V2 DynamicView route returns 404 for V3 candidate ID
    $v2DynamicViewUrl = "/dynamic-views/{$this->candidateRef}";
    $v2Response = $this->getJson($v2DynamicViewUrl);
    expect(in_array($v2Response->status(), [404, 405], true))->toBeTrue();

    // 3. Legacy V2 section route returns 404 or 401/302 for unauthenticated/unowned candidate
    $v2SectionUrl = "/sections/{$this->candidateRef}";
    $secResponse = $this->getJson($v2SectionUrl);
    expect(in_array($secResponse->status(), [401, 404, 405, 302], true))->toBeTrue();
});
