<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotebookLessonBundle extends Model
{
    protected $table = 'v3_notebook_lesson_bundles';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_ordinal' => 'integer',
            'committed_cursor_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            throw new \InvalidArgumentException('NotebookLessonBundle records are strictly immutable.');
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function lessonQuery(): BelongsTo
    {
        return $this->belongsTo(LessonQuery::class, 'query_id', 'id');
    }

    public function workNode(): BelongsTo
    {
        return $this->belongsTo(LessonNode::class, 'work_node_id', 'id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(PromptWorkItem::class, 'work_item_id', 'id');
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(LessonCompletion::class, 'completion_id', 'id');
    }

    public function frameworkVersion(): BelongsTo
    {
        return $this->belongsTo(NotebookFrameworkVersion::class, 'framework_version_id', 'id');
    }
}
