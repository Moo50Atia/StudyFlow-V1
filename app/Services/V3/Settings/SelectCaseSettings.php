<?php

namespace App\Services\V3\Settings;

use App\Models\V3\SettingsVersion;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class SelectCaseSettings
{
    public function selectForNewCase(?Connection $connection = null): SettingsVersion
    {
        $conn = $connection ?? DB::connection();

        if ($conn->transactionLevel() === 0) {
            throw new \LogicException('SelectCaseSettings requires an active database transaction.');
        }

        // First-write the pointer revision on the same connection
        $affected = $conn->table('v3_active_settings')
            ->where('key', 'pilot')
            ->increment('revision');

        if ($affected !== 1) {
            throw new SettingsUnavailable('V3 settings pointer is unavailable.');
        }

        $activeRow = $conn->table('v3_active_settings')
            ->where('key', 'pilot')
            ->first();

        if (! $activeRow || $activeRow->active_settings_version_id === null) {
            throw new SettingsUnavailable('No active settings version is published.');
        }

        $versionRow = $conn->table('v3_settings_versions')
            ->where('id', $activeRow->active_settings_version_id)
            ->first();

        if (! $versionRow) {
            throw new SettingsUnavailable('Active settings version row is missing.');
        }

        // Verify checksum of captured row
        $payload = is_array($versionRow->payload) ? $versionRow->payload : json_decode($versionRow->payload, true);
        $canonical = CanonicalSettings::canonicalize($payload);
        $encoded = CanonicalSettings::encode($canonical);
        $expectedChecksum = hash('sha256', $encoded);

        if ($versionRow->checksum !== $expectedChecksum) {
            throw new SettingsUnavailable('Captured settings version is corrupt.');
        }

        return SettingsVersion::findOrFail($versionRow->id);
    }
}
