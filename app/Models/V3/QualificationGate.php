<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualificationGate extends Model implements V3OwnedResource
{
    protected $table = 'v3_qualification_gates';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'waived_by_owner_id' => 'integer',
            'missing_fields_json' => 'array',
            'created_at' => 'datetime',
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

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SceneCandidate::class, 'candidate_id', 'id');
    }

    public function waivedByOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_owner_id', 'id');
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(PipelineCommand::class, 'command_id', 'id');
    }

    public function v3OwnerId(): int
    {
        if ($this->waived_by_owner_id) {
            return (int) $this->waived_by_owner_id;
        }

        if ($this->relationLoaded('processingCase') && $this->processingCase) {
            return (int) $this->processingCase->owner_user_id;
        }

        return (int) ProcessingCase::where('id', $this->case_id)->value('owner_user_id');
    }
}
