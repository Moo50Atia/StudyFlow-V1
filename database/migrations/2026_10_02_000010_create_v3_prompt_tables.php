<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Prompt Work Items
        Schema::create('v3_prompt_work_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->uuid('map_id');
            $table->uuid('work_node_id');
            $table->unsignedInteger('work_ordinal');
            $table->unsignedBigInteger('expected_cursor_version');
            $table->uuid('section_query_id');
            $table->string('state', 32); // awaiting_execution|awaiting_second|awaiting_third|originals_ready|receipt_committed|uncertain|blocked
            $table->json('section_refs');
            $table->uuid('execution_turn_id')->nullable();
            $table->uuid('second_turn_id')->nullable();
            $table->uuid('third_turn_id')->nullable();
            $table->string('completion_ref', 255)->nullable();
            $table->char('completion_sha256', 64)->nullable();
            $table->string('final_conversation_id', 255)->nullable();
            $table->string('last_alert_code', 32)->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamp('receipt_committed_at')->nullable();
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
            $table->foreign('map_id')->references('id')->on('v3_lesson_maps')->onDelete('restrict');
            $table->foreign('work_node_id')->references('id')->on('v3_lesson_nodes')->onDelete('restrict');
            $table->foreign('section_query_id')->references('id')->on('v3_lesson_queries')->onDelete('restrict');

            $table->unique(['case_id', 'work_ordinal']);
            $table->unique(['case_id', 'completion_ref']);
            $table->index(['case_id', 'state', 'work_ordinal']);
        });

        // 2. Prompt Turns
        Schema::create('v3_prompt_turns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('work_item_id');
            $table->string('step', 32); // execution|second|third
            $table->string('origin', 32); // sent|execution_reuse|continue_reuse
            $table->uuid('origin_query_id')->nullable();
            $table->uuid('origin_turn_id')->nullable();
            $table->string('state', 32); // claimed|uncertain|recorded|blocked
            $table->unsignedTinyInteger('send_count')->default(0);
            $table->char('request_sha256', 64);
            $table->char('canonical_sha256', 64);
            $table->char('answer_sha256', 64)->nullable();
            $table->string('conversation_in', 255);
            $table->string('conversation_out', 255)->nullable();
            $table->string('answer_path', 255)->nullable();
            $table->unsignedBigInteger('answer_bytes')->nullable();
            $table->json('parsed_mappings')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->timestamps();

            $table->foreign('work_item_id')->references('id')->on('v3_prompt_work_items')->onDelete('restrict');
            $table->foreign('origin_query_id')->references('id')->on('v3_lesson_queries')->onDelete('restrict');
            $table->foreign('origin_turn_id')->references('id')->on('v3_prompt_turns')->onDelete('restrict');

            $table->unique(['work_item_id', 'step']);
            $table->index(['state', 'sent_at']);
        });

        // Add turn foreign keys to prompt work items now that v3_prompt_turns exists
        Schema::table('v3_prompt_work_items', function (Blueprint $table) {
            $table->foreign('execution_turn_id')->references('id')->on('v3_prompt_turns')->onDelete('restrict');
            $table->foreign('second_turn_id')->references('id')->on('v3_prompt_turns')->onDelete('restrict');
            $table->foreign('third_turn_id')->references('id')->on('v3_prompt_turns')->onDelete('restrict');
        });

        // 3. Scene Prompt Versions
        Schema::create('v3_scene_prompt_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->uuid('work_item_id');
            $table->uuid('section_id');
            $table->unsignedSmallInteger('version_no');
            $table->uuid('predecessor_id')->nullable();
            $table->string('origin', 32); // third|feedback
            $table->uuid('third_turn_id')->nullable();
            $table->uuid('feedback_effect_id')->nullable();
            $table->string('body_path', 255);
            $table->char('body_sha256', 64);
            $table->unsignedInteger('body_bytes');
            $table->string('scene_type', 32); // Process|Comparison|Accumulation|Parallel Flow|Time Evolution
            $table->char('section_fields_sha256', 64);
            $table->char('canonical_third_sha256', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
            $table->foreign('work_item_id')->references('id')->on('v3_prompt_work_items')->onDelete('restrict');
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->onDelete('restrict');
            $table->foreign('predecessor_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
            $table->foreign('third_turn_id')->references('id')->on('v3_prompt_turns')->onDelete('restrict');

            $table->unique(['section_id', 'version_no']);
            $table->unique(['section_id', 'body_sha256', 'version_no']);
            $table->unique(['work_item_id', 'section_id', 'version_no']);
        });

        // 4. Prompt JEV Attempts
        Schema::create('v3_prompt_jev_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('prompt_version_id');
            $table->unsignedSmallInteger('attempt_no');
            $table->char('request_sha256', 64);
            $table->char('question_sha256', 64);
            $table->string('requested_model', 100);
            $table->foreignId('settings_version_id')->constrained('v3_settings_versions')->onDelete('restrict');
            $table->string('state', 32); // claimed|technical_failure|recorded
            $table->string('error_code', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
            $table->unique(['prompt_version_id', 'attempt_no']);
            $table->index(['state', 'sent_at']);
        });

        // 5. Prompt Qualifications
        Schema::create('v3_prompt_qualifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('prompt_version_id')->unique();
            $table->uuid('winning_attempt_id')->unique();
            $table->uuid('case_id');
            $table->uuid('section_id');
            $table->foreignId('settings_version_id')->constrained('v3_settings_versions')->onDelete('restrict');
            $table->char('settings_checksum', 64);
            $table->char('request_sha256', 64);
            $table->char('answer_sha256', 64);
            $table->char('question_sha256', 64);
            $table->string('rule_version', 32)->default('prompt-rule-v1');
            $table->string('requested_model', 100);
            $table->string('returned_model', 100);
            $table->string('choice', 16); // pass|revise|block
            $table->decimal('choice_confidence', 20, 18);
            $table->decimal('score', 21, 18);
            $table->decimal('score_confidence', 20, 18);
            $table->decimal('noul_probability', 20, 18);
            $table->boolean('noul_yes');
            $table->unsignedTinyInteger('min_score');
            $table->unsignedTinyInteger('min_confidence');
            $table->string('decision', 16); // pass|revise|block
            $table->json('failed_checks');
            $table->string('answer_path', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
            $table->foreign('winning_attempt_id')->references('id')->on('v3_prompt_jev_attempts')->onDelete('restrict');
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->onDelete('restrict');
        });

        // 6. Prompt Feedback Effects
        Schema::create('v3_prompt_feedback_effects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->uuid('section_id');
            $table->uuid('from_prompt_version_id')->unique();
            $table->char('request_sha256', 64);
            $table->string('template_id', 64);
            $table->char('template_sha256', 64);
            $table->string('provider_id', 32); // gemini_api|glm_api|deepseek_api
            $table->string('provider_model', 100);
            $table->string('credential_ref', 32);
            $table->string('state', 32); // claimed|uncertain|recorded|blocked
            $table->unsignedTinyInteger('send_count')->default(0);
            $table->string('output_path', 255)->nullable();
            $table->char('output_sha256', 64)->nullable();
            $table->string('response_path', 255)->nullable();
            $table->char('response_sha256', 64)->nullable();
            $table->string('returned_model', 100)->nullable();
            $table->string('provider_response_id', 255)->nullable();
            $table->unsignedInteger('output_bytes')->nullable();
            $table->uuid('to_prompt_version_id')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->onDelete('restrict');
            $table->foreign('from_prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
            $table->foreign('to_prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
        });

        // Add feedback_effect_id foreign key to v3_scene_prompt_versions
        Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
            $table->foreign('feedback_effect_id')->references('id')->on('v3_prompt_feedback_effects')->onDelete('restrict');
        });

        // 7. Prompt Section States
        Schema::create('v3_prompt_section_states', function (Blueprint $table) {
            $table->uuid('section_id')->primary();
            $table->uuid('case_id');
            $table->uuid('current_prompt_version_id')->nullable();
            $table->string('status', 32); // awaiting_prompt|awaiting_qualification|revising|qualified|uncertain|blocked
            $table->unsignedSmallInteger('revision_count')->default(0);
            $table->uuid('last_qualification_id')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->string('last_alert_code', 32)->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamps();

            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->onDelete('restrict');
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
            $table->foreign('current_prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
            $table->foreign('last_qualification_id')->references('id')->on('v3_prompt_qualifications')->onDelete('restrict');

            $table->index(['case_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_prompt_section_states');

        Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
            $table->dropForeign(['feedback_effect_id']);
        });

        Schema::dropIfExists('v3_prompt_feedback_effects');
        Schema::dropIfExists('v3_prompt_qualifications');
        Schema::dropIfExists('v3_prompt_jev_attempts');
        Schema::dropIfExists('v3_scene_prompt_versions');

        Schema::table('v3_prompt_work_items', function (Blueprint $table) {
            $table->dropForeign(['execution_turn_id']);
            $table->dropForeign(['second_turn_id']);
            $table->dropForeign(['third_turn_id']);
        });

        Schema::dropIfExists('v3_prompt_turns');
        Schema::dropIfExists('v3_prompt_work_items');
    }
};
