<?php

namespace Tests\Integration\V3\Access;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccountConcurrencyTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir().'/v3_concurrency_'.uniqid().'.sqlite';
        touch($this->tempDb);

        // Run migrations on the temp db
        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        parent::tearDown();
    }

    public function test_l08_two_admins_concurrently_suspending_each_other_leaves_at_least_one_active_admin(): void
    {
        // Insert Admin 1 and Admin 2
        $now = now();
        $admin1Id = DB::table('users')->insertGetId([
            'name' => 'Admin One',
            'email' => 'admin1_conc@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $admin2Id = DB::table('users')->insertGetId([
            'name' => 'Admin Two',
            'email' => 'admin2_conc@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_account_access')->insert([
            ['user_id' => $admin1Id, 'is_active' => true, 'version' => 1, 'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['user_id' => $admin2Id, 'is_active' => true, 'version' => 1, 'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Worker 1: Admin 1 suspends Admin 2
        $worker1Script = base_path('tests/Support/V3/ConcurrentAccountWorker.php');
        $proc1 = new Process([
            'php', $worker1Script,
            '--db='.$this->tempDb,
            '--actor='.$admin1Id,
            '--target='.$admin2Id,
            '--role=admin',
            '--is_active=false',
            '--version=1',
            '--expected_role=admin',
            '--reason=suspend_access',
        ]);

        // Worker 2: Admin 2 suspends Admin 1
        $proc2 = new Process([
            'php', $worker1Script,
            '--db='.$this->tempDb,
            '--actor='.$admin2Id,
            '--target='.$admin1Id,
            '--role=admin',
            '--is_active=false',
            '--version=1',
            '--expected_role=admin',
            '--reason=suspend_access',
        ]);

        // Start both simultaneously
        $proc1->start();
        $proc2->start();

        // Wait for both to finish (max 10 seconds)
        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        // Assert at most one worker succeeded
        $successes = 0;
        if (! empty($out1['success'])) {
            $successes++;
        }
        if (! empty($out2['success'])) {
            $successes++;
        }

        $this->assertLessThanOrEqual(1, $successes, 'At most one admin suspension can succeed concurrently.');

        // Reconnect to temp DB to verify active admin count invariant
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $activeAdminCount = DB::table('users')
            ->join('v3_account_access', 'users.id', '=', 'v3_account_access.user_id')
            ->where('users.role', 'admin')
            ->where('v3_account_access.is_active', 1)
            ->count();

        $this->assertGreaterThanOrEqual(1, $activeAdminCount, 'Invariant: at least one active admin must remain in system.');
    }

    public function test_l09_two_concurrent_updates_with_same_target_version_allows_at_most_one_winner(): void
    {
        $now = now();
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Admin Runner',
            'email' => 'admin_runner@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Target Teacher',
            'email' => 'teacher_runner@example.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_account_access')->insert([
            ['user_id' => $adminId, 'is_active' => true, 'version' => 1, 'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['user_id' => $teacherId, 'is_active' => true, 'version' => 1, 'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $workerScript = base_path('tests/Support/V3/ConcurrentAccountWorker.php');

        // Worker 1: suspend teacher
        $proc1 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--actor='.$adminId,
            '--target='.$teacherId,
            '--role=teacher',
            '--is_active=false',
            '--version=1',
            '--expected_role=teacher',
            '--reason=suspend_access',
        ]);

        // Worker 2: demote teacher to student
        $proc2 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--actor='.$adminId,
            '--target='.$teacherId,
            '--role=student',
            '--is_active=false',
            '--version=1',
            '--expected_role=teacher',
            '--reason=change_role',
        ]);

        $proc1->start();
        $proc2->start();

        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        $successes = 0;
        if (! empty($out1['success'])) {
            $successes++;
        }
        if (! empty($out2['success'])) {
            $successes++;
        }

        $this->assertLessThanOrEqual(1, $successes, 'At most one concurrent update with same expected version can succeed.');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $finalAccess = DB::table('v3_account_access')->where('user_id', $teacherId)->first();
        $this->assertEquals(2, $finalAccess->version, 'Final version must be exactly incremented once.');
    }
}
