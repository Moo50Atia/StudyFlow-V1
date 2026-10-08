<?php

namespace Tests\Feature\V3\Access;

use App\Models\User;
use App\Models\V3\V3AccessEvent;
use App\Models\V3\V3AccountAccess;
use App\Services\V3\Access\ChangeAccountAccess;
use Tests\Support\V3\AccessFixtures;

beforeEach(function () {
    AccessFixtures::setUpFixtureSchema();

    $this->admin = AccessFixtures::createEnrolledAdmin('Active Admin');
    $this->suspendedAdmin = AccessFixtures::createEnrolledAdmin('Suspended Admin');
    V3AccountAccess::where('user_id', $this->suspendedAdmin->id)->update([
        'is_active' => false,
        'disabled_at' => now(),
    ]);

    $this->teacher = AccessFixtures::createEnrolledTeacher('Active Teacher');

    // Demoted teacher (enrolled but demoted to student)
    $this->demotedTeacher = AccessFixtures::createEnrolledTeacher('Demoted Teacher');
    app(ChangeAccountAccess::class)->updateAccess(
        actor: $this->admin,
        targetId: $this->demotedTeacher->id,
        role: 'student',
        isActive: false,
        expectedVersion: 1,
        expectedRole: 'teacher',
        reasonCode: 'change_role'
    );

    $this->student = AccessFixtures::createStudent('Unenrolled Student');
});

test('L10: suspended admin submitting legacy /users create or update is denied 403', function () {
    // 1. Create attempt by suspended admin
    $responseStore = $this->actingAs($this->suspendedAdmin)->post('/users', [
        'name' => 'Bypass User',
        'email' => 'bypass@example.com',
        'password' => 'Password123!',
        'role' => 'admin',
    ]);
    $responseStore->assertStatus(403);
    expect(User::where('email', 'bypass@example.com')->exists())->toBeFalse();

    // 2. Update attempt by suspended admin (attempting to restore self or another)
    $responseUpdate = $this->actingAs($this->suspendedAdmin)->put("/users/{$this->suspendedAdmin->id}", [
        'name' => 'Restored Admin',
        'email' => $this->suspendedAdmin->email,
        'role' => 'admin',
        'expected_version' => 1,
        'expected_role' => 'admin',
    ]);
    $responseUpdate->assertStatus(403);
    expect(V3AccountAccess::find($this->suspendedAdmin->id)->is_active)->toBeFalse();
});

test('L12: admin DELETE /users and owner DELETE /profile for enrolled or demoted account returns 409 and retains account', function () {
    // 1. Admin DELETE /users/{id} for active teacher -> 409
    $resDeleteTeacher = $this->actingAs($this->admin)->delete("/users/{$this->teacher->id}");
    $resDeleteTeacher->assertStatus(409);
    expect(User::find($this->teacher->id))->not->toBeNull()
        ->and(V3AccountAccess::find($this->teacher->id))->not->toBeNull();

    // 2. Admin DELETE /users/{id} for demoted teacher -> 409
    $resDeleteDemoted = $this->actingAs($this->admin)->delete("/users/{$this->demotedTeacher->id}");
    $resDeleteDemoted->assertStatus(409);
    expect(User::find($this->demotedTeacher->id))->not->toBeNull()
        ->and(V3AccountAccess::find($this->demotedTeacher->id))->not->toBeNull();

    // 3. Owner DELETE /profile for active teacher -> 409 and remains authenticated
    $resProfileTeacher = $this->actingAs($this->teacher)->delete('/profile', [
        'password' => 'password',
    ]);
    $resProfileTeacher->assertStatus(409);
    expect(User::find($this->teacher->id))->not->toBeNull()
        ->and(auth()->check())->toBeTrue();
});

test('L13: never-enrolled student deletes own account or is deleted by admin normally', function () {
    // 1. Admin deletes never-enrolled student
    $resAdminDelete = $this->actingAs($this->admin)->delete("/users/{$this->student->id}");
    $resAdminDelete->assertRedirect(route('users.index'));
    expect(User::find($this->student->id))->toBeNull();

    // 2. Student deletes own account via /profile
    $otherStudent = AccessFixtures::createStudent('Other Student');
    $resProfileDelete = $this->actingAs($otherStudent)->delete('/profile', [
        'password' => 'password',
    ]);
    $resProfileDelete->assertRedirect('/');
    expect(User::find($otherStudent->id))->toBeNull()
        ->and(auth()->check())->toBeFalse();
});

