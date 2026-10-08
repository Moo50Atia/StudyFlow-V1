<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v3_scene_candidate_states', function (Blueprint $table) {
            $table->uuid('candidate_id')->primary();
            $table->uuid('root_candidate_id')->index();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('prompt_version_id')->index();
            $table->unsignedTinyInteger('version_no')->default(1);
            $table->string('status', 40); // awaiting_check, checking, check_failed, awaiting_jev, qualified, repair_pending, repairing, superseded, blocked, unavailable, stale
            $table->uuid('check_run_id')->nullable()->index();
            $table->uuid('qualification_id')->nullable()->index();
            $table->string('safe_error_code', 40)->nullable();
            $table->string('last_alert_code', 40)->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->unsignedBigInteger('state_version')->default(1);
            $table->timestamps();

            $table->unique(['root_candidate_id', 'version_no']);
            $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->onDelete('restrict');
            $table->foreign('root_candidate_id')->references('id')->on('v3_scene_candidates')->onDelete('restrict');
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->onDelete('restrict');
            $table->foreign('prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
        });

        Schema::create('v3_scene_check_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('candidate_id')->index();
            $table->uuid('case_id')->index();
            $table->unsignedTinyInteger('attempt_no');
            $table->char('manifest_sha256', 64);
            $table->char('request_sha256', 64);
            $table->string('checker_version', 40)->default('chromium-pw-1.63.0-v1');
            $table->string('state', 40); // claimed, technical_failure, recorded
            $table->string('safe_error_code', 40)->nullable();
            $table->char('result_sha256', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['candidate_id', 'attempt_no']);
            $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->onDelete('restrict');
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
        });

        Schema::create('v3_scene_check_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('candidate_id')->unique();
            $table->uuid('winning_attempt_id')->unique();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('prompt_version_id')->index();
            $table->uuid('settings_version_id')->index();
            $table->char('manifest_sha256', 64);
            $table->char('report_sha256', 64);
            $table->char('screenshot_sha256', 64);
            $table->string('report_path', 255);
            $table->string('screenshot_path', 255);
            $table->string('checker_version', 40)->default('chromium-pw-1.63.0-v1');
            $table->string('verdict', 20); // pass, fail
            $table->json('failed_checks');
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->onDelete('restrict');
            $table->foreign('winning_attempt_id')->references('id')->on('v3_scene_check_attempts')->onDelete('restrict');
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->onDelete('restrict');
            $table->foreign('prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
            $table->foreign('settings_version_id')->references('id')->on('v3_settings_versions')->onDelete('restrict');
        });

        Schema::create('v3_scene_jev_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('candidate_id')->index();
            $table->uuid('check_run_id')->index();
            $table->uuid('case_id')->index();
            $table->unsignedTinyInteger('attempt_no');
            $table->char('request_sha256', 64);
            $table->char('question_sha256', 64);
            $table->char('evidence_sha256', 64);
            $table->string('evidence_path', 255);
            $table->string('requested_model', 100);
            $table->string('state', 40); // claimed, technical_failure, recorded
            $table->char('result_sha256', 64)->nullable();
            $table->char('answer_sha256', 64)->nullable();
            $table->string('answer_path', 255)->nullable();
            $table->string('safe_error_code', 40)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['candidate_id', 'attempt_no']);
            $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->onDelete('restrict');
            $table->foreign('check_run_id')->references('id')->on('v3_scene_check_runs')->onDelete('restrict');
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
        });

        Schema::create('v3_scene_qualifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('candidate_id')->unique();
            $table->uuid('check_run_id')->unique();
            $table->uuid('winning_jev_attempt_id')->unique();
            $table->uuid('case_id')->index();
            $table->uuid('section_id')->index();
            $table->uuid('prompt_version_id')->index();
            $table->uuid('settings_version_id')->index();
            $table->char('settings_checksum', 64);
            $table->char('manifest_sha256', 64);
            $table->char('evidence_sha256', 64);
            $table->char('request_sha256', 64);
            $table->char('question_sha256', 64);
            $table->char('answer_sha256', 64);
            $table->string('evidence_path', 255);
            $table->string('answer_path', 255);
            $table->string('rule_version', 40)->default('scene-rule-v1');
            $table->string('requested_model', 100);
            $table->string('returned_model', 100);
            $table->string('choice', 20); // pass, revise, block
            $table->decimal('choice_confidence', 20, 18);
            $table->decimal('score', 21, 18);
            $table->decimal('score_confidence', 20, 18);
            $table->decimal('noul_probability', 20, 18);
            $table->boolean('noul_yes');
            $table->unsignedTinyInteger('min_score');
            $table->unsignedTinyInteger('min_confidence');
            $table->string('decision', 20); // pass, revise, block
            $table->json('failed_checks');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->onDelete('restrict');
            $table->foreign('check_run_id')->references('id')->on('v3_scene_check_runs')->onDelete('restrict');
            $table->foreign('winning_jev_attempt_id')->references('id')->on('v3_scene_jev_attempts')->onDelete('restrict');
            $table->foreign('case_id')->references('id')->on('v3_processing_cases')->onDelete('restrict');
            $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->onDelete('restrict');
            $table->foreign('prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->onDelete('restrict');
            $table->foreign('settings_version_id')->references('id')->on('v3_settings_versions')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_scene_qualifications');
        Schema::dropIfExists('v3_scene_jev_attempts');
        Schema::dropIfExists('v3_scene_check_runs');
        Schema::dropIfExists('v3_scene_check_attempts');
        Schema::dropIfExists('v3_scene_candidate_states');
    }
};
