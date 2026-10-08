<?php

namespace Tests\Feature\V3\SceneQualification;

use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
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
});

test('S01: current same-case candidate claims check once; duplicate returns reconcile', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts";
    $body = [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'attempt_no' => 1,
    ];

    $response = SceneQualificationFixtures::postSigned($this, $path, $body);
    $response->assertStatus(201);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $data = $response->json('data');
    expect($data['state'])->toBe('claimed');
    expect($data['disposition'])->toBe('send_now');
    expect($data['candidate_ref'])->toBe($this->candidate->id);
    expect($data['attempt_ref'])->not->toBeNull();
    $attemptRef = $data['attempt_ref'];

    // Verify candidate state row exists
    $candidateState = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($candidateState)->not->toBeNull();
    expect($candidateState->status)->toBe('checking');
    expect($candidateState->version_no)->toBe(1);

    // Duplicate claim returns same attempt with disposition reconcile
    $dupResponse = SceneQualificationFixtures::postSigned($this, $path, $body);
    $dupResponse->assertStatus(200);
    $dupData = $dupResponse->json('data');
    expect($dupData['attempt_ref'])->toBe($attemptRef);
    expect($dupData['disposition'])->toBe('reconcile');

    // Readback attempt status
    $readPath = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts/{$attemptRef}";
    $readResponse = SceneQualificationFixtures::getSigned($this, $readPath);
    $readResponse->assertStatus(200);
    expect($readResponse->json('data.state'))->toBe('claimed');
});

test('S02: foreign, missing, stale or corrupt candidate returns 404 or 503', function () {
    // Foreign case
    $foreignCaseId = (string) Str::uuid();
    $path = "/api/internal/v1/cases/{$foreignCaseId}/scene-check-attempts";
    $body = [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'attempt_no' => 1,
    ];
    $response = SceneQualificationFixtures::postSigned($this, $path, $body);
    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('scene_unavailable');

    // Non-existent candidate
    $nonCandidate = (string) Str::uuid();
    $path2 = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts";
    $body2 = [
        'schema_version' => 1,
        'candidate_ref' => $nonCandidate,
        'attempt_no' => 1,
    ];
    $res2 = SceneQualificationFixtures::postSigned($this, $path2, $body2);
    $res2->assertStatus(404);

    // Corrupt candidate file on disk
    Storage::disk('v3_private')->delete($this->candidate->manifest_path);
    $res3 = SceneQualificationFixtures::postSigned($this, $path2, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'attempt_no' => 1,
    ]);
    $res3->assertStatus(503);
    expect($res3->json('error.code'))->toBe('scene_unavailable');
});
