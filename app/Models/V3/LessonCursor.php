<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonCursor extends Model
{
    protected $table = 'v3_lesson_cursors';
    protected $primaryKey = 'case_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'current_work_ordinal' => 'integer',
            'version' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (LessonCursor $model) {
            foreach (['case_id', 'map_id'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on LessonCursor is prohibited.");
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

    public function lastQuery(): BelongsTo
    {
        return $this->belongsTo(LessonQuery::class, 'last_query_id', 'id');
    }

    public function lastCompletion(): BelongsTo
    {
        return $this->belongsTo(LessonCompletion::class, 'last_completion_id', 'id');
    }
}
