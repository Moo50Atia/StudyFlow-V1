<?php

namespace Tests\Feature\V3\SceneQualification;

use App\Models\User;
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
    $this->teacher = $this->env['teacher'];
});

test('S20: unsigned internal check claim is rejected with 401', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-check-attempts";
    $res = $this->postJson($path, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'attempt_no' => 1,
    ]);

    $res->assertStatus(401);
});

test('S20: teacher qualification status requires case owner and rejects foreign teacher', function () {
    $statusUrl = "/v3/teacher/cases/{$this->case->id}/scene-qualification";

    // Unauthenticated -> redirect or 401
    $resUnauth = $this->getJson($statusUrl);
    expect($resUnauth->status())->toBeIn([401, 403, 404]);

    // Foreign teacher -> 403/404
    $foreignTeacher = User::factory()->create(['role' => 'teacher']);
    $resForeign = $this->actingAs($foreignTeacher)->getJson($statusUrl);
    expect($resForeign->status())->toBeIn([403, 404]);

    // Owner teacher -> 200
    $resOwner = $this->actingAs($this->teacher)->getJson($statusUrl);
    $resOwner->assertStatus(200);
});
