<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v3_deletion_assessments', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36);
            $table->unsignedBigInteger('actor_user_id');
            $table->char('idempotency_key', 36);
            $table->char('request_sha256', 64);
            $table->string('reason_code', 40)->default('pilot_cleanup_request');
            $table->string('policy_version', 40)->default('pilot-retain-v1');
            $table->string('status', 24); // deferred_to_015 | ineligible
            $table->string('safe_blocker', 32)->nullable(); // case_active | workflow_open | review_pending | effect_uncertain
            $table->unsignedBigInteger('case_state_version');
            $table->unsignedBigInteger('control_version');
            $table->timestamp('assessed_at');
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->unique(['case_id', 'idempotency_key'], 'v3_del_assess_case_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_deletion_assessments');
    }
};
