<?php

namespace Tests\Feature\V3\Admin;

use App\Models\User;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\V3AccountAccess;
use App\Services\V3\Access\ChangeAccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    AccessFixtures::setUpFixtureSchema();

    $this->admin = AccessFixtures::createEnrolledAdmin('Primary Admin');
    $this->secondAdmin = AccessFixtures::createEnrolledAdmin('Secondary Admin');

    $this->suspendedAdmin = AccessFixtures::createEnrolledAdmin('Suspended Admin');
    V3AccountAccess::where('user_id', $this->suspendedAdmin->id)->update([
        'is_active' => false,
        'disabled_at' => now(),
    ]);

    $this->teacher = AccessFixtures::createEnrolledTeacher('Enrolled Teacher');
    $this->student = AccessFixtures::createStudent('Regular Student');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
    $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $canonical,
        'checksum' => $checksum,
        'published_by_user_id' => $this->admin->id,
        'published_at' => now(),
    ]);
});

test('O19: existing /users creation enrolls teacher through 004 service; V3 list/access PATCH stay on 004 authorities', function () {
    // 1. Admin creates teacher via legacy /users -> 004 service auto-enrolls with active=true, version=1
    $resp = $this->actingAs($this->admin)->post('/users', [
        'name' => 'New Teacher',
        'email' => 'new_teacher@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role' => 'teacher',
    ]);
    $resp->assertRedirect(route('users.index'));

    $newUser = User::where('email', 'new_teacher@example.com')->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->role)->toBe('teacher');

    $access = V3AccountAccess::find($newUser->id);
    expect($access)->not->toBeNull()
        ->and($access->is_active)->toBeTrue()
        ->and($access->version)->toBe(1);

    // 2. V3 admin accounts list returns 200 with permitted fields
    $listResp = $this->actingAs($this->admin)->getJson('/v3/admin/accounts');
    $listResp->assertStatus(200);
    $listResp->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'name',
                'email',
                'role',
                'v3_is_active',
                'v3_version',
            ],
        ],
    ]);

    // 3. V3 admin accounts PATCH updates access via 004 ChangeAccountAccess
    $patchResp = $this->actingAs($this->admin)->patchJson("/v3/admin/accounts/{$newUser->id}/access", [
        'role' => 'teacher',
        'is_active' => false,
        'expected_version' => 1,
        'expected_role' => 'teacher',
        'reason_code' => 'suspend_access',
    ]);
    $patchResp->assertStatus(200);
    expect(V3AccountAccess::find($newUser->id)->is_active)->toBeFalse()
        ->and(V3AccountAccess::find($newUser->id)->version)->toBe(2);

    // 4. Inactive/suspended admin cannot use /users to create accounts
    $suspendedCreateResp = $this->actingAs($this->suspendedAdmin)->post('/users', [
        'name' => 'Bypass Teacher',
        'email' => 'bypass@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role' => 'teacher',
    ]);
    $suspendedCreateResp->assertStatus(403);
    expect(User::where('email', 'bypass@example.com')->exists())->toBeFalse();

    // 5. Inactive/suspended admin cannot use /users to promote self or restore active status
    $suspendedPutResp = $this->actingAs($this->suspendedAdmin)->put("/users/{$this->suspendedAdmin->id}", [
        'name' => 'Self Promoted Admin',
        'email' => $this->suspendedAdmin->email,
        'role' => 'admin',
        'expected_version' => 1,
        'expected_role' => 'admin',
    ]);
    $suspendedPutResp->assertStatus(403);
    expect(V3AccountAccess::find($this->suspendedAdmin->id)->is_active)->toBeFalse();
});

