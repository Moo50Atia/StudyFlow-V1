<?php

namespace App\Services\V3\Settings;

use App\Models\V3\SettingsVersion;

class SettingsVersionRepository
{
    public function getCaptured(int $id): SettingsVersion
    {
        $version = SettingsVersion::find($id);
        if (! $version) {
            throw new SettingsUnavailable("Settings version {$id} not found.");
        }

        $payload = $version->payload;
        $canonical = CanonicalSettings::canonicalize(is_array($payload) ? $payload : json_decode($payload, true));
        $encoded = CanonicalSettings::encode($canonical);
        $actualChecksum = hash('sha256', $encoded);

        if ($version->checksum !== $actualChecksum) {
            throw new SettingsUnavailable("Settings version {$id} has corrupted checksum.");
        }

        return $version;
    }
}
