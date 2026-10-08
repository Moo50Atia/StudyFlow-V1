<?php

namespace Tests\Feature\V3\Evidence;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExportRaceTest extends TestCase
{
    protected string $tempDb;

    protected string $barrierDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir().'/v3_export_race_'.Str::random(10).'.sqlite';
        touch($this->tempDb);

        $this->barrierDir = sys_get_temp_dir().'/v3_barrier_'.Str::random(10);
        mkdir($this->barrierDir, 0777, true);

        // Configure race database connection
        config(['database.connections.race' => [
            'driver' => 'sqlite',
            'database' => $this->tempDb,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 10000,
            'journal_mode' => 'WAL',
            'transaction_mode' => 'IMMEDIATE',
        ]]);

        // Run migrations on race database
        Artisan::call('migrate', [
            '--database' => 'race',
            '--force' => true,
        ]);

        DB::connection('race')->statement('PRAGMA journal_mode = WAL;');
        DB::connection('race')->statement('PRAGMA busy_timeout = 10000;');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        if (is_dir($this->barrierDir)) {
            array_map('unlink', glob("{$this->barrierDir}/*"));
            @rmdir($this->barrierDir);
        }

        parent::tearDown();
    }

    public function test_h19_two_concurrent_processes_with_same_actor_and_key_create_exactly_one_snapshot_and_audit(): void
    {
        // Seed teacher and case into race DB
        $conn = DB::connection('race');

        $userId = $conn->table('users')->insertGetId([
            'name' => 'Race Teacher',
            'email' => 'race_teacher_'.Str::random(5).'@example.com',
            'password' => bcrypt('secret'),
            'role' => 'teacher',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $conn->table('v3_account_access')->insert([
            'user_id' => $userId,
            'is_active' => true,
            'version' => 1,
            'provisioned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settingsId = $conn->table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'checksum' => str_repeat('a', 64),
            'payload' => json_encode(['pipeline' => ['v3' => true]]),
            'published_by_user_id' => $userId,
            'published_at' => now(),
        ]);

        $docId = (string) Str::uuid();
        $conn->table('v3_documents')->insert([
            'id' => $docId,
            'owner_user_id' => $userId,
            'sha256' => str_repeat('b', 64),
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => "accepted/{$docId}.pdf",
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $caseId = (string) Str::uuid();
        $conn->table('v3_processing_cases')->insert([
            'id' => $caseId,
            'owner_user_id' => $userId,
            'document_id' => $docId,
            'settings_version_id' => $settingsId,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'approved',
            'state_version' => 1,
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(5),
        ]);

        $idempotencyKey = (string) Str::uuid();
        $payload = json_encode([
            'schema_version' => 1,
            'idempotency_key' => $idempotencyKey,
            'scope' => 'owner_case',
            'approval_ref' => null,
        ]);

        $url = "/v3/teacher/cases/{$caseId}/evidence-exports";
        $workerScript = base_path('tests/Support/V3/Evidence/ConcurrentExportWorker.php');

        // Worker 1 process
        $proc1 = new Process([
            'php',
            $workerScript,
            "--db={$this->tempDb}",
            "--actor={$userId}",
            '--role=teacher',
            "--url={$url}",
            "--payload={$payload}",
            "--barrier-dir={$this->barrierDir}",
            '--worker-id=1',
        ]);

        // Worker 2 process
        $proc2 = new Process([
            'php',
            $workerScript,
            "--db={$this->tempDb}",
            "--actor={$userId}",
            '--role=teacher',
            "--url={$url}",
            "--payload={$payload}",
            "--barrier-dir={$this->barrierDir}",
            '--worker-id=2',
        ]);

        $proc1->start();
        $proc2->start();

        // Wait for both workers to signal ready
        $ready1 = "{$this->barrierDir}/ready_1";
        $ready2 = "{$this->barrierDir}/ready_2";
        $startWait = microtime(true);
        while (! file_exists($ready1) || ! file_exists($ready2)) {
            usleep(1000); // 1ms
            if (microtime(true) - $startWait > 10.0) {
                $proc1->stop();
                $proc2->stop();
                $this->fail('Workers timed out before reaching barrier: ready_1='.(int) file_exists($ready1).' ready_2='.(int) file_exists($ready2));
            }
        }

        // Release barrier simultaneously
        file_put_contents("{$this->barrierDir}/go", '1');

        // Wait for both processes to complete
        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        // Verify status codes: one must be 201 (winner) and one 200 (replay), or both 200/201
        $statusCodes = [$out1['status'] ?? 0, $out2['status'] ?? 0];
        sort($statusCodes);
        $this->assertEquals([200, 201], $statusCodes, 'Expected [200, 201]. Got: '.json_encode([$out1, $out2]));

        // Exactly one export row in database
        $exports = $conn->table('v3_evidence_exports')
            ->where('actor_user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->get();
        $this->assertCount(1, $exports);

        // Exactly one 'generated' audit row in database
        $exportId = $exports->first()->id;
        $generatedAudits = $conn->table('v3_evidence_export_audits')
            ->where('export_id', $exportId)
            ->where('event', 'generated')
            ->get();
        $this->assertCount(1, $generatedAudits);
    }
}
