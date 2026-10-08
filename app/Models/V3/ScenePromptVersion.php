<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScenePromptVersion extends Model
{
    protected $table = 'v3_scene_prompt_versions';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'body_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \InvalidArgumentException("ScenePromptVersion records are strictly immutable.");
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(PromptWorkItem::class, 'work_item_id', 'id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TeacherSection::class, 'section_id', 'id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(ScenePromptVersion::class, 'predecessor_id', 'id');
    }

    public function thirdTurn(): BelongsTo
    {
        return $this->belongsTo(PromptTurn::class, 'third_turn_id', 'id');
    }

    public function feedbackEffect(): BelongsTo
    {
        return $this->belongsTo(PromptFeedbackEffect::class, 'feedback_effect_id', 'id');
    }

    public function qualification(): HasOne
    {
        return $this->hasOne(PromptQualification::class, 'prompt_version_id', 'id');
    }

    public function teacherCorrection(): BelongsTo
    {
        return $this->belongsTo(TeacherPromptCorrection::class, 'teacher_correction_id', 'id');
    }
}
