<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowLink extends Model
{
    protected $table = 'v3_workflow_links';
    protected $primaryKey = 'case_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings_version_id' => 'integer',
            'attempt_count' => 'integer',
            'scene_count' => 'integer',
            'approved_scene_count' => 'integer',
            'start_attempts' => 'integer',
            'lease_until' => 'datetime',
            'next_retry_at' => 'datetime',
            'start_attempted_at' => 'datetime',
            'started_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'last_event_at' => 'datetime',
            'last_alerted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function (WorkflowLink $link) {
            foreach (['case_id', 'workflow_id', 'settings_version_id'] as $immutableField) {
                if ($link->isDirty($immutableField)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$immutableField}' on WorkflowLink is prohibited.");
                }
            }

            // run_id and settings_checksum are immutable once set
            if ($link->isDirty('run_id') && $link->getOriginal('run_id') !== null) {
                throw new \InvalidArgumentException("Modifying immutable attribute 'run_id' once set on WorkflowLink is prohibited.");
            }

            if ($link->isDirty('settings_checksum') && $link->getOriginal('settings_checksum') !== null) {
                throw new \InvalidArgumentException("Modifying immutable attribute 'settings_checksum' once set on WorkflowLink is prohibited.");
            }
        });
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id');
    }

    public function settingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id');
    }
}
