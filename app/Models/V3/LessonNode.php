<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonNode extends Model
{
    protected $table = 'v3_lesson_nodes';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ordinal_in_parent' => 'integer',
            'work_ordinal' => 'integer',
            'coverage_pages' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (LessonNode $model) {
            foreach (['id', 'map_id'] as $immutableField) {
                if ($model->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on LessonNode is prohibited.");
                }
            }
        });
    }

    public function lessonMap(): BelongsTo
    {
        return $this->belongsTo(LessonMap::class, 'map_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(LessonNode::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(LessonNode::class, 'parent_id', 'id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(TeacherSection::class, 'work_node_id', 'id');
    }
}
