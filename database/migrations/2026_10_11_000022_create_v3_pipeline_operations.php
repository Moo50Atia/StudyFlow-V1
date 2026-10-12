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
        if (! Schema::hasTable('v3_pipeline_operations')) {
            Schema::create('v3_pipeline_operations', function (Blueprint $table) {
                $table->string('id', 64)->primary(); // operation_id
                $table->char('run_id', 36);
                $table->char('item_id', 36)->nullable();
                $table->string('kind', 60)->default('unknown');
                $table->integer('expected_version')->default(1);
                $table->char('request_sha256', 64);
                $table->char('lease_token', 36)->nullable();
                $table->char('context_ref', 36)->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->string('status', 40)->default('pending'); // pending, completed, failed, cancelled
                $table->string('outcome', 40)->nullable();
                $table->string('safe_code', 50)->nullable();
                $table->char('result_ref', 36)->nullable();
                $table->char('result_sha256', 64)->nullable();
                $table->text('counts_json')->nullable();
                $table->integer('state_version')->nullable();
                $table->timestamps();

                $table->foreign('run_id')->references('id')->on('v3_subject_runs')->cascadeOnDelete();
                $table->foreign('item_id')->references('id')->on('v3_subject_run_items')->nullOnDelete();

                $table->index(['run_id', 'status'], 'v3_pipeline_ops_run_status_idx');
                $table->index(['lease_token'], 'v3_pipeline_ops_lease_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_pipeline_operations');
    }
};
