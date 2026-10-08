<?php

namespace Tests\Feature\V3\Access;

use App\Models\V3\V3AccessEvent;
use App\Models\V3\V3AccountAccess;
use Tests\Support\V3\AccessFixtures;

beforeEach(function () {
    AccessFixtures::setUpFixtureSchema();

    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Boss');
    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Oak');
    $this->student = AccessFixtures::createStudent('Student Ash');
});

test('M05: admin account list returns only permitted identity/access fields and 20 per page stable order', function () {
    // Create more users to test pagination (total > 20)
    for ($i = 1; $i <= 25; $i++) {
        AccessFixtures::createStudent("Extra Student {$i}");
    }

    $response = $this->actingAs($this->admin)
        ->getJson('/v3/admin/accounts');

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $json = $response->json();
    expect($json)->toHaveKeys(['data', 'links', 'meta'])
        ->and(count($json['data']))->toBe(20);

    // Verify ordering by ID ascending
    $ids = array_column($json['data'], 'id');
    $sortedIds = $ids;
    sort($sortedIds);
    expect($ids)->toBe($sortedIds);

    // Verify exact key set on each item: ONLY id, name, email, role, v3_is_active, v3_version
    $allowedKeys = ['id', 'name', 'email', 'role', 'v3_is_active', 'v3_version'];
    foreach ($json['data'] as $item) {
        $keys = array_keys($item);
        sort($keys);
        sort($allowedKeys);
        expect($keys)->toBe($allowedKeys);

        // Explicitly assert absence of forbidden fields
        expect($item)->not->toHaveKey('password')
            ->and($item)->not->toHaveKey('remember_token')
            ->and($item)->not->toHaveKey('teacher_permissions')
            ->and($item)->not->toHaveKey('email_verified_at');
    }

    // Also verify HTML response renders correctly without error
    $htmlResponse = $this->actingAs($this->admin)
        ->get('/v3/admin/accounts');

    $htmlResponse->assertOk();
    expect($htmlResponse->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $htmlResponse->assertSee('Admin Boss')
        ->assertSee('Teacher Oak');
});

test('M06: teacher requests account list or operations returns 403 with no rows', function () {
    // Account list
    $responseList = $this->actingAs($this->teacher)
        ->getJson('/v3/admin/accounts');

    $responseList->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'access_denied',
                'message' => 'V3 access is unavailable.',
            ],
        ]);

    expect($responseList->getContent())->not->toContain('Teacher Oak')
        ->and($responseList->getContent())->not->toContain('Admin Boss');

    // Account PATCH operation
    $responsePatch = $this->actingAs($this->teacher)
        ->patchJson("/v3/admin/accounts/{$this->student->id}/access", [
            'role' => 'teacher',
            'is_active' => true,
            'expected_version' => 0,
            'expected_role' => 'student',
            'reason_code' => 'grant_access',
        ]);

    $responsePatch->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'access_denied',
                'message' => 'V3 access is unavailable.',
            ],
        ]);
});

