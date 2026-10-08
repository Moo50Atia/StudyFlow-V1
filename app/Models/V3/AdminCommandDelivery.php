<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminCommandDelivery extends Model
{
    public const ALLOWED_STATES = [
        'pending',
        'sending',
        'uncertain',
        'acknowledged',
        'not_started',
        'blocked',
        'superseded',
    ];

    protected $table = 'v3_admin_command_deliveries';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'command_id',
        'case_id',
        'workflow_id',
        'run_id',
        'state',
        'control_version',
        'attempt_count',
        'lease_until',
        'last_attempt_at',
        'acknowledged_at',
        'error_code',
    ];

    protected $casts = [
        'control_version' => 'integer',
        'attempt_count' => 'integer',
        'lease_until' => 'datetime',
        'last_attempt_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function command(): BelongsTo
    {
        return $this->belongsTo(AdminCommand::class, 'command_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id');
    }
}
