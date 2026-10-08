<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v3_lesson_completions', function (Blueprint $table) {
            $table->unsignedInteger('cursor_version')->nullable()->after('work_ordinal');
        });

        // The cursor starts at version 1. Each work item advances it once
        // when its First/Continue response is committed and once when its
        // completion receipt is committed. Each work item after the first
        // also advances it once when Continue is claimed. A committed
        // completion at ordinal n therefore captures version 3*n.
        DB::table('v3_lesson_completions')
            ->whereNull('cursor_version')
            ->update(['cursor_version' => DB::raw('work_ordinal * 3')]);
    }

    public function down(): void
    {
        Schema::table('v3_lesson_completions', function (Blueprint $table) {
            $table->dropColumn('cursor_version');
        });
    }
};
