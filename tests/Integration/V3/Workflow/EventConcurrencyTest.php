<?php

namespace Tests\Integration\V3\Workflow;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class EventConcurrencyTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_event_conc_' . uniqid() . '.sqlite';
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

    public function test_w09_two_concurrent_distinct_events_yield_one_commit_and_one_conflict(): void
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Event Conc',
            'email' => 'teacher_ev_conc@example.com',
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
        $encoded = \App\Services\V3\Settings\CanonicalSettings::encode($canonical);
        $checksum = hash('sha256', $encoded);

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
            'sha256' => hash('sha256', 'doc-ev-conc'),
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

        $workflowId = "v3-case-{$caseId}";
        $runId = 'run-conc-001';

        DB::table('v3_workflow_links')->insert([
            'case_id' => $caseId,
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'settings_version_id' => $settingsId,
            'settings_checksum' => $checksum,
            'start_state' => 'started',
            'stage_code' => 'extraction',
            'status_code' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Insert initial sequence 2 event (workflow_started)
        DB::table('v3_case_events')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $caseId,
            'event_key' => 'workflow_started',
            'payload_digest' => str_repeat('0', 64),
            'sequence' => 2,
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'event_type' => 'workflow_started',
            'stage_code' => 'extraction',
            'status_code' => 'queued',
            'error_code' => null,
            'recorded_at' => $now,
        ]);

        $workerScript = base_path('tests/Support/V3/ConcurrentWorkflowWorker.php');

        // Worker 1: stage_started mapping
        $payload1 = [
            'schema_version' => 1,
            'event_key' => 'stage_started_mapping',
            'case_id' => $caseId,
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'expected_state_version' => 2,
            'event_type' => 'stage_started',
            'stage_code' => 'mapping',
            'status_code' => 'running',
            'error_code' => null,
        ];

        // Worker 2: stage_started extraction
        $payload2 = [
            'schema_version' => 1,
            'event_key' => 'stage_started_extraction',
            'case_id' => $caseId,
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'expected_state_version' => 2,
            'event_type' => 'stage_started',
            'stage_code' => 'extraction',
            'status_code' => 'running',
            'error_code' => null,
        ];

        $proc1 = new Process([
            'php', $workerScript,
            '--db=' . $this->tempDb,
            '--mode=event',
            '--case=' . $caseId,
            '--payload=' . json_encode($payload1),
        ]);

        $proc2 = new Process([
            'php', $workerScript,
            '--db=' . $this->tempDb,
            '--mode=event',
            '--case=' . $caseId,
            '--payload=' . json_encode($payload2),
        ]);

        $proc1->start();
        $proc2->start();

        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        // Reconnect to verify database invariants
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA busy_timeout = 5000;');

        $successCount = 0;
        $conflictCount = 0;

        if (! empty($out1['success']) && ($out1['status'] ?? 0) === 201) {
            $successCount++;
        } elseif (($out1['status'] ?? 0) === 409) {
            $conflictCount++;
        }

        if (! empty($out2['success']) && ($out2['status'] ?? 0) === 201) {
            $successCount++;
        } elseif (($out2['status'] ?? 0) === 409) {
            $conflictCount++;
        }

        $this->assertSame(1, $successCount, 'Exactly one concurrent event must succeed (201).');
        $this->assertSame(1, $conflictCount, 'The competing concurrent event must conflict (409).');

        // Total events for this case should be 2 (initial workflow_started + 1 committed event)
        $totalEvents = DB::table('v3_case_events')->where('case_id', $caseId)->count();
        $this->assertSame(2, $totalEvents);

        // State version must be exactly 3
        $caseRow = DB::table('v3_processing_cases')->where('id', $caseId)->first();
        $this->assertSame(3, (int) $caseRow->state_version);
    }
}
