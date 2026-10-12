<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineOperation extends Model
{
    protected $table = 'v3_pipeline_operations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expected_version' => 'integer',
            'state_version' => 'integer',
            'lease_expires_at' => 'datetime',
            'counts_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SubjectRun::class, 'run_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SubjectRunItem::class, 'item_id');
    }
}
