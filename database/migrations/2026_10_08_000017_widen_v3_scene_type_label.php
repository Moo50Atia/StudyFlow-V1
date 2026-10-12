<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v3_scene_prompt_versions')) {
            Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
                $table->string('scene_type', 255)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v3_scene_prompt_versions')) {
            Schema::table('v3_scene_prompt_versions', function (Blueprint $table) {
                $table->string('scene_type', 32)->change();
            });
        }
    }
};