test('L18: existing account edit form requires expected version and role; stale returns 409 and missing returns 422', function () {
    // 1. Missing expected_version and expected_role -> 422
    $resMissing = $this->actingAs($this->admin)->put("/users/{$this->teacher->id}", [
        'name' => 'Renamed Teacher',
        'email' => $this->teacher->email,
        'role' => 'teacher',
    ]);
    $resMissing->assertStatus(302)
        ->assertSessionHasErrors(['expected_version', 'expected_role']);

    // 2. Wrong expected_role -> 409
    $resWrongRole = $this->actingAs($this->admin)->put("/users/{$this->teacher->id}", [
        'name' => 'Renamed Teacher',
        'email' => $this->teacher->email,
        'role' => 'teacher',
        'expected_version' => 1,
        'expected_role' => 'student', // wrong
    ]);
    $resWrongRole->assertStatus(409);

    // 3. Stale expected_version -> 409
    $resStaleVersion = $this->actingAs($this->admin)->put("/users/{$this->teacher->id}", [
        'name' => 'Renamed Teacher',
        'email' => $this->teacher->email,
        'role' => 'teacher',
        'expected_version' => 999, // stale
        'expected_role' => 'teacher',
    ]);
    $resStaleVersion->assertStatus(409);

    // 4. Correct expected version/role for teacher name edit -> success
    $resSuccess = $this->actingAs($this->admin)->put("/users/{$this->teacher->id}", [
        'name' => 'Renamed Teacher',
        'email' => $this->teacher->email,
        'role' => 'teacher',
        'expected_version' => 1,
        'expected_role' => 'teacher',
    ]);
    $resSuccess->assertRedirect(route('users.index'));
    expect($this->teacher->fresh()->name)->toBe('Renamed Teacher');
});

test('L19: admin changing enrolled or demoted target email or password returns 403 credential_change_denied', function () {
    $initialTeacherEmail = $this->teacher->email;
    $initialTeacherPassword = $this->teacher->password;

    // 1. Attempt changing email for enrolled teacher -> 403
    $resEmail = $this->actingAs($this->admin)->put("/users/{$this->teacher->id}", [
        'name' => $this->teacher->name,
        'email' => 'newemail@example.com',
        'role' => 'teacher',
        'expected_version' => 1,
        'expected_role' => 'teacher',
    ]);
    $resEmail->assertStatus(403);
    expect($this->teacher->fresh()->email)->toBe($initialTeacherEmail);

    $emailEvent = V3AccessEvent::where('target_user_id', $this->teacher->id)
        ->where('reason_code', 'credential_change_denied')
        ->first();
    expect($emailEvent)->not->toBeNull();

    // 2. Attempt changing password for enrolled teacher -> 403
    $resPassword = $this->actingAs($this->admin)->put("/users/{$this->teacher->id}", [
        'name' => $this->teacher->name,
        'email' => $this->teacher->email,
        'password' => 'NewPassword123!',
        'role' => 'teacher',
        'expected_version' => 1,
        'expected_role' => 'teacher',
    ]);
    $resPassword->assertStatus(403);
    expect($this->teacher->fresh()->password)->toBe($initialTeacherPassword);

    // 3. Attempt changing password for demoted teacher -> 403
    $initialDemotedPassword = $this->demotedTeacher->password;
    $resDemotedPassword = $this->actingAs($this->admin)->put("/users/{$this->demotedTeacher->id}", [
        'name' => $this->demotedTeacher->name,
        'email' => $this->demotedTeacher->email,
        'password' => 'NewPassword123!',
        'role' => 'student',
        'expected_version' => 2,
        'expected_role' => 'student',
    ]);
    $resDemotedPassword->assertStatus(403);
    expect($this->demotedTeacher->fresh()->password)->toBe($initialDemotedPassword);
});
