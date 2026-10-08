<?php

namespace App\Models\V3;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingsPublication extends Model
{
    protected $table = 'v3_settings_publications';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'settings_version_id',
        'publisher_user_id',
        'previous_version_id',
        'changed_paths',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'settings_version_id' => 'integer',
            'publisher_user_id' => 'integer',
            'previous_version_id' => 'integer',
            'changed_paths' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \LogicException('Settings publications are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('Settings publications are immutable and cannot be deleted.');
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'settings_version_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publisher_user_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'previous_version_id');
    }
}
