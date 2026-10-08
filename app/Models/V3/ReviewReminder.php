<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewReminder extends Model
{
    protected $table = 'v3_review_reminders';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'case_id',
        'review_item_id',
        'actor_user_id',
        'idempotency_key',
        'request_sha256',
        'state',
        'posted_at',
        'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id');
    }

    public function reviewItem(): BelongsTo
    {
        return $this->belongsTo(TeacherReviewItem::class, 'review_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
