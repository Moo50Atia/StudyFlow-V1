<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptQualification extends Model
{
    protected $table = 'v3_prompt_qualifications';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings_version_id' => 'integer',
            'choice_confidence' => 'string',
            'score' => 'string',
            'score_confidence' => 'string',
            'noul_probability' => 'string',
            'noul_yes' => 'boolean',
            'min_score' => 'integer',
            'min_confidence' => 'integer',
            'failed_checks' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \InvalidArgumentException("PromptQualification records are strictly immutable.");
        });
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(ScenePromptVersion::class, 'prompt_version_id', 'id');
    }

    public function winningAttempt(): BelongsTo
    {
        return $this->belongsTo(PromptJevAttempt::class, 'winning_attempt_id', 'id');
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TeacherSection::class, 'section_id', 'id');
    }

    public function settingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id', 'id');
    }
}
