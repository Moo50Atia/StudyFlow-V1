<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\Concerns\HasV3Owner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingCase extends Model implements V3OwnedResource
{
    use HasV3Owner;

    protected $table = 'v3_processing_cases';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [
        'owner_user_id',
        'document_id',
        'settings_version_id',
        'idempotency_key',
        'action',
        'state',
        'state_version',
    ];

    protected function casts(): array
    {
        return [
            'state_version' => 'integer',
            'notebook_policy' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($case) {
            foreach (['owner_user_id', 'document_id', 'settings_version_id', 'idempotency_key', 'action', 'pipeline_version', 'notebook_framework_version_id', 'notebook_policy'] as $field) {
                if ($case->isDirty($field)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$field}' on a ProcessingCase is prohibited.");
                }
            }
        });
    }

    public function frameworkVersion(): BelongsTo
    {
        return $this->belongsTo(NotebookFrameworkVersion::class, 'notebook_framework_version_id');
    }

    public function v3OwnerId(): int
    {
        return (int) $this->owner_user_id;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function settingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id');
    }

    public function workflowLink(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(WorkflowLink::class, 'case_id');
    }

    public function mapActivation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MapActivation::class, 'case_id');
    }

    public function lessonMap(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LessonMap::class, 'case_id');
    }

    public function lessonQueries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LessonQuery::class, 'case_id');
    }

    public function teacherSections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TeacherSection::class, 'case_id');
    }

    public function lessonCursor(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LessonCursor::class, 'case_id');
    }

    public function lessonCompletions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LessonCompletion::class, 'case_id');
    }

    public function isDirectProtocol(): bool
    {
        return ($this->pipeline_version ?? '') === 'notebooklm_combined_v1';
    }

    public function isWebPipelineCase(): bool
    {
        // Web intake reuses the combined NotebookLM case protocol. The subject
        // run item is the durable link that distinguishes it from CLI cases.
        return ($this->pipeline_version ?? '') === 'notebooklm_per_pdf_web_v1'
            || SubjectRunItem::where('case_id', $this->id)->exists();
    }

    public function isLocalWorkerCase(): bool
    {
        $item = SubjectRunItem::where('case_id', $this->id)->first();
        if (! $item) {
            return false;
        }
        $run = SubjectRun::find($item->run_id);
        return $run?->execution_mode === 'local_worker_v1'
            || \App\Models\V3\LocalJob::where('run_id', $item->run_id)->exists();
    }
}
