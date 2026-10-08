<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewOutbox extends Model implements V3OwnedResource
{
    protected $table = 'v3_review_outbox';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'lease_until' => 'datetime',
            'last_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function reviewDecision(): BelongsTo
    {
        return $this->belongsTo(TeacherReviewDecision::class, 'review_decision_id', 'id');
    }

    public function postapprovalCorrection(): BelongsTo
    {
        return $this->belongsTo(PostapprovalCorrection::class, 'postapproval_correction_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->review_decision_id && $this->reviewDecision) {
            return $this->reviewDecision->v3OwnerId();
        }

        if ($this->postapproval_correction_id && $this->postapprovalCorrection) {
            return $this->postapprovalCorrection->v3OwnerId();
        }

        if ($this->review_decision_id) {
            $dec = TeacherReviewDecision::find($this->review_decision_id);
            return $dec ? $dec->v3OwnerId() : 0;
        }

        if ($this->postapproval_correction_id) {
            $corr = PostapprovalCorrection::find($this->postapproval_correction_id);
            return $corr ? $corr->v3OwnerId() : 0;
        }

        return 0;
    }
}
