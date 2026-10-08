<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceEffect extends Model
{
    protected $table = 'v3_source_effects';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'send_count' => 'integer',
            'started_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (SourceEffect $model) {
            if ($model->send_count > 1) {
                throw new \InvalidArgumentException("SourceEffect send_count cannot exceed 1.");
            }
        });

        static::updating(function (SourceEffect $model) {
            foreach (['id', 'case_id', 'operation', 'request_digest'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on SourceEffect is prohibited.");
                }
            }
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }
}
