<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptWorkItem extends Model
{
    protected $table = 'v3_prompt_work_items';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_ordinal' => 'integer',
            'expected_cursor_version' => 'integer',
            'section_refs' => 'array',
            'last_alerted_at' => 'datetime',
            'receipt_committed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (PromptWorkItem $model) {
            foreach (['id', 'case_id', 'map_id', 'work_node_id', 'work_ordinal'] as $field) {
                if ($model->isDirty($field)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$field}' on PromptWorkItem is prohibited.");
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

    public function workNode(): BelongsTo
    {
        return $this->belongsTo(LessonNode::class, 'work_node_id', 'id');
    }

    public function sectionQuery(): BelongsTo
    {
        return $this->belongsTo(LessonQuery::class, 'section_query_id', 'id');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(PromptTurn::class, 'work_item_id', 'id');
    }

    public function promptVersions(): HasMany
    {
        return $this->hasMany(ScenePromptVersion::class, 'work_item_id', 'id');
    }
}
