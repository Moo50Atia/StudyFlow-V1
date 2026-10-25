<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocalTask extends Model
{
    use HasUuids;

    protected $table = 'v3_local_tasks';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'job_id',
        'task_kind',
        'status',
        'input_manifest_id',
        'input_manifest_sha256',
        'control_version',
        'active_assignment_id',
        'active_epoch',
    ];

    protected $casts = [
        'control_version' => 'integer',
        'active_epoch' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(LocalJob::class, 'job_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LocalAssignment::class, 'task_id');
    }

    public function activeAssignment(): BelongsTo
    {
        return $this->belongsTo(LocalAssignment::class, 'active_assignment_id');
    }

    public function effects(): HasMany
    {
        return $this->hasMany(LocalProviderEffect::class, 'task_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(LocalResult::class, 'task_id');
    }
}
