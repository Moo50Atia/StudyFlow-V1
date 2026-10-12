<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TeacherReviewItem extends Model implements V3OwnedResource
{
    protected $table = 'v3_teacher_review_items';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'candidate_version' => 'integer',
            'state_version' => 'integer',
            'ready_at' => 'datetime',
            'dormant_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'decided_at' => 'datetime',
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

    public function teacherSection(): BelongsTo
    {
        return $this->section();
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SceneCandidate::class, 'candidate_id', 'id');
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(SceneQualification::class, 'qualification_id', 'id');
    }

    public function qualificationGate(): BelongsTo
    {
        return $this->belongsTo(QualificationGate::class, 'qualification_gate_id', 'id');
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(ScenePromptVersion::class, 'prompt_version_id', 'id');
    }

    public function scenePromptVersion(): BelongsTo
    {
        return $this->promptVersion();
    }

    public function decision(): HasOne
    {
        return $this->hasOne(TeacherReviewDecision::class, 'review_item_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
