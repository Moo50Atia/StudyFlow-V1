<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AuthoringRequest extends Model implements V3OwnedResource
{
    protected $table = 'v3_authoring_requests';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings_version_id' => 'integer',
            'provider_override_json' => 'array',
            'state_version' => 'integer',
            'attempt_limit' => 'integer',
            'last_alerted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
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

    public function promptGate(): BelongsTo
    {
        return $this->belongsTo(QualificationGate::class, 'prompt_gate_id', 'id');
    }

    public function settingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id', 'id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CodingAttempt::class, 'request_id', 'id');
    }

    public function candidate(): HasOne
    {
        return $this->hasOne(SceneCandidate::class, 'request_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
