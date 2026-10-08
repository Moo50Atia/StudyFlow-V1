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
        // 1. v3_map_activations
        Schema::create('v3_map_activations', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36)->unique();
            $table->char('binding_id', 36);
            $table->string('workflow_id', 64);
            $table->string('state', 24)->default('pending');
            $table->unsignedInteger('signal_attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('signal_attempted_at')->nullable();
            $table->timestamp('signal_accepted_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->string('last_alert_code', 32)->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('binding_id')
                ->references('id')
                ->on('v3_knowledge_source_bindings')
                ->restrictOnDelete();

            $table->index(['state', 'next_check_at'], 'v3_map_act_state_check_index');
            $table->index(['lease_until', 'state'], 'v3_map_act_lease_state_index');
        });

        // 2. v3_lesson_maps
        Schema::create('v3_lesson_maps', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36)->unique();
            $table->char('binding_id', 36)->unique();
            $table->char('activation_id', 36)->unique();
            $table->string('state', 24)->default('pending');
            $table->char('divide_query_id', 36)->nullable();
            $table->char('coverage_query_id', 36)->nullable();
            $table->char('divide_prompt_sha256', 64)->nullable();
            $table->char('coverage_prompt_sha256', 64)->nullable();
            $table->char('map_sha256', 64)->nullable();
            $table->unsignedInteger('page_count');
            $table->unsignedInteger('work_count')->default(0);
            $table->string('error_code', 32)->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('binding_id')
                ->references('id')
                ->on('v3_knowledge_source_bindings')
                ->restrictOnDelete();

            $table->foreign('activation_id')
                ->references('id')
                ->on('v3_map_activations')
                ->restrictOnDelete();
        });

        // 3. v3_lesson_nodes
        Schema::create('v3_lesson_nodes', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('map_id', 36);
            $table->char('parent_id', 36)->nullable();
            $table->string('kind', 24);
            $table->string('position_path', 64);
            $table->unsignedInteger('ordinal_in_parent');
            $table->string('title', 255);
            $table->unsignedInteger('work_ordinal')->nullable();
            $table->json('coverage_pages')->nullable();
            $table->timestamps();

            $table->foreign('map_id')
                ->references('id')
                ->on('v3_lesson_maps')
                ->restrictOnDelete();

            $table->foreign('parent_id')
                ->references('id')
                ->on('v3_lesson_nodes')
                ->restrictOnDelete();

            $table->unique(['map_id', 'position_path'], 'v3_lesson_nodes_map_path_unique');
            $table->unique(['map_id', 'work_ordinal'], 'v3_lesson_nodes_map_work_unique');
        });

        // 4. v3_lesson_queries
        Schema::create('v3_lesson_queries', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36);
            $table->char('map_id', 36)->nullable();
            $table->string('kind', 24);
            $table->unsignedInteger('target_work_ordinal')->default(0);
            $table->string('state', 24);
            $table->unsignedInteger('send_count')->default(0);
            $table->char('request_sha256', 64);
            $table->char('canonical_prompt_sha256', 64)->nullable();
            $table->string('conversation_in', 255)->nullable();
            $table->string('conversation_out', 255)->nullable();
            $table->string('answer_path', 255)->nullable();
            $table->char('answer_sha256', 64)->nullable();
            $table->unsignedBigInteger('answer_bytes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->string('last_alert_code', 32)->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('map_id')
                ->references('id')
                ->on('v3_lesson_maps')
                ->restrictOnDelete();

            $table->unique(['case_id', 'kind', 'target_work_ordinal'], 'v3_lesson_queries_case_kind_work_unique');
        });

        // Add FKs on v3_lesson_maps to v3_lesson_queries
        Schema::table('v3_lesson_maps', function (Blueprint $table) {
            $table->foreign('divide_query_id')
                ->references('id')
                ->on('v3_lesson_queries')
                ->restrictOnDelete();

            $table->foreign('coverage_query_id')
                ->references('id')
                ->on('v3_lesson_queries')
                ->restrictOnDelete();
        });

        // 5. v3_teacher_sections
        Schema::create('v3_teacher_sections', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36);
            $table->char('map_id', 36);
            $table->char('work_node_id', 36);
            $table->char('query_id', 36);
            $table->unsignedInteger('ordinal_in_work');
            $table->longText('section_identity');
            $table->longText('core_understanding');
            $table->longText('visual_first_explanation');
            $table->longText('visual_scene_script');
            $table->longText('dynamic_view_blueprint');
            $table->longText('visual_learning_outcome');
            $table->longText('concept_check');
            $table->longText('conceptual_summary');
            $table->longText('colloquial_egyptian_explanation');
            $table->longText('laws_if_any');
            $table->longText('real_life_example');
            $table->char('first_prompt_sha256', 64);
            $table->char('answer_sha256', 64);
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('map_id')
                ->references('id')
                ->on('v3_lesson_maps')
                ->restrictOnDelete();

            $table->foreign('work_node_id')
                ->references('id')
                ->on('v3_lesson_nodes')
                ->restrictOnDelete();

            $table->foreign('query_id')
                ->references('id')
                ->on('v3_lesson_queries')
                ->restrictOnDelete();

            $table->unique(['work_node_id', 'ordinal_in_work'], 'v3_teacher_sections_node_ordinal_unique');
        });

        // 6. v3_lesson_completions
        Schema::create('v3_lesson_completions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('case_id', 36);
            $table->char('map_id', 36);
            $table->char('work_node_id', 36);
            $table->unsignedInteger('work_ordinal');
            $table->json('section_refs');
            $table->json('third_output_refs');
            $table->string('completion_ref', 255);
            $table->char('completion_sha256', 64);
            $table->string('final_conversation_id', 255)->nullable();
            $table->string('signal_state', 24)->default('committed');
            $table->unsignedInteger('signal_attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('signal_attempted_at')->nullable();
            $table->timestamp('signal_accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('map_id')
                ->references('id')
                ->on('v3_lesson_maps')
                ->restrictOnDelete();

            $table->foreign('work_node_id')
                ->references('id')
                ->on('v3_lesson_nodes')
                ->restrictOnDelete();

            $table->unique(['case_id', 'work_ordinal'], 'v3_lesson_comp_case_work_unique');
            $table->unique(['case_id', 'completion_ref'], 'v3_lesson_comp_case_ref_unique');
        });

        // 7. v3_lesson_cursors
        Schema::create('v3_lesson_cursors', function (Blueprint $table) {
            $table->char('case_id', 36)->primary();
            $table->char('map_id', 36)->unique();
            $table->unsignedInteger('current_work_ordinal')->default(1);
            $table->string('phase', 24);
            $table->string('conversation_id', 255)->nullable();
            $table->char('last_query_id', 36)->nullable();
            $table->char('last_completion_id', 36)->nullable();
            $table->string('error_code', 32)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('map_id')
                ->references('id')
                ->on('v3_lesson_maps')
                ->restrictOnDelete();

            $table->foreign('last_query_id')
                ->references('id')
                ->on('v3_lesson_queries')
                ->restrictOnDelete();

            $table->foreign('last_completion_id')
                ->references('id')
                ->on('v3_lesson_completions')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_lesson_cursors');
        Schema::dropIfExists('v3_lesson_completions');
        Schema::dropIfExists('v3_teacher_sections');
        Schema::table('v3_lesson_maps', function (Blueprint $table) {
            $table->dropForeign(['divide_query_id']);
            $table->dropForeign(['coverage_query_id']);
        });
        Schema::dropIfExists('v3_lesson_queries');
        Schema::dropIfExists('v3_lesson_nodes');
        Schema::dropIfExists('v3_lesson_maps');
        Schema::dropIfExists('v3_map_activations');
    }
};
