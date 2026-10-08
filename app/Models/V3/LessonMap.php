<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LessonMap extends Model
{
    protected $table = 'v3_lesson_maps';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'page_count' => 'integer',
            'work_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (LessonMap $model) {
            foreach (['id', 'case_id', 'binding_id', 'activation_id'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on LessonMap is prohibited.");
                }
            }
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function knowledgeSourceBinding(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSourceBinding::class, 'binding_id', 'id');
    }

    public function mapActivation(): BelongsTo
    {
        return $this->belongsTo(MapActivation::class, 'activation_id', 'id');
    }

    public function divideQuery(): BelongsTo
    {
        return $this->belongsTo(LessonQuery::class, 'divide_query_id', 'id');
    }

    public function coverageQuery(): BelongsTo
    {
        return $this->belongsTo(LessonQuery::class, 'coverage_query_id', 'id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(LessonNode::class, 'map_id', 'id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(TeacherSection::class, 'map_id', 'id');
    }

    public function cursor(): HasOne
    {
        return $this->hasOne(LessonCursor::class, 'map_id', 'id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(LessonCompletion::class, 'map_id', 'id');
    }
}
