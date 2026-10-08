<?php

namespace Tests\Integration\V3\Sources;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class ActivationRaceTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_src_act_race_' . uniqid() . '.sqlite';
        touch($this->tempDb);

        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA journal_mode = WAL;');
        DB::statement('PRAGMA busy_timeout = 5000;');

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        parent::tearDown();
    }

    public function test_k01_two_concurrent_discovery_processes_create_exactly_one_source_activation(): void
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Src Conc',
            'email' => 'teacher_src_conc@example.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_account_access')->insert([
            'user_id' => $teacherId,
            'is_active' => true,
            'version' => 1,
            'provisioned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
        $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => json_encode($canonical),
            'checksum' => $checksum,
            'published_by_user_id' => $teacherId,
            'published_at' => $now,
        ]);

        $docId = (string) Str::uuid();
        DB::table('v3_documents')->insert([
            'id' => $docId,
            'owner_user_id' => $teacherId,
            'sha256' => hash('sha256', 'doc-conc-src'),
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => "accepted/{$docId}.pdf",
            'accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $caseId = (string) Str::uuid();
        DB::table('v3_processing_cases')->insert([
            'id' => $caseId,
            'owner_user_id' => $teacherId,
            'document_id' => $docId,
            'settings_version_id' => $settingsId,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'queued',
            'state_version' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_workflow_links')->insert([
            'case_id' => $caseId,
            'workflow_id' => "v3-case-{$caseId}",
            'run_id' => 'run-conc-1',
            'settings_version_id' => $settingsId,
            'settings_checksum' => $checksum,
            'start_state' => 'started',
            'stage_code' => 'extraction',
            'status_code' => 'queued',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workerScript = base_path('tests/Support/V3/ConcurrentSourceWorker.php');

        $proc1 = new Process([
            'php', $workerScript,
            '--db=' . $this->tempDb,
            '--mode=dispatch',
        ]);

        $proc2 = new Process([
            'php', $workerScript,
            '--db=' . $this->tempDb,
            '--mode=dispatch',
        ]);

        // Start both simultaneously
        $proc1->start();
        $proc2->start();

        $proc1->wait();
        $proc2->wait();

        // Reconnect to check database state
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA busy_timeout = 5000;');

        $count = DB::table('v3_source_activations')->where('case_id', $caseId)->count();
        $this->assertSame(1, $count, 'Exactly one SourceActivation must be created under concurrent discovery.');

        $activation = DB::table('v3_source_activations')->where('case_id', $caseId)->first();
        $this->assertSame("v3-case-{$caseId}", $activation->workflow_id);
    }
}
