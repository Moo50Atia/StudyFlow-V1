<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v3_subject_runs', fn (Blueprint $table) => $table->json('pdf_preparation')->nullable());
        Schema::table('v3_subject_run_items', fn (Blueprint $table) => $table->json('pdf_lineage')->nullable());
    }

    public function down(): void
    {
        Schema::table('v3_subject_run_items', fn (Blueprint $table) => $table->dropColumn('pdf_lineage'));
        Schema::table('v3_subject_runs', fn (Blueprint $table) => $table->dropColumn('pdf_preparation'));
    }
};
