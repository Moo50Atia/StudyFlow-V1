<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocalAssignment extends Model
{
    use HasUuids;

    protected $table = 'v3_local_assignments';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'task_id',
        'device_id',
        'claim_id',
        'assignment_epoch',
        'lease_expires_at',
        'heartbeat_at',
        'status',
        'control_version',
    ];

    protected $casts = [
        'assignment_epoch' => 'integer',
        'control_version' => 'integer',
        'lease_expires_at' => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(LocalTask::class, 'task_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(ProcessingDevice::class, 'device_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LocalEffectAttempt::class, 'assignment_id');
    }

    public function isLeaseValid(): bool
    {
        return $this->status === 'active' && $this->lease_expires_at->isFuture();
    }
}
