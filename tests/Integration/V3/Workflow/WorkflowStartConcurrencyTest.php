<?php

namespace Tests\Integration\V3\Workflow;

use App\Models\V3\SettingsVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class WorkflowStartConcurrencyTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_wf_start_conc_' . uniqid() . '.sqlite';
        touch($this->tempDb);

        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
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

    public function test_w01_two_concurrent_discovery_processes_create_exactly_one_workflow_link(): void
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Conc',
            'email' => 'teacher_wf_conc@example.com',
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
        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => json_encode($payload),
            'checksum' => hash('sha256', json_encode($payload)),
            'published_by_user_id' => $teacherId,
            'published_at' => $now,
        ]);

        $docId = (string) Str::uuid();
        DB::table('v3_documents')->insert([
            'id' => $docId,
            'owner_user_id' => $teacherId,
            'sha256' => hash('sha256', 'doc-conc-wf'),
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
            'state' => 'awaiting_workflow',
            'state_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workerScript = base_path('tests/Support/V3/ConcurrentWorkflowWorker.php');

        $proc1 = new Process([
            'php', $workerScript,
            '--db=' . $this->tempDb,
            '--mode=start',
            '--case=' . $caseId,
        ]);

        $proc2 = new Process([
            'php', $workerScript,
            '--db=' . $this->tempDb,
            '--mode=start',
            '--case=' . $caseId,
        ]);

        // Start both simultaneously
        $proc1->start();
        $proc2->start();

        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        // Reconnect to check database state
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA busy_timeout = 5000;');

        $linkCount = DB::table('v3_workflow_links')->where('case_id', $caseId)->count();
        $this->assertSame(1, $linkCount, 'Exactly one WorkflowLink must be created under concurrent discovery.');

        $links = DB::table('v3_workflow_links')->where('case_id', $caseId)->first();
        $this->assertSame("v3-case-{$caseId}", $links->workflow_id);
    }
}
