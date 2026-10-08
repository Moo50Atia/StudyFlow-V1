<?php

namespace Tests\Integration\V3\Lessons;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class MapActivationRaceTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_map_act_race_' . uniqid() . '.sqlite';
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

    public function test_l01_two_concurrent_discovery_processes_create_exactly_one_map_activation(): void
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Map Conc',
            'email' => 'teacher_map_conc@example.com',
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
            'sha256' => hash('sha256', 'doc-conc-map'),
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
            'run_id' => 'run-conc-map-1',
            'settings_version_id' => $settingsId,
            'settings_checksum' => $checksum,
            'start_state' => 'started',
            'stage_code' => 'sourcing',
            'status_code' => 'queued',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $srcActId = (string) Str::uuid();
        DB::table('v3_source_activations')->insert([
            'id' => $srcActId,
            'case_id' => $caseId,
            'workflow_id' => "v3-case-{$caseId}",
            'state' => 'accepted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $extId = (string) Str::uuid();
        DB::table('v3_extracted_sources')->insert([
            'id' => $extId,
            'case_id' => $caseId,
            'activation_id' => $srcActId,
            'document_id' => $docId,
            'settings_version_id' => $settingsId,
            'status' => 'ready',
            'artifact_path' => 'extracted/conc-map.txt',
            'text_sha256' => hash('sha256', 'extracted text'),
            'text_bytes' => 100,
            'page_count' => 1,
            'page_report' => json_encode([]),
            'extractor_version' => '008-v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bindingId = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert([
            'id' => $bindingId,
            'case_id' => $caseId,
            'extracted_source_id' => $extId,
            'adapter_kind' => 'notebooklm',
            'adapter_version' => '0.3.1',
            'notebook_title' => "v3-case-{$caseId}",
            'source_title' => "v3-text-{$caseId}",
            'notebook_id' => 'nb-conc-1',
            'source_id' => 'src-conc-1',
            'state' => 'bound',
            'text_sha256' => hash('sha256', 'extracted text'),
            'text_bytes' => 100,
            'transfer_status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workerScript = base_path('tests/Support/V3/ConcurrentMapWorker.php');

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

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        $this->assertTrue($out1['success'] ?? false, 'Worker 1 failed: ' . $proc1->getOutput() . ' ' . $proc1->getErrorOutput());
        $this->assertTrue($out2['success'] ?? false, 'Worker 2 failed: ' . $proc2->getOutput() . ' ' . $proc2->getErrorOutput());

        $totalDispatched = ($out1['count'] ?? 0) + ($out2['count'] ?? 0);
        $this->assertEquals(1, $totalDispatched, 'Expected exactly one process to claim and dispatch');

        $activationCount = DB::table('v3_map_activations')->where('case_id', $caseId)->count();
        $this->assertEquals(1, $activationCount);
    }
}
