<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptSectionState extends Model
{
    protected $table = 'v3_prompt_section_states';
    protected $primaryKey = 'section_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'revision_count' => 'integer',
            'last_alerted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TeacherSection::class, 'section_id', 'id');
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function currentPromptVersion(): BelongsTo
    {
        return $this->belongsTo(ScenePromptVersion::class, 'current_prompt_version_id', 'id');
    }

    public function lastQualification(): BelongsTo
    {
        return $this->belongsTo(PromptQualification::class, 'last_qualification_id', 'id');
    }
}
