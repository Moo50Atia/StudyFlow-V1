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
        // 1. v3_subject_runs
        Schema::create('v3_subject_runs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('subject_id');
            $table->string('origin', 32); // 'web' or 'terminal_import'
            $table->string('protocol', 40)->default('notebooklm_per_pdf_web_v1');
            $table->unsignedBigInteger('settings_version_id');
            $table->char('framework_version_id', 36);
            $table->string('template_version_id', 64)->nullable();
            $table->string('mode', 30)->default('export_manual'); // 'export_manual' or 'api_generate'
            $table->string('qualification_mode', 30)->default('owner_waived_demo'); // 'real_jev' or 'owner_waived_demo'
            $table->text('captured_settings_json');
            $table->char('capture_sha256', 64);
            $table->char('submission_key', 36);
            $table->char('request_sha256', 64);
            $table->string('state', 40)->default('staging');
            $table->integer('state_version')->default(1);
            $table->string('workflow_id', 200)->nullable();
            $table->string('temporal_run_id', 200)->nullable();
            $table->string('namespace', 100)->default('v3-terminal');
            $table->string('generation_phase', 40)->nullable();
            $table->integer('total_pdfs')->default(0);
            $table->integer('total_lessons')->nullable();
            $table->integer('total_scenes')->nullable();
            $table->text('counters_json')->nullable();
            $table->integer('last_event_sequence')->default(0);
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();
            $table->foreign('settings_version_id')->references('id')->on('v3_settings_versions')->restrictOnDelete();
            $table->foreign('framework_version_id')->references('id')->on('v3_notebook_framework_versions')->restrictOnDelete();
            $table->foreign('template_version_id')->references('id')->on('v3_approved_templates')->nullOnDelete();

            $table->unique(['owner_user_id', 'submission_key'], 'v3_subject_runs_owner_sub_key_unique');
            $table->unique(['id', 'owner_user_id'], 'v3_subject_runs_id_owner_unique');
            $table->index(['subject_id', 'owner_user_id', 'created_at'], 'v3_subject_runs_subj_owner_created_idx');
        });

        // 2. v3_subject_run_items
        Schema::create('v3_subject_run_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('run_id', 36);
            $table->unsignedBigInteger('owner_user_id');
            $table->integer('pdf_ordinal');
            $table->string('display_title', 255);
            $table->char('upload_attempt_id', 36)->nullable();
            $table->char('document_id', 36)->nullable();
            $table->unsignedBigInteger('lecture_id')->nullable()->unique();
            $table->char('case_id', 36)->nullable()->unique();
            $table->string('original_filename', 255);
            $table->char('input_sha256', 64)->nullable();
            $table->unsignedBigInteger('input_bytes')->nullable();
            $table->string('accepted_input_ref', 255)->nullable();
            $table->string('state', 40)->default('staging');
            $table->string('outcome', 20)->nullable();
            $table->string('safe_code', 50)->nullable();
            $table->integer('work_count')->nullable();
            $table->integer('saved_lesson_count')->default(0);
            $table->integer('prompt_count')->default(0);
            $table->string('archive_ref', 255)->nullable();
            $table->char('archive_sha256', 64)->nullable();
            $table->integer('state_version')->default(1);
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('v3_subject_runs')->cascadeOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('upload_attempt_id')->references('id')->on('v3_upload_attempts')->nullOnDelete();
            $table->foreign('document_id')->references('id')->on('v3_documents')->nullOnDelete();
            $table->foreign('lecture_id')->references('id')->on('lectures')->nullOnDelete();
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->nullOnDelete();

            $table->unique(['run_id', 'pdf_ordinal'], 'v3_subject_run_items_run_ordinal_unique');
            $table->index(['run_id', 'owner_user_id'], 'v3_subject_run_items_run_owner_idx');
        });

        // 3. v3_notebook_instances
        Schema::create('v3_notebook_instances', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('run_id', 36)->unique();
            $table->unsignedBigInteger('owner_user_id');
            $table->string('adapter_kind', 40)->default('notebooklm_py');
            $table->string('provider_notebook_id', 200)->nullable();
            $table->string('private_lineage_ref', 255)->nullable();
            $table->string('credential_ref', 100)->default('mo50atiapro');
            $table->string('account_alias', 50)->default('mo50atiapro');
            $table->string('state', 40)->default('pending');
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('v3_subject_runs')->cascadeOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->index(['adapter_kind', 'provider_notebook_id'], 'v3_notebook_instances_adapter_prov_idx');
        });

        // 4. v3_pipeline_commands
        Schema::create('v3_pipeline_commands', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('run_id', 36);
            $table->unsignedBigInteger('actor_user_id');
            $table->string('action', 40);
            $table->char('request_sha256', 64);
            $table->integer('expected_version')->default(1);
            $table->string('private_payload_ref', 255)->nullable();
            $table->string('state', 40)->default('pending');
            $table->string('safe_code', 50)->nullable();
            $table->string('result_ref', 255)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('v3_subject_runs')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['run_id', 'id'], 'v3_pipeline_commands_run_id_unique');
            $table->index(['run_id', 'created_at'], 'v3_pipeline_commands_run_created_idx');
        });

        // 5. v3_pipeline_outbox
        Schema::create('v3_pipeline_outbox', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('command_id', 36)->unique();
            $table->string('destination', 50)->default('application_bridge');
            $table->string('state', 40)->default('pending');
            $table->integer('attempt_count')->default(0);
            $table->char('lease_token', 36)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('safe_code', 50)->nullable();
            $table->timestamps();

            $table->foreign('command_id')->references('id')->on('v3_pipeline_commands')->cascadeOnDelete();
            $table->index(['state', 'next_attempt_at'], 'v3_pipeline_outbox_state_next_idx');
        });

        // 6. v3_pipeline_events
        Schema::create('v3_pipeline_events', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('run_id', 36);
            $table->integer('sequence');
            $table->char('operation_id', 36)->nullable();
            $table->string('kind', 60);
            $table->string('phase', 40)->nullable();
            $table->string('outcome', 40)->nullable();
            $table->string('safe_code', 50)->nullable();
            $table->integer('attempt')->default(1);
            $table->integer('state_version')->default(1);
            $table->text('counts_json')->nullable();
            $table->timestamp('occurred_at');
            $table->boolean('historical')->default(false);
            $table->string('artifact_ref', 255)->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('v3_subject_runs')->cascadeOnDelete();

            $table->unique(['run_id', 'sequence'], 'v3_pipeline_events_run_seq_unique');
            $table->unique(['run_id', 'id'], 'v3_pipeline_events_run_id_unique');
            $table->index(['run_id', 'occurred_at'], 'v3_pipeline_events_run_occurred_idx');
        });

        // 7. v3_terminal_imports
        Schema::create('v3_terminal_imports', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->unsignedBigInteger('actor_admin_id');
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('subject_id');
            $table->char('source_run_id', 36);
            $table->char('archive_sha256', 64);
            $table->char('destination_run_id', 36)->nullable();
            $table->char('request_sha256', 64);
            $table->string('state', 40)->default('validated');
            $table->integer('committed_item_count')->default(0);
            $table->string('private_manifest_ref', 255)->nullable();
            $table->timestamps();

            $table->foreign('actor_admin_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();
            $table->foreign('destination_run_id')->references('id')->on('v3_subject_runs')->nullOnDelete();
        });

        // 8. v3_terminal_import_items
        Schema::create('v3_terminal_import_items', function (Blueprint $table) {
            $table->char('import_id', 36);
            $table->integer('pdf_ordinal');
            $table->char('request_sha256', 64);
            $table->char('destination_item_id', 36)->nullable();
            $table->timestamp('committed_at');

            $table->primary(['import_id', 'pdf_ordinal'], 'v3_import_items_pk');
            $table->foreign('import_id')->references('id')->on('v3_terminal_imports')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_terminal_import_items');
        Schema::dropIfExists('v3_terminal_imports');
        Schema::dropIfExists('v3_pipeline_events');
        Schema::dropIfExists('v3_pipeline_outbox');
        Schema::dropIfExists('v3_pipeline_commands');
        Schema::dropIfExists('v3_notebook_instances');
        Schema::dropIfExists('v3_subject_run_items');
        Schema::dropIfExists('v3_subject_runs');
    }
};
