<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MapActivation extends Model
{
    protected $table = 'v3_map_activations';
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

        static::updating(function (MapActivation $model) {
            foreach (['id', 'case_id', 'workflow_id', 'binding_id'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on MapActivation is prohibited.");
                }
            }
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function case(): BelongsTo
    {
        return $this->processingCase();
    }

    public function knowledgeSourceBinding(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSourceBinding::class, 'binding_id', 'id');
    }

    public function binding(): BelongsTo
    {
        return $this->knowledgeSourceBinding();
    }

    public function lessonMap(): HasOne
    {
        return $this->hasOne(LessonMap::class, 'activation_id', 'id');
    }
}
