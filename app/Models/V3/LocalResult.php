<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocalResult extends Model
{
    use HasUuids;

    protected $table = 'v3_local_results';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'task_id',
        'job_id',
        'device_id',
        'assignment_id',
        'assignment_epoch',
        'result_kind',
        'result_digest',
        'manifest_sha256',
        'receipt_payload',
    ];

    protected $casts = [
        'assignment_epoch' => 'integer',
        'receipt_payload' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(LocalTask::class, 'task_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(LocalJob::class, 'job_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(ProcessingDevice::class, 'device_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LocalAssignment::class, 'assignment_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(LocalSceneSubmission::class, 'result_id');
    }
}
