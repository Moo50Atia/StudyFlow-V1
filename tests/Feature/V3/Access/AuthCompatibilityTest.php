<?php

namespace Tests\Feature\V3\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\Support\V3\AccessFixtures;

test('A09: POST registration with forged role, access, or owner fields creates student with no V3 access row', function () {
    $response = $this->post('/register', [
        'name' => 'Forged Hacker',
        'email' => 'hacker@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'admin',
        'is_active' => true,
        'expected_version' => 1,
        'owner_user_id' => 1,
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'hacker@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->role)->toBe('student');

    // No V3 account access row provisioned
    $access = DB::table('v3_account_access')->where('user_id', $user->id)->first();
    expect($access)->toBeNull();

    // Access to V3 is denied
    $this->actingAs($user)->get('/v3')->assertStatus(403);
});

test('A10: password reset for suspended account succeeds but V3 remains denied and logout invalidates session', function () {
    $teacher = AccessFixtures::createEnrolledTeacher('Suspended Teacher', 'suspended_pw@example.com', isActive: false);

    // Initial state: V3 is denied
    $this->actingAs($teacher)->get('/v3/teacher')->assertStatus(403);

    // Create password reset token
    $token = Password::createToken($teacher);

    // Submit password reset
    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => $teacher->email,
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ]);

    $response->assertSessionHasNoErrors();

    // Authenticate with new password
    $this->post('/login', [
        'email' => $teacher->email,
        'password' => 'new-secure-password',
    ]);

    $this->assertAuthenticatedAs($teacher);

    // V3 workspace is STILL denied (403)
    $this->get('/v3/teacher')->assertStatus(403);

    // Logout
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();

    // Next request to V3 redirects to login
    $this->get('/v3/teacher')->assertRedirect(route('login'));
});

test('A11: invalid credentials and throttled login behave securely without granting V3 access', function () {
    $teacher = AccessFixtures::createEnrolledTeacher('Target Teacher', 'throttle_target@example.com');

    // Attempt 5 bad logins to trigger throttle
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', [
            'email' => $teacher->email,
            'password' => 'wrong-password',
        ]);
        $this->assertGuest();
    }

    // 6th attempt should be throttled
    $throttledResponse = $this->post('/login', [
        'email' => $teacher->email,
        'password' => 'wrong-password',
    ]);

    $throttledResponse->assertSessionHasErrors('email');
    $this->assertGuest();

    // Guest visiting V3 is redirected to login
    $this->get('/v3/teacher')->assertRedirect(route('login'));
});

test('L11: profile update cannot inject role or access escalation', function () {
    $teacher = AccessFixtures::createEnrolledTeacher('Modest Teacher');

    // Attempt to escalate to admin or alter access flags via profile patch
    $response = $this->actingAs($teacher)->patch('/profile', [
        'name' => 'Modest Teacher Renamed',
        'email' => $teacher->email,
        'role' => 'admin',
        'is_active' => false,
        'version' => 99,
    ]);

    $teacher->refresh();
    expect($teacher->name)->toBe('Modest Teacher Renamed')
        ->and($teacher->role)->toBe('teacher');

    $access = DB::table('v3_account_access')->where('user_id', $teacher->id)->first();
    expect((bool) $access->is_active)->toBeTrue()
        ->and((int) $access->version)->toBe(1);
});
