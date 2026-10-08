<?php

namespace Tests\Feature\V3\Access;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Schema test setup
});

test('L01: migration backfills existing teacher and admin accounts with active access and bootstrap events while leaving students untouched', function () {
    // Assert v3 tables exist
    expect(Schema::hasTable('v3_account_access'))->toBeTrue()
        ->and(Schema::hasTable('v3_access_events'))->toBeTrue()
        ->and(Schema::hasTable('v3_access_control_locks'))->toBeTrue();

    // Verify lock singleton row
    $lock = DB::table('v3_access_control_locks')->where('id', 1)->first();
    expect($lock)->not->toBeNull()
        ->and($lock->revision)->toBe(0);

    // Create users before migration backfill scenario:
    $teacher = User::create([
        'name' => 'Backfill Teacher',
        'email' => 'bf_teacher@example.com',
        'password' => Hash::make('password'),
        'role' => 'teacher',
    ]);
    $admin = User::create([
        'name' => 'Backfill Admin',
        'email' => 'bf_admin@example.com',
        'password' => Hash::make('password'),
        'role' => 'admin',
    ]);
    $student = User::create([
        'name' => 'Backfill Student',
        'email' => 'bf_student@example.com',
        'password' => Hash::make('password'),
        'role' => 'student',
    ]);

    // Run the migration backfill logic (or bootstrap)
    Artisan::call('v3:bootstrap-access');

    // Assert teacher and admin have active access row
    $teacherAccess = DB::table('v3_account_access')->where('user_id', $teacher->id)->first();
    expect($teacherAccess)->not->toBeNull()
        ->and((bool) $teacherAccess->is_active)->toBeTrue()
        ->and((int) $teacherAccess->version)->toBe(1)
        ->and($teacherAccess->provisioned_at)->not->toBeNull()
        ->and($teacherAccess->disabled_at)->toBeNull();

    $adminAccess = DB::table('v3_account_access')->where('user_id', $admin->id)->first();
    expect($adminAccess)->not->toBeNull()
        ->and((bool) $adminAccess->is_active)->toBeTrue()
        ->and((int) $adminAccess->version)->toBe(1);

    // Student must NOT have access row
    $studentAccess = DB::table('v3_account_access')->where('user_id', $student->id)->first();
    expect($studentAccess)->toBeNull();

    // Assert bootstrap events exist for teacher and admin
    $teacherEvent = DB::table('v3_access_events')
        ->where('target_user_id', $teacher->id)
        ->where('event_type', 'bootstrap')
        ->first();
    expect($teacherEvent)->not->toBeNull()
        ->and($teacherEvent->reason_code)->toBe('bootstrap')
        ->and($teacherEvent->actor_user_id)->toBeNull();

    $afterState = json_decode($teacherEvent->after_state, true);
    expect($afterState)->toEqual([
        'role' => 'teacher',
        'is_active' => true,
        'version' => 1,
    ]);
});

test('L02: bootstrap command is idempotent and only adds missing rows without altering existing state', function () {
    $teacher = User::create([
        'name' => 'Disabled Teacher',
        'email' => 'disabled_teacher@example.com',
        'password' => Hash::make('password'),
        'role' => 'teacher',
    ]);

    // Manually create disabled access row
    DB::table('v3_account_access')->insert([
        'user_id' => $teacher->id,
        'is_active' => false,
        'version' => 3,
        'provisioned_at' => now()->subDays(5),
        'disabled_at' => now()->subDays(2),
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(2),
    ]);

    $initialEventCount = DB::table('v3_access_events')->count();

    // Run bootstrap
    Artisan::call('v3:bootstrap-access');

    // Verify disabled row was NOT reactivated and version did not change
    $access = DB::table('v3_account_access')->where('user_id', $teacher->id)->first();
    expect((bool) $access->is_active)->toBeFalse()
        ->and((int) $access->version)->toBe(3);

    // Verify no new event was inserted for this existing user
    $userEvents = DB::table('v3_access_events')->where('target_user_id', $teacher->id)->count();
    expect($userEvents)->toBe(0);

    // Running bootstrap a second time produces no changes
    Artisan::call('v3:bootstrap-access');
    expect(DB::table('v3_access_events')->count())->toBe($initialEventCount);
});

test('L17: foreign key constraint on v3_account_access restricts deleting enrolled user', function () {
    // In SQLite, ensure foreign keys are enabled
    DB::statement('PRAGMA foreign_keys = ON;');

    $teacher = User::create([
        'name' => 'Enrolled Teacher FK',
        'email' => 'enrolled_fk@example.com',
        'password' => Hash::make('password'),
        'role' => 'teacher',
    ]);

    DB::table('v3_account_access')->insert([
        'user_id' => $teacher->id,
        'is_active' => true,
        'version' => 1,
        'provisioned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Attempting direct raw delete of parent user must throw foreign key QueryException
    $threw = false;
    try {
        DB::table('users')->where('id', $teacher->id)->delete();
    } catch (QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    expect(DB::table('users')->where('id', $teacher->id)->exists())->toBeTrue();
});

test('down migration drops V3 tables safely without touching users table', function () {
    expect(Schema::hasTable('v3_account_access'))->toBeTrue();

    $migration = require database_path('migrations/2026_10_01_000004_create_v3_access_tables.php');
    $migration->down();

    expect(Schema::hasTable('v3_access_events'))->toBeFalse()
        ->and(Schema::hasTable('v3_account_access'))->toBeFalse()
        ->and(Schema::hasTable('v3_access_control_locks'))->toBeFalse()
        ->and(Schema::hasTable('users'))->toBeTrue();

    // Re-run up to leave database clean for other tests
    $migration->up();
    expect(Schema::hasTable('v3_account_access'))->toBeTrue();
});
