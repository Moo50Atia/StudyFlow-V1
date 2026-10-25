<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevicePairing extends Model
{
    use HasUuids;

    protected $table = 'v3_device_pairings';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'pairing_code_hash',
        'platform_owner_id',
        'device_name',
        'tenant_id',
        'scopes',
        'capabilities',
        'expires_at',
        'redeemed_at',
        'redeemed_device_id',
    ];

    protected $casts = [
        'scopes' => 'array',
        'capabilities' => 'array',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public function platformOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'platform_owner_id');
    }

    public function redeemedDevice(): BelongsTo
    {
        return $this->belongsTo(ProcessingDevice::class, 'redeemed_device_id');
    }

    public function isRedeemable(): bool
    {
        return $this->redeemed_at === null && $this->expires_at->isFuture();
    }
}
