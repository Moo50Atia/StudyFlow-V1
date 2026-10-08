<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneQualification extends Model implements V3OwnedResource
{
    protected $table = 'v3_scene_qualifications';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
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
            throw new \InvalidArgumentException("SceneQualification records are strictly immutable.");
        });
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SceneCandidate::class, 'candidate_id', 'id');
    }

    public function checkRun(): BelongsTo
    {
        return $this->belongsTo(SceneCheckRun::class, 'check_run_id', 'id');
    }

    public function winningAttempt(): BelongsTo
    {
        return $this->belongsTo(SceneJevAttempt::class, 'winning_jev_attempt_id', 'id');
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TeacherSection::class, 'section_id', 'id');
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(ScenePromptVersion::class, 'prompt_version_id', 'id');
    }

    public function settingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
