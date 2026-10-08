<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SettingsVersion extends Model
{
    protected $table = 'v3_settings_versions';

    public $timestamps = false;

    protected $fillable = [
        'version',
        'schema_version',
        'payload',
        'checksum',
        'published_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_version' => 'integer',
            'payload' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \LogicException('Settings versions are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('Settings versions are immutable and cannot be deleted.');
        });
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function publication(): HasOne
    {
        return $this->hasOne(SettingsPublication::class, 'settings_version_id');
    }
}
