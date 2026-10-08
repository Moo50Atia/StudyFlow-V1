<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneRepair extends Model implements V3OwnedResource
{
    protected $table = 'v3_scene_repairs';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'last_alerted_at' => 'datetime',
            'candidate_ready_at' => 'datetime',
        ];
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TeacherSection::class, 'section_id', 'id');
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(ScenePromptVersion::class, 'prompt_version_id', 'id');
    }

    public function rootCandidate(): BelongsTo
    {
        return $this->belongsTo(SceneCandidate::class, 'root_candidate_id', 'id');
    }

    public function fromCandidate(): BelongsTo
    {
        return $this->belongsTo(SceneCandidate::class, 'from_candidate_id', 'id');
    }

    public function toCandidate(): BelongsTo
    {
        return $this->belongsTo(SceneCandidate::class, 'to_candidate_id', 'id');
    }

    public function authoringRequest(): BelongsTo
    {
        return $this->belongsTo(AuthoringRequest::class, 'authoring_request_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
