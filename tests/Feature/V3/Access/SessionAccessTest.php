<?php

namespace Tests\Feature\V3\Access;

use App\Models\User;
use App\Services\V3\Access\V3AccessResolver;
use Illuminate\Support\Facades\DB;
use Tests\Support\V3\AccessFixtures;

test('A06: existing session is denied immediately on next request when another actor suspends the account', function () {
    $teacher = AccessFixtures::createEnrolledTeacher('Teacher Active');

    // First request passes
    $this->actingAs($teacher)->get('/v3/teacher')->assertStatus(200);

    // Another actor suspends the account in DB
    DB::table('v3_account_access')
        ->where('user_id', $teacher->id)
        ->update([
            'is_active' => false,
            'disabled_at' => now(),
            'updated_at' => now(),
        ]);

    // Next request with existing session MUST be denied (403)
    $this->actingAs($teacher)->get('/v3/teacher')->assertStatus(403);
    $this->actingAs($teacher)->getJson('/v3/teacher')->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'access_denied',
                'message' => 'V3 access is unavailable.',
            ],
        ]);

    // User row is retained in database
    expect(User::where('id', $teacher->id)->exists())->toBeTrue();
});

test('A07: existing teacher session promoted to admin reflects role change immediately', function () {
    $user = AccessFixtures::createEnrolledTeacher('Promoted Teacher');
    $fixture = AccessFixtures::createOwnedFixture($user, 'Original Content');

    // As teacher, teacher workspace passes and admin workspace fails
    $this->actingAs($user)->get('/v3/teacher')->assertStatus(200);
    $this->actingAs($user)->get('/v3/admin')->assertStatus(403);

    // Promote in DB to admin
    DB::table('users')
        ->where('id', $user->id)
        ->update(['role' => 'admin']);

    // Next request: teacher area now denied (403), admin area allowed (200)
    $this->actingAs($user)->get('/v3/teacher')->assertStatus(403);
    $this->actingAs($user)->get('/v3/admin')->assertStatus(200);

    // Owned fixture owner remains unchanged
    expect($fixture->fresh()->owner_user_id)->toBe($user->id);
});

test('A08: suspended account restored explicitly is allowed on next request without owner change', function () {
    $teacher = AccessFixtures::createEnrolledTeacher('Suspended Teacher', isActive: false);
    $fixture = AccessFixtures::createOwnedFixture($teacher, 'Teacher Fixture Content');

    // Initially suspended -> denied 403
    $this->actingAs($teacher)->get('/v3/teacher')->assertStatus(403);

    // Restore in DB
    DB::table('v3_account_access')
        ->where('user_id', $teacher->id)
        ->update([
            'is_active' => true,
            'disabled_at' => null,
            'updated_at' => now(),
        ]);

    // Next request is allowed (200)
    $this->actingAs($teacher)->get('/v3/teacher')->assertStatus(200);

    // Owner remains unchanged
    expect($fixture->fresh()->owner_user_id)->toBe($teacher->id);
});

test('A12: authorizer database exception returns 503 safe response and protected controller never executes', function () {
    $teacher = AccessFixtures::createEnrolledTeacher('Teacher For DB Fail');

    // Mock or bind V3AccessResolver to simulate database failure during authorization
    $this->mock(V3AccessResolver::class, function ($mock) {
        $mock->shouldReceive('resolve')
            ->andThrow(new \Illuminate\Database\QueryException('sqlite', 'SELECT * FROM users', [], new \Exception('Database unavailable')));
    });

    $responseHtml = $this->actingAs($teacher)->get('/v3/teacher');
    $responseHtml->assertStatus(503);

    $responseJson = $this->actingAs($teacher)->getJson('/v3/teacher');
    $responseJson->assertStatus(503)
        ->assertJson([
            'error' => [
                'code' => 'access_unavailable',
                'message' => 'V3 access is temporarily unavailable.',
            ],
        ]);
});
