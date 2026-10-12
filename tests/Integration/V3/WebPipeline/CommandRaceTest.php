<?php

namespace Tests\Integration\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\SubjectRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class CommandRaceTest extends TestCase
{
    protected string $tempDb;
    protected User $teacher;
    protected string $runId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_cmd_race_' . uniqid() . '.sqlite';
        touch($this->tempDb);

        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA journal_mode = WAL;');
        DB::statement('PRAGMA busy_timeout = 10000;');

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        // Seed basic fixtures
        $this->teacher = WebPipelineFixtures::createTeacherA();
        $env = AuthoringFixtures::createAuthoringEnvironment();
        $case = $env['case'];

        $frameworkId = (string) Str::uuid();
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/test.txt',
            'body_sha256' => hash('sha256', 'framework body'),
            'body_bytes' => 100,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'format'),
            'approved_by_user_id' => $this->teacher->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        $subject = Subject::create([
            'name' => 'Race Mechanics',
            'description' => 'Physics concurrency test',
        ]);

        $this->runId = (string) Str::uuid();
        SubjectRun::create([
            'id' => $this->runId,
            'origin' => 'web_intake',
            'owner_user_id' => $this->teacher->id,
            'subject_id' => $subject->id,
            'settings_version_id' => $case->settings_version_id,
            'framework_version_id' => $frameworkId,
            'captured_settings_json' => ['max_pdf_bytes' => 50000000],
            'capture_sha256' => hash('sha256', 'cap'),
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'req'),
            'state_version' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        parent::tearDown();
    }

    /**
     * FR-019, FR-020, A24: Two concurrent processes racing with the identical command ID
     * must linearize safely without duplicate command insertion or state corruption.
     */
    public function test_identical_command_race_linearizes_or_deduplicates(): void
    {
        $commandId = (string) Str::uuid();
        $requestSha = hash('sha256', 'identical-race-command');
        $workerScript = base_path('tests/Support/V3/ConcurrentCommandWorker.php');

        $proc1 = new Process([
            'php', $workerScript,
            '--db=' . $this->tempDb,
            '--run=' . $this->runId,
            '--actor=' . $this->teacher->id,
            '--action=pause',
            '--expected-version=1',
            '--command-id=' . $commandId,
            '--request-sha=' . $requestSha,
        ]);

        $proc2 = new Process([
            'php', $workerScript,
            '--db=' . $this->tempDb,
            '--run=' . $this->runId,
            '--actor=' . $this->teacher->id,
            '--action=pause',
            '--expected-version=1',
            '--command-id=' . $commandId,
            '--request-sha=' . $requestSha,
        ]);

        $proc1->start();
        $proc2->start();

        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        // At least one must succeed
        $oneSucceeded = ($out1['success'] ?? false) || ($out2['success'] ?? false);
        $this->assertTrue($oneSucceeded, 'At least one worker must execute the command successfully.');

        // Exactly one command record must exist in DB
        $commandCount = DB::table('v3_pipeline_commands')->where('id', $commandId)->count();
        $this->assertSame(1, $commandCount, 'Exactly one command record must be persisted for the given command ID.');

        // Run state_version must have advanced to exactly 2
        $run = DB::table('v3_subject_runs')->where('id', $this->runId)->first();
        $this->assertSame(2, (int) $run->state_version, 'State version must advance from 1 to 2 exactly once.');
    }

    /**
     * FR-019, A24: A command submitted with a stale expected_version must be rejected with conflict.
     */
    public function test_stale_command_version_is_rejected(): void
    {
        // Advance run state_version to 3
        DB::table('v3_subject_runs')->where('id', $this->runId)->update(['state_version' => 3]);

        $workerScript = base_path('tests/Support/V3/ConcurrentCommandWorker.php');
        $proc = new Process([
            'php', $workerScript,
            '--db=' . $this->tempDb,
            '--run=' . $this->runId,
            '--actor=' . $this->teacher->id,
            '--action=cancel',
            '--expected-version=1', // Stale!
        ]);

        $proc->run();
        $out = json_decode($proc->getOutput(), true);

        $this->assertFalse($out['success'] ?? true);
        $this->assertStringContainsString('State version conflict', $out['error'] ?? '');

        // Verify version unchanged
        $run = DB::table('v3_subject_runs')->where('id', $this->runId)->first();
        $this->assertSame(3, (int) $run->state_version);
    }
}
