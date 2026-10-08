<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v3_review_reminders', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36);
            $table->char('review_item_id', 36)->unique();
            $table->unsignedBigInteger('actor_user_id');
            $table->char('idempotency_key', 36);
            $table->char('request_sha256', 64);
            $table->string('state', 20)->default('posted');
            $table->timestamp('posted_at');
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('review_item_id')
                ->references('id')
                ->on('v3_teacher_review_items')
                ->restrictOnDelete();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->unique(['case_id', 'idempotency_key'], 'v3_rev_rem_case_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_review_reminders');
    }
};
