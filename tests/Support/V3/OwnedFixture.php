<?php

namespace Tests\Support\V3;

use App\Contracts\V3\V3OwnedResource;
use App\Models\Concerns\HasV3Owner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OwnedFixture extends Model implements V3OwnedResource
{
    use HasV3Owner;

    protected $table = 'v3_test_owned_fixtures';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'owner_user_id',
        'parent_id',
        'content',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OwnedFixture::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(OwnedFixture::class, 'parent_id');
    }

    public function v3OwnerId(): int
    {
        return (int) $this->owner_user_id;
    }
}
