<?php

namespace App\Console\Commands\V3;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapAccess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v3:bootstrap-access';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bootstrap missing V3 access records and audit events for eligible teacher and admin accounts.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $eligibleUsers = DB::table('users')
            ->whereIn('role', ['teacher', 'admin'])
            ->get();

        $provisionedCount = 0;
        $now = now();

        foreach ($eligibleUsers as $user) {
            $exists = DB::table('v3_account_access')
                ->where('user_id', $user->id)
                ->exists();

            if (! $exists) {
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

                $provisionedCount++;
            }
        }

        $this->info("Bootstrapped {$provisionedCount} missing V3 account access records.");

        return Command::SUCCESS;
    }
}
