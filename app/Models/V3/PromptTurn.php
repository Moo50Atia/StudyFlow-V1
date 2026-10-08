<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptTurn extends Model
{
    protected $table = 'v3_prompt_turns';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'send_count' => 'integer',
            'answer_bytes' => 'integer',
            'parsed_mappings' => 'array',
            'sent_at' => 'datetime',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (PromptTurn $model) {
            foreach (['id', 'work_item_id', 'step'] as $field) {
                if ($model->isDirty($field)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$field}' on PromptTurn is prohibited.");
                }
            }
        });
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(PromptWorkItem::class, 'work_item_id', 'id');
    }

    public function originQuery(): BelongsTo
    {
        return $this->belongsTo(LessonQuery::class, 'origin_query_id', 'id');
    }

    public function originTurn(): BelongsTo
    {
        return $this->belongsTo(PromptTurn::class, 'origin_turn_id', 'id');
    }
}
