<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceExport extends Model
{
    protected $table = 'v3_evidence_exports';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [
        'id',
        'actor_user_id',
        'actor_role',
        'idempotency_key',
        'request_sha256',
        'scope',
        'case_id',
        'approval_id',
        'period_start',
        'period_end',
        'serializer_version',
        'as_of',
        'snapshot_path',
        'snapshot_sha256',
        'snapshot_bytes',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'expires_at' => 'datetime',
            'file_removed_at' => 'datetime',
            'created_at' => 'datetime',
            'snapshot_bytes' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovedScene::class, 'approval_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(EvidenceExportAudit::class, 'export_id');
    }
}
