<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TeacherReviewDecision extends Model implements V3OwnedResource
{
    public $timestamps = false;
    protected $table = 'v3_teacher_review_decisions';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'candidate_version' => 'integer',
            'expected_review_version' => 'integer',
            'note_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function reviewItem(): BelongsTo
    {
        return $this->belongsTo(TeacherReviewItem::class, 'review_item_id', 'id');
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

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    public function approval(): HasOne
    {
        return $this->hasOne(ApprovedScene::class, 'review_decision_id', 'id');
    }

    public function reviewRoute(): HasOne
    {
        return $this->hasOne(ReviewRoute::class, 'review_decision_id', 'id');
    }

    public function outbox(): HasOne
    {
        return $this->hasOne(ReviewOutbox::class, 'review_decision_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
