<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ApprovedScene extends Model implements V3OwnedResource
{
    public $timestamps = false;
    protected $table = 'v3_approved_scenes';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'candidate_version' => 'integer',
            'approved_at' => 'datetime',
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

    public function decision(): BelongsTo
    {
        return $this->belongsTo(TeacherReviewDecision::class, 'review_decision_id', 'id');
    }

    public function postapprovalCorrection(): HasOne
    {
        return $this->hasOne(PostapprovalCorrection::class, 'approval_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
