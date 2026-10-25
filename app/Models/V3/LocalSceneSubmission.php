<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalSceneSubmission extends Model
{
    use HasUuids;

    protected $table = 'v3_local_scene_submissions';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'result_id',
        'task_id',
        'scene_id',
        'prompt_version_id',
        'prompt_sha256',
        'package_artifact_id',
        'package_sha256',
        'provenance_mode',
        'provenance_payload',
        'candidate_id',
    ];

    protected $casts = [
        'provenance_payload' => 'array',
    ];

    public function result(): BelongsTo
    {
        return $this->belongsTo(LocalResult::class, 'result_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(LocalTask::class, 'task_id');
    }

    public function packageArtifact(): BelongsTo
    {
        return $this->belongsTo(LocalArtifact::class, 'package_artifact_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SceneCandidate::class, 'candidate_id');
    }
}
