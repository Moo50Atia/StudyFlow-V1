<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseOperationControl extends Model
{
    public const ALLOWED_MODES = [
        'normal',
        'hold_requested',
        'held',
        'resume_requested',
        'cancel_requested',
        'cancelled',
    ];

    protected $table = 'v3_case_operation_controls';

    protected $primaryKey = 'case_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'case_id',
        'mode',
        'control_version',
        'last_command_id',
        'requested_at',
        'applied_at',
        'safe_error_code',
    ];

    protected $casts = [
        'control_version' => 'integer',
        'requested_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id');
    }
}
