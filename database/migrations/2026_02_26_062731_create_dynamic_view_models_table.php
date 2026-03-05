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
        Schema::create('dynamic_view_models', function (Blueprint $table) {
            $table->id();

            $table->foreignId("section_id")->constrained()->cascadeOnDelete();

            $table->json("blueprint_json");
            $table->longText("html_content")->nullable();

            $table->integer("version")->default(1);
            $table->boolean("is_generated")->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_view_models');
    }
};
