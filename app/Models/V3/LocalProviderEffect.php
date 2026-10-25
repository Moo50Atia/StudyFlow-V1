<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocalProviderEffect extends Model
{
    use HasUuids;

    protected $table = 'v3_local_provider_effects';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'task_id',
        'job_id',
        'logical_effect_key',
        'operation_kind',
        'pdf_ordinal',
        'lesson_ordinal',
        'scene_ordinal',
        'request_digest',
        'status',
        'current_attempt_id',
        'attempt_count',
        'outcome_ref',
    ];

    protected $casts = [
        'pdf_ordinal' => 'integer',
        'lesson_ordinal' => 'integer',
        'scene_ordinal' => 'integer',
        'attempt_count' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(LocalTask::class, 'task_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(LocalJob::class, 'job_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LocalEffectAttempt::class, 'effect_id');
    }
}
