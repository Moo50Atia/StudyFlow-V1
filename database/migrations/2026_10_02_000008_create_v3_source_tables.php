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
        // 1. v3_source_activations
        Schema::create('v3_source_activations', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36)->unique();
            $table->string('workflow_id', 64);
            $table->string('state', 24)->default('pending');
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('signal_attempted_at')->nullable();
            $table->timestamp('signal_accepted_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedInteger('signal_attempts')->default(0);
            $table->string('error_code', 32)->nullable();
            $table->string('last_alert_code', 32)->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->index(['state', 'next_check_at'], 'v3_src_act_state_check_index');
            $table->index(['lease_until', 'state'], 'v3_src_act_lease_state_index');
        });

        // 2. v3_extracted_sources
        Schema::create('v3_extracted_sources', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36)->unique();
            $table->char('activation_id', 36)->unique();
            $table->char('document_id', 36);
            $table->unsignedBigInteger('settings_version_id');
            $table->string('status', 16);
            $table->string('artifact_path', 255)->nullable();
            $table->char('text_sha256', 64)->nullable();
            $table->unsignedBigInteger('text_bytes')->default(0);
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('ocr_page_count')->default(0);
            $table->json('page_report');
            $table->string('error_code', 32)->nullable();
            $table->string('extractor_version', 64);
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('activation_id')
                ->references('id')
                ->on('v3_source_activations')
                ->restrictOnDelete();

            $table->foreign('settings_version_id')
                ->references('id')
                ->on('v3_settings_versions')
                ->restrictOnDelete();
        });

        // 3. v3_knowledge_source_bindings
        Schema::create('v3_knowledge_source_bindings', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36)->unique();
            $table->char('extracted_source_id', 36)->unique();
            $table->string('adapter_kind', 24);
            $table->string('adapter_version', 64);
            $table->string('notebook_title', 128);
            $table->string('source_title', 128);
            $table->string('notebook_id', 255)->nullable();
            $table->string('source_id', 255)->nullable();
            $table->string('state', 24);
            $table->char('text_sha256', 64);
            $table->unsignedBigInteger('text_bytes');
            $table->string('transfer_status', 16)->default('not_sent');
            $table->timestamp('send_attempted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->string('last_alert_code', 32)->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('extracted_source_id')
                ->references('id')
                ->on('v3_extracted_sources')
                ->restrictOnDelete();

            $table->unique(['adapter_kind', 'notebook_id'], 'v3_ksb_adapter_notebook_unique');
            $table->unique(['adapter_kind', 'source_id'], 'v3_ksb_adapter_source_unique');
        });

        // 4. v3_source_effects
        Schema::create('v3_source_effects', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36);
            $table->string('operation', 24);
            $table->char('request_digest', 64);
            $table->string('state', 16);
            $table->unsignedInteger('send_count')->default(0);
            $table->string('provider_ref', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->unique(['case_id', 'operation'], 'v3_src_effects_case_op_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_source_effects');
        Schema::dropIfExists('v3_knowledge_source_bindings');
        Schema::dropIfExists('v3_extracted_sources');
        Schema::dropIfExists('v3_source_activations');
    }
};
