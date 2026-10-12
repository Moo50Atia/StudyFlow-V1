<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });
        Schema::table('teacher_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('lecture_id')->nullable()->change();
            $table->unique(['teacher_id', 'subject_id', 'lecture_id'], 'teacher_permission_scope_unique');
        });
        Schema::create('v3_lesson_publications', function (Blueprint $table) {
            $table->id();
            $table->char('work_node_id', 36)->unique();
            $table->unsignedBigInteger('lecture_id');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->foreign('work_node_id')->references('id')->on('v3_lesson_nodes')->cascadeOnDelete();
            $table->foreign('lecture_id')->references('id')->on('lectures')->cascadeOnDelete();
            $table->foreign('published_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['lecture_id', 'published_at']);
        });
        Schema::create('v3_lesson_publication_events', function (Blueprint $table) {
            $table->id();
            $table->char('work_node_id', 36);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 32);
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['work_node_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_lesson_publication_events');
        Schema::dropIfExists('v3_lesson_publications');
        Schema::table('teacher_permissions', fn (Blueprint $table) => $table->dropUnique('teacher_permission_scope_unique'));
        Schema::table('subjects', fn (Blueprint $table) => $table->dropConstrainedForeignId('owner_user_id'));
    }
};
