<?php

namespace App\Services\V3\Settings;

use App\Models\V3\ApprovedTemplate;

class ApprovedTemplateRepository
{
    public function getCaptured(string $id, string $checksum): string
    {
        $template = ApprovedTemplate::find($id);
        if (! $template) {
            throw new SettingsUnavailable("Approved template '{$id}' not found.");
        }

        if ($template->checksum !== $checksum) {
            throw new SettingsUnavailable("Template checksum mismatch for '{$id}'.");
        }

        $actualChecksum = hash('sha256', $template->body);
        if ($actualChecksum !== $checksum) {
            throw new SettingsUnavailable("Template content corrupted for '{$id}'.");
        }

        return $template->body;
    }
}
