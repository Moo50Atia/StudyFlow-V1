<?php

namespace Tests\Feature\V3\Access;

use App\Models\V3\V3AccessEvent;
use App\Models\V3\V3AccountAccess;
use App\Services\V3\Access\ChangeAccountAccess;
use Tests\Support\V3\AccessFixtures;

beforeEach(function () {
    AccessFixtures::setUpFixtureSchema();

    $this->admin1 = AccessFixtures::createEnrolledAdmin('Admin Primary');
    $this->admin2 = AccessFixtures::createEnrolledAdmin('Admin Secondary');
    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Subject');
    $this->student = AccessFixtures::createStudent('Student Unenrolled');
});

test('L03: admin grants, suspends and restores target with correct version transition and atomic audit event', function () {
    $service = app(ChangeAccountAccess::class);

    // 1. Grant access to student (promote to teacher and grant)
    $grantResult = $service->updateAccess(
        actor: $this->admin1,
        targetId: $this->student->id,
        role: 'teacher',
        isActive: true,
        expectedVersion: 0,
        expectedRole: 'student',
        reasonCode: 'grant_access'
    );

    expect($grantResult['changed'])->toBeTrue()
        ->and($grantResult['role'])->toBe('teacher')
        ->and($grantResult['is_active'])->toBeTrue()
        ->and($grantResult['version'])->toBe(1);

    $access = V3AccountAccess::find($this->student->id);
    expect($access)->not->toBeNull()
        ->and($access->is_active)->toBeTrue()
        ->and($access->version)->toBe(1)
        ->and($access->disabled_at)->toBeNull();

    $grantEvent = V3AccessEvent::where('target_user_id', $this->student->id)
        ->where('reason_code', 'grant_access')
        ->first();
    expect($grantEvent)->not->toBeNull()
        ->and($grantEvent->actor_user_id)->toBe($this->admin1->id);

    // 2. Suspend access for teacher
    $suspendResult = $service->updateAccess(
        actor: $this->admin1,
        targetId: $this->teacher->id,
        role: 'teacher',
        isActive: false,
        expectedVersion: 1,
        expectedRole: 'teacher',
        reasonCode: 'suspend_access'
    );

    expect($suspendResult['changed'])->toBeTrue()
        ->and($suspendResult['is_active'])->toBeFalse()
        ->and($suspendResult['version'])->toBe(2);

    $teacherAccess = V3AccountAccess::find($this->teacher->id);
    expect($teacherAccess->is_active)->toBeFalse()
        ->and($teacherAccess->version)->toBe(2)
        ->and($teacherAccess->disabled_at)->not->toBeNull();

    // 3. Restore access for teacher
    $restoreResult = $service->updateAccess(
        actor: $this->admin1,
        targetId: $this->teacher->id,
        role: 'teacher',
        isActive: true,
        expectedVersion: 2,
        expectedRole: 'teacher',
        reasonCode: 'restore_access'
    );

    expect($restoreResult['changed'])->toBeTrue()
        ->and($restoreResult['is_active'])->toBeTrue()
        ->and($restoreResult['version'])->toBe(3);

    $teacherAccess->refresh();
    expect($teacherAccess->is_active)->toBeTrue()
        ->and($teacherAccess->version)->toBe(3)
        ->and($teacherAccess->disabled_at)->toBeNull();
});

test('L04: demote teacher to student retains inactive row and subsequent legacy promotion does not implicitly restore active', function () {
    $service = app(ChangeAccountAccess::class);
    $fixture = AccessFixtures::createOwnedFixture($this->teacher, 'Teacher Content');

    // Demote teacher to student
    $demoteResult = $service->updateAccess(
        actor: $this->admin1,
        targetId: $this->teacher->id,
        role: 'student',
        isActive: false,
        expectedVersion: 1,
        expectedRole: 'teacher',
        reasonCode: 'change_role'
    );

    expect($demoteResult['role'])->toBe('student')
        ->and($demoteResult['is_active'])->toBeFalse()
        ->and($demoteResult['version'])->toBe(2);

    // Row is retained
    $access = V3AccountAccess::find($this->teacher->id);
    expect($access)->not->toBeNull()
        ->and($access->is_active)->toBeFalse();

    // Owner ID in fixture is unchanged
    expect($fixture->fresh()->owner_user_id)->toBe($this->teacher->id);

    // Promote back via legacy role-only update
    $legacyPromoteResult = $service->updateFromLegacy(
        actor: $this->admin1,
        targetId: $this->teacher->id,
        identityData: ['name' => $this->teacher->name, 'email' => $this->teacher->email, 'role' => 'teacher'],
        expectedVersion: 2,
        expectedRole: 'student'
    );

    expect($legacyPromoteResult['role'])->toBe('teacher');

    // Retains inactive state! No implicit restoration!
    $access->refresh();
    expect($access->is_active)->toBeFalse()
        ->and($access->version)->toBe(3);
});

