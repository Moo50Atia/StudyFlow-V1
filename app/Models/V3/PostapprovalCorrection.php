<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PostapprovalCorrection extends Model implements V3OwnedResource
{
    public $timestamps = false;
    protected $table = 'v3_postapproval_corrections';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'note_bytes' => 'integer',
            'created_at' => 'datetime',
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

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovedScene::class, 'approval_id', 'id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    public function reviewRoute(): HasOne
    {
        return $this->hasOne(ReviewRoute::class, 'postapproval_correction_id', 'id');
    }

    public function outbox(): HasOne
    {
        return $this->hasOne(ReviewOutbox::class, 'postapproval_correction_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
