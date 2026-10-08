<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneCheckAttempt extends Model implements V3OwnedResource
{
    protected $table = 'v3_scene_check_attempts';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'started_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SceneCandidate::class, 'candidate_id', 'id');
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
