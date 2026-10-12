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
        // 1. v3_manual_scene_imports
        if (! Schema::hasTable('v3_manual_scene_imports')) {
            Schema::create('v3_manual_scene_imports', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('case_id');
                $table->uuid('section_id');
                $table->uuid('prompt_version_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->char('command_id', 36)->unique();
                $table->char('package_sha256', 64);
                $table->string('private_source_ref', 255);
                $table->string('user_reported_provider', 80)->nullable();
                $table->string('user_reported_model', 120)->nullable();
                $table->uuid('candidate_id')->nullable()->unique();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
                $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
                $table->foreign('prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->restrictOnDelete();
                $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('command_id')->references('id')->on('v3_pipeline_commands')->restrictOnDelete();
                $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->nullOnDelete();

                $table->index(['case_id', 'section_id']);
                $table->index('prompt_version_id');
            });
        }

        // 2. v3_qualification_gates
        if (! Schema::hasTable('v3_qualification_gates')) {
            Schema::create('v3_qualification_gates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('case_id');
                $table->uuid('section_id');
                $table->uuid('prompt_version_id');
                $table->uuid('candidate_id')->nullable();
                $table->string('gate_stage', 20); // prompt|scene
                $table->string('basis', 24); // real_jev|owner_waived_demo
                $table->uuid('real_qualification_id')->nullable();
                $table->unsignedBigInteger('waived_by_owner_id')->nullable();
                $table->string('policy_version', 40);
                $table->char('policy_sha256', 64);
                $table->char('prompt_sha256', 64);
                $table->char('candidate_manifest_sha256', 64)->nullable();
                $table->text('missing_fields_json');
                $table->char('acknowledgment_sha256', 64)->nullable();
                $table->char('command_id', 36);
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('case_id')->references('id')->on('v3_processing_cases')->restrictOnDelete();
                $table->foreign('section_id')->references('id')->on('v3_teacher_sections')->restrictOnDelete();
                $table->foreign('prompt_version_id')->references('id')->on('v3_scene_prompt_versions')->restrictOnDelete();
                $table->foreign('candidate_id')->references('id')->on('v3_scene_candidates')->nullOnDelete();
                $table->foreign('waived_by_owner_id')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('command_id')->references('id')->on('v3_pipeline_commands')->restrictOnDelete();

                $table->unique(['command_id', 'gate_stage', 'candidate_id'], 'v3_qual_gates_cmd_stage_cand_unique');
                $table->index(['case_id', 'gate_stage']);
                $table->index(['section_id', 'gate_stage']);
                $table->index('prompt_version_id');
            });
        }

        // 3. Extend v3_authoring_requests
        Schema::table('v3_authoring_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_authoring_requests', 'eligibility_basis')) {
                $table->string('eligibility_basis', 24)->default('real_jev');
            }
            if (! Schema::hasColumn('v3_authoring_requests', 'prompt_gate_id')) {
                $table->uuid('prompt_gate_id')->nullable();
                $table->foreign('prompt_gate_id')->references('id')->on('v3_qualification_gates')->nullOnDelete();
            }
            $table->uuid('qualification_id')->nullable()->change();
        });

        // 4. Extend v3_scene_candidates
        Schema::table('v3_scene_candidates', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_scene_candidates', 'origin')) {
                $table->string('origin', 24)->default('provider_generated');
            }
            if (! Schema::hasColumn('v3_scene_candidates', 'package_profile')) {
                $table->string('package_profile', 32)->default('legacy_v1');
            }
            if (! Schema::hasColumn('v3_scene_candidates', 'manual_import_id')) {
                $table->uuid('manual_import_id')->nullable()->unique();
                $table->foreign('manual_import_id')->references('id')->on('v3_manual_scene_imports')->nullOnDelete();
            }
            if (! Schema::hasColumn('v3_scene_candidates', 'prompt_gate_id')) {
                $table->uuid('prompt_gate_id')->nullable();
                $table->foreign('prompt_gate_id')->references('id')->on('v3_qualification_gates')->nullOnDelete();
            }
            $table->uuid('request_id')->nullable()->change();
            $table->uuid('attempt_id')->nullable()->change();
            $table->uuid('qualification_id')->nullable()->change();
        });

        // 5. Extend v3_teacher_review_items
        Schema::table('v3_teacher_review_items', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_teacher_review_items', 'eligibility_basis')) {
                $table->string('eligibility_basis', 24)->default('real_jev');
            }
            if (! Schema::hasColumn('v3_teacher_review_items', 'qualification_gate_id')) {
                $table->uuid('qualification_gate_id')->nullable();
                $table->foreign('qualification_gate_id')->references('id')->on('v3_qualification_gates')->nullOnDelete();
            }
            $table->uuid('qualification_id')->nullable()->change();
        });

        // 6. Extend v3_teacher_review_decisions
        Schema::table('v3_teacher_review_decisions', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_teacher_review_decisions', 'eligibility_basis')) {
                $table->string('eligibility_basis', 24)->default('real_jev');
            }
            if (! Schema::hasColumn('v3_teacher_review_decisions', 'qualification_gate_id')) {
                $table->uuid('qualification_gate_id')->nullable();
                $table->foreign('qualification_gate_id')->references('id')->on('v3_qualification_gates')->nullOnDelete();
            }
            $table->uuid('qualification_id')->nullable()->change();
        });

        // 7. Extend v3_approved_scenes
        Schema::table('v3_approved_scenes', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_approved_scenes', 'eligibility_basis')) {
                $table->string('eligibility_basis', 24)->default('real_jev');
            }
            if (! Schema::hasColumn('v3_approved_scenes', 'qualification_gate_id')) {
                $table->uuid('qualification_gate_id')->nullable();
                $table->foreign('qualification_gate_id')->references('id')->on('v3_qualification_gates')->nullOnDelete();
            }
            $table->uuid('qualification_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v3_approved_scenes', function (Blueprint $table) {
            if (Schema::hasColumn('v3_approved_scenes', 'qualification_gate_id')) {
                $table->dropForeign(['qualification_gate_id']);
                $table->dropColumn('qualification_gate_id');
            }
            if (Schema::hasColumn('v3_approved_scenes', 'eligibility_basis')) {
                $table->dropColumn('eligibility_basis');
            }
        });

        Schema::table('v3_teacher_review_decisions', function (Blueprint $table) {
            if (Schema::hasColumn('v3_teacher_review_decisions', 'qualification_gate_id')) {
                $table->dropForeign(['qualification_gate_id']);
                $table->dropColumn('qualification_gate_id');
            }
            if (Schema::hasColumn('v3_teacher_review_decisions', 'eligibility_basis')) {
                $table->dropColumn('eligibility_basis');
            }
        });

        Schema::table('v3_teacher_review_items', function (Blueprint $table) {
            if (Schema::hasColumn('v3_teacher_review_items', 'qualification_gate_id')) {
                $table->dropForeign(['qualification_gate_id']);
                $table->dropColumn('qualification_gate_id');
            }
            if (Schema::hasColumn('v3_teacher_review_items', 'eligibility_basis')) {
                $table->dropColumn('eligibility_basis');
            }
        });

        Schema::table('v3_scene_candidates', function (Blueprint $table) {
            if (Schema::hasColumn('v3_scene_candidates', 'manual_import_id')) {
                $table->dropForeign(['manual_import_id']);
                $table->dropColumn('manual_import_id');
            }
            if (Schema::hasColumn('v3_scene_candidates', 'prompt_gate_id')) {
                $table->dropForeign(['prompt_gate_id']);
                $table->dropColumn('prompt_gate_id');
            }
            if (Schema::hasColumn('v3_scene_candidates', 'origin')) {
                $table->dropColumn('origin');
            }
            if (Schema::hasColumn('v3_scene_candidates', 'package_profile')) {
                $table->dropColumn('package_profile');
            }
        });

        Schema::table('v3_authoring_requests', function (Blueprint $table) {
            if (Schema::hasColumn('v3_authoring_requests', 'prompt_gate_id')) {
                $table->dropForeign(['prompt_gate_id']);
                $table->dropColumn('prompt_gate_id');
            }
            if (Schema::hasColumn('v3_authoring_requests', 'eligibility_basis')) {
                $table->dropColumn('eligibility_basis');
            }
        });

        Schema::dropIfExists('v3_qualification_gates');
        Schema::dropIfExists('v3_manual_scene_imports');
    }
};
