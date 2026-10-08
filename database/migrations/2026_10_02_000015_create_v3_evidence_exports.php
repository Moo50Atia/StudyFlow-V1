<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v3_evidence_exports', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('actor_role', 8); // teacher|admin
            $table->char('idempotency_key', 36);
            $table->char('request_sha256', 64);
            $table->string('scope', 16); // owner_case|owner_approval|admin_period
            $table->char('case_id', 36)->nullable();
            $table->char('approval_id', 36)->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('serializer_version', 24)->default('evidence-json-v1');
            $table->timestamp('as_of');
            $table->string('snapshot_path', 255);
            $table->char('snapshot_sha256', 64);
            $table->unsignedInteger('snapshot_bytes');
            $table->timestamp('expires_at');
            $table->timestamp('file_removed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('approval_id')
                ->references('id')
                ->on('v3_approved_scenes')
                ->restrictOnDelete();

            $table->unique(['actor_user_id', 'idempotency_key'], 'v3_exports_actor_idempotency_unique');
            $table->index(['case_id']);
            $table->index(['approval_id']);
            $table->index(['expires_at', 'file_removed_at']);
        });

        Schema::create('v3_evidence_export_audits', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('export_id', 36);
            $table->unsignedBigInteger('actor_user_id');
            $table->string('event', 32); // generated|download_attempt
            $table->timestamp('occurred_at');
            $table->string('result_code', 32); // ready|stream_started|expired|file_unavailable
            $table->char('snapshot_sha256', 64)->nullable();

            $table->foreign('export_id')
                ->references('id')
                ->on('v3_evidence_exports')
                ->restrictOnDelete();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(['export_id', 'event']);
            $table->index(['actor_user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_evidence_export_audits');
        Schema::dropIfExists('v3_evidence_exports');
    }
};
