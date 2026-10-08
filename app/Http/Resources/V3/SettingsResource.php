<?php

namespace App\Http\Resources\V3;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [
                'version_id' => null,
                'active_version' => 0,
                'settings' => null,
                'checksum' => null,
                'published_at' => null,
            ];
        }

        return [
            'version_id' => $this->id,
            'active_version' => $this->version,
            'settings' => is_array($this->payload) ? $this->payload : json_decode($this->payload, true),
            'checksum' => $this->checksum,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
