<?php

namespace Tests\Feature\V3\LocalWorker;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalTask;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SubjectRun;
use App\Services\V3\WebPipeline\RunProgress;
use App\Services\V3\WebPipeline\TerminalBridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class LocalWorkerProgressTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected Subject $subject;
    protected SettingsVersion $settingsVersion;
    protected NotebookFrameworkVersion $frameworkVersion;
    protected SubjectRun $run;
    protected LocalJob $job;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher LW Progress');
        $this->subject = Subject::forceCreate([
            'name' => 'Local Worker Physics',
            'description' => 'Physics with Local Worker',
        ]);

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
        $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

        $this->settingsVersion = SettingsVersion::create([
            'version' => 1,
            'schema_version' => 1,
            'payload' => $canonical,
            'checksum' => $checksum,
            'published_by_user_id' => $this->teacher->id,
            'published_at' => now(),
        ]);

        $this->frameworkVersion = NotebookFrameworkVersion::create([
            'id' => (string) Str::uuid(),
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/steps.txt',
            'body_sha256' => hash('sha256', 'body'),
            'body_bytes' => 10,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'resp'),
            'approved_by_user_id' => $this->teacher->id,
            'approved_at' => now(),
        ]);

        $this->run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'origin' => 'web',
            'protocol' => 'notebooklm_per_pdf_web_v1',
            'settings_version_id' => $this->settingsVersion->id,
            'framework_version_id' => $this->frameworkVersion->id,
            'mode' => 'export_manual',
            'execution_mode' => 'local_worker_v1',
            'qualification_mode' => 'owner_waived_demo',
            'state' => 'queued',
            'state_version' => 1,
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'sub1'),
            'captured_settings_json' => json_encode(['output_scope' => 'prompts_and_scenes']),
            'capture_sha256' => hash('sha256', 'capture'),
        ]);

        $this->job = LocalJob::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'teacher_id' => $this->teacher->id,
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_and_scenes',
            'status' => 'queued',
            'settings_snapshot_id' => (string) Str::uuid(),
            'settings_sha256' => str_repeat('a', 64),
            'policy_budgets' => ['max_output_bytes' => 1000000],
            'control_version' => 1,
        ]);
    }

    public function test_progress_projects_queued_when_active_device_exists(): void
    {
        $platformOwner = User::factory()->create(['role' => 'admin']);
        ProcessingDevice::create([
            'id' => (string) Str::uuid(),
            'name' => 'MacBook-Pro',
            'tenant_id' => 'default',
            'platform_owner_id' => $platformOwner->id,
            'token_hash' => hash('sha256', 'dev-tok'),
            'scopes' => ['tenant:all'],
            'capabilities' => ['authoring_v1', 'scene_return_v1'],
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $this->job->id,
            'task_kind' => 'author_prompts',
            'status' => 'queued',
            'input_manifest_id' => (string) Str::uuid(),
            'input_manifest_sha256' => str_repeat('b', 64),
            'control_version' => 1,
        ]);

        $bridge = $this->createMock(TerminalBridgeClient::class);
        $bridge->expects($this->never())->method('getRunStatus');

        $progress = new RunProgress($bridge);
        $projection = $progress->project($this->run);

        $this->assertEquals('queued', $projection['state']);
        $this->assertEquals('local_worker', $projection['projection_source']);
        $this->assertEquals('prompts_and_scenes', $projection['output_scope']);
        $this->assertEquals('local_worker_v1', $projection['execution_mode']);
        $this->assertFalse($projection['retry_available']);
    }

    public function test_progress_projects_offline_when_no_device_or_lease_expired(): void
    {
        // No device registered -> offline
        $task = LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $this->job->id,
            'task_kind' => 'author_prompts',
            'status' => 'queued',
            'input_manifest_id' => (string) Str::uuid(),
            'input_manifest_sha256' => str_repeat('b', 64),
            'control_version' => 1,
        ]);

        $bridge = $this->createMock(TerminalBridgeClient::class);
        $bridge->expects($this->never())->method('getRunStatus');

        $progress = new RunProgress($bridge);
        $projection = $progress->project($this->run);

        $this->assertEquals('offline', $projection['state']);
        $this->assertNotEmpty($projection['failures']);
        $this->assertEquals('worker_offline', $projection['failures'][0]['code']);

        // Now add device, lease task, but lease expired
        $device = ProcessingDevice::create([
            'id' => (string) Str::uuid(),
            'name' => 'MacBook-Pro',
            'tenant_id' => 'default',
            'platform_owner_id' => $this->teacher->id,
            'token_hash' => hash('sha256', 'dev-tok'),
            'scopes' => ['device:all'],
            'capabilities' => ['authoring_v1', 'scene_return_v1'],
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        $assignment = LocalAssignment::create([
            'id' => (string) Str::uuid(),
            'task_id' => $task->id,
            'device_id' => $device->id,
            'claim_id' => (string) Str::uuid(),
            'assignment_epoch' => 1,
            'status' => 'active',
            'lease_expires_at' => now()->subMinutes(5), // EXPIRED
            'heartbeat_at' => now()->subMinutes(5),
            'control_version' => 1,
        ]);

        $task->status = 'leased';
        $task->active_assignment_id = $assignment->id;
        $task->save();

        $projection2 = $progress->project($this->run);
        $this->assertEquals('offline', $projection2['state']);
    }

    public function test_progress_projects_running_when_active_lease_heartbeats(): void
    {
        $device = ProcessingDevice::create([
            'id' => (string) Str::uuid(),
            'name' => 'MacBook-Pro',
            'tenant_id' => 'default',
            'platform_owner_id' => $this->teacher->id,
            'token_hash' => hash('sha256', 'dev-tok'),
            'scopes' => ['device:all'],
            'capabilities' => ['authoring_v1'],
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        $task = LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $this->job->id,
            'task_kind' => 'author_prompts',
            'status' => 'leased',
            'input_manifest_id' => (string) Str::uuid(),
            'input_manifest_sha256' => str_repeat('b', 64),
            'control_version' => 1,
        ]);

        $assignment = LocalAssignment::create([
            'id' => (string) Str::uuid(),
            'task_id' => $task->id,
            'device_id' => $device->id,
            'claim_id' => (string) Str::uuid(),
            'assignment_epoch' => 1,
            'status' => 'active',
            'lease_expires_at' => now()->addSeconds(120),
            'heartbeat_at' => now(),
            'control_version' => 1,
        ]);

        $task->active_assignment_id = $assignment->id;
        $task->save();

        $bridge = $this->createMock(TerminalBridgeClient::class);
        $bridge->expects($this->never())->method('getRunStatus');

        $progress = new RunProgress($bridge);
        $projection = $progress->project($this->run);

        $this->assertEquals('running', $projection['state']);
        $this->assertEquals('authoring', $projection['workflow']['phase']);
    }

    public function test_progress_projects_durable_holds(): void
    {
        $bridge = $this->createMock(TerminalBridgeClient::class);
        $bridge->expects($this->never())->method('getRunStatus');
        $progress = new RunProgress($bridge);

        $task = LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $this->job->id,
            'task_kind' => 'author_prompts',
            'status' => 'paused_auth',
            'input_manifest_id' => (string) Str::uuid(),
            'input_manifest_sha256' => str_repeat('b', 64),
            'control_version' => 1,
        ]);

        $projAuth = $progress->project($this->run);
        $this->assertEquals('paused_auth', $projAuth['state']);
        $this->assertTrue($projAuth['retry_available']);
        $this->assertEquals('auth_required', $projAuth['failures'][0]['code']);

        // Quota hold
        $task->status = 'paused_quota';
        $task->save();
        $projQuota = $progress->project($this->run);
        $this->assertEquals('paused_quota', $projQuota['state']);
        $this->assertTrue($projQuota['retry_available']);
        $this->assertEquals('quota_exceeded', $projQuota['failures'][0]['code']);

        // Reconciliation hold
        $task->status = 'reconciliation_required';
        $task->save();
        $projRecon = $progress->project($this->run);
        $this->assertEquals('held_reconciliation', $projRecon['state']);
        $this->assertTrue($projRecon['retry_available']);
        $this->assertEquals('reconciliation_required', $projRecon['failures'][0]['code']);
    }

    public function test_progress_projects_awaiting_generation_and_completed(): void
    {
        $bridge = $this->createMock(TerminalBridgeClient::class);
        $bridge->expects($this->never())->method('getRunStatus');
        $progress = new RunProgress($bridge);

        // Awaiting generation
        $this->job->status = 'awaiting_generation';
        $this->job->save();
        $this->run->state = 'awaiting_generation';
        $this->run->save();

        $projAwaiting = $progress->project($this->run);
        $this->assertEquals('awaiting_generation', $projAwaiting['state']);
        $this->assertEquals('awaiting_generation', $projAwaiting['workflow']['phase']);

        // Completed
        $this->job->status = 'completed';
        $this->job->save();
        $this->run->state = 'completed';
        $this->run->save();

        $projCompleted = $progress->project($this->run);
        $this->assertEquals('completed', $projCompleted['state']);
        $this->assertEquals('completed', $projCompleted['workflow']['phase']);
    }
}