test('O20: 004 self/last-admin, enrolled credential edit/delete, and original-owner retention guards hold', function () {
    // 1. Last active admin protection: deactivating or demoting the last active admin fails
    // Make secondAdmin inactive first
    V3AccountAccess::where('user_id', $this->secondAdmin->id)->update(['is_active' => false]);

    // Now $this->admin is the only remaining active admin; demoting or disabling self returns 409
    $lastAdminResp = $this->actingAs($this->admin)->patchJson("/v3/admin/accounts/{$this->admin->id}/access", [
        'role' => 'admin',
        'is_active' => false,
        'expected_version' => V3AccountAccess::find($this->admin->id)->version,
        'expected_role' => 'admin',
        'reason_code' => 'suspend_access',
    ]);
    $lastAdminResp->assertStatus(409);
    expect(V3AccountAccess::find($this->admin->id)->is_active)->toBeTrue();

    // 2. Admin cannot DELETE an enrolled teacher via /users/{id} -> returns 409 and retains account
    $delTeacherResp = $this->actingAs($this->admin)->delete("/users/{$this->teacher->id}");
    $delTeacherResp->assertStatus(409);
    expect(User::find($this->teacher->id))->not->toBeNull()
        ->and(V3AccountAccess::find($this->teacher->id))->not->toBeNull();

    // 3. Enrolled teacher cannot self-delete via /profile -> returns 409
    $selfDelResp = $this->actingAs($this->teacher)->delete('/profile', [
        'password' => 'password',
    ]);
    $selfDelResp->assertStatus(409);
    expect(User::find($this->teacher->id))->not->toBeNull();

    // 4. Original owner retention: disabling teacher retains case ownership
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $caseOwnerId = $case->owner_user_id;

    V3AccountAccess::where('user_id', $caseOwnerId)->update(['is_active' => false]);
    $case->refresh();
    expect($case->owner_user_id)->toBe($caseOwnerId);

    // 5. Eloquent boot prevents directly mutating owner_user_id
    expect(function () use ($case) {
        $case->owner_user_id = $this->admin->id;
        $case->save();
    })->toThrow(\InvalidArgumentException::class);
});

test('O21: admin credentials cannot access 013 teacher review routes; no impersonation or owner transfer', function () {
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $case->state = 'awaiting_review';
    $case->state_version = 2;
    $case->save();

    $qualified = ReviewFixtures::createQualifiedCandidate($env, 1);
    $reviewItem = TeacherReviewItem::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'section_id' => $qualified['section']->id,
        'candidate_id' => $qualified['candidate']->id,
        'qualification_id' => $qualified['qualification']->id,
        'prompt_version_id' => $qualified['candidate']->prompt_version_id,
        'candidate_version' => 1,
        'status' => 'pending',
        'state_version' => 1,
        'ready_at' => now(),
    ]);

    // 1. Admin GET review index -> 403
    $this->actingAs($this->admin)->get("/v3/teacher/cases/{$case->id}/reviews")->assertStatus(403);

    // 2. Admin GET review show -> 403
    $this->actingAs($this->admin)->get("/v3/teacher/cases/{$case->id}/reviews/{$reviewItem->id}")->assertStatus(403);

    // 3. Admin POST review decision -> 403
    $this->actingAs($this->admin)->postJson("/v3/teacher/cases/{$case->id}/reviews/{$reviewItem->id}/decisions", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_review_version' => 1,
        'decision' => 'approve',
    ])->assertStatus(403);

    // 4. Admin GET approved-scenes index -> 403
    $this->actingAs($this->admin)->get("/v3/teacher/cases/{$case->id}/approved-scenes")->assertStatus(403);

    // 5. Admin POST preview viewer session -> 403
    $this->actingAs($this->admin)->postJson("/v3/teacher/cases/{$case->id}/reviews/{$reviewItem->id}/viewer-sessions", [
        'client_instance_id' => 'admin-test-instance',
    ])->assertStatus(403);
});

test('architectural constraint: spec 014 does not register a duplicate account controller or writer', function () {
    // Verify that the route for account management resolves to 004 AccountController
    $route = Route::getRoutes()->getByName('v3.admin.accounts.index');
    expect($route)->not->toBeNull();
    $actionName = $route->getActionName();
    expect($actionName)->toContain('App\Http\Controllers\V3\Admin\AccountController@index');

    // Verify there is no separate 014 AccountController in the codebase
    expect(class_exists('App\Http\Controllers\V3\Admin\AdminAccountController'))->toBeFalse();
});
