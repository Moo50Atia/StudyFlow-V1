<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\Concerns\HasV3Owner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadAttempt extends Model implements V3OwnedResource
{
    use HasV3Owner;

    protected $table = 'v3_upload_attempts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [
        'owner_user_id',
        'state',
        'staging_path',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'scan_attempts' => 'integer',
            'technical_failures' => 'integer',
            'duplicate' => 'boolean',
            'next_retry_at' => 'datetime',
            'lease_until' => 'datetime',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function v3OwnerId(): int
    {
        return (int) $this->owner_user_id;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function uploadSettingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'upload_settings_version_id');
    }
}
