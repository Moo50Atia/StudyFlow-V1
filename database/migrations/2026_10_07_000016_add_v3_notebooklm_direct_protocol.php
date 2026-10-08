<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Framework catalog & pointer
        if (! Schema::hasTable('v3_notebook_framework_versions')) {
            Schema::create('v3_notebook_framework_versions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('profile', 32)->default('expanded-root-v1');
                $table->string('body_path', 255);
                $table->char('body_sha256', 64)->unique();
                $table->unsignedBigInteger('body_bytes');
                $table->char('first_sha256', 64);
                $table->char('second_sha256', 64);
                $table->char('third_sha256', 64);
                $table->char('response_format_sha256', 64);
                $table->unsignedBigInteger('approved_by_user_id');
                $table->timestamp('approved_at');
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('approved_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('v3_notebook_framework_pointer')) {
            Schema::create('v3_notebook_framework_pointer', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->uuid('version_id')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->foreign('version_id')
                    ->references('id')
                    ->on('v3_notebook_framework_versions')
                    ->restrictOnDelete();
            });

            DB::table('v3_notebook_framework_pointer')->insert([
                'id' => 1,
                'version_id' => null,
                'updated_at' => now(),
            ]);
        }

        // 2. Extend v3_processing_cases
        Schema::table('v3_processing_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_processing_cases', 'pipeline_version')) {
                $table->string('pipeline_version', 32)->default('legacy_staged_v1');
            }
            if (! Schema::hasColumn('v3_processing_cases', 'notebook_framework_version_id')) {
                $table->uuid('notebook_framework_version_id')->nullable();
            }
            if (! Schema::hasColumn('v3_processing_cases', 'notebook_policy')) {
                $table->json('notebook_policy')->nullable();
            }
        });

        // Add foreign key constraint for framework version on processing cases
        Schema::table('v3_processing_cases', function (Blueprint $table) {
            $table->foreign('notebook_framework_version_id')
                ->references('id')
                ->on('v3_notebook_framework_versions')
                ->restrictOnDelete();
        });

        // 3. Extend v3_knowledge_source_bindings
        Schema::table('v3_knowledge_source_bindings', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_knowledge_source_bindings', 'source_mode')) {
                $table->string('source_mode', 24)->default('extracted_text');
            }
            if (! Schema::hasColumn('v3_knowledge_source_bindings', 'document_id')) {
                $table->uuid('document_id')->nullable();
            }
            if (! Schema::hasColumn('v3_knowledge_source_bindings', 'framework_version_id')) {
                $table->uuid('framework_version_id')->nullable();
            }
            if (! Schema::hasColumn('v3_knowledge_source_bindings', 'response_format_sha256')) {
                $table->char('response_format_sha256', 64)->nullable();
            }
            if (! Schema::hasColumn('v3_knowledge_source_bindings', 'chat_configuration_state')) {
                $table->string('chat_configuration_state', 16)->default('pending');
            }

            $table->char('extracted_source_id', 36)->nullable()->change();
            $table->string('source_title', 255)->nullable()->change();
            $table->char('text_sha256', 64)->nullable()->change();
            $table->unsignedBigInteger('text_bytes')->nullable()->change();

            $table->foreign('document_id')
                ->references('id')
                ->on('v3_documents')
                ->restrictOnDelete();

            $table->foreign('framework_version_id')
                ->references('id')
                ->on('v3_notebook_framework_versions')
                ->restrictOnDelete();
        });

        // 4. v3_knowledge_source_files
        if (! Schema::hasTable('v3_knowledge_source_files')) {
            Schema::create('v3_knowledge_source_files', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->char('binding_id', 36);
                $table->string('role', 24);
                $table->string('title', 128);
                $table->char('artifact_sha256', 64);
                $table->unsignedBigInteger('artifact_bytes');
                $table->string('mime_type', 32);
                $table->string('provider_source_id', 255)->nullable();
                $table->string('state', 16)->default('pending');
                $table->string('provider_type', 32)->nullable();
                $table->timestamp('registered_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->string('error_code', 32)->nullable();
                $table->timestamps();

                $table->foreign('binding_id')
                    ->references('id')
                    ->on('v3_knowledge_source_bindings')
                    ->restrictOnDelete();

                $table->unique(['binding_id', 'role'], 'v3_ksf_binding_role_unique');
                $table->unique(['binding_id', 'provider_source_id'], 'v3_ksf_binding_provider_source_unique');
            });
        }

        // 5. Extend v3_lesson_maps
        Schema::table('v3_lesson_maps', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_lesson_maps', 'coverage_mode')) {
                $table->string('coverage_mode', 32)->default('verified_text_markers');
            }
        });

        // 6. Extend v3_lesson_queries
        Schema::table('v3_lesson_queries', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_lesson_queries', 'binding_id')) {
                $table->char('binding_id', 36)->nullable();
            }
            if (! Schema::hasColumn('v3_lesson_queries', 'expected_cursor_version')) {
                $table->unsignedBigInteger('expected_cursor_version')->nullable();
            }
            if (! Schema::hasColumn('v3_lesson_queries', 'source_scope')) {
                $table->string('source_scope', 16)->nullable();
            }
            if (! Schema::hasColumn('v3_lesson_queries', 'response_format_sha256')) {
                $table->char('response_format_sha256', 64)->nullable();
            }
            if (! Schema::hasColumn('v3_lesson_queries', 'history_length_before')) {
                $table->unsignedInteger('history_length_before')->nullable();
            }
            if (! Schema::hasColumn('v3_lesson_queries', 'history_anchor_sha256')) {
                $table->char('history_anchor_sha256', 64)->nullable();
            }

            $table->foreign('binding_id')
                ->references('id')
                ->on('v3_knowledge_source_bindings')
                ->restrictOnDelete();
        });

        // 7. v3_notebook_lesson_bundles
        if (! Schema::hasTable('v3_notebook_lesson_bundles')) {
            Schema::create('v3_notebook_lesson_bundles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->char('case_id', 36);
                $table->char('query_id', 36)->unique();
                $table->char('work_node_id', 36);
                $table->uuid('work_item_id');
                $table->char('completion_id', 36)->unique();
                $table->unsignedInteger('work_ordinal');
                $table->uuid('framework_version_id');
                $table->char('answer_sha256', 64);
                $table->char('projection_sha256', 64);
                $table->unsignedBigInteger('committed_cursor_version');
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('case_id')
                    ->references('id')
                    ->on('v3_processing_cases')
                    ->restrictOnDelete();

                $table->foreign('query_id')
                    ->references('id')
                    ->on('v3_lesson_queries')
                    ->restrictOnDelete();

                $table->foreign('work_node_id')
                    ->references('id')
                    ->on('v3_lesson_nodes')
                    ->restrictOnDelete();

                $table->foreign('work_item_id')
                    ->references('id')
                    ->on('v3_prompt_work_items')
                    ->restrictOnDelete();

                $table->foreign('completion_id')
                    ->references('id')
                    ->on('v3_lesson_completions')
                    ->restrictOnDelete();

                $table->foreign('framework_version_id')
                    ->references('id')
                    ->on('v3_notebook_framework_versions')
                    ->restrictOnDelete();

                $table->unique(['case_id', 'work_ordinal'], 'v3_nlb_case_ordinal_unique');
            });
        }

        // 8. Extend v3_scene_prompt_versions
        Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
            if (! Schema::hasColumn('v3_scene_prompt_versions', 'framework_version_id')) {
                $table->uuid('framework_version_id')->nullable();
                $table->foreign('framework_version_id')
                    ->references('id')
                    ->on('v3_notebook_framework_versions')
                    ->restrictOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
            $table->dropForeign(['framework_version_id']);
            $table->dropColumn('framework_version_id');
        });

        Schema::dropIfExists('v3_notebook_lesson_bundles');

        Schema::table('v3_lesson_queries', function (Blueprint $table) {
            $table->dropForeign(['binding_id']);
            $table->dropColumn([
                'binding_id',
                'expected_cursor_version',
                'source_scope',
                'response_format_sha256',
                'history_length_before',
                'history_anchor_sha256',
            ]);
        });

        Schema::table('v3_lesson_maps', function (Blueprint $table) {
            $table->dropColumn('coverage_mode');
        });

        Schema::dropIfExists('v3_knowledge_source_files');

        Schema::table('v3_knowledge_source_bindings', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropForeign(['framework_version_id']);
            $table->dropColumn([
                'source_mode',
                'document_id',
                'framework_version_id',
                'response_format_sha256',
                'chat_configuration_state',
            ]);
        });

        Schema::table('v3_processing_cases', function (Blueprint $table) {
            $table->dropForeign(['notebook_framework_version_id']);
            $table->dropColumn([
                'pipeline_version',
                'notebook_framework_version_id',
                'notebook_policy',
            ]);
        });

        Schema::dropIfExists('v3_notebook_framework_pointer');
        Schema::dropIfExists('v3_notebook_framework_versions');
    }
};
