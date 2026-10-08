<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\Concerns\HasV3Owner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model implements V3OwnedResource
{
    use HasV3Owner;

    protected $table = 'v3_documents';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [
        'owner_user_id',
        'state',
        'storage_path',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'page_count' => 'integer',
            'accepted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($document) {
            // Once ready, core attributes cannot be altered by application code
            if ($document->getOriginal('state') === 'ready') {
                foreach (['owner_user_id', 'sha256', 'byte_size', 'page_count', 'storage_path'] as $field) {
                    if ($document->isDirty($field)) {
                        throw new \InvalidArgumentException("Modifying immutable attribute '{$field}' on a ready Document is prohibited.");
                    }
                }
            }
        });

        static::deleting(function ($document) {
            if ($document->state === 'ready') {
                throw new \LogicException('Ready documents cannot be deleted.');
            }
        });
    }

    public function v3OwnerId(): int
    {
        return (int) $this->owner_user_id;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(UploadAttempt::class, 'document_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ProcessingCase::class, 'document_id');
    }
}
