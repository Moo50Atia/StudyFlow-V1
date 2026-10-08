<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewRoute extends Model implements V3OwnedResource
{
    protected $table = 'v3_review_routes';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TeacherSection::class, 'section_id', 'id');
    }

    public function reviewDecision(): BelongsTo
    {
        return $this->belongsTo(TeacherReviewDecision::class, 'review_decision_id', 'id');
    }

    public function postapprovalCorrection(): BelongsTo
    {
        return $this->belongsTo(PostapprovalCorrection::class, 'postapproval_correction_id', 'id');
    }

    public function teacherPromptCorrection(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TeacherPromptCorrection::class, 'review_route_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
