<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v3_case_operation_controls', function (Blueprint $table) {
            $table->char('case_id', 36)->primary();
            $table->string('mode', 32)->default('normal');
            $table->unsignedBigInteger('control_version')->default(0);
            $table->char('last_command_id', 36)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('safe_error_code', 32)->nullable();
            $table->timestamps();

            $table->foreign('case_id')
                ->references('id')
                ->on('v3_processing_cases')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_case_operation_controls');
    }
};
