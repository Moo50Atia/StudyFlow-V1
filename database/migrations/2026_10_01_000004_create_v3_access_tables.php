<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('v3_access_control_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('revision')->default(0);
        });

        DB::table('v3_access_control_locks')->insert([
            'id' => 1,
            'revision' => 0,
        ]);

        Schema::create('v3_account_access', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('provisioned_at');
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('v3_access_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->string('event_type', 24);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('route_name', 100)->nullable();
            $table->string('reason_code', 40);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->timestamp('occurred_at');

            $table->unique(['event_type', 'request_id']);
            $table->index(['target_user_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
        });

        // One-time backfill for existing teacher/admin accounts
        $existingEligibleUsers = DB::table('users')
            ->whereIn('role', ['teacher', 'admin'])
            ->get();

        $now = now();
        foreach ($existingEligibleUsers as $user) {
            DB::table('v3_account_access')->insert([
                'user_id' => $user->id,
                'is_active' => true,
                'version' => 1,
                'provisioned_at' => $now,
                'disabled_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('v3_access_events')->insert([
                'id' => (string) Str::uuid(),
                'request_id' => (string) Str::uuid(),
                'event_type' => 'bootstrap',
                'actor_user_id' => null,
                'target_user_id' => $user->id,
                'route_name' => null,
                'reason_code' => 'bootstrap',
                'before_state' => null,
                'after_state' => json_encode([
                    'role' => $user->role,
                    'is_active' => true,
                    'version' => 1,
                ]),
                'occurred_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_access_events');
        Schema::dropIfExists('v3_account_access');
        Schema::dropIfExists('v3_access_control_locks');
    }
};
