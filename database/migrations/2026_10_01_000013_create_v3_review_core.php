<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. v3_review_section_states
        Schema::create('v3_review_section_states', function (Blueprint $table) {
            $table->uuid('section_id')->primary();
            $table->uuid('case_id')->index();
            $table->uuid('current_review_item_id')->nullable()->index();
            $table->uuid('current_approval_id')->nullable()->index();
            $table->string('state', 40)->default('awaiting_qualification'); // awaiting_qualification|awaiting_review|routing|approved|blocked|unavailable
            $table->unsignedBigInteger('state_version')->default(1);
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
        });

        // 2. v3_teacher_review_items
        Schema::create('v3_teacher_review_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('candidate_id')->unique();
            $table->uuid('qualification_id')->index();
            $table->uuid('prompt_version_id')->index();
            $table->unsignedTinyInteger('candidate_version');
            $table->string('status', 40)->default('pending'); // pending|dormant|decided|stale
            $table->unsignedBigInteger('state_version')->default(1);
            $table->timestamp('ready_at');
            $table->timestamp('dormant_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['section_id', 'candidate_id'], 'v3_review_item_sec_cand_unique');
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
            $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->restrictOnDelete();
            $table->foreign('qualification_id')->references('id')->on('v3_scene_qualifications')->restrictOnDelete();
            $table->foreign('prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->restrictOnDelete();
        });

        // 3. v3_teacher_review_decisions
        Schema::create('v3_teacher_review_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('review_item_id')->unique();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('candidate_id')->index();
            $table->uuid('qualification_id')->index();
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedInteger('candidate_version');
            $table->unsignedInteger('expected_review_version');
            $table->uuid('idempotency_key');
            $table->char('request_sha256', 64);
            $table->string('decision', 30); // approve|request_changes
            $table->string('feedback_kind', 40)->nullable(); // visual_interaction|factual_source|mixed
            $table->string('note_path', 255)->nullable();
            $table->char('note_sha256', 64)->nullable();
            $table->unsignedInteger('note_bytes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['case_id', 'idempotency_key'], 'v3_review_dec_case_idem_unique');
            $table->foreign('review_item_id')->references('id')->on('v3_teacher_review_items')->restrictOnDelete();
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
            $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->restrictOnDelete();
            $table->foreign('qualification_id')->references('id')->on('v3_scene_qualifications')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        // 4. v3_approved_scenes
        Schema::create('v3_approved_scenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('candidate_id')->unique();
            $table->uuid('qualification_id')->index();
            $table->uuid('review_decision_id')->unique();
            $table->unsignedTinyInteger('candidate_version');
            $table->timestamp('approved_at')->useCurrent();

            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
            $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->restrictOnDelete();
            $table->foreign('qualification_id')->references('id')->on('v3_scene_qualifications')->restrictOnDelete();
            $table->foreign('review_decision_id')->references('id')->on('v3_teacher_review_decisions')->restrictOnDelete();
        });

        // 5. v3_postapproval_corrections
        Schema::create('v3_postapproval_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('approval_id')->unique();
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('idempotency_key');
            $table->char('request_sha256', 64);
            $table->string('feedback_kind', 40); // visual_interaction|factual_source|mixed
            $table->string('note_path', 255);
            $table->char('note_sha256', 64);
            $table->unsignedInteger('note_bytes');
            $table->string('state', 30)->default('accepted'); // accepted|routing|uncertain|blocked|completed
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['case_id', 'idempotency_key'], 'v3_postappr_case_idem_unique');
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
            $table->foreign('approval_id')->references('id')->on('v3_approved_scenes')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        // 6. v3_review_routes
        Schema::create('v3_review_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('review_decision_id')->nullable()->unique();
            $table->uuid('postapproval_correction_id')->nullable()->unique();
            $table->string('kind', 40); // scene_repair|prompt_requalification
            $table->string('state', 30)->default('pending'); // pending|claimed|uncertain|completed|blocked
            $table->uuid('target_ref')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
            $table->foreign('review_decision_id')->references('id')->on('v3_teacher_review_decisions')->restrictOnDelete();
            $table->foreign('postapproval_correction_id')->references('id')->on('v3_postapproval_corrections')->restrictOnDelete();
        });

        // 7. v3_review_outbox
        Schema::create('v3_review_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('review_decision_id')->nullable()->unique();
            $table->uuid('postapproval_correction_id')->nullable()->unique();
            $table->string('operation', 40); // signal_decision|start_correction
            $table->string('workflow_id', 160);
            $table->string('run_id', 160)->nullable();
            $table->string('state', 30)->default('pending'); // pending|sending|uncertain|delivered|blocked
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->foreign('review_decision_id')->references('id')->on('v3_teacher_review_decisions')->restrictOnDelete();
            $table->foreign('postapproval_correction_id')->references('id')->on('v3_postapproval_corrections')->restrictOnDelete();
        });

        // 8. v3_review_preview_sessions
        Schema::create('v3_review_preview_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('review_item_id')->index();
            $table->uuid('candidate_id')->index();
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('worker_session_ref')->nullable();
            $table->string('state', 30)->default('starting'); // starting|ready|unavailable|closed
            $table->timestamp('first_frame_at')->nullable();
            $table->timestamp('last_action_at')->nullable();
            $table->char('last_action_sha256', 64)->nullable();
            $table->unsignedInteger('last_frame_no')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('safe_error_code', 60)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('idle_expires_at');
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
            $table->foreign('review_item_id')->references('id')->on('v3_teacher_review_items')->restrictOnDelete();
            $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        // 9. Add cyclic foreign keys to v3_review_section_states
        Schema::table('v3_review_section_states', function (Blueprint $table) {
            $table->foreign('current_review_item_id')->references('id')->on('v3_teacher_review_items')->restrictOnDelete();
            $table->foreign('current_approval_id')->references('id')->on('v3_approved_scenes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('v3_review_section_states', function (Blueprint $table) {
            $table->dropForeign(['current_review_item_id']);
            $table->dropForeign(['current_approval_id']);
        });

        Schema::dropIfExists('v3_review_preview_sessions');
        Schema::dropIfExists('v3_review_outbox');
        Schema::dropIfExists('v3_review_routes');
        Schema::dropIfExists('v3_postapproval_corrections');
        Schema::dropIfExists('v3_approved_scenes');
        Schema::dropIfExists('v3_teacher_review_decisions');
        Schema::dropIfExists('v3_teacher_review_items');
        Schema::dropIfExists('v3_review_section_states');
    }
};
