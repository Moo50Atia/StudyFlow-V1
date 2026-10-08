<?php

namespace Tests\Integration\V3\Lessons;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class MapAcceptanceRaceTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_map_acc_race_' . uniqid() . '.sqlite';
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

    public function test_l05_two_concurrent_acceptance_processes_create_one_map_idempotently(): void
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Map Acc Race',
            'email' => 'teacher_map_acc_race@example.com',
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
            'sha256' => hash('sha256', 'race doc'),
            'byte_size' => 2048,
            'page_count' => 3,
            'state' => 'ready',
            'storage_path' => 'accepted/race-map.pdf',
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
            'state' => 'running',
            'state_version' => 7,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $actId = (string) Str::uuid();
        $srcActId = (string) Str::uuid();
        DB::table('v3_source_activations')->insert([
            'id' => $srcActId,
            'case_id' => $caseId,
            'workflow_id' => "v3-case-{$caseId}",
            'state' => 'accepted',
            'signal_accepted_at' => $now,
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
            'artifact_path' => 'extracted/race.txt',
            'text_sha256' => hash('sha256', 'text'),
            'text_bytes' => 100,
            'page_count' => 3,
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
            'notebook_id' => 'nb-race-1',
            'source_id' => 'src-race-1',
            'state' => 'bound',
            'text_sha256' => hash('sha256', 'text'),
            'text_bytes' => 100,
            'transfer_status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mapActId = (string) Str::uuid();
        DB::table('v3_map_activations')->insert([
            'id' => $mapActId,
            'case_id' => $caseId,
            'binding_id' => $bindingId,
            'workflow_id' => "v3-case-{$caseId}",
            'state' => 'accepted',
            'signal_accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $queryId = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert([
            'id' => $queryId,
            'case_id' => $caseId,
            'kind' => 'divide',
            'target_work_ordinal' => 0,
            'state' => 'sending',
            'send_count' => 1,
            'request_sha256' => hash('sha256', 'req'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $barrierFile = sys_get_temp_dir() . '/v3_race_barrier_' . uniqid();
        $workerScript = base_path('tests/Support/V3/ConcurrentMapAcceptor.php');

        $p1 = new Process(['php', $workerScript, $this->tempDb, $queryId, $barrierFile]);
        $p2 = new Process(['php', $workerScript, $this->tempDb, $queryId, $barrierFile]);

        $p1->start();
        $p2->start();

        // Wait until both processes are ready
        $timeout = time() + 5;
        while (count(glob($barrierFile . '.*')) < 2 && time() < $timeout) {
            usleep(10000);
        }

        // Release both
        touch($barrierFile);

        $p1->wait();
        $p2->wait();

        @unlink($barrierFile);
        foreach (glob($barrierFile . '.*') as $f) {
            @unlink($f);
        }

        // Both processes should exit 0
        $this->assertEquals(0, $p1->getExitCode(), "Process 1 failed: " . $p1->getOutput() . $p1->getErrorOutput());
        $this->assertEquals(0, $p2->getExitCode(), "Process 2 failed: " . $p2->getOutput() . $p2->getErrorOutput());

        // Exactly one map must exist
        $mapCount = DB::table('v3_lesson_maps')->where('case_id', $caseId)->count();
        $this->assertEquals(1, $mapCount);
    }
}
