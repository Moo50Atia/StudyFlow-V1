<?php

namespace Tests\Feature\V3\LocalWorker;

use App\Models\User;
use App\Models\V3\DevicePairing;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalEffectAttempt;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalResult;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\SubjectRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalStorageFencingTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_pairings_enforce_unique_code_hash(): void
    {
        $owner = User::factory()->create();
        $codeHash = str_repeat('a', 64);

        DevicePairing::create([
            'id' => (string) Str::uuid(),
            'pairing_code_hash' => $codeHash,
            'platform_owner_id' => $owner->id,
            'device_name' => 'MacBook Pro',
            'scopes' => ['tenant:all'],
            'capabilities' => ['notebooklm_v1'],
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->expectException(QueryException::class);

        DevicePairing::create([
            'id' => (string) Str::uuid(),
            'pairing_code_hash' => $codeHash,
            'platform_owner_id' => $owner->id,
            'device_name' => 'Duplicate Device',
            'scopes' => ['tenant:all'],
            'capabilities' => ['notebooklm_v1'],
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    public function test_processing_devices_enforce_unique_token_hash(): void
    {
        $owner = User::factory()->create();
        $tokenHash = str_repeat('b', 64);

        ProcessingDevice::create([
            'id' => (string) Str::uuid(),
            'name' => 'Worker 1',
            'platform_owner_id' => $owner->id,
            'token_hash' => $tokenHash,
            'scopes' => ['tenant:all'],
            'capabilities' => ['notebooklm_v1'],
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        $this->expectException(QueryException::class);

        ProcessingDevice::create([
            'id' => (string) Str::uuid(),
            'name' => 'Worker 2',
            'platform_owner_id' => $owner->id,
            'token_hash' => $tokenHash,
            'scopes' => ['tenant:all'],
            'capabilities' => ['notebooklm_v1'],
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function test_local_jobs_enforce_unique_run_id(): void
    {
        $teacher = User::factory()->create();
        $runId = (string) Str::uuid();

        LocalJob::create([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'teacher_id' => $teacher->id,
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'status' => 'queued',
            'settings_snapshot_id' => (string) Str::uuid(),
            'settings_sha256' => str_repeat('c', 64),
            'policy_budgets' => ['max_output_bytes' => 536870912],
            'control_version' => 1,
        ]);

        $this->expectException(QueryException::class);

        LocalJob::create([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'teacher_id' => $teacher->id,
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'status' => 'queued',
            'settings_snapshot_id' => (string) Str::uuid(),
            'settings_sha256' => str_repeat('c', 64),
            'policy_budgets' => ['max_output_bytes' => 536870912],
            'control_version' => 1,
        ]);
    }

    public function test_local_tasks_enforce_unique_job_and_task_kind(): void
    {
        $teacher = User::factory()->create();
        $job = LocalJob::create([
            'id' => (string) Str::uuid(),
            'run_id' => (string) Str::uuid(),
            'teacher_id' => $teacher->id,
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'status' => 'queued',
            'settings_snapshot_id' => (string) Str::uuid(),
            'settings_sha256' => str_repeat('c', 64),
            'policy_budgets' => [],
            'control_version' => 1,
        ]);

        LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'task_kind' => 'author_prompts',
            'status' => 'queued',
            'control_version' => 1,
        ]);

        $this->expectException(QueryException::class);

        LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'task_kind' => 'author_prompts', // duplicate kind for same job
            'status' => 'queued',
            'control_version' => 1,
        ]);
    }

    public function test_local_assignments_enforce_epoch_and_claim_uniqueness(): void
    {
        $owner = User::factory()->create();
        $device = ProcessingDevice::create([
            'id' => (string) Str::uuid(),
            'name' => 'Worker 1',
            'platform_owner_id' => $owner->id,
            'token_hash' => str_repeat('d', 64),
            'scopes' => ['tenant:all'],
            'capabilities' => ['notebooklm_v1'],
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        $job = LocalJob::create([
            'id' => (string) Str::uuid(),
            'run_id' => (string) Str::uuid(),
            'teacher_id' => $owner->id,
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'status' => 'queued',
            'settings_snapshot_id' => (string) Str::uuid(),
            'settings_sha256' => str_repeat('c', 64),
            'policy_budgets' => [],
            'control_version' => 1,
        ]);

        $task = LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'task_kind' => 'author_prompts',
            'status' => 'queued',
            'control_version' => 1,
        ]);

        $claimId = (string) Str::uuid();

        LocalAssignment::create([
            'id' => (string) Str::uuid(),
            'task_id' => $task->id,
            'device_id' => $device->id,
            'claim_id' => $claimId,
            'assignment_epoch' => 1,
            'lease_expires_at' => now()->addSeconds(120),
            'heartbeat_at' => now(),
            'status' => 'active',
            'control_version' => 1,
        ]);

        $this->expectException(QueryException::class);

        // Attempting to create duplicate epoch for same task
        LocalAssignment::create([
            'id' => (string) Str::uuid(),
            'task_id' => $task->id,
            'device_id' => $device->id,
            'claim_id' => (string) Str::uuid(),
            'assignment_epoch' => 1, // duplicate epoch!
            'lease_expires_at' => now()->addSeconds(120),
            'heartbeat_at' => now(),
            'status' => 'active',
            'control_version' => 1,
        ]);
    }

    public function test_local_provider_effects_enforce_logical_key_uniqueness(): void
    {
        $owner = User::factory()->create();
        $job = LocalJob::create([
            'id' => (string) Str::uuid(),
            'run_id' => (string) Str::uuid(),
            'teacher_id' => $owner->id,
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'status' => 'queued',
            'settings_snapshot_id' => (string) Str::uuid(),
            'settings_sha256' => str_repeat('c', 64),
            'policy_budgets' => [],
            'control_version' => 1,
        ]);

        $task = LocalTask::create([
            'id' => (string) Str::uuid(),
            'job_id' => $job->id,
            'task_kind' => 'author_prompts',
            'status' => 'queued',
            'control_version' => 1,
        ]);

        $effectKey = 'author_prompts:create_notebook';

        LocalProviderEffect::create([
            'id' => (string) Str::uuid(),
            'task_id' => $task->id,
            'job_id' => $job->id,
            'logical_effect_key' => $effectKey,
            'operation_kind' => 'create_notebook',
            'request_digest' => str_repeat('e', 64),
            'status' => 'reserved',
            'attempt_count' => 0,
        ]);

        $this->expectException(QueryException::class);

        LocalProviderEffect::create([
            'id' => (string) Str::uuid(),
            'task_id' => $task->id,
            'job_id' => $job->id,
            'logical_effect_key' => $effectKey, // duplicate logical key under same task!
            'operation_kind' => 'create_notebook',
            'request_digest' => str_repeat('f', 64),
            'status' => 'reserved',
            'attempt_count' => 0,
        ]);
    }
}
