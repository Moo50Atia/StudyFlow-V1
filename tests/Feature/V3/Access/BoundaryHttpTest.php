<?php

namespace Tests\Feature\V3\Access;

use App\Policies\V3\TeacherResourcePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\FixtureRoutes;
use Tests\Support\V3\OwnedFixture;

beforeEach(function () {
    AccessFixtures::setUpFixtureSchema();
    FixtureRoutes::register();
    Gate::policy(OwnedFixture::class, TeacherResourcePolicy::class);

    $this->teacherA = AccessFixtures::createEnrolledTeacher('Teacher A');
    $this->teacherB = AccessFixtures::createEnrolledTeacher('Teacher B');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

    $this->fixtureA = AccessFixtures::createOwnedFixture($this->teacherA, AccessFixtures::SENTINEL_A);
    $this->fixtureB = AccessFixtures::createOwnedFixture($this->teacherB, AccessFixtures::SENTINEL_B);
});

test('O03: requesting absent ID versus foreign existing ID yields identical safe 404 response without existence leak', function () {
    $absentId = (string) Str::uuid();

    // Teacher B requests absent ID
    $responseAbsent = $this->actingAs($this->teacherB)->getJson("/v3/test-fixtures/resources/{$absentId}");
    $responseAbsent->assertStatus(404);

    // Teacher B requests Teacher A's existing ID
    $responseForeign = $this->actingAs($this->teacherB)->getJson("/v3/test-fixtures/resources/{$this->fixtureA->id}");
    $responseForeign->assertStatus(404);

    // Bodies must be identical (safe error shape)
    expect($responseAbsent->json())->toEqual($responseForeign->json())
        ->and($responseForeign->json('error.code'))->toBe('resource_unavailable');

    // Both must have Cache-Control: private, no-store
    expect($responseAbsent->headers->get('Cache-Control'))->toContain('no-store')
        ->and($responseForeign->headers->get('Cache-Control'))->toContain('no-store');
});

test('O06: parent-child relationship check denies request when child belongs to another parent even if caller owns both parents', function () {
    // Teacher A creates Parent 1 and Child 1
    $parent1 = AccessFixtures::createOwnedFixture($this->teacherA, 'Parent 1');
    $child1 = AccessFixtures::createOwnedFixture($this->teacherA, 'Child 1', parentId: $parent1->id);

    // Teacher A creates Parent 2 (no children)
    $parent2 = AccessFixtures::createOwnedFixture($this->teacherA, 'Parent 2');

    // Legitimate query: parent 1 with child 1 -> 200
    $responseValid = $this->actingAs($this->teacherA)->getJson("/v3/test-fixtures/parents/{$parent1->id}/children/{$child1->id}");
    $responseValid->assertStatus(200);

    // Substitution attack: parent 2 with child 1 -> 404
    $responseSubstituted = $this->actingAs($this->teacherA)->getJson("/v3/test-fixtures/parents/{$parent2->id}/children/{$child1->id}");
    $responseSubstituted->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'resource_unavailable',
                'message' => 'V3 resource is unavailable.',
            ],
        ]);

    // Foreign parent by teacher B
    $parentB = AccessFixtures::createOwnedFixture($this->teacherB, 'Parent B');
    $responseForeignParent = $this->actingAs($this->teacherA)->getJson("/v3/test-fixtures/parents/{$parentB->id}/children/{$child1->id}");
    $responseForeignParent->assertStatus(404);
});

test('O08: client POST and PATCH requests including owner_user_id are rejected with 422 and owner remains unchanged', function () {
    // 1. POST creation attempting to supply foreign owner_user_id
    $postResponse = $this->actingAs($this->teacherA)->postJson('/v3/test-fixtures/resources', [
        'content' => 'Malicious creation',
        'owner_user_id' => $this->teacherB->id,
    ]);
    $postResponse->assertStatus(422);

    // 2. PATCH update attempting to reassign owner_user_id
    $initialOwner = $this->fixtureA->owner_user_id;
    $patchResponse = $this->actingAs($this->teacherA)->patchJson("/v3/test-fixtures/resources/{$this->fixtureA->id}", [
        'content' => 'Updated content',
        'owner_user_id' => $this->teacherB->id,
    ]);
    $patchResponse->assertStatus(422);

    // Persisted owner is unchanged
    expect($this->fixtureA->fresh()->owner_user_id)->toBe($initialOwner);
});

test('O11: foreign download, malformed ID or nonexistent ID streams zero bytes and returns identical safe 404', function () {
    $absentId = (string) Str::uuid();

    // Foreign download by Teacher B
    $responseForeign = $this->actingAs($this->teacherB)->get("/v3/test-fixtures/resources/{$this->fixtureA->id}/download");
    $responseForeign->assertStatus(404);
    expect($responseForeign->getContent())->not->toContain(AccessFixtures::SENTINEL_A);

    // Absent download by Teacher B
    $responseAbsent = $this->actingAs($this->teacherB)->get("/v3/test-fixtures/resources/{$absentId}/download");
    $responseAbsent->assertStatus(404);

    // Malformed ID
    $responseMalformed = $this->actingAs($this->teacherB)->get('/v3/test-fixtures/resources/not-a-uuid/download');
    $responseMalformed->assertStatus(404);

    // Response bodies match
    expect($responseForeign->getContent())->toBe($responseAbsent->getContent());
});

test('A13: V3 GET/error and mutation without CSRF returns no-store on all responses, and mutation returns 419 with no write when real CSRF check enabled', function () {
    // 1. All V3 GET, error and denial responses have Cache-Control: private, no-store
    $getRes = $this->actingAs($this->teacherA)->get('/v3/teacher');
    $getRes->assertOk();
    expect($getRes->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $deniedRes = $this->actingAs($this->teacherA)->get('/v3/admin');
    $deniedRes->assertStatus(403);
    expect($deniedRes->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    // 2. Real CSRF checking subclass
    $testCsrf = new class($this->app, app(\Illuminate\Contracts\Encryption\Encrypter::class)) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    };
    $this->app->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $testCsrf);

    $initialVersion = $this->teacherA->fresh()->v3Access?->version ?? 1;

    // PATCH mutation without CSRF token
    $resWithoutCsrf = $this->actingAs($this->admin)->patch("/v3/admin/accounts/{$this->teacherA->id}/access", [
        'role' => 'teacher',
        'is_active' => '0',
        'expected_version' => $initialVersion,
        'expected_role' => 'teacher',
        'reason_code' => 'suspend_access',
    ]);

    $resWithoutCsrf->assertStatus(419);
    expect($resWithoutCsrf->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    // Assert zero mutations occurred
    expect(\App\Models\V3\V3AccountAccess::find($this->teacherA->id)->version)->toBe($initialVersion);
});
