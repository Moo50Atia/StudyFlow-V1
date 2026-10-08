<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewSectionState extends Model implements V3OwnedResource
{
    protected $table = 'v3_review_section_states';
    protected $primaryKey = 'section_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state_version' => 'integer',
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

    public function currentReviewItem(): BelongsTo
    {
        return $this->belongsTo(TeacherReviewItem::class, 'current_review_item_id', 'id');
    }

    public function currentApproval(): BelongsTo
    {
        return $this->belongsTo(ApprovedScene::class, 'current_approval_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
