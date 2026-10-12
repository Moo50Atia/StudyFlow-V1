<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineEvent extends Model
{
    protected $table = 'v3_pipeline_events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'attempt' => 'integer',
            'state_version' => 'integer',
            'counts_json' => 'array',
            'historical' => 'boolean',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SubjectRun::class, 'run_id');
    }
}
