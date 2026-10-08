<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. v3_authoring_requests
        Schema::create('v3_authoring_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->uuid('section_id');
            $table->uuid('prompt_version_id');
            $table->uuid('qualification_id');
            $table->char('prompt_body_sha256', 64);
            $table->char('settings_checksum', 64);
            $table->char('template_sha256', 64);
            $table->unsignedBigInteger('settings_version_id');
            $table->string('template_id', 64);
            $table->string('origin', 16)->default('initial'); // 'initial' in 011
            $table->string('state', 24)->default('pending'); // pending|authoring|candidate_ready|blocked|uncertain|stale
            $table->unsignedBigInteger('state_version')->default(1);
            $table->unsignedTinyInteger('attempt_limit');
            $table->string('safe_error_code', 32)->nullable();
            $table->string('last_alert_code', 32)->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('section_id')
                ->references('id')
                ->on('v3_teacher_sections')
                ->restrictOnDelete();

            $table->foreign('prompt_version_id')
                ->references('id')
                ->on('v3_scene_prompt_versions')
                ->restrictOnDelete();

            $table->foreign('qualification_id')
                ->references('id')
                ->on('v3_prompt_qualifications')
                ->restrictOnDelete();

            $table->foreign('settings_version_id')
                ->references('id')
                ->on('v3_settings_versions')
                ->restrictOnDelete();

            $table->unique(['case_id', 'prompt_version_id', 'origin'], 'v3_auth_req_case_prompt_origin_unique');
            $table->index(['case_id', 'state']);
            $table->index(['section_id', 'state']);
        });

        // 2. v3_coding_attempts
        Schema::create('v3_coding_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->uuid('case_id');
            $table->unsignedTinyInteger('ordinal');
            $table->string('provider_id', 32); // gemini_api|glm_api|deepseek_api
            $table->string('requested_model', 100);
            $table->string('credential_ref', 32);
            $table->string('state', 24)->default('send_reserved'); // send_reserved|known_failure|candidate_ready|uncertain
            $table->uuid('reserved_candidate_id')->nullable();
            $table->char('result_sha256', 64)->nullable();
            $table->char('response_sha256', 64)->nullable();
            $table->string('response_path', 255)->nullable();
            $table->string('returned_model', 100)->nullable();
            $table->string('provider_response_id', 255)->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cost_minor_units')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('send_reserved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('request_id')
                ->references('id')
                ->on('v3_authoring_requests')
                ->restrictOnDelete();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->unique(['request_id', 'ordinal'], 'v3_coding_attempts_req_ordinal_unique');
            $table->unique(['request_id', 'provider_id'], 'v3_coding_attempts_req_provider_unique');
            $table->unique(['request_id', 'reserved_candidate_id'], 'v3_coding_attempts_req_candidate_unique');
            $table->index(['state', 'send_reserved_at']);
        });

        // 3. v3_scene_candidates
        Schema::create('v3_scene_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->uuid('attempt_id')->unique();
            $table->uuid('case_id');
            $table->uuid('section_id');
            $table->uuid('prompt_version_id');
            $table->uuid('qualification_id');
            $table->unsignedBigInteger('settings_version_id');
            $table->string('manifest_path', 255);
            $table->char('manifest_sha256', 64);
            $table->string('entrypoint', 32)->default('index.html');
            $table->unsignedTinyInteger('file_count');
            $table->unsignedInteger('total_bytes');
            $table->timestamp('collected_at')->useCurrent();

            $table->foreign('request_id')
                ->references('id')
                ->on('v3_authoring_requests')
                ->restrictOnDelete();

            $table->foreign('attempt_id')
                ->references('id')
                ->on('v3_coding_attempts')
                ->restrictOnDelete();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();

            $table->foreign('section_id')
                ->references('id')
                ->on('v3_teacher_sections')
                ->restrictOnDelete();

            $table->foreign('prompt_version_id')
                ->references('id')
                ->on('v3_scene_prompt_versions')
                ->restrictOnDelete();

            $table->foreign('qualification_id')
                ->references('id')
                ->on('v3_prompt_qualifications')
                ->restrictOnDelete();

            $table->foreign('settings_version_id')
                ->references('id')
                ->on('v3_settings_versions')
                ->restrictOnDelete();

            $table->index(['case_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_scene_candidates');
        Schema::dropIfExists('v3_coding_attempts');
        Schema::dropIfExists('v3_authoring_requests');
    }
};
