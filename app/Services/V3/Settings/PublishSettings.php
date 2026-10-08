<?php

namespace App\Services\V3\Settings;

use App\Models\V3\SettingsVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublishSettings
{
    public static bool $forceFailure = false;

    public function publish(array $payload, int $expectedActiveVersion, int $actorUserId): SettingsVersion
    {
        return DB::transaction(function () use ($payload, $expectedActiveVersion, $actorUserId) {
            // 1. Update v3_access_control_locks
            $lockAffected = DB::table('v3_access_control_locks')
                ->where('id', 1)
                ->increment('revision');

            if ($lockAffected !== 1) {
                throw new SettingsUnavailable('V3 access control lock is unavailable.');
            }

            // 2. Freshly read users.role and v3_account_access.is_active under the lock
            $user = DB::table('users')->where('id', $actorUserId)->first();
            $access = DB::table('v3_account_access')->where('user_id', $actorUserId)->first();

            if (! $user || $user->role !== 'admin' || ! $access || ! (bool) $access->is_active) {
                throw new HttpException(403, 'V3 access is unavailable.');
            }

            // 3. Update v3_active_settings.revision = revision + 1 WHERE key = 'pilot'
            $pointerAffected = DB::table('v3_active_settings')
                ->where('key', 'pilot')
                ->increment('revision');

            if ($pointerAffected !== 1) {
                throw new SettingsUnavailable('V3 active settings pointer is unavailable.');
            }

            // 4. Validate and canonicalize payload
            $validated = SettingsSchema::validate($payload);
            $canonical = CanonicalSettings::canonicalize($validated);
            $encoded = CanonicalSettings::encode($canonical);
            $checksum = hash('sha256', $encoded);

            // 5. Read current active version
            $activeRow = DB::table('v3_active_settings')
                ->where('key', 'pilot')
                ->first();

            if (! $activeRow) {
                throw new SettingsUnavailable('V3 active settings pointer row is missing.');
            }

            $currentActiveVersionId = $activeRow->active_settings_version_id;
            $currentActiveVersionNum = 0;
            $previousPayload = null;

            if ($currentActiveVersionId !== null) {
                $prevVersionRow = DB::table('v3_settings_versions')
                    ->where('id', $currentActiveVersionId)
                    ->first();

                if (! $prevVersionRow) {
                    throw new SettingsUnavailable('Active settings version row is missing.');
                }

                $currentActiveVersionNum = (int) $prevVersionRow->version;
                $previousPayload = json_decode($prevVersionRow->payload, true);
            }

            // 6. Compare expected active version
            if ($expectedActiveVersion !== $currentActiveVersionNum) {
                throw new SettingsConflict('Settings changed. Reload and retry.');
            }

            // 7. Calculate changed leaf paths
            $changedPaths = CanonicalSettings::diffPaths($canonical, $previousPayload);

            $newVersionNum = $currentActiveVersionNum + 1;
            $now = now();

            // 8. Insert new SettingsVersion
            $newVersionId = DB::table('v3_settings_versions')->insertGetId([
                'version' => $newVersionNum,
                'schema_version' => 1,
                'payload' => $encoded,
                'checksum' => $checksum,
                'published_by_user_id' => $actorUserId,
                'published_at' => $now,
            ]);

            // 9. Insert safe publication event
            DB::table('v3_settings_publications')->insert([
                'id' => (string) Str::uuid(),
                'settings_version_id' => $newVersionId,
                'publisher_user_id' => $actorUserId,
                'previous_version_id' => $currentActiveVersionId,
                'changed_paths' => json_encode($changedPaths),
                'published_at' => $now,
            ]);

            // 10. Check forceFailure for tests
            if (static::$forceFailure) {
                throw new \RuntimeException('Injected storage failure');
            }

            // 11. Update active pointer
            DB::table('v3_active_settings')
                ->where('key', 'pilot')
                ->update([
                    'active_settings_version_id' => $newVersionId,
                    'updated_at' => $now,
                ]);

            return SettingsVersion::findOrFail($newVersionId);
        });
    }
}
