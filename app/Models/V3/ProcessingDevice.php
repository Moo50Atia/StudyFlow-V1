<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingDevice extends Model
{
    use HasUuids;

    protected $table = 'v3_processing_devices';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'tenant_id',
        'platform_owner_id',
        'token_hash',
        'scopes',
        'capabilities',
        'status',
        'expires_at',
        'last_seen_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'scopes' => 'array',
        'capabilities' => 'array',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function platformOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'platform_owner_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LocalAssignment::class, 'device_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
