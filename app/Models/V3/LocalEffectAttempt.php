<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalEffectAttempt extends Model
{
    use HasUuids;

    protected $table = 'v3_local_effect_attempts';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'effect_id',
        'attempt_number',
        'device_id',
        'assignment_id',
        'assignment_epoch',
        'permit_id',
        'permit_issued_at',
        'permit_expires_at',
        'worker_process_id',
        'status',
        'outcome_ref',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'assignment_epoch' => 'integer',
        'permit_issued_at' => 'datetime',
        'permit_expires_at' => 'datetime',
    ];

    public function effect(): BelongsTo
    {
        return $this->belongsTo(LocalProviderEffect::class, 'effect_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(ProcessingDevice::class, 'device_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LocalAssignment::class, 'assignment_id');
    }
}
