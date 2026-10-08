<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SourceActivation extends Model
{
    protected $table = 'v3_source_activations';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'signal_attempts' => 'integer',
            'lease_until' => 'datetime',
            'signal_attempted_at' => 'datetime',
            'signal_accepted_at' => 'datetime',
            'next_check_at' => 'datetime',
            'last_alerted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (SourceActivation $model) {
            foreach (['id', 'case_id', 'workflow_id'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on SourceActivation is prohibited.");
                }
            }
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function extractedSource(): HasOne
    {
        return $this->hasOne(ExtractedSource::class, 'activation_id', 'id');
    }
}
