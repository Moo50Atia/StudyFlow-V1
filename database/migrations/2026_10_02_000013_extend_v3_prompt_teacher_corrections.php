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
        // 1. Create v3_teacher_prompt_corrections
        if (! Schema::hasTable('v3_teacher_prompt_corrections')) {
            Schema::create('v3_teacher_prompt_corrections', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('review_route_id')->unique();
                $table->uuid('case_id');
                $table->uuid('section_id');
                $table->uuid('from_prompt_version_id')->unique();
                $table->char('request_sha256', 64);
                $table->char('template_sha256', 64);
                $table->char('note_sha256', 64);
                $table->string('template_id', 64);
                $table->string('provider_id', 64);
                $table->string('provider_model', 100);
                $table->string('credential_ref', 32);
                $table->string('state', 30)->default('claimed'); // claimed|uncertain|recorded|blocked
                $table->unsignedSmallInteger('send_count')->default(0);
                $table->char('result_sha256', 64)->nullable();
                $table->string('response_path', 255)->nullable();
                $table->char('response_sha256', 64)->nullable();
                $table->string('output_path', 255)->nullable();
                $table->char('output_sha256', 64)->nullable();
                $table->string('returned_model', 100)->nullable();
                $table->string('provider_response_id', 255)->nullable();
                $table->uuid('to_prompt_version_id')->nullable();
                $table->string('error_code', 60)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('recorded_at')->nullable();
                $table->timestamps();

                $table->foreign('review_route_id')->references('id')->on('v3_review_routes')->restrictOnDelete();
                $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
                $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
                $table->foreign('from_prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->restrictOnDelete();
                $table->foreign('to_prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->restrictOnDelete();
            });
        }

        // 2. Extend v3_scene_prompt_versions with teacher_correction_id
        if (Schema::hasTable('v3_scene_prompt_versions')) {
            Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
                if (! Schema::hasColumn('v3_scene_prompt_versions', 'teacher_correction_id')) {
                    $table->uuid('teacher_correction_id')->nullable()->unique();
                    $table->foreign('teacher_correction_id')->references('id')->on('v3_teacher_prompt_corrections')->restrictOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('v3_scene_prompt_versions') && Schema::hasColumn('v3_scene_prompt_versions', 'teacher_correction_id')) {
            Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
                $table->dropForeign(['teacher_correction_id']);
                $table->dropColumn('teacher_correction_id');
            });
        }

        Schema::dropIfExists('v3_teacher_prompt_corrections');
    }
};
