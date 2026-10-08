<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminCommand extends Model
{
    public const ALLOWED_ACTIONS = ['hold', 'resume', 'cancel', 'retry'];

    public const ALLOWED_REASONS = [
        'operator_investigation',
        'service_recovered',
        'abandoned_review',
        'technical_reconcile',
    ];

    public const ALLOWED_TARGET_KINDS = [
        'workflow_start',
        'source_proof',
        'scene_check',
        'scene_jev',
        'review_signal',
        'review_correction',
    ];

    public const ALLOWED_STATUSES = [
        'accepted',
        'dispatching',
        'uncertain',
        'applied',
        'blocked',
        'superseded',
    ];

    protected $table = 'v3_admin_commands';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'case_id',
        'actor_user_id',
        'idempotency_key',
        'request_sha256',
        'action',
        'reason_code',
        'target_kind',
        'target_ref',
        'expected_case_version',
        'expected_control_version',
        'control_version_after',
        'status',
        'error_code',
        'lease_until',
        'last_attempt_at',
        'applied_at',
        'dispatch_attempts',
    ];

    protected $casts = [
        'expected_case_version' => 'integer',
        'expected_control_version' => 'integer',
        'control_version_after' => 'integer',
        'dispatch_attempts' => 'integer',
        'lease_until' => 'datetime',
        'last_attempt_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AdminCommandDelivery::class, 'command_id');
    }
}
