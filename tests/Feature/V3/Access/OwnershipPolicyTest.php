<?php

namespace Tests\Feature\V3\Access;

use App\Models\TeacherPermission;
use App\Models\User;
use App\Policies\V3\TeacherResourcePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\OwnedFixture;

beforeEach(function () {
    AccessFixtures::setUpFixtureSchema();
    Gate::policy(OwnedFixture::class, TeacherResourcePolicy::class);

    $this->teacherA = AccessFixtures::createEnrolledTeacher('Teacher A');
    $this->teacherB = AccessFixtures::createEnrolledTeacher('Teacher B');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Boss');

    $this->fixtureA = AccessFixtures::createOwnedFixture($this->teacherA, AccessFixtures::SENTINEL_A);
    $this->fixtureB = AccessFixtures::createOwnedFixture($this->teacherB, AccessFixtures::SENTINEL_B);
});

test('O01: Teacher A can view, update, review and download their own resource', function () {
    $policy = app(TeacherResourcePolicy::class);

    expect($policy->view($this->teacherA, $this->fixtureA))->toBeTrue()
        ->and($policy->update($this->teacherA, $this->fixtureA))->toBeTrue()
        ->and($policy->review($this->teacherA, $this->fixtureA))->toBeTrue()
        ->and($policy->download($this->teacherA, $this->fixtureA))->toBeTrue();
});

test('O02: Teacher B is denied from view, update, review and download of Teacher A resource', function () {
    $policy = app(TeacherResourcePolicy::class);

    expect($policy->view($this->teacherB, $this->fixtureA))->toBeFalse()
        ->and($policy->update($this->teacherB, $this->fixtureA))->toBeFalse()
        ->and($policy->review($this->teacherB, $this->fixtureA))->toBeFalse()
        ->and($policy->download($this->teacherB, $this->fixtureA))->toBeFalse();

    // Verify content and owner are unchanged
    expect($this->fixtureA->fresh()->content)->toBe(AccessFixtures::SENTINEL_A)
        ->and($this->fixtureA->fresh()->owner_user_id)->toBe($this->teacherA->id);
});

test('O04: Admin is denied on all four abilities with no privileged bypass', function () {
    $policy = app(TeacherResourcePolicy::class);

    expect($policy->view($this->admin, $this->fixtureA))->toBeFalse()
        ->and($policy->update($this->admin, $this->fixtureA))->toBeFalse()
        ->and($policy->review($this->admin, $this->fixtureA))->toBeFalse()
        ->and($policy->download($this->admin, $this->fixtureA))->toBeFalse();

    // Also check via Gate
    expect(Gate::forUser($this->admin)->allows('view', $this->fixtureA))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', $this->fixtureA))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('review', $this->fixtureA))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('download', $this->fixtureA))->toBeFalse();
});

test('O05: Legacy TeacherPermission confers no V3 ownership privilege', function () {
    $subjectId = DB::table('subjects')->insertGetId([
        'name' => 'Math',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lectureId = DB::table('lectures')->insertGetId([
        'title' => 'Lecture 1',
        'subject_id' => $subjectId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    TeacherPermission::create([
        'teacher_id' => $this->teacherB->id,
        'subject_id' => $subjectId,
        'lecture_id' => $lectureId,
    ]);

    $policy = app(TeacherResourcePolicy::class);

    expect($policy->view($this->teacherB, $this->fixtureA))->toBeFalse()
        ->and($policy->update($this->teacherB, $this->fixtureA))->toBeFalse()
        ->and($policy->review($this->teacherB, $this->fixtureA))->toBeFalse()
        ->and($policy->download($this->teacherB, $this->fixtureA))->toBeFalse();
});

test('O07: ownedBy query scope isolates list and count strictly to requesting owner', function () {
    // Add more records for A and B
    AccessFixtures::createOwnedFixture($this->teacherA, 'A Second');
    AccessFixtures::createOwnedFixture($this->teacherB, 'B Second');
    AccessFixtures::createOwnedFixture($this->teacherB, 'B Third');

    $queryA = OwnedFixture::ownedBy($this->teacherA);
    expect($queryA->count())->toBe(2);
    foreach ($queryA->get() as $item) {
        expect($item->owner_user_id)->toBe($this->teacherA->id);
    }

    $queryB = OwnedFixture::ownedBy($this->teacherB);
    expect($queryB->count())->toBe(3);
    foreach ($queryB->get() as $item) {
        expect($item->owner_user_id)->toBe($this->teacherB->id);
    }

    // Ineligible user (admin or student) query yields 0 rows
    expect(OwnedFixture::ownedBy($this->admin)->count())->toBe(0);
});

test('O09: direct Eloquent owner modification is rejected and owner remains unchanged', function () {
    $initialOwnerId = $this->fixtureA->owner_user_id;

    expect(function () {
        $this->fixtureA->owner_user_id = $this->teacherB->id;
        $this->fixtureA->save();
    })->toThrow(\InvalidArgumentException::class);

    expect($this->fixtureA->fresh()->owner_user_id)->toBe($initialOwnerId);
});

test('O10: owner suspended or demoted is denied until restored as active teacher', function () {
    $policy = app(TeacherResourcePolicy::class);

    // Initial state: teacher A allowed
    expect($policy->view($this->teacherA, $this->fixtureA))->toBeTrue();

    // 1. Suspend teacher A in DB
    DB::table('v3_account_access')
        ->where('user_id', $this->teacherA->id)
        ->update(['is_active' => false]);

    expect($policy->view($this->teacherA, $this->fixtureA))->toBeFalse();

    // 2. Restore teacher A
    DB::table('v3_account_access')
        ->where('user_id', $this->teacherA->id)
        ->update(['is_active' => true]);

    expect($policy->view($this->teacherA, $this->fixtureA))->toBeTrue();

    // 3. Demote teacher A to student
    DB::table('users')
        ->where('id', $this->teacherA->id)
        ->update(['role' => 'student']);

    expect($policy->view($this->teacherA, $this->fixtureA))->toBeFalse();

    // Owner ID in fixture remains unchanged throughout
    expect($this->fixtureA->fresh()->owner_user_id)->toBe($this->teacherA->id);
});
