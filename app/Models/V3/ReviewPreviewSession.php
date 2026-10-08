<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewPreviewSession extends Model implements V3OwnedResource
{
    protected $table = 'v3_review_preview_sessions';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_frame_no' => 'integer',
            'first_frame_at' => 'datetime',
            'last_action_at' => 'datetime',
            'closed_at' => 'datetime',
            'expires_at' => 'datetime',
            'idle_expires_at' => 'datetime',
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

    public function reviewItem(): BelongsTo
    {
        return $this->belongsTo(TeacherReviewItem::class, 'review_item_id', 'id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SceneCandidate::class, 'candidate_id', 'id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
