<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocalJob extends Model
{
    use HasUuids;

    protected $table = 'v3_local_jobs';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'run_id',
        'teacher_id',
        'execution_mode',
        'output_scope',
        'status',
        'settings_snapshot_id',
        'settings_sha256',
        'runtime_settings_artifact_id',
        'policy_budgets',
        'control_version',
    ];

    protected $casts = [
        'policy_budgets' => 'array',
        'control_version' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SubjectRun::class, 'run_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(LocalTask::class, 'job_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(LocalArtifact::class, 'job_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(LocalResult::class, 'job_id');
    }
}
