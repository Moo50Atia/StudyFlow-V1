<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonCompletion extends Model
{
    protected $table = 'v3_lesson_completions';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_ordinal' => 'integer',
            'cursor_version' => 'integer',
            'section_refs' => 'array',
            'third_output_refs' => 'array',
            'signal_attempts' => 'integer',
            'lease_until' => 'datetime',
            'signal_attempted_at' => 'datetime',
            'signal_accepted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (LessonCompletion $model) {
            foreach (['id', 'case_id', 'map_id', 'work_node_id', 'work_ordinal', 'completion_ref', 'completion_sha256'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on LessonCompletion is prohibited.");
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
}