test('L05: same desired state with current version is no-op, while stale version request returns 409 conflict', function () {
    $service = app(ChangeAccountAccess::class);

    $initialEventCount = V3AccessEvent::where('target_user_id', $this->teacher->id)->count();

    // 1. Same state, current version -> no-op
    $noopResult = $service->updateAccess(
        actor: $this->admin1,
        targetId: $this->teacher->id,
        role: 'teacher',
        isActive: true,
        expectedVersion: 1,
        expectedRole: 'teacher',
        reasonCode: 'restore_access'
    );

    expect($noopResult['changed'])->toBeFalse()
        ->and($noopResult['version'])->toBe(1);

    // No new audit event inserted
    expect(V3AccessEvent::where('target_user_id', $this->teacher->id)->count())->toBe($initialEventCount);

    // 2. Stale version -> 409 conflict
    $threwConflict = false;
    try {
        $service->updateAccess(
            actor: $this->admin1,
            targetId: $this->teacher->id,
            role: 'teacher',
            isActive: false,
            expectedVersion: 0, // stale (actual is 1)
            expectedRole: 'teacher',
            reasonCode: 'suspend_access'
        );
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        if ($e->getStatusCode() === 409) {
            $threwConflict = true;
        }
    }
    expect($threwConflict)->toBeTrue();
});

test('L06: invalid role, active student, or reason mismatch throws validation exception with no mutation', function () {
    $service = app(ChangeAccountAccess::class);

    // 1. Student with isActive=true is forbidden
    expect(fn () => $service->updateAccess(
        actor: $this->admin1,
        targetId: $this->student->id,
        role: 'student',
        isActive: true,
        expectedVersion: 0,
        expectedRole: 'student',
        reasonCode: 'grant_access'
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    // 2. Reason code mismatch for suspension (using grant_access for suspend)
    expect(fn () => $service->updateAccess(
        actor: $this->admin1,
        targetId: $this->teacher->id,
        role: 'teacher',
        isActive: false,
        expectedVersion: 1,
        expectedRole: 'teacher',
        reasonCode: 'grant_access'
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    // Access row unchanged
    expect(V3AccountAccess::find($this->teacher->id)->is_active)->toBeTrue();
});

test('L07: self-disable, self-demotion, or removing the last active admin is rejected with 409', function () {
    $service = app(ChangeAccountAccess::class);

    // 1. Self-disablement
    $threwSelfDisable = false;
    try {
        $service->updateAccess(
            actor: $this->admin1,
            targetId: $this->admin1->id,
            role: 'admin',
            isActive: false,
            expectedVersion: 1,
            expectedRole: 'admin',
            reasonCode: 'suspend_access'
        );
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        if ($e->getStatusCode() === 409) {
            $threwSelfDisable = true;
        }
    }
    expect($threwSelfDisable)->toBeTrue();

    // 2. Self-demotion
    $threwSelfDemote = false;
    try {
        $service->updateAccess(
            actor: $this->admin1,
            targetId: $this->admin1->id,
            role: 'teacher',
            isActive: true,
            expectedVersion: 1,
            expectedRole: 'admin',
            reasonCode: 'change_role'
        );
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        if ($e->getStatusCode() === 409) {
            $threwSelfDemote = true;
        }
    }
    expect($threwSelfDemote)->toBeTrue();

    // 3. Suspend admin2 so admin1 is the LAST active admin
    $service->updateAccess(
        actor: $this->admin1,
        targetId: $this->admin2->id,
        role: 'admin',
        isActive: false,
        expectedVersion: 1,
        expectedRole: 'admin',
        reasonCode: 'suspend_access'
    );

    // Now attempt to demote or suspend admin1 (even if attempted by admin1 or anyone)
    $threwFinalAdmin = false;
    try {
        $service->updateAccess(
            actor: $this->admin1,
            targetId: $this->admin1->id,
            role: 'teacher',
            isActive: true,
            expectedVersion: 1,
            expectedRole: 'admin',
            reasonCode: 'change_role'
        );
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        if ($e->getStatusCode() === 409) {
            $threwFinalAdmin = true;
        }
    }
    expect($threwFinalAdmin)->toBeTrue();
});

test('L14: audit failure during ChangeAccountAccess rolls back user and access row mutations', function () {
    $service = app(ChangeAccountAccess::class);
    $initialTeacherVersion = V3AccountAccess::find($this->teacher->id)->version;

    \Illuminate\Support\Facades\Schema::rename('v3_access_events', 'v3_access_events_temp');
    try {
        expect(fn () => $service->updateAccess(
            actor: $this->admin1,
            targetId: $this->teacher->id,
            role: 'teacher',
            isActive: false,
            expectedVersion: $initialTeacherVersion,
            expectedRole: 'teacher',
            reasonCode: 'suspend_access'
        ))->toThrow(\Illuminate\Database\QueryException::class);
    } finally {
        \Illuminate\Support\Facades\Schema::rename('v3_access_events_temp', 'v3_access_events');
    }

    $access = V3AccountAccess::find($this->teacher->id);
    expect($access->version)->toBe($initialTeacherVersion)
        ->and($access->is_active)->toBeTrue();
});
