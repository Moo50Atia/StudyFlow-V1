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
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecture_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('quick_summary')->nullable();
            $table->text('core_concept')->nullable();
            $table->text('egyptian_explain')->nullable();
            $table->text('formulas')->nullable();
            $table->text('real_life')->nullable();
            $table->string('dynamic_view_link')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
