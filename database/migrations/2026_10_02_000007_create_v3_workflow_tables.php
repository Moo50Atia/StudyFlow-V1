<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. v3_workflow_links
        Schema::create('v3_workflow_links', function (Blueprint $table) {
            $table->char('case_id', 36)->primary();
            $table->string('workflow_id', 64)->unique();
            $table->string('run_id', 64)->nullable();
            $table->unsignedBigInteger('settings_version_id');
            $table->char('settings_checksum', 64)->nullable();
            $table->string('start_state', 24)->default('pending');
            $table->string('stage_code', 32)->default('intake');
            $table->string('status_code', 24)->default('queued');
            $table->string('error_code', 32)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('scene_count')->default(0);
            $table->unsignedInteger('approved_scene_count')->default(0);
            $table->unsignedInteger('start_attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('start_attempted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->string('last_alert_code', 32)->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('settings_version_id')
                ->references('id')
                ->on('v3_settings_versions')
                ->restrictOnDelete();

            $table->index(['start_state', 'next_retry_at'], 'v3_workflow_links_state_retry_index');
            $table->index(['lease_until', 'start_state'], 'v3_workflow_links_lease_state_index');
            $table->index(['status_code', 'last_event_at'], 'v3_workflow_links_status_event_index');
        });

        // 2. v3_case_events
        Schema::create('v3_case_events', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36);
            $table->string('event_key', 128);
            $table->char('payload_digest', 64);
            $table->unsignedBigInteger('sequence');
            $table->string('workflow_id', 64);
            $table->string('run_id', 64);
            $table->string('event_type', 32);
            $table->string('stage_code', 32);
            $table->string('status_code', 24);
            $table->string('error_code', 32)->nullable();
            $table->timestamp('recorded_at');

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->unique(['case_id', 'event_key'], 'v3_case_events_case_key_unique');
            $table->unique(['case_id', 'sequence'], 'v3_case_events_case_sequence_unique');
            $table->index(['case_id', 'sequence'], 'v3_case_events_case_seq_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_case_events');
        Schema::dropIfExists('v3_workflow_links');
    }
};
