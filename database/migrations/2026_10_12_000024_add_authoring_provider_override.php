<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v3_authoring_requests', function (Blueprint $table) {
            $table->json('provider_override_json')->nullable()->after('settings_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('v3_authoring_requests', function (Blueprint $table) {
            $table->dropColumn('provider_override_json');
        });
    }
};
