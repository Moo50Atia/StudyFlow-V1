<?php

namespace App\Models\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\Concerns\HasV3Owner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotebookInstance extends Model implements V3OwnedResource
{
    use HasV3Owner;

    protected $table = 'v3_notebook_instances';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($instance) {
            foreach (['run_id', 'owner_user_id', 'adapter_kind', 'credential_ref'] as $field) {
                if ($instance->isDirty($field)) {
                    throw new \InvalidArgumentException("Modifying immutable attribute '{$field}' on NotebookInstance is prohibited.");
                }
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

    public function v3OwnerId(): int
    {
        return (int) $this->owner_user_id;
    }
}
