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
        // 1. Extend v3_processing_cases for historical import provenance
        Schema::table('v3_processing_cases', function (Blueprint $table) {
            $table->char('document_id', 36)->nullable()->change();
            if (! Schema::hasColumn('v3_processing_cases', 'source_origin')) {
                $table->string('source_origin', 24)->default('live');
            }
        });

        // 2. Extend v3_lesson_maps for historical provenance without fake activations/bindings
        Schema::table('v3_lesson_maps', function (Blueprint $table) {
            $table->char('activation_id', 36)->nullable()->change();
            $table->char('binding_id', 36)->nullable()->change();
            $table->unsignedInteger('page_count')->default(0)->change();
        });

        // 2. Extend v3_teacher_sections for truthful historical records without fake queries
        Schema::table('v3_teacher_sections', function (Blueprint $table) {
            $table->char('query_id', 36)->nullable()->change();
            $table->char('answer_sha256', 64)->nullable()->change();
            $table->char('first_prompt_sha256', 64)->nullable()->change();
        });

        // 3. Extend v3_scene_prompt_versions for truthful historical prompts
        Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
            $table->uuid('work_item_id')->nullable()->change();
            $table->string('body_path', 255)->nullable()->change();
            $table->unsignedInteger('body_bytes')->nullable()->change();
            $table->char('section_fields_sha256', 64)->nullable()->change();
            $table->char('canonical_third_sha256', 64)->nullable()->change();

            if (! Schema::hasColumn('v3_scene_prompt_versions', 'prompt_text')) {
                $table->longText('prompt_text')->nullable();
            }
            if (! Schema::hasColumn('v3_scene_prompt_versions', 'scene_title')) {
                $table->string('scene_title', 255)->nullable();
            }
            if (! Schema::hasColumn('v3_scene_prompt_versions', 'status')) {
                $table->string('status', 32)->default('awaiting_manual');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Additive migration: preserve columns on down
    }
};
