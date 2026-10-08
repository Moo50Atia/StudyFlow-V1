<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v3_scene_repairs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('prompt_version_id')->index();
            $table->uuid('root_candidate_id')->index();
            $table->uuid('from_candidate_id')->unique();
            $table->uuid('to_candidate_id')->nullable()->unique();
            $table->uuid('authoring_request_id')->nullable()->unique();
            $table->unsignedTinyInteger('ordinal');
            $table->string('criteria_path', 255);
            $table->char('criteria_sha256', 64);
            $table->string('state', 40); // claimed, authoring, candidate_ready, uncertain, blocked
            $table->string('safe_error_code', 40)->nullable();
            $table->string('last_alert_code', 40)->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamp('candidate_ready_at')->nullable();
            $table->timestamps();

            $table->unique(['root_candidate_id', 'ordinal']);
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->onDelete('restrict');
            $table->foreign('prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
            $table->foreign('root_candidate_id')->references('id')->on('v3_scene_candidates')->onDelete('restrict');
            $table->foreign('from_candidate_id')->references('id')->on('v3_scene_candidates')->onDelete('restrict');
            $table->foreign('to_candidate_id')->references('id')->on('v3_scene_candidates')->onDelete('restrict');
            $table->foreign('authoring_request_id')->references('id')->on('v3_authoring_requests')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_scene_repairs');
    }
};
