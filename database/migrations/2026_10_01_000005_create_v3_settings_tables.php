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
        Schema::create('v3_approved_templates', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('kind', 16);
            $table->longText('body');
            $table->char('checksum', 64);
            $table->timestamp('registered_at');

            $table->index(['kind', 'id']);
        });

        Schema::create('v3_settings_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('version')->unique();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->char('checksum', 64);
            $table->foreignId('published_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');

            $table->index(['published_at', 'id']);
        });

        Schema::create('v3_active_settings', function (Blueprint $table) {
            $table->string('key', 16)->primary();
            $table->foreignId('active_settings_version_id')->nullable()->constrained('v3_settings_versions')->restrictOnDelete();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamp('updated_at');
        });

        DB::table('v3_active_settings')->insert([
            'key' => 'pilot',
            'active_settings_version_id' => null,
            'revision' => 0,
            'updated_at' => now(),
        ]);

        Schema::create('v3_settings_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('settings_version_id')->unique()->constrained('v3_settings_versions')->restrictOnDelete();
            $table->foreignId('publisher_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('previous_version_id')->nullable()->constrained('v3_settings_versions')->restrictOnDelete();
            $table->json('changed_paths');
            $table->timestamp('published_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_settings_publications');
        Schema::dropIfExists('v3_active_settings');
        Schema::dropIfExists('v3_settings_versions');
        Schema::dropIfExists('v3_approved_templates');
    }
};
