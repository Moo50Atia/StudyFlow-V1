<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminOperationAudit extends Model
{
    public const ALLOWED_EVENTS = [
        'command_requested',
        'command_applied',
        'command_superseded',
        'reminder_posted',
        'assessment_recorded',
        'operation_denied',
    ];

    public const ALLOWED_ACTIONS = [
        'hold',
        'resume',
        'cancel',
        'retry',
        'remind',
        'assess_deletion',
    ];

    public const ALLOWED_DENIALS = [
        'resource_unavailable',
        'operation_conflict',
        'contract_invalid',
    ];

    protected $table = 'v3_admin_operation_audits';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'case_id',
        'actor_user_id',
        'source_kind',
        'source_ref',
        'event',
        'action_code',
        'reason_code',
        'safe_denial_code',
        'old_control_mode',
        'new_control_mode',
        'old_control_version',
        'new_control_version',
        'occurred_at',
    ];

    protected $casts = [
        'old_control_version' => 'integer',
        'new_control_version' => 'integer',
        'occurred_at' => 'datetime',
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
