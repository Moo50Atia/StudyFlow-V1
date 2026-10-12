<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\Concerns\HasV3Owner;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubjectRun extends Model implements V3OwnedResource
{
    use HasV3Owner;

    protected $table = 'v3_subject_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state_version' => 'integer',
            'total_pdfs' => 'integer',
            'total_lessons' => 'integer',
            'total_scenes' => 'integer',
            'last_event_sequence' => 'integer',
            'captured_settings_json' => 'array',
            'counters_json' => 'array',
            'last_updated_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($run) {
            foreach (['owner_user_id', 'subject_id', 'origin', 'protocol', 'submission_key', 'request_sha256', 'capture_sha256', 'settings_version_id', 'framework_version_id'] as $field) {
                if ($run->isDirty($field)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$field}' on SubjectRun is prohibited.");
                }
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function settingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id');
    }

    public function frameworkVersion(): BelongsTo
    {
        return $this->belongsTo(NotebookFrameworkVersion::class, 'framework_version_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ApprovedTemplate::class, 'template_version_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubjectRunItem::class, 'run_id')->orderBy('pdf_ordinal');
    }

    public function notebookInstance(): HasOne
    {
        return $this->hasOne(NotebookInstance::class, 'run_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(PipelineCommand::class, 'run_id')->orderBy('created_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PipelineEvent::class, 'run_id')->orderBy('sequence');
    }

    public function v3OwnerId(): int
    {
        return (int) $this->owner_user_id;
    }
}
