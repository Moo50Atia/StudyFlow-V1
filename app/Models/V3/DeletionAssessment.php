<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeletionAssessment extends Model
{
    public const ALLOWED_STATUSES = [
        'deferred_to_015',
        'ineligible',
    ];

    public const ALLOWED_BLOCKERS = [
        'case_active',
        'workflow_open',
        'review_pending',
        'effect_uncertain',
    ];

    public const POLICY_VERSION = 'pilot-retain-v1';
    public const REASON_CODE = 'pilot_cleanup_request';

    protected $table = 'v3_deletion_assessments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'case_id',
        'actor_user_id',
        'idempotency_key',
        'request_sha256',
        'reason_code',
        'policy_version',
        'status',
        'safe_blocker',
        'case_state_version',
        'control_version',
        'assessed_at',
    ];

    protected $casts = [
        'case_state_version' => 'integer',
        'control_version' => 'integer',
        'assessed_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
