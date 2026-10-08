<?php

namespace Tests\Integration\V3\Settings;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class PublicationConcurrencyTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir().'/v3_settings_concurrency_'.uniqid().'.sqlite';
        touch($this->tempDb);

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

    public function test_s05_two_simultaneous_admins_with_same_expected_version_yield_exactly_one_winner(): void
    {
        $now = now();

        // 1. Create two active admins
        $admin1Id = DB::table('users')->insertGetId([
            'name' => 'Admin One',
            'email' => 'admin1_set@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $admin2Id = DB::table('users')->insertGetId([
            'name' => 'Admin Two',
            'email' => 'admin2_set@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_account_access')->insert([
            ['user_id' => $admin1Id, 'is_active' => true, 'version' => 1, 'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['user_id' => $admin2Id, 'is_active' => true, 'version' => 1, 'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // 2. Register approved templates in tempDb
        DB::table('v3_approved_templates')->insert([
            [
                'id' => SettingsFixtures::VALID_FEEDBACK_TEMPLATE_ID,
                'kind' => 'feedback',
                'body' => SettingsFixtures::VALID_FEEDBACK_BODY,
                'checksum' => hash('sha256', SettingsFixtures::VALID_FEEDBACK_BODY),
                'registered_at' => $now,
            ],
            [
                'id' => SettingsFixtures::VALID_SCENE_TEMPLATE_ID,
                'kind' => 'scene',
                'body' => SettingsFixtures::VALID_SCENE_BODY,
                'checksum' => hash('sha256', SettingsFixtures::VALID_SCENE_BODY),
                'registered_at' => $now,
            ],
        ]);

        $workerScript = base_path('tests/Support/V3/ConcurrentSettingsWorker.php');
        $payload1 = json_encode(SettingsFixtures::validPayload(0, ['limits' => ['max_pdf_bytes' => 10485760]]));
        $payload2 = json_encode(SettingsFixtures::validPayload(0, ['limits' => ['max_pdf_bytes' => 20971520]]));

        $proc1 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--actor='.$admin1Id,
            '--expected_version=0',
            '--payload='.$payload1,
        ]);

        $proc2 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--actor='.$admin2Id,
            '--expected_version=0',
            '--payload='.$payload2,
        ]);

        // Launch both processes concurrently
        $proc1->start();
        $proc2->start();

        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        $successes = 0;
        $conflicts = 0;

        foreach ([$out1, $out2] as $out) {
            if (! empty($out['success'])) {
                $successes++;
            }
            if (! empty($out['status']) && $out['status'] === 409) {
                $conflicts++;
            }
        }

        // Exactly one publisher committed; the other received a 409 conflict
        $this->assertSame(1, $successes, "Expected exactly 1 successful publication, got {$successes}. Out1: {$proc1->getOutput()}, Out2: {$proc2->getOutput()}");
        $this->assertSame(1, $conflicts, "Expected exactly 1 conflict response, got {$conflicts}.");

        // Reconnect to verify DB state
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $versionCount = DB::table('v3_settings_versions')->count();
        $pubCount = DB::table('v3_settings_publications')->count();
        $activeRow = DB::table('v3_active_settings')->where('key', 'pilot')->first();

        $this->assertSame(1, $versionCount, 'Exactly 1 version row should be inserted.');
        $this->assertSame(1, $pubCount, 'Exactly 1 publication event should be recorded.');
        $this->assertNotNull($activeRow->active_settings_version_id, 'Active settings pointer must point to committed version.');
    }
}
