<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v3_scene_repairs', function (Blueprint $table) {
            $table->uuid('teacher_review_route_id')->nullable()->unique()->after('authoring_request_id');
            $table->foreign('teacher_review_route_id')
                ->references('id')
                ->on('v3_review_routes')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('v3_scene_repairs', function (Blueprint $table) {
            $table->dropForeign(['teacher_review_route_id']);
            $table->dropColumn('teacher_review_route_id');
        });
    }
};
