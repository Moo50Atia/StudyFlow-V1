<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\Concerns\HasV3Owner;
use App\Models\Lecture;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectRunItem extends Model implements V3OwnedResource
{
    use HasV3Owner;

    protected $table = 'v3_subject_run_items';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pdf_ordinal' => 'integer',
            'input_bytes' => 'integer',
            'work_count' => 'integer',
            'saved_lesson_count' => 'integer',
            'prompt_count' => 'integer',
            'state_version' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($item) {
            foreach (['run_id', 'owner_user_id', 'pdf_ordinal', 'input_sha256'] as $field) {
                if ($item->isDirty($field)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$field}' on SubjectRunItem is prohibited.");
                }
            }
            if ($item->isDirty('document_id') && $item->getOriginal('document_id') !== null) {
                throw new \InvalidArgumentException("Modifying immutable attribute 'document_id' on SubjectRunItem is prohibited.");
            }
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SubjectRun::class, 'run_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(Lecture::class, 'lecture_id');
    }

    public function processingCase(): BelongsTo
    {
        return $this->belongsTo(ProcessingCase::class, 'case_id');
    }

    public function v3OwnerId(): int
    {
        return (int) $this->owner_user_id;
    }
}
