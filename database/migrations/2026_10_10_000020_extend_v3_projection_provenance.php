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
        // 1. v3_lecture_pipeline_links
        if (! Schema::hasTable('v3_lecture_pipeline_links')) {
            Schema::create('v3_lecture_pipeline_links', function (Blueprint $table) {
                $table->unsignedBigInteger('lecture_id')->primary();
                $table->char('item_id', 36)->unique();
                $table->unsignedBigInteger('owner_user_id');
                $table->string('protocol', 40)->default('notebooklm_per_pdf_web_v1');
                $table->timestamps();

                $table->foreign('lecture_id')->references('id')->on('lectures')->cascadeOnDelete();
                $table->foreign('item_id')->references('id')->on('v3_subject_run_items')->cascadeOnDelete();
                $table->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        // 2. v3_section_pipeline_links
        if (! Schema::hasTable('v3_section_pipeline_links')) {
            Schema::create('v3_section_pipeline_links', function (Blueprint $table) {
                $table->unsignedBigInteger('section_id')->primary();
                $table->char('teacher_section_id', 36)->unique();
                $table->char('item_id', 36);
                $table->unsignedBigInteger('owner_user_id');
                $table->timestamps();

                $table->foreign('section_id')->references('id')->on('sections')->cascadeOnDelete();
                $table->foreign('teacher_section_id')->references('id')->on('v3_teacher_sections')->cascadeOnDelete();
                $table->foreign('item_id')->references('id')->on('v3_subject_run_items')->cascadeOnDelete();
                $table->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        // 3. Extend v3_processing_cases
        Schema::table('v3_processing_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_processing_cases', 'subject_run_item_id')) {
                $table->char('subject_run_item_id', 36)->nullable()->unique();
                $table->foreign('subject_run_item_id')->references('id')->on('v3_subject_run_items')->nullOnDelete();
            }
        });

        // 4. Extend v3_knowledge_source_bindings
        Schema::table('v3_knowledge_source_bindings', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_knowledge_source_bindings', 'notebook_instance_id')) {
                $table->char('notebook_instance_id', 36)->nullable();
                $table->foreign('notebook_instance_id')->references('id')->on('v3_notebook_instances')->nullOnDelete();
            }
            if (! Schema::hasColumn('v3_knowledge_source_bindings', 'subject_run_item_id')) {
                $table->char('subject_run_item_id', 36)->nullable()->unique();
                $table->foreign('subject_run_item_id')->references('id')->on('v3_subject_run_items')->nullOnDelete();
            }
        });

        // 5. Extend v3_teacher_sections
        Schema::table('v3_teacher_sections', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_teacher_sections', 'origin')) {
                $table->string('origin', 24)->default('pipeline');
            }
            if (! Schema::hasColumn('v3_teacher_sections', 'projection_status')) {
                $table->string('projection_status', 24)->default('complete');
            }
            if (! Schema::hasColumn('v3_teacher_sections', 'missing_fields_json')) {
                $table->text('missing_fields_json')->nullable();
            }
            if (! Schema::hasColumn('v3_teacher_sections', 'raw_bundle_ref')) {
                $table->char('raw_bundle_ref', 36)->nullable();
            }
            if (! Schema::hasColumn('v3_teacher_sections', 'subject_run_item_id')) {
                $table->char('subject_run_item_id', 36)->nullable();
                $table->foreign('subject_run_item_id')->references('id')->on('v3_subject_run_items')->nullOnDelete();
            }

            // Make the eleven fields nullable
            $table->longText('section_identity')->nullable()->change();
            $table->longText('core_understanding')->nullable()->change();
            $table->longText('visual_first_explanation')->nullable()->change();
            $table->longText('visual_scene_script')->nullable()->change();
            $table->longText('dynamic_view_blueprint')->nullable()->change();
            $table->longText('visual_learning_outcome')->nullable()->change();
            $table->longText('concept_check')->nullable()->change();
            $table->longText('conceptual_summary')->nullable()->change();
            $table->longText('colloquial_egyptian_explanation')->nullable()->change();
            $table->longText('laws_if_any')->nullable()->change();
            $table->longText('real_life_example')->nullable()->change();
        });

        // 6. Extend v3_lesson_maps
        Schema::table('v3_lesson_maps', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_lesson_maps', 'source_origin')) {
                $table->string('source_origin', 24)->default('live');
            }
            if (! Schema::hasColumn('v3_lesson_maps', 'private_evidence_ref')) {
                $table->char('private_evidence_ref', 36)->nullable();
            }
        });

        // 7. Extend v3_lesson_queries
        Schema::table('v3_lesson_queries', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_lesson_queries', 'source_origin')) {
                $table->string('source_origin', 24)->default('live');
            }
            if (! Schema::hasColumn('v3_lesson_queries', 'private_evidence_ref')) {
                $table->char('private_evidence_ref', 36)->nullable();
            }
            $table->char('request_sha256', 64)->nullable()->change();
        });

        // 8. Extend v3_prompt_work_items
        Schema::table('v3_prompt_work_items', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_prompt_work_items', 'source_origin')) {
                $table->string('source_origin', 24)->default('live');
            }
            if (! Schema::hasColumn('v3_prompt_work_items', 'private_evidence_ref')) {
                $table->char('private_evidence_ref', 36)->nullable();
            }
        });

        // 9. Extend v3_prompt_turns
        Schema::table('v3_prompt_turns', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_prompt_turns', 'source_origin')) {
                $table->string('source_origin', 24)->default('live');
            }
            if (! Schema::hasColumn('v3_prompt_turns', 'private_evidence_ref')) {
                $table->char('private_evidence_ref', 36)->nullable();
            }
            $table->string('conversation_in', 255)->nullable()->change();
        });

        // 10. Extend v3_notebook_lesson_bundles
        Schema::table('v3_notebook_lesson_bundles', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_notebook_lesson_bundles', 'source_origin')) {
                $table->string('source_origin', 24)->default('live');
            }
            if (! Schema::hasColumn('v3_notebook_lesson_bundles', 'private_evidence_ref')) {
                $table->char('private_evidence_ref', 36)->nullable();
            }
        });

        // 11. Extend v3_scene_prompt_versions
        Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_scene_prompt_versions', 'origin')) {
                $table->string('origin', 24)->default('pipeline');
            }
            if (! Schema::hasColumn('v3_scene_prompt_versions', 'private_evidence_ref')) {
                $table->char('private_evidence_ref', 36)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_section_pipeline_links');
        Schema::dropIfExists('v3_lecture_pipeline_links');
    }
};
