<?php

namespace App\Models\V3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActiveSettings extends Model
{
    protected $table = 'v3_active_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'active_settings_version_id',
        'revision',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'active_settings_version_id' => 'integer',
            'revision' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(SettingsVersion::class, 'active_settings_version_id');
    }
}
