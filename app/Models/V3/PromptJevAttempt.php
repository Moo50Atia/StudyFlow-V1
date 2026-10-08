<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptJevAttempt extends Model
{
    protected $table = 'v3_prompt_jev_attempts';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'settings_version_id' => 'integer',
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(ScenePromptVersion::class, 'prompt_version_id', 'id');
    }

    public function settingsVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id', 'id');
    }
}
