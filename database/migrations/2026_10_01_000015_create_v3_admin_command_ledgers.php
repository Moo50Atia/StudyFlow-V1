<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. v3_admin_commands
        Schema::create('v3_admin_commands', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36);
            $table->unsignedBigInteger('actor_user_id');
            $table->char('idempotency_key', 36);
            $table->char('request_sha256', 64);
            $table->string('action', 16);
            $table->string('reason_code', 40);
            $table->string('target_kind', 40)->nullable();
            $table->char('target_ref', 36)->nullable();
            $table->unsignedBigInteger('expected_case_version');
            $table->unsignedBigInteger('expected_control_version');
            $table->unsignedBigInteger('control_version_after')->nullable();
            $table->string('status', 24);
            $table->string('error_code', 40)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->unique(['case_id', 'idempotency_key'], 'v3_adm_cmd_case_idem_unique');
            $table->unique(['case_id', 'control_version_after'], 'v3_adm_cmd_case_ver_unique');
        });

        // 2. Add deferred foreign key to v3_case_operation_controls
        Schema::table('v3_case_operation_controls', function (Blueprint $table) {
            $table->foreign('last_command_id')
                ->references('id')
                ->on('v3_admin_commands')
                ->restrictOnDelete();
        });

        // 3. v3_admin_command_deliveries
        Schema::create('v3_admin_command_deliveries', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('command_id', 36);
            $table->char('case_id', 36);
            $table->string('workflow_id', 160);
            $table->string('run_id', 160)->nullable();
            $table->string('state', 24);
            $table->unsignedBigInteger('control_version');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamps();

            $table->foreign('command_id')
                ->references('id')
                ->on('v3_admin_commands')
                ->restrictOnDelete();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->unique(['command_id', 'workflow_id'], 'v3_adm_deliv_cmd_wf_unique');
        });

        // 4. v3_admin_operation_audits
        Schema::create('v3_admin_operation_audits', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36)->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('source_kind', 24)->nullable();
            $table->char('source_ref', 36)->nullable();
            $table->string('event', 40);
            $table->string('action_code', 24);
            $table->string('reason_code', 40)->nullable();
            $table->string('safe_denial_code', 40)->nullable();
            $table->string('old_control_mode', 32)->nullable();
            $table->string('new_control_mode', 32)->nullable();
            $table->unsignedBigInteger('old_control_version')->nullable();
            $table->unsignedBigInteger('new_control_version')->nullable();
            $table->timestamp('occurred_at');

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->unique(['source_kind', 'source_ref', 'event'], 'v3_adm_audit_src_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_admin_operation_audits');
        Schema::dropIfExists('v3_admin_command_deliveries');
        Schema::table('v3_case_operation_controls', function (Blueprint $table) {
            $table->dropForeign(['last_command_id']);
        });
        Schema::dropIfExists('v3_admin_commands');
    }
};
