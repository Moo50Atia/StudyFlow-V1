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
        // 1. v3_documents
        Schema::create('v3_documents', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->unsignedBigInteger('owner_user_id');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedSmallInteger('page_count');
            $table->string('state', 16); // 'pending_move' or 'ready'
            $table->string('storage_path', 128); // internal accepted/{id}.pdf
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->unique(['owner_user_id', 'sha256'], 'v3_documents_owner_sha256_unique');
            $table->unique(['id', 'owner_user_id'], 'v3_documents_id_owner_unique');
            $table->index(['owner_user_id', 'state', 'id'], 'v3_documents_owner_state_id_index');
        });

        // 2. v3_upload_attempts
        Schema::create('v3_upload_attempts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('upload_settings_version_id')->nullable();
            $table->char('document_id', 36)->nullable();
            $table->string('state', 24);
            $table->string('reason_code', 32)->nullable();
            $table->string('cleanup_target', 8)->nullable();
            $table->string('staging_path', 128)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedSmallInteger('scan_attempts')->default(0);
            $table->unsignedSmallInteger('technical_failures')->default(0);
            $table->boolean('duplicate')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('upload_settings_version_id')
                ->references('id')
                ->on('v3_settings_versions')
                ->restrictOnDelete();

            $table->foreign(['document_id', 'owner_user_id'], 'v3_upload_attempts_doc_owner_fk')
                ->references(['id', 'owner_user_id'])
                ->on('v3_documents')
                ->restrictOnDelete();

            $table->index(['owner_user_id', 'created_at', 'id'], 'v3_upload_attempts_owner_created_id_index');
            $table->index(['state', 'next_retry_at'], 'v3_upload_attempts_state_retry_index');
            $table->index(['state', 'lease_until'], 'v3_upload_attempts_state_lease_index');
            $table->index(['expires_at', 'state'], 'v3_upload_attempts_expires_state_index');
            $table->unique(['id', 'owner_user_id'], 'v3_upload_attempts_id_owner_unique');
        });

        // 3. v3_processing_cases
        Schema::create('v3_processing_cases', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->unsignedBigInteger('owner_user_id');
            $table->char('document_id', 36);
            $table->unsignedBigInteger('settings_version_id');
            $table->char('idempotency_key', 36);
            $table->string('action', 16); // 'process' or 'process_again'
            $table->string('state', 32)->default('awaiting_workflow');
            $table->unsignedBigInteger('state_version')->default(1);
            $table->timestamps();

            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign(['document_id', 'owner_user_id'], 'v3_cases_doc_owner_fk')
                ->references(['id', 'owner_user_id'])
                ->on('v3_documents')
                ->restrictOnDelete();

            $table->foreign('settings_version_id')
                ->references('id')
                ->on('v3_settings_versions')
                ->restrictOnDelete();

            $table->unique(['owner_user_id', 'idempotency_key'], 'v3_cases_owner_idempotency_unique');
            $table->index(['owner_user_id', 'document_id', 'created_at', 'id'], 'v3_cases_owner_doc_created_id_index');
            $table->index(['state', 'created_at', 'id'], 'v3_cases_state_created_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_processing_cases');
        Schema::dropIfExists('v3_upload_attempts');
        Schema::dropIfExists('v3_documents');
    }
};