test('L06: invalid role, active student, negative version, bad reason or extra fields returns 422 and no mutation', function () {
    $initialEventsCount = V3AccessEvent::count();

    // 1. Active student is invalid
    $res1 = $this->actingAs($this->admin)
        ->patchJson("/v3/admin/accounts/{$this->student->id}/access", [
            'role' => 'student',
            'is_active' => true,
            'expected_version' => 0,
            'expected_role' => 'student',
            'reason_code' => 'grant_access',
        ]);
    $res1->assertStatus(422)
        ->assertJsonValidationErrors(['is_active']);

    // 2. Invalid role
    $res2 = $this->actingAs($this->admin)
        ->patchJson("/v3/admin/accounts/{$this->student->id}/access", [
            'role' => 'superadmin',
            'is_active' => true,
            'expected_version' => 0,
            'expected_role' => 'student',
            'reason_code' => 'grant_access',
        ]);
    $res2->assertStatus(422)
        ->assertJsonValidationErrors(['role']);

    // 3. Negative version
    $res3 = $this->actingAs($this->admin)
        ->patchJson("/v3/admin/accounts/{$this->teacher->id}/access", [
            'role' => 'teacher',
            'is_active' => false,
            'expected_version' => -1,
            'expected_role' => 'teacher',
            'reason_code' => 'suspend_access',
        ]);
    $res3->assertStatus(422)
        ->assertJsonValidationErrors(['expected_version']);

    // 4. Bad reason code
    $res4 = $this->actingAs($this->admin)
        ->patchJson("/v3/admin/accounts/{$this->teacher->id}/access", [
            'role' => 'teacher',
            'is_active' => false,
            'expected_version' => 1,
            'expected_role' => 'teacher',
            'reason_code' => 'invalid_reason',
        ]);
    $res4->assertStatus(422)
        ->assertJsonValidationErrors(['reason_code']);

    // 5. Extra fields rejected
    $res5 = $this->actingAs($this->admin)
        ->patchJson("/v3/admin/accounts/{$this->teacher->id}/access", [
            'role' => 'teacher',
            'is_active' => false,
            'expected_version' => 1,
            'expected_role' => 'teacher',
            'reason_code' => 'suspend_access',
            'extra_field' => 'malicious_input',
        ]);
    $res5->assertStatus(422);

    // Verify zero mutations occurred
    expect(V3AccessEvent::count())->toBe($initialEventsCount)
        ->and(V3AccountAccess::find($this->teacher->id)->version)->toBe(1)
        ->and(V3AccountAccess::find($this->student->id))->toBeNull();
});

test('PATCH account access: JSON and Browser success, conflict and missing target', function () {
    // 1. JSON Success: suspend teacher
    $patchRes = $this->actingAs($this->admin)
        ->patchJson("/v3/admin/accounts/{$this->teacher->id}/access", [
            'role' => 'teacher',
            'is_active' => false,
            'expected_version' => 1,
            'expected_role' => 'teacher',
            'reason_code' => 'suspend_access',
        ]);

    $patchRes->assertOk();
    expect($patchRes->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $patchRes->assertJson([
        'data' => [
            'id' => $this->teacher->id,
            'role' => 'teacher',
            'is_active' => false,
            'version' => 2,
            'changed' => true,
        ],
    ]);

    // 2. JSON Stale version returns 409 conflict
    $staleRes = $this->actingAs($this->admin)
        ->patchJson("/v3/admin/accounts/{$this->teacher->id}/access", [
            'role' => 'teacher',
            'is_active' => false,
            'expected_version' => 1, // now version is 2
            'expected_role' => 'teacher',
            'reason_code' => 'suspend_access',
        ]);

    $staleRes->assertStatus(409);
    expect($staleRes->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $staleRes->assertJson([
        'error' => [
            'code' => 'access_conflict',
            'message' => 'V3 access is unavailable.',
        ],
    ]);

    // 3. Browser Conflict returns 409 page
    $browserStale = $this->actingAs($this->admin)
        ->patch("/v3/admin/accounts/{$this->teacher->id}/access", [
            'role' => 'teacher',
            'is_active' => '0',
            'expected_version' => 1,
            'expected_role' => 'teacher',
            'reason_code' => 'suspend_access',
        ]);

    $browserStale->assertStatus(409);
    expect($browserStale->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $browserStale->assertSee('Access Conflict');

    // 4. Missing target returns 404
    $missingRes = $this->actingAs($this->admin)
        ->patchJson('/v3/admin/accounts/999999/access', [
            'role' => 'teacher',
            'is_active' => false,
            'expected_version' => 0,
            'expected_role' => 'student',
            'reason_code' => 'grant_access',
        ]);

    $missingRes->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'resource_unavailable',
                'message' => 'V3 access is unavailable.',
            ],
        ]);

    // 5. Browser Success: restore teacher -> 303 redirect to account list
    $browserSuccess = $this->actingAs($this->admin)
        ->patch("/v3/admin/accounts/{$this->teacher->id}/access", [
            'role' => 'teacher',
            'is_active' => '1',
            'expected_version' => 2,
            'expected_role' => 'teacher',
            'reason_code' => 'restore_access',
        ]);

    $browserSuccess->assertStatus(303)
        ->assertRedirect(route('v3.admin.accounts.index'))
        ->assertSessionHas('status');
});
