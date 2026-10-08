<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherPromptCorrection extends Model
{
    protected $table = 'v3_teacher_prompt_corrections';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'send_count' => 'integer',
            'sent_at' => 'datetime',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function reviewRoute(): BelongsTo
    {
        return $this->belongsTo(ReviewRoute::class, 'review_route_id', 'id');
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id', 'id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TeacherSection::class, 'section_id', 'id');
    }

    public function fromPromptVersion(): BelongsTo
    {
        return $this->belongsTo(ScenePromptVersion::class, 'from_prompt_version_id', 'id');
    }

    public function toPromptVersion(): BelongsTo
    {
        return $this->belongsTo(ScenePromptVersion::class, 'to_prompt_version_id', 'id');
    }
}
