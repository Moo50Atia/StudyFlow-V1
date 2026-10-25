<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalArtifact extends Model
{
    use HasUuids;

    protected $table = 'v3_local_artifacts';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'job_id',
        'task_id',
        'purpose',
        'digest_sha256',
        'size_bytes',
        'media_type',
        'logical_name',
        'storage_path',
        'is_quarantine',
        'is_committed',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'is_quarantine' => 'boolean',
        'is_committed' => 'boolean',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(LocalJob::class, 'job_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(LocalTask::class, 'task_id');
    }
}
