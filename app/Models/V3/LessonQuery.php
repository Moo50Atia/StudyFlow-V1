<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonQuery extends Model
{
    protected $table = 'v3_lesson_queries';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_work_ordinal' => 'integer',
            'send_count' => 'integer',
            'answer_bytes' => 'integer',
            'sent_at' => 'datetime',
            'recorded_at' => 'datetime',
            'last_alerted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (LessonQuery $model) {
            foreach (['id', 'case_id', 'kind', 'target_work_ordinal'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on LessonQuery is prohibited.");
                }
            }
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function lessonMap(): BelongsTo
    {
        return $this->belongsTo(LessonMap::class, 'map_id', 'id');
    }
}
