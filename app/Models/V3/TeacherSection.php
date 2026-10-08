<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherSection extends Model
{
    protected $table = 'v3_teacher_sections';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ordinal_in_work' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (TeacherSection $model) {
            foreach (['id', 'case_id', 'map_id', 'work_node_id'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on TeacherSection is prohibited.");
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

    public function lessonNode(): BelongsTo
    {
        return $this->belongsTo(LessonNode::class, 'work_node_id', 'id');
    }

    public function lessonQuery(): BelongsTo
    {
        return $this->belongsTo(LessonQuery::class, 'query_id', 'id');
    }
}
