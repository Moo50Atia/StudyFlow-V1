<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v3_subject_runs') && ! Schema::hasColumn('v3_subject_runs', 'execution_mode')) {
            Schema::table('v3_subject_runs', function (Blueprint $table) {
                $table->string('execution_mode', 32)->nullable()->default('same_host');
            });
        }

        // 1. Devices & Pairings
        Schema::create('v3_processing_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('platform_owner_id');
            $table->string('token_hash', 64)->unique();
            $table->json('scopes');
            $table->json('capabilities');
            $table->string('status', 32)->default('active'); // active, revoked, suspended
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['platform_owner_id', 'status']);
        });

        Schema::create('v3_device_pairings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pairing_code_hash', 64)->unique();
            $table->unsignedBigInteger('platform_owner_id');
            $table->string('device_name');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->json('scopes');
            $table->json('capabilities');
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->uuid('redeemed_device_id')->nullable();
            $table->timestamps();

            $table->index(['platform_owner_id', 'expires_at']);
        });

        // 2. Local Jobs
        Schema::create('v3_local_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->unique();
            $table->unsignedBigInteger('teacher_id');
            $table->string('execution_mode', 32)->default('local_worker_v1');
            $table->string('output_scope', 32)->default('prompts_only');
            $table->string('status', 32)->default('queued');
            $table->string('settings_snapshot_id', 64);
            $table->string('settings_sha256', 64);
            $table->uuid('runtime_settings_artifact_id')->nullable();
            $table->json('policy_budgets');
            $table->unsignedInteger('control_version')->default(1);
            $table->timestamps();

            $table->index(['teacher_id', 'status']);
            $table->index('status');
        });

        // 3. Stage Tasks
        Schema::create('v3_local_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('job_id');
            $table->string('task_kind', 32); // author_prompts, return_scenes
            $table->string('status', 32)->default('queued');
            $table->uuid('input_manifest_id')->nullable();
            $table->string('input_manifest_sha256', 64)->nullable();
            $table->unsignedInteger('control_version')->default(1);
            $table->uuid('active_assignment_id')->nullable();
            $table->unsignedInteger('active_epoch')->default(0);
            $table->timestamps();

            $table->unique(['job_id', 'task_kind']);
            $table->index('status');
        });

        // 4. Assignments & Leases
        Schema::create('v3_local_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('device_id');
            $table->uuid('claim_id')->unique();
            $table->unsignedInteger('assignment_epoch');
            $table->timestamp('lease_expires_at');
            $table->timestamp('heartbeat_at');
            $table->string('status', 32)->default('active'); // active, released, expired, reconciliation_only, revoked
            $table->unsignedInteger('control_version')->default(1);
            $table->timestamps();

            $table->unique(['task_id', 'assignment_epoch']);
            $table->index(['task_id', 'status']);
            $table->index(['device_id', 'status']);
        });

        // 5. Provider Effects & Attempts
        Schema::create('v3_local_provider_effects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('job_id');
            $table->string('logical_effect_key');
            $table->string('operation_kind', 32);
            $table->integer('pdf_ordinal')->nullable();
            $table->integer('lesson_ordinal')->nullable();
            $table->integer('scene_ordinal')->nullable();
            $table->string('request_digest', 64);
            $table->string('status', 32)->default('reserved');
            $table->uuid('current_attempt_id')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('outcome_ref')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'logical_effect_key']);
            $table->index(['job_id', 'status']);
        });

        Schema::create('v3_local_effect_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('effect_id');
            $table->unsignedInteger('attempt_number');
            $table->uuid('device_id');
            $table->uuid('assignment_id');
            $table->unsignedInteger('assignment_epoch');
            $table->uuid('permit_id')->unique();
            $table->timestamp('permit_issued_at');
            $table->timestamp('permit_expires_at');
            $table->string('worker_process_id', 128)->nullable();
            $table->string('status', 32)->default('issued');
            $table->string('outcome_ref')->nullable();
            $table->timestamps();

            $table->unique(['effect_id', 'attempt_number']);
        });

        // 6. Artifacts
        Schema::create('v3_local_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('job_id');
            $table->uuid('task_id')->nullable();
            $table->string('purpose', 64);
            $table->string('digest_sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('media_type', 128);
            $table->string('logical_name');
            $table->string('storage_path');
            $table->boolean('is_quarantine')->default(true);
            $table->boolean('is_committed')->default(false);
            $table->timestamps();

            $table->index(['job_id', 'purpose']);
            $table->index(['job_id', 'digest_sha256']);
        });

        // 7. Results & Scene Submissions
        Schema::create('v3_local_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('job_id');
            $table->uuid('device_id');
            $table->uuid('assignment_id');
            $table->unsignedInteger('assignment_epoch');
            $table->string('result_kind', 64);
            $table->string('result_digest', 64);
            $table->string('manifest_sha256', 64);
            $table->json('receipt_payload');
            $table->timestamps();

            $table->unique(['task_id', 'result_digest']);
            $table->index('job_id');
        });

        Schema::create('v3_local_scene_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('result_id');
            $table->uuid('task_id');
            $table->uuid('scene_id');
            $table->uuid('prompt_version_id');
            $table->string('prompt_sha256', 64);
            $table->uuid('package_artifact_id');
            $table->string('package_sha256', 64);
            $table->string('provenance_mode', 32);
            $table->json('provenance_payload');
            $table->uuid('candidate_id')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'scene_id', 'prompt_version_id', 'package_sha256'], 'v3_local_scene_sub_unique');
            $table->index(['scene_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_local_scene_submissions');
        Schema::dropIfExists('v3_local_results');
        Schema::dropIfExists('v3_local_artifacts');
        Schema::dropIfExists('v3_local_effect_attempts');
        Schema::dropIfExists('v3_local_provider_effects');
        Schema::dropIfExists('v3_local_assignments');
        Schema::dropIfExists('v3_local_tasks');
        Schema::dropIfExists('v3_local_jobs');
        Schema::dropIfExists('v3_device_pairings');
        Schema::dropIfExists('v3_processing_devices');

        if (Schema::hasTable('v3_subject_runs') && Schema::hasColumn('v3_subject_runs', 'execution_mode')) {
            Schema::table('v3_subject_runs', function (Blueprint $table) {
                $table->dropColumn('execution_mode');
            });
        }
    }
};
