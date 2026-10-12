<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneCandidate extends Model implements V3OwnedResource
{
    protected $table = 'v3_scene_candidates';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings_version_id' => 'integer',
            'file_count' => 'integer',
            'total_bytes' => 'integer',
            'collected_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \InvalidArgumentException("SceneCandidate records are strictly immutable.");
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AuthoringRequest::class, 'request_id', 'id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CodingAttempt::class, 'attempt_id', 'id');
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

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(PromptQualification::class, 'qualification_id', 'id');
    }

    public function settingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id', 'id');
    }

    public function manualImport(): BelongsTo
    {
        return $this->belongsTo(ManualSceneImport::class, 'manual_import_id', 'id');
    }

    public function promptGate(): BelongsTo
    {
        return $this->belongsTo(QualificationGate::class, 'prompt_gate_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
